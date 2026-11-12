# API error responses

UAPI, API2 and WHM accept only canonical success flags (`1`, `"1"`, or `true`).
Explicit `errors` or `error` fields override success. The same rule applies to
API2 operation results and reported upload statuses.

Failed JSON responses expose a fixed diagnostic category and omit partial data
and raw response bodies. Categories retain existing provider checks for missing
resources, already-existing resources, access denial, unavailable features and
timeouts. Remote error text is not copied into diagnostics because it may echo
credentials, file contents or other private data.

Successful responses keep their data and raw payload for provider operations
such as reading manifests and application files. Their message is `OK`. Callers
must still handle successful custom-operation output as potentially sensitive.
Transport exceptions are covered separately by the transport diagnostic change.


## Object roots and real HTTP regression

The JSON decoder requires an object root. Valid JSON scalars, null, and arrays
return the normal safe failure result instead of throwing an uncaught decoder
exception. Unknown/contradictory success flags still fail validation.

`python3 tests/http/test_json_failures.py` runs 150 cases through actual Guzzle
HTTP: token/Basic auth; UAPI GET/POST, API2, WHM, and multipart upload; malformed
status flags, absent flags, explicit and nested error fields, non-object roots,
and canonical `1`, `"1"`, and `true` success controls. Failed results must clear
message payloads/data/raw without exposing echoed credentials or file contents.
Successful data remains readable. Restoring the original client exposes a
synthetic credential in the API-error negative control. CI runs this check with
the PHP matrix.

Fixtures bind loopback non-TLS ports 2082/2086 before making requests and use
synthetic data only. Occupied ports fail without contacting another service.
This checks API JSON normalization; transport exceptions and redirects are
covered separately. It does not prove live cPanel compatibility or application
log redaction.
