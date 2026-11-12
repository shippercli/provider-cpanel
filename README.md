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
          adopt_existing:
            project: app
            profile: production
            domain: api.example.com
          backup_before_deploy: true
          release_retention: 5
```

`domain_type: existing` selects an existing domain but does not establish
ownership. Without a matching deployment manifest, apply requires the explicit
`adopt_existing` tuple shown above to match the exact project, profile, and
domain. Review the domain, deployment path, existing files, and account
resources before enabling it: adoption permits Shipper to overwrite application
configuration and files. A consent tuple for another identity or scope does not
authorize adoption.

The resulting manifest records `ownership_state: adopted` and `created: false`
for the domain, so cleanup does not claim ownership of the domain itself.
Subsequent applies require matching project, profile, and recorded domain scope.
A manifest belonging to another deployment is rejected even when adoption is enabled.

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

### Plan ownership inspection

Manifests must include a nonempty target domain in `domain.domain`, alongside
project and profile identity. Missing or malformed domain evidence requires
operator review and migration before use; `adopt_existing` does not bypass an
invalid manifest. Review the actual deployment path, domain, project/profile,
and recorded resources before restoring this evidence. Shipper does not infer
the domain from the current command or automatically rewrite the manifest.

Planning reads the deployment manifest and, when no manifest exists, domain
inventory. It blocks a mismatched/corrupt/unreadable manifest or an existing
domain without the explicit reviewed `adopt_existing` tuple matching its exact
project, profile, and domain. An authorized adoption plan names the domain
explicitly. Planning performs no writes and returns `blocked: true` with one public reason when
ownership cannot be established. CLI enforcement is proposed in
shippercli/cli#154. Account-feature and other resource prerequisites remain
subject to checks during apply; an unblocked plan does not certify live readiness.
