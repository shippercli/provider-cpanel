# Release archive inventories

Release listing accepts documented `dirs`/`files` mappings and legacy flat row
lists. Every entry must have an unambiguous basename and recognized file type.
A filename matching the release archive format must be an ordinary file;
symlinks and directories cannot be rollback candidates. Duplicate names and
unreadable inventories fail rollback and retention checks before they use that
inventory to authorize changes.

Status retains its `releases` array and adds `release_inventory.available`.
An unavailable inventory includes an error; it is not a verified empty listing.

Before clearing the deployment, rollback lists the selected gzip tar archive with
its configured tar binary. A failed read stops cleanup and extraction. This
inspection runs after any optional safety backup, so an archive that disappeared
during retention also fails before deployment cleanup.

These checks validate inventory metadata and tar readability. They do not
validate archive member paths, symlinks or application identity; prove safe or
atomic extraction; or prevent changes between inspection and extraction. Live
backup and rollback verification remain required.

During rollback safety-backup retention, the selected archive and the newly
created backup are protected from pruning. Other archives outside the newest
configured retention window (minimum one) are still pruned. The retained count
can exceed that window to preserve protected files. The selected-archive pin
applies only to the current rollback; a new backup is always protected during
its own retention pass. Protection does not prevent concurrent deletion or
changes by another process.

## Confirmed external-link restoration gap

The readability check is insufficient to establish archive safety. A local
reproduction with bsdtar 3.5.3/libarchive 3.7.4 created an ordinary gzip tar
containing `public-link.txt`, a symlink to a synthetic private file outside the
source directory. Both `tar -tzf` and `tar -xzf ... -C restore` returned zero.
The restored link remained a symlink and read the outside file's contents.
The outside file itself was unchanged. All files were in a temporary directory;
no live account or real secret was accessed.

This archive can also be produced by the provider's existing backup command,
which preserves symlinks. Inventory checks on the archive file and successful
tar listing do not constrain archive members or link destinations. Whether a
restored link becomes publicly readable depends on the live server's document
root and symlink policy; that exposure has not been tested.

Rollback must remain under review until member types, paths, and link targets
are checked before clearing the current deployment, and extraction is verified
to remain within its intended directory. A complete solution also needs tests
for legitimate in-tree links, absolute and relative escaping links, hardlinks,
duplicate/overlapping entries, and changes between inspection and extraction.
Do not describe the current readability check as safe archive validation.

## Validator implementation in progress

`resources/validate-release.py` provides a standalone Python 3 archive inspection
step. Run its offline tests with `python3 tests/archive/test_validate_release.py`.
It reads gzip data through the trailer, reads regular-file payloads, rejects
unsupported member types and special permission bits, and checks canonical
member identities, parent types, symlinks and hardlinks. Symlink destinations
are resolved component by component, including intervening links; hardlinks must
refer to an archived regular file. Internal dangling symlinks are accepted.

This helper is not yet called by rollback. Its tests do not fix the confirmed
restoration gap. Integration must establish the remote Python runtime, capture
a private immutable archive copy, validate and extract that copy into an empty
private staging directory, and restore the verified tree without following
existing deployment links. Failures before staging is complete must preserve
the active deployment. The current tar readability command remains unchanged.

### Private staging helper

`python3 resources/validate-release.py ARCHIVE --stage NEW_DIRECTORY` creates a
new mode-0700 directory, copies the archive into a mode-0600 snapshot, validates
that snapshot, and builds `NEW_DIRECTORY/tree`. Existing destinations, including
symlinks, are refused. Regular files and directories are created before links;
archive member permissions and modification times are preserved without copying
ownership or special permission bits. Hardlinks are created only to staged
regular files. Failure removes the new staging directory. Success leaves it for
the caller to restore and then remove.

The staging parent must already be trusted. This helper does not defend against
another process running as the same account modifying its private directory.
It does not yet promote the tree into the live deployment, preserve the live
`.well-known` directory, or establish rollback application identity. Production
rollback still does not call this helper. Ten Python test methods cover member
validation and actual staging, including source replacement after inspection,
external-link rejection, mode/link preservation, and existing destination refusal.

### Promotion helper and recovery limits

`python3 resources/validate-release.py --promote STAGE ACTIVE` requires a private
staging directory containing `tree` and an existing real deployment directory.
It copies the live `.well-known` directory into the staged tree, replacing an
archived copy, then validates all staged filesystem links again. A live
`.well-known` symlink or copied link escaping the complete staged tree is
refused. No active files move until these checks complete.

The helper renames ACTIVE to STAGE/previous and then tree to ACTIVE. Failure of
the second rename attempts to restore the previous tree. Successful promotion
retains STAGE/previous and the snapshot for recovery. These are two renames,
not an atomic directory swap: a brief availability gap exists, and a process
crash or failed recovery rename requires restoring the retained previous tree.
Do not delete staging after an uncertain result. Cross-filesystem rename is
unsupported; failures preserve or attempt to restore the previous deployment.
Trusted parent directories and exclusion of concurrent deployment processes
remain caller requirements. Production rollback integration is still pending.
