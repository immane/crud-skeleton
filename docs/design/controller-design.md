# Controller Design Contract

> The controller layer follows a **composition-over-inheritance** pattern using PHP traits as "mixin" contracts.
> This is the exact same contract used by **farm-neighbor** (`src/LamProject/CoreBundle/View/`).
> Controllers compose behaviors (list, detail, create, update, delete, workflow) by `use`-ing traits rather than through deep class hierarchies.

---

## 1. Core Principle

```
Controller = RestController (base)
           + ApiView (service binding + common filter)
           + [ListApiViewMixin | DetailApiViewMixin | CreateApiViewMixin | ...]
           + Business-specific hook overrides
```

Every controller is built by **assembling traits**, not by extending a monolithic CRUD base class.

---

## 2. Trait Catalog (The Controller Contract Toolkit)

### 2.1 Mandatory: `ApiView`

**File**: `src/Core/View/ApiView.php`
**Must be used by**: Every controller

Declares the contract for service binding and data scoping:

```php
trait ApiView
{
    protected ?string $serviceClass = null;  // REQUIRED: set in controller

    protected function commonFilter()         // OVERRIDE: scope data
    {
        return [];  // return array criteria OR QueryBuilder
    }
}
```

**Contract**:
- Controller MUST set `$serviceClass` to the FQCN of the corresponding service
- Controller SHOULD override `commonFilter()` to apply ownership/scoping filters
- `commonFilter()` returns either: (a) associative array of criteria, or (b) a `QueryBuilder` instance

### 2.2 List: `ListApiViewMixin`

**File**: `src/Core/View/ListApiViewMixin.php`

Registers `GET /` and provides paginated list endpoint:

| Route | Method | Action |
|-------|--------|--------|
| `GET /` | listAction | Paginated collection with dynamic queries |

**Hook Methods** (overrideable):

| Hook | Signature | When Called | Purpose |
|------|-----------|-------------|---------|
| `listFilter($filter)` | `protected` | Before service.list() | Modify the filter criteria |
| `listProcessor($entities)` | `protected` | After service.list() | Transform the result set |
| `listResponses($entities)` | `protected` | Before JSON serialization | Final response shaping |

**OpenAPI Parameters** (documented):
`page`, `limit`, `@order`, `@dql`, `@select`, `@groupBy`, `@hints`, `@filter`, `@sort`, `@expands`, `@display`, `@showDQL`

### 2.3 Detail: `DetailApiViewMixin`

**File**: `src/Core/View/DetailApiViewMixin.php`

Registers `GET /{id}`. The Core identifier lookup accepts a digit-only local ID or a
canonical UUID when the entity has a mapped `uuid` field:

| Route | Method | Action |
|-------|--------|--------|
| `GET /{id}` | detailAction | Single entity by numeric ID or UUID |

**Hook Methods**:

| Hook | Signature | When Called | Purpose |
|------|-----------|-------------|---------|
| `detailFilter($filter)` | `protected` | After mixIdToCommonFilter, before get | Modify the lookup criteria |
| `detailProcessor($entity)` | `protected` | After service.get() | Transform the entity |
| `detailResponse($entity)` | `protected` | Before JSON serialization | Shaping the response |

### 2.4 Create: `CreateApiViewMixin`

**File**: `src/Core/View/CreateApiViewMixin.php`

Registers `POST /` with support for both single object and array inputs:

| Route | Method | Action |
|-------|--------|--------|
| `POST /` | createAction | Create single or batch entities |

**Query Parameters**: `@partial` (non-transactional), `@transform` (expression transformer)

**Properties** the controller MAY declare:

| Property | Type | Purpose |
|----------|------|---------|
| `$requiredCreateProperties` | `string[]` | Fields that MUST be present in input |
| `$acceptedCreateProperties` | `string[]` | Fields that MAY be present in input |

**Hook Methods**:

| Hook | Signature | When Called | Purpose |
|------|-----------|-------------|---------|
| `defaultCreateValues()` | `protected` | Before processing | Supply default values |
| `processCreateContent(array $content, $entity)` | `protected` | After transform, before save | Modify/validate content |
| `processEntity($content, $entity)` | `protected` | After new(), before update | Modify the new entity |
| `afterCreated($entity)` | `protected` | After successful save | Post-create hook |

**Input Modes**:
- Single object `{}`: Returns the created entity directly
- Array `[{}, {}, ...]`: Returns array of created entities, wrapped in transaction (unless `@partial=true`)

### 2.5 Update: `UpdateApiViewMixin`

**File**: `src/Core/View/UpdateApiViewMixin.php`

Registers two routes:

| Route | Method | Action |
|-------|--------|--------|
| `PUT /{id}` | updateAction | Single entity update by numeric ID or UUID |
| `POST /batch-update` | batchUpdateAction | Batch upsert (create or update) |

**Query Parameters**: `@mode=mixed|strict`, `@basis=field1,field2`, `@partial`, `@transform`

**Properties** the controller MAY declare:

| Property | Type | Purpose |
|----------|------|---------|
| `$requiredUpdateProperties` | `string[]` | Fields that MUST be present in update input |
| `$acceptedUpdateProperties` | `string[]` | Fields that MAY be present in update input |

**Hook Methods** (update path):

| Hook | Signature | When Called | Purpose |
|------|-----------|-------------|---------|
| `defaultUpdateValues()` | `protected` | Before processing | Supply default values for update |
| `processUpdateContent(array $content, $entity)` | `protected` | After transform, before save | Modify/validate update content |
| `afterUpdated($entity)` | `protected` | After successful save | Post-update hook |

**Compatibility Hooks** (delegates to update hooks by default -- override only if needed):

| Hook | Default Delegates To |
|------|---------------------|
| `defaultValues()` | Used by both create and update if no specific override |
| `processContent($content, $entity)` | Used by both create and update if no specific override |
| `after($entity)` | Used by both create and update if no specific override |

**Batch Upsert Logic** (`POST /batch-update` with `@mode=mixed`):
1. For each item in array, attempt to find existing entity by `@basis` fields
2. If found: `update()` (MODE_UPDATE)
3. If not found: `new()` + `update()` (MODE_CREATE)
4. With `@mode=strict`: skip items that don't exist (update-only)

### 2.6 Delete: `DeleteApiViewMixin`

**File**: `src/Core/View/DeleteApiViewMixin.php`

Registers `DELETE /{id}`:

| Route | Method | Action |
|-------|--------|--------|
| `DELETE /{id}` | deleteAction | Remove entity by numeric ID or UUID |

**Hook Methods**:

| Hook | Signature | When Called | Purpose |
|------|-----------|-------------|---------|
| `deletionFilter($filter)` | `protected` | Before lookup | Add scoping to prevent unauthorized deletes |

### 2.7 Singleton: `SingleCreateAndUpdateApiViewMixin`

**File**: `src/Core/View/SingleCreateAndUpdateApiViewMixin.php`

For resources that have exactly **one row** per scope (e.g., user settings):

| Route | Method | Action |
|-------|--------|--------|
| `PUT /` | updateAction | Get-or-create singleton, then update |

**Hook Methods**: `defaultCreateValues()`, `defaultUpdateValues()`

### 2.8 Singleton Detail: `SingleDetailApiViewMixin`

**File**: `src/Core/View/SingleDetailApiViewMixin.php`

| Route | Method | Action |
|-------|--------|--------|
| `GET /` | detailAction | Fetch the single resource (no ID) |

Uses `commonFilter()` to identify which row.

### 2.9 Workflow: `WorkflowApiViewMixin`

**File**: `src/Core/View/WorkflowApiViewMixin.php`

For entities governed by Symfony Workflow state machines:

| Route | Method | Action |
|-------|--------|--------|
| `GET /todo` | todoAction | List entities with available transitions |
| `GET /{id}/transitions` | availableTransitionsAction | Get enabled transitions for an entity by numeric ID or UUID |
| `POST /{id}/do/{transition}` | doTransitionAction | Execute a workflow transition by numeric ID or UUID |
| `PUT /{id}/status-reset` | resetMarkingAction | Reset state machine marking by numeric ID or UUID (admin only) |

All three identifier-aware actions resolve `id` through `ApiView::mixIdToCommonFilter()`
(digit-only → `id`, canonical UUID → `uuid`), merged with `commonFilter()` and
`authorizeApiAction('workflow', $entity)`. Missing entities return `404`; authorization
failures return `403`. The `{id}` route parameter accepts `\d+|[0-9a-fA-F-]{36}`.

**Properties** the controller MUST declare:
- `protected $workflow;` -- the workflow service ID (e.g., `'state_machine.order'`)

### 2.10 Scoped: `ScopedListApiViewMixin` and `ScopedDetailApiViewMixin`

**Files**: `src/Core/View/ScopedListApiViewMixin.php`, `src/Core/View/ScopedDetailApiViewMixin.php`

For nested resources such as `/store/{scopeId}/orders/{id}`:

| Trait | Route | Behavior |
|-------|-------|----------|
| `ScopedListApiViewMixin` | `GET /` (parent `/{scopeId}` on the controller) | Calls abstract `scopedListFilter($scopeId)` and lists with `service->list()` |
| `ScopedDetailApiViewMixin` | `GET /{id}` with `requirements: ['id' => '\\d+|[0-9a-fA-F-]{36}']` | Calls abstract `scopedDetailFilter($scopeId, $id)`; `scopeId` and `id` each accept numeric ID or UUID |

Both `scopeId` and `id` follow the same rule as the core mixins: digit-only → `id`, canonical UUID → `uuid`. Controllers MUST resolve them explicitly via `ApiView::identifierField()` / `identifierCriteria()` or `mixIdToCommonFilter()` and MUST NOT rely on an `id`-then-`uuid` fallback. For Store-scoped controllers, the parent Store is resolved through `StoreScopedAuthorizationApiMixin::storeForAuthorization()`, which now uses `identifierCriteria($scopeId)` so `/store/1/orders/2` and `/store/{storeUuid}/orders/{uuid}` are both valid. Example:

```php
protected function scopedDetailFilter(string $scopeId, string $id): array
{
    return [$this->identifierField($id) => $id, ...$this->storeScopedFilter($this->storeForAuthorization())];
}
```

The class-level route SHOULD declare `requirements: ['scopeId' => '\\d+|[0-9a-fA-F-]{36}']` when the parent resource exposes both forms.

---

## 3. Controller Assembly Patterns

### 3.1 Standard Admin CRUD Controller

```php
#[IsGranted('ROLE_ADMIN')]
class CategoryController extends RestController
{
    use ApiView;
    use ListApiViewMixin;
    use DetailApiViewMixin;
    use CreateApiViewMixin;
    use UpdateApiViewMixin;
    use DeleteApiViewMixin;

    protected ?string $serviceClass = CategoryService::class;

    protected array $requiredCreateProperties = ['name'];
    protected array $acceptedCreateProperties = ['name', 'slug', 'description', 'parent', 'enabled'];
    protected array $acceptedUpdateProperties = ['name', 'slug', 'description', 'parent', 'enabled', 'sortOrder'];
}
```

### 3.2 Public Read-Only Controller

```php
class CategoryController extends RestController
{
    use ApiView;
    use ListApiViewMixin;
    use DetailApiViewMixin;

    protected ?string $serviceClass = CategoryService::class;

    protected function commonFilter()
    {
        return ['enabled' => true];  // Only show enabled categories
    }
}
```

### 3.3 Custom Business Logic Controller (Beyond CRUD)

When a controller needs endpoints not covered by mixins, add custom action methods:

```php
class OrderController extends RestController
{
    use ApiView;
    use ListApiViewMixin;
    use DetailApiViewMixin;

    protected ?string $serviceClass = OrderService::class;

    #[Route('/my-orders', name: 'my-orders', methods: ['GET'])]
    public function myOrdersAction(): Response
    {
        $service = $this->get($this->serviceClass);
        $filter = $this->commonFilter();  // scopes to current user
        $entities = $service->list($filter);
        return $this->success($entities);
    }
}
```

### 3.4 Workflow-Enabled Controller

```php
#[IsGranted('ROLE_ADMIN')]
class OrderController extends RestController
{
    use ApiView;
    use ListApiViewMixin;
    use DetailApiViewMixin;
    use UpdateApiViewMixin;           // For updating draft orders
    use WorkflowApiViewMixin;         // For state transitions

    protected ?string $serviceClass = OrderService::class;
    protected $workflow = 'state_machine.order';

    protected array $acceptedUpdateProperties = ['notes', 'metadata'];
}
```

---

## 4. Hook Execution Order

### 4.1 GET List Pipeline

```
commonFilter()
  -> listFilter()
    -> service.list(filter)
      -> listProcessor(entities)
        -> listResponses(entities)
          -> success(response)
```

### 4.2 GET Detail Pipeline

```
commonFilter()
  -> mixIdToCommonFilter(id)
    -> detailFilter(filter)
      -> service.get(filter)
        -> detailProcessor(entity)
          -> detailResponse(entity)
            -> success(response)
```

### 4.3 POST Create Pipeline

```
json_decode(content)
  -> check input type (object vs array)
    -> service.new()
      -> requiredCreateProperties check
        -> acceptedCreateProperties filter
          -> defaultCreateValues()
            -> @transform (expression)
              -> processCreateContent(content, entity)
                -> processEntity(content, entity)
                  -> service.update(entity, content)
                    -> afterCreated(entity)
                      -> success(response)
```

### 4.4 PUT Update Pipeline

```
json_decode(content)
  -> mixIdToCommonFilter(id)
    -> service.get(filter)
      -> requiredUpdateProperties check
        -> acceptedUpdateProperties filter
          -> defaultUpdateValues()
            -> @transform (expression)
              -> processUpdateContent(content, entity)
                -> service.update(entity, content)
                  -> afterUpdated(entity)
                    -> success(response)
```

### 4.5 DELETE Pipeline

```
mixIdToCommonFilter(id)
  -> deletionFilter(filter)
    -> service.get(filter)
      -> service.remove(entity)
        -> success(...) | warning(...)
```

---

## 5. Contract Rules

### 5.1 MUST Rules

| # | Rule |
|---|------|
| R1 | Controller MUST extend `RestController` |
| R2 | Controller MUST `use ApiView` trait |
| R3 | Controller MUST set `protected ?string $serviceClass` |
| R4 | Public (anonymous) controllers (`Controller/Public/`) MUST NOT use Create/Update/Delete mixins; App controllers (`Controller/App/`) are authenticated client-facing APIs and MAY use those mixins when writes are ownership-scoped and authorization-checked |
| R5 | Manage (admin) controllers MUST be guarded by `#[IsGranted('ROLE_ADMIN')]` |
| R6 | Admin CRUD controllers MUST declare `$requiredCreateProperties` and `$acceptedCreateProperties` |
| R7 | Every public action MUST have `#[OA\*]` OpenAPI attributes |
| R8 | Controllers MUST NOT call EntityManager directly |
| R9 | Controllers MUST NOT call Repository directly |
| R10 | All business logic MUST go through the service layer |

### 5.2 SHOULD Rules

| # | Rule |
|---|------|
| S1 | App controllers SHOULD override `commonFilter()` to scope data |
| S2 | Custom validations SHOULD go in `processCreateContent()` / `processUpdateContent()` hooks |
| S3 | Post-save side effects SHOULD go in `afterCreated()` / `afterUpdated()` hooks |
| S4 | Deletion should verify ownership via `deletionFilter()` or `commonFilter()` |

### 5.3 MUST NOT Rules

| # | Rule |
|---|------|
| N1 | MUST NOT inject `EntityManager` into a controller |
| N2 | MUST NOT put business logic in hook methods (delegate to services) |
| N3 | MUST NOT bypass the `serviceClass` binding for standard CRUD operations |
| N4 | MUST NOT use `$_GET`, `$_POST`, `$_REQUEST` superglobals (use `Request` object) |
| N5 | MUST NOT modify mixin trait files for module-specific needs (override hooks instead) |

---

## 6. Comparison with farm-neighbor

The contract is **identical in structure** to farm-neighbor's:

| Aspect | farm-neighbor | crud-skeleton |
|--------|--------------|---------------|
| Base class | `LamProject\CoreBundle\Controller\RestController` | `App\Core\Controller\RestController` |
| Mixin location | `LamProject\CoreBundle\View\` | `App\Core\View\` |
| Trait names | Identical (ListApiViewMixin, CreateApiViewMixin, etc.) | Same names |
| Hook method signatures | Same | Same |
| Service binding | `$serviceClass` | `$serviceClass` |
| Field whitelisting | `$requiredCreateProperties`, etc. | Same |
| `@` query syntax | Same expression engine | Same |
| Mixed batch upsert | `@mode=mixed` + `@basis` | Same |

Differences are minor (namespace, Symfony version 3.4→8.1, annotations→attributes) but the **contract semantics are identical**.
