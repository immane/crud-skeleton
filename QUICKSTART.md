# Quick Start

This Quick Start walks you through a minimal, runnable development setup.

## Option A: Docker (recommended)

No PHP, Composer, or database setup required on your host. Prerequisites: **Docker** only.

```bash
# 1) Start all services (app, worker, scheduler, nginx, MySQL, Redis, Mailpit)
docker compose up -d --build

# 2) Run database migration
docker compose exec app php bin/console doctrine:migrations:migrate --no-interaction

# 3) Create admin user
docker compose exec app php bin/console app:identity:user:create admin@example.com admin 'P@ssw0rd' --admin

# 4) Login and get token
curl -s -X POST http://localhost:8080/api/auth/login \
  -H 'Content-Type: application/json' \
  -d '{"identifier":"admin@example.com","password":"P@ssw0rd"}' \
  | php -r 'print_r(json_decode(stream_get_contents(STDIN),true));'
```

> Docker dev creates JWT keys once under the mounted `./var/jwt` directory and reuses them on later starts. For production, generate keys on the host before starting — see [README](README.md#docker-deployment).

> `worker` consumes Messenger's `async` transport and `scheduler` publishes Trade/Store/Inventory Outbox rows and releases expired reservations every five seconds. Both start automatically with Compose. Check them with `docker compose logs -f worker scheduler`.

Docker development uses built-in safe defaults. Create a Docker env file only when you need to customize ports, database credentials, or optional integrations:

```bash
cp .env.example .env.docker.local
docker compose --env-file .env.docker.local up -d --build
```

---

## Option B: Native PHP

Prerequisites
- PHP 8.4+ (Homebrew is recommended on macOS)
- Composer
- MySQL / MariaDB or PostgreSQL (as configured in `DATABASE_URL`)
- Optional: Symfony CLI

On macOS (Homebrew):

```bash
brew install php composer
```

1) Install dependencies

```bash
composer install
```

2) Configure environment variables

Create or update `.env.local` (do not commit) with the values needed for native PHP. Keep optional integrations in `.env.example` unless you use them.

```dotenv
APP_ENV=dev
APP_SECRET=change-me
DATABASE_URL="mysql://user:password@127.0.0.1:3306/crud_skeleton?serverVersion=8.0&charset=utf8mb4"

JWT_PRIVATE_KEY_PATH=var/jwt_dev_private.pem
JWT_PUBLIC_KEY_PATH=var/jwt_dev_public.pem
JWT_PASSPHRASE=
ACCESS_TOKEN_TTL=7200
REFRESH_TOKEN_TTL=31536000
REFRESH_TOKEN_SECRET=change-this-secret
```

See `.env.example` for the full variable reference.

3) Generate development JWT keys

```bash
mkdir -p var
openssl genpkey -algorithm RSA -out var/jwt_dev_private.pem -pkeyopt rsa_keygen_bits:2048
openssl rsa -pubout -in var/jwt_dev_private.pem -out var/jwt_dev_public.pem
chmod 600 var/jwt_dev_private.pem
```

If your private key is not encrypted, leave `JWT_PASSPHRASE` empty.

4) Initialize the database (recommended unified migration flow)

Use your PHP 8.4+ binary. On macOS with Homebrew, replace `php` with `/opt/homebrew/bin/php` if needed to avoid CLI version mismatch:

```bash
php bin/console doctrine:schema:drop --force
php bin/console doctrine:migrations:migrate --no-interaction
```

5) Create an administrator account

```bash
php bin/console app:identity:user:create admin@example.com admin 'P@ssw0rd' --admin
```

6) Start the local server

```bash
php -S 127.0.0.1:8000 -t public
```

or

```bash
symfony server:start
```

For Store/Trade asynchronous events in native PHP, run these in separate terminals:

```bash
php bin/console messenger:consume async --time-limit=3600 --memory-limit=256M
while true; do
  php bin/console app:trade:outbox:publish --no-interaction
  php bin/console app:store:outbox:publish --no-interaction
  php bin/console app:inventory:outbox:publish --no-interaction
  php bin/console app:inventory:reservations:release-expired --no-interaction
  sleep 5
done
```

7) Log in and test protected endpoints

Obtain an access token:

```bash
TOKEN=$(curl -s -X POST http://127.0.0.1:8000/api/auth/login \
   -H 'Content-Type: application/json' \
   -d '{"identifier":"admin@example.com","password":"P@ssw0rd"}' \
   | php -r 'echo json_decode(stream_get_contents(STDIN), true)["access_token"];')
```

Call a management endpoint (requires `ROLE_ADMIN`):

```bash
curl -i -H "Authorization: Bearer $TOKEN" http://127.0.0.1:8000/api/v1/manage/contents
```

8) API documentation

- Swagger UI: `http://127.0.0.1:8000/api/doc`
- Click `Authorize` and paste `Bearer <access_token>` to try authenticated endpoints in the UI.

9) System introspection endpoints

```bash
# List all Doctrine entities
curl http://127.0.0.1:8000/system/entities

# Get entity field metadata
curl http://127.0.0.1:8000/system/entities/App%5CCommon%5CEntity%5CCategory

# List all registered routes
curl http://127.0.0.1:8000/system/router
```

Troubleshooting

- `openssl_sign(): ... cannot be coerced into a private key`:
   - Verify `JWT_PRIVATE_KEY_PATH` exists and points to a valid PEM file.
   - If you configured `JWT_PASSPHRASE`, ensure it matches the private key; set it empty if your key is unencrypted.

- OTP Redis / Predis errors:
   - The app defaults to local cache OTP storage for development to avoid Redis dependency. Run ` /opt/homebrew/bin/php bin/console cache:clear` if you switched back to Redis.

- `doctrine:migrations:migrate` failing due to missing tables:
   - Ensure you have the latest migrations and run the unified migration flow in step 4.
