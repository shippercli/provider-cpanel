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

## Destroy

[Directory cleanup validation](directory-cleanup.md) explains inventory, root
protection, and filename limits during apply and rollback.

Destroy refuses to proceed without a manifest belonging to the requested
project and profile. It removes only resources recorded as Shipper-created:

- marker-owned cron jobs
- Passenger application registration
- database users and databases
- cPanel-managed Git repository
- aliases and domains
- the safe managed deployment path

Account roots, `/`, and `/public_html` are protected from recursive deletion.


### Verify directory cleanup before uploading

After a nonempty deployment directory is sent to Fileman trash, Shipper reads
its inventory again. It proceeds only when no entries remain except `.`, `..`,
and `.well-known`. An existing `.well-known` entry must still be listed. A failed,
malformed, or incomplete cleanup result stops deployment before archive upload.
Shipper does not repeatedly trash unexpected entries that appear concurrently.

This check observes names reported by cPanel. It does not prove inode identity,
symlink ancestry, preserved file contents, or protection against changes after
the inventory read. The trash operation may already have changed files before a
verification failure; cleanup and deployment are not transactional.
