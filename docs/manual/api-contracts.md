# API Contracts

> Wire-level contracts of the HTTP API: the JSON envelope, authentication, URL
> conventions, pagination, error handling, OpenAPI docs, and webhooks. Every endpoint
> MUST conform to these conventions.

---

## 1. JSON Envelope

All business endpoints under `/api/v1/*` return a unified envelope produced by
`App\Core\Controller\RestController` (`success()` / `warning()`).

### 1.1 Success (`success()`)

```json
{
  "data": {},
  "code": 0,
  "message": "SUCCESS",
  "paginator": {
    "page": 1,
    "limit": 20,
    "pages": 5,
    "total": 100,
    "has_previous": false,
    "has_next": true
  }
}
```

| Field | Type | Present when | Description |
|-------|------|--------------|-------------|
| `data` | any | always | Payload: object, array, null, or scalar |
| `code` | int | always | Application-level code. The current implementation emits `0` for success (see note below) |
| `message` | string | always | Status text; `"SUCCESS"` by default |
| `paginator` | object | only when the response is paginated | Pagination metadata |

> **Note on `code`:** the design contract (`docs/design/api-design.md` §1.1) specifies
> that `code` mirrors the HTTP status (e.g. `200`), and the README shows that example.
> The current `RestController::success()` implementation writes `"code": 0` and carries
> the real HTTP status in the response line (200/201/204). Treat the HTTP status as
> authoritative, and `code` as the application channel used for error codes.

HTTP status mapping on success: `GET 200`, `POST 201`, `PUT 200`, `DELETE 204`
(empty body), `204` responses are rendered empty.

### 1.2 Error (`warning()`)

```json
{
  "code": 400,
  "message": "Validation failed: email is required",
  "raw_data": ""
}
```

`warning()` sets `code` to the passed error code, translates the message through the
Translator, and carries an optional `raw_data` payload. Status codes follow the HTTP
semantics: 400 validation, 401 authentication, 403 authorization, 404 not found,
500 server error.

### 1.3 Example request & response

```bash
curl -X POST "http://127.0.0.1:8000/api/v1/manage/contents" \
  -H "Content-Type: application/json" \
  -H "Authorization: Bearer {token}" \
  -d '{"title":"Hello","body":"World"}'
```

```json
{
  "data": {
    "id": 42,
    "title": "Hello",
    "body": "World",
    "createdAt": "2026-08-20T09:00:00+00:00"
  },
  "code": 0,
  "message": "SUCCESS"
}
```

### 1.4 Auth / WeChat token endpoints

Endpoints that return credentials do **not** use the envelope. They return a plain
token payload (see §2).

---

## 2. Authentication

### 2.1 Scheme

```
Authorization: Bearer {access_token}
```

- **Access token**: RS256 JWT, default TTL 7200s (`ACCESS_TOKEN_TTL`).
- **Refresh token**: opaque, stored **hashed** (HMAC-SHA256) in the DB,
  default TTL 1 year (`REFRESH_TOKEN_TTL`), **rotated on every use** with reuse
  detection (S4: a reused revoked token revokes all of the user's tokens).

Firewall (`config/packages/security.yaml`) applies to `^/api`; the `manage` prefix
requires `ROLE_ADMIN`, everything else requires `IS_AUTHENTICATED_FULLY`, and the
listed public routes are exempt.

### 2.2 Endpoints

All endpoints below are `PUBLIC_ACCESS` (no token required):

| Endpoint | Method | Body (required) | Response |
|----------|--------|-----------------|----------|
| `/api/auth/login` | POST | `{"identifier","password"}` | 200 tokens; 400 missing fields; 401 bad credentials; 403 phone-unverified |
| `/api/auth/register` | POST | `{"email","username","password","phone"?}` | 201 tokens; 400 weak/missing; 409 duplicates |
| `/api/auth/otp/request` | POST | `{"phone","purpose":"login\|verify_phone"}` | 204 sent; 400 invalid; 429 rate-limited |
| `/api/auth/otp/verify` | POST | `{"phone","otp","purpose"}` | 200 tokens (purpose=login) or `{"phone_verified":true}`; 400/401 |
| `/api/auth/token/refresh` | POST | `{"refresh_token"}` | 200 new token pair; 401 invalid/reused |
| `/api/auth/logout` | POST | `{"access_token"?, "refresh_token"?}` | 204, revokes provided tokens |

Login accepts an **email, username, or phone** as `identifier` (phone detection:
`^\+?[0-9]{7,20}$`). OTP purposes are `login` and `verify_phone`; OTP TTL is 300s by
default (`OTP_TTL`), storage via Redis (`OTP_REDIS_DSN`).

Success token payload (login / register / otp-verify / token-refresh / WeChat login):

```json
{
  "access_token": "eyJhbGc...",
  "expires_in": 7200,
  "refresh_token": "eyJhbGc..."
}
```

---

## 3. URL Conventions

### 3.1 Prefixes

| Scope | Prefix | Access |
|-------|--------|--------|
| Authentication | `/api/auth/*` | PUBLIC_ACCESS |
| Public read API | `/api/v1/app/*` | authenticated (read) |
| Admin CRUD API | `/api/v1/manage/*` | `ROLE_ADMIN` |
| Public read-only (opt-in) | `/api/v1/public` | `GET` + PUBLIC_ACCESS |
| WeChat integration | `/api/wechat/*` | mix of public and authenticated |
| Payment webhooks | `/api/payment/notify/*` (+ `/api/payment/refund-notify`) | PUBLIC_ACCESS — gateway-verified |
| API docs | `/api/doc`, `/api/doc.json` | PUBLIC_ACCESS |

Routes are registered per module in `config/routes.yaml` with the `/api/v1` prefix for
the `App` and `Manage` controller trees (attribute routing).

### 3.2 Resource style

| Convention | Example |
|------------|---------|
| Lowercase, hyphenated, plural | `/api/v1/manage/order-items` |
| Detail via path id | `/categories/{id}` |
| No trailing slash; verbs not in the URL | `POST /categories` creates |
| Sub-resources | `/orders/{id}/items` |
| Record lookup by integer id **or UUID** | handled by `ApiView::mixIdToCommonFilter` (`uuid` vs `id`) |

### 3.3 Non-CRUD routes

| Operation | Pattern |
|-----------|---------|
| Batch upsert | `POST /{resource}/batch-update` |
| Create single / batch | `POST /` (object or array body) |
| Singleton upsert | `PUT /` |
| Workflow todo | `GET /{resource}/todo` |
| Workflow transitions | `GET /{resource}/{id}/transitions` |
| Workflow execute | `POST /{resource}/{id}/do/{transition}` |
| Workflow reset | `PUT /{resource}/{id}/status-reset` |

### 3.4 WeChat endpoints

| Endpoint | Method | Access | Purpose |
|----------|--------|--------|---------|
| `/api/wechat/miniapp/login` | POST | public | `js_code` → JWT |
| `/api/wechat/miniapp/phone` | POST | authenticated | bind WeChat phone |
| `/api/wechat/oauth/url` | GET | public | Official-Account OAuth URL |
| `/api/wechat/oauth/callback` | POST | public | OAuth `code` → JWT |

### 3.5 Store & Trade (multi-store)

| Endpoint | Method | Access | Purpose |
|----------|--------|--------|---------|
| `/api/v1/manage/stores` | POST | `ROLE_ADMIN` | Create store (`code`, `name`, `timezone`, `currency` 1..32 default `CNY`, `contact`/`address`/`settings` validated via JSON Schema) |
| `/api/v1/manage/stores/{uuid}` | PUT | `ROLE_ADMIN` | Update store (`name`, `timezone`, `currency`, `contact`, `address`, `settings`); `code` immutable |
| `/api/v1/manage/stores/{uuid}/members` | POST | `ROLE_ADMIN` | Grant membership (`userUuid`, `role` `owner/manager/clerk/fulfillment`) — upsert & re-activate |
| `/api/v1/app/stores/{uuid}/membership` | POST | `ROLE_USER` | **Self-join as member** (idempotent, fixed `role=clerk`, body ignored, `200 Already a member` / `201 Joined`) |
| `/api/v1/app/stores/{uuid}/membership` | GET | `ROLE_USER` | Get own membership for store |
| `/api/v1/app/orders` | POST | `ROLE_USER` | Create order — `currency` is **authoritative from `Store`** via `X-Store-Code` header (`LIANSHENG_POINT` for points mall, `CNY` otherwise); mismatch `400 Currency mismatch`; global (no header) defaults to `CNY` |

`StoreContact` (`Store/StoreContact` schema) now includes `subTitle` (1..100) and `tags` (array 1..30×20 unique) alongside `phone`, `email`, `serviceHours`, etc.; `StoreAddress` includes `province/city/district/street/detail/formattedAddress/latitude/longitude` with `additionalProperties:false`.

---

## 4. Pagination

List endpoints are paginated through `RestController::pagination()`:

| Query param | Default | Meaning |
|-------------|---------|---------|
| `page` | `1` | 1-based page number |
| `limit` | `100` | items per page |

Paginator object: `{ total, page, limit, pages, has_previous, has_next }`. `pages` is
`max(1, ceil(total / limit))`. Pagination is applied for `GET` only and supports
`Doctrine\ORM\QueryBuilder` collections (counted via the Doctrine Paginator) and plain
arrays/`ArrayCollection`.

### 4.1 Dynamic query system (list filtering)

When both filter params and pagination are used, the service assembles the query; the
controller paginates the result.

| Param | Example |
|-------|---------|
| `@filter` | `entity.status == "active"` |
| `@dql` | `(entity.price > 100)` |
| `@order` | `createdAt\|DESC` |
| `@select` | `entity.id, entity.name` |
| `@groupBy` | `entity.category` |
| `@hints` | Doctrine query hints |
| `@sort` | in-memory sort fallback expression |
| `@expands` | `specifications` / `category,tags` (supports `specifications`, `a,b`, or `["a","b"]`) |
| `@display` | `complex` / `reduce` / expression mapping |
| `@showDQL` | debug: return compiled DQL |

Mutation params: `@partial` (per-item non-transactional mode), `@transform` (expression
field transform), `@mode` (`mixed` upsert / `strict` update), `@basis` (upsert match
fields).

Filter expression operators: `==`, `!=`, `>`, `<`, `>=`, `<=`, `&&`, `||`, `!`,
`in` / `not in` (collection membership, e.g. `entity.getStoreUuid() in storeUuids`;
empty `in []` → `1 = 0`, empty `not in []` → `1 = 1`), `matches` (literal substring or
`/regex/flags/`), and chained attribute access (`entity.getCategory().getName()`).
The same operator set powers server-owned `DqlExpression` row scopes in
`commonFilter()` (e.g. `new DqlExpression('entity.getUser() == this.getUser()')`),
which are fail-closed rather than falling back to in-memory evaluation.

---

## 5. Error Handling

| Channel | Where |
|---------|-------|
| Controller-level | mixins/controllers catch domain exceptions and convert to `warning()` with 400/404/500 |
| Global | `ExceptionInterceptor` (kernel.exception on `/api/*`) converts unhandled exceptions to JSON `{code, message, class}`; bypassed in `dev` (standard Symfony error page) |
| Invalid JSON | request bodies must be valid JSON; malformed JSON is rejected |

Status semantics: 400 validation, 401 authentication, 403 authorization, 404 not
found, 429 rate-limit (e.g. OTP requests), 500 server error. Messages are short,
user-facing, and translated.

Responses are `application/json` on every `/api/*` route except webhook callbacks
(see §7).

---

## 6. OpenAPI / NelmioApiDoc

- **Swagger UI**: `/api/doc`
- **OpenAPI JSON**: `/api/doc.json`

The bundle is `nelmio/api-doc-bundle` with `zircote/swagger-php` (`zircote/swagger-php`)
attributes. Every endpoint declares `#[OA\*]` attributes — request bodies, query
parameters, and response codes. The OpenAPI integration test builds the complete
specification in-process, and `OpenApiEnricherListener` augments responses.

Required attributes per action: list (`#[OA\Get]` + query parameters), detail
(`#[OA\Get]` + `@expands`), create (`#[OA\Post]` + `@partial`/`@transform` + request
body), update (`#[OA\Put]` + body), delete (`#[OA\Delete]`), batch (`#[OA\Post]` +
`@mode`/`@basis`/`@partial`), workflow (`#[OA\Get]`/`#[OA\Post]`/`#[OA\Put]`).

---

## 7. Webhooks

Payment providers notify the platform at the webhook namespace (no `/api/v1` prefix):

| Route | Method | Access |
|-------|--------|--------|
| `/api/payment/notify/{payment}` | POST | PUBLIC_ACCESS |
| `/api/payment/refund-notify` | POST | PUBLIC_ACCESS |

Flow (`src/Payment/Controller/Webhook/PaymentNotifyController.php`):

1. The controller resolves the gateway for `{payment}` from `PaymentGatewayRegistry`.
2. `$gateway->notify($request)` **verifies the provider signature** (each gateway owns
   its verification).
3. `InvoiceService::handleNotifyResult()` updates the invoice and dispatches
   provider-agnostic invoice events.
4. `$gateway->getNotifySuccessResponse()` returns the provider-specific success body.

On verification failure the response is `400 text/plain` (`FAIL: <reason>`); any other
error is a `400 text/plain` `FAIL`. Webhook responses are **not** JSON envelopes — they
are gateway-specific plain text/accepted bytes required by each provider.

WeChat Pay V3 callbacks flow through the same gateway abstraction
(`/api/payment/notify/wechat`).

---

## 8. Reference

- Design contract: [`docs/design/api-design.md`](../design/api-design.md)
- Controller + mixins: `src/Core/Controller/RestController.php`, `src/Core/View/*`
- Auth: `src/Identity/Controller/AuthController.php`, `src/Identity/Controller/OtpController.php`
- Webhooks: `src/Payment/Controller/Webhook/PaymentNotifyController.php`
- Firewall: `config/packages/security.yaml`
- OpenAPI: `/api/doc` (running server), `docs/openapi/endpoints.yaml`