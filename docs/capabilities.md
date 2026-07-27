# cPanel capability model

Shipper's cPanel provider must distinguish API reachability from feature
availability. A hosting plan can disable modules, and account credentials cannot
perform root or reseller operations.

## API surfaces

| Surface | Purpose | Authentication | Shipper support target |
| --- | --- | --- | --- |
| cPanel UAPI | Account files, Git, databases, PHP, Passenger apps, SSL, domains, DNS, redirects, usage, and backups | cPanel password or API token | First-class wrappers plus generic calls |
| cPanel API 2 | Operations with no UAPI replacement, including cron, addon domains, aliases, archive extraction, and some cleanup operations | cPanel account through the cPanel JSON API proxy | Compatibility wrappers plus generic calls |
| WHM API 1 | Account creation/removal, packages, quotas, server PHP/FPM, DNS zones, account backup/restore, services, and other privileged operations | WHM root or reseller password/API token | Optional generic calls and explicit privileged lifecycle features |

Official sources:

- https://api.docs.cpanel.net/specifications/cpanel.openapi/
- https://api.docs.cpanel.net/cpanel-api-2/
- https://api.docs.cpanel.net/specifications/whm.openapi/

## First-class deployment capabilities

| Capability | Required behavior | Account API |
| --- | --- | --- |
| Capability discovery | Read enabled features and fail with a precise unavailable-feature message | `Features/list_features` |
| Static and PHP deployment | Upload an archive, extract large archives through a temporary marker-owned cron task, clean managed artifacts, and preserve unrelated files | `Fileman/upload_files`, `Fileman/*`, API 2 `Cron/*` |
| Git deployment | Create or update a cPanel-managed repository, select the branch, trigger deployment, and report task state | `VersionControl/*`, `VersionControlDeployment/*` |
| Domain lifecycle | Detect existing domains; create and clean up managed subdomains, addon domains, and aliases; control document roots | `DomainInfo/*`, `SubDomain/*`, API 2 `AddonDomain/*`, API 2 `Park/*` |
| PHP runtime | Discover installed versions, set the vhost version, and configure supported `php.ini` directives | `LangPHP/*` |
| Node.js, Python, and Ruby runtime | Register, update, enable, disable, and remove Passenger applications; install declared dependencies | `PassengerApps/*` |
| Environment variables | Write managed environment files for file-based applications and synchronize Passenger environment variables | `Fileman/save_file_content`, `PassengerApps/edit_application` |
| MySQL and PostgreSQL | Create databases and users, set passwords and privileges, expose resolved names to deployment environment, and perform opt-in cleanup | `Mysql/*`, `Postgresql/*` |
| Cron | Reconcile marker-owned cron entries without deleting unrelated user entries | API 2 `Cron/*` |
| Redirects and HTTPS | Reconcile redirects, start AutoSSL, install custom certificates, and toggle HTTPS redirect where supported | `Mime/*`, `SSL/*` |
| Backups and rollback | Create deployment-scoped archives outside the managed web root, report release identifiers, enforce retention, and restore an explicit or latest release | Fileman operations and marker-owned cron tasks |
| Observability | Return manifest and deployment state, Git task state, release identifiers, account resource usage, and domain error-log lines | `VersionControlDeployment/retrieve`, `Stats/get_site_errors`, `ResourceUsage/get_usages` |
| Destroy safety | Delete only resources recorded as Shipper-managed; refuse destructive cleanup without a matching manifest | Domain, database, Passenger, cron, Git, and Fileman APIs |

## Long-tail coverage

cPanel exposes substantially more account functionality than a deployment
schema should model, including email, FTP, Web Disk, DNSSEC, ModSecurity,
directory privacy, hotlink protection, dynamic DNS, team users, and backup
destinations. The provider must expose typed generic `uapi`, `api2`, and `whm`
calls so these operations are available without pretending they belong in every
project configuration.

Generic calls do not bypass cPanel authorization. A call remains unavailable
when the authenticated account, hosting package, server profile, or installed
plugin does not expose the requested operation.

## Stable release gate

The provider can leave beta only when all of the following are true:

1. The provider is executable from `shippercli/provider-cpanel`; no cPanel
   implementation is required from the CLI repository.
2. The CLI discovers installed `shipper-plugin` packages through Composer's
   runtime API.
3. Unit tests cover UAPI, API 2, WHM, authentication, response envelopes,
   feature-disabled errors, idempotency, and destroy safety.
4. Private sample repositories verify raw HTML, raw PHP, Laravel with a real
   database, cPanel Git deployment, and Passenger configuration. A live
   Node.js HTTP assertion runs only on accounts with an EA Node.js runtime.
5. Live workflows verify domain creation, PHP version selection, environment
   variables, database/user/privileges, cron, AutoSSL/HTTPS, deployment status,
   rollback, and opt-in cleanup.
6. The temporary repository containing raw cPanel credentials is deleted and
   the exposed password is rotated.
7. Provider metadata and website documentation match verified behavior rather
   than intended behavior.

The current test account exposes CloudLinux Node Selector but no EA Node.js
runtime. Shipper verifies Passenger registration and dependency configuration
there, while the HTTP runtime assertion remains an explicit host prerequisite.
