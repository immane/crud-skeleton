# System Contracts

> Cross-cutting rules that span the entire system: transactions, error handling, logging, security,
> validation, testing, and deployment. Every module, service, and controller MUST obey these contracts.

---

## 1. Transaction Contract

### 1.1 Transaction Boundary

Transactions are managed in the **Service layer**, never in controllers.

```php
// In any service:
$this->wrapInTransaction(function ($em) use ($data) {
    // Multiple persist/update operations
    // All-or-nothing semantics
});
```

### 1.2 Rules

| # | Rule |
|---|------|
| T1 | Multiple mutations on related entities MUST be wrapped in a transaction |
| T2 | Wallet transfers MUST use explicit `beginTransaction()`/`commit()`/`rollback()` with entity lock (`PESSIMISTIC_WRITE`) |
| T3 | After rollback, `EntityManager` MUST be recovered (`$em->isOpen()` check) |
| T4 | Controllers MUST NOT call `beginTransaction()`, `commit()`, `rollback()` directly |
| T5 | The mixin methods (`createAction`, `batchUpdateAction`) handle transaction wrapping automatically unless `@partial=true` |

### 1.3 Partial Mode Contract

When `@partial=true`:
- Each item is processed independently (no transaction)
- Failed items are silently skipped
- Successful items are returned

When `@partial=false` (default):
- All items in a single transaction
- Any failure rolls back ALL items

---

## 2. Error Handling Contract

### 2.1 Exception Hierarchy

```
\Exception
  |-- RuntimeException
  |     |-- InsufficientFundsException      (Wallet module)
  |     |-- WalletFrozenException           (Wallet module)
  |     |-- SameWalletTransferException     (Wallet module)
  |     |-- OrderInvalidTransitionException (Trade module)
  |     |-- SpecificationNotFoundException  (Trade module)
  |     |-- PaymentGatewayNotFoundException (Payment module)
  |     |-- PaymentVerificationException    (Payment module)
  |     |-- InvoiceInvalidTransitionException (Payment module)
  |     |-- InvoiceAmountMismatchException  (Payment module)
  |-- MessageErrorHttpException             (Core - user-facing)
  |-- MessageSuccessHttpException           (Core - success with custom message)
```

### 2.2 Where to Throw

| Layer | May Throw |
|-------|-----------|
| Entity | **NO** |
| Repository | Doctrine exceptions only |
| Service | Business exceptions (module-specific) |
| Controller | **NO** (catch and convert to `warning()`) |

### 2.3 Global Exception Handling

`ExceptionInterceptor` catches unhandled exceptions on `/api/*` routes:

```
Exception -> catch -> log -> JSON response {code, message, class}
```

In `dev` mode, bypassed (standard Symfony exception page).

### 2.4 Controller Error Handling Pattern

```php
try {
    // ... business logic ...
} catch (ValidatorException $e) {
    return $this->warning($e->getMessage(), 400, '', 400);
} catch (NotFoundHttpException $e) {
    return $this->warning($e->getMessage(), 404, '', 404);
} catch (\Exception $e) {
    return $this->warning($e->getMessage() ?: RestController::UNKNOWN_ERROR, 500, '', 500);
}
```

Controllers MUST catch domain exceptions and convert to `warning()` responses -- never let unhandled exceptions propagate.

---

## 3. Logging Contract

### 3.1 Log Channels

| Channel | Purpose |
|---------|---------|
| `app` | General application logs |
| `doctrine` | Doctrine ORM queries (dev only) |
| `security` | Authentication/authorization events |

### 3.2 What to Log

| Event | Level | Content |
|-------|-------|---------|
| Request body (PUT/POST) | INFO | User ID, URI, truncated body (1KB) |
| Unhandled exception | ERROR | Exception class, message, trace |
| Wallet transfer | INFO | From/To wallet, amount, reference ID |
| OTP send | INFO | Phone (masked), purpose |
| Token rotation anomaly | WARNING | Reuse detected, revoked all tokens |

### 3.3 What NOT to Log

- Passwords, tokens, OTP codes in plaintext
- Full request bodies with sensitive data (truncate at 1KB)
- Personal data in production (PII)

---

## 4. Validation Contract

### 4.1 Validation Layers

| Layer | Mechanism | What |
|-------|-----------|------|
| Controller (input) | `$requiredCreateProperties`, `$acceptedCreateProperties` | Field presence/whitelisting |
| Controller (input) | `processCreateContent()` hook | Custom business validation |
| Service | Symfony Validator (`$this->getValidator()->validate($entity)`) | Entity constraint validation |
| Entity | `#[Assert\*]` attributes | Declarative field constraints |

### 4.2 Validation Order

```
Controller field whitelist
  -> Controller hook validation (processCreateContent/processUpdateContent)
    -> @transform expression evaluation
      -> Service::update() -> Symfony Validator -> Entity constraints
```

### 4.3 Error Message Contract

- Validation errors: short, user-facing, localized (via Translator)
- Error code: HTTP status code
- Field-level errors: keyed by field name

---

## 5. Security Contract

### 5.1 Authentication

| Component | Responsibility |
|-----------|---------------|
| `JwtAuthenticator` | Extract JWT from `Authorization: Bearer`, validate, create Passport |
| `TokenManager` | Create/decode/revoke JWT access tokens, manage refresh token lifecycle |
| `User` entity | Implements `UserInterface`, `PasswordAuthenticatedUserInterface` |

### 5.2 Authorization

| Mechanism | Where |
|-----------|-------|
| `#[IsGranted('ROLE_ADMIN')]` | Manage controllers (class-level) |
| `commonFilter()` override | Row-level data scoping (e.g., user only sees own orders) |
| `security.yaml` access_control | Route-pattern-level firewall rules |

### 5.3 Token Security Rules

| # | Rule |
|---|------|
| S1 | Access tokens: RS256 signed, 7200s TTL |
| S2 | Refresh tokens: opaque, HMAC-SHA256 hashed in DB, 1 year TTL |
| S3 | Token rotation: refresh token replaced on each use |
| S4 | Reuse detection: if a revoked/replaced refresh token is used, revoke ALL user tokens |
| S5 | JWT blacklist: revoked access token JTIs stored in cache with TTL until natural expiration |
| S6 | Private keys MUST NOT be committed (in `.env.dev` or vault) |
| S7 | All API endpoints use HTTPS in production |

### 5.4 Rate Limiting Contract

| Resource | Limit | Storage |
|----------|-------|---------|
| OTP request (per phone) | 1 per 60s cooldown | Redis / cache |
| OTP verify (per phone) | 5 attempts max | Redis / cache |
| Login (per IP) | Implementation-defined | Application-level |

---

## 6. Serialization Contract

### 6.1 Normalizer Pipeline

```
ObjectNormalizer (Symfony)
  -> FlatNormalizer (decorator)
    -> DateTimeNormalizer (custom)
      -> CircularReferenceHandler (fallback)
```

### 6.2 FlatNormalizer Rules

| Rule | Behavior |
|------|----------|
| Doctrine proxies | Stripped (internals removed) |
| `__toString` | Added to every serialized entity |
| Related entities | Collapsed to `{id, __toString, __metadata}` |
| JSON strings in fields | Auto-parsed to arrays/objects |
| Traversable collections | Normalized as arrays |
| Max depth exceeded | Circular reference handler fallback |

### 6.3 Serializer Groups

Supported via `SerializerContextFactory`:
- `groups`: Array of serializer group names
- `max_depth`: Maximum serialization depth
- `enable_max_depth`: Whether to enforce depth limit

---

## 7. Testing Contract

### 7.1 Coverage Requirement

- **Minimum**: 90% line coverage (enforced in CI)
- **Test environment**: `APP_ENV=test`, SQLite database

### 7.2 Test Categories

| Category | Extends | Purpose |
|----------|---------|---------|
| Unit | `PHPUnit\Framework\TestCase` | Isolated logic (entities, utils, calculators) |
| Kernel | `IntegrationKernelTestCase` | With booted kernel, service access, DB |
| Web | `IntegrationWebTestCase` | Full HTTP request/response cycle |
| Regression | Varies | API contract stability |

### 7.3 Test Naming Convention

| Pattern | Example |
|---------|---------|
| `{Class}Test` | `CategoryTest.php`, `OrderServiceTest.php` |
| `{Module}ApiRegressionTest` | `CommonModulesApiRegressionTest.php` |
| `{Module}IntegrationTest` | `WalletApiIntegrationTest.php` |

### 7.4 Test Database Contract

Canonical contract is `docs/testing/crud-skeleton-production/TEST_STRATEGY.md` §Test Environments / §Test Layers.

- Each test method starts with a clean schema
- **Integration / kernel tests** (`tests/Integration/` + `Integration*TestCase` helpers) create the schema from the Doctrine `SchemaTool` (not migrations) in `DatabaseBootstrapTrait` — fast, per-test isolation with auto-rollback
- **Migration chain** is validated separately in CI on MySQL 8.4 (`migrations.yml`) and on demand via `doctrine:migrations:migrate` on a disposable env; `DatabaseBootstrapTrait` does not prove migration correctness
- Test data fixtures are inserted per-test or per-class
- Transactions are wrapped per test and rolled back (auto-rollback)

### 7.5 Static Analysis Contract

- **PHPStan**: Level 8 over the configured `src/` scope; run `composer phpstan`
- **Rector**: CI dry-runs `composer rector:types:check` for Doctrine Collection/Repository PHPDoc rules
- **Broader Rector**: `composer rector` is opt-in and must be reviewed before applying changes
- **Runtime**: Use PHP 8.4 or newer for Composer, Symfony, PHPUnit, PHPStan, and Rector commands

---

## 8. Code Style Contract

### 8.1 PHP

| Rule | Detail |
|------|--------|
| PHP version | >= 8.4 |
| Type declarations | Strict typing (`declare(strict_types=1)`) |
| Property types | Explicit (no docblock-only types) |
| Return types | Explicit wherever possible |
| Nullable | `?Type` syntax |
| Namespace | PSR-4 `App\` under `src/` |
| Use statements | Alphabetically ordered |

### 8.2 Naming

| Element | Convention | Example |
|---------|-----------|---------|
| Classes | PascalCase | `CategoryService` |
| Methods | camelCase | `getEnabledCategories()` |
| Properties | camelCase | `$serviceClass` |
| Constants | UPPER_SNAKE | `UNKNOWN_ERROR` |
| Interfaces | `*Interface` suffix | `BaseServiceInterface` |
| Abstract classes | `Abstract*` or `Base*` prefix | `BaseService` |
| Traits | `*Trait` suffix or descriptive | `ListApiViewMixin` |
| Tests | `*Test` suffix | `CategoryTest` |

### 8.3 File Organization

| Rule | Detail |
|------|--------|
| One class per file | No exceptions |
| File name = class name | `CategoryService.php` contains `class CategoryService` |
| Trait files | Same name as trait |
| Interface files | Same name as interface |

---

## 9. Event System Contract

### 9.1 Doctrine Lifecycle Events

| Event | Listener Pattern | Example |
|-------|-----------------|---------|
| `prePersist` | Entity lifecycle callback | `touch()` for timestamps |
| `postLoad` | Entity listener or subscriber | Derived field calculation |
| `onFlush` | Event subscriber | Audit logging |

### 9.2 Symfony Kernel Events

| Event | Listener | Purpose |
|-------|----------|---------|
| `kernel.exception` | `ExceptionInterceptor` | Global API error-to-JSON conversion |
| `kernel.controller` | `ControllerListener` | Request logging for PUT/POST |

### 9.3 Workflow Events

| Event | Subscriber | Purpose |
|-------|-----------|---------|
| `workflow.{name}.transition` | `OrderWorkflowListener` | Post-transition actions (set timestamps) |

### 9.4 Custom Events (Optional)

Custom events dispatched via Symfony EventDispatcher for decoupled cross-module communication:

- Event classes in `src/{Module}/Event/`
- Listeners/subscribers in `src/{Module}/EventListener/`

---

## 10. CLI Command Contract

### 10.1 Command Structure

```php
#[AsCommand(name: 'app:module:action', description: '...')]
class XxxCommand extends Command
{
    // Inject services via constructor
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        return Command::SUCCESS;
    }
}
```

### 10.2 Naming Convention

`app:{module}:{entity}:{action}`

| Command | Module | Purpose |
|---------|--------|---------|
| `app:identity:user:create` | Identity | Create user from CLI |
| `fission:expire` | Lottery | Expire overdue tasks |

### 10.3 Command Requirements

- MUST be registered via `#[AsCommand]` attribute
- MUST handle errors gracefully (no uncaught exceptions)
- MUST output progress for long-running operations
- MUST return `Command::SUCCESS` or `Command::FAILURE`

---

## 11. Deployment & CI Contract

### 11.1 CI Pipeline

**File**: `.github/workflows/ci.yml`

| Step | Action |
|------|--------|
| 1 | Set up PHP 8.4 |
| 2 | Install dependencies (`composer install`) |
| 3 | Run PHPStan Level 8 with an isolated SQLite URL |
| 4 | Run Rector type-rule dry-run with an isolated SQLite URL |
| 5 | Start PostgreSQL service and prepare the test database |
| 6 | Run PHPUnit with coverage and enforce 90% line coverage minimum |

### 11.2 Docker

- `compose.yaml`: MySQL 8
- `compose.override.yaml`: Port mapping + Mailpit
- All services health-checked before app starts

### 11.3 Environment

| Env | `APP_ENV` | `APP_DEBUG` | Database |
|-----|-----------|-------------|----------|
| Production | `prod` | `false` | MySQL |
| Staging | `staging` | `false` | MySQL |
| Development | `dev` | `true` | MySQL (Docker) |
| Testing | `test` | `true` | SQLite |

---

## 12. Breaking Change Policy

| Change | Allowed | Requires |
|--------|---------|----------|
| Add new mixin trait | Yes | Documentation |
| Add new hook method | Yes (with default impl) | Documentation |
| Change hook method signature | **NO** | Major version bump |
| Remove mixin method | **NO** | Major version bump |
| Change response envelope format | **NO** | Major version bump |
| Add query parameter | Yes | Backward compatible |
| Remove supported query parameter | **NO** | Deprecation notice + major version bump |
| Change `BaseServiceInterface` | **NO** | Cross-module impact assessment |
| Add new module | Yes | Follow module contract |

---

## 13. Documentation Contract

### 13.1 Required Documentation per Module

| Document | Location | Contents |
|----------|----------|----------|
| Bundle design doc | `docs/design/bundles/{module}.md` | Business flow, API design, data model, store/catalog or domain rules |
| Operational guide | `docs/manual/{module}.md` + `docs/runbooks/{module}.md` (when applicable) | Seeding, deployment, and recovery procedures |
| OpenAPI spec | Code attributes `#[OA\*]` (`/api/doc` + `/api/doc.json`) + `docs/openapi/endpoints.yaml` + `docs/openapi/order-payment-flow.md` | Machine-readable spec + human consumer flow |
| Testing evidence | `docs/testing/crud-skeleton-production/TEST_MATRIX.md` / `BUSINESS_INVARIANTS.md` for critical modules | Required validation per change type |

### 13.2 Code Documentation Rules

- PHPDoc on interfaces and abstract methods (contract documentation)
- No comments on self-documenting code (well-named methods/variables)
- `@deprecated` annotations for deprecated features with migration notes
