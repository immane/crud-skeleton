# Development Contracts

> The rules that keep the modular monolith coherent: layer boundaries, cross-module
> communication, service boundaries, naming, code style, performance, and security.
> These are the developer-facing companion to the normative design docs
> (`docs/design/system-architecture.md`, `docs/design/system-contracts.md`,
> `docs/design/module-design.md`, `docs/design/naming-convention.md`).

---

## 1. Layer Rules

Requests move through a strict, one-directional chain:

```
HTTP (Controllers / View mixins)  →  Service  →  Repository  →  Entity  →  Infrastructure
```

| Rule | From | To | Allowed? | Why |
|------|------|----|----------|-----|
| R1 | Controller | Service | YES | Normal flow |
| R2 | Controller | Entity | YES | Type hints / returns only |
| R3 | Controller | Repository | **NO** | Go through Service |
| R4 | Controller | EntityManager | **NO** | Go through Service |
| R5 | Service | Repository | YES | Data access |
| R6 | Service | Entity | YES | Domain operations |
| R7 | Service | EntityManager | YES | Transactions |
| R8 | Service | Other Services | YES | DI, interface-first |
| R9 | Entity | Repository | **NO** | Entities are pure |
| R10 | Entity | Service | **NO** | No business-logic deps |
| R11 | Entity | EntityManager | **NO** | Persistence is external |

### Transaction contract

- Transactions live in the **Service layer only** — controllers never call
  `beginTransaction()` / `commit()` / `rollback()` (T4).
- Multiple mutations on related entities are wrapped in `wrapInTransaction()` (T1).
- Wallet transfers use explicit `beginTransaction`/`commit`/`rollback` with entity
  locks (`PESSIMISTIC_WRITE`) (T2), and the EntityManager is recovered after a
  rollback with an `$em->isOpen()` guard (T3).
- Mixin-driven mutations (`createAction`, `batchUpdateAction`) wrap automatically
  unless `@partial=true`. `@partial=true` processes each item independently; otherwise
  all items commit or roll back together.

### Where exceptions are thrown and caught

| Layer | May throw | Must catch |
|-------|-----------|------------|
| Entity | **NO** | — |
| Repository | Doctrine exceptions only | — |
| Service | Business exceptions (`InsufficientFundsException`, `OrderInvalidTransitionException`, …) | — |
| Controller | **NO** — convert to `warning()` | domain exceptions → `warning()` with 400/404/500 |

Unhandled exceptions on `/api/*` turn into JSON `{code, message, class}` via the
`ExceptionInterceptor` (bypassed in dev).

---

## 2. Cross-Module Communication

Modules share one kernel, one EntityManager, one cache, and one Messenger bus — but
must behave as if they were independent services.

| Concern | Contract |
|---------|----------|
| Doctrine associations | **No cross-module associations.** An entity never references another module's entity as a relation/FK |
| Durable references | Reference aggregates across modules by **UUID** (`App\Core\Utils\UUID`) or an explicitly documented business key — never a local auto-increment id |
| Contracts in, scalar data out | Cross-module service calls exchange **scalars / snapshots / DTOs only** — never entities, repositories, or an EntityManager |
| Writes | **Outbox**: the publishing module persists a `*OutboxMessage` entity in the *same transaction* as the change; a `app:{module}:outbox:publish` command flushes it to Messenger |
| Reads | **Inbox / message handlers**: consuming modules read another module's state through their own `MessageHandler/` handlers, which enforce integrity and idempotency |
| Direct repository reads across modules | **Forbidden** |
| Dependency direction | Business modules may autowire `Core` and (interface-first) each other; `Core` must never import a business module; circular module dependencies are forbidden |

### Messenger routing (async transport)

Registered messages include: Trade order created/cancelled / store accepted/rejected,
Inventory reservation request/confirm/reject/release, Settlement funding-confirmed and
allocation-posting. Handlers live in the consuming module's `MessageHandler/`
directory (e.g. `src/Store/MessageHandler/TradeOrderCreatedHandler.php`,
`src/Inventory/MessageHandler`). Failure transport is `failed` with a 3-retry,
doubling backoff.

### Registries for cross-cutting extension instead of direct wiring

Hot-swappable collaborators are collected with tagged iterators and resolved through a
registry — not hard-wired: `PaymentGatewayRegistry` (`payment.gateway`),
`PaymentAdjustmentRegistry`, `DepositProviderRegistry`, `WithdrawProviderRegistry`,
`MediaStorageRegistry`, `SettlementContextResolverRegistry`, and the
`trade.price_calculator` pricing chain (priority-ordered, Promotion at 60).

---

## 3. Service Boundaries

### Service shape

- Every service implements a module interface: `{Entity}ServiceInterface extends
  App\Core\Service\BaseServiceInterface`.
- Implementations extend `App\Core\Service\BaseService`, which composes
  `BaseServiceInfrastructureTrait` (container/EM/repository/logger/serializer,
  transaction wrapper, validators), `BaseServiceReadListTrait` (`get`/`list`,
  QueryBuilder listing, DQL compilation), and `BaseServiceMutationTrait`
  (`new`/`update`/`remove`, relation/date mapping, Serializer + Validator integration).
- Services **must not** return raw HTTP responses (controller's job) and must not touch
  `Request` directly — use `getCurrentRequest()`.

### Repository shape

- Repositories extend `ServiceEntityRepository`, take `ManagerRegistry` in the
  constructor, and return entities/arrays/scalars.
- Repositories must **not** return raw `QueryBuilder` to consumers — that is the
  service layer's concern — and may expose a `*RepositoryInterface` when consumed by
  other modules.
- Doctrine 3.6+ repositories carry the `@extends ServiceEntityRepository<...>` PHPDoc
  (enforced by Rector's `AddAnnotationToRepositoryRector`).

### Controller shape

- `App` controllers (public read) extend `RestController` with `ApiView`,
  `DetailApiViewMixin`, `ListApiViewMixin`; they set `$serviceClass` and may override
  `commonFilter()` for row-level scoping. No create/update/delete.
- `Manage` controllers (admin CRUD) add `Create/Update/DeleteApiViewMixin` and are
  guarded `#[IsGranted('ROLE_ADMIN')]` at class level.
- Controllers declare only module-specific constructor dependencies — `RequestStack`,
  `SerializerInterface`, `TranslatorInterface`, container arrive via `#[Required]`
  setter injection on `RestController`.

---

## 4. Naming Conventions

The namespace carries the module; the class name carries the domain concept.
`docs/design/naming-convention.md` is authoritative; the four rules are:

| # | Rule |
|---|------|
| N1 | Class name = domain concept (bare preferred); ownership lives in the namespace |
| N2 | Add a module prefix only on (a) cross-module collision/ambiguity, or (b) intra-module infrastructure (outbox / registry / gateway / provider) |
| N3 | Service / Controller / Repository / Event / Exception strictly mirror the entity name: `{Entity}Service`, `{Entity}Controller`, `{Entity}Repository` |
| N4 | Prefix policy is consistent within a module — never a mix of prefixed and bare |

### Protected cross-module conflict groups (never drop the prefix)

| Concept | Reserved class names |
|---------|----------------------|
| Order | `Trade\Order`, `Store\StoreOrder` |
| User | `Identity\User`, `Wechat\WechatUser` |
| OutboxMessage | `Trade\TradeOutboxMessage`, `Store\StoreOutboxMessage`, `Inventory\InventoryOutboxMessage` |
| ConsumedEvent | `Store\StoreConsumedEvent`, `Inventory\InventoryConsumedEvent` |

(The 2026-08-15 prefix refactor removed `Wallet*`/`Inventory*`/`Store*` prefixes for
unique concepts — `WalletTransaction→Transaction`, `InventoryStock→Stock`,
`StoreMembership→Membership` — with zero migration or API breakage; table names, URLs
and route names were unchanged.)

Forbidden: inventing a service suffix (`CategoryService` for an entity `Category` is
fine, but adding an arbitrary `XxxService` name for a non-service class is not),
inconsistent prefixes inside a module, non-mirrored controller/service names.

---

## 5. Code Style

| Area | Rule |
|------|------|
| PHP | `>= 8.4`, `declare(strict_types=1)` in new code |
| Properties / returns | Explicit types, never docblock-only; nullable as `?Type` |
| Namespace | PSR-4 `App\` under `src/` |
| Use statements | Alphabetically ordered |
| Files | One class/trait/interface per file, filename = classname |
| Classes | PascalCase; methods/properties camelCase; constants `UPPER_SNAKE` |
| Interfaces | `*Interface` suffix; abstracts `Abstract*` / `Base*`; traits `*Trait` or descriptive (`ListApiViewMixin`) |
| Tests | `*Test` suffix, namespaced `App\Tests\...` |
| Comments | PHPDoc on interfaces/abstract methods; no comments on self-documenting code; `@deprecated` + migration notes for removals |

---

## 6. Performance

| Practice | Where it shows up |
|----------|-------------------|
| Paginate every list | `RestController::pagination()` — default limit 100, Doctrine Paginator for QueryBuilder |
| Project columns instead of fetching everything | `@select`, `@display` (e.g. `reduce` = id + `__toString` only) |
| Push filtering to the database | Expression DSL compiles to DQL (`ExpressionDqlParser`); the in-memory fallback is only a fallback |
| Avoid implicit relation loading | Relations load lazily; expand relation trees explicitly via `@expands` |
| Concurrent writes are guarded | `Wallet` uses optimistic locking (`version` column) and pessimistic writes for transfers; token rotation uses reuse detection |
| Costly lookup is indexed/short | UUID/IAM references on aggregates; repositories expose targeted queries |
| Bulk admin writes stay transactional | `@partial=true` for per-item mode, else single-transaction |
| Long-running work goes async | Messenger async transport + outbox publish commands |

---

## 7. Security

### Authentication

- `JwtAuthenticator` extracts and validates the `Authorization: Bearer` JWT and builds
  the Passport; `TokenManager` issues/revokes tokens and manages refresh-token lifecycle.

| # | Rule |
|---|------|
| S1 | Access tokens: RS256 signed, 7200s TTL |
| S2 | Refresh tokens: opaque, HMAC-SHA256-hashed in DB, 1-year TTL |
| S3 | Refresh tokens rotate on every use |
| S4 | Reuse of a revoked/replaced refresh token revokes ALL the user's tokens |
| S5 | Revoked access-token JTIs are blacklisted until natural expiry |
| S6 | Private keys are never committed |
| S7 | HTTPS in production |

### Authorization

- Access control is centralized in `config/packages/security.yaml`: `/api/v1/manage`
  → `ROLE_ADMIN`; `/api` → `IS_AUTHENTICATED_FULLY`; the public list (auth flows,
  register, WeChat login, payment notify, docs, `GET /api/v1/public`) is
  `PUBLIC_ACCESS`.
- Row-level scoping is per-controller via `commonFilter()`/`listFilter()` overrides
  (e.g. a user only ever sees their own records; the App-side filter enforces it).
  `commonFilter()` may return an array (`['user' => $this->getUser()]`), a
  `QueryBuilder`, or a server-owned `DqlExpression` such as
  `new DqlExpression('entity.getUser() == this.getUser()')` or
  `new DqlExpression('entity.getStoreUuid() in storeUuids', ['storeUuids' => $allowed])`.
  `DqlExpression` shares the `@filter` syntax (including `in`/`not in` with empty-
  collection safety) but is fail-closed and never uses the in-memory fallback. The
  designed `Authorization` bundle (`docs/design/bundles/authorization.md`) will build on this
  primitive for scoped RBAC, Store-scoped grants, field grants, and audit logging.
- Manage controllers also carry class-level `#[IsGranted('ROLE_ADMIN')]`.
- Role hierarchy: `ROLE_ADMIN: [ROLE_USER]`.

### Validation order

```
Controller field whitelist ($requiredCreateProperties / $acceptedCreateProperties)
  → JSON Schema validation via $jsonSchemas (CreateApiViewMixin/UpdateApiViewMixin → Core\Validator\JsonSchemaValidator)
    → controller hook validation (processCreateContent / processUpdateContent)
      → @transform expression
        → Service::update() → Symfony Validator → entity #[Assert\*] constraints
```

- **JSON Schema step** runs **before** `processCreateContent`/`processUpdateContent`. Declare on the controller:

  ```php
  protected array $jsonSchemas = [
      'address'  => 'Store/StoreAddress',
      'contact'  => 'Store/StoreContact',
      'settings' => 'Store/StoreSettings',
  ];
  ```

  Each declared field present and non-null is validated against `src/{Bundle}/Resources/JsonSchema/{Name}.json` (`Core\Validator\JsonSchemaValidator::validate()`). Violations throw `JsonSchemaViolationException` → `ValidatorException` → `400` via `warning()`. `Store` bundle ships `StoreAddress` (province/city/district/street/detail/latitude/longitude/geohash...; `latitude↔longitude` dependencies, `additionalProperties:false`), `StoreContact` (phone/email/uuid, `subTitle` 1..100, `tags` array 1..30×20 unique), `StoreSettings` (order/fulfillment booleans, top-level `additionalProperties:true` for forward compat, empty `{}` coerced to object). `Store` entity adds `currency` varchar(32) `DEFAULT 'CNY'` (`REWARD_POINT` for points mall, validated `^[A-Za-z0-9._-]{1,32}$`). Validator lives in `Core`, schemas in each bundle.

### Rate limiting

| Resource | Limit |
|----------|-------|
| OTP request (per phone) | 1 per 60s cooldown |
| OTP verify (per phone) | 5 attempts max |

Backed by Redis (`OTP_REDIS_DSN`) or the local-cache fallback (`LocalCacheOtpStorage`).

### Logging hygiene

- Request bodies on PUT/POST are logged at INFO with user id, URI, body truncated to
  1 KB.
- **Never log** passwords, tokens, OTP codes in plaintext, or PII in production.
- Channels: `app`, `doctrine` (dev), `security`.

### Money and identity

- Money is integer-cents or fixed-scale quantum (`QuantumAmount`, 18-decimal,
  `brick/math`) — never floats.
- Aggregate references across modules are UUIDs — never raw FK or auto-increment ids.

---

## 8. Breaking-Change Policy

| Change | Allowed? |
|--------|----------|
| New mixin trait / hook with default impl | Yes, documented |
| Change a hook signature / remove a mixin method / change the response envelope | **NO** — major version |
| Add a query parameter | Yes (backward compatible) |
| Remove a supported query parameter | **NO** — deprecation + major version |
| Change `BaseServiceInterface` | **NO** — cross-module impact assessment |
| Add a module | Yes, following the module checklist (`docs/design/module-design.md`) |

---

## 9. See Also

- [Development Workflow](development-workflow.md) — branches, CI, static analysis gates
- [Testing](testing.md) — suites, helpers, the 90% coverage gate
- [API Contracts](api-contracts.md) — envelope, auth, URL, pagination, webhooks
- [Architecture](architecture.md) — practical overview of the module model and patterns
- Normative contracts: `docs/design/system-architecture.md`, `system-contracts.md`,
  `module-design.md`, `naming-convention.md`, `controller-design.md`, per-module
  `docs/design/bundles/*.md`