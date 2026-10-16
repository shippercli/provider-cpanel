# Exact DNS ownership and migration

DNS state now records `data_hash_version: 2`. The hash preserves each RDATA
field's bytes and order, including TXT whitespace. Domain-valued fields retain
case and trailing-period normalization. Hashing uses base64-encoded fields so
unrelated binary TXT values remain readable. Raw DNS values are not stored in
the manifest. Multiple exact matches are ambiguous and stop reconciliation.

Older hashes trimmed every field. They cannot prove which whitespace variant
was created. An owned DNS manifest entry without integer version 2 therefore
blocks account-resource reconciliation and destruction before their API calls.
There is no automatic ownership migration. Do not add a version number to an
old hash: it uses a different encoding and does not establish ownership.

Before upgrading an existing managed deployment, inspect its DNS entries,
current zone and exact record contents. To relinquish automatic DNS ownership,
back up the manifest and explicitly set each legacy DNS entry's `created` field
to `false`. A subsequent apply can discover an exact matching record as unowned;
it will not remove it on destroy or alter its TTL. Arrange any required removal
or TTL correction manually after verifying the record's owner. Preserve the
manifest's project, profile and domain scope while editing it.

This is a breaking ownership safeguard for existing managed DNS records. Test
migration on a dedicated account before production use. Offline regressions do
not establish live cPanel behavior or public DNS propagation.
