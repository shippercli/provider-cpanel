# DNS inventory validation

DNS reconciliation requires a readable zone containing one SOA record for the
requested zone. Serial numbers, record line indices and TTLs must be unsigned
32-bit integers or canonical decimal strings. Duplicate record indices,
ambiguous SOAs, malformed record names, invalid base64 data and unknown row
types stop creation, updates and manifest-driven deletion. Comment and control
rows remain supported.

Malformed records are never skipped as evidence that a configured record is
absent. Zone edits still use cPanel's SOA serial guard. These checks do not make
multiple DNS operations transactional, and do not verify live DNS propagation.
