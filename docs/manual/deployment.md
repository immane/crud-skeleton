# Docker Deployment

This document is the operational reference for running the CRUD Skeleton in
Docker. It mirrors the README "Docker Deployment" section (starting at
README.md:646) and documents every service, variable, and startup step in
detail.

---

## Architecture

```mermaid
flowchart LR
    Client[Client / Browser] -->|:8080| Nginx[nginx:alpine]
    Nginx -->|/api/*| Fpm["PHP-FPM 8.4<br/>(app, Symfony)"]
    Nginx -->|/api/doc| Swagger[Swagger UI<br/>NelmioApiDoc]
    Fpm --> MySQL[(MySQL 8)]
    Fpm --> Redis[(Redis 7<br/>OTP / cache)]
    Fpm --> Mailpit[Mailpit<br/>email dev]
    Fpm --> Worker[Messenger worker<br/>handlers / outbox]
    Fpm --> Scheduler[Scheduler<br/>outbox publish]
```

All services join a single `internal` Docker network; no container is exposed
to the public internet except `nginx` (and the `database` / `mailer` host ports
in the dev override).

## Services

| Service    | Image / build              | Purpose                                                        |
|------------|----------------------------|----------------------------------------------------------------|
| `app`      | built from `Dockerfile`    | PHP-FPM 8.4, the Symfony application                           |
| `worker`   | extends `app`              | Runs `messenger:consume async` (async message handlers)        |
| `scheduler`| extends `app`              | Infinite loop publishing outbox + housekeeping commands        |
| `nginx`    | `nginx:alpine`             | Reverse proxy → `app:9000`, static files from `public/`        |
| `database` | `mysql:${MYSQL_VERSION:-8.4}` | MySQL 8.4, persistent via `mysql_data` volume                |
| `redis`    | `redis:7-alpine`           | OTP storage + cache, persistent via `redis_data` volume        |
| `mailer`   | `axllent/mailpit`          | Catches outbound SMTP mail, web UI at `:8025` (dev ports)      |

### Per-service notes

- **app** — runs `php-fpm`, listens on port 9000. Startup is guarded by the
  container entrypoint (see [JWT keys](#jwt-keys)). It `depends_on` the
  `database` service's healthcheck and the `redis` container. `./var` is
  bind-mounted to `/var/www/html/var` so cache, logs, and JWT keys persist
  across container rebuilds.
- **worker** — same image and environment as `app`, but overrides the command
  to consume the `async` transport indefinitely:

  ```bash
  php bin/console messenger:consume async --time-limit=3600 --memory-limit=256M --no-interaction
  ```

  The process restarts every hour (or earlier when memory exceeds 256M) to
  avoid leaks. `app` must be healthy before the worker starts.

- **scheduler** — non-Dockerized cron replacement. Runs a `while :` loop that
  executes the outbox publish and housekeeping commands on an interval
  (`OUTBOX_PUBLISH_INTERVAL`, default **5 s**):

  ```bash
  php bin/console app:trade:outbox:publish --no-interaction
  php bin/console app:store:outbox:publish --no-interaction
  php bin/console app:inventory:outbox:publish --no-interaction
  php bin/console app:inventory:reservations:release-expired --no-interaction
  php bin/console app:settlement:allocations:requeue-due --no-interaction
  php bin/console app:settlement:outbox:publish --no-interaction
  ```

  See [Scheduler commands](#scheduler-commands) for what each one does.

- **nginx** — the only published service. The `APP_PORT` variable (default
  `8080`) maps host → container port 80. It mounts `public/` read-only and the
  vhost config `docker/nginx/default.conf`. Its healthcheck requests
  `http://localhost/health/ready` through the full stack (nginx → PHP-FPM →
  Symfony → DB/Redis), so the container is only "healthy" once the application
  can actually serve traffic.
- **database** — MySQL 8.4 by default (`MYSQL_VERSION`, e.g. `8.4` or `8.0`).
  Data lives in the named `mysql_data` volume. Healthcheck uses
  `mysqladmin ping` with the app credentials.
- **redis** — Redis 7, used for OTP storage and cache. No port is exposed in
  the base compose file; the dev override opens the DB and mailer ports only.
- **mailer** — Mailpit (`axllent/mailpit`). SMTP on 1025 inside the network,
  UI published on `MAILPIT_UI_PORT` (default `8025`). Accepts auth-free SMTP so
  `smtp://mailer:1025` works out of the box.

## Compose files

| File                      | Role                                                                 |
|---------------------------|----------------------------------------------------------------------|
| `compose.yaml`            | Base definition: all services, safe development defaults for secrets |
| `compose.override.yaml`   | Auto-loaded in dev: `APP_ENV=dev`, debug on, full source bind-mount, exposes `3306` + `1025` |
| `compose.prod.yaml`       | Production: enforces `prod`, debug off, requires real secrets        |

`compose.override.yaml` is applied automatically by `docker compose`
(because of the `compose.yaml`/`compose.override.yaml` neighbor rule). For
production you must **explicitly** pass both files:

```bash
docker compose -f compose.yaml -f compose.prod.yaml --env-file .env.prod.local up -d --build
```

`compose.prod.yaml` forces `APP_ENV=prod` / `APP_DEBUG=0` and uses `:?required`
for `APP_SECRET`, `REFRESH_TOKEN_SECRET`, `MYSQL_PASSWORD`, and
`MYSQL_ROOT_PASSWORD` — the stack refuses to start without them.

## Environment variables

### Core Symfony (always required)

| Variable            | Default (dev)        | Purpose                                     |
|---------------------|----------------------|---------------------------------------------|
| `APP_ENV`           | `prod` (base) / `dev` (override) | Symfony environment            |
| `APP_DEBUG`         | `0` (override `1`)   | Debug mode                                  |
| `APP_SECRET`        | `dev-secret-change-me` | Symfony application secret                |
| `DATABASE_URL`      | `mysql://app:!ChangeMe!@database:3306/app?...` | Doctrine DSN, built from the `MYSQL_*` variables |
| `DEFAULT_URI`       | `http://localhost`   | Canonical app URI                           |

### JWT / authentication

| Variable               | Default                                    | Purpose                        |
|------------------------|--------------------------------------------|--------------------------------|
| `JWT_PRIVATE_KEY_PATH` | `/var/www/html/var/jwt/jwt_private.pem`    | RSA private key path in container |
| `JWT_PUBLIC_KEY_PATH`  | `/var/www/html/var/jwt/jwt_public.pem`     | RSA public key path in container |
| `JWT_PASSPHRASE`       | *(empty)*                                  | Passphrase for the private key |
| `ACCESS_TOKEN_TTL`     | `7200`                                     | Access token lifetime (seconds) |
| `REFRESH_TOKEN_TTL`    | `31536000` (1 year)                        | Refresh token lifetime (seconds) |
| `REFRESH_TOKEN_SECRET` | `dev-refresh-secret-change-me`             | HMAC-SHA256 key for refresh tokens |

### OTP / SMS (optional — empty disables)

| Variable                            | Default         |
|-------------------------------------|-----------------|
| `OTP_REDIS_DSN`                     | `redis://redis:6379/0` |
| `ALIYUN_ACCESS_KEY_ID`              | *(empty)*       |
| `ALIYUN_ACCESS_KEY_SECRET`          | *(empty)*       |
| `ALIYUN_SMS_REGION`                 | `cn-hangzhou`   |
| `ALIYUN_SMS_SIGN_NAME`              | *(empty)*       |
| `ALIYUN_SMS_TEMPLATE_LOGIN_OTP`     | *(empty)*       |
| `ALIYUN_SMS_TEMPLATE_VERIFY_PHONE`  | *(empty)*       |
| `ALIYUN_SMS_DRY_RUN`                | `true`          |

`OTP_REDIS_DSN` also gates the Redis portion of the `/health/ready` probe — an
empty value reports `disabled` and is treated as healthy.

### WeChat (optional — empty disables)

Mini Program: `WECHAT_MINIAPP_APP_ID`, `WECHAT_MINIAPP_SECRET`.

Official Account: `WECHAT_OFFICIAL_APP_ID`, `WECHAT_OFFICIAL_SECRET`,
`WECHAT_OFFICIAL_TOKEN`, `WECHAT_OFFICIAL_AES_KEY`.

WeChat Pay V3: `WECHAT_PAY_MCH_ID`, `WECHAT_PAY_SECRET_KEY`,
`WECHAT_PAY_PRIVATE_KEY`, `WECHAT_PAY_CERTIFICATE`, `WECHAT_PAY_NOTIFY_URL`
(plus `WECHAT_PAY_PLATFORM_CERT`, `WECHAT_PAY_PUB_KEY_ID`,
`WECHAT_PAY_PUB_KEY_PATH` — see `.env.example`).

### Infrastructure

| Variable                     | Default                        | Purpose                                        |
|------------------------------|--------------------------------|------------------------------------------------|
| `MAILER_DSN`                 | `smtp://mailer:1025`           | Mailpit inside the network                     |
| `MESSENGER_TRANSPORT_DSN`    | `doctrine://default?auto_setup=0` | Messenger `async` transport (Doctrine)      |
| `INVENTORY_ENABLED`          | `0`                            | Boolean; toggles inventory feature paths       |
| `OUTBOX_PUBLISH_INTERVAL`    | `5`                            | Scheduler loop sleep (seconds)                 |
| `MYSQL_DATABASE` / `MYSQL_USER` / `MYSQL_PASSWORD` / `MYSQL_ROOT_PASSWORD` | `app` / `app` / `!ChangeMe!` / `!RootChangeMe!` | MySQL setup |
| `MYSQL_VERSION`              | `8.4`                          | MySQL image tag                                |
| `APP_PORT`                   | `8080`                         | Host port for nginx                            |
| `MAILPIT_UI_PORT`            | `8025`                         | Host port for Mailpit UI                       |

## Environment file setup

Symfony and Docker Compose read environment variables from different files. The
table below shows which file is loaded in each scenario:

| Scenario | File read | Notes |
|----------|-----------|-------|
| Native PHP (local) | `.env` then `.env.local` | `.env.local` overrides `.env`; never commit it |
| Docker Compose (dev) | `.env` (or `--env-file`) | `compose.yaml` has safe dev defaults, so no file is required |
| Docker Compose (prod) | `.env.prod.local` via `--env-file` | Copy from `.env.prod.example` |

### Development `.env.local`

For native PHP development, copy the variables you need from `.env.example` into
`.env.local`. A complete example:

```ini
### Symfony
APP_ENV=dev
APP_DEBUG=1
APP_SECRET=dev-secret-do-not-use-in-production

### Database (native PHP)
DATABASE_URL=mysql://app:!ChangeMe!@127.0.0.1:3306/app?serverVersion=8.0&charset=utf8mb4

### JWT / Auth
JWT_PRIVATE_KEY_PATH=var/jwt_dev_private.pem
JWT_PUBLIC_KEY_PATH=var/jwt_dev_public.pem
JWT_PASSPHRASE=
ACCESS_TOKEN_TTL=7200
REFRESH_TOKEN_TTL=31536000
REFRESH_TOKEN_SECRET=dev-refresh-secret-do-not-use-in-production

### OTP / Redis
OTP_TTL=300
OTP_REDIS_DSN=redis://127.0.0.1:6379/0

### Aliyun SMS (leave empty to disable)
ALIYUN_ACCESS_KEY_ID=
ALIYUN_ACCESS_KEY_SECRET=
ALIYUN_SMS_REGION=cn-hangzhou
ALIYUN_SMS_SIGN_NAME=
ALIYUN_SMS_TEMPLATE_LOGIN_OTP=
ALIYUN_SMS_TEMPLATE_VERIFY_PHONE=
ALIYUN_SMS_DRY_RUN=true

### WeChat (optional — leave empty to disable)
WECHAT_MINIAPP_APP_ID=
WECHAT_MINIAPP_SECRET=
WECHAT_OFFICIAL_APP_ID=
WECHAT_OFFICIAL_SECRET=
WECHAT_OFFICIAL_TOKEN=
WECHAT_OFFICIAL_AES_KEY=
WECHAT_PAY_MCH_ID=
WECHAT_PAY_SECRET_KEY=
WECHAT_PAY_PRIVATE_KEY=
WECHAT_PAY_CERTIFICATE=
WECHAT_PAY_NOTIFY_URL=
```

### Production `.env.prod.local`

Copy `.env.prod.example` to `.env.prod.local` and fill in real values. The
`compose.prod.yaml` overlay uses `:?required` for `APP_SECRET`,
`REFRESH_TOKEN_SECRET`, `MYSQL_PASSWORD`, and `MYSQL_ROOT_PASSWORD` — the stack
refuses to start without them.

```ini
APP_ENV=prod
APP_SECRET=<generate-a-long-random-string>
REFRESH_TOKEN_SECRET=<generate-a-long-random-string>

# Public ports
APP_PORT=8080
MAILPIT_UI_PORT=8025

# MySQL container
MYSQL_DATABASE=app
MYSQL_USER=app
MYSQL_PASSWORD=<strong-db-password>
MYSQL_ROOT_PASSWORD=<strong-root-password>
MYSQL_VERSION=8.4

# JWT keys are expected at ./var/jwt on the host and mounted into the app volume.
JWT_PRIVATE_KEY_PATH=/var/www/html/var/jwt/jwt_private.pem
JWT_PUBLIC_KEY_PATH=/var/www/html/var/jwt/jwt_public.pem
JWT_PASSPHRASE=
ACCESS_TOKEN_TTL=7200
REFRESH_TOKEN_TTL=31536000

# Infrastructure
MAILER_DSN=smtp://mailer:1025
MESSENGER_TRANSPORT_DSN=doctrine://default?auto_setup=0
OUTBOX_PUBLISH_INTERVAL=5
OTP_REDIS_DSN=redis://redis:6379/0
DEFAULT_URI=https://example.com

# Optional: leave empty to disable.
ALIYUN_ACCESS_KEY_ID=
ALIYUN_ACCESS_KEY_SECRET=
ALIYUN_SMS_REGION=cn-hangzhou
ALIYUN_SMS_SIGN_NAME=
ALIYUN_SMS_TEMPLATE_LOGIN_OTP=
ALIYUN_SMS_TEMPLATE_VERIFY_PHONE=
ALIYUN_SMS_DRY_RUN=false

WECHAT_MINIAPP_APP_ID=
WECHAT_MINIAPP_SECRET=
WECHAT_OFFICIAL_APP_ID=
WECHAT_OFFICIAL_SECRET=
WECHAT_OFFICIAL_TOKEN=
WECHAT_OFFICIAL_AES_KEY=
WECHAT_PAY_MCH_ID=
WECHAT_PAY_SECRET_KEY=
WECHAT_PAY_PRIVATE_KEY=
WECHAT_PAY_CERTIFICATE=
WECHAT_PAY_NOTIFY_URL=
WECHAT_PAY_PLATFORM_CERT=
WECHAT_PAY_PUB_KEY_ID=
WECHAT_PAY_PUB_KEY_PATH=
```

### Generating secrets

```bash
# APP_SECRET / REFRESH_TOKEN_SECRET (32+ random bytes)
openssl rand -hex 32

# Strong DB passwords
openssl rand -base64 24
```

Never commit `.env.local` or `.env.prod.local`. Keep production secrets out of
the committed `.env` file.

## JWT keys

The container entrypoint `docker/app/entrypoint.sh` manages the key pair:

- If **both** keys already exist, they are reused as-is.
- In **non-prod** environments missing keys are generated automatically into
  the standard paths (2048-bit RSA) and persist under the mounted `./var/jwt`
  directory (`var/jwt` on the host). The private key is written with mode
  `600`.
- In **prod** (`APP_ENV=prod`) missing keys abort startup with an error —
  keys must be generated on the host first.

Generate production keys on the host before starting:

```bash
mkdir -p var/jwt
openssl genpkey -algorithm RSA -out var/jwt/jwt_private.pem -pkeyopt rsa_keygen_bits:2048
openssl rsa -pubout -in var/jwt/jwt_private.pem -out var/jwt/jwt_public.pem
chmod 600 var/jwt/jwt_private.pem
```

> If the private key has a passphrase, set `JWT_PASSPHRASE` in
> `.env.prod.local`.

## Building images

The `Dockerfile` targets a small final image (≈90 MB):
`php:8.4-fpm-alpine` + extensions (`pdo`, `pdo_mysql`, `intl`, `zip`,
`opcache`) + Composer. Dependencies are installed first (cached layer) with
`composer install --no-dev --no-scripts`, then the app source is copied and
`--no-dev --optimize` autoloader dumped. `var/` is created and owned by
`www-data`.

```bash
# Dev — build and start everything
docker compose up -d --build

# Explicit env file (custom ports / credentials / integrations)
cp .env.example .env.docker.local
docker compose --env-file .env.docker.local up -d --build

# Production
docker compose -f compose.yaml -f compose.prod.yaml --env-file .env.prod.local up -d --build
```

Do not put production secrets in the committed `.env` file.

## First-run and migrations

Development (override applies automatically):

```bash
docker compose up -d --build
docker compose exec app php bin/console doctrine:migrations:migrate --no-interaction
docker compose exec app php bin/console app:identity:user:create admin@example.com admin 'P@ssw0rd' --admin
```

Production:

```bash
docker compose -f compose.yaml -f compose.prod.yaml --env-file .env.prod.local up -d --build
docker compose -f compose.yaml -f compose.prod.yaml --env-file .env.prod.local \
  exec app php bin/console doctrine:migrations:migrate --no-interaction
docker compose -f compose.yaml -f compose.prod.yaml --env-file .env.prod.local \
  exec app php bin/console app:identity:user:create admin@example.com admin 'P@ssw0rd' --admin
```

The migration chain is also validated by CI (`.github/workflows/migrations.yml`),
which applies every migration from scratch against a fresh MySQL 8.4 and
checks `doctrine:migrations:status`.

Useful migration commands:

```bash
docker compose exec app php bin/console doctrine:migrations:status   # pending?
docker compose exec app php bin/console doctrine:migrations:migrate   # apply
```

## Health checks

Both endpoints live in `src/Core/Controller/HealthController.php` and are
**public** (outside the `/api` firewall) so orchestrators and load balancers
can poll them without tokens.

### `/health/live`

Liveness probe. Always answers `200 {"status":"ok"}` while PHP can serve
requests. Use this to detect a wedged process.

### `/health/ready`

Readiness probe. Responses:

```json
// 200 — ready
{"status": "ok", "checks": {"database": "ok", "redis": "ok"}}
// 200 — ready, redis optional
{"status": "ok", "checks": {"database": "ok", "redis": "disabled"}}
// 503 — degraded
{"status": "degraded", "checks": {"database": "error", "redis": "ok"}}
```

- `database` — `SELECT 1` via the default Doctrine connection. Errors are
  logged, never echoed (avoids leaking driver details to anonymous callers).
- `redis` — dependency-free TCP PING (RESP `PING` → `+PONG`). Reported
  `disabled` when `OTP_REDIS_DSN` is empty, otherwise `ok` / `error`.

The service is **ready** only when the database is `ok` **and** redis is
`ok` or `disabled`. In the test environment the Redis probe is forced to
`disabled` for deterministic CI results.

The nginx container healthcheck uses `/health/ready` through the full stack,
so a green nginx container implies app + DB (+ optional Redis) are reachable.

## Scheduler commands

The `scheduler` service replaces a cron job. It loops forever, runs the
commands below, then sleeps `OUTBOX_PUBLISH_INTERVAL` (default 5 s):

| Command                                        | Module      | Purpose                                                     |
|------------------------------------------------|-------------|-------------------------------------------------------------|
| `app:trade:outbox:publish`                     | Trade       | Publish pending `trade.order.created.v1` / `trade.order.cancelled.v1` events to Messenger |
| `app:store:outbox:publish`                     | Store       | Publish pending store integration events                     |
| `app:inventory:outbox:publish`                 | Inventory   | Publish pending inventory integration events                 |
| `app:inventory:reservations:release-expired`   | Inventory   | Release expired stock reservations                           |
| `app:settlement:allocations:requeue-due`       | Settlement  | Re-queue allocation postings that are due for retry          |
| `app:settlement:outbox:publish`                | Settlement  | Publish pending settlement integration events                |

Each outbox publisher follows the same pattern (`src/{Module}/Command/PublishOutboxCommand.php`):
markable rows are claimed with a lease (`claim()`), validated against known
topics, dispatched onto the `messageBus` with a versioned envelope, then
`markPublished()`; failures are deferred with an error message and retried
later. The loop tolerates a crashed iteration (each run exits cleanly), making
it safe to run as a scheduled job instead.

## Local one-shot async runner

After creating a `X-Store-Code` order and wondering why `store_order` is missing, run the bundled helper instead of remembering three commands. It runs a publish loop in background (every `$OUTBOX_PUBLISH_INTERVAL` or `--interval 5`) and `messenger:consume` in foreground, bounded by `--time-limit`:

```bash
./scripts/dev/run-async.sh          # publish loop + consume 60s
./scripts/dev/run-async.sh 120      # custom duration (supports 60 / 60s / 2m / 1h)
./scripts/dev/run-async.sh 10 --interval 2  # publish every 2s
./scripts/dev/run-async.sh --dry-run
docker compose exec app ./scripts/dev/run-async.sh 60
```

It is the local equivalent of the scheduler+worker pair above — the loop ensures `store_outbox` created *during* `consume` (e.g. by `TradeOrderCreatedHandler`) is also published within the same run.

## Useful commands

Dev (production variants add `-f compose.yaml -f compose.prod.yaml --env-file .env.prod.local`):

```bash
docker compose logs -f app                       # tail app logs
docker compose exec app php bin/console about    # run a Symfony command
docker compose exec app bash                     # shell into the app container
docker compose exec app php bin/console cache:clear
docker compose ps                                # service / health status
docker compose down                              # stop everything
docker compose down -v && docker compose up -d --build   # reset (WARNING: deletes all data)
```

### Verify after deploy

```bash
curl -s http://localhost:8080/health/live
curl -s http://localhost:8080/health/ready
curl -s http://localhost:8080/api/auth/login \
  -H 'Content-Type: application/json' \
  -d '{"identifier":"admin@example.com","password":"P@ssw0rd"}'
```

App: `http://localhost:8080` — Swagger UI: `http://localhost:8080/api/doc`.

## Upgrading

Development:

```bash
git pull
docker compose up -d --build
docker compose exec app php bin/console doctrine:migrations:migrate --no-interaction
docker compose exec app php bin/console cache:clear
```

Production:

```bash
git pull
docker compose -f compose.yaml -f compose.prod.yaml --env-file .env.prod.local up -d --build
docker compose -f compose.yaml -f compose.prod.yaml --env-file .env.prod.local \
  exec app php bin/console doctrine:migrations:migrate --no-interaction
docker compose -f compose.yaml -f compose.prod.yaml --env-file .env.prod.local \
  exec app php bin/console cache:clear
```

## Custom nginx configuration

Replace `docker/nginx/default.conf` with your own config. Common changes:

- Add TLS/SSL certificates and listen on 443
- Change `server_name` to your domain
- Add rate limiting or IP whitelisting

Then rebuild only nginx:

```bash
docker compose up -d --build nginx
```

The default config routes `location /` through `index.php` (front controller),
forwards `^/index\.php(/|$)` to `app:9000` via FastCGI, `internal`s the PHP
location (never served directly), returns `404` for any other `.php` file, and
caps `client_max_body_size` at 20m.