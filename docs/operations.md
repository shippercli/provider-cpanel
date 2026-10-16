# cPanel deployment operations

## Status

`shipper status` reports:

- manifest ownership and latest apply time
- runtime and deployment method
- cPanel Git task state when relevant
- domain inventory availability
- account resource usage availability
- deployment release identifiers

Optional cPanel modules are reported as unavailable instead of making the
entire status command fail.

## Logs

`shipper logs` reads the selected domain's Apache error log through
`Stats/get_site_errors`. The account must expose the domain statistics feature.
Use `--lines` from `1` through `5000`.

## Rollback

Rollback requires a matching Shipper manifest and at least one deployment
release:

```bash
shipper rollback app --profile=production
shipper rollback app --profile=production --release=20260727010101-deadbeef
```

Release identifiers cannot contain paths. Shipper cleans only the
manifest-owned deployment path, preserves `.well-known`, and extracts the
selected archive through a monitored account task.

## Transport error diagnostics

`python3 tests/http/test_diagnostics.py` checks the actual Guzzle HTTP path using
synthetic data and loopback servers. Its 240 cases cover token/Basic auth, eleven
HTTP failure statuses with JSON/text bodies, invalid JSON, and valid responses
for UAPI GET/POST, API2, WHM, and multipart upload. Expected credentials, query
values, bodies, and uploaded file contents must reach the fixture; error results
must retain status/shape without any echoed payload, including encoded Basic
auth. The original source fails the credential-echo negative control. CI runs
the check alongside the PHP matrix.

The fixture uses the documented non-TLS ports 2082/2086, bound locally before PHP
starts. An occupied port fails the check without contacting the existing service.
This establishes local HTTP error boundaries, not production TLS or live API
compatibility. Successful API data and application logs are not automatically
redacted by this check.


HTTP failures report a status code; connection and JSON decoding failures use
fixed error categories. Request URLs, response bodies and raw exception text
are omitted from these diagnostics because they can contain credentials.
This covers transport/JSON exceptions, including uploads; API-level JSON error
payloads and custom operation output still require separate review.

## Destroy

Destroy refuses to proceed without a manifest belonging to the requested
project and profile. It removes only resources recorded as Shipper-created:

- marker-owned cron jobs
- Passenger application registration
- database users and databases
- cPanel-managed Git repository
- aliases and domains
- the safe managed deployment path

Account roots, `/`, and `/public_html` are protected from recursive deletion.
