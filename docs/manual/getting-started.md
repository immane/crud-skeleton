# Getting Started

This page gets the application running and shows you how to verify it works.
Two paths are supported: **Docker Compose** (recommended, everything included) and
**native PHP** (uses a locally installed PHP toolchain).

## Prerequisites

| Tool | Version | Purpose |
|------|---------|---------|
| Docker Engine + Compose v2 | latest stable | Runs the full service stack (`compose.yaml`) |
| PHP | **8.4+** (project constraint `>=8.4`) | Native setup; the CLI requires `pdo_mysql`, `openssl`, `zip`, `intl` extensions |
| Composer | 2.x | Dependency and autoload management |
| OpenSSL | any recent | Generates the RSA JWT key pair |
| MySQL 8 client | 8.x | Optional — native `mysql` CLI for inspecting the container database |

> On macOS with Homebrew this project has been tested against **PHP 8.5**
> (`/opt/homebrew/opt/php`). PHP 8.4 from Homebrew works too — see
> [Troubleshooting](#troubleshooting) if `php` resolves to an older version.

## Services in `compose.yaml`

`docker compose up -d --build` starts a single networked stack. Only `nginx`,
`database`, `redis`, and `mailer` publish host ports; the PHP containers never do.

| Service | Image / Build | Purpose | Host port (default) |
|---------|---------------|---------|---------------------|
| `app` | built from `Dockerfile` | PHP-FPM serving the Symfony API | — |
| `worker` | same image | Messenger consumer for the `async` transport | — |
| `scheduler` | same image | Loop running outbox-publish and reservation/settlement housekeeping | — |
| `nginx` | `nginx:alpine` | Reverse proxy to PHP-FPM; serves `/` → `public/` | `${APP_PORT:-8080}:80` |
| `database` | `mysql:8.4` | Primary storage (Doctrine) | `3306:3306` via `compose.override.yaml` |
| `redis` | `redis:7-alpine` | Cache, OTP storage, rate limiter backing | — |
| `mailer` | `axllent/mailpit` | Email catcher (Mailpit UI) | `${MAILPIT_UI_PORT:-8025}:8025` |

`worker` and `scheduler` `extends: app`, so they share the same image and environment.
`nginx` has a full-path readiness probe through to `GET /health/ready`.

## Docker Quick Start

```bash
# 1. Build images and start every service
docker compose up -d --build

# 2. Apply the latest database schema
docker compose exec app php bin/console doctrine:migrations:migrate --no-interaction

# 3. Create an administrator account (email, screen name, password)
docker compose exec app php bin/console app:identity:user:create admin@example.com admin 'P@ssw0rd' --admin
docker compose exec app php bin/console app:authorization:seed
```

That is enough to log in and exercise the API. The last line seeds Authorization permissions/roles/field-grants (idempotent; see [Authorization Setup](authorization.md)). The JWT key pair is generated
automatically on first container start by `docker/app/entrypoint.sh` into
`var/jwt/`. No environment file is required for a first run — `compose.yaml`
provides safe development defaults.

Common follow-up commands:

```bash
# Tail app logs
docker compose logs -f app

# Reset the database (dev only)
docker compose exec app php bin/console doctrine:schema:drop --force
docker compose exec app php bin/console doctrine:migrations:migrate --no-interaction

# Stop the stack (keep volumes)
docker compose down
```

## Native PHP Setup

The optional `compose.override.yaml` binds the source tree and exposes MySQL and
Mailpit locally, but you can also run everything directly on the host:

```bash
# 1. Install dependencies (Docker is not required for this step)
composer install

# 2. Create your local environment file
cp .env .env.local
```

Configure `.env.local` before the next steps:

```dotenv
APP_ENV=dev
APP_SECRET=your-secret
DATABASE_URL="mysql://app:!ChangeMe!@127.0.0.1:3306/app?serverVersion=8.0&charset=utf8mb4"

JWT_PRIVATE_KEY_PATH=%kernel.project_dir%/var/jwt/jwt_private.pem
JWT_PUBLIC_KEY_PATH=%kernel.project_dir%/var/jwt/jwt_public.pem
JWT_PASSPHRASE=
REFRESH_TOKEN_SECRET=your-refresh-secret
```

### Generate the JWT key pair

The JWT authenticator signs access tokens with RS256, so a private/public RSA pair
is required. Create it under `var/jwt/` (`.gitignore`d):

```bash
mkdir -p var/jwt

# Private key (optionally guarded by a passphrase)
openssl genpkey -algorithm RSA -pkeyopt rsa_keygen_bits:2048 \
  -out var/jwt/jwt_private.pem

# Public key derived from the private key
openssl rsa -in var/jwt/jwt_private.pem -pubout -out var/jwt/jwt_public.pem

# Restrict permissions: the private key must not be world-readable
chmod 600 var/jwt/jwt_private.pem
```

If you set a `JWT_PASSPHRASE`, re-run the private-key generation with
`-aes256` and (optionally) `-pass pass:...`, and set `JWT_PASSPHRASE` to the same
value in `.env.local`.

### Create the database and run migrations

```bash
php bin/console doctrine:database:create
php bin/console doctrine:migrations:migrate
```

### Serve the API

```bash
php -S localhost:8000 -t public/
```

In `APP_ENV=dev` the built-in server is enough for local work; the app is also fully
functional behind nginx (see `docker/nginx/default.conf` for the production shape).

## JWT Environment Variables

| Variable | Default (compose) | Purpose |
|----------|-------------------|---------|
| `JWT_PRIVATE_KEY_PATH` | `/var/www/html/var/jwt/jwt_private.pem` | Path to the RSA private key (PEM) used for signing access tokens |
| `JWT_PUBLIC_KEY_PATH` | `/var/www/html/var/jwt/jwt_public.pem` | Path to the RSA public key (PEM) used for verification |
| `JWT_PASSPHRASE` | *(empty)* | Optional passphrase protecting the private key |
| `REFRESH_TOKEN_SECRET` | `dev-refresh-secret-change-me` | HMAC secret for refresh-token hashing and rotation |
| `ACCESS_TOKEN_TTL` | `7200` (seconds) | Access-token lifetime |
| `REFRESH_TOKEN_TTL` | `31536000` (seconds) | Refresh-token lifetime |

Related optional variables: `OTP_REDIS_DSN`, `ALIYUN_*` (SMS), `WECHAT_*`
(WeChat login/pay), `MAILER_DSN`, `MESSENGER_TRANSPORT_DSN`, `INVENTORY_ENABLED`.
See `.env.example` for the full reference.

## Async workers (Trade → Store)

Docker Compose starts `worker` (`messenger:consume async --time-limit=3600`) and `scheduler` (outbox publish every 5s) automatically. For native PHP or one-shot local verification after creating a `X-Store-Code` order:

```bash
# One-shot: publish loop (5s) in background + consume for 60s (default)
./scripts/dev/run-async.sh          # 60s
./scripts/dev/run-async.sh 120      # 120s / 2m
./scripts/dev/run-async.sh 10 --interval 2  # publish every 2s
./scripts/dev/run-async.sh --dry-run
# Inside Docker
docker compose exec app ./scripts/dev/run-async.sh 60
```
The loop is required because `TradeOrderCreatedHandler` creates `store_outbox` rows *during* `consume`; a single pre-publish would miss them.

For long-running native workers, keep the two terminals from `QUICKSTART.md`:
`messenger:consume async --time-limit=3600` + `while true; do app:*:outbox:publish; sleep 5; done`.

## Verifying the Setup

```bash
# Swagger UI (also available at http://localhost:8000/api/doc natively)
curl -s http://localhost:8080/api/doc | head -c 200

# Health/readiness returns JSON when the app, DB, and Redis are reachable
curl -s http://localhost:8080/health/ready
```

Log in and capture a token:

```bash
curl -s -X POST http://localhost:8080/api/auth/login \
  -H 'Content-Type: application/json' \
  -d '{"email":"admin@example.com","password":"P@ssw0rd"}'
```

Use the returned `token` for authenticated calls:

```bash
curl -s http://localhost:8080/api/v1/profile \
  -H "Authorization: Bearer <token>"
```

## Troubleshooting

| Symptom | Likely cause | Fix |
|---------|--------------|-----|
| `Doctrine\DBAL\Exception\DriverException: could not find driver` | The `pdo_mysql` PHP extension is missing | Check `php -m \| grep -i mysql`. On macOS Homebrew: `brew install php` bundles it; ensure `/opt/homebrew/opt/php/bin/php` is first on `PATH` |
| `JWT ... key not found` / 401 on protected routes | JWT keys not generated, or paths mismatch `.env.local` | Run the openssl commands above and verify `JWT_PRIVATE_KEY_PATH` / `JWT_PUBLIC_KEY_PATH` are absolute and point at existing PEM files |
| `Permission denied` when the app reads the private key | Private key mode too open | `chmod 600 var/jwt/jwt_private.pem` (it is git-ignored) |
| `Address already in use: 8080` / `8000` | Another process occupies the port | `docker compose` — set `APP_PORT=8090 docker compose up -d`; native — pick another port, e.g. `php -S localhost:8001 -t public/` |
| `php` is an old version (e.g. 7.4) on macOS | Homebrew `php` not linked/first | Run `/opt/homebrew/opt/php/bin/php bin/console ...` or `PATH="/opt/homebrew/opt/php/bin:$PATH" php ...` |
| Migrations fail with `Unknown database 'app'` | Database not created | `docker compose exec app php bin/console doctrine:database:create` (native: run the same without `exec`) |
| Mail not arriving | Mailpit not running or wrong DSN | Native: start Mailpit (`docker compose up -d mailer`) and use `MAILER_DSN=smtp://127.0.0.1:1025`; check the UI at `http://localhost:8025` |