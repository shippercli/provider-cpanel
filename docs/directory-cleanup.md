# Verify directory cleanup before uploading

After a nonempty deployment directory is sent to Fileman trash, Shipper reads
its inventory again. It proceeds only when no entries remain except `.`, `..`,
and `.well-known`. An existing `.well-known` entry must still be listed. A failed,
malformed, or incomplete cleanup result stops deployment before archive upload.
Shipper does not repeatedly trash unexpected entries that appear concurrently.

This check observes names reported by cPanel. It does not prove inode identity,
symlink ancestry, preserved file contents, or protection against changes after
the inventory read. The trash operation may already have changed files before a
verification failure; cleanup and deployment are not transactional.
