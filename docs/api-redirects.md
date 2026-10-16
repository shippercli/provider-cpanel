# API redirect policy

Authenticated UAPI, API2, WHM and upload requests do not follow redirects.
Any 3xx response fails with `cPanel API redirects are not allowed`; a success
flag inside its JSON body cannot override the HTTP response. This includes
same-host redirects, redirects to another host and HTTPS-to-HTTP redirects.

Configure the final API hostname and port directly. An API redirect can forward
request bodies, passwords or application files, even when a client removes the
Authorization header. Fix reverse-proxy or control-panel routing rather than
enabling redirects to make a failing API call pass.

Tests exercise the actual Guzzle middleware with a mock transport, require one
request only, and cover API GET/POST and multipart uploads. They do not verify
live hosting or network routing.

## Real HTTP regression

`python3 tests/http/test_redirects.py` uses the real Guzzle handler and local
HTTP servers. It checks 301/302/303/307/308 with relative and cross-origin
locations, token and Basic auth, UAPI GET/POST, API2, WHM, and multipart upload.
All 100 source requests must contain the expected synthetic authentication and
payloads; redirect destinations must receive zero requests. The server returns a
success-shaped JSON body on redirects to ensure status rejection takes priority.
Enabling redirect following makes the negative control detect a replay.

The test binds loopback ports 2082 and 2086 because those documented ports select
HTTP in the client. Occupied ports cause a test failure; no existing local service
is contacted. A third ephemeral port is the cross-origin destination. All data is
synthetic. This checks redirect behavior, not production TLS or live cPanel API
compatibility. CI runs it alongside the PHP matrix.
