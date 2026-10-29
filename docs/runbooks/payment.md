# Payment Runbook

This runbook is the operational guide for configuring and operating the Payment
module (invoices, gateways, webhooks, adjustments). It complements
[`design/bundles/payment.md`](../design/bundles/payment.md).

## 1. Concepts In One Minute

- An **Invoice** is the unit of payment, with a state machine
  (`pending → paying → paid`, or `failed`/`cancelled`/`refunded`).
- A **gateway** (`mock`, `wallet`, `wechat`) executes the actual payment.
- **Webhooks** (`/api/payment/notify/{payment}`) deliver provider callbacks.
- **Adjustment providers** reduce the amount a gateway must handle (e.g. wallet balance).

## 2. Configuration

**File**: `config/packages/payment.yaml`

```yaml
payment:
    default_currency: 'CNY'
    system_wallet_id: 0
    gateways:
        mock:
            enabled: true
        wallet:
            enabled: true
    adjustments:
        wallet_balance:
            enabled: true
```

Sensitive provider values (merchant IDs, keys, notify URLs) MUST come from environment
variables and MUST NOT be committed.

## 3. Gateways

| Gateway | Purpose | Enabled by |
|---------|---------|-----------|
| `mock` | Local testing | `payment.gateways.mock.enabled` |
| `wallet` | Pay from wallet balance | `payment.gateways.wallet.enabled` |
| `wechat` | WeChat Pay V3 | `payment.gateways.wechat.enabled` + WeChat env vars |

Gateways are auto-discovered via the `payment.gateway` tag. To add a gateway, implement
`PaymentGatewayInterface` and register it; no manual wiring is needed.

## 4. Payment Endpoints

| Method | Path | Description |
|--------|------|-------------|
| GET | `/api/v1/app/invoices` | List user's invoices |
| POST | `/api/v1/app/invoices/{id}/pay/{payment}` | Pay invoice via gateway |
| GET | `/api/v1/manage/invoices` | Admin list invoices |
| POST | `/api/v1/manage/invoices` | Create invoice |
| POST | `/api/v1/manage/invoices/{id}/pay/{payment}` | Admin pay invoice |
| POST | `/api/v1/manage/invoices/{id}/cancel` | Cancel unpaid invoice |
| POST | `/api/v1/manage/invoices/{id}/refund` | Refund paid invoice |
| POST | `/api/payment/notify/{payment}` | Provider callback (webhook) |

## 5. Refund safety and recovery

Invoice changes use source-owned `InvoiceSourcePolicyInterface` adapters to lock
the source before the invoice. Trade's `OrderInvoiceSourcePolicy` locks and refreshes
the linked order; Payment itself has no Trade dependency. Custom sources that need
aggregate coordination must register their own `payment.invoice_source_policy`.
When constructing `InvoiceService` manually, supply the appropriate source policies
explicitly; a source without a policy only locks its invoice. Refund requests are persisted in
`payment_invoice_refund_attempt` before calling a gateway. A completed request is
idempotent; concurrent or uncertain requests for the same invoice block a different
refund until the original request is resolved. Refund must be called outside any
existing database transaction, so its reservation commits before external effects.
Callers must restructure workflows that currently wrap refund in a transaction.

Refund attempt lookups use locking current reads inside mutation transactions,
including on MySQL `REPEATABLE READ`; refreshing an entity with an ordinary read
does not advance an existing snapshot. Trade invoice refunds require the locked
order to remain paid and linked to that invoice before reserving or resuming a
refund. Completed attempts can still be replayed after the order is refunded.
The durable `processing`/`unknown` attempt blocks the Trade `fulfill` workflow
transition while gateway calls run outside the reservation transaction. Fulfillment
mutations must hold the order-row lock and recheck the workflow in the same
transaction (including generic workflow endpoints). Unknown attempts keep blocking
fulfillment until reconciled; do not delete the attempt to release this barrier.

Clients should pass a stable `idempotencyKey` in the refund request body and reuse it
for retries. If omitted, the service derives a key from the amount and reason, which
means an intentional later refund with the same amount and reason must use a distinct
explicit key. Gateways must honor the supplied `refundIdempotencyKey` or provide
equivalent provider-side idempotency. Do not retry an uncertain refund with a new key.

An attempt left in `processing` cannot be taken over automatically, even after a
timeout: the original worker may still be running. Reconcile abandoned attempts
only after confirming that the worker has stopped and checking the provider and
any adjustment records. The original gateway and adjustment allocation is recorded
on new attempts. An `unknown` attempt without adjustments can be retried with the
same key; the gateway must return the original refund result rather than issue another
transfer. An uncertain adjusted attempt, or a legacy attempt without an allocation,
must be reconciled manually, even with the same key: an adjustment may already have
been refunded or partially refunded. A finalization failure can leave `processing`
and requires the same investigation.
If a provider cannot guarantee this behavior, reconcile the provider dashboard and
the `payment_invoice_refund_attempt` row manually before issuing any new refund.
Never delete an uncertain attempt to unblock another refund: that removes the
idempotency barrier and can permit a duplicate payout.

## 6. Webhook Setup

1. Configure the provider's notify URL to point at
   `https://<host>/api/payment/notify/{payment}`.
2. Ensure the endpoint is reachable from the provider (no auth on the webhook).
3. Verify the provider signs the callback and the gateway validates the signature.

Synchronous payment completion reuses the locks held by `pay()` and preserves
both gateway-written invoice context and the sanitized `pay` payload. External
notifications still refresh under the source/invoice locks before validating state.

When another invoice is paid after an order is already paid, fulfilled, completed,
or refunded, Trade preserves the original invoice link, payment method, status,
and payment timestamp. Investigate the critical log containing both invoice
identities and reconcile the extra payment using the provider records. An illegal
`pay` transition likewise leaves order fields unchanged; the paid invoice and log
retain evidence rather than forcing a status or replacing the association.

## 7. Wallet Balance Adjustment

When `payment.adjustments.wallet_balance.enabled=true`, the wallet balance is deducted
first; the gateway only handles the remaining invoice amount. The `system_wallet_id`
must point to a valid system wallet.

## 8. Troubleshooting

| Symptom | Likely cause | Fix |
|---------|--------------|-----|
| Gateway not found | Gateway disabled or not registered | Check `payment.gateways.*.enabled` |
| Webhook ignored | Signature mismatch or wrong notify URL | Verify provider config and signature |
| Amount mismatch | Adjustment double-applied | Check `PaymentDeduction` audit records |
| Refund rejected | Invoice not in refundable state | Check invoice state machine |
| Refund result uncertain | Gateway timed out or local finalization failed | Retry only an unadjusted `unknown` attempt with the same key and an idempotent gateway; otherwise reconcile provider, adjustments and attempt row |

## 9. Checklist Before Going Live

- [ ] `default_currency` and `system_wallet_id` set.
- [ ] Required gateways enabled.
- [ ] Provider notify URL configured and reachable.
- [ ] Sensitive provider values in env, not committed.
- [ ] Adjustment providers enabled as intended.
