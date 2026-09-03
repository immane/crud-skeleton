# Core Bundle Design

> The Core bundle (`src/Core/`) is the **foundational framework** that all business modules depend on.
> It provides the controller base class, CRUD service abstraction, dynamic query engine, view mixin system, serializer pipeline, and utility classes.

---

## 1. Overview

The Core bundle is NOT a business module -- it is the framework layer. It exports abstractions consumed by Common, Trade, Payment, Wallet, and Identity modules. Core MUST NOT depend on any business module.

### 1.1 What Core Provides

| Concern | Components |
|---------|-----------|
| HTTP Response | `RestController` -- unified JSON success/warning/pagination |
| CRUD Service | `BaseService` + `BaseServiceInterface` -- abstract CRUD with dynamic queries |
| View Mixins | 9 PHP traits -- list, detail, create, update, delete, workflow, singleton |
| Dynamic Queries | `ExpressionDqlParser` -- compile expression to DQL, `ExpressionService` -- caching facade |
| Serializer | `FlatNormalizer` -- decorates ObjectNormalizer, `CircularReferenceHandler`, `ObjectCallback` |
| Error Handling | `ExceptionInterceptor` -- catch unhandled /api exceptions, `ControllerListener` -- request logging |
| Utilities | UUID, RSA, Math, ArrayCommon, StringCase, Inflect, FilterDateTime, FixJSON, Location |
| DI | `ServiceLocatorInterface` + `DefaultServiceLocator` -- testable service location |

---

## 2. File Structure

```
src/Core/
|-- CoreBundle.php                              # Empty Symfony bundle class
|-- Controller/
|   |-- RestController.php                      # Base API controller (356 lines)
|   |-- DefaultController.php                   # Empty stub
|-- DependencyInjection/
|   |-- Configuration.php                       # Empty config tree
|   |-- CoreExtension.php                       # Loads services.yaml
|-- EventListener/
|   |-- ControllerListener.php                  # Logs PUT/POST requests
|   |-- ExceptionInterceptor.php                # Catches unhandled /api exceptions
|-- Exception/
|   |-- MessageErrorHttpException.php           # User-facing error
|   |-- MessageSuccessHttpException.php         # Success with custom message
|-- Parser/
|   |-- ExpressionDqlParser.php                 # Expression -> DQL compiler (479 lines)
|   |-- ExpressionQueryBuilderAssembler.php     # QueryBuilder from fragments (172 lines)
|-- Resources/config/
|   |-- services.yaml                           # Core service registrations
|-- Serializer/
|   |-- Callbacks/ObjectCallback.php            # Static ID callback
|   |-- CircularReferenceHandler.php            # Returns entity ID or hash
|   |-- Normalizer/
|   |   |-- CircularReferenceHandler.php        # Alternative requiring getId()
|   |   |-- FlatNormalizer.php                  # Decorates ObjectNormalizer (181 lines)
|   |-- SerializerContextFactory.php            # groups, max_depth context builder
|-- Service/
|   |-- BaseService.php                         # Abstract CRUD service
|   |-- BaseServiceInterface.php                # CRUD contract (6 methods)
|   |-- DefaultServiceLocator.php               # Production locator
|   |-- ExpressionService.php / Interface       # Filter parser facade with cache
|   |-- LegacyEvaluator.php                     # ExpressionLanguage wrapper
|   |-- QueryBuilderFactory.php                 # QB factory with root alias
|   |-- ServiceLocatorInterface.php             # DI abstraction contract
|   |-- Concern/
|       |-- BaseServiceInfrastructureTrait.php  # EM, Logger, transactions
|       |-- BaseServiceMutationTrait.php        # new(), update(), remove()
|       |-- BaseServiceReadListTrait.php        # get(), list() with dynamic queries
|-- Utils/
|   |-- ArrayCollection.php
|   |-- ArrayCommon.php                         # Expression-language compatible array ops
|   |-- FilterDateTime.php                      # DateTime factory
|   |-- FixJSON.php                             # JSON repair + type detection
|   |-- Inflect.php                             # English pluralizer/singularizer
|   |-- Location.php                            # Tencent Maps API
|   |-- Math.php                                # Math functions (ExpressionLanguage)
|   |-- RsaClient.php                           # RSA sign/verify/encrypt/decrypt
|   |-- StringCase.php                          # dashesToCamelCase
|   |-- UUID.php                                # UUID v3/v4/v5/v4c
|-- View/
    |-- ApiView.php                             # Service binding + commonFilter()
    |-- CreateApiViewMixin.php                  # POST / single + batch create
    |-- DeleteApiViewMixin.php                  # DELETE /{id}
    |-- DetailApiViewMixin.php                  # GET /{id}
    |-- ListApiViewMixin.php                    # GET / with dynamic queries
    |-- SingleCreateAndUpdateApiViewMixin.php   # PUT / singleton upsert
    |-- SingleDetailApiViewMixin.php            # GET / singleton detail
    |-- TransformContent.php                    # Expression-based field transform
    |-- UpdateApiViewMixin.php                  # PUT /{id} + batch-update
    |-- WorkflowApiViewMixin.php                # State machine transitions
```

---

## 3. RestController -- Base Controller Contract

**File**: `src/Core/Controller/RestController.php`

Extends `Symfony\Bundle\FrameworkBundle\Controller\AbstractController`. All API controllers MUST extend this.

### 3.1 Core Methods

```php
// Success response: {data, code: status, message}
success($content, $message = 'SUCCESS', $status = 200): JsonResponse

// Error response: {code: error_code, message, data}
warning($error_msg = null, $error_code = 400, $raw_data = '', $status = 400): JsonResponse

// Pagination handler: supports QueryBuilder, array, ArrayCollection
pagination($collection): array  // returns paginator metadata

// Field projection & nested expansion
requestProcess($collection, $request): void
expandObjects($entities, $expands): void
```

### 3.2 Setter Injection

| Dependency | Method | Attribute |
|-------------|--------|-----------|
| `RequestStack` | `setRequestStack()` | `#[Required]` |
| `SerializerInterface` | `setSerializer()` | `#[Required]` |
| `TranslatorInterface` | `setTranslator()` | `#[Required]` |

### 3.3 Response Envelope

```json
// Success
{"data": {...}, "code": 200, "message": "SUCCESS"}

// With pagination
{"data": [...], "code": 200, "message": "SUCCESS", "paginator": {"page":1,"limit":20,"pages":5,"total":100}}

// Error
{"code": 400, "message": "...", "data": ""}
```

---

## 4. BaseService -- CRUD Service Contract

**File**: `src/Core/Service/BaseService.php`
**Interface**: `src/Core/Service/BaseServiceInterface.php`

Composed of three traits:

```
BaseService (abstract)
|-- BaseServiceInfrastructureTrait   # EM, Logger, Serializer, Validator, Transactions
|-- BaseServiceReadListTrait         # get(), list() with dynamic queries
|-- BaseServiceMutationTrait         # new(), update(), updateWithoutListener(), remove()
```

### 4.1 BaseServiceInterface Methods

| Method | Signature | Description |
|--------|-----------|-------------|
| `get` | `get(mixed $object, bool $directly=false)` | Find by local id, UUID, criteria, or QueryBuilder |
| `list` | `list($object=null, $order=null, bool $disableRequest=true)` | List or return QueryBuilder |
| `new` | `new()` | Create entity instance |
| `update` | `update($object, array $data=null, bool $noFlush=false)` | Persist entity with data |
| `updateWithoutListener` | `updateWithoutListener($object, array $data)` | Bulk DQL update (no events) |
| `remove` | `remove($object): bool` | Delete entity |

### 4.1.1 Identifier Lookup Contract

`get()` is the common lookup facade for the mandatory local integer ID and an optional
mapped UUID. Core CRUD routes keep `{id}` as the route parameter and pass either form
to this method:

```php
$service->get(123);                  // local integer primary key
$service->get('123');                // local integer primary key, backward compatible
$service->get('canonical-uuid');     // unique uuid field
$service->get(['uuid' => $uuid]);    // explicit criteria, always unambiguous
```

Rules:

1. Integers and digit-only strings resolve to the local primary key.
2. A canonical UUID string resolves against the entity's `uuid` field.
3. Arrays remain explicit Doctrine criteria and QueryBuilder handling remains unchanged.
4. An entity without a mapped `uuid` field returns `null` or raises a clear development
   error for UUID input; it MUST NOT silently retry the value as a primary key.
5. The implementation MUST NOT use an "id lookup then UUID fallback" heuristic because
   it is ambiguous and adds unnecessary queries.

Cross-module services and event consumers MUST use explicit UUID criteria or a
module-specific `getByUuid()` convenience method. Client-facing Core CRUD routes may
use the plain `get($identifier)` form because the route accepts exactly one unambiguous
numeric ID or UUID. Neither form is an authorization shortcut.

### 4.2 Dynamic Query Pipeline (`list()` method)

```
request params
  -> @dql         (DQL WHERE sub-clause)
  -> @filter      (Expression -> ExpressionDqlParser -> DQL WHERE)
      fallback: LegacyEvaluator (in-memory)
  -> @order       (DQL ORDER BY)
  -> @select      (DQL SELECT)
  -> @groupBy     (DQL GROUP BY)
  -> @hints       (Query hints)
  -> @sort        (in-memory sort)
  -> @showDQL     (debug: return compiled DQL)
```

### 4.3 `update()` Mutation Pipeline

```
Input data
  -> ManyToOne / OneToOne: resolve by ID
  -> ManyToMany / OneToMany: add/remove by ID (uses Inflect for setter naming)
  -> DateTime: deserialize from string
  -> Scalar fields: Symfony Serializer
  -> Symfony Validator
  -> persist + flush (unless $noFlush)
```

### 4.4 Transaction

```php
$this->wrapInTransaction(function ($em) {
    // All-or-nothing operations
});
```

---

## 5. View Mixin System

See the [Controller Design Contract](../controller-design.md) for the full trait catalog and hook contracts.

The 9 traits in `src/Core/View/` provide the controller composition toolkit:

| Trait | Route(s) | Purpose |
|-------|----------|---------|
| `ApiView` | (none) | Service binding, commonFilter() |
| `ListApiViewMixin` | `GET /` | Paginated collection |
| `DetailApiViewMixin` | `GET /{id}` | Single entity by numeric ID or UUID |
| `CreateApiViewMixin` | `POST /` | Single + batch create |
| `UpdateApiViewMixin` | `PUT /{id}`, `POST /batch-update` | Single update + batch upsert by numeric ID or UUID |
| `DeleteApiViewMixin` | `DELETE /{id}` | Entity removal by numeric ID or UUID |
| `ScopedListApiViewMixin` | `GET /` | Collection scoped by a required parent route parameter (`scopeId` as numeric ID or UUID) |
| `ScopedDetailApiViewMixin` | `GET /{id}` | Detail scoped by a required parent route parameter; `scopeId` and `id` each accept numeric ID or UUID |
| `SingleCreateAndUpdateApiViewMixin` | `PUT /` | Singleton upsert |
| `SingleDetailApiViewMixin` | `GET /` | Singleton detail |
| `WorkflowApiViewMixin` | `/todo`, `/transitions`, `/do/{*}`, `/status-reset` | State machine ops by numeric ID or UUID (via `mixIdToCommonFilter()`) |
| `TransformContent` | (used by others) | Expression-based field transformation |

---

## 6. Expression Dynamic Query Engine

### 6.1 Flow

```
User query string (@filter or @sort)
  -> ExpressionService (PSR-16 cache facade)
    -> ExpressionDqlParser::parse(string $expression)
      -> fragments: [joins, where, parameters]
    -> ExpressionQueryBuilderAssembler::assemble(fragments, QueryBuilder)
      -> complete QueryBuilder
```

### 6.2 ExpressionDqlParser

**File**: `src/Core/Parser/ExpressionDqlParser.php` (479 lines)

- Compiles string expressions into DQL fragments
- Supports binary operators (`==`, `!=`, `>`, `<`, `>=`, `<=`, `&&`, `||`, `+`, `-`, `*`, `/`, `matches`)
- Supports unary `!` operator
- Supports chained attributes (`entity.getCategory().getName()`)
- Validates fragments against Doctrine metadata (must match real entity fields/relations)
- Constants: `ROOT_ALIAS = 'filter_entity'`, `PARAM_PREFIX = 'filter_parameter_'`
- Caching: PSR-16 cache for compiled expressions

### 6.3 ExpressionQueryBuilderAssembler

**File**: `src/Core/Parser/ExpressionQueryBuilderAssembler.php` (172 lines)

- Assembles QueryBuilder from parser fragments
- Handles alias remapping for JOINs
- Avoids parameter name collisions

### 6.4 LegacyEvaluator

When DQL compilation fails (e.g., expression references non-DQL-compatible constructs), falls back to:
- Symfony ExpressionLanguage + in-memory filter/sort via `ArrayCommon::filter()` / `ArrayCommon::sort()`

---

## 7. Serializer Pipeline

### 7.1 Normalizer Stack

```
Symfony ObjectNormalizer
  -> FlatNormalizer (decorator)
    -> Strips Doctrine proxy internals
    -> Adds __toString to every entity
    -> Collapses related entities to {id, __toString, __metadata}
    -> Auto-parses JSON strings to arrays/objects
```

### 7.2 Circular Reference Handling

`CircularReferenceHandler.php` -- returns entity ID or `spl_object_hash()` if no `getId()`.

### 7.3 SerializerContextFactory

Builds context with `groups`, `max_depth`, `enable_max_depth`, `circular_reference_handler`.

### 7.4 ObjectCallback

Static callback for serialization groups -- returns ID or null.

---

## 8. Event Listeners

### 8.1 ExceptionInterceptor

- Subscribes to `kernel.exception`
- Intercepts unhandled exceptions on `/api/*` routes
- Logs the exception
- Returns JSON `{code, message, data: {class}}`
- Bypassed in `dev` mode

### 8.2 ControllerListener

- Subscribes to `kernel.controller`
- Logs PUT/POST requests with user ID, URI, and truncated body (1KB max)

---

## 9. Utility Classes

| Class | Purpose |
|-------|---------|
| `UUID` | v3 (MD5), v4 (random), v5 (SHA1), v4c (compact no dashes) |
| `Math` | Wraps PHP math functions for ExpressionLanguage |
| `ArrayCommon` | ExpressionLanguage-compatible array operations |
| `RsaClient` | RSA sign/verify/encrypt/decrypt |
| `FilterDateTime` | DateTime factory with timezone |
| `FixJSON` | Single-quote repair, `getJSONType()` (object/array) |
| `Inflect` | English pluralizer (for ManyToMany setter naming) |
| `StringCase` | `dashesToCamelCase()` |
| `Location` | Tencent Maps geocode/reverse/distance API |
| `ArrayCollection` | Doctrine ArrayCollection wrapper with JSON factory |

---

## 10. Environment Variables (Core-Related)

Core framework itself has no special env vars. Configuration is via `config/services.yaml`:

```yaml
services:
  App\:
    resource: '../src/'
    exclude:
      - '../src/Core/Serializer/Normalizer/FlatNormalizer.php'
      - '../src/*/EventListener/'
      - '../src/Identity/Controller/'
      - '../src/Identity/Security/'
```

---

## 11. Testing

Tests for Core components at `tests/Core/`:

| Component | Tests |
|-----------|-------|
| RestController | Response format, pagination, request processing |
| BaseService | Infrastructure trait, read list trait, mutation trait |
| Expression Parser | DQL parsing, assembler, expression service |
| Serializer | FlatNormalizer, CircularReferenceHandler, ObjectCallback |
| Event Listeners | ExceptionInterceptor, ControllerListener |
| Utilities | Math, UUID, StringCase, FixJSON, ArrayCommon, Inflect, FilterDateTime |
