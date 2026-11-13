# Account home directory resolution

File operations require a verified account home path. By default the provider
reads the top-level `home` field from Variables/get_user_information. The legacy
`homedir` field remains accepted. If both are present they must resolve to the
same path. Failed, missing, malformed, nested-only, or conflicting responses stop
operations; the provider no longer guesses `/home/<username>`.

A provider-level `home_directory` string can explicitly supply the path without
the lookup. Use an absolute, non-root path without traversal, dot segments,
controls, backslashes, commas, percent signs, or surrounding whitespace. Commas
and percent signs cannot be addressed reliably through cPanel's CSV file
operations and double decoding. A trailing slash is removed. An omitted or
empty string selects API discovery; other types, including null, are rejected.

The resolved path is cached for the provider instance. Changing API credentials
or account requires a new instance. Path validation does not establish server
filesystem ownership, resolve symlinks, or prove the explicit override belongs
to the authenticated account. Use the account's actual home directory.

Schema reference: https://api.docs.cpanel.net/_spec/specifications/cpanel.openapi.json
(operation Variables/get_user_information, result.data.home).
