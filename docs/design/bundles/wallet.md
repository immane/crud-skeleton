# Wallet Bundle Design

> The Wallet bundle (`src/Wallet/`) provides user wallets, transactions, atomic
> wallet-to-wallet transfers with deadlock prevention and idempotency,
> **system deposits**, **balance verification**, **reconciliation**, and
> **wallet balance payment adjustments** for Payment invoices.

---

## 1. Overview

Wallet is a financial module for managing user balances:

- **Wallets** per user per currency with balance in cents, pessimistic locking (`SELECT ... FOR UPDATE`) + manual `version` counter (no `#[ORM\Version]`), freeze capability, and available/held balance separation
- **Transactions** with UUID, type classification, status tracking, and idempotency via `referenceId`
- **TransferService** with atomic from-wallet-to-wallet transfers, deadlock prevention, and rollback recovery
- **Deposit** endpoint for system-injected funding with audit trail
- **Balance verification** — `GET /api/v1/manage/wallets/balance` checks invariant: `SUM(wallets) == SUM(deposits + adjustments)`
- **Reconciliation** — `POST /api/v1/manage/wallets/reconcile` fixes per-wallet gaps
- **Payment adjustment provider** — deducts wallet balance before an external Payment gateway handles the remaining invoice amount

### 1.1 Accounting Model

```
deposit (TYPE_DEPOSIT)     → injects money from system (one-sided credit)
adjustment (TYPE_ADJUSTMENT) → reconciliation fix (one-sided credit)
transfer (TYPE_TRANSFER)   → zero-sum between wallets (debit + credit)
```

**Invariant**: `SUM(all wallet balances) == SUM(all deposit + adjustment transactions)` at all times.

### 1.2 Entities

| Entity | Table | Purpose |
|--------|-------|---------|
| `Wallet` | `wallet` | User balance per currency (cents), freeze support, optimistic locking |
| `Transaction` | `wallet_transaction` | Record of deposit/withdrawal/transfer/fee/refund/**adjustment** |
| `PaymentDeduction` | `wallet_payment_deduction` | Wallet-owned audit record for invoice wallet balance deductions |

---

## 2. File Structure

```
src/Wallet/
|-- Controller/Manage/
|   |-- TransactionController.php        # CRUD for transactions
|   |-- TransferController.php           # Transfer + Deposit endpoints
|   |-- WalletController.php             # CRUD + Balance + Reconcile
|-- Entity/
|   |-- Wallet.php
|   |-- Transaction.php
|   |-- PaymentDeduction.php       # Payment adjustment audit record
|-- Exception/
|   |-- InsufficientFundsException.php
|   |-- SameWalletTransferException.php
|   |-- WalletFrozenException.php
|-- Repository/
|   |-- WalletRepository.php             # + getTotalBalance()
|   |-- TransactionRepository.php  # + getTotalDeposited(), getExpectedBalance()
|   |-- PaymentDeductionRepository.php
|-- Service/
    |-- Payment/
    |   |-- WalletBalanceAdjustmentProvider.php # Implements Payment adjustment provider
    |   |-- WalletGateway.php                    # Implements Payment gateway
    |-- TransactionService.php
    |-- TransferResult.php               # Transfer result DTO
    |-- TransferService.php              # Core transfer + deposit logic
    |-- TransferServiceInterface.php     # Transfer + deposit contract
    |-- WalletService.php                # + verifyBalance(), reconcile()
    |-- Withdraw/
    |   |-- WithdrawService.php          # Voucher-backed withdrawal + reversal
    |   |-- WithdrawServiceInterface.php # Withdrawal contract
    |   |-- WithdrawProviderInterface.php
    |   |-- WithdrawProviderRegistry.php
    |   |-- ManualWithdrawProvider.php
```

---

## 3. Entity Design

### 3.1 Wallet

| Field | Type | Detail |
|-------|------|--------|
| `id` | int | Auto-increment PK |
| `user` | ManyToOne -> User | Wallet owner |
| `currency` | string(32) | Unit of account code (see below) |
| `balance` | int (bigint) | **Total** balance in cents (`balance = available + held`) |
| `held` | int (bigint) | Frozen amount in cents; available balance = `balance - held` |
| `version` | int | Manual version counter (`SET version = version + 1` under `FOR UPDATE` lock); no `#[ORM\Version]` |
| `status` | string | `active` or `frozen` |
| `label` | string | Human-readable wallet name |

**⚠️ No `setBalance()` / no public `held` setter** — Wallet balance and held can ONLY be altered through `TransferService` (transfer, deposit, hold, release, reconcile). This prevents direct mutation that would bypass the audit trail.

**Available vs held**: `balance` is the total; `held` is funds frozen (escrow, pending withdrawal). `available = balance - held`. `TransferService::transfer()` and `hold()` operate against available balance only. Holds do NOT change the total balance and do NOT write a ledger row — a hold moves funds between the available and held buckets internally, so reconciliation (`SUM(balance) == deposits - withdrawals`) is unaffected. The audit record for a hold belongs to the owning lifecycle entity (e.g. `WalletWithdrawal`), not the ledger.

**Unique constraint**: `(user_id, currency)` -- one wallet per user per unit of account.

**Unit of account codes**: `currency` is the account discriminator, not a strict ISO 4217 code. A plain ISO code (e.g. `CNY`, `USD`) identifies the default balance wallet; extended codes (e.g. `CNY.ESCROW`, `CNY.COMMISSION`, `POINTS`) identify category accounts such as escrow, commission, or points. The default balance wallet MUST use the plain ISO code — invoice payment resolution (`findByUserAndCurrency(payerId, invoice.currency)`) relies on this and must never match a category account. If FX or per-currency reporting is needed later, introduce an explicit base-currency column rather than parsing these codes.

**Methods**: `isActive(): bool`, `isFrozen(): bool`

### 3.2 Transaction

| Field | Type | Detail |
|-------|------|--------|
| `id` | int | Auto-increment PK |
| `uuid` | string(32) | UUID v4c for external reference |
| `fromWallet` | ManyToOne -> Wallet (nullable) | Source wallet |
| `toWallet` | ManyToOne -> Wallet (nullable) | Destination wallet |
| `amount` | int (bigint) | Amount in cents |
| `type` | string | `deposit`, `withdrawal`, `transfer`, `fee`, `refund`, `adjustment`, **`credit_reversal`**, **`debit_reversal`** |
| `status` | string | `pending`, `completed`, `failed`, `reversed` |
| `referenceId` | string (unique) | Idempotency key |
| `description` | string | Human-readable note |
| `metadata` | JSON | Extensible data |

**Unique constraint**: `referenceId` -- prevents duplicate transactions.

**Methods**: `markCompleted()`, `markFailed()`

**Single-sided semantics (contract)**: `fromWallet`/`toWallet` nulls are meaningful, not bugs.

| Movement shape | Meaning |
|----------------|---------|
| `fromWallet = wallet`, `toWallet = wallet` | Internal transfer (zero-sum, both sides present) |
| `fromWallet = null`, `toWallet = wallet` | **Single-sided credit** — funds enter the wallet system from outside, backed by a `wallet_voucher` (deposit) |
| `fromWallet = wallet`, `toWallet = null` | **Single-sided debit** — funds leave the wallet system (withdrawal, or `credit_reversal` of a deposit) |

A deposit MUST write `fromWallet = null`; a reversal/withdrawal MUST write `toWallet = null`. A transfer MUST have both sides. Anything else is a contract violation. `credit_reversal` reverses a credit (deposit) with a single-sided debit; `debit_reversal` reverses a debit (withdrawal) with a single-sided credit.

**Two-layer ledger (contract)**: wallet movements and boundary events are recorded separately and must not be conflated.

| Layer | Table | Answers | Read by |
|-------|-------|---------|---------|
| Internal (movement journal) | `wallet_transaction` | How each wallet's balance changed | `getExpectedBalance(walletId)` — per-wallet reconciliation |
| Boundary (provenance) | `wallet_voucher` | Why funds entered/left the system (`fund_source`, `created_by`, reversal state) | Boundary invariant — `verifyBalance()` |

Both a `wallet_transaction` AND a `wallet_voucher` are written for a boundary event. The transaction feeds the unified per-wallet movement journal (internal transfers have no voucher, so the journal cannot be built from vouchers alone); the voucher feeds provenance and the boundary invariant. Removing either breaks the corresponding audit surface.

### 3.3 PaymentDeduction

Wallet balance deduction for Payment invoices is owned by Wallet because the operation is implemented through wallet balance transfers and reversals.

**Table**: `wallet_payment_deduction`

**Purpose**: record a wallet balance amount applied to a Payment invoice before the selected gateway processes the remaining amount.

| Field | Type | Detail |
|-------|------|--------|
| `id` | int | Auto-increment PK |
| `uuid` | string(36) | Public stable identifier |
| `invoiceId` | string(36) | Payment invoice uuid, stored as a lightweight cross-module reference |
| `invoiceNo` | string(64) | Payment invoice `outTradeNo` for lookup/debugging |
| `payerId` | int | User id of the payer at deduction time |
| `wallet` | ManyToOne -> Wallet | Payer wallet used for deduction |
| `systemWalletId` | int | System wallet id that receives deducted funds |
| `amount` | int | Deduction amount in cents |
| `currency` | string | Must equal invoice currency and wallet currency |
| `status` | string | `pending`, `applied`, `released`, `refunded`, `failed` |
| `walletTransactionId` | ?string | Transfer transaction uuid after deduction is applied |
| `reversalTransactionId` | ?string | Transfer transaction uuid after release/refund |
| `referenceId` | string | Idempotency key for the apply transfer |
| `metadata` | JSON | Sanitized operational metadata |
| `createdAt` | DateTimeImmutable | Creation timestamp |
| `appliedAt` | ?DateTimeImmutable | Deduction applied timestamp |
| `releasedAt` | ?DateTimeImmutable | Unpaid deduction release timestamp |
| `refundedAt` | ?DateTimeImmutable | Paid invoice refund timestamp |

**No ORM relation to Payment invoice**: Wallet stores `invoiceId` and `invoiceNo` as scalar references. This avoids a hard persistence dependency from Wallet to Payment entities while still allowing audit and lookup.

**Unique constraints**:

| Constraint | Requirement |
|------------|-------------|
| `uuid` | Unique |
| `referenceId` | Unique |
| `invoiceId` | Unique for first-phase one wallet deduction per invoice |

**Status transitions**:

```text
pending -> applied -> released
pending -> failed
applied -> refunded
```

`released` means the invoice was not paid and the deducted wallet balance was returned. `refunded` means the invoice was paid and later fully refunded.

---

## 4. TransferService — Atomic Transfer + Deposit

### 4.1 Contract

```php
interface TransferServiceInterface
{
    public function transfer(int $fromWalletId, int $toWalletId, int $amount,
        ?string $referenceId = null, ?string $description = null): TransferResult;

    public function deposit(int $toWalletId, int $amount,
        ?string $referenceId = null, ?string $description = null): TransferResult;
}
```

### 4.2 deposit() — System Funding

```
deposit(toWalletId, amount, referenceId, description)
  |
  v
1. Idempotency Check (via referenceId)
  |
  v
2. Lock target wallet (SELECT ... FOR UPDATE)
  |
  v
3. Validation
   -> amount > 0
   -> wallet exists
   -> wallet not frozen
  |
  v
4. Execute (within DB transaction)
   -> beginTransaction()
   -> DQL UPDATE: toWallet.balance += amount
   -> Create Transaction (type=deposit, fromWallet=null)
   -> commit()
   |
   -> On failure: rollback(), EM recovery
  |
  v
5. Return TransferResult (fromWalletBalance=0)
```

### 4.3 Transfer Algorithm

```
transfer(fromWalletId, toWalletId, amount, referenceId, description)
  |
  v
1. Idempotency Check
  |
  v
2. Lock Wallets (Deadlock Prevention)
   -> Sort wallet IDs ascending
   -> SELECT ... FOR UPDATE on both wallets (in sorted order)
   -> This guarantees consistent lock ordering across concurrent transfers
  |
  v
3. Validation
   -> fromWallet != toWallet (SameWalletTransferException)
   -> fromWallet exists, toWallet exists
   -> fromWallet->isFrozen() || toWallet->isFrozen() (WalletFrozenException)
   -> fromWallet->getBalance() >= amount (InsufficientFundsException)
   -> Currency match: fromWallet->getCurrency() == toWallet->getCurrency()
  |
  v
4. Execute (within DB transaction)
   -> beginTransaction()
   -> DQL UPDATE: fromWallet.balance -= amount
   -> DQL UPDATE: toWallet.balance += amount
   -> transaction->markCompleted()
   -> commit()
   |
   -> On failure: rollback(), transaction->markFailed()
   -> EM recovery: if !$em->isOpen(), recreate EM
  |
  v
5. Return TransferResult
   -> Transaction entity + post-transfer balances
```

### 4.4 TransferResult DTO

```php
class TransferResult
{
    public Transaction $transaction;
    public int $fromWalletBalanceAfter;  // 0 for deposits
    public int $toWalletBalanceAfter;    // Post-operation
}
```

### 4.5 WithdrawService — Voucher-Backed Withdrawal

`WithdrawService` is the mirror of deposit: the single gate for funds **leaving** the wallet system. A withdrawal writes a **single-sided debit** (`fromWallet = wallet`, `toWallet = null`) backed by a **`DIRECTION_DEBIT` voucher**. Reversal is its mirror — a **single-sided credit** (`fromWallet = null`, `toWallet = wallet`, type `debit_reversal`) returning the funds to the source wallet.

```
withdraw(voucherType, voucherId, walletId, amount, currency, referenceId, createdBy, reason)
  |
  v
1. Validation
   -> amount > 0, referenceId required
   -> Idempotency: findByReferenceId -> return existing voucher
   -> Duplicate source: findByVoucherSource -> reject
   -> Provider whitelist: withdrawRegistry.forVoucherType -> reject unsupported type
  |
  v
2. Within DB transaction (wrapInTransaction)
   -> Lock wallet (SELECT ... FOR UPDATE)
   -> Wallet exists, not frozen, currency matches
   -> available balance >= amount (InsufficientFundsException)
   -> provider.authorize(voucher, options)
   -> DQL UPDATE: wallet.balance -= amount
   -> Create Transaction (type=withdrawal, fromWallet=wallet)
   -> Voucher DIRECTION_DEBIT -> markApplied(tx.uuid)
```

```
reverse(voucherUuid, reason)
  |
  v
1. Voucher must be APPLIED + DIRECTION_DEBIT
2. Within DB transaction
   -> Lock wallet
   -> DQL UPDATE: wallet.balance += amount
   -> Create Transaction (type=debit_reversal, toWallet=wallet)
   -> voucher->markReversed(tx.uuid, reason)
3. provider.reverse(voucher, reason) hook
```

Both operations are atomic (rollback + EM recovery on failure) and idempotent by `referenceId`. The provider tag is `wallet.withdraw_provider`; `ManualWithdrawProvider` is the built-in no-op authorization.

**Permission control is provider-owned.** Each provider implements `assertPermitted(array $options)` and may deny with `AccessDeniedException` (mapped to HTTP 403). The Manual providers require `ROLE_ADMIN`; with no active security context (CLI/queue/system invocation) the call is treated as a trusted internal caller and allowed. External providers implement their own rules.

---

## 5. WalletService — Balance Verification + Reconciliation

### 5.1 verifyBalance()

Checks the accounting invariant:
```
SUM(all wallet balances) == SUM(all deposit + adjustment transactions)
```

Returns `{totalBalance, totalDeposited, discrepancy, matches, walletCount}`.

### 5.2 reconcile()

Per-wallet reconciliation:

1. For each wallet, compute `expected = SUM(credits) - SUM(debits)` from transaction history
2. Compare actual balance against expected
3. If `actual > expected`: create `TYPE_ADJUSTMENT` deposit (acknowledge legacy balance)
4. If `actual < expected`: report as `skipped_negative` (requires manual review)
5. **Does NOT touch wallet balances** — only creates adjustment transaction records
6. **Idempotent** — re-running when books are balanced produces 0 adjustments

Returns `{reconciled, adjustments[]}`.

---

## 6. Exception Design

| Exception | When Thrown |
|-----------|-------------|
| `InsufficientFundsException` | Source wallet balance < transfer amount |
| `WalletFrozenException` | Either wallet status is `frozen` |
| `SameWalletTransferException` | fromWalletId == toWalletId |

All exceptions extend `\RuntimeException`.

---

## 7. Payment Integration

Wallet integrates with Payment through Payment-defined interfaces. Wallet owns wallet-specific behavior and data, while Payment owns invoice workflow and gateway orchestration.

### 7.1 Dependency Direction

| From | To | Allowed | Rule |
|------|----|---------|------|
| Wallet | Payment | Yes | Implement Payment gateway and adjustment provider interfaces |
| Payment | Wallet | No | Payment must not import Wallet services or deduction entities |
| Wallet | Trade | No | Wallet must not react to Trade orders directly |

### 7.2 WalletGateway

`WalletGateway` is a Payment gateway implementation owned by Wallet. It processes invoices that are paid entirely through wallet balance.

**File**: `src/Wallet/Service/Payment/WalletGateway.php`

**Contract**: implements `App\Payment\Service\PaymentGatewayInterface`

Rules:

| Rule | Requirement |
|------|-------------|
| WPG-1 | Gateway receives an explicit amount from Payment and transfers exactly that amount |
| WPG-2 | Gateway must not inspect wallet deduction or adjustment options |
| WPG-3 | Gateway uses `TransferServiceInterface::transfer()` from payer wallet to system wallet |
| WPG-4 | Gateway refund transfers from system wallet back to payer wallet |
| WPG-5 | Gateway references are stable and idempotent per invoice/refund attempt |

`WalletGateway` is separate from wallet balance deduction. Wallet gateway pays the invoice through wallet as the selected payment method. Wallet balance deduction is a pre-payment adjustment that can be combined with another gateway.

### 7.3 WalletBalanceAdjustmentProvider

`WalletBalanceAdjustmentProvider` applies wallet balance as a Payment adjustment before gateway payment.

**File**: `src/Wallet/Service/Payment/WalletBalanceAdjustmentProvider.php`

**Contract**: implements `App\Payment\Service\Adjustment\PaymentAdjustmentProviderInterface`

Provider name: `wallet_balance`

Supported request options:

| Input | Meaning |
|-------|---------|
| `walletAmount` | Shortcut amount in invoice currency |
| `deduction.type = wallet_balance` | Structured adjustment request |
| `deduction.amount` | Deduction amount in cents |
| `deduction.currency` | Must equal invoice currency |
| `systemWalletId` | System wallet receiving deducted funds |

Provider responsibilities:

| Responsibility | Detail |
|----------------|--------|
| `supports()` | Return true when `walletAmount > 0` or structured `deduction.type = wallet_balance` is present |
| `apply()` | Transfer payer wallet balance to system wallet and persist `PaymentDeduction` |
| `applied()` | Return applied deduction as `PaymentAdjustmentResult` for Payment amount validation |
| `release()` | Reverse applied deduction when invoice payment fails or is cancelled before paid |
| `refund()` | Reverse applied deduction when paid invoice is refunded |

The provider returns only generic Payment adjustment data to Payment:

```php
new PaymentAdjustmentResult(
    provider: 'wallet_balance',
    amount: $deduction->getAmount(),
    currency: $deduction->getCurrency(),
    referenceId: $deduction->getReferenceId(),
    payload: [
        'deductionId' => $deduction->getUuid(),
    ],
);
```

Wallet transaction ids may be stored in `PaymentDeduction`, but Payment must not rely on them.

### 7.4 Deduction Flow

```text
InvoiceService::pay(invoice, payment, options)
  -> PaymentAdjustmentRegistry finds WalletBalanceAdjustmentProvider
  -> WalletBalanceAdjustmentProvider::apply(context)
     -> find payer wallet by payer id and invoice currency
     -> transfer payer wallet -> system wallet
     -> persist PaymentDeduction as applied
     -> return PaymentAdjustmentResult(amount=walletAmount)
  -> Payment computes gatewayAmount = invoice.amount - adjustmentTotal
  -> Payment calls selected gateway with explicit gatewayAmount
```

Full wallet deduction:

```text
walletAmount == invoice.amount
  -> WalletBalanceAdjustmentProvider applies transfer
  -> Payment marks invoice paid with payment = wallet
  -> no external gateway is called
```

### 7.5 Release And Refund Flow

Release before invoice is paid:

```text
gateway pay creation fails or invoice is cancelled
  -> Payment calls WalletBalanceAdjustmentProvider::release(result, reason)
  -> Wallet transfers system wallet -> payer wallet
  -> PaymentDeduction status becomes released
```

Refund after invoice is paid:

```text
InvoiceService::refund(full invoice amount)
  -> Payment refunds external gateway amount first, if any
  -> Payment calls WalletBalanceAdjustmentProvider::refund(result, reason)
  -> Wallet transfers system wallet -> payer wallet
  -> PaymentDeduction status becomes refunded
```

First-phase wallet deductions only support full invoice refunds. Partial refund allocation between external gateway amount and wallet deduction is out of scope.

### 7.6 Reference Ids

Wallet deduction transfers MUST use stable idempotency references:

```text
invoice-adjustment-wallet-balance-{invoice.uuid}
invoice-adjustment-wallet-balance-release-{invoice.uuid}
invoice-adjustment-wallet-balance-refund-{invoice.uuid}
```

These references are passed to `TransferServiceInterface::transfer()` and stored on `PaymentDeduction`.

---

## 8. API Endpoints

### 8.1 Manage (Admin, ROLE_ADMIN)

| Method | Path | Description |
|--------|------|-------------|
| GET | `/api/v1/manage/wallets` | List wallets |
| GET | `/api/v1/manage/wallets/{id}` | Wallet detail |
| POST | `/api/v1/manage/wallets` | Create wallet (balance always starts at 0) |
| PUT | `/api/v1/manage/wallets/{id}` | Update wallet (freeze/unfreeze) |
| DELETE | `/api/v1/manage/wallets/{id}` | Delete wallet |
| **GET** | **`/api/v1/manage/wallets/balance`** | **Verify accounting invariant** |
| **POST** | **`/api/v1/manage/wallets/reconcile`** | **Per-wallet reconciliation** |
| GET | `/api/v1/manage/transactions` | List transactions |
| POST | `/api/v1/manage/transactions` | Execute wallet-to-wallet transfer |
| **POST** | **`/api/v1/manage/vouchers/deposit`** | **Voucher-backed deposit. `voucherType` optional (default `manual`; admin-only zone)** |
| **POST** | **`/api/v1/manage/vouchers/withdraw`** | **Voucher-backed withdrawal. `voucherType` optional (default `manual`; admin-only zone)** |

### 8.2 App (User, ROLE_USER)

| Method | Path | Description |
|--------|------|-------------|
| **POST** | **`/api/v1/app/vouchers/deposit`** | **Self-service deposit into own wallet. `voucherType` REQUIRED — permission enforced by the provider's `assertPermitted()` (e.g. `manual` requires `ROLE_ADMIN`, denied for users)** |
| **POST** | **`/api/v1/app/vouchers/withdraw`** | **Self-service withdrawal out of own wallet. `voucherType` REQUIRED — permission enforced by the provider's `assertPermitted()`** |
| **POST** | **`/api/v1/app/vouchers/{uuid}/reverse`** | **Reverse own voucher (deposit or withdrawal by direction)** |

---

## 9. Concurrency Contract — Pessimistic Lock + Manual Version

Wallets use `SELECT ... FOR UPDATE` (pessimistic locking) with a manually
incremented `version` column. There is **no** `#[ORM\Version]` mapping:

- `TransferService` / `DepositService` / `WithdrawService` lock the target wallet row(s) `FOR UPDATE` in a deterministic order, validate, then `UPDATE wallet SET balance = ..., version = version + 1`
- Concurrent transactions block on the row lock; the loser re-reads the updated balance after the winner commits
- `version` is an ordinary `int` used for audit ordering, not for Doctrine optimistic-lock checks
- Do not add `#[ORM\Version]` without a documented architectural decision

---

## 10. Money Handling Contract

Same contract as Trade module:

| Aspect | Rule |
|--------|------|
| Storage | `bigint` (cents) |
| PHP type | `int` |
| API boundary | Decimal (string/number) |
| Transfer amount | Integer cents (not decimal) |

---

## 11. Database Migration

**Version**: `Version20250517000000`

Creates `wallet` and `wallet_transaction` tables. Payment adjustment support adds `wallet_payment_deduction` in the wallet deduction phase.

---

## 12. Testing

| Suite | Tests |
|-------|-------|
| `tests/Wallet/Entity/` | Wallet, Transaction unit tests |
| `tests/Wallet/Service/WalletServiceTest.php` | **11 unit tests**: verifyBalance (match/mismatch/zero), reconcile (empty/balanced/excess/negative/idempotent/multi/skip-non-wallet/skip-no-id) |
| `tests/Wallet/Service/TransferServiceTest.php` | **20 unit tests**: deposit (happy/wallet-not-found/frozen/idempotent/rollback/em-closed), transfer (happy/same-wallet/source-not-found/target-not-found/frozen/currency/insufficient/idempotent/deadlock/rollback/em-closed) |
| `tests/Wallet/Service/Payment/WalletBalanceAdjustmentProviderTest.php` | Wallet deduction apply/applied/release/refund validation and idempotency |
| `tests/Wallet/Service/Payment/WalletGatewayTest.php` | Wallet gateway pay/refund explicit amount handling |
| `tests/Wallet/Integration/` | TransferService, wallet repository, transaction repository, API regression |
