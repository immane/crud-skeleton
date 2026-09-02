# Trade Bundle Design

> The Trade bundle (`src/Trade/`) owns orders, order items, price calculation, and the
> Symfony Workflow-based order state machine. Product and Specification are owned by
> `Store` (`src/Store/Entity/Product.php`, `src/Store/Entity/Specification.php`,
> tables `trade_product`/`trade_specification` retained) with nullable `store` (`NULL` =
> shared/global); see [Store Catalog Model](../store-catalog.md). `Trade` remains the
> commercial-order authority and references the Store catalog via scalar snapshots and
> `StoreContext`.

---

## 1. Overview

Trade provides a complete order management system:

- **Store-catalog integration** with `Store` Products and Specifications (SKU-like variants, `Product.store` nullable for shared/global)
- **Orders** with a state machine lifecycle (draft -> completed)
- **Order Items** with price snapshots for historical accuracy
- **Price Calculation Pipeline**: pluggable calculators with priority ordering (Store visibility enforced in `BasePriceCalculator`)
- **Soft Deletes**: products and specifications use `isDeleted` flag (Store entities)
- **UUID v4**: external identifiers for orders and items
- **Store integration**: Store-scoped orders write a local Outbox event and await Store acceptance

### 1.1 Catalog Ownership

Product and Specification are `Store` entities (`Product.store` nullable). `StoreContext` (`X-Store-Code` → `Store`) is required for catalog reads, quotes, and orders; `BasePriceCalculator` accepts only shared (`store IS NULL`) or Store-owned specs for the resolved Store. See [Store Catalog Model](../store-catalog.md).

### 1.2 Entities

| Entity | Table | Purpose |
|--------|-------|---------|
| `Store\Product` | `trade_product` | Sellable product with name, description, status, nullable `store` |
| `Store\Specification` | `trade_specification` | Product variant (name, price in cents, status) |
| `Order` | `trade_order` | Purchase order with state machine, total, currency |
| `OrderItem` | `trade_order_item` | Line item with `specificationUuid` (scalar), `specificationTitle`, snapshots, quantity, unit price |
| `TradeOutboxMessage` | `trade_outbox_message` | Transactional integration event relay record |

### 1.2 Store-Scoped Orders (optional, default off)

`POST /api/v1/app/orders` remains the sole customer order entry point. A trusted
`X-Store-Code` is resolved by the Store bundle into the Trade-owned scalar
`StoreContext`; Trade never imports a Store entity. The order always receives an `_store`
metadata snapshot, but whether it enters the Store workflow is controlled by
`Store.settings` (see `store.md §5.1.1`, both `false` by default):

- `order.requireAcceptance=false` (default): order stays `draft` (like a plain order),
  `POST /api/v1/app/orders` returns `201 Order created`, no `trade.order.created.v1`.
- `order.requireAcceptance=true`: order enters `awaiting_store_acceptance`, writes
  `trade.order.created.v1` in the same transaction and returns `202 Order submitted for store acceptance`.

`app:trade:outbox:publish` dispatches the event through Messenger when emitted. Store consumes it
idempotently, writes its StoreOrder and result Outbox event (`store.order.accepted/rejected/verified`),
and Trade consumers apply `store.order.accepted.v1` / `store.order.rejected.v1` /
`store.order.verified.v1` (latter drives `fulfilled --request_verification--> awaiting_store_verification --store_verify--> completed` when `fulfillment.requireVerification=true`). Guard blocking is in `Store/EventListener/StoreOrderWorkflowGuardListener`; `Trade/EventListener/OrderWorkflowListener` is status-driven for `completed` and does not hard-code Store transition names. The status column is
`VARCHAR(40)` to support these workflow states.

---

## 2. File Structure

```
src/Trade/
|-- Controller/
|   |-- App/
|   |   `-- OrderController.php           # Public: list/create/cancel own orders
|   |-- Manage/
|       |-- OrderController.php            # CRUD + workflow + price calculation
|-- Entity/
|   |-- Order.php
|   |-- OrderItem.php  # scalar specificationUuid + snapshots (FK removed, irreversible)
|   `-- TradeOutboxMessage.php
|-- Command/PublishOutboxCommand.php
|-- DTO/StoreContext.php
|-- Message/ + MessageHandler/             # Store integration contracts/consumers
|-- EventListener/
|   |-- OrderWorkflowListener.php          # Post-transition timestamp setters
|-- Exception/
|   |-- OrderInvalidTransitionException.php
|   |-- SpecificationNotFoundException.php
|-- Repository/
|   |-- OrderItemRepository.php
|   |-- OrderRepository.php
|-- Service/
|   |-- Catalog/CatalogResolverInterface.php + CatalogItem.php # Trade-owned port/DTO
|   |-- OrderService.php                   # Order creation + price pipeline (no Store import)
|   |-- Pricing/
|       |-- PriceCalculatorInterface.php   # Plugin contract
|       |-- PriceCalculationContext.php    # Input/output DTO (storeCode)
|       |-- PriceCalculationResult.php     # Result DTO
|       |-- BasePriceCalculator.php        # Resolves via CatalogResolver (Store visibility enforced in Store)
|       |-- QuantityCalculator.php         # Computes price = unitPrice * quantity
|       |-- TotalAggregator.php            # Establishes subtotal (priority 55)

 src/Store/
 |-- Entity/Product.php  # trade_product, nullable store ManyToOne Store
 |-- Entity/Specification.php # trade_specification, ManyToOne Store\Product
 |-- Repository/ProductRepository.php, SpecificationRepository.php
 |-- Service/ProductService.php, SpecificationService.php
 |-- Service/Catalog/StoreCatalogResolver.php # Trade CatalogResolverInterface impl (Store visibility)
 |-- Controller/App/ProductController.php, SpecificationController.php # DqlExpression row-scope
 |-- Controller/Manage/ProductController.php, SpecificationController.php, SpecificationAllController.php
```

---

## 3. Entity Relationships

```
Store\Product (status: active/inactive, isDeleted: bool, metadata: JSON, store: ?Store)
  |
  +-- 1:N -> Store\Specification (cascade: persist)

Store\Specification (name, price: int cents, status, sort, isDeleted)
  |  inherits Product.store visibility
  +-- (no FK) -> Trade\OrderItem via scalar specificationUuid + snapshots

Order (uuid, totalAmount: int cents, currency: CNY, status: state machine, notes, metadata _store)
  |
  +-- M:1 -> User
  +-- 1:N -> OrderItem (cascade: persist)

OrderItem (uuid, quantity, unitPrice: cents, price: cents, cost, profit, specificationUuid, specificationTitle, specSnapshot/productSnapshot: JSON)
  |
  +-- M:1 -> Order
  +-- (scalar) specificationUuid (indexed, no FK)
```

---

## 4. Entity Design Details

### 4.1 Product

- UUID v4 for external reference
- `status`: `active` or `inactive`
- `isDeleted`: soft delete flag
- `metadata`: JSON extensible field

### 4.2 Specification

- Belongs to a Product
- `price` in cents (integer, converted to/from decimal at API boundary)
- `status`: active/inactive
- `sort`: manual ordering
- `isDeleted`: soft delete flag

### 4.3 Order

- UUID v4
- `totalAmount` in cents
- `currency`: default `CNY`
- `status`: state machine marking field (draft/pending/confirmed/paid/fulfilled/completed/cancelled/refunded)
- `cancelledAt`, `completedAt`: set by `OrderWorkflowListener`
- `items`: cascaded persist

### 4.4 OrderItem

- UUID v4
- `unitPrice`: snapshot of specification's price at order time (cents)
- `price`: `unitPrice * quantity`, auto-calculated in `#[ORM\PrePersist]`
- `cost`, `profit`: for margin tracking
- `specSnapshot`, `productSnapshot`: JSON snapshots captured at creation for historical record

---

## 5. Price Calculation Pipeline

### 5.1 Contract

```php
interface PriceCalculatorInterface
{
    public function calculate(PriceCalculationContext $context): void;
    public static function getPriority(): int;
}
```

### 5.2 Calculator Chain (Priority Order)

| Priority | Calculator | Module | Responsibility |
|----------|-----------|--------|----------------|
| -100 | `BasePriceCalculator` | Trade | Resolve Specification entity, validate active/not-deleted, extract unit price, capture snapshots |
| 50 | `QuantityCalculator` | Trade | Compute `price = unitPrice * quantity` for each item |
| **55** | **`TotalAggregator`** | **Trade** | **Establish the subtotal before promotion evaluation** |
| **60** | **`PromotionCalculator`** | **Promotion** | **DSL eval → match → apply (max 20 iterations, applied-ID tracking, exclusive/lock-item/best-price conflict modes)** |

External modules (e.g., `Promotion`, future `Coupon`) hook into the pipeline by implementing `PriceCalculatorInterface` and tagging with `#[AutoconfigureTag('trade.price_calculator')]`. Trade has zero awareness of these modules.

### 5.3 Pipeline Execution

```
OrderService::calculatePrices($items, $currency)
  -> Collect all PriceCalculatorInterface implementations (auto-tagged)
  -> Sort by getPriority() ascending
  -> Execute each in sequence on PriceCalculationContext
  -> Return PriceCalculationResult (items, totalAmount, currency)
```

### 5.4 Pipeline Execution

```
OrderService::calculatePrices($items, $currency, $storeCode = null, $meta = [])
  -> Create PriceCalculationContext with items, currency, user, storeCode, meta
  -> Collect all PriceCalculatorInterface implementations (auto-tagged)
  -> Sort by getPriority() ascending
  -> Execute each in sequence on PriceCalculationContext
     (BasePriceCalculator → QuantityCalculator → TotalAggregator → PromotionCalculator)
  -> Return PriceCalculationResult (items, totalAmount, currency, meta)
```

### 5.5 DTOs

```php
class PriceCalculationContext
{
    public array $inputItems;     // Raw input from request
    public array $items;          // Mutated by calculators
    public int $totalAmount;      // Final total in cents
    public string $currency;      // e.g., 'CNY'
    public array $meta = [];      // Bidirectional opaque channel for calculators
    public ?object $user = null;  // Current user (for member-level conditions)
    public ?string $storeCode = null; // Multi-store routing
}

class PriceCalculationResult
{
    public int $totalAmount;
    public string $currency;
    public array $items;          // Calculated order items
    public array $meta;           // From context.meta (carries promotion/coupon results)
}
```

### 5.6 `meta` Channel Contract

`meta` is an opaque array that Trade never inspects. Calculators read from it as input
and write to it as output. The contract is:

| Direction | Example | Set By |
|-----------|---------|--------|
| Client → Calculators | `{coupon: {code: "ABC123"}}` | Request body → `calculatePrices($items, $currency, $storeCode, $meta)` |
| Calculators → Client | `{promotion: {inner: [...], outer: {...}}}` | `PromotionCalculator` writes to `context.meta['promotion']` |
| Any key can coexist | `{promotion: {...}, coupon: {...}, existing: "..."}` | Multiple calculators |

**Guarantees**:
- Trade never reads or mutates `meta` content — it passes through unchanged.
- `PriceCalculationResult::fromContext()` copies `context.meta` verbatim into the result.
- New modules (e.g., Coupon) follow the same pattern: implement `PriceCalculatorInterface`,
  read from `context.meta['coupon']`, write to `context.meta['coupon']`.

### 5.7 Registration

Calculators are auto-discovered and tagged via `config/services.yaml`:

```yaml
services:
  App\Trade\Service\Pricing\:
    resource: '../src/Trade/Service/Pricing/'
    tags: ['trade.price_calculator']
```

New calculators can be added by implementing the interface -- no other code changes needed.

---

## 6. Order State Machine

### 6.1 Configuration

**File**: `config/packages/workflow.yaml` — two optional Store flows (both `false` by default, see `store.md §5.1.1`).

```yaml
framework:
  workflows:
    order:
      type: state_machine
      marking_store: { type: method, property: status }
      places:
        - draft
        - pending
        - awaiting_store_acceptance
        - store_accepted
        - store_rejected
        - confirmed
        - paid
        - fulfilled
        - awaiting_store_verification
        - completed
        - cancelled
        - refunded
      transitions:
        submit:   draft -> pending
        store_submit: draft -> awaiting_store_acceptance # guard: order.requireAcceptance && hasStore
        store_accept: awaiting_store_acceptance -> store_accepted
        store_reject: awaiting_store_acceptance -> store_rejected
        confirm:  [pending, store_accepted] -> confirmed
        pay:      confirmed -> paid
        fulfill:  paid -> fulfilled
        request_verification: fulfilled -> awaiting_store_verification # guard: fulfillment.requireVerification && hasStore
        store_verify: awaiting_store_verification -> completed           # Staff verify
        complete: fulfilled -> completed                                 # guard blocks when requireVerification
        cancel:   [draft, pending, awaiting_store_acceptance, store_accepted, store_rejected, confirmed, fulfilled, awaiting_store_verification] -> cancelled
        refund:   completed -> refunded
```

Guards live in `Store/EventListener/StoreOrderWorkflowGuardListener` (reads `Store.settings` via `StoreRepository`). Plain orders (`_store` absent) remain permissive at the workflow layer.

### 6.2 Valid Transitions (non-Store happy path + Store branches)

| From | To | Transition Name | When |
|------|-----|----------------|------|
| draft | pending | `submit` | always / `requireAcceptance=false + hasStore` |
| draft | awaiting_store_acceptance | `store_submit` | `requireAcceptance=true + hasStore` |
| awaiting_store_acceptance | store_accepted | `store_accept` | via `store.order.accepted.v1` |
| awaiting_store_acceptance | store_rejected | `store_reject` | via `store.order.rejected.v1` |
| pending / store_accepted | confirmed | `confirm` | |
| confirmed | paid | `pay` | |
| paid | fulfilled | `fulfill` | |
| fulfilled | awaiting_store_verification | `request_verification` | `requireVerification=true + hasStore` |
| awaiting_store_verification | completed | `store_verify` | `store.order.verified.v1 {verificationCode, verifiedBy}` |
| fulfilled | completed | `complete` | `requireVerification=false` or no Store |
| draft/pending/.../fulfilled/awaiting_store_verification | cancelled | `cancel` | |
| completed | refunded | `refund` | |

### 6.3 Workflow Listener

```php
class OrderWorkflowListener
{
    // On cancel/paying/fulfill/refund -> set timestamps per transition
    // On *landed in* completed (complete via Trade OR store_verify via Store) -> set completedAt + dispatch OrderCompletedEvent (status-driven, no Store transition name)
}
```

---

## 7. Order Creation Flow

```
POST /api/v1/manage/orders
  Body: {items: [{specification: {id: N}, quantity: N}, ...], currency: "CNY", notes: "...", meta: {coupon: {...}}}
  |
  v
OrderService::calculatePrices($items, $currency, $storeCode, $meta)
  -> Create PriceCalculationContext(items, currency)
  -> Set context.user, context.storeCode, context.meta = $meta
   -> Price Calculation Pipeline (Base → Quantity → TotalAggregator [subtotal] → Promotion)
   -> Returns PriceCalculationResult (items, totalAmount, currency, meta)
  |
  v
OrderService::createOrder($calculatedItems, $user, $totalAmount, $currency, $notes)
  -> Within transaction:
     -> Create Order entity
     -> For each calculated item: create OrderItem
        -> Snapshot spec + product data
        -> Auto-calculate price = unitPrice * quantity (PrePersist)
     -> Persist + flush
  -> Returns Order entity
```

### 7.1 Quote Flow (Order Preview)

```
POST /api/v1/app/orders/quote
  Body: {items: [{specificationId: N, quantity: N}, ...], meta: {coupon: {code: "ABC"}}}
  |
  v
OrderService::calculatePrices($items, $currency, $storeCode, $meta)
  -> Returns PriceCalculationResult (items, totalAmount, currency, meta)
  -> No Order is persisted — pure pricing preview
  
Response:
{
  data: {
    items: [{specificationId: 1, unitPrice: 10000, price: 10000, ...}],
    totalAmount: 8000,
    currency: "CNY",
    meta: {
      promotion: { inner: [{promotionId: 1, promotionName: "满减", ...}] }
    }
  }
}
```

---

## 8. API Endpoints

### 8.1 Manage (Admin, ROLE_ADMIN)

| Method | Path | Description |
|--------|------|-------------|
| GET | `/api/v1/manage/products` | List products |
| GET | `/api/v1/manage/products/{id}` | Product detail |
| POST | `/api/v1/manage/products` | Create product |
| PUT | `/api/v1/manage/products/{id}` | Update product |
| DELETE | `/api/v1/manage/products/{id}` | Delete product |
| GET | `/api/v1/manage/specifications` | List specs |
| POST | `/api/v1/manage/specifications` | Create spec |
| PUT | `/api/v1/manage/specifications/{id}` | Update spec |
| DELETE | `/api/v1/manage/specifications/{id}` | Delete spec |
| GET | `/api/v1/manage/products/{id}/specifications/{sid}` | **Manage spec detail** |
| GET | `/api/v1/manage/orders` | List orders |
| GET | `/api/v1/manage/orders/{id}` | Order detail |
| POST | `/api/v1/manage/orders` | Create order (custom logic) |
| **POST** | **`/api/v1/manage/orders/quote`** | **Calculate prices without creating order** |
| PUT | `/api/v1/manage/orders/{id}` | Update draft order only |
| DELETE | `/api/v1/manage/orders/{id}` | Delete draft order only |
| GET | `/api/v1/manage/orders/{id}/items` | View order items |
| POST | `/api/v1/manage/orders/{id}/pay` | Pay with wallet deduction |
| POST | `/api/v1/manage/orders/{id}/fulfill` | Fulfill with tracking info |
| POST | `/api/v1/manage/orders/{id}/refund` | Refund with wallet credit |
| GET | `/api/v1/manage/orders/todo` | Orders with available transitions |
| GET | `/api/v1/manage/orders/{id}/transitions` | Enabled transitions |
| POST | `/api/v1/manage/orders/{id}/do/{transition}` | Execute transition |
| PUT | `/api/v1/manage/orders/{id}/status-reset` | Admin reset marking |

### 8.2 App (Public, Authenticated)

| Method | Path | Description |
|--------|------|-------------|
| GET | `/api/v1/app/products` | List active, non-deleted products |
| GET | `/api/v1/app/products/{id}` | Product detail |
| **GET** | **`/api/v1/app/specifications`** | **Browse all active specs** |
| **GET** | **`/api/v1/app/specifications/by-product/{id}`** | **Specs by product** |
| **GET** | **`/api/v1/app/specifications/{id}`** | **Spec detail** |
| GET | `/api/v1/app/orders` | List current user's orders |
| GET | `/api/v1/app/orders/{id}` | Order detail |
| POST | `/api/v1/app/orders` | Create order |
| **POST** | **`/api/v1/app/orders/quote`** | **Calculate prices without creating order** |
| GET | `/api/v1/app/orders/{id}/items` | View order items |
| POST | `/api/v1/app/orders/{id}/cancel` | Cancel own order |

---

## 9. Order Controller Constraints

### 9.1 Update Constraint

Orders can only be updated in `draft` status. Attempting to update non-draft orders should be rejected.

### 9.2 Delete Constraint

Orders can only be deleted in `draft` status. Other states require cancellation first.

### 9.3 Workflow Operations

- `todo`: Lists all orders that have at least one enabled transition
- `transitions`: Returns available transitions for a specific order
- `do/{transition}`: Executes the named transition within a transaction, optionally accepting data to update the entity before transition

### 9.4 Payment (Pay)

- `POST /manage/orders/{id}/pay` with `{systemWalletId, paymentMethod}`
- Validates order is in `confirmed` status
- Deducts from user's wallet, credits to system wallet via `TransferService`
- Sets `paidAt`, `paymentMethod`, applies `pay` transition

### 9.5 Fulfillment

- `POST /manage/orders/{id}/fulfill` with `{trackingNumber, shippingAddress}`
- Validates order is in `paid` status
- Sets `fulfilledAt`, `trackingNumber`, `shippingAddress`
- Applies `fulfill` transition

### 9.6 Refund

- `POST /manage/orders/{id}/refund` with `{systemWalletId, reason}`
- Validates order is in `completed` status
- Transfers from system wallet back to user's wallet via `TransferService`
- Sets `refundedAt`, `refundReason`, applies `refund` transition

### 9.7 User Cancel

- `POST /app/orders/{id}/cancel` -- authenticated user cancels own order
- Allowed only when status is `draft`, `pending`, or `confirmed`
- Sets status to `cancelled` (not via workflow, direct update)

### 9.8 View Items

- `GET /manage/orders/{id}/items` -- admin view
- `GET /app/orders/{id}/items` -- user view (ownership verified)

---

## 10. Money Handling Contract

| Aspect | Rule |
|--------|------|
| Storage | `bigint` (cents) in database |
| PHP type | `int` for amounts |
| API input | Decimal string/number |
| API output | Decimal string/number |
| Conversion on write | `* 100` (via `@transform` expression or service) |
| Conversion on read | `/ 100` |

---

## 11. Database Migrations

**Version**: `Version20250620000000`

Creates 4 tables: `trade_product`, `trade_specification`, `trade_order`, `trade_order_item`.

**Version**: `Version20250621000000`

Adds columns to `trade_order`: `paid_at`, `refunded_at`, `fulfilled_at`, `payment_method`, `tracking_number`, `shipping_address`, `refund_reason`.

---

## 12. Testing

| Suite | Tests |
|-------|-------|
| `tests/Trade/Entity/` | Product, Order, OrderItem, Specification unit tests |
| `tests/Trade/Service/` | OrderService create order, OrderItem service |
| `tests/Trade/Pricing/` | BasePriceCalculator, QuantityCalculator, TotalAggregator, PriceCalculationResult |
| `tests/Trade/Controller/` | OrderController create/quote/list/detail |
| `tests/Trade/Integration/` | Product repository, Order repository integration |
| `tests/Promotion/Integration/` | 8 real SQLite pipeline tests with Doctrine + actual OrderService |
