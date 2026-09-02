# Store Bundle Design

> **Status: phase 1-3 implemented in the modular monolith.** Store, membership,
> StoreOrder, Trade/Store Outbox-Inbox, Messenger consumers, and Store acceptance are
> implemented. Inventory reservation remains an intentionally deferred boundary.
> The Store bundle (`src/Store/`) owns multi-store context, store operations, the
> store-side order projection, and the Product/Specification catalog (shared/global
> `Product.store = NULL` and store-private `Product.store = Store`, tables
> `trade_product`/`trade_specification` retained). Trade remains the single commercial
> order entry point and the source of truth for order amount, payment, refund, and
> customer order status. See [Store Catalog Model](../store-catalog.md) for invariants.

---

## 1. Goals And Scope

### 1.1 Goal

Provide a multi-store boundary that can begin as a Symfony module and later be
extracted as a service without changing the ownership of commercial orders.

```text
Customer -> Trade Order API -> Trade Order + Trade Outbox
                                  |
                                  v
                         Store event consumer
                                  |
                                  v
                         StoreOrder + store decision
                                  |
                                  v
                         Store result event -> Trade consumer
```

The bundle owns:

- Store identity, lifecycle, business configuration, and public store identity.
- Store membership and store-local operational authorization.
- Store request context resolution and validation.
- The `StoreOrder` projection/aggregate for store acceptance and fulfillment data.
- Idempotent consumption and publication of Store integration events.
- The contract by which a store accepts or rejects a Trade order.
- Product and Specification catalog (shared/global and store-private, nullable `store`).

The bundle does not own:

- Customer order creation API.
- Commercial order amount, discounts, payment, refund, or customer cancellation.
- The canonical payment invoice.
- Inventory implementation. Inventory reservation is an integration boundary defined
  here and designed in a later Inventory bundle.

### 1.2 Non-Goals

The first Store phase MUST NOT:

- Create a second customer-facing order through `/stores/{id}/orders`.
- Maintain a writable copy of Trade payment, refund, or total amount state.
- Add Doctrine foreign keys to Trade, Payment, Identity, or future Inventory tables.
- Let a client-provided `storeId` or `storeCode` become an authorization fact.
- Treat `trade_order.metadata` as the authoritative store relation.
- Require distributed transactions or exactly-once broker delivery.

### 1.3 Ownership Matrix

| Concern | Owner | Store Responsibility |
|---|---|---|
| Order UUID, line-item amount, total, currency | Trade | Consume immutable snapshot only |
| Customer payment and refund | Trade + Payment | React to resulting business events only |
| Store identity and membership | Store | Authoritative |
| Store acceptance/rejection | Store | Authoritative |
| Store fulfillment operations | Store | Authoritative once introduced |
| Store-specific item eligibility | Store | Validate during acceptance |
| Inventory reservation | Inventory, future | Request and react; do not own stock ledger |
| Promotion calculation | Promotion + Trade | Receive a server-resolved store code at quote/order time |

---

## 2. Architectural Principles

### 2.1 One Commercial Order

`Trade\Entity\Order` is the only customer order aggregate. A client creates it through
the existing Trade order endpoint. `StoreOrder` is created only as the result of a
`trade.order.created.v1` event.

```text
Trade Order                         StoreOrder
-----------                         ----------
payment / refund                    store acceptance
commercial workflow                 operational workflow
customer-facing status              store-facing status
commercial amount                   copied amount snapshot for display only
invoice references                  fulfillment and reservation references
```

`StoreOrder` MUST NOT expose an API that creates a standalone commercial order.

### 2.2 Explicit Event Contracts

Trade and Store communicate through versioned, serializable integration events. They
MUST NOT communicate by passing Doctrine entities, repositories, or service objects.

The publisher does not know which consumers exist. A consumer knows only an event
schema and its own local services.

### 2.3 Local Transaction, Eventual Cross-Bundle Consistency

Every module writes its own business records and its own outbox event in one database
transaction. A relay delivers events at least once. Consumers provide idempotency.

```text
local database transaction:
  business mutation + outbox insert

cross-module delivery:
  at least once + inbox deduplication + business unique keys
```

No component may assume an exactly-once message broker guarantee.

### 2.4 Runtime Workers

The modular monolith starts asynchronous processing as Compose services, not inside the
PHP-FPM request process:

| Service | Command | Responsibility |
|---|---|---|
| `worker` | `messenger:consume async` | Consume Trade and Store integration messages with Messenger retry handling |
| `scheduler` | Trade/Store Outbox publisher loop | Relay unpublished Outbox rows every `OUTBOX_PUBLISH_INTERVAL` seconds (default `5`) |

The scheduler and worker use the same application image and environment as `app`. This
keeps the Outbox pattern durable in SQL while making the monolith operationally automatic.

### 2.4 Stable Scalar References

Cross-boundary references are UUIDs and scalar snapshots, never Doctrine associations.

Examples:

- `StoreOrder.tradeOrderUuid`, not `ManyToOne Trade\Order`.
- `Membership.userUuid`, not `ManyToOne Identity\User`.
- Future inventory reservation UUID, not an Inventory entity relation.

This prevents cross-module schema coupling and permits separate databases later.

---

## 3. Existing System Changes Required

This bundle cannot be implemented as a completely isolated source directory because
Trade owns the only order entry point. The required changes are intentionally narrow
and generic; Trade MUST NOT import Store entities, repositories, or services.

| Area | Required target change | Reason |
|---|---|---|
| Trade order workflow | Add `awaiting_store_acceptance`, `store_accepted`, and `store_rejected` places/transitions | Prevent payment before store acceptance |
| Trade order creation | Persist server-generated store snapshot and a `trade.order.created.v1` outbox event | Start asynchronous Store processing |
| Trade payment entry | Permit payment only after `store_accepted` | Avoid payment for unavailable stock/store |
| Trade outbox relay | Publish versioned integration events | Decouple publisher from Store consumer |
| Identity User identity | Add a unique User UUID before Store persists or publishes user references | Prevent Store from depending on `users.id` |
| Root routing/DI | Register Store controllers and Store service configuration | Compose the new bundle |

The implemented Store-scoped workflow is `draft -> awaiting_store_acceptance ->
store_accepted -> confirmed -> paid -> fulfilled -> completed`. Store rejection follows
`awaiting_store_acceptance -> store_rejected -> cancelled`. Non-Store orders retain the
existing Trade workflow.

The current context transport is `X-Store-Code`, resolved only against an active Store
by `StoreContextResolver`. A Store-scoped App order returns `202`. The Trade-order
consumer currently auto-accepts eligible active Stores; Inventory reservation is the
future decision boundary.

### 3.1 Store Context At The Trade Entry Point

The Trade controller obtains a `StoreContext` before calculating a quote or creating
an order. It MUST use a generic interface or DTO, never a Store entity type.

```php
final readonly class StoreContext
{
    public function __construct(
        public string $storeUuid,
        public string $storeCode,
        public string $storeName,
        public string $channel,
    ) {}
}
```

The Store bundle implements the resolver. Trade consumes only the resolved scalar data.
The exact location mechanism may be one of:

| Mechanism | Use | Trust Rule |
|---|---|---|
| Canonical host/subdomain | Store-branded storefront | Resolve from trusted request host mapping |
| Gateway-injected header | API gateway or mini-program gateway | Accept only after gateway authentication/signature |
| Explicit route selection | A customer deliberately selects a store | Validate availability server-side before use |

Raw request-body `storeId`, `storeUuid`, and `storeCode` are never authoritative. A
client may provide a selection hint only when a Store resolver validates it and returns
a concrete `StoreContext`.

### 3.2 Trade Metadata Snapshot

Trade stores a historical display snapshot under a reserved metadata key:

```json
{
  "_store": {
    "uuid": "store-uuid",
    "code": "shanghai-xuhui",
    "name": "Xuhui Store",
    "channel": "mini_program"
  }
}
```

The snapshot is written by trusted server code only. It is useful for audit and order
display, but it is not a query, authorization, or integrity boundary. `StoreOrder` is
the Store-side authoritative record, and the integration event carries the same
immutable snapshot.

---

## 4. Module Structure

```text
src/Store/
|-- Controller/
|   |-- App/
|   |   |-- StoreController.php              # Discover/read stores
|   |   `-- StoreOrderController.php         # Customer read-only store order view
|   `-- Manage/
|       |-- StoreController.php              # Platform admin store CRUD
|       |-- MembershipController.php    # Membership administration
|       `-- StoreOrderController.php         # Store operational actions
|-- DTO/
|   |-- StoreContext.php
|   |-- IntegrationEvent.php
|   `-- StoreOrderDecision.php
|-- Entity/
|   |-- Store.php
|   |-- Membership.php
|   |-- StoreOrder.php
|   |-- StoreOutboxMessage.php
|   `-- StoreConsumedEvent.php
|-- Event/
|   |-- TradeOrderCreatedV1.php             # Deserialized integration contract
|   |-- StoreOrderAcceptedV1.php
|   `-- StoreOrderRejectedV1.php
|-- EventListener/
|   |-- TradeOrderCreatedConsumer.php
|   `-- ReservationListener.php    # Future adapter boundary
|-- Exception/
|   |-- StoreContextNotFoundException.php
|   |-- StoreOrderConflictException.php
|   `-- StoreOrderNotOperableException.php
|-- Repository/
|   |-- StoreRepository.php
|   |-- MembershipRepository.php
|   |-- StoreOrderRepository.php
|   |-- StoreOutboxMessageRepository.php
|   `-- StoreConsumedEventRepository.php
|-- Service/
|   |-- StoreService.php
|   |-- StoreServiceInterface.php
|   |-- StoreContextResolverInterface.php
|   |-- StoreContextResolver.php
|   |-- MembershipService.php
|   |-- MembershipServiceInterface.php
|   |-- StoreOrderService.php
|   |-- StoreOrderServiceInterface.php
|   |-- StoreOrderDecisionService.php
|   |-- Outbox/
|   |   |-- OutboxPublisherInterface.php
|   |   `-- StoreOutboxPublisher.php
|   `-- Consumer/
|       `-- TradeOrderCreatedConsumer.php
`-- Resources/config/
    `-- services_store.yaml
```

The names above describe the target module. Inventory-specific implementation classes
are intentionally deferred until the inventory design is approved.

---

## 5. Entity Design

### 5.1 Store

**Table:** `store`

| Field | Type | Required | Description |
|---|---|---:|---|
| `id` | int | Yes | Internal primary key |
| `uuid` | string(36), unique | Yes | Stable public/cross-service identifier |
| `code` | string(50), unique | Yes | Immutable machine-readable store code |
| `name` | string(255) | Yes | Current display name |
| `status` | string(30) | Yes | `active`, `suspended`, `closed` |
| `timezone` | string(64) | Yes | IANA zone, such as `Asia/Shanghai` |
| `contact` | json nullable | No | Sanitized contact data |
| `address` | json nullable | No | Structured address/geolocation data |
| `settings` | json nullable | No | Store-local configuration, not secrets — see §5.1.1 |
| `createdAt` | datetime_immutable | Yes | Creation time |
| `updatedAt` | datetime_immutable nullable | No | Last update |

Rules:

- `uuid` is the only cross-service store identifier.
- `code` is stable after activation because Promotion and external channels may use it.
- `closed` stores cannot be selected for new orders.
- Historical orders use snapshots, so Store deletion is forbidden; closure is a status.

#### 5.1.1 Store Settings Schema (optional flows, default `false`)

`settings` is validated by `Manage/StoreController::validateStoreSettings` and parsed by `Store/DTO/StoreSettings` (`StoreSettingsResolver`). Unknown keys are tolerated for forward compatibility.

```json
{
  "order": { "requireAcceptance": false },
  "fulfillment": { "requireVerification": false }
}
```

| Key | Type | Default | Effect |
|---|---|---|---|
| `order.requireAcceptance` | `bool` | `false` | `true` → Trade order with `X-Store-Code` must `draft --store_submit--> awaiting_store_acceptance` and await `store.order.accepted/rejected.v1`. `false` → draft stays inline (`draft --submit--> pending` is allowed), `_store` snapshot is still kept, no Trade outbox `trade.order.created.v1` is emitted. Guard: `Store/EventListener/StoreOrderWorkflowGuardListener` blocks `store_submit` when disabled and blocks `submit` when enabled + hasStore. |
| `fulfillment.requireVerification` | `bool` | `false` | `true` → Trade `fulfilled --request_verification--> awaiting_store_verification --store_verify--> completed` via `store.order.verified.v1`. Guard blocks direct `complete` and requires `request_verification`+`store_verify`. `false` → `fulfilled --complete--> completed` directly. `Trade/EventListener/OrderWorkflowListener` is status-driven for `completed` so it does not hard-code `store_verify`. |

Validation:

- `settings` must be `object|null`; `order`/`fulfillment` must be `object|null`.
- `requireAcceptance` / `requireVerification` must be `bool` when present.
- `null` or missing `settings` is treated as both `false` (legacy stores). Change is immediate; inflight `fulfilled` orders whose Store flips to `true` will be blocked on next `complete` and must go through `request_verification`.

Example — enable both flows:

```bash
curl -X PUT http://localhost:8080/api/v1/manage/stores/{uuid} \
  -H "Content-Type: application/json" -d '{"settings":{"order":{"requireAcceptance":true},"fulfillment":{"requireVerification":true}}}'
```

### 5.2 Membership

**Table:** `store_membership`

| Field | Type | Required | Description |
|---|---|---:|---|
| `id` | int | Yes | Internal primary key |
| `store` | ManyToOne Store | Yes | Local Store relation |
| `userUuid` | string(36) | Yes | Identity User UUID scalar; no foreign key |
| `role` | string(30) | Yes | `owner`, `manager`, `clerk`, `fulfillment` |
| `status` | string(30) | Yes | `active`, `suspended`, `revoked` |
| `createdAt` | datetime_immutable | Yes | Grant time |
| `updatedAt` | datetime_immutable nullable | No | Last update |

Constraints and rules:

- Unique `(store_id, user_uuid)`.
- Index `(user_uuid, status)` for authorization lookup.
- A membership role is local to one Store and MUST NOT be written into `Identity\User.roles`.
- `ROLE_ADMIN` remains a platform-wide override; Store membership supports store-scoped
  operations only.

### 5.3 StoreOrder

**Table:** `store_order`

`StoreOrder` is the Store-side aggregate created from one Trade order-created event.
It is not a second commercial order.

| Field | Type | Required | Description |
|---|---|---:|---|
| `id` | int | Yes | Internal primary key |
| `uuid` | string(36), unique | Yes | Store order public identifier |
| `tradeOrderUuid` | string(36), unique | Yes | Immutable Trade order reference |
| `store` | ManyToOne Store | Yes | Local Store relation |
| `storeCodeSnapshot` | string(50) | Yes | Code at placement time |
| `storeNameSnapshot` | string(255) | Yes | Name at placement time |
| `customerUserUuid` | string(36) nullable | No | Scalar customer Identity UUID reference |
| `currency` | string(10) | Yes | Copied for display/validation |
| `totalAmount` | bigint | Yes | Immutable copied commercial amount in cents |
| `orderSnapshot` | json | Yes | Immutable line and delivery snapshot from event |
| `operationalStatus` | string(40) | Yes | Store operational state machine |
| `rejectionCode` | string(50) nullable | No | Machine-readable rejection reason |
| `rejectionReason` | text nullable | No | Sanitized operator/customer reason |
| `acceptedAt` | datetime_immutable nullable | No | Store acceptance time |
| `rejectedAt` | datetime_immutable nullable | No | Store rejection time |
| `fulfillmentData` | json nullable | No | Pickup/delivery/assignment data, Store-owned |
| `reservationId` | string(64) nullable | No | Future inventory reservation reference |
| `verifiedAt` | datetime_immutable nullable | No | Store verification time (when `settings.fulfillment.requireVerification=true`, set by `POST /store/{scopeId}/orders/{uuid}/verify`) |
| `verifiedBy` | string(36) nullable | No | Verifying staff `userUuid` (audit) |
| `verificationCode` | string(64) nullable | No | Client-supplied verification code (e.g. pickup/核销码, audit) |
| `createdAt` | datetime_immutable | Yes | Projection creation time |
| `updatedAt` | datetime_immutable nullable | No | Last update |

Indexes:

| Index | Purpose |
|---|---|
| Unique `trade_order_uuid` | Business idempotency: one StoreOrder per Trade order |
| `(store_id, operational_status, created_at)` | Store work queue and reporting |
| `(customer_user_uuid, created_at)` | Customer read views |
| `(reservation_id)` | Future inventory reconciliation |

`totalAmount`, `currency`, and `orderSnapshot` are copied facts for Store operations.
Trade remains authoritative if they ever differ; a mismatch is an alert-worthy contract
violation, not a Store-side repricing opportunity.

### 5.4 Store Operational Statuses

| Status | Meaning | Owner |
|---|---|---|
| `pending_validation` | Event received; Store checks eligibility | Store |
| `awaiting_inventory` | Store validated; reservation pending | Store/Inventory integration |
| `accepted` | Store can fulfill order; acceptance event emitted | Store |
| `rejected` | Store cannot fulfill order; rejection event emitted | Store |
| `fulfillment_pending` | Commercial payment complete; Store may prepare work | Store |
| `fulfilling` | Store is actively preparing/dispatching | Store |
| `fulfilled` | Store reports local fulfillment complete | Store |
| `cancelled` | Store operation stopped after Trade cancellation | Store |

The Store workflow MUST NOT use `paid`, `refunded`, or `completed` as its own state
names. Those remain Trade commercial states.

### 5.5 Outbox And Inbox Entities

#### StoreOutboxMessage

**Table:** `store_outbox_message`

| Field | Type | Description |
|---|---|---|
| `id` | bigint | Internal sequence |
| `eventId` | string(36), unique | Globally unique event identifier |
| `topic` | string(120) | e.g. `store.order.accepted.v1` |
| `aggregateType` | string(80) | `store_order` |
| `aggregateId` | string(64) | StoreOrder UUID |
| `payload` | json | Event envelope/payload, safe to serialize |
| `occurredAt` | datetime_immutable | Business event time |
| `availableAt` | datetime_immutable | Retry scheduling time |
| `publishedAt` | datetime_immutable nullable | Delivery acknowledgement time |
| `attempts` | int | Relay attempt counter |
| `lastError` | text nullable | Sanitized relay error |

#### StoreConsumedEvent

**Table:** `store_consumed_event`

| Field | Type | Description |
|---|---|---|
| `id` | bigint | Internal sequence |
| `eventId` | string(36), unique | Incoming integration event id |
| `topic` | string(120) | Original topic |
| `aggregateId` | string(64) | Source aggregate id |
| `processedAt` | datetime_immutable | Successful local processing time |
| `payloadHash` | string(64) | SHA-256 of canonical payload for diagnostics |

Inbox uniqueness prevents replayed broker messages from repeating Store decisions. The
business unique key on `StoreOrder.tradeOrderUuid` remains mandatory defense in depth.

---

## 6. Trade Order State Machine Extension

### 6.1 Target States

Trade `order` workflow now carries two **optional** Store flows controlled by `Store.settings` (both `false` by default). See `config/packages/workflow.yaml`.

**A. Store acceptance (pre-confirm) — `order.requireAcceptance`**

```text
# when requireAcceptance = false (default) — order stays draft/pending like a plain order
# Store snapshot still kept in metadata, no outbox, no StoreOrder is strictly required
draft -> pending -> confirmed -> paid -> fulfilled -> completed -> refunded

# when requireAcceptance = true
draft -> awaiting_store_acceptance -> store_accepted -> confirmed -> paid ...
awaiting_store_acceptance -> store_rejected -> cancelled
awaiting_store_acceptance -> cancelled (timeout or customer cancellation)
store_accepted -> cancelled (before payment)
```

Guard: `Store/EventListener/StoreOrderWorkflowGuardListener` blocks `store_submit` when
`requireAcceptance=false` and blocks `submit` when `true + hasStore`. `Trade/Service/OrderService::createOrder` emits `trade.order.created.v1` **only** when `store_submit` is actually taken.

**B. Store verification (post-fulfill) — `fulfillment.requireVerification`**

```text
# when requireVerification = false (default)
paid -> fulfilled --complete--> completed

# when requireVerification = true
paid -> fulfilled --request_verification--> awaiting_store_verification --store_verify--> completed
fulfilled/cancel & awaiting_store_verification/cancel also allowed
```

Guard blocks direct `complete` and requires `request_verification`+`store_verify` when enabled.
Store side: `POST /store/{scopeId}/orders/{uuid}/verify {verificationCode}` (`StoreOrderService::verify` → `store.order.verified.v1` → `Trade/MessageHandler/StoreOrderVerifiedHandler` applies `request_verification` then `store_verify`). `Trade/EventListener/OrderWorkflowListener` sets `completedAt`/dispatches `OrderCompletedEvent` status-driven so it does not hard-code `store_verify`.

Invariants (when the corresponding flag is `true`):

1. Trade emits `trade.order.created.v1` only after it has an immutable order UUID and
   Store snapshot.
2. A payment invoice cannot be created or paid before Store acceptance.
3. A Store rejection or acceptance timeout makes the commercial order terminally
   cancelled before payment.
4. Store acceptance/rejection/verification consumers are idempotent.
5. When `requireVerification=true`, `fulfilled` cannot `complete` without Store verification.

### 6.2 Payment Gate

The Trade payment service checks `order.status` is payment-eligible. Payment endpoints
MUST return a conflict response while an order is awaiting Store acceptance. This is a
business gate, not a UI convention.

### 6.3 Order Creation Response

When `settings.order.requireAcceptance=false` (default), `POST /api/v1/app/orders` with
`X-Store-Code` returns `201` `Order created` and the order stays in `draft` (no Store
outbox). Only when `requireAcceptance=true` does the Trade outbox emit
`trade.order.created.v1` and the API return `202`:

```json
{
  "data": {
    "orderUuid": "trade-order-uuid",
    "status": "awaiting_store_acceptance",
    "store": {
      "uuid": "store-uuid",
      "name": "Xuhui Store"
    }
  },
  "code": 202,
  "message": "Order submitted for store acceptance"
}
```

Similarly `paid --complete--> completed` is direct when
`settings.fulfillment.requireVerification=false`; with `true` the client must drive
`fulfilled --request_verification--> awaiting_store_verification --store_verify--> completed` via Store verification (see §7.6). The client polls the order detail endpoint; a `202` does not mean stock is available.

---

## 7. Integration Event Contracts

### 7.1 Envelope

All integration events use this envelope:

```json
{
  "eventId": "uuid",
  "type": "trade.order.created",
  "version": 1,
  "occurredAt": "2026-07-24T12:00:00+00:00",
  "aggregateType": "trade_order",
  "aggregateId": "trade-order-uuid",
  "correlationId": "trade-order-uuid",
  "causationId": null,
  "payload": {}
}
```

Rules:

- `eventId` is immutable and globally unique.
- `aggregateId` is the aggregate UUID, never an internal database id.
- `correlationId` follows the business flow across services; for this flow it begins as
  the Trade order UUID.
- `causationId` is the triggering event id, or `null` for an initial HTTP command.
- A breaking payload change creates a new event version/topic; it never mutates v1.
- Payloads must contain only data safe for the receiving domain. Do not publish secrets,
  payment credentials, raw provider callbacks, or unnecessary PII.

### 7.2 `trade.order.created.v1`

**Publisher:** Trade outbox relay after order creation commits.

**Consumer:** Store `TradeOrderCreatedConsumer`.

```json
{
  "orderUuid": "trade-order-uuid",
  "store": {
    "uuid": "store-uuid",
    "code": "shanghai-xuhui",
    "name": "Xuhui Store",
    "channel": "mini_program"
  },
  "customerUserUuid": "identity-user-uuid",
  "currency": "CNY",
  "totalAmount": 12800,
  "items": [
    {
      "lineId": "trade-order-item-uuid",
      "catalogReference": "SKU-001",
      "quantity": 2,
      "unitPrice": 6400,
      "lineAmount": 12800,
      "snapshot": {}
    }
  ],
  "delivery": {},
  "placedAt": "2026-07-24T12:00:00+00:00"
}
```

The event is a historical placement snapshot. Store does not re-read Trade tables to
complete the payload.

### 7.3 `store.order.accepted.v1`

**Publisher:** Store, after local validation and the future inventory reservation
contract succeed.

**Consumer:** Trade order decision consumer.

```json
{
  "orderUuid": "trade-order-uuid",
  "storeOrderUuid": "store-order-uuid",
  "storeUuid": "store-uuid",
  "acceptedAt": "2026-07-24T12:01:00+00:00",
  "reservationId": "inventory-reservation-uuid"
}
```

Trade applies the `store_accept` transition only when the event store UUID matches the
server-written order snapshot and the order is awaiting Store acceptance. Duplicates
are no-ops.

### 7.4 `store.order.rejected.v1`

**Publisher:** Store, when validation or inventory reservation cannot succeed.

**Consumer:** Trade order decision consumer.

```json
{
  "orderUuid": "trade-order-uuid",
  "storeOrderUuid": "store-order-uuid",
  "storeUuid": "store-uuid",
  "reasonCode": "OUT_OF_STOCK",
  "reason": "One or more items are unavailable.",
  "rejectedAt": "2026-07-24T12:01:00+00:00"
}
```

Allowed `reasonCode` values begin with:

| Code | Meaning |
|---|---|
| `STORE_NOT_FOUND` | Resolved Store no longer exists; indicates configuration drift |
| `STORE_UNAVAILABLE` | Suspended, closed, or not accepting the channel |
| `ITEM_NOT_AVAILABLE` | Store cannot sell one or more requested items |
| `OUT_OF_STOCK` | Inventory reservation rejected |
| `DELIVERY_NOT_SUPPORTED` | Delivery method/address not serviceable |
| `ACCEPTANCE_TIMEOUT` | Store decision did not arrive before the Trade deadline |
| `SYSTEM_ERROR` | Retry budget exhausted; operator action required |

Trade transitions the unpaid order to cancellation and records a user-safe reason. It
does not expose internal exception details.

### 7.5 Implemented Verification Event

**`store.order.verified.v1`** (Store → Trade) is implemented when `settings.fulfillment.requireVerification=true`.

```json
{
  "orderUuid": "trade-order-uuid",
  "storeOrderUuid": "store-order-uuid",
  "storeUuid": "store-uuid",
  "verificationCode": "PICKUP-OTP-123",
  "verifiedBy": "staff-user-uuid",
  "verifiedAt": "2026-09-03T08:00:00+00:00"
}
```

Published by `Store/Service/StoreOrderService::verify()` via `store_outbox_message`. `PublishOutbox` routes it as `StoreOrderVerifiedMessage` to `Trade/MessageHandler/StoreOrderVerifiedHandler`, which (inside one transaction) applies Trade `request_verification` if still in `fulfilled`, then `store_verify` to `completed`. Audit fields are `verifiedAt/verifiedBy/verificationCode` on `store_order` (see §5.3). Idempotent: `StoreOrderVerifiedHandler` checks store-uuid match and `workflow.can()`.

### 7.6 Future Events

The following remain reserved:

| Event | Publisher | Purpose |
|---|---|---|
| `trade.order.paid.v1` | Trade | Allow Store to begin preparation/fulfillment |
| `trade.order.refunded.v1` | Trade | Stop or reverse Store work where allowed |
| `store.order.fulfilled.v1` | Store | Tell Trade fulfillment completed, if Trade retains its workflow transition (currently Store `fulfill` is local only) |
| `inventory.reservation.confirmed.v1` | Inventory | Confirm Store-requested reservation |
| `inventory.reservation.rejected.v1` | Inventory | Reject Store-requested reservation |

`trade.order.cancelled.v1` is already implemented (Trade `OrderWorkflowListener` on `cancel`).

---

## 8. Consumer And Publisher Design

### 8.1 Trade Order Created Consumer

`TradeOrderCreatedConsumer` processes one message as follows:

```text
receive message
  -> validate envelope and schema version
  -> start Store local transaction
  -> insert StoreConsumedEvent(eventId), or return if already present
  -> find Store by payload.store.uuid
  -> create/find StoreOrder by tradeOrderUuid
  -> validate Store status, channel, local eligibility
  -> request inventory reservation (future boundary)
  -> set StoreOrder accepted or rejected
  -> insert StoreOutboxMessage for result event
  -> commit
  -> acknowledge broker message
```

The broker message is acknowledged only after the Store transaction commits. If the
process crashes before acknowledgement, the event is delivered again and becomes an
inbox/business-idempotent no-op.

### 8.2 Outbox Publisher

The outbox publisher is a transport adapter, not business logic:

```text
poll unpublished outbox messages in created order
  -> lock one batch
  -> publish envelope to transport topic
  -> mark publishedAt on broker acknowledgement
  -> on transient error: increment attempts and retry later
  -> on retry exhaustion: retain record and alert/DLQ
```

Initial deployment may use Symfony Messenger with Doctrine transport. The business
contract must not depend on that choice. RabbitMQ, Kafka, SQS, or an HTTP event relay
can replace the transport without changing StoreOrder business code.

### 8.3 Retry Classification

| Failure | Consumer action |
|---|---|
| Duplicate `eventId` | Acknowledge; no mutation |
| Duplicate `tradeOrderUuid`, same snapshot | Continue idempotently; ensure result event exists |
| Duplicate `tradeOrderUuid`, different snapshot | Do not overwrite; critical alert and DLQ |
| Temporary database/broker failure | Roll back and retry |
| Invalid event schema/version | DLQ; do not retry blindly |
| Business rejection such as out of stock | Commit rejected StoreOrder + rejection outbox event |
| Unexpected domain exception | Roll back; retry with bounded backoff, then DLQ |

### 8.4 Ordering And Concurrency

- The Store consumer serializes concurrent decisions by `tradeOrderUuid` through the
  unique StoreOrder key and transaction locking where necessary.
- An event topic should preserve ordering per aggregate key where the transport supports
  it, but correctness must not rely on global ordering.
- Store acceptance after a terminal Trade cancellation is harmless: Trade ignores the
  stale accept event and Store consumes `trade.order.cancelled.v1` to release work.
- All result handlers validate expected source state before applying transitions.

---

## 9. Store APIs

### 9.1 Public Store Discovery

| Method | Path | Auth | Purpose |
|---|---|---|---|
| GET | `/api/v1/app/stores` | Optional/ROLE_USER by channel | Discover selectable active stores |
| GET | `/api/v1/app/stores/{uuid}` | Optional/ROLE_USER by channel | Read store details and availability |

These endpoints do not create orders. The storefront uses the selected Store context
when calling the existing Trade quote/order APIs.

### 9.2 Customer Store Order Read View

| Method | Path | Auth | Purpose |
|---|---|---|---|
| GET | `/api/v1/app/store-orders/{uuid}` | ROLE_USER | Read Store operational information for own commercial order |

The controller resolves ownership through the `customerUserUuid` stored in StoreOrder.
It must not reveal operational notes, employee IDs, inventory internals, or internal
rejection diagnostics.

The API is a convenience read model; the customer still uses Trade order APIs for
payment, cancellation, refunds, and canonical commercial status.

### 9.3 Platform Store Administration

| Method | Path | Auth | Purpose |
|---|---|---|---|
| GET/POST/PUT | `/api/v1/manage/stores` | ROLE_ADMIN | Store CRUD; closure instead of delete |
| GET/POST/PUT | `/api/v1/manage/store-memberships` | ROLE_ADMIN | Assign/revoke Store memberships |
| GET | `/api/v1/manage/store-orders` | ROLE_ADMIN | Cross-store operational reporting |
| GET | `/api/v1/manage/store-orders/{uuid}` | ROLE_ADMIN | Operational detail |

### 9.4 Store Staff Operations

Store staff must not use generic Trade `manage/orders` routes, which are platform-wide
`ROLE_ADMIN` endpoints. Store provides scoped operational endpoints:

| Method | Path | Required membership | Purpose |
|---|---|---|---|
| GET | `/api/v1/store/manage/orders` | active member | Current store work queue |
| GET | `/api/v1/store/manage/orders/{uuid}` | active member | StoreOrder operational detail |
| POST | `/api/v1/store/manage/orders/{uuid}/accept` | owner/manager (`store:order:accept`) | Manual acceptance when `order.requireAcceptance=true` |
| POST | `/api/v1/store/manage/orders/{uuid}/reject` | owner/manager (`store:order:reject`) | Reject before payment with reason code |
| POST | `/api/v1/store/manage/orders/{uuid}/fulfill` | fulfillment/manager (`store:order:fulfill`) | Mark Store operation fulfilled (local) |
| POST | `/api/v1/store/{scopeId}/orders/{uuid}/verify` | fulfillment/manager/owner (`store:order:verify`) | Store verification post-fulfill — requires `fulfillment.requireVerification=true`, `operationalStatus=fulfilled`, body `{verificationCode}`; records `store.order.verified.v1` with audit `verifiedAt/verifiedBy/verificationCode` |

The first asynchronous acceptance implementation may not expose manual actions until
the timeout/escalation policy is ready. All actions check membership against the
StoreOrder's local Store relation, not a request-supplied store identifier.

---

## 10. Authorization Model

### 10.1 Roles

| Principal | Scope | Capabilities |
|---|---|---|
| `ROLE_ADMIN` | Platform | All Store administration and reporting |
| Store `owner` | One Store | Membership, store settings, acceptance, fulfillment |
| Store `manager` | One Store | Acceptance, rejection, fulfillment, operational views |
| Store `clerk` | One Store | Read operational queue; limited configured actions |
| Store `fulfillment` | One Store | Fulfillment-only operational actions |
| Customer | Own orders | Read customer-safe StoreOrder view only |

### 10.2 Authorization Rules

1. Platform roles live in Identity and retain their existing meaning.
2. Store roles live only in `Membership`.
3. A Store controller resolves the authenticated Identity user to a scalar `userUuid` and
   queries Store membership locally.
4. `ROLE_ADMIN` bypasses membership checks only on explicitly administrative routes.
5. Store staff cannot elevate a Trade order's commercial status directly.
6. Store staff actions produce Store events; Trade applies commercial transitions only
   through its own event consumers and workflow guards.

---

## 11. Store Context And Promotion Contract

Promotion currently uses a `storeCode` within Trade's price calculation context. The
Store bundle gives that value an authoritative source:

```text
request -> StoreContextResolver -> active Store -> StoreContext.storeCode
        -> Trade calculatePrices(..., storeCode, ...)
        -> Promotion storeCode filtering
```

Rules:

- The client cannot select an arbitrary promotion store by posting `storeCode`.
- Trade passes the resolver-generated code to the pricing pipeline.
- A Store snapshot is persisted with the order-created event so the Store consumer can
  validate that the order was priced for the intended Store.
- Promotion remains independent: it does not reference Store tables or entities.
- A global promotion remains represented by the existing empty store code convention;
  Store selection must never accidentally turn an unknown store into global pricing.

This design makes the existing `Promotion.storeCode` a routing input, not the Store
domain model or authorization mechanism.

---

## 12. Inventory Boundary

Inventory is deliberately excluded from the first Store data model. Store defines the
command/result boundary it needs:

```text
Store validates order
  -> inventory.reservation.requested.v1
  -> Inventory reserves quantity idempotently
  -> inventory.reservation.confirmed.v1
       or inventory.reservation.rejected.v1
  -> Store accepts/rejects StoreOrder
```

The future request payload must include:

- `reservationId`: Store-generated idempotency/reference UUID.
- `storeUuid`.
- `tradeOrderUuid` and `storeOrderUuid`.
- Item inventory keys, quantities, and immutable correlation IDs.
- Reservation expiry/deadline.

Store owns the decision to request reservation and keeps the resulting `reservationId`.
Inventory owns available quantity, reservation ledger, decrement/release, and stock
reconciliation. Neither module accesses the other's database.

---

## 13. Timeout And Compensation

### 13.1 Acceptance Timeout

Trade records a configurable acceptance deadline when it creates an order. A scheduled
Trade job finds orders still awaiting Store acceptance after the deadline and:

1. Applies the cancellation transition.
2. Writes `trade.order.cancelled.v1` to Trade outbox with reason `ACCEPTANCE_TIMEOUT`.
3. Store consumes cancellation, marks its pending operation cancelled, and asks future
   Inventory to release any reservation.

The job must be idempotent: terminal or already accepted orders are ignored.

### 13.2 Late Events

| Late event | Required behavior |
|---|---|
| Store accept after Trade timeout cancellation | Trade ignores it and logs correlation; Store releases work on cancellation event |
| Store reject after Trade cancellation | Trade ignores it; Store retains rejected audit record |
| Trade cancellation while Store awaits Inventory | Store marks cancellation pending and releases reservation when known |
| Payment event before acceptance | Trade rejects/flags as invariant breach; payment start must already be gated |

### 13.3 No Distributed Rollback

Once an event crosses an outbox boundary, a remote mutation cannot be rolled back.
Compensation is expressed as a new business event and local state transition, never a
cross-service database transaction.

---

## 14. Persistence And Migration Plan

### 14.1 Initial Store Migration

A Store migration creates only Store-owned tables:

```text
store
store_membership
store_order
store_outbox_message
store_consumed_event
```

It may include foreign keys from `store_membership.store_id` and `store_order.store_id`
to the local `store` table. It MUST NOT add foreign keys to `users`, `trade_order`,
`payment_invoice`, or future inventory tables.

### 14.2 Trade Migration

A separate Trade migration adds only generic order orchestration data required by the
new workflow, such as a Store snapshot in existing JSON metadata and any explicit
acceptance deadline field. It must not add a foreign key to Store.

### 14.3 Data Backfill

For existing orders without Store metadata:

- Treat them as legacy/global orders.
- Do not fabricate `StoreOrder` records unless a reliable external mapping exists.
- Existing payment/refund behavior remains unchanged.
- New Store acceptance workflow applies only to newly created Store-scoped orders.

---

## 15. Failure Handling And Observability

### 15.1 Error Categories

| Category | HTTP/Event behavior | Logging |
|---|---|---|
| Invalid Store context | Reject before Trade creates order | Warning with safe store hint |
| Store business rejection | Publish rejection event | Info with reason code/correlation |
| Event schema violation | DLQ | Error with event id/topic |
| Consumer transient failure | Retry | Warning with attempt count |
| Snapshot mismatch | Do not mutate existing StoreOrder | Critical alert |
| Outbox publishing failure | Retry/DLQ | Error with aggregate/event id |

### 15.2 Required Structured Log Fields

All asynchronous paths include:

```text
eventId, topic, aggregateId, correlationId, causationId,
tradeOrderUuid, storeOrderUuid, storeUuid, attempt
```

Logs must not contain customer phone/address details, payment secrets, raw gateway
payloads, or complete item snapshots unless explicitly redacted for secure diagnostics.

### 15.3 Operational Metrics

The target deployment exports at least:

- Outbox backlog count and oldest unpublished age.
- Consumer lag by topic.
- Acceptance/rejection count by Store and reason code.
- Acceptance latency from Trade order creation to Store decision.
- Timeout cancellation count.
- Inbox duplicate count.
- DLQ count and oldest message age.

---

## 16. Testing Contract

### 16.1 Unit Tests

| Suite | Required cases |
|---|---|
| `tests/Store/Entity/` | Store lifecycle, membership role/status, StoreOrder snapshots/statuses, outbox/inbox fields |
| `tests/Store/Service/` | Context resolution, membership authorization, Store acceptance/rejection rules |
| `tests/Store/Consumer/` | Valid event, duplicate event, duplicate business key, schema error, rejection event creation |
| `tests/Store/Outbox/` | Publish retry classification and event envelope serialization |

### 16.2 Integration Tests

| Scenario | Expected result |
|---|---|
| Active Store receives valid Trade event | One accepted StoreOrder and one accepted outbox event |
| Store is closed | Rejected StoreOrder and rejection outbox event |
| Same `eventId` redelivered | No second StoreOrder or result event |
| Different event ids with same Trade UUID/same payload | One StoreOrder; idempotent result behavior |
| Same Trade UUID/different payload | No overwrite; critical failure/DLQ |
| Store staff reads another store order | 404/403 without information leak |
| Customer reads another customer's StoreOrder | 404/403 without information leak |
| Trade receives Store acceptance twice | One legal workflow transition only |
| Trade acceptance timeout races Store acceptance | Exactly one terminal Trade outcome; stale event safely ignored |

### 16.3 Contract Tests

Event producers and consumers need contract fixtures stored independently of Doctrine
serialization. Tests validate:

- Required fields and scalar types.
- v1 event compatibility.
- Unknown optional field tolerance.
- Forbidden sensitive fields absent from payload.
- Stable canonical payload hashing for inbox diagnostics.

---

## 17. Implementation Phases

### Phase 1: Store Foundation

1. Create Store, Membership, StoreOrder, Outbox, and Inbox entities/repositories.
2. Add Store service interfaces and services.
3. Implement platform Store administration and membership management.
4. Implement StoreContext resolution for the selected channel.
5. Add Store discovery endpoints and Store-staff authorization tests.

### Phase 2: Trade Orchestration Contract

1. Add target Trade workflow states/transitions and payment gate.
2. Add server-generated Store snapshot to new Trade orders.
3. Add a generic Trade integration-event outbox abstraction.
4. Publish `trade.order.created.v1` only after the Trade transaction commits.
5. Return `202` for Store-scoped order creation.
6. Preserve legacy order behavior when no Store context is present, only if that legacy
   mode remains a product requirement.

### Phase 3: Store Consumer And Decision Events

1. Implement `TradeOrderCreatedConsumer` with inbox and business idempotency.
2. Create StoreOrder from immutable event snapshot.
3. Implement Store eligibility checks and accepted/rejected decisions.
4. Write `store.order.accepted.v1` / `store.order.rejected.v1` to Store outbox.
5. Implement Trade consumers that apply guarded workflow transitions.
6. Add timeout cancellation and late-event handling.

### Phase 4: Fulfillment And Inventory Integration

1. Define Inventory reservation event contracts.
2. Gate Store acceptance on reservation confirmation.
3. Add Store fulfillment operations and corresponding Trade event consumers where
   commercial workflow transitions remain Trade-owned.
4. Add cancellation/release compensation.

### Phase 5: Service Extraction Readiness

1. Move event schemas to a versioned contract package or JSON schema repository.
2. Replace in-process transport with a broker while retaining Outbox/Inbox behavior.
3. Move Store database and consumer independently.
4. Run consumer-driven contract tests in CI.
5. Add replay tooling and operational dashboards before enabling production extraction.

---

## 18. Acceptance Criteria

The Store bundle design is implemented when:

| Criterion | Requirement |
|---|---|
| Single order entry | No Store endpoint creates a commercial order; Trade remains the entry point |
| Store isolation | Store has no Doctrine association/FK to Trade, Payment, Identity, or Inventory |
| Context integrity | Store identity comes from a server-resolved StoreContext, not client body fields |
| Store projection | Exactly one StoreOrder is created per Trade order UUID, idempotently |
| Event isolation | Integration event payloads are versioned scalar/JSON contracts, never Doctrine entities |
| Reliable publication | Business mutation and local outbox write share one transaction |
| Reliable consumption | Inbox event-id uniqueness and StoreOrder business uniqueness protect retries |
| Payment gate | A Store-scoped order cannot begin payment before Store acceptance |
| Rejection/timeout | Trade cancels unpaid orders on Store rejection or acceptance timeout |
| Authorization | Store membership scopes Store operations; platform admin retains explicit override |
| Inventory boundary | Store has reservation contract hooks but no embedded inventory ledger |
| Extraction readiness | Store can move to a separate database/consumer with no cross-schema joins or transactions |
