# Module Design Contract

> Rules for creating new business modules. Every module under `src/` MUST follow this structure and contracts.
> This is the blueprint for extending the system with new domains.

---

## 1. Module Skeleton

When creating a new business module `{Module}`:

```
src/{Module}/
|-- Controller/
|   |-- App/{Entity}Controller.php         # Public read endpoints
|   |-- Manage/{Entity}Controller.php      # Admin CRUD endpoints
|-- Entity/{Entity}.php                    # Doctrine entity
|-- Repository/{Entity}Repository.php      # Data access
|-- Service/{Entity}Service.php            # Business logic
|-- Service/{Entity}ServiceInterface.php   # Service contract
|-- Exception/                             # Module-specific exceptions (optional)
|-- EventListener/                         # Event subscribers (optional)
|-- Command/                               # CLI commands (optional)
|-- Resources/config/                      # DI and routing (optional)
```

---

## 2. File-Level Contracts

### 2.1 Entity Contract

- **Location**: `src/{Module}/Entity/{Name}.php`
- **Namespace**: `App\{Module}\Entity`
- **Must**: Implement `__toString()`, declare `touch()` lifecycle hook, use PHP 8 attributes
- **Must**: Follow the [Data Model Design Contract](data-model.md)
- **Must NOT**: Contain business logic, DI, or service references

### 2.2 Repository Contract

- **Location**: `src/{Module}/Repository/{Name}Repository.php`
- **Namespace**: `App\{Module}\Repository`
- **Must**: Extend `ServiceEntityRepository`
- **Must**: Accept `ManagerRegistry` in constructor
- **May**: Add custom query methods returning entities/arrays/scalars
- **Must NOT**: Return raw `QueryBuilder` (that is the service layer's concern)
- **May**: Declare a `{Name}RepositoryInterface` if consumed by other modules

### 2.3 Service Interface Contract

- **Location**: `src/{Module}/Service/{Name}ServiceInterface.php`
- **Namespace**: `App\{Module}\Service`
- **Must**: Extend `App\Core\Service\BaseServiceInterface`
- **May**: Be empty (if base interface covers all needed methods)
- **May**: Add module-specific method signatures

```php
interface CategoryServiceInterface extends BaseServiceInterface
{
    // Module-specific methods go here (optional)
}
```

### 2.4 Service Implementation Contract

- **Location**: `src/{Module}/Service/{Name}Service.php`
- **Namespace**: `App\{Module}\Service`
- **Must**: Extend `App\Core\Service\BaseService`
- **Must**: Implement `{Name}ServiceInterface`
- **Must**: Accept `ContainerInterface`, entity class FQCN, optional locator/expression service
- **Should**: Contain all business logic, validation, and transaction management
- **Must NOT**: Access Request directly (use `getCurrentRequest()`)
- **Must NOT**: Return raw HTTP responses (that's the controller's job)

```php
class CategoryService extends BaseService implements CategoryServiceInterface
{
    public function __construct(
        ContainerInterface $container,
        ?ServiceLocatorInterface $locator = null,
        ?ExpressionServiceInterface $expressionService = null,
        ?LegacyEvaluator $legacyEvaluator = null
    ) {
        parent::__construct(
            $container,
            Category::class,
            $locator,
            $expressionService,
            $legacyEvaluator
        );
    }
}
```

### 2.5 Controller Contracts

Controllers follow two roles. See the [Controller Design Contract](controller-design.md) for full details.

#### App Controller (Client-Facing, Authenticated)

- **Location**: `src/{Module}/Controller/App/{Name}Controller.php`
- **Namespace**: `App\{Module}\Controller\App`
- **Must**: Extend `RestController`
- **Must**: Use `ApiView` and the appropriate mixins (`List`, `Detail` always; `Create`/`Update`/`Delete` when the client workflow requires writes)
- **Must**: Set `$serviceClass` property
- **Must**: Scope every query through `commonFilter()` / `scopedDetailFilter()` to the authenticated user (or store-membership) and enforce authorization (voter / field grants) — App writes are allowed only when ownership and permission are enforced
- **Should**: Override `commonFilter()` to scope data (e.g., current user, enabled scope)

#### Public Controller (Anonymous, Read-Only)

- **Location**: `src/{Module}/Controller/Public/{Name}Controller.php`
- **Namespace**: `App\{Module}\Controller\Public`
- **Must**: Extend `RestController`
- **Must**: Use `ApiView`, `DetailApiViewMixin`, `ListApiViewMixin` traits only
- **Must**: Set `$serviceClass` property
- **Must**: Expose only data that is safe for anonymous access (e.g., `user IS NULL` media) and never rely on `getUser()`
- **Must NOT**: Create, update, or delete entities

#### Manage Controller (Admin CRUD)

- **Location**: `src/{Module}/Controller/Manage/{Name}Controller.php`
- **Namespace**: `App\{Module}\Controller\Manage`
- **Must**: Extend `RestController`
- **Must**: Use `ApiView`, `ListApiViewMixin`, `DetailApiViewMixin`, `CreateApiViewMixin`, `UpdateApiViewMixin`, `DeleteApiViewMixin` traits
- **Must**: Set `$serviceClass` property
- **Must**: Guard with `#[IsGranted('ROLE_ADMIN')]` on the class
- **Should**: Declare `$requiredCreateProperties`, `$acceptedCreateProperties`, `$acceptedUpdateProperties`
- **May**: Override hook methods for custom logic

---

## 3. Module Registration Contract

### 3.1 Route Registration

Add the module's controllers to `config/routes.yaml`:

```yaml
api_{module}_app:
  resource:
    path: '../src/{Module}/Controller/App/'
    namespace: App\{Module}\Controller\App
  prefix: /api/v1
  type: attribute

api_{module}_manage:
  resource:
    path: '../src/{Module}/Controller/Manage/'
    namespace: App\{Module}\Controller\Manage
  prefix: /api/v1
  type: attribute
```

### 3.2 DI Registration

If the module needs custom service configuration, create:

```yaml
# src/{Module}/Resources/config/services_{module}.yaml
services:
  _defaults:
    autowire: true
    autoconfigure: true
  App\{Module}\:
    resource: '../../'
    exclude: '../../{Entity,Exception,Resources}'
```

Import in `config/services.yaml`:
```yaml
imports:
  - { resource: '../src/{Module}/Resources/config/services_{module}.yaml' }
```

Otherwise, autowiring covers everything via the global `App\` resource.

### 3.3 Migration

Create a single migration per module in `migrations/`:

```bash
php bin/console make:migration
```

Naming convention: `Version{YYYYMMDD}{HHMMSS}.php`

---

## 4. Module Dependency Rules

### 4.1 Allowed Dependencies

| Module | May Depend On |
|--------|--------------|
| Any Business Module | Core (always), other Business Modules (via service interfaces) |
| Identity | Core |
| Core | Nothing (foundational) |

### 4.2 Module Isolation Checklist

When creating a new module, verify:

- [ ] Module has no circular dependencies with other modules
- [ ] Module exports at least one `*ServiceInterface`
- [ ] Module does NOT import concrete services from other modules (use interfaces)
- [ ] Module's entities are not referenced directly by controllers in other modules
- [ ] Module's services are self-contained (do not leak DB connections or EMs)

---

## 5. Module Testing Contract

Every module MUST have tests at the layer that matches the change (see `docs/testing/crud-skeleton-production/TEST_STRATEGY.md`). Historical `tests/{Module}/` paths are deprecated — current layout is layer-first:

| Test Suite | Location | Coverage Target |
|------------|----------|----------------|
| Entity / domain unit tests | `tests/UnitTest/{Module}/` | `__toString()`, getter/setter, lifecycle |
| Service / pure logic tests | `tests/UnitTest/{Module}/` | All public service methods (mocked) |
| Integration / HTTP tests | `tests/Integration/{Module}/` or `tests/Integration/*` | API endpoints, Doctrine mapping, full request/response cycle |

### 5.1 Test Base Classes

| Test Type | Extends | Purpose |
|-----------|---------|---------|
| Unit test | `PHPUnit\Framework\TestCase` | Pure logic tests |
| Kernel test | `IntegrationKernelTestCase` | With booted kernel, DB access |
| Web test | `IntegrationWebTestCase` | Full HTTP request/response |

### 5.2 Test Database

Canonical contract is `docs/testing/crud-skeleton-production/TEST_STRATEGY.md`.

- Test environment uses SQLite (`var/test.db`) for local, PostgreSQL 16 in CI
- **Integration tests** use `DatabaseBootstrapTrait` + Doctrine `SchemaTool` (not migrations) for per-test schema + auto-rollback (fast isolation)
- **Migrations** are validated separately via `migrations.yml` on MySQL 8.4 and disposable-env `doctrine:migrations:migrate`
- Each test method is wrapped in a transaction and rolled back

---

## 6. Exception Contract

Module-specific exceptions MUST:

- **Extend**: `\RuntimeException` or `\Exception`
- **Be named**: Descriptively (`InsufficientFundsException`, `OrderInvalidTransitionException`)
- **Be thrown**: In the Service layer (never in Controller or Entity)
- **Be caught**: In the Controller layer (via mixin try/catch blocks)
- **Be logged**: Via `ExceptionInterceptor` for unhandled exceptions on `/api/*` routes

### 6.1 Exception Response Mapping

Unhandled exceptions caught by `ExceptionInterceptor` return:

```json
{
  "code": "{status_code}",
  "message": "{exception_message}",
  "data": {
    "class": "{exception_class}"
  }
}
```

---

## 7. Configuration Contract

Module-specific configuration (if needed beyond environment variables):

- **Location**: `config/packages/{module}.yaml` OR `src/{Module}/Resources/config/`
- **Environment overrides**: Via `%env(...)%` in YAML, NOT hardcoded
- **Sensitive values**: ALWAYS via environment variables, never in committed files

---

## 8. Module Checklist (New Module Creation)

When adding a new business domain, complete these steps in order:

1. Design entities (YAML or sketch first, then PHP classes)
2. Create Doctrine migration
3. Implement repositories
4. Implement service interface + service class
5. Implement App controllers (read-only)
6. Implement Manage controllers (CRUD)
7. Register routes in `config/routes.yaml`
8. Add OpenAPI `#[OA\*]` attributes to all endpoints
9. Write entity unit tests
10. Write service unit tests
11. Write API integration tests
12. Verify CI passes (90% coverage minimum)
