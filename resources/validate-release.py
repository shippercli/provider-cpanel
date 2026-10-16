#!/usr/bin/env python3
"""Inspect a gzip tar release without extracting members or following links.

This validator is not yet wired into remote rollback. Safe restoration also
requires extracting into a private empty directory and using that staged tree.
"""
import gzip
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


def main():
    if len(sys.argv) != 2:
        print('Usage: validate-release.py ARCHIVE', file=sys.stderr)
        return 2
    try:
        count = inspect_release(sys.argv[1])
    except (InvalidRelease, OSError, EOFError, tarfile.TarError):
        # Do not echo attacker-controlled archive names or file contents.
        print('Release archive validation failed', file=sys.stderr)
        return 1
    print('Validated {} archive members'.format(count))
    return 0


if __name__ == '__main__':
    sys.exit(main())
