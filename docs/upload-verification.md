# Upload acceptance verification

A successful outer API status alone does not prove that cPanel stored a file.
The client requires `failed` to be zero, `succeeded` to match the requested file
count, and an upload report for each requested filename exactly once. Each
report must indicate success and a stored byte size matching the local file.
Canonical decimal strings are accepted alongside integer counts and sizes.
Empty files are valid, and response order need not match request order.

Missing reports, duplicate or unexpected names, malformed counters, failed
statuses and size mismatches fail the upload. A nonempty `error` or `errors`
field on either the overall upload report or a file record overrides matching
counts and sizes. Only absent, null, empty string, or empty array error fields
are accepted. Contradictory reports return a fixed failure message with no
response data or raw response, so echoed credentials cannot escape through them. Local file handles still close on
failure. Warnings remain available in successful results for caller inspection.

These checks verify cPanel's acceptance reports. They do not read back remote
contents, verify permissions, prove public serving, or roll back files stored
before a partial failure. Live upload verification remains required.

Run `python3 tests/http/test_upload_reports.py` after installing Composer
dependencies. The fixture binds loopback port 2082 and exercises 32 real HTTP
multipart requests across API token and Basic authentication, including an empty
file, reversed reports, canonical strings, invalid records, and echoed error
payloads. It checks the parsed multipart filenames, bytes, target directory,
and overwrite flag. A busy port fails before any request is sent. The fixture
uses synthetic credentials and does not contact a cPanel account.
