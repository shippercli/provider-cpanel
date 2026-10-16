import importlib.util
import io
from pathlib import Path
import tarfile
import tempfile
import unittest
from unittest import mock

spec = importlib.util.spec_from_file_location('release_validator', Path(__file__).parents[2] / 'resources/validate-release.py')
validator = importlib.util.module_from_spec(spec)
spec.loader.exec_module(validator)


class ReleaseValidationTest(unittest.TestCase):
    def inspect(self, entries):
        with tempfile.TemporaryDirectory() as directory:
            archive = Path(directory) / 'release.tar.gz'
            with tarfile.open(archive, 'w:gz') as output:
                for name, kind, target in entries:
                    entry = tarfile.TarInfo(name)
                    entry.type = kind
                    if kind in (tarfile.SYMTYPE, tarfile.LNKTYPE):
                        entry.linkname = target
                    if kind == tarfile.REGTYPE:
                        data = target.encode()
                        entry.size = len(data)
                        output.addfile(entry, io.BytesIO(data))
                    else:
                        output.addfile(entry)
            return validator.inspect_release(archive)

    def test_regular_directories_and_internal_links(self):
        entries = [('.', tarfile.DIRTYPE, ''), ('app', tarfile.DIRTYPE, ''),
                   ('app/index.txt', tarfile.REGTYPE, 'fixture'),
                   ('current', tarfile.SYMTYPE, 'app'),
                   ('app/alias', tarfile.SYMTYPE, '../app/index.txt'),
                   ('hard', tarfile.LNKTYPE, './app/index.txt')]
        self.assertEqual(self.inspect(entries), len(entries))

    def test_dangerous_member_paths_and_types(self):
        for name in ('/absolute', '../outside', 'app/../../outside', 'bad\\path', 'control\nname'):
            with self.subTest(name=name), self.assertRaises(validator.InvalidRelease):
                self.inspect([(name, tarfile.REGTYPE, 'fixture')])
        for kind in (tarfile.FIFOTYPE, tarfile.CHRTYPE, tarfile.BLKTYPE):
            with self.subTest(kind=kind), self.assertRaises(validator.InvalidRelease):
                self.inspect([('special', kind, '')])

    def test_escaping_links_and_cycles(self):
        cases = [
            [('link', tarfile.SYMTYPE, '/outside')],
            [('link', tarfile.SYMTYPE, '../outside')],
            [('app/link', tarfile.SYMTYPE, '../../outside')],
            [('a', tarfile.SYMTYPE, 'b'), ('b', tarfile.SYMTYPE, 'a')],
            [('a', tarfile.SYMTYPE, '.'), ('b', tarfile.SYMTYPE, 'a/../outside')],
            [('hard', tarfile.LNKTYPE, '../outside')],
            [('hard', tarfile.LNKTYPE, 'missing')],
            [('a', tarfile.SYMTYPE, 'inside'), ('hard', tarfile.LNKTYPE, 'a')],
        ]
        for entries in cases:
            with self.subTest(entries=entries), self.assertRaises(validator.InvalidRelease):
                self.inspect(entries)

    def test_duplicates_and_non_directory_ancestors(self):
        cases = [
            [('a', tarfile.REGTYPE, 'one'), ('./a', tarfile.REGTYPE, 'two')],
            [('a', tarfile.SYMTYPE, 'inside'), ('a/file', tarfile.REGTYPE, 'fixture')],
            [('a/file', tarfile.REGTYPE, 'fixture'), ('a', tarfile.SYMTYPE, 'inside')],
            [('a', tarfile.REGTYPE, 'fixture'), ('a/file', tarfile.REGTYPE, 'fixture')],
        ]
        for entries in cases:
            with self.subTest(entries=entries), self.assertRaises(validator.InvalidRelease):
                self.inspect(entries)

    def test_corrupt_gzip_is_rejected(self):
        with tempfile.TemporaryDirectory() as directory:
            path = Path(directory) / 'release.tar.gz'
            path.write_bytes(b'not gzip')
            with self.assertRaises(OSError):
                validator.inspect_release(path)


class ReleaseStagingTest(unittest.TestCase):
    def make_archive(self, path, unsafe_target=None):
        with tarfile.open(path, 'w:gz') as output:
            directory = tarfile.TarInfo('app')
            directory.type = tarfile.DIRTYPE
            directory.mode = 0o750
            output.addfile(directory)
            file = tarfile.TarInfo('app/file.txt')
            file.size = 7
            file.mode = 0o640
            output.addfile(file, io.BytesIO(b'fixture'))
            link = tarfile.TarInfo('link')
            link.type = tarfile.SYMTYPE
            link.linkname = unsafe_target or 'app/file.txt'
            output.addfile(link)
            hardlink = tarfile.TarInfo('hardlink')
            hardlink.type = tarfile.LNKTYPE
            hardlink.linkname = 'app/file.txt'
            output.addfile(hardlink)

    def test_stages_content_modes_and_internal_links_privately(self):
        with tempfile.TemporaryDirectory() as directory:
            root = Path(directory)
            archive = root / 'release.tar.gz'
            self.make_archive(archive)
            stage = root / 'stage'
            self.assertEqual(validator.stage_release(archive, stage), 4)
            self.assertEqual(stage.stat().st_mode & 0o777, 0o700)
            self.assertEqual((stage / 'archive.tar.gz').stat().st_mode & 0o777, 0o600)
            self.assertEqual((stage / 'tree/app/file.txt').read_bytes(), b'fixture')
            self.assertEqual((stage / 'tree/app/file.txt').stat().st_mode & 0o777, 0o640)
            self.assertEqual((stage / 'tree/app').stat().st_mode & 0o777, 0o750)
            self.assertTrue((stage / 'tree/link').is_symlink())
            self.assertEqual((stage / 'tree/link').read_bytes(), b'fixture')
            self.assertEqual((stage / 'tree/hardlink').stat().st_ino,
                             (stage / 'tree/app/file.txt').stat().st_ino)

    def test_external_link_refused_without_touching_active_tree(self):
        with tempfile.TemporaryDirectory() as directory:
            root = Path(directory)
            active = root / 'active'
            active.mkdir()
            sentinel = active / 'private.txt'
            sentinel.write_text('unchanged')
            archive = root / 'release.tar.gz'
            self.make_archive(archive, str(sentinel))
            stage = root / 'stage'
            with self.assertRaises(validator.InvalidRelease):
                validator.stage_release(archive, stage)
            self.assertEqual(sentinel.read_text(), 'unchanged')
            self.assertFalse(stage.exists())

    def test_archive_replacement_after_inspection_cannot_change_staged_content(self):
        with tempfile.TemporaryDirectory() as directory:
            root = Path(directory)
            archive = root / 'release.tar.gz'
            self.make_archive(archive)
            original_inspect = validator.inspect_release
            def replace_original(snapshot):
                count = original_inspect(snapshot)
                archive.write_bytes(b'corrupt replacement')
                return count
            with mock.patch.object(validator, 'inspect_release', side_effect=replace_original):
                validator.stage_release(archive, root / 'stage')
            self.assertEqual((root / 'stage/tree/app/file.txt').read_bytes(), b'fixture')

    def test_existing_destination_and_links_are_never_reused(self):
        with tempfile.TemporaryDirectory() as directory:
            root = Path(directory)
            archive = root / 'release.tar.gz'
            self.make_archive(archive)
            active = root / 'active'
            active.mkdir()
            sentinel = active / 'sentinel'
            sentinel.write_text('unchanged')
            link = root / 'stage-link'
            link.symlink_to(active)
            for stage in (active, link):
                with self.assertRaises(FileExistsError):
                    validator.stage_release(archive, stage)
                self.assertEqual(sentinel.read_text(), 'unchanged')

    def test_copy_failure_removes_private_staging_directory(self):
        with tempfile.TemporaryDirectory() as directory:
            root = Path(directory)
            with self.assertRaises(FileNotFoundError):
                validator.stage_release(root / 'missing.tar.gz', root / 'stage')
            self.assertFalse((root / 'stage').exists())


if __name__ == '__main__':
    unittest.main()
