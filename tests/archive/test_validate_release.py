import importlib.util
import io
import json
import fcntl
import hashlib
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


class ReleasePromotionTest(unittest.TestCase):
    def prepare(self, root):
        archive = root / 'release.tar.gz'
        ReleaseStagingTest.make_archive(self, archive)
        stage = root / 'stage'
        validator.stage_release(archive, stage)
        active = root / 'active'
        active.mkdir()
        (active / 'old.txt').write_text('old release')
        return stage, active

    def test_promotes_new_tree_and_retains_previous_with_well_known(self):
        with tempfile.TemporaryDirectory() as directory:
            stage, active = self.prepare(Path(directory))
            (active / '.well-known').mkdir()
            (active / '.well-known/challenge').write_text('ACME fixture')
            validator.promote_release(stage, active)
            self.assertEqual((active / 'link').read_bytes(), b'fixture')
            self.assertEqual((active / '.well-known/challenge').read_text(), 'ACME fixture')
            self.assertFalse((active / 'old.txt').exists())
            self.assertEqual((stage / 'previous/old.txt').read_text(), 'old release')
            self.assertEqual((stage / 'previous/.well-known/challenge').read_text(), 'ACME fixture')

    def test_failed_second_rename_restores_active_release(self):
        with tempfile.TemporaryDirectory() as directory:
            stage, active = self.prepare(Path(directory))
            rename = validator.os.rename
            def fail_promotion(source, target):
                if Path(source) == stage / 'tree':
                    raise OSError('synthetic promotion failure')
                return rename(source, target)
            with mock.patch.object(validator.os, 'rename', side_effect=fail_promotion):
                with self.assertRaises(OSError):
                    validator.promote_release(stage, active)
            self.assertEqual((active / 'old.txt').read_text(), 'old release')
            self.assertFalse((stage / 'previous').exists())
            self.assertTrue((stage / 'tree').is_dir())

    def test_preserved_well_known_cannot_introduce_external_links(self):
        with tempfile.TemporaryDirectory() as directory:
            root = Path(directory)
            stage, active = self.prepare(root)
            outside = root / 'outside.txt'
            outside.write_text('private fixture')
            (active / '.well-known').mkdir()
            (active / '.well-known/external').symlink_to(outside)
            with self.assertRaises(validator.InvalidRelease):
                validator.promote_release(stage, active)
            self.assertEqual((active / 'old.txt').read_text(), 'old release')
            self.assertFalse((stage / 'previous').exists())
            self.assertEqual(outside.read_text(), 'private fixture')

    def test_live_well_known_symlink_is_refused_without_following_it(self):
        with tempfile.TemporaryDirectory() as directory:
            root = Path(directory)
            stage, active = self.prepare(root)
            outside = root / 'outside'
            outside.mkdir()
            (outside / 'sentinel').write_text('private fixture')
            (active / '.well-known').symlink_to(outside)
            with self.assertRaises(validator.InvalidRelease):
                validator.promote_release(stage, active)
            self.assertEqual((outside / 'sentinel').read_text(), 'private fixture')
            self.assertEqual((active / 'old.txt').read_text(), 'old release')

    def test_modified_stage_cannot_promote_an_external_link(self):
        with tempfile.TemporaryDirectory() as directory:
            root = Path(directory)
            stage, active = self.prepare(root)
            (stage / 'tree/escape').symlink_to('../outside')
            with self.assertRaises(validator.InvalidRelease):
                validator.promote_release(stage, active)
            self.assertEqual((active / 'old.txt').read_text(), 'old release')

    def test_live_deployment_symlink_is_refused(self):
        with tempfile.TemporaryDirectory() as directory:
            root = Path(directory)
            stage, active = self.prepare(root)
            alias = root / 'alias'
            alias.symlink_to(active)
            with self.assertRaises(validator.InvalidRelease):
                validator.promote_release(stage, alias)
            self.assertEqual((active / 'old.txt').read_text(), 'old release')


class RestoreEntryPointTest(unittest.TestCase):
    def prepare(self, root, archive_project='sample'):
        home = root / 'home'
        home.mkdir()
        active = home / 'app'
        active.mkdir()
        manifest = {'project': 'sample', 'profile': 'production',
                    'domain': {'domain': 'app.example.com'}, 'deploy_path': '/app'}
        (active / '.shipper-manifest.json').write_text(json.dumps(manifest))
        (active / 'old.txt').write_text('old release')
        archive = home / 'release.tar.gz'
        manifest['project'] = archive_project
        with tarfile.open(archive, 'w:gz') as output:
            for name, content in [('index.txt', 'new release'),
                                  ('.shipper-manifest.json', json.dumps(manifest))]:
                data = content.encode()
                member = tarfile.TarInfo(name)
                member.size = len(data)
                output.addfile(member, io.BytesIO(data))
        return home, active, archive, home / 'stage'

    def restore(self, home, active, archive, stage):
        validator.restore_release(archive, stage, active, home, 'sample', 'production', 'app.example.com')

    def test_matching_release_restores_and_retains_previous(self):
        with tempfile.TemporaryDirectory() as directory:
            home, active, archive, stage = self.prepare(Path(directory).resolve())
            self.restore(home, active, archive, stage)
            self.assertEqual((active / 'index.txt').read_text(), 'new release')
            self.assertEqual((stage / 'previous/old.txt').read_text(), 'old release')

    def test_foreign_archive_identity_does_not_move_active_release(self):
        with tempfile.TemporaryDirectory() as directory:
            home, active, archive, stage = self.prepare(Path(directory).resolve(), 'other-project')
            with self.assertRaises(validator.InvalidRelease):
                self.restore(home, active, archive, stage)
            self.assertEqual((active / 'old.txt').read_text(), 'old release')
            self.assertFalse((stage / 'previous').exists())

    def test_symlink_ancestor_stops_before_staging(self):
        with tempfile.TemporaryDirectory() as directory:
            root = Path(directory).resolve()
            home, active, archive, stage = self.prepare(root)
            alias = root / 'alias'
            alias.symlink_to(home)
            with self.assertRaises(validator.InvalidRelease):
                self.restore(alias, alias / 'app', alias / 'release.tar.gz', alias / 'stage')
            self.assertEqual((active / 'old.txt').read_text(), 'old release')
            self.assertFalse(stage.exists())

    def test_competing_restore_lock_stops_before_staging(self):
        with tempfile.TemporaryDirectory() as directory:
            home, active, archive, stage = self.prepare(Path(directory).resolve())
            name = '.shipper-rollback-' + hashlib.sha256(str(active).encode()).hexdigest() + '.lock'
            with open(home / name, 'w') as lock:
                fcntl.flock(lock.fileno(), fcntl.LOCK_EX | fcntl.LOCK_NB)
                with self.assertRaises(BlockingIOError):
                    self.restore(home, active, archive, stage)
            self.assertEqual((active / 'old.txt').read_text(), 'old release')
            self.assertFalse(stage.exists())

    def test_changed_live_identity_stops_before_staging(self):
        with tempfile.TemporaryDirectory() as directory:
            home, active, archive, stage = self.prepare(Path(directory).resolve())
            path = active / '.shipper-manifest.json'
            manifest = json.loads(path.read_text())
            manifest['domain']['domain'] = 'other.example.com'
            path.write_text(json.dumps(manifest))
            with self.assertRaises(validator.InvalidRelease):
                self.restore(home, active, archive, stage)
            self.assertEqual((active / 'old.txt').read_text(), 'old release')
            self.assertFalse(stage.exists())


if __name__ == '__main__':
    unittest.main()
