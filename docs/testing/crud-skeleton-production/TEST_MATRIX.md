# CRUD Skeleton Test Matrix

This matrix is maintained when a module or business path changes. Tests are
organized by layer (`tests/UnitTest/{Module}/`, `tests/Integration/{Module}/` or
`tests/Integration/*` helpers, `tests/LowValue/` excluded) — paths below are
representative ownership locations, not an exhaustive inventory, and use
layer-first globs to remain stable after the layer re-organization. Historical
`tests/{Module}/` paths from the 2026-08-09 audit are retained only in that audit.

| Area | Primary risk | Required validation | Current owning tests / scripts |
|---|---|---|---|
| Core API framework | inconsistent response, exception, locale, pagination, or query semantics | unit plus HTTP integration for public behaviour | `tests/UnitTest/Core/`, `tests/Integration/Core*`, `tests/Integration/ApiRegressionTest.php` |
| Identity | token misuse, account takeover, cross-user access | token lifecycle, auth failure, role and owner-boundary HTTP tests | `tests/UnitTest/Identity/`, `tests/Integration/Identity/`, `tests/Integration/TokenRevocationIntegrationTest.php` |
| Common CMS and storage | unauthorized media/content access, upload cleanup failure | owner/public/admin scope, validation, storage-driver failure tests | `tests/UnitTest/Common/`, `tests/Integration/Common/`, `tests/UnitTest/Storage/` |
| Trade and promotions | wrong price, invalid order lifecycle, incorrect snapshot | pricing table cases, quote/order integration, valid and invalid workflow transitions | `tests/UnitTest/Trade/`, `tests/UnitTest/Promotion/`, `tests/Integration/Trade/`, `tests/Integration/Promotion/` |
| Wallet | lost or duplicated money, balance drift | atomic transfer/deposit, idempotency, pessimistic lock (`SELECT ... FOR UPDATE`) + manual `version` bump and reconciliation tests | `tests/UnitTest/Wallet/`, `tests/Integration/Wallet/` |
| Payment | incorrect gateway amount, duplicate callback, wrong order update | gateway/adjustment contract, invoice state, webhook and Trade integration tests | `tests/UnitTest/Payment/`, `tests/Integration/Payment/`, `tests/Integration/PaymentTradeIntegrationTest.php` |
| Store | store isolation, wrong staff authority, delayed acceptance/rejection | scoped HTTP flow, outbox/inbox delivery, cancellation ordering tests | `tests/UnitTest/Store/`, `tests/Integration/Store/`, `scripts/tests/store-smoke.sh` |
| Inventory | oversell, leaked reservation, wrong compensation | stock/reservation integration, duplicate/out-of-order handler tests, concurrency checks before enablement | `tests/UnitTest/Inventory/`, `tests/Integration/Inventory/`, `scripts/tests/inventory-smoke.sh` |
| Messaging | duplicated, lost, or out-of-order cross-module effects | producer transaction, consumer idempotency, retry and ordering tests | `tests/UnitTest/*/MessageHandler/`, `tests/Integration/*MessageHandler/`, `tests/UnitTest/*/Command/` |
| Schema and portability | mapping/query/migration fails outside local SQLite | CI PostgreSQL schema setup; MySQL-compatible staging rehearsal for DB-specific changes | CI `tests` job; release checklist |
| Public API contract | deployed app cannot complete a critical journey | disposable-environment smoke tests | `scripts/tests/api-smoke.sh`, Store/Inventory smoke scripts |

## Critical Paths

The following paths require both success and meaningful failure/denial coverage
when changed:

1. Register/login, refresh rotation, logout/revocation, and access control.
2. Owner, store-staff, public, and admin data boundaries.
3. Product/specification quote, order creation, state transition, cancellation,
   fulfillment, and refund.
4. Invoice creation, adjustments, payment, callback, refund, and Trade result
   propagation.
5. Wallet deposit, transfer, deduction, reconciliation, and duplicate reference
   handling.
6. Trade, Store, and Inventory outbox/inbox delivery, duplicate delivery,
   cancellation-before-create, and compensation.
7. Media upload/delete and physical-storage failure cleanup.

## Test Selection

| Change characteristic | Tests that must be added or updated |
|---|---|
| New API endpoint or payload field | success, validation failure, authorization/scope, and response contract |
| New workflow transition | legal path, illegal path, idempotency/repeat behaviour, and side effects |
| Money, price, tax, discount, or balance calculation | boundary values, rounding/cent representation, negative/zero rejection, and integration result |
| Async event | producer transaction, duplicate handling, ordering/cancellation race, and retry/failure |
| Query/filter/expand feature | parser/compiler result, authorization filter composition, and malformed-input failure |
| Migration | existing-data upgrade, fresh schema, and staging target-database rehearsal |

Coverage is never used to waive an item in this matrix.
