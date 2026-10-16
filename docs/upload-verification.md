# Upload acceptance verification

A successful outer API status alone does not prove that cPanel stored a file.
The client requires `failed` to be zero, `succeeded` to match the requested file
count, and an upload report for each requested filename exactly once. Each
report must indicate success and a stored byte size matching the local file.
Canonical decimal strings are accepted alongside integer counts and sizes.
Empty files are valid, and response order need not match request order.

Missing reports, duplicate or unexpected names, malformed counters, failed
statuses and size mismatches fail the upload. Local file handles still close on
failure. Warnings remain available in successful results for caller inspection.

These checks verify cPanel's acceptance reports. They do not read back remote
contents, verify permissions, prove public serving, or roll back files stored
before a partial failure. Live upload verification remains required.
