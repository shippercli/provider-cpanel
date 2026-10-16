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
