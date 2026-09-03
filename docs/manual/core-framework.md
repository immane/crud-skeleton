# Core Framework — Deep Dive

The `src/Core` bundle is the heart of the crud-skeleton. It provides the base
controller contract, reusable view mixins, a trait-based service layer, a
dynamic query/expression engine, serializer plumbing, kernel event listeners,
utilities, exceptions and a Prometheus-style metrics registry.

This document describes each class, its role, and its key methods. It is a
reference — for practical recipes see [core-usage.md](core-usage.md) and for the
query parameters see [query-system.md](query-system.md).

---

## 1. Controller Layer

### `src/Core/Controller/RestController.php`

The base controller contract. Every API controller extends `RestController`
(which extends `AbstractController`). It centralizes response shaping,
pagination, request processing, and dependency access.

Dependencies are injected via `#[Required]` setters (`setRequestStack`,
`setSerializer`, `setTranslator`, `setServiceContainer`) so child controllers
only need to declare their module-specific dependencies in their own
constructor. See the `_instanceof` wiring in `config/services.yaml`.

Key members:

| Member | Purpose |
|--------|---------|
| `UNKNOWN_ERROR` | Default warning message `'Api error occurred'`. |
| `resolveService(string $id): object` | Resolve an arbitrary service id from the container, throwing if missing. |
| `getService(): object` | Return the controller's `$service` property (must be an object). |
| `getRequestStack()` / `getSerializer()` / `getTranslator()` | Lazy accessors with guards. |
| `pagination(mixed $collection): array` | Applies `page`/`limit` query params and returns `{items, paginator}`. Handles `QueryBuilder` (via Doctrine `Paginator`), arrays, and `ArrayCollection`. |
| `success(mixed $content, string $message, int $status): Response` | Builds the standard envelope `{data, code: 0, message}` (plus optional `paginator`). Returns `204` as an empty body when `$status === 204`. Runs pagination and request processing (`@expands`, `@display`). |
| `warning(string $msg, int $code, mixed $raw, int $status): Response` | Builds the error envelope `{code, message, raw_data}` with the translated message. |
| `requestProcess(mixed $collection)` | Applied inside `success()`. Handles `@expands`, `@display` (complex / JSON projection / `reduce`), pulling request-driven view shaping. |

### `src/Core/Controller/HealthController.php`

Container/load-balancer probes, **public (no JWT)** — they live outside the
`/api` firewall so orchestrators can poll them without tokens.

| Route | Method | Purpose |
|-------|--------|---------|
| `/health/live` | GET | Liveness probe — always `200` while PHP serves requests. |
| `/health/ready` | GET | Readiness — runs `SELECT 1` (database) plus an optional Redis probe; returns `503` degraded when a hard dependency is down. |

The Redis probe (`checkRedis()`) is dependency-free: it opens a raw TCP socket
and sends a RESP `PING`. It returns `disabled` when `OTP_REDIS_DSN` is empty,
`ok` on `+PONG`, otherwise an error string. Details are never leaked to
anonymous callers; they are logged instead.

### `src/Core/Controller/MetricsController.php`

Serves the Prometheus **text exposition format** at `/metrics` (public).
In-memory request metrics (counters, duration histogram, in-flight gauge) are
per PHP-FPM worker. DB-backed gauges — outbox backlog per topic
(`trade_outbox_message`, `store_outbox_message`, `inventory_outbox_message`)
and failed messenger messages — are computed live on each scrape so they are
accurate across all workers. Scrape failures increment
`metrics_scrape_errors_total`.

### `src/Core/Controller/System/EntityController.php`

Introspection endpoint (extends `RestController`) at `/system/entities`.

| Route | Purpose |
|-------|---------|
| `GET /system/entities` | Lists the FQCN of every Doctrine-managed entity. |
| `GET /system/entities/{entityName}` | Returns field mappings (type, columnName, nullable, length, etc.), association mappings (type, targetEntity), plus a humanized plaintext label and its translation for each property. `entityName` uses slashes instead of backslashes (e.g. `App/Common/Entity/Category`). |

### `src/Core/Controller/System/RouterController.php`

At `GET /system/router`, returns every route registered in the Symfony router
keyed by route name.

### `src/Core/Controller/DefaultController.php`

Top-level default controller (not covered in detail here; see the file).

---

## 2. View Layer

The view layer turns a configured controller into a full REST endpoint simply
by composing **traits**. Each mixin defines routes (`#[Route]`), OpenAPI
attributes (`#[OA\*]`), and the action method. Controllers opt in via `use`.

### `src/Core/View/ApiView.php`

Base **trait** that provides shared filtering helpers. Not a mixin that defines
routes itself; it provides the `commonFilter` extension point and criterion
merging used by the read mixins.

| Member | Purpose |
|--------|---------|
| `entityNotFoundMessage()` | Default `'Entity not found'`. |
| `commonFilter()` | Extension point: return an array, `QueryBuilder`, or `DqlExpression` restricting all queries on the controller (e.g. `['enabled' => true]`, `['user' => $this->getUser()]`, or `new DqlExpression('entity.getUser() == this.getUser()')`). |
| `resolvedCommonFilter()` | Internal resolver used by the mixins: returns `commonFilter()` with `this` bound for `DqlExpression`. Do not override. |
| `identifierField(int\|string $value)` | Returns `uuid` for a canonical UUID string, otherwise `id`. Use when building scope-aware or custom filters so both forms remain unambiguous. |
| `identifierCriteria(int\|string $value)` | Returns `['uuid' => $value]` or `['id' => $value]` via `identifierField()`. |
| `scopeIdentifierCriteria(int\|string $scopeId)` | Convenience wrapper over `identifierCriteria()` for scoped parent identifiers (e.g. `scopeId` in `/store/{scopeId}/orders/{id}`). |
| `mixIdToCommonFilter(int\|string $id)` | Merges an id into the common filter; uses `uuid` as the key when the id is a valid UUID, otherwise `id`. For `DqlExpression` it appends the id as an additional `AND` criterion. |
| `mixToCommonFilter(array $data)` | Merges `$data` into the common filter. For a `QueryBuilder` it appends `AND alias.key = :key` conditions; for `DqlExpression` it appends criteria via `withCriteria()`. |

### Standard CRUD mixins

These are the meat of the view layer. Each is used as:

```php
use ApiView, DetailApiViewMixin, ListApiViewMixin,
    CreateApiViewMixin, UpdateApiViewMixin, DeleteApiViewMixin;
```

| Mixin | Route(s) | Methods | Behavior |
|-------|----------|---------|----------|
| `ListApiViewMixin` | `/` (name `list`) | GET | Calls `service->list($this->listFilter($this->commonFilter()), null, false)`, then `listProcessor` / `listResponses`. |
| `DetailApiViewMixin` | `/{id}` (name `detail`) | GET | `mixIdToCommonFilter`, then `detailFilter`, `detailProcessor`, `detailResponse`. 404 when missing. |
| `CreateApiViewMixin` | `/` (name `create`) | POST | Accepts a single object or array (batch). Enforces `requiredCreateProperties` / `acceptedCreateProperties`, applies `defaultCreateValues`, `processCreateContent`, `processEntity`, `afterCreated`. Batch/transactional unless `@partial`. Supports `jsonSchemas` (see below) — validated before `processCreateContent`. |
| `UpdateApiViewMixin` | `/{id}` (PUT, `update`); `/batch-update` (POST) | PUT/POST | Single update by id plus batch/upsert mode via `@mode` and `@basis`. Enforces `requiredUpdateProperties` / `acceptedUpdateProperties`. Supports `jsonSchemas` — validated before `processUpdateContent`/`processCreateContent`. |
| `DeleteApiViewMixin` | `/{id}` (name `delete`) | DELETE | `deletionFilter`, `service->get`, then `service->remove`; returns `204` on success, 404 when missing. |

#### `CreateApiViewMixin` key points

- Determines input shape with `FixJSON::getJSONType()` (`object` → single,
  `array` → batch).
- If `requiredCreateProperties` is defined, each listed property must exist in
  the payload or a `ValidatorException` (`"{Property} is required"`) is thrown.
- If `acceptedCreateProperties` is defined, only those keys are forwarded.
- `@partial` (boolean) disables the surrounding transaction; `@transform`
  enables content transformation.
- Returns `201` with the created entity (or array of entities).

#### `UpdateApiViewMixin` key points

- Single update: `PUT /{id}`, looks up via `mixIdToCommonFilter`.
- Batch update: `POST /batch-update`.
  - `@mode`: `update` or `mixed` (default `mixed` = upsert: create when no match).
  - `@basis`: comma-separated fields used to match existing entities.
  - `@partial`: when false, wraps the whole batch in one transaction.
- Enforces `requiredUpdateProperties` / `acceptedUpdateProperties` via
  `propertyCannotBeEmpty`.
- In mixed mode it may call the create code path (`defaultCreateValues`,
  `processCreateContent`, `afterCreated`).

### Scoped mixins

| Mixin | Route | Behavior |
|-------|-------|----------|
| `ScopedListApiViewMixin` | `/{scopeId}` (name `list`) | Calls the abstract `scopedListFilter($scopeId)` then `service->list(...)`. `scopeId` accepts numeric ID or UUID; implementations should use `identifierCriteria()` / `identifierField()` so both forms are handled without fallback guessing. |
| `ScopedDetailApiViewMixin` | `/{scopeId}/{id}` (name `detail`) | Calls abstract `scopedDetailFilter($scopeId, $id)` and returns 404 when nothing matches. Both `scopeId` and `id` accept numeric ID or UUID (`\d+\|[0-9a-fA-F-]{36}`) and controllers must resolve them via `identifierField()` / `identifierCriteria()` or `mixIdToCommonFilter()`. |

### "Single resource" mixins

For resources that are singletons (e.g. a user's own single profile/settings
record) rather than collections:

| Mixin | Route | Behavior |
|-------|-------|----------|
| `SingleDetailApiViewMixin` | `/` (name `detail`) | GET — returns whatever `commonFilter()` finds (may be null). |
| `SingleCreateAndUpdateApiViewMixin` | `/` (name `update`) | PUT — upserts the single record: if `commonFilter()` finds nothing it creates (filtering via `filterCreateProperties` + `defaultCreateValues`), otherwise updates (`filterUpdateProperties` + `defaultUpdateValues`). |

### `WorkflowApiViewMixin`

Adds Symfony Workflow helpers for a controller that declares a `$workflow`
service (and typically a `$serviceClass`):

| Route | Method | Behavior |
|-------|--------|----------|
| `/todo` | GET | Lists entities with at least one enabled transition. |
| `/{id}/transitions` | GET | Lists enabled transitions for an entity by numeric ID or UUID (`mixIdToCommonFilter()`). Returns `404` when not found. |
| `/{id}/do/{transition}` | POST | Applies a transition inside a transaction (optionally updating the entity first) by numeric ID or UUID. Returns `404` when not found, `403` on authorization failure, throws `TRANSITION_CANNOT_APPLY` when `can()` fails. |
| `/{id}/status-reset` | PUT | `ROLE_ADMIN` only — resets the workflow marking by numeric ID or UUID (`mixIdToCommonFilter()`). Returns `404` when not found. |

Scoped routes such as `/store/{scopeId}/orders/{id}` follow the same rule: both `scopeId` and `id` accept numeric ID or UUID. Implement `scopedDetailFilter()` with `identifierField()` / `identifierCriteria()` (e.g. `[$this->identifierField($id) => $id, ...$storeFilter]`) and resolve the parent Store via `identifierCriteria($scopeId)` so resolution is explicit and never relies on an `id`-then-`uuid` fallback. |

> Note: Many modules (e.g. Trade `OrderController`) implement workflow actions
> inline rather than using this trait — prefer the trait when it fits, or follow
> the Trade controllers for more control.

### `TransformContent` trait

Enables `@transform` (or the `$transformer` argument) to rewrite submitted
content through Symfony `ExpressionLanguage`. For each field in the transformer
map it resolves the target entity's property metadata to detect Doctrine
relations and, for a to-one relation, resolves the matching `*Service` so you
can write things like:

```
category: "Service.get({'name': ':value'}).getId()"
```

It exposes `Service` (a gateway with `get`/`list` wrapping the resolved service
and returning identity objects), `entity`, `Math`, and `ArrayCommon` to the
expression. Throws `INVALID_CONTENT_FIELD` when a field is not a real property.

### `ApiViewMessages`

Constant/helper set for shared user-facing messages:

| Constant | Value |
|----------|-------|
| `SUCCESS` | `SUCCESS` |
| `ENTITY_NOT_FOUND` | `Entity is not found` |
| `INVALID_JSON` | `Invalid JSON` |
| `INVALID_CONTENT_FIELD` | `Invalid content field` |
| `CREATE_FAILED` | `Create failed` |
| `BATCH_UPDATE_ERROR` | `Batch update error` |
| `CONTENT_TYPE_ERROR` | `Content type error.` |
| `TRANSITION_CANNOT_APPLY` | `Current transition cannot be applied.` |
| `propertyRequired($p)` | `"{P} is required"` |
| `propertyCannotBeEmpty($p)` | `"{P} cannot be empty."` |

### `src/Core/Validator/JsonSchemaValidator.php`

Generic JSON Schema validator (`justinrainbow/json-schema` `^6.11`). Bundle schemas live under `src/{Bundle}/Resources/JsonSchema/{Name}.json` (e.g. `Store/StoreAddress`).

| Member | Purpose |
|--------|---------|
| `validate(mixed $data, string $schemaName)` | Load `src/{Bundle}/Resources/JsonSchema/{Name}.json` via `%kernel.project_dir%`, coerce PHP arrays to objects, run draft-07 validation, throw `JsonSchemaViolationException` on failure (`property: message`). `null` is skipped (field is nullable). |
| `validateInline(mixed $data, array $inlineSchema)` | Validate against an inline schema array. |

`Store` bundle ships `StoreAddress`, `StoreContact`, `StoreSettings` schemas (see `store.md §5.1.1`); `Core` owns only the validator. Register `App\Core\Validator\JsonSchemaValidator` as `public: true` so `Create/UpdateApiViewMixin` can fetch it from the container (`serviceContainer`/`container`).

#### `jsonSchemas` on controllers

Any controller using `CreateApiViewMixin`/`UpdateApiViewMixin` may declare:

```php
protected array $jsonSchemas = [
    'address'  => 'Store/StoreAddress',
    'contact'  => 'Store/StoreContact',
    'settings' => 'Store/StoreSettings',
];
```

The mixins call `validateJsonSchemas($content)` — for each declared field present and non-null — **before** `processCreateContent`/`processUpdateContent`. Violations become `400` via `ValidatorException`→`warning()`. Unknown keys are rejected when the schema sets `additionalProperties: false` (Store `address`/`contact`), while `StoreSettings` keeps `additionalProperties:true` for forward compatibility.

---

## 3. Service Layer

### `src/Core/Service/BaseServiceInterface.php`

The contract implemented by `BaseService`. Generic over `TEntity`.

| Method | Signature | Purpose |
|--------|-----------|---------|
| `get` | `($object, bool $directly = false)` | Resolve an entity from an id, criteria array, `QueryBuilder`, `DqlExpression`, or an object with `getId()`. |
| `list` | `($object = null, $order = null, bool $disableRequest = true)` | List entities or return a configured `QueryBuilder`. Accepts an array, `QueryBuilder`, or `DqlExpression` as the base filter. When `$disableRequest` is `false`, consult the current request for `@filter`/`@order`/`@select`/`@groupBy`/`@hints`/`@sort`. |
| `new` | `()` | Create a new entity instance. |
| `update` | `($object, ?array $data, bool $noFlush = false)` | Persist a data array onto an entity; flush unless `$noFlush`. |
| `remove` | `($object): bool` | Remove and flush. |
| `wrapInTransaction` | `(callable $fn): mixed` | Run a callable inside a DB transaction with all-or-nothing semantics. |

### `src/Core/Service/BaseService.php`

Abstract base that assembles the three concern traits and wires shared state
through the constructor: container, entity class, locator, entity manager,
repository, logger, current user (from the token storage), and optional
`ExpressionServiceInterface` / `LegacyEvaluator`.

### `src/Core/Service/ServiceLocatorInterface.php` & `DefaultServiceLocator.php`

A small interface decoupling `BaseService` from the container, which keeps
test fakes lightweight. It exposes `getEntityManager`, `getLogger`,
`getTokenStorage`, `getRequestStack`, `getSerializer`, `getValidator` (return
types documented via PHPDoc so fakes stay untyped). `DefaultServiceLocator`
implements it by pulling those services from the container, returning `null` /
`NullLogger` gracefully when absent. Autowired as
`App\Core\Service\ServiceLocatorInterface`.
`DefaultServiceLocator` is registered as that interface alias in
`config/services.yaml`.

### Service concerns (`src/Core/Service/Concern/`)

#### `BaseServiceInfrastructureTrait`

Shared infrastructure accessors and helpers:

| Member | Purpose |
|--------|---------|
| `listResultToCollection($list): ArrayCollection` (static) | Normalize a `QueryBuilder` result or array into an `ArrayCollection`. |
| `externalExpressionValues(): array` | Values exposed to expressions: `math`/`Math`, `datetime`/`Datetime`, `ArrayCommon`. |
| `getEntityManager()` / `getRepository()` / `getLogger()` / `getSerializer()` / `getValidator()` / `getRequestStack()` | Lazy accessors, with fallbacks (e.g. a hand-rolled `Serializer` when none is registered). |
| `getCurrentRequest()` | Current request via the request stack. |
| `getQueryBuilderFactory()` / `getExpressionService()` / `getLegacyEvaluator()` | Lazy creators of the query engine collaborators. |
| `wrapInTransaction(callable $fn): mixed` | Begins a transaction, flushes before commit, rolls back on any `Throwable`, and resets a closed EntityManager. Falls back to plain execution for fake/mock EM (no `beginTransaction`). |
| `resetEntityManager()` | Rebound a fresh manager/repository after a connection failure. |

#### `BaseServiceReadListTrait`

Implements `get()` and `list()` (see [query-system.md](query-system.md) for the
full `list()` behavior).

- `get()` handles: `null` (→ null), `DqlExpression` (compiled to a filtered `QueryBuilder`), `QueryBuilder` (single result), an object
  with `getId()`, an associative criteria array (`findOneBy`), a valid UUID
  string (matched against a `uuid` field when present), or a plain id
  (`rep->find`).
- `list()` builds a `QueryBuilder` from the entity class, applies an
  associative array as equality conditions or a `DqlExpression` via `ExpressionDqlParser` + `ExpressionQueryBuilderAssembler`, and — when `$disableRequest` is
  `false` — processes `@dql`, `@filter`, `@select`, `@groupBy`, `@order`,
  `@hints`, `@showDQL`, and the in-memory `@sort`/`@filter` fallback. A server-owned `DqlExpression` failure is fail-closed (500) and never falls back to in-memory.
- Privileged parameters `@dql`, `@sort`, `@hints` are restricted to
  `ROLE_ADMIN`; `@showDQL` is restricted to the `dev` environment. `@select` is
  guarded against accessing identity data.

#### `BaseServiceMutationTrait`

Implements `new()`, `update()`, `remove()`.

- `new()` instantiates the entity, bypassing a required constructor if needed.
- `update()` maps data onto the entity: it reflects property attributes to
  detect relations — to-one (`ManyToOne`/`OneToOne`) relations are resolved by
  id and set via the setter; to-many (`ManyToMany`/`OneToMany`) collections are
  diffed and add/remove methods called; date-like properties are converted with
  `new \DateTime(...)`. Remaining scalar fields are deserialized via the
  Symfony Serializer with `object_to_populate`. It validates the entity and
  persists (flushing unless `$noFlush`). Throws `ValidatorException` on null
  objects, missing related entities (`NotFoundHttpException`), and
  `"Duplication entries"` on a `UniqueConstraintViolationException`.
- `remove()` gets, removes and flushes, returning success.

---

## 4. Parser & Expression Engine

See [query-system.md](query-system.md) for the complete user-facing query
reference. Here we document the classes.

### `src/Core/Parser/ExpressionDqlParser.php`

Compiles an `@filter` expression into safe `QueryBuilder` fragments (joins,
where, parameters). It parses with Symfony `ExpressionLanguage`, then walks the
AST (`recursiveCompile`) supporting a strict, safe node set.

Supported operators map to DQL:

| Operator | DQL fragment |
|----------|--------------|
| `==` / `!=` | `=` / `!=` |
| `>` `>=` `<` `<=` | same |
| `&&` / `||` | `AND` / `OR` |
| `+ - * /` | same |
| `in` / `not in` | `IN (:param)` / `NOT IN (:param)` — bound as array parameters; empty `in []` becomes `1 = 0` (no rows), empty `not in []` becomes `1 = 1` |
| `matches` | `REGEXP(...) = TRUE` for `/pattern/flags`, else `LIKE '%...%'` |

Notable members:

| Member | Purpose |
|--------|---------|
| `setExpression` / `setDataClass` / `setValues` | Configure the parser (builder style). `setValues` keeps an `entity` signature key. |
| `compile(int $cacheTtl = 3600): self` | Parse + compile; uses a PSR-16 cache when available. Throws `ValidatorException` on syntax/compile errors. |
| `getWhere()` / `getJoins()` / `getParameters()` | Compiled fragments. |
| `getFragments(): array` | Returns `{joins, where, params}` — DB-agnostic; the assembler applies them. |
| `getSource(?QueryBuilder $qb = null): string` | Full DQL source of the compiled expression. |
| `validateFragments(EntityManagerInterface $em): void` | Re-resolves every join path and where token against Doctrine metadata, throwing `ValidatorException` for unknown aliases/properties. |

Attribute chains like `entity.getCategory().getName()` produce joins
(`filter_entity_category` etc.) and a final `filter_entity_category.name`
reference. A top-level bare attribute compiles to `prop IS NOT NULL`.

### `src/Core/Query/DqlExpression.php`

Immutable value object for **server-owned** row-level scopes (ABAC). Unlike
client `@filter`, it never falls back to in-memory evaluation and always
fail-closes.

```php
use App\Core\Query\DqlExpression;

// Explicit variable binding
new DqlExpression('entity.getUser() == user', ['user' => $this->getUser()]);

// Controller `this` shorthand — only inside commonFilter()
new DqlExpression('entity.getUser() == this.getUser()');
new DqlExpression('entity.getStoreUuid() in this.getAllowedStoreUuids()');

// Combined predicates and collection membership
new DqlExpression(
    'entity.getUser() == user && entity.getCurrency() in currencies',
    ['user' => $user, 'currencies' => ['CNY','USD']]
);
```

| Member | Purpose |
|--------|---------|
| `__construct(string $expression, array $values = [], array $criteria = [], ?object $context = null)` | Create an immutable scope. `expression` is non-empty; `values` keys are `[A-Za-z_][A-Za-z0-9_]*` and may not be `entity`/`this`. |
| `withCriteria(array $criteria): self` | Return a new instance with additional `field => value` equality predicates (used internally by `ApiView` to append `id`/`uuid`). Duplicate keys throw. |
| `withContext(object $context): self` | Bind the controller `this` (idempotent for the same object, rejects a different one). |
| `usesThis(): bool` | Whether the expression contains `this.` |
| `criteria(): array` / `context(): ?object` | Accessors. |

Server-owned compilation in `BaseServiceReadListTrait` validates the expression
against Doctrine metadata, binds variables as parameters, and appends criteria as
additional `AND` predicates. Empty `in` collections are compiled to `1 = 0` so that
a missing permission yields no rows rather than an invalid `IN ()`.

### `src/Core/Parser/ExpressionQueryBuilderAssembler.php`

Applies `ExpressionDqlParser` fragments onto a Doctrine `QueryBuilder`.

| Method | Purpose |
|--------|---------|
| `buildQueryBuilder(parser, $options = []): QueryBuilder` | Create a fresh `SELECT root FROM dataClass root` and apply the fragments. |
| `applyToQueryBuilder($qb, parser, $options = []): QueryBuilder` | Apply fragments to an existing QueryBuilder (remaps root alias, dedupes joins/aliases, renames colliding parameter names, and applies `leftJoin` + `andWhere` + params). |

### `src/Core/Service/ExpressionService.php` (+ interface `ExpressionServiceInterface`)

Central wrapper that turns a filter string into a `{qb, parameters}` result
(used by `BaseServiceReadListTrait`). It caches compiled DQL + parameters via a
PSR-16 cache, otherwise parses/validates/assembles through the parser +
assembler. `parseAndAssemble()` is protected so unit tests can override it.

### `src/Core/Service/QueryBuilderFactory.php`

Simple factory for creating `QueryBuilder` roots: `create($dataClass,
$rootAlias = 'entity')` (an alias of `createRootQueryBuilder`) returns a
`SELECT alias FROM dataClass alias`. Keeps creation in one place so `BaseService`
can delegate and tests can mock it.

### `src/Core/Service/LegacyEvaluator.php`

Provides the in-memory fallback for `@filter` and `@sort` using Symfony
`ExpressionLanguage`.

| Method | Purpose |
|--------|---------|
| `evaluate($expr, $context = [])` | Evaluate against globals+context; returns `false` and logs on error. |
| `evaluateBool($expr, $context = []): bool` | Boolean-cast convenience (used when filtering/sorting in memory, e.g. `@sort` comparing `x`/`y`). |

---

## 5. Serializer

### `src/Core/Serializer/SerializerContextFactory.php`

Builds canonical serializer contexts. Recognized options: `groups`
(array|string) and `max_depth` (int, with `enable_max_depth` defaulting to
`true`).

### `src/Core/Serializer/CircularReferenceHandler.php`

Static, dependency-free circular-reference handler: returns the object's `id`
when it has `getId()`, otherwise `spl_object_hash`. Referenced from
serializer config.

### `src/Core/Serializer/Normalizer/FlatNormalizer.php`

Decorates the default object normalizer (registered as
`app.serializer.method_normalizer`, decorating
`serializer.normalizer.object`) to produce the project's "flat" entity
representation:

- Avoids normalizing Doctrine internal objects (`Doctrine\ORM\*`,
  `Doctrine\Persistence\*`) — stringifies or returns the class name.
- Adds `__toString` at the top level when present.
- Reduces related objects to `{id, __toString, __metadata}`.
- Expands traversable collections of related objects.
- JSON-decodes string-valued fields (excluding numeric strings) into objects.
- On normalization failure returns `{id, __toString}` or `{__class}`.

### `src/Core/Serializer/Normalizer/CircularReferenceHandler.php`

A second, stricter circular handler: returns `['id' => $id]` when the object
has a scalar id, otherwise throws `"Every entity should have getId method"`.

### `src/Core/Serializer/Callbacks/ObjectCallback.php`

Serializer callback (`handle(object)`): returns the object's `getId()`, else
`null`. Useful as a callback map entry for specific properties.

---

## 6. Kernel Event Listeners

Registered in `config/services.yaml` (and some in the Core bundle
`services.yaml`).

### `AccessLogListener`

Responds to `kernel.response` (priority `-5`). Logs `POST`/`PUT`/`DELETE`
requests to the `monolog.logger.access` channel in the form
`{user} {METHOD} {uri} | {status} | REQ: ... | RES: ...`. Bodies are truncated
at 4096 chars. Auth paths (`/api/auth`, `/api/wechat`) have their bodies hidden.

### `ControllerListener`

Responds to `kernel.controller`. Logs `PUT`/`POST` requests with the operating
user id and a 1 KB-truncated body (`"User [#$operator] Requests $method $uri: ..."`).

### `ExceptionInterceptor`

Responds to `kernel.exception`. Only acts on `/api/...` URLs. Logs the
exception, returns JSON `{code, message, class}` with an appropriate status
when out of dev. (If the environment equals `dev.disabled` it re-throws so
Symfony shows the debug page.)

### `LocaleListener`

Responds to `kernel.request` (priority `20`). Sets the request locale from
`_locale` query param or the `Accept-Language` header. Supported locales:
`en`, `zh`, `zh_Hant`, `ja`. Maps verbose forms (`zh-CN` → `zh`, `zh-TW` →
`zh_Hant`, `ja-JP` → `ja`, etc.).

### `MetricsListener`

Responds to `kernel.request` (priority `10`) and `kernel.response` (priority
`-1`). Records `http_requests_total` (counter by method/route/status),
`http_request_duration_seconds` (histogram), and `http_requests_inflight`
(gauge). Skips sub-requests and `/health`, `/metrics`, `/_profiler`, `/_wdt`,
`/api/doc` paths.

### `OpenApiEnricherListener`

Responds to `kernel.response` (priority `-10`). Post-processes the
`/api/doc.json` and `/api/doc` responses to enrich the OpenAPI spec: injects
canonical tags, per-endpoint summaries/descriptions from a `META` map,
multi-part upload request bodies for the media upload endpoints, and strips the
generic mixin tags (`List`, `Detail`, `Create`, `Update`, `Delete`, `Workflow`).
Tags are auto-detected from route names (`manage-*`/`app-*` resource segments,
`system-*`, `wechat-*`, `sys-auth`).

### `RateLimitListener`

Responds to `kernel.controller` (priority `10`). Applies rate limits keyed by
client IP to public endpoints:

| Limiter | Paths |
|---------|-------|
| `auth_login` | `^/api/auth/login$` |
| `auth_register` | `^/api/auth/register$` |
| `otp_request` | `^/api/auth/otp/request$` |
| `otp_verify` | `^/api/auth/otp/verify$` |
| `wechat_login` | `^/api/wechat/miniapp/login$`, `^/api/wechat/oauth/callback$` |
| `payment` | `~^/api/v1/app/orders/.+/payment$~`, `~^/api/v1/(app\|manage)/invoices/.+/pay~` |

On exceedance it replaces the controller with a `429` response in the standard
envelope `{data, code, message}` plus a `Retry-After` header. Limiters are
injected via a service locator (`auth_login`, `auth_register`, `otp_request`,
`otp_verify`, `wechat_login`, `payment`).

---

## 7. Utility Classes (`src/Core/Utils`)

| Class | Purpose / key methods |
|-------|-----------------------|
| `UUID` | UUID generation/validation: `v3`, `v4`, `v4c` (compact v4), `v5`, `is_valid`. |
| `Math` | Static math helpers (`random`, `locationDistance`) plus wrappers for the full PHP math function set (abs, trig, log, round with `PHP_ROUND_HALF_*` modes, etc.), exposed to expressions. |
| `StringCase` | `dashesToCamelCase($string, $capitalizeFirstCharacter = false)`. |
| `ArrayCollection` | `init($array)`, `fromJsonString($json)`, `map($array, $key)` (maps via `get{Key}` getter) — all returning Doctrine `ArrayCollection`. |
| `ArrayCommon` | Expression-friendly array helpers: `in_array`, `count`, `merge`, `push`, `key_exist`, plus expression-based `filter`, `map`, `reduce`. |
| `FilterDateTime` | `get($time = 'now', ?DateTimeZone)` → `DateTime`. |
| `FixJSON` | `fixJSON($json)` converts single-quoted JSON to valid JSON; `getJSONType($json)` returns `'object'`, `'array'`, or `false`. |
| `Inflect` | `pluralize`, `singularize`, `pluralize_if` with plural/singular/irregular/uncountable tables (used to derive collection add/remove method names). |
| `Location` | Tencent Map geocoding helpers (`getLocation`, `getAddress`, `getDistance`) via the `Curl\Curl` client. |
| `RsaClient` | RSA sign/verify and encrypt/decrypt over raw keys or key files (`rsaSign`, `rsaVerifySign`, `sign`, `verifySign`, `getSignContent`, `privateEncryptRsa`, `publicEncryptRsa`, `privateDecryptRsa`, `publicDecryptRsa`, key length helpers). |

---

## 8. Exceptions (`src/Core/Exception/`)

| Class | Purpose |
|-------|---------|
| `MessageErrorHttpException` | `HttpException` with status `403`, optional `redirectUrl` header. Use for business "error" control-flow. |
| `MessageSuccessHttpException` | `HttpException` with status `200`, optional `redirectUrl` header. Use for business "success" control-flow message payloads. |

Both are handled by `ExceptionInterceptor` and translated in the JSON
`message` field.

---

## 9. Metrics Registry (`src/Core/Metrics/MetricsRegistry.php`)

A lightweight, in-memory Prometheus-style registry (no external client).

| Method | Purpose |
|--------|---------|
| `incCounter($name, $labels, $by = 1)` | Increment a counter. |
| `setGauge($name, $labels, $value)` / `getGauge(...)` | Set/read a gauge. |
| `observe($name, $labels, $value, $buckets = null)` | Record a histogram observation (default buckets `HISTOGRAM_BUCKETS` in seconds). |
| `render(): string` | Render the Prometheus text exposition format (v0.0.4). |

Registered metric names in `METADATA`: `app_info`, `http_requests_total`,
`http_request_duration_seconds`, `http_requests_inflight`,
`app_outbox_backlog`, `app_messenger_failed`, `metrics_scrape_errors_total`.
Counters/histograms are per-worker; DB-backed gauges are computed live on scrape.
