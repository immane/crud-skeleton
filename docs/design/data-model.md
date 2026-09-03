# Data Model Design Contract

> Abstract entity conventions, attribute requirements, relationship patterns, lifecycle hooks, and field rules.
> **Every Doctrine entity MUST conform to this contract.**

---

## 1. Entity Base Contract

Every **CRUD aggregate** entity MUST have (defaults for domain aggregates such as `Category`, `Order`, `Wallet`, `Store`, etc.):

| Element | Requirement |
|---------|-------------|
| Namespace | `App\{Module}\Entity` |
| PHP 8 attributes | `#[ORM\Entity]`, `#[ORM\Table]`, `#[ORM\HasLifecycleCallbacks]` |
| Primary key | `$id` (int, auto-increment) |
| Created timestamp | `$createdAt` (DateTimeImmutable) |
| Updated timestamp | `$updatedAt` (DateTimeImmutable) |
| `__toString()` method | Returns human-readable identifier |

> **Exception categories** (not CRUD aggregates): Inbox/Outbox event records
> (`eventId` identity, claim fields, no `updatedAt`/`__toString`), append-only
> audit / ledger / projection records, join / pivot tables, DTO / value objects,
> and infrastructure records. Those records may omit `createdAt/updatedAt`,
> `__toString()`, or a dedicated repository per projection and are documented
> in the owning bundle doc. The table above is the default, not a universal
> requirement.

```php
#[ORM\Entity(repositoryClass: XxxRepository::class)]
#[ORM\Table(name: 'module_table_name')]
#[ORM\HasLifecycleCallbacks]
class Xxx
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column]
    private ?\DateTimeImmutable $createdAt = null;

    #[ORM\Column]
    private ?\DateTimeImmutable $updatedAt = null;

    public function __toString(): string { return (string)$this->id; }

    #[ORM\PrePersist]
    public function touch(): void
    {
        $this->createdAt = new \DateTimeImmutable();
        $this->updatedAt = new \DateTimeImmutable();
    }
}
```

---

## 2. Naming Conventions

### 2.1 Table Names

| Rule | Example |
|------|---------|
| Lowercase, underscore-separated | `common_category` |
| Module prefix | `common_*`, `trade_*`, `payment_*`, `wallet_*`, `identity_*` |
| Singular noun | `wallet` not `wallets` |
| Join tables | `{left}_{right}` (e.g., `common_content_tag`) |

### 2.2 Column Names

| Rule | Example |
|------|---------|
| camelCase in PHP | `sortOrder`, `createdAt` |
| snake_case in DB | `sort_order`, `created_at` |
| Foreign key columns | `{entity}_id` (e.g., `category_id`) |
| Boolean columns | Verb or adjective (e.g., `is_deleted`, `enabled`) |
| Status columns | Noun (e.g., `status`) |
| Timestamp columns | `*_at` suffix (e.g., `expires_at`, `completed_at`) |

### 2.3 Property Naming in PHP

| Rule | Example |
|------|---------|
| camelCase | `$createdAt`, `$sortOrder` |
| Boolean getters | `isEnabled()`, `isDeleted()` |
| Collection properties | Plural noun (e.g., `$tags`, `$items`) |
| Relation properties | Singular for M:1/O:O (`$category`), plural for 1:M/M:M (`$items`) |

---

## 3. Field Type Mapping

### 3.1 Scalar Types

| PHP Type | Doctrine Type | DB Type | Use Case |
|----------|--------------|---------|----------|
| `?int` | `integer` | INT | IDs, counters, sort order |
| `int` | `bigint` | BIGINT | Monetary amounts (cents) |
| `?string` | `string` | VARCHAR(n) | Names, titles, emails |
| `string` | `text` | TEXT | Body content, descriptions |
| `bool` | `boolean` | TINYINT(1) | Flags, status toggles |
| `?float` | `decimal` | DECIMAL(p,s) | If decimal money needed |
| `array` | `json` | JSON | Metadata, config, snapshots |

### 3.2 DateTime Types

| PHP Type | Doctrine Type | DB Type | Use Case |
|----------|--------------|---------|----------|
| `?\DateTimeImmutable` | `datetime_immutable` | DATETIME | All timestamps |

**Rule**: Always use `DateTimeImmutable`, never `DateTime`.

### 3.3 Monolithic Amounts (Cents Contract)

| Rule | Detail |
|------|--------|
| Storage type | `bigint` (integer cents) |
| PHP property type | `int` |
| External API | Decimal (e.g., `20.00`) |
| Conversion on write | `* 100` |
| Conversion on read | `/ 100` |
| Conversion mechanism | `@transform` expression or service method |

---

## 4. Relationship Contract

### 4.1 Attribute Requirements

Every relationship MUST declare:
- `targetEntity` (FQCN)
- `inversedBy` / `mappedBy` (bidirectional)
- `cascade` strategy (explicit)
- `fetch` strategy (explicit, default LAZY acceptable)

### 4.2 Relationship Cardinality

| Type | PHP Attribute | Owning Side | DB Column |
|------|--------------|-------------|-----------|
| ManyToOne | `#[ORM\ManyToOne]` | Current entity (FK here) | FK column |
| OneToMany | `#[ORM\OneToMany]` | Opposite side | No column (mappedBy) |
| ManyToMany | `#[ORM\ManyToMany]` | Either side | Join table |
| OneToOne | `#[ORM\OneToOne]` | Current entity (FK here) | FK + UNIQUE |

### 4.3 Self-Referencing Relationships

| Pattern | Example | Attributes |
|---------|---------|------------|
| Tree/Parent | Category -> parent/children | `ManyToOne(targetEntity: self::class)`, `OneToMany(mappedBy: 'parent')` |
| Adjacency | Comment -> parent/replies | `ManyToOne(targetEntity: self::class)`, `OneToMany(mappedBy: 'parent')` |

---

## 5. Index & Constraint Contract

### 5.1 Mandatory

| Element | Requirement |
|---------|-------------|
| Primary key | Single auto-increment integer `id` |
| Foreign keys | All FK columns MUST have explicit index |
| Unique constraints | On natural keys (slug, email, phone, username, referenceId) |

### 5.2 Optional (Performance)

| Pattern | When to Add |
|---------|-------------|
| Composite index | Frequent queries filtering on `(entity_id, status)` |
| Fulltext index | Content search on `body`/`title` |
| Index on status | Frequent `WHERE status = ?` queries |

---

## 6. Lifecycle Contract

### 6.1 Required Lifecycle Callbacks

| Callback | Method | Purpose |
|----------|--------|---------|
| `#[ORM\PrePersist]` | `touch()` | Set `createdAt` and `updatedAt` |

### 6.2 Optional Lifecycle Callbacks

| Callback | Use Case |
|----------|----------|
| `#[ORM\PreUpdate]` | Update `updatedAt` on modification |
| `#[ORM\PrePersist]` | Calculate derived fields (e.g., `price = unitPrice * quantity`) |
| `#[ORM\PrePersist]` | Save entity snapshots (e.g., `productSnapshot`) |

---

## 7. Special Patterns

### 7.1 Optional UUID for External And Cross-Boundary Identity

Entities retain an integer auto-increment `id` for local Doctrine relations, joins, and
storage efficiency. A UUID is a separate, stable identity for an entity that leaves its
bounded context.

An entity SHOULD have a UUID when it is any of the following:

- Addressable through a public API, URL, webhook, or third-party integration.
- Included in a cross-module or cross-service event payload.
- A domain aggregate that may later move to its own database/service.
- Referenced by an idempotency, audit, payment, inventory, or external correlation flow.

Purely internal implementation records do not need a separate entity UUID. Examples
include a private join table or an Inbox record already uniquely identified by its
incoming `eventId`. An independently managed relation exposed through an API should
still receive a UUID.

```text
Local database key / Doctrine relation: integer id
Public API path and response identity: integer id, or UUID when the entity exposes one
Cross-module/service reference: uuid or immutable business key
Event aggregateId, sourceId, correlationId: uuid
```

Core CRUD routes use the `{id}` path parameter for both forms. Digit-only values are
local IDs; canonical UUID values are resolved against the unique `uuid` field. The two
forms are unambiguous and must not be sent as separate request values. A resource that
has no UUID field accepts only its integer ID.

New cross-boundary entities use a canonical UUID string alongside the mandatory integer
`id`:

```php
#[ORM\Column(type: 'string', length: 36, unique: true)]
private string $uuid;

public function __construct() {
    $this->uuid = UUID::v4();
}
```

UUIDs are not an authorization mechanism. Every lookup still requires row-level
authorization and ownership checks.

### 7.1.1 UUID Version Policy

- Existing entities using UUID v4 retain their current identifiers; migrations MUST NOT
  rewrite identifiers merely for version uniformity.
- New cross-service aggregates SHOULD use UUID v7 once the shared UUID utility supports
  it, because time ordering improves index locality.
- Until UUID v7 is available, UUID v4 is acceptable for new entities and events.
- UUID format and version are implementation details behind the external UUID string
  contract. Consumers must not infer business meaning from UUID bytes.

### 7.2 Soft Delete

```php
#[ORM\Column]
private bool $isDeleted = false;
```

**Use when**: Entities should never be hard-deleted (audit trail, legal requirements).

**Consequence**: All queries MUST filter `isDeleted = false` (via `commonFilter()` in controller).

### 7.3 Value Snapshot

When an entity captures a point-in-time copy of another entity's state:

```php
#[ORM\Column(type: 'json', nullable: true)]
private ?array $snapshot = null;
```

**Populated at creation** (in `#[ORM\PrePersist]` or service method), never updated.

### 7.4 Wallet Concurrency — Pessimistic Lock + Manual Version

Wallet balances use `SELECT ... FOR UPDATE` (pessimistic locking) with a manually
incremented `version` column (`SET version = version + 1`). There is **no**
`#[ORM\Version]` mapping. The `version` field is an ordinary `int` column
used only for audit ordering, not for Doctrine optimistic-lock checks.

See `docs/design/bundles/wallet.md` §4 for the locked transfer / deposit / withdrawal
flows. For other aggregates that handle money or inventory, use the same service-layer
pessimistic-lock + explicit bump pattern and do not introduce `#[ORM\Version]` without
a documented decision.

### 7.5 Polymorphic Association

For comments/reactions referencing different entity types:

```php
#[ORM\Column(length: 100)]
private string $entityType;  // e.g., 'App\Common\Entity\Content'

#[ORM\Column]
private int $entityId;
```

**No FK constraint** (resolved in application layer).

### 7.6 JSON Metadata / Extra Data

```php
#[ORM\Column(type: 'json', nullable: true)]
private ?array $metadata = null;

#[ORM\Column(type: 'json', nullable: true)]
private ?array $extraData = null;
```

**Use when**: Extensible fields that don't warrant dedicated columns.

---

## 8. Repository Contract

Every entity MUST have a dedicated repository:

```php
namespace App\{Module}\Repository;

use App\{Module}\Entity\Xxx;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

class XxxRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Xxx::class);
    }
}
```

### 8.1 Custom Query Methods

- Repository MAY add custom query methods beyond the base `ServiceEntityRepository`
- Custom methods return entities, arrays, or scalar values -- NEVER QueryBuilder (that's the service layer's responsibility to compose)
- Interface MAY be created for repository if cross-module consumption is needed (e.g., `ContentRepositoryInterface`)

### 8.2 PHPDoc for IDE Support

- Add `@method Xxx|null find($id)` and `@method Xxx[] findAll()` docblocks

---

## 9. Validation Contract

Entities define validation rules via attributes or annotations:

```php
use Symfony\Component\Validator\Constraints as Assert;

#[Assert\NotBlank]
private ?string $name = null;

#[Assert\Email]
private ?string $email = null;
```

Validation is invoked in `BaseService::update()` via Symfony Validator. Entities MUST declare constraints for:
- Required fields (`NotBlank`)
- Format constraints (`Email`, `Url`, `Regex`)
- Length constraints (`Length`)
- Range constraints (`Range`, `Positive`, `PositiveOrZero`)
- Choice constraints (`Choice` for enums/statuses)

---

## 10. Anti-Patterns (Forbidden)

| Anti-Pattern | What to Do Instead |
|-------------|-------------------|
| Entity extending `RestController` or `BaseService` | Entities are pure data objects |
| Business logic in Entity methods | Move to Service layer |
| Injecting services into Entity | Entities MUST NOT use DI |
| Direct `$em->persist()` in Entity | Use Service layer |
| Mutable `DateTime` | Use `DateTimeImmutable` |
| Using a local ID as a durable cross-module reference | Use UUID or a documented immutable business key |
| Storing money as float/decimal in code | Store as int (cents), convert at API boundary |
| Circular references in JSON without handling | Use `CircularReferenceHandler` or serializer groups |
