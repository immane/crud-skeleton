# Quick Start / 快速上手

> 5 分钟完成本地可登录、可调用受保护接口的最小流程。

## 方式 A：Docker（推荐）

无需本地安装 PHP、Composer 或数据库，仅需 **Docker**。

```bash
# 1) 启动所有服务（app、worker、scheduler、nginx、MySQL、Redis、Mailpit）
docker compose up -d --build

# 2) 执行数据库迁移
docker compose exec app php bin/console doctrine:migrations:migrate --no-interaction

# 3) 创建管理员
docker compose exec app php bin/console app:identity:user:create admin@example.com admin 'P@ssw0rd' --admin

# 4) 登录获取 token
curl -s -X POST http://localhost:8080/api/auth/login \
  -H 'Content-Type: application/json' \
  -d '{"identifier":"admin@example.com","password":"P@ssw0rd"}' \
  | php -r 'print_r(json_decode(stream_get_contents(STDIN),true));'
```

> Docker 开发环境会在挂载的 `./var/jwt` 目录下生成一次 JWT 密钥，后续启动会复用。生产环境请先在主机上手动生成 — 详见 [README](README.zh-cn.md#docker-部署)。

> `worker` 自动消费 Messenger 的 `async` 队列，`scheduler` 每五秒发布 Trade/Store/Inventory Outbox 并释放过期预留。两者随 Compose 自动启动，可通过 `docker compose logs -f worker scheduler` 查看日志。

Docker 开发环境使用内置安全默认值。只有需要定制端口、数据库密码或可选集成时，才需要创建 Docker env 文件：

```bash
cp .env.example .env.docker.local
docker compose --env-file .env.docker.local up -d --build
```

---

## 方式 B：本机 PHP

环境要求

- PHP `8.4+`（建议 Homebrew）
- Composer
- MySQL/MariaDB（按你的 `DATABASE_URL`）
- 可选：Symfony CLI

macOS (Homebrew) 推荐：

```bash
brew install php composer
```

## 1) 安装依赖

```bash
composer install
```

## 2) 配置环境变量

在项目根创建/更新 `.env.local`（不要提交到 Git），只保留本机 PHP 运行所需变量。可选集成变量参考 `.env.example`。

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

## 3) 生成 JWT 开发密钥

```bash
mkdir -p var
openssl genpkey -algorithm RSA -out var/jwt_dev_private.pem -pkeyopt rsa_keygen_bits:2048
openssl rsa -pubout -in var/jwt_dev_private.pem -out var/jwt_dev_public.pem
chmod 600 var/jwt_dev_private.pem
```

> 如果私钥是未加密 PEM，可把 `JWT_PASSPHRASE` 置空（`JWT_PASSPHRASE=`）。

## 4) 初始化数据库（统一迁移流程）

> 使用 PHP 8.4+。如果 macOS 系统默认 PHP 版本不一致，可把下面的 `php` 换成 `/opt/homebrew/bin/php`。

```bash
php bin/console doctrine:schema:drop --force
php bin/console doctrine:migrations:migrate --no-interaction
```

## 5) 创建管理员账号

```bash
php bin/console app:identity:user:create admin@example.com admin 'P@ssw0rd' --admin
```

## 6) 启动服务

```bash
php -S 127.0.0.1:8000 -t public
```

或：

```bash
symfony server:start
```

## 7) 登录并验证受保护接口

获取 token：

```bash
TOKEN=$(curl -s -X POST http://127.0.0.1:8000/api/auth/login \
  -H 'Content-Type: application/json' \
  -d '{"identifier":"admin@example.com","password":"P@ssw0rd"}' \
  | php -r 'echo json_decode(stream_get_contents(STDIN), true)["access_token"];')
```

访问管理接口（需要 `ROLE_ADMIN`）：

```bash
curl -i -H "Authorization: Bearer $TOKEN" http://127.0.0.1:8000/api/v1/manage/contents
```

## 8) API 文档

- Swagger UI: `http://127.0.0.1:8000/api/doc`
- 右上角 `Authorize` 输入：`Bearer <access_token>`

## 9) 系统自省接口

```bash
# 列出所有 Doctrine 实体
curl http://127.0.0.1:8000/system/entities

# 获取实体字段元数据
curl http://127.0.0.1:8000/system/entities/App%5CCommon%5CEntity%5CCategory

# 列出所有已注册路由
curl http://127.0.0.1:8000/system/router
```

## 常见问题

1. `openssl_sign(): ... cannot be coerced into a private key`
   - 检查 `JWT_PRIVATE_KEY_PATH` 是否存在
   - 检查 `JWT_PASSPHRASE` 与私钥是否匹配（不匹配可置空）

2. OTP 报 Redis/Predis 类型错误
   - 当前默认已使用本地缓存 OTP 存储（不依赖 Redis）
   - 先执行：`/opt/homebrew/bin/php bin/console cache:clear`

3. `migrations:migrate` 失败提示缺少 `users`
   - 请确保已拉取最新迁移并执行第 4 步（统一迁移流程）
