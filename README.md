# Shipper CLI cPanel provider

Deploy and operate applications on cPanel accounts from Shipper CLI.

## Install

```bash
composer require shippercli/provider-cpanel
```

Shipper discovers the provider through Composer's plugin metadata. No cPanel
implementation is bundled into the CLI.

## Existing database user passwords

Profile database entries may set `update_password: true` to rotate an existing
MySQL or PostgreSQL user's password before privileges and application deployment.
Use an unquoted YAML boolean; strings, numbers, null, and other types are rejected
by validation, planning, and direct apply before API calls. If cPanel refuses
that update, apply fails. The default is false: existing user
passwords are left unchanged, so the configured password must already be correct.
This option affects the account-level database user and every application using
that user; coordinate rotations of shared credentials. Newly created users get
the configured password through `create_user`.

PostgreSQL updates use cPanel's documented
[`Postgresql/set_password`](https://api.docs.cpanel.net/specifications/cpanel.openapi/postgresql-user-management).
Offline tests check request parameters and failure behavior; a live database
connection is still needed to verify the resulting credentials.

## Configure

Use a cPanel API token when the hosting provider allows one. Password
authentication remains available for hosts that do not expose account tokens.

Configure the final API endpoint directly; authenticated API calls reject
redirects. See the [API redirect policy](docs/api-redirects.md).

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

### Git deployment status output

`shipper status` exposes a summary of saved Git deployment tasks and retrieved
Git deployment records: identifiers, method, repository root, state/status, and
recognized lifecycle timestamps. Commands, logs, output, and unknown task fields
are omitted. Unavailable or malformed Git status responses use fixed diagnostics.
This changes the status JSON shape; consumers needing command output should use
an explicit log or API operation. Other status sections retain their existing
behavior. This does not establish live cPanel deployment verification.

Runtime data in both `shipper plan` and `shipper status` is also summarized:
only type, version, application root, base URI and the dependency-install flag
are included. PHP INI directives can contain credentials or command arguments
and are omitted from these outputs. The full configured directives continue to
be used when applying runtime configuration.
