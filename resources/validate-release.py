#!/usr/bin/env python3
"""Validate a gzip tar release and optionally build a private staged tree.

Not yet wired into remote rollback. The caller must supply a trusted staging
parent and restore the completed tree without traversing existing target links.
"""
import gzip
import os
from pathlib import Path
import shutil
import stat
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


def validate_tree(tree):
    """Check the final staged filesystem, including preserved live resources."""
    members = {}
    def visit(directory, prefix):
        with os.scandir(str(directory)) as entries:
            for entry in entries:
                key = prefix + (entry.name,)
                components(entry.name)
                info = entry.stat(follow_symlinks=False)
                member = tarfile.TarInfo('/'.join(key))
                if stat.S_ISLNK(info.st_mode):
                    member.type = tarfile.SYMTYPE
                    member.linkname = os.readlink(entry.path)
                elif stat.S_ISDIR(info.st_mode):
                    member.type = tarfile.DIRTYPE
                elif stat.S_ISREG(info.st_mode):
                    member.type = tarfile.REGTYPE
                else:
                    raise InvalidRelease('unsupported staged file type')
                if info.st_mode & 0o7000:
                    raise InvalidRelease('unsupported staged permission bits')
                members[key] = member
                if member.isdir():
                    visit(Path(entry.path), key)
    visit(tree, ())
    for key, member in members.items():
        if member.issym():
            resolve_link(key, members, set())


def remove_staged_entry(path):
    if path.is_symlink() or path.is_file():
        path.unlink()
    elif path.exists():
        # Only used within the newly created, private staging tree.
        for root, directories, _ in os.walk(str(path), followlinks=False):
            os.chmod(root, 0o700)
            for directory in directories:
                child = os.path.join(root, directory)
                if not os.path.islink(child):
                    os.chmod(child, 0o700)
        shutil.rmtree(str(path))


def promote_release(destination, active):
    """Promote a prepared tree, retaining the old tree for recovery.

    Parents must be trusted. This uses two renames, not a single atomic swap.
    A process crash between the renames requires recovery from `previous`.
    """
    destination, active = Path(destination), Path(active)
    tree, previous = destination / 'tree', destination / 'previous'
    if destination.is_symlink() or not destination.is_dir() or destination.stat().st_mode & 0o077:
        raise InvalidRelease('staging directory is not private')
    if tree.is_symlink() or not tree.is_dir() or active.is_symlink() or not active.is_dir():
        raise InvalidRelease('promotion requires real deployment directories')
    stage_real, active_real = destination.resolve(), active.resolve()
    if stage_real == active_real or stage_real in active_real.parents or active_real in stage_real.parents:
        raise InvalidRelease('deployment and staging directories overlap')
    if previous.exists() or previous.is_symlink():
        raise InvalidRelease('previous deployment already exists')
    preserved = active / '.well-known'
    if preserved.exists() or preserved.is_symlink():
        if preserved.is_symlink() or not preserved.is_dir():
            raise InvalidRelease('live .well-known must be a real directory')
        remove_staged_entry(tree / '.well-known')
        shutil.copytree(str(preserved), str(tree / '.well-known'), symlinks=True)
    validate_tree(tree)
    os.rename(str(active), str(previous))
    try:
        os.rename(str(tree), str(active))
    except BaseException:
        os.rename(str(previous), str(active))
        raise


def main():
    promote = len(sys.argv) == 4 and sys.argv[1] == '--promote'
    stage = len(sys.argv) == 4 and sys.argv[2] == '--stage'
    if len(sys.argv) != 2 and not promote and not stage:
        print('Usage: validate-release.py ARCHIVE [--stage NEW_DIRECTORY] | --promote STAGE ACTIVE', file=sys.stderr)
        return 2
    try:
        if promote:
            promote_release(sys.argv[2], sys.argv[3])
            print('Promoted staged release; previous deployment retained')
        else:
            count = stage_release(sys.argv[1], sys.argv[3]) if stage else inspect_release(sys.argv[1])
            print('Validated {} archive members'.format(count))
    except (InvalidRelease, OSError, EOFError, tarfile.TarError):
        # Do not echo attacker-controlled archive names or file contents.
        print('Release operation failed; inspect staging and previous deployment before retrying', file=sys.stderr)
        return 1
    return 0


if __name__ == '__main__':
    sys.exit(main())
