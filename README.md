# Shipper CLI cPanel provider

Deploy and operate applications on cPanel accounts from Shipper CLI.

## Install

```bash
composer require shippercli/provider-cpanel
```

Shipper discovers the provider through Composer's plugin metadata. No cPanel
implementation is bundled into the CLI.

## Configure

Use a cPanel API token when the hosting provider allows one. Password
authentication remains available for hosts that do not expose account tokens.

```yaml
providers:
  cpanel:
    host: "cpanel.hosting.com"
    port: 2083
    username: "${CPANEL_USERNAME}"
    api_token: "${CPANEL_API_TOKEN}"

projects:
  app:
    provider: cpanel
    path: "."
    web_directory: /public
    profiles:
      production:
        branch: main
        domain: "api.example.com"
        deploy_path: "/api"
        runtime:
          type: php
          version: "8.4"
          install_dependencies: true
        cpanel:
          domain_type: existing
          backup_before_deploy: true
          release_retention: 5
```

See [configuration](docs/configuration.md) for runtime, database, cron, SSL,
release, and account-specific options.

## Operate

```bash
shipper validate
shipper plan app --profile=production
shipper apply app --profile=production
shipper status app --profile=production
shipper logs app --profile=production --lines=100
shipper rollback app --profile=production
shipper destroy app --profile=production
```

The implementation targets cPanel account UAPI, the remaining operations that
only exist in cPanel API 2, and optional privileged WHM API operations. See the
[capability model](docs/capabilities.md) for availability rules and the
[operations guide](docs/operations.md) for rollback and cleanup safety.

## Existing database user passwords

Profile database entries may set `update_password: true` to rotate an existing
MySQL or PostgreSQL user's password before privileges and application deployment.
If cPanel refuses that update, apply fails. The default is false: existing user
passwords are left unchanged, so the configured password must already be correct.
This option affects the account-level database user and every application using
that user; coordinate rotations of shared credentials. Newly created users get
the configured password through `create_user`.

PostgreSQL updates use cPanel's documented
[`Postgresql/set_password`](https://api.docs.cpanel.net/specifications/cpanel.openapi/postgresql-user-management).
Offline tests check request parameters and failure behavior; a live database
connection is still needed to verify the resulting credentials.
