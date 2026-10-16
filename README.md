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
