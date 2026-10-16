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
