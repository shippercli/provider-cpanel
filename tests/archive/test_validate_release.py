import importlib.util
import io
from pathlib import Path
import tarfile
import tempfile
import unittest

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


if __name__ == '__main__':
    unittest.main()
