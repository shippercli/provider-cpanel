# Release archive inventories

Release listing accepts documented `dirs`/`files` mappings and legacy flat row
lists. Every entry must have an unambiguous basename and recognized file type.
A filename matching the release archive format must be an ordinary file;
symlinks and directories cannot be rollback candidates. Duplicate names and
unreadable inventories fail rollback and retention checks before they use that
inventory to authorize changes.

Status retains its `releases` array and adds `release_inventory.available`.
An unavailable inventory includes an error; it is not a verified empty listing.

These checks validate inventory metadata. They do not inspect archive contents,
prove tar extraction safety, establish atomic restore behavior, or prevent
concurrent changes after listing. Live backup and rollback remain required.
