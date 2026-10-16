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
