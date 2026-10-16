# VPNRENT Enterprise

The initial billing, Midtrans sandbox, reseller ledger and CHR provisioning workflows are now implemented. See [integration setup and current limitations](docs/INTEGRATIONS.md). They have not yet been tested with a real Midtrans account or CHR gateway. This is not a production-complete release.

VPNRENT Enterprise is a Laravel SaaS foundation for an authorized, multi-tenant VPN rental operation. This repository deliberately does **not** claim a WireGuard, payment-gateway, MikroTik, or VPS integration until that component is separately implemented and tested against real infrastructure.

## Phase 1 — implemented scope (acceptance still pending)

- Laravel 12 (PHP 8.2 compatible) and Blade/Tailwind foundation.
- Laravel Breeze authentication: registration, login, verified-email flow, password reset, secure session handling, and CSRF protection.
- Backend-enforced roles and permissions for Super Admin, Infrastructure Admin, Tenant Owner, Tenant Operator, Finance, Support, Reseller, Customer, and Auditor.
- Organization (tenant) membership, active tenant context middleware, organization-switch authorization, and organization policy.
- Audit log schema and audited authentication/context actions.
- Responsive enterprise dashboard based only on current database records. VPN / billing / capacity metrics are explicitly not displayed until their verified data sources exist.
- Database migrations, development roles/admin seeder, PHPUnit feature tests, and GitHub Actions test workflow.

## Architecture

```text
Browser → Laravel middleware (auth → verified → tenant context)
        → controllers / policies → models / services → MySQL or MariaDB
```

All new tenant-owned modules in subsequent phases must carry an `organization_id`, resolve it through `OrganizationContext`, and use policies/server-side queries to prevent cross-tenant access. UI visibility is never authorization.

## Local XAMPP setup

### Prerequisites

- PHP 8.2+ with `pdo_mysql`, `mbstring`, `openssl`, and `fileinfo` enabled.
- Composer 2.
- Node.js 24+ and npm.
- XAMPP Apache and MySQL/MariaDB.

### Database

Start **MySQL** in the XAMPP Control Panel, then create a local-only development database in phpMyAdmin or run:

```sql
CREATE DATABASE vpnrent_development
  CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
```

Copy `.env.example` to `.env`, set a unique `APP_KEY` with `php artisan key:generate`, and keep the local database credentials in `.env`. Do not commit that file.

```powershell
php artisan migrate --seed
npm install
npm run build
```

For a local PHP development server:

```powershell
php artisan serve
```

For Apache, point a virtual host or alias at this project’s `public` directory — never the repository root. Then visit the configured local URL.

`db:seed` creates a development-only administrator:

```text
Email: admin@vpnrent.test
Password: ChangeMe123!
```

Set `VPNRENT_DEV_ADMIN_PASSWORD` before seeding if a different local password is required. Change or remove this development account before any shared environment.

## Tests

```powershell
php artisan test
```

The test suite uses an in-memory SQLite database and covers dashboard authorization, cross-tenant organization switching, and backend role/permission resolution. Tests do not validate a real VPN gateway.

### Verification update — 16 October 2026

33 tests passed with 77 assertions. Added regression coverage for accounts without an active tenant, inactive organizations, suspended membership, and audit permissions. Dashboard audit queries now fail closed when tenant context or permission is missing. Development administrator seeding is restricted to local/testing environments. The environment template quotes the application name and CI generates its application key.

MariaDB 10.4.32 was started with a localhost binding and a new `vpnrent_development` database was created. Local `.env` now selects MySQL and encrypted sessions; the earlier SQLite file remains intact. Apache port 80 was not accepting connections during verification, so browser acceptance through XAMPP Apache remains pending. Phase 2 has not started. The private GitHub repository and production VPS deployment have not been created.

## Planned delivery sequence

1. **Foundation** — current phase.
2. Commercial core: plans, orders, invoices, subscriptions, payment adapter contracts.
3. VPN engine: verified server inventory, IPAM, WireGuard provisioning jobs, configuration delivery.
4. Automation: renewal, expiration, suspension, retry, notifications.
5. MikroTik / cluster: authorized router integration, gateway monitoring, failover design.
6. Reseller: accounts, balances, commissions, scoped customer access.
7. Production: hardening, deployment, backups, workers, scheduler, monitoring, acceptance tests.

## GitHub workflow

Use a private GitHub repository. Suggested branch workflow:

```text
feature/* → staging → main
```

Before committing:

```powershell
php artisan test
git status
git add .
git commit -m "feat: establish identity and tenant foundation"
git push origin feature/<name>
```

`.env`, private keys, generated frontend assets, dependencies, and logs are ignored. GitHub Actions runs Composer install, frontend build, and PHP tests for `staging` and `main` pull requests/pushes.

## VPS deployment (not performed by this project)

Production deployment requires a separately provisioned Linux VPS, HTTPS, a restricted service account, MySQL/MariaDB, a queue worker, scheduler, backup procedure, firewall rules, and a real WireGuard gateway. Do not expose MikroTik management ports, run browser-provided shell commands, or place VPN private keys in logs, URLs, analytics, or general database fields.

Before deployment: inject production secrets through the server’s secret-management process, run a reviewed backup, apply non-destructive migrations, build assets, clear/cache config appropriately, and run health checks. No production VPS action is made by the Phase 1 code.
