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
validated child names. These checks are not a transaction, do not validate every
ancestor against symlinks, and do not prove remote deletion or public serving.
Other apply operations may already have changed resources before cleanup fails.
Live account verification remains required.
