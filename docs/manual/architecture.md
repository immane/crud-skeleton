# Architecture

This page is a practical summary of how the codebase is built. The authoritative,
normative version is [docs/design/system-architecture.md](../design/system-architecture.md);
that document is the contract — this page is the developer-facing overview.

## Modular Monolith (not a monorepo)

The application is **one Symfony application deployed as one unit** that contains
several self-contained business modules. It is deliberately *not* a multi-service
monorepo: modules share a single kernel, one Doctrine EntityManager, one cache, and
one Messenger bus, but they must behave as if they were independent services.

- Modules communicate through **service interfaces** only (see
  [Cross-Module Dependency Rules](#cross-module-dependency-rules)).
- Each module owns its entities, repositories, controllers, and services.
- `Core` is foundational and must never depend on a business module.

```
┌────────────────────────────────────────────────────────────┐
│                     Single application                     │
│   Kernel · config/ · public/ ·  migrations/ ·  tests/      │
│  ┌────────┐ ┌─────────┐ ┌────────┐ ┌────────┐ ┌─────────┐  │
│  │  Core  │ │ Common  │ │Identity│ │ Trade  │ │  ...    │  │
│  ├────────┤ ├─────────┤ ├────────┤ ├────────┤ ├─────────┤  │
│  │ Store  │ │Inventory│ │Payment │ │ Wallet │ │Promotion│  │
│  ├────────┤ ├─────────┤ ├────────┤ ├────────┤ ├─────────┤  │
│  │Settle  │ │Storage  │ │Wechat  │ │  ...   │ │         │  │
│  └────────┘ └─────────┘ └────────┘ └────────┘ └─────────┘  │
└────────────────────────────────────────────────────────────┘
```

### Modules under `src/`

| Module | Responsibility |
|--------|----------------|
| `Core` | Framework abstractions: `RestController`, `BaseService`, View mixins, Expression query engine, serialization |
| `Common` | CMS primitives: Category, Tag, Content, Comment, Page, Media, Picture, Setting |
| `Identity` | Authentication & accounts: User, Profile, RefreshToken, JWT/OTP flows |
| `Trade` | E-commerce transactions: Order (`currency` from `Store` via `X-Store-Code`), OrderItem, pricing pipeline (single currency per order), payment orchestration; references Store catalog |
| `Store` | Multi-store operations: Store (`currency` varchar(32) DEFAULT `CNY`, `REWARD_POINT` for points mall, `X-Store-Code` → `StoreContext`), Membership (self-join `POST /app/stores/{uuid}/membership` as `clerk`), StoreOrder distribution; owns Product and Specification catalog (shared `NULL` / store-private, [Store Catalog Model](../design/store-catalog.md)) |
| `Inventory` | Stock, materials, recipes, reservations, ledger |
| `Payment` | Invoice lifecycle, gateway abstraction, webhooks, events |
| `Wallet` | Balances, atomic transfers, deposits/withdrawals, vouchers, deductions |
| `Promotion` | Promotional rules with a small embedded DSL, strategies, calculation |
| `Settlement` | Rule-driven funding allocation and settlement finality |
| `Storage` | Media storage abstraction (local / Qiniu) |
| `Wechat` | WeChat login (Mini Program / Official Account) and WeChat Pay V3 gateway |
| `Authorization` | Scoped RBAC (`global`/`store`), `DqlExpression` row scopes, Store-scoped grants, strict field grants, audit log, `AuthorizationVoter` (see `docs/design/bundles/authorization.md` and `manual/authorization.md`) |

## Layer Architecture

Requests flow through a strict, one-directional chain:

```
HTTP (Controllers / View mixins)  →  Service  →  Repository  →  Entity  →  Infrastructure
```

- **HTTP layer** — controllers only. The only layer that touches Request/Response.
  Thin: it reads input, calls one service, and renders via the View mixins.
  Separate `App` (read-only user), `Manage` (admin CRUD), and `Webhook` namespaces.
- **Service layer** — all business logic, transactions, and validation live here.
  Services expose interfaces; other modules and controllers depend on the interface,
  never the concrete class.
- **Repository layer** — Doctrine data-access queries only.
- **Entity layer** — pure domain objects (Doctrine entities), no business logic.
- **Infrastructure** — framework-provided ORM, cache, serializer, messenger.

### Layer Dependency Rules

| Rule | From | To | Allowed? |
|------|------|----|----------|
| R1 | Controller | Service | YES |
| R2 | Controller | Entity | YES (type hints / returns only) |
| R3 | Controller | Repository | **NO** — go through Service |
| R4 | Controller | EntityManager | **NO** — go through Service |
| R5 | Service | Repository | YES |
| R6 | Service | Entity | YES |
| R7 | Service | EntityManager | YES |
| R8 | Service | Other Services | YES (via DI, interface-first) |
| R9 | Entity | Repository | **NO** |
| R10 | Entity | Service | **NO** |
| R11 | Entity | EntityManager | **NO** |

## Key Design Patterns

### RestController + View Mixins

Every business controller extends `App\Core\Controller\RestController`
(`src/Core/Controller/RestController.php`), which provides `success()`/`warning()`
JSON envelopes, pagination, and `@display`/`@expands` response shaping.

CRUD endpoints are assembled from reusable mixins under `src/Core/View/`:

- `ListApiViewMixin` / `ScopedListApiViewMixin` — list + paginate
- `DetailApiViewMixin` / `ScopedDetailApiViewMixin` — single record
- `CreateApiViewMixin`, `UpdateApiViewMixin`, `DeleteApiViewMixin` — mutation endpoints
- `SingleCreateAndUpdateApiViewMixin`, `SingleDetailApiViewMixin` — singleton resources
- `WorkflowApiViewMixin` — workflow transitions driven from the controller

### BaseService + Traits

Services extend `App\Core\Service\BaseService` and implement a module interface.
`BaseService` composes three traits:

- `BaseServiceInfrastructureTrait` — container / EntityManager / repository wiring
- `BaseServiceReadListTrait` — generic list/filter implementation
- `BaseServiceMutationTrait` — generic create/update/delete behaviour

The result is a generic CRUD service per entity that concrete services override
where domain rules require it. Every module service also declares an interface
(e.g. `OrderServiceInterface extends BaseServiceInterface`).

### Expression Dynamic Query Engine & `DqlExpression`

List endpoints accept expression query parameters that are parsed to DQL:

| Parameter | Meaning |
|-----------|---------|
| `@filter` | Declarative filter conditions (client-supplied, `GET` only) |
| `@sort` / `@order` | Ordering clauses |
| `@dql` | Raw DQL fragments / embedded expressions |
| `@select` | Projection of selected fields |

Shared syntax is implemented in `src/Core/Parser/` (`ExpressionDqlParser`,
`ExpressionQueryBuilderAssembler`) and executed through
`App\Core\Service\ExpressionService` with `QueryBuilderFactory`. The same syntax
powers server-owned `DqlExpression` (`src/Core/Query/DqlExpression.php`) for
row-level authorization: `commonFilter()` may return
`new DqlExpression('entity.getUser() == this.getUser()')` or
`new DqlExpression('entity.getStoreUuid() in storeUuids', ['storeUuids' => $allowed])`,
compiled via `ExpressionDqlParser` + `ExpressionQueryBuilderAssembler` and
automatically `AND`ed with `id`/`uuid` criteria. Unlike `@filter`, it is
fail-closed (500 on error) and supports `in`/`not in` with empty-collection
safety.

### Pricing Pipeline

Order pricing is a priority-ordered chain of calculators, all tagged
`trade.price_calculator` and sorted ascending by `getPriority()`:

| Order | Calculator | Priority |
|-------|-----------|----------|
| 1 | `BasePriceCalculator` (`src/Trade/Service/Pricing/`) | -100 |
| 2 | `QuantityCalculator` | 50 |
| 3 | `TotalAggregator` | 55 |
| 4 | `PromotionCalculator` (`src/Promotion/Service/`) | 60 |

`OrderService` collects them with `#[AutowireIterator('trade.price_calculator')]`
and applies them in priority order. Adding a new pricing step = implement
`PriceCalculatorInterface`, tag it, done.

### Payment Gateway Registry

`PaymentGatewayInterface` implementations (mock, wallet, wechat, …) are registered
against the `payment.gateway` tag in
`src/Payment/Resources/config/services_payment.yaml` and resolved at runtime through
`PaymentGatewayRegistry` — supports switching providers per invoice and dispatch to
webhooks (`PaymentNotifyController`) with provider-agnostic invoice events.

### Outbox / Inbox Pattern

Cross-module writes never call the target module synchronously:

- **Outbox** — each publishing module persists its own `*OutboxMessage` entity in the
  same transaction as the change. The `scheduler` service polls
  `app:{module}:outbox:publish` commands (Trade, Store, Inventory, Settlement) to
  flush messages to Messenger.
- **Inbox / handlers** — message handlers (`MessageHandler/`) in the consuming module
  are the only readers of those events and enforce their own integrity and
  idempotency (e.g. inventory `ReservationRequestedHandler`, store
  `TradeOrderCreatedHandler`, settlement `SettlementFundingConfirmedHandler`).

### Other Notable Patterns

| Pattern | Where |
|---------|-------|
| UUID identity on aggregates | Cross-module references are UUIDs (`Core\Utils\UUID`); durable keys never cross a boundary as plain integer IDs |
| Optimistic locking on Wallet | `Wallet` carries an integer `version` column; concurrent balance updates detect conflicts instead of overwriting |
| Soft delete | `Product` / `Specification` (`Store` entities, tables `trade_product`/`trade_specification`) use an `isDeleted` flag while retaining immutable order snapshots |
| Token rotation | Refresh tokens are stored hashed (HMAC-SHA256) and rotated on every use with reuse detection (`Identity\Security\TokenManager`) |
| Exact-money settlement | `QuantumAmount` (`Settlement\Service\Money`) is a base-10 integer quantum of fixed scale (default 18, `brick/math`), avoiding float error; `AllocationRoundingService` handles remainder distribution (largest-remainder) |
| Registry pattern | `PaymentGatewayRegistry`, `PaymentAdjustmentRegistry`, `DepositProviderRegistry`, `WithdrawProviderRegistry`, `MediaStorageRegistry`, `SettlementContextResolverRegistry` |

## Cross-Module Dependency Rules

| Concern | Rule |
|---------|------|
| Doctrine associations | **No cross-module Doctrine associations/relations.** An entity never references another module's entity by foreign key |
| Durable references | Between modules, reference aggregates by **UUID** (or an explicitly documented business key), never a local auto-increment id |
| Contracts | Cross-module service calls exchange **scalar values / snapshots / DTOs only** — no entities, repositories, or EntityManager across a boundary |
| Writes | Use the **outbox** pattern: persist the change plus an outbox message in one transaction; the target module consumes its own inbox message |
| Reads | Use **inbox / message handlers** as the entry point for consuming another module's state; direct repository reads across modules are forbidden |
| Dependency direction | Business modules may autowire `Core` and (interface-first) each other; `Core` must never import a business module; circular module dependencies are forbidden |
| Shared state | Event payloads carry scalar snapshots and external identity only; the receiving module still enforces its own authorization |

### Where this shows up in practice

- `Trade` publishes `TradeOrderCreatedMessage`/`TradeOrderCancelledMessage`; `Store`
  consumes them to fan orders out to stores (`StoreOrder`).
- `Store` publishes `StoreOrderAcceptedMessage`/`StoreOrderRejectedMessage`; `Trade`
  consumes them to move order state.
- `Trade` requests inventory reservations through `ReservationRequestedMessage`;
  `Inventory` confirms/rejects via `ReservationConfirmed/RejectedMessage`.
- `Payment` emits `InvoicePaidEvent`, `InvoiceRefundedEvent`, etc.; the `Wallet`
  gateway and `Settlement` funding flow react through their own handlers.
