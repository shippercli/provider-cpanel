# Deployment-directory cleanup

Cleanup requires a successful, readable top-level file inventory before any
trash operation. Documented `dirs`/`files` mappings and legacy flat lists are
supported. Nested metadata is not treated as a directory child. Duplicate names,
unknown types and names containing path separators or control characters fail
cleanup. The account home and primary `public_html` root are protected, including
aliases with redundant separators or `.` components.

The current API2 trash operation uses comma-separated paths and double decoding.
Directories or children containing commas or percent signs cannot be addressed
unambiguously by this implementation; cleanup refuses them. Names with leading
or trailing whitespace are also refused because existing path normalization
would alter them. Handle those entries explicitly before cleanup or use
`clean: false` when preserving existing contents is intended. This limitation
must not be bypassed by interpreting the names as additional paths.

`.well-known` is preserved. Tests verify that ordinary cleanup selects only
validated child names. These checks are not a transaction and do not prove inode identity or public
serving.
Other apply operations may already have changed resources before cleanup fails.
Live account verification remains required.

## Verify cleanup before uploading

After a nonempty deployment directory is sent to Fileman trash, Shipper reads
its inventory again. It proceeds only when no entries remain except `.`, `..`,
and `.well-known`. An existing `.well-known` entry must still be listed. A failed,
malformed, or incomplete cleanup result stops deployment before archive upload.
Shipper does not repeatedly trash unexpected entries that appear concurrently.

This check observes names reported by cPanel. It does not prove inode identity,
preserved file contents or protection against changes after
the inventory read. The trash operation may already have changed files before a
verification failure; cleanup and deployment are not transactional.


## Directory ancestry before trash

Before sending a nonempty cleanup batch to trash, Shipper reads
`Fileman/get_file_information` for the account home, each descendant directory,
and the cleanup target. Each response must report `type=dir`, `exists=1`, and the
exact requested `fullpath`. Reported links, other types, missing metadata,
conflicting paths, and API failures stop before trash. Metadata is not cached.

The [official API schema](https://api.docs.cpanel.net/specifications/cpanel.openapi/manage-files/fileman-get_file_information)
documents these fields and a distinct `link` type. This verifies API observations,
not a server-side no-follow handle: concurrent changes between requests can still
replace directories. Ancestors above the configured account home are trusted;
this check does not establish a shared apply/destroy lock or cover writes when
`clean: false`. Live cPanel behavior still requires verification.
