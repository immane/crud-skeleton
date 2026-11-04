# Trade Runbook

This runbook is the operational guide for configuring and operating the Trade
module (products, specifications, orders, pricing). It complements
[`design/bundles/trade.md`](../design/bundles/trade.md).

## 1. Concepts In One Minute

- **Product** → **Specification** (SKU) → **OrderItem** → **Order**.
- Orders follow a state machine: `draft → pending → confirmed → paid → fulfilled → completed`.
- Pricing runs through a **calculator pipeline** (priority-ordered).

## 2. Pricing Pipeline

Pricing is computed by a chain of calculators. Each calculator is tagged and ordered by
priority. The pipeline produces unit prices, line amounts, and totals.

| Calculator | Purpose |
|-----------|---------|
| `BasePriceCalculator` | Base unit price from specification |
| `QuantityCalculator` | Line amount = unit price × quantity |
| `TotalAggregator` | Order total |

Promotion adds a `trade.price_calculator` at priority 60.

## 3. Order State Machine

```
draft → pending → confirmed → paid → fulfilled → completed
                          ↘ cancelled
                          ↘ refunded
```

Transitions are enforced by Symfony Workflow. Use the transitions endpoint to discover
available moves.

## 4. Trade Endpoints

| Method | Path | Description |
|--------|------|-------------|
| GET | `/api/v1/app/products` | List active products |
| GET | `/api/v1/app/specifications` | Browse active specs |
| GET | `/api/v1/app/orders` | List user's orders |
| GET/POST/PUT/DELETE | `/api/v1/manage/products[/{id}]` | Product CRUD |
| GET/POST/PUT/DELETE | `/api/v1/manage/specifications[/{id}]` | Specification CRUD |
| POST | `/api/v1/manage/orders` | Create order (with pricing) |
| POST | `/api/v1/manage/orders/quote` | Price preview |
| POST | `/api/v1/manage/orders/{id}/do/{transition}` | Execute transition |
| POST | `/api/v1/app/orders/{id}/payment` | Start order payment |

## 5. Money Handling

Order amounts are stored in **cents** (integer). Never use floats for money. The pricing
pipeline and payment integration both operate on integer minor units.

### Authenticated third-party payment

The global Common Setting `payment.allowThirdPartyPayment` controls whether a
signed-in user may start payment for another user's Trade order through the app
order payment endpoint. It does not grant anonymous access or order-management
permissions. Only the exact combination `type = boolean`, `value = true` enables
the policy; missing, null, disabled and malformed settings fail closed. Values
are stored as strings, not PHP booleans.

Migration `Version20261104090000` creates the setting, if missing, with value
`false`, group `payment`, and label `Allow third-party payment`. It never
overwrites an existing value; rollback preserves operator configuration. Apply
migrations through the normal reviewed deployment process. Administrators can
then edit the setting using existing Common Setting management. No database
schema change or frontend customization is required. A missing key has the same
disabled behavior before migration, and the value is read for each non-owner
payment request rather than cached as an authorization decision.

When enabled, `POST /api/v1/app/orders/{id}/payment` accepts a confirmed order's
numeric ID or UUID from a logged-in third party. Ownership filtering for lists,
details, items, submission, confirmation, cancellation and refunds is unchanged.
The existing Invoice app endpoints remain payer-scoped. The option does not
bypass payment/workflow guards or provider-specific initiation requirements;
in particular, it does not turn an authenticated Store payment adapter into a
public Huifu checkout endpoint.

The new Invoice's payer is the authenticated user, while `Order.user` remains
the original owner. Wallet payments therefore debit the actual payer; provider
adapters that resolve identity from `invoice.payer` also see that payer. The
request body cannot select another payer. Non-owner payment responses project
only Invoice identity/status/amount and payment-launch results; new third-party
Invoices do not copy the owner's private order notes into their description.
Owner payment responses retain their existing shape.

A pending Invoice is reused only for the same payer. A payer change creates a
new Invoice instead of reassigning the old one. Existing non-pending-Invoice
replacement behavior, including a previous `paying` Invoice, is unchanged: the
old record and provider transaction are not closed or cancelled automatically.
This feature does not add duplicate-collection handling or fix stale Invoice
event propagation; those are separate concerns when allowing multiple payment
attempts.

For trusted server integrations, the existing `createPayment()` signature is
unchanged. Its options may contain the internal `_payer` key with an actual
`User` object supplied after caller authorization; non-User values are rejected
and the key is removed before gateway invocation. Omission preserves the
historical order-owner payer behavior. The app controller always overwrites
this key with the authenticated user. This service context is not itself an
authorization API, and callers must not construct it from untrusted payer IDs.

## 6. Troubleshooting

| Symptom | Likely cause | Fix |
|---------|--------------|-----|
| Order cannot transition | Wrong current state | Check state machine and transitions endpoint |
| Price wrong | Calculator order or promotion conflict | Review pipeline priority and promotion phase |
| Spec not found | Inactive or deleted | Check specification status |

## 7. Checklist Before Going Live

- [ ] Products and specifications created and active.
- [ ] Pricing pipeline calculators ordered correctly.
- [ ] Order state machine transitions verified.
- [ ] Payment integration configured (see Payment runbook).
