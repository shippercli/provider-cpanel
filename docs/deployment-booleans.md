# Deployment boolean options

The profile's `cpanel` options require genuine YAML booleans for:

| Option | Default |
| --- | --- |
| `clean` | `true` |
| `backup_before_deploy` | `false` |
| `backup_before_rollback` | `false` |
| `environment_file` | `false` |

Validation, planning, direct apply and rollback reject strings, numbers, null
and other types before API calls. Use `clean: false`, without quotes. PHP treats
nonempty strings such as `"false"` as true; those values must not authorize
cleanup, backups or environment-file creation. Existing quoted or interpolated
string flags must be replaced with explicit booleans.

`clean` controls Fileman apply cleanup. Rollback has its own restore cleanup.
With `clean: false`, deployment can still replace uploaded application files and
write managed metadata; it preserves extra directory contents instead of doing
the initial cleanup. Existing genuine booleans and omitted defaults retain their
behavior. Live hosting verification remains required.

## Resource activation and dependency installation

The same strict checks cover profile `cron.*.enabled`, `redirects.*.enabled`,
`ssl.enabled`, `ssl.force_https`, and the effective runtime's
`install_dependencies`. Cron and redirect `enabled` values default to true;
SSL `enabled` and `force_https` default to false; dependency installation defaults
to true. The cPanel runtime mapping overrides matching profile runtime fields
before validation, preserving existing configuration precedence.

Project-object `enabled()` and `forceHttps()` getters must return genuine
booleans. Invalid profile flags or getter results stop validation, planning,
apply and rollback before API calls. These checks do not establish whether the
CLI's earlier configuration parsing correctly preserves every raw YAML type;
that remains a separate integration requirement.
