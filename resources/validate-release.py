#!/usr/bin/env python3
"""Validate a gzip tar release and optionally build a private staged tree.

Not yet wired into remote rollback. The caller must supply a trusted staging
parent and restore the completed tree without traversing existing target links.
"""
import gzip
import os
from pathlib import Path
import shutil
import sys
import tarfile


class InvalidRelease(ValueError):
    pass


def components(value):
    if not isinstance(value, str) or not value or value.startswith('/'):
        raise InvalidRelease('absolute or empty archive path')
    if '\\' in value or any(ord(char) < 32 or ord(char) == 127 for char in value):
        raise InvalidRelease('ambiguous archive path')
    return value.split('/')


def normalize(value, base=()):
    result = list(base)
    for part in components(value):
        if part in ('', '.'):
            continue
        if part == '..':
            if not result:
                raise InvalidRelease('archive path escapes its root')
            result.pop()
        else:
            result.append(part)
    return tuple(result)


def inspect_release(path):
    # Consume the complete gzip stream, including its checksum/trailer.
    with gzip.open(path, 'rb') as stream:
        while stream.read(1024 * 1024):
            pass
    with tarfile.open(path, 'r:gz') as archive:
        members = {}
        for member in archive:
            key = normalize(member.name)
            if not key and not member.isdir():
                raise InvalidRelease('archive root must be a directory')
            if key in members:
                raise InvalidRelease('duplicate archive path')
            if not (member.isdir() or member.isfile() or member.issym() or member.islnk()):
                raise InvalidRelease('unsupported archive member type')
            if member.mode & 0o7000:
                raise InvalidRelease('special permission bits are unsupported')
            members[key] = member
            if member.isfile():
                stream = archive.extractfile(member)
                if stream is None:
                    raise InvalidRelease('unreadable archive member')
                with stream:
                    remaining = member.size
                    while remaining:
                        chunk = stream.read(min(remaining, 1024 * 1024))
                        if not chunk:
                            raise InvalidRelease('truncated archive member')
                        remaining -= len(chunk)

        for key, member in members.items():
            for length in range(1, len(key)):
                parent = members.get(key[:length])
                if parent is not None and not parent.isdir():
                    raise InvalidRelease('archive member is nested beneath a non-directory')
            if member.issym():
                resolve_link(key, members, set())
            elif member.islnk():
                target = normalize(member.linkname)
                target_member = members.get(target)
                if target_member is None or not target_member.isfile():
                    raise InvalidRelease('hardlink must target an archived regular file')
        return len(members)


def resolve_link(key, members, visiting):
    if key in visiting or len(visiting) >= 64:
        raise InvalidRelease('cyclic or excessive archive symlink chain')
    visiting = visiting | {key}
    member = members[key]
    # Expand symlinks before processing subsequent .. components, matching
    # filesystem resolution rather than lexical path normalization.
    result = list(key[:-1])
    pending = components(member.linkname)
    while pending:
        part = pending.pop(0)
        if part in ('', '.'):
            continue
        if part == '..':
            if not result:
                raise InvalidRelease('symlink escapes archive root')
            result.pop()
            continue
        result.append(part)
        candidate = tuple(result)
        target_member = members.get(candidate)
        if target_member is not None and target_member.issym():
            result = list(resolve_link(candidate, members, visiting))
        elif pending and target_member is not None and not target_member.isdir():
            raise InvalidRelease('symlink traverses a non-directory')
    return tuple(result)


def stage_release(archive_path, destination):
    """Create a private snapshot and tree; never touch an active deployment.

    destination must not exist. Its parent must already be a trusted directory.
    The caller owns cleanup of the returned staging directory after success.
    """
    destination = Path(destination)
    os.mkdir(str(destination), 0o700)
    try:
        snapshot = destination / 'archive.tar.gz'
        with open(archive_path, 'rb') as source, open(str(snapshot), 'xb') as output:
            os.chmod(str(snapshot), 0o600)
            shutil.copyfileobj(source, output, 1024 * 1024)
        count = inspect_release(str(snapshot))
        tree = destination / 'tree'
        tree.mkdir(mode=0o700)
        with tarfile.open(str(snapshot), 'r:gz') as archive:
            entries = [(normalize(member.name), member) for member in archive]
            # No archive links exist while creating directories and regular files.
            # The validator already rejected entries beneath non-directories.
            for key, member in entries:
                target = tree.joinpath(*key)
                target.parent.mkdir(mode=0o700, parents=True, exist_ok=True)
                if member.isdir():
                    target.mkdir(mode=0o700, exist_ok=True)
                elif member.isfile():
                    stream = archive.extractfile(member)
                    if stream is None:
                        raise InvalidRelease('unreadable archive member')
                    with stream, open(str(target), 'xb') as output:
                        shutil.copyfileobj(stream, output, 1024 * 1024)
                    if target.stat().st_size != member.size:
                        raise InvalidRelease('incomplete staged file')
                    os.chmod(str(target), member.mode & 0o777)
                    os.utime(str(target), (member.mtime, member.mtime))
            # Hardlinks reference previously created regular files only.
            for key, member in entries:
                if member.islnk():
                    target = tree.joinpath(*key)
                    target.parent.mkdir(mode=0o700, parents=True, exist_ok=True)
                    os.link(str(tree.joinpath(*normalize(member.linkname))), str(target))
            for key, member in entries:
                if member.issym():
                    target = tree.joinpath(*key)
                    target.parent.mkdir(mode=0o700, parents=True, exist_ok=True)
                    os.symlink(member.linkname, str(target))
            # Apply directory metadata after descendants have been written.
            for key, member in sorted(entries, key=lambda pair: len(pair[0]), reverse=True):
                if member.isdir():
                    target = tree.joinpath(*key)
                    os.chmod(str(target), member.mode & 0o777)
                    os.utime(str(target), (member.mtime, member.mtime))
        return count
    except BaseException:
        # Permit removing staged read-only directories; never follow symlinks.
        for root, directories, _ in os.walk(str(destination), followlinks=False):
            os.chmod(root, 0o700)
            for directory in directories:
                path = os.path.join(root, directory)
                if not os.path.islink(path):
                    os.chmod(path, 0o700)
        shutil.rmtree(str(destination))
        raise


def main():
    if len(sys.argv) not in (2, 4) or (len(sys.argv) == 4 and sys.argv[2] != '--stage'):
        print('Usage: validate-release.py ARCHIVE [--stage NEW_DIRECTORY]', file=sys.stderr)
        return 2
    try:
        count = stage_release(sys.argv[1], sys.argv[3]) if len(sys.argv) == 4 else inspect_release(sys.argv[1])
    except (InvalidRelease, OSError, EOFError, tarfile.TarError):
        # Do not echo attacker-controlled archive names or file contents.
        print('Release archive validation failed', file=sys.stderr)
        return 1
    print('Validated {} archive members'.format(count))
    return 0


if __name__ == '__main__':
    sys.exit(main())
