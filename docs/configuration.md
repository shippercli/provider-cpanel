# cPanel provider configuration

## Connection

| Key | Required | Description |
| --- | --- | --- |
| `host` | Yes | cPanel hostname or URL |
| `port` / `cpanel_port` | No | cPanel port; defaults to `2083` for HTTPS |
| `username` | Yes | cPanel account username |
| `api_token` | One credential | Preferred cPanel account API token |
| `password` | One credential | cPanel account password |
| `origin_ip` | No | Connect to a fixed origin IP while retaining the host for TLS |
| `verify_tls` | No | Verify the cPanel TLS certificate; defaults to `true` |
| `whm_username` | No | Reseller or root username for explicit WHM operations |
| `whm_api_token` | No | Token for explicit WHM operations |

## Deployment

| Key | Default | Description |
| --- | --- | --- |
| `deployment_method` | `auto` | `auto`, `fileman`, or cPanel-managed `git` |
| `cpanel.clean` | `true` | Remove managed deploy-path contents except `.well-known` before upload |
| `cpanel.archive_extraction` | `auto` | `auto`, `direct`, or monitored `cron` extraction |
| `cpanel.repository_root` | `/.shipper/repositories/<project>/<profile>` | Separate cPanel-managed Git repository path; `.cpanel.yml` deploys from here into `deploy_path` |
| `cpanel.git_deployment_timeout` | `300` | Maximum seconds to wait for the exact cPanel Git deployment task started by Shipper |
| `cpanel.git_deployment_interval_ms` | `2000` | Polling interval in milliseconds for the current Git deployment task, capped at 10 seconds |
| `cpanel.task_timeout` | `360` | Maximum seconds for a monitored account task |

`auto` uses cPanel Git when the project has a repository URL and the account
supports Git. It falls back to Fileman only when Git is unavailable.

cPanel Git repositories must not share the domain document root. Keep
`cpanel.repository_root` separate from `deploy_path`, and commit a `.cpanel.yml`
file that copies the intended deployment files into the document root.

Private repositories require Git credentials to be configured on the cPanel
account before deployment. Prefer short-lived credentials or a credential helper,
and remove temporary credentials after the deployment completes. Shipper never
embeds repository credentials in `shipper.yml`.

## Runtime

```yaml
runtime:
  type: php
  version: "8.4"
  install_dependencies: true
  php_ini:
    memory_limit: 256M
```

Supported runtime types are `static`, `php`, `nodejs`, `python`, and `ruby`.
Node.js, Python, and Ruby use cPanel Passenger and remain subject to the
runtimes installed by the hosting provider.

## Databases

Databases belong to the profile that owns them:

```yaml
databases:
  main:
    type: mysql
    name: app_production
    user: app_production
    password: "${DB_PASSWORD}"
    privileges: ALL PRIVILEGES
```

`mysql` and `postgresql` are supported. cPanel may prefix database and user
names with the account username. Shipper injects the resolved names into the
deployment environment.

## Releases

```yaml
cpanel:
  backup_before_deploy: true
  backup_before_rollback: true
  release_retention: 5
```

Release archives are stored under
`~/.shipper/releases/<project>/<profile>`, outside the deployment path.
They contain only the managed deployment files, not a full cPanel account or
database backup.

## DNS records

DNS records are opt-in and require a zone that the cPanel account can edit.
Shipper adopts an exact existing record without claiming ownership. It updates
or deletes only records that its manifest proves Shipper created.

```yaml
cpanel:
  dns_zone: example.com
  dns_records:
    verification:
      name: _shipper.api.example.com
      type: TXT
      data:
        - "${DEPLOYMENT_VERIFICATION}"
      ttl: 300
```

Record values are hashed before they enter the deployment manifest, so
verification tokens are not written into the document root. cPanel DNS changes
affect only zones for which the cPanel server is authoritative; externally
managed DNS such as Cloudflare remains separate.

## Email accounts and forwarders

```yaml
cpanel:
  email_accounts:
    deploy:
      address: deploy@api.example.com
      password: "${MAIL_PASSWORD}"
      quota: 250
      update_password: false
      delete_data: false
  email_forwarders:
    alerts:
      address: alerts@api.example.com
      forward_to: deploy@api.example.com
```

Use `create: false` to require an existing account without creating it.
`manage_existing: true` explicitly permits quota and opt-in password changes on
an adopted account. Passwords and password hashes are never stored in the
manifest. Destroy removes only Shipper-created accounts; mailbox data is
preserved unless `delete_data: true` was explicitly configured.

## FTP accounts

```yaml
cpanel:
  ftp_accounts:
    deploy:
      user: shipperdeploy
      domain: api.example.com
      password: "${FTP_PASSWORD}"
      home_directory: api
      quota: 500
      update_password: false
      delete_home: false
```

FTP home directories are relative to the cPanel account home. Existing accounts
are adopted without mutation unless `manage_existing: true` is explicit.
Destroy removes only Shipper-created FTP accounts and preserves their home
directories unless `delete_home: true` was recorded.

## Full-account backups

cPanel account users can request a full backup with
`Backup/fullbackup_to_homedir`, but Shipper does not run this expensive,
quota-consuming operation implicitly. Use an explicit `cpanel.operations`
entry when account-wide backup creation is required. Full-account restoration
is a privileged WHM operation and is intentionally not treated as an ordinary
project rollback.

## Account-specific operations

Long-tail cPanel operations can be attached to `before_apply`, `after_apply`,
`before_destroy`, or `after_destroy` through `cpanel.operations`. Each
operation selects `uapi`, `api2`, or `whm`, a module/function, and parameters.
The authenticated account must expose and authorize the requested operation.
