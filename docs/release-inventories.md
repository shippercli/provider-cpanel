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
