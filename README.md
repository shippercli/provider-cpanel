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
          adopt_existing: true
          backup_before_deploy: true
          release_retention: 5
```

`domain_type: existing` selects an existing domain but does not establish
ownership. Without a matching deployment manifest, apply requires the explicit
`cpanel.adopt_existing: true` option shown above. Review the domain, deployment
path, existing files, and account resources before enabling it: adoption permits
Shipper to overwrite application configuration and files. Prefer a profile-level
option to constrain adoption to that target. Provider-level `adopt_existing` is
also supported. Only a boolean `true` opts in; strings and numbers do not.

The resulting manifest records that the existing domain was not created by
Shipper, so cleanup does not claim ownership of the domain itself. Subsequent
applies require matching project, profile, and recorded domain scope. A manifest
belonging to another deployment is rejected even when adoption is enabled.

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
