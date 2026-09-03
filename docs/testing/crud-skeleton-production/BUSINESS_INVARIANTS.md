# CRUD Skeleton Business Invariants

Each invariant is an observable rule. The listed locations are the normal
starting point for regression tests; a new critical invariant must be added here
when it is introduced.

| Domain | Invariant | Typical evidence |
|---|---|---|
| Transactions | A successful multi-write operation commits all required changes; a failure leaves no partial durable state. | service integration rollback test |
| Authorization | Anonymous users have only explicitly public access. App users see only their own resources; store staff and admins remain within their declared scope. | HTTP allow/deny and cross-owner tests |
| Identity | Refresh-token rotation revokes the old token, and reuse is detected. Password/OTP failures do not issue a valid session. | `tests/UnitTest/Identity/` and `tests/Integration/Identity/` security and integration tests |
| Dynamic query | User-provided filter, sort, select, expand, and DQL input cannot bypass the controller's `commonFilter()` scope. | Core query API integration tests |
| Orders | Only configured workflow transitions occur. An order item retains its product/specification snapshot after creation. | Trade workflow and order integration tests |
| Money | Values are represented in integer cents; an operation never creates or destroys balance except through an auditable transaction, adjustment, or reconciliation. | Wallet and Payment integration tests |
| Wallet idempotency | A repeated request with the same reference identifier returns/uses the original transaction and does not move money twice. | transfer/deposit duplicate tests |
| Payments | Gateway payment/refund amounts are explicit and never exceed the invoice business amount after valid adjustments. Callback replay cannot settle twice. | Payment adjustment, gateway, and webhook tests |
| Promotions | Eligible promotion evaluation is deterministic for the same order context and cannot cross store, time, member, or SKU boundaries. | promotion pipeline integration tests |
| Messaging | An emitted integration event is recorded in the same transaction as its source change; consumers process an event identity at most once. | outbox/inbox integration tests |
| Inventory | When enabled, a confirmed reservation has ledger-backed, non-negative reserved quantities unless that stock explicitly allows negatives. Release is idempotent. | Inventory service and handler tests |
| Storage | An app user cannot delete another user's media. A failed physical-store operation does not leave a falsely persisted media record. | media ownership/upload integration tests |
| Internationalization | Explicit locale selection takes precedence over `Accept-Language`; unknown input falls back to the configured default without breaking API response shape. | locale HTTP integration tests |

## Regression Rule

Every escaped production issue creates a permanent, focused regression test that
would have failed before the fix. The test must assert the public or persisted
outcome, not merely that the repaired implementation method was called.

Inventory remains disabled by default and is not approved for production use
until the production-readiness conditions in `docs/ai/context.md` are resolved
and verified with production-like concurrency tests.
