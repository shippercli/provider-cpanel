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

## Account-specific operations

Long-tail cPanel operations can be attached to `before_apply`, `after_apply`,
`before_destroy`, or `after_destroy` through `cpanel.operations`. Each
operation selects `uapi`, `api2`, or `whm`, a module/function, and parameters.
The authenticated account must expose and authorize the requested operation.
