# Store Services / Controllers / Store Entity — Test Coverage & Bug Report

Date: 2026-08-09
Scope: `StoreOrderService`, `StoreMembershipService`, `StoreContextResolver`, the Staff `StoreOrderController`, the Manage `StoreController`, and the `Store` entity.
Rule followed: **no files under `src/` were modified** — only test files were added/extended under `tests/`, plus this report.

## Files changed (tests only)

| File | Change | What it covers |
|---|---|---|
| `tests/Store/Service/StoreOrderServiceTest.php` | extended | `reject` without outbox (line 49); snapshot normalization rejections — invalid snapshot (135), non-string customer UUID (140), negative total (143), non-string channel (153); snapshot-conflict `LogicException` (85); unique-constraint race paths — rethrow when existing vanished (105-108), conflict with constraint cause (110-111), idempotent return (114); `transaction()` direct-callback when a transaction is already active (186) and fallback when `isTransactionActive()` throws (189); a skipped idempotency test documenting Bug 1. |
| `tests/Store/Service/StoreMembershipServiceTest.php` | extended | `grant` blank user (24); `grant` on unpersisted store (29); `grant` creates new membership; `grant` updates + reactivates an existing membership (43). |
| `tests/Store/Service/StoreContextResolverTest.php` | new | no header → `null`; missing store and inactive store → `RuntimeException` (30); context build with `X-Store-Channel` header. |
| `tests/Store/Controller/Staff/StoreOrderControllerTest.php` | new | accept/reject/fulfill 404, wrong-status 400, bad-body 400, and success paths (57, 60, 78, 81, 98, 101, 107); `authorizedStore`/`authorizedOrder` guard branches (127, 138); `scopedListFilter`/`scopedDetailFilter` (39, 41, 47, 49) via reflection. |
| `tests/Store/Controller/Manage/StoreControllerTest.php` | new | status/list-members/grant-member 404 (83, 94) and success; grant data/role validation; `validateStoreContent` code/name/timezone/settings branches (115, 118, 122, 125-127, 131) via `processCreateContent`/`processUpdateContent`; status dynamic-method call. |
| `tests/Store/Entity/StoreTest.php` | extended | `setContact` (92), `setAddress` (94), `activate` (98), plus null-contact/address/settings, `setCode`/`setTimezone`, `getName`, `__toString`, `getCreatedAt`/`getUpdatedAt`/`getId`. |

No existing test that was not touched by this task was modified; no `src/` file was touched.

## Coverage results

Measured with the six files above (Xdebug, `phpunit.dist.xml` config).

| File | Before | After |
|---|---|---|
| `src/Store/Service/StoreOrderService.php` | 86.67% | **100% (105/105)** |
| `src/Store/Service/StoreMembershipService.php` | 88% | **100% (25/25)** |
| `src/Store/Service/StoreContextResolver.php` | 92.86% | **100% (14/14)** |
| `src/Store/Controller/Staff/StoreOrderController.php` | 81.63% | **100% (49/49)** |
| `src/Store/Controller/Manage/StoreController.php` | 87.5% | **100% (40/40)** |
| `src/Store/Entity/Store.php` | 89.66% | **100% (29/29)** |

All six target files reach **100%** line and method coverage.

## How to run

```bash
cd /Volumes/Nayuki/Development/PHP/crud-skeleton
XDEBUG_MODE=off /opt/homebrew/opt/php@8.5/bin/php vendor/bin/phpunit \
  tests/Store/Service/StoreOrderServiceTest.php \
  tests/Store/Service/StoreMembershipServiceTest.php \
  tests/Store/Service/StoreContextResolverTest.php \
  tests/Store/Controller/Staff/StoreOrderControllerTest.php \
  tests/Store/Controller/Manage/StoreControllerTest.php \
  tests/Store/Entity/StoreTest.php \
  --no-coverage
# => OK (65 tests, 246 assertions, 1 skipped)
```

These are all unit tests (no DB access); they do not touch the shared `var/test.db`. The pre-existing `tests/Store/Integration/*` suite remains green (13 tests, 70 assertions) and was re-run to confirm no regression.

## Bugs found

All `src/` bugs below are **documented only** — no fix was applied.

### Bug 1 — `matchesSnapshot()` compares snapshots with order-sensitive `===` (medium, idempotency)
- **File/line:** `src/Store/Service/StoreOrderService.php:180`
- **Description:** `$storeOrder->getOrderSnapshot() === $data['orderSnapshot']` uses PHP's strict array comparison, which requires identical **key order**. The normalized snapshot is always built in one fixed order, but a stored row's `order_snapshot` JSON can legitimately carry keys in a different order (round-tripped from a producer that serialized `channel` first, or any hand-constructed order). In that case `matchesSnapshot()` returns `false`, and re-delivering the exact same trade-order event throws `LogicException('Trade order snapshot conflicts with the existing Store order.')` (line 85/111) instead of returning the existing order.
- **Impact:** the core "create once, then return the existing projection" idempotency guarantee fails for otherwise-identical snapshots whose JSON key order differs; duplicate `trade.order.created.v1` events then 500 and are retried forever.
- **Reproduction:** persist a `StoreOrder` whose `order_snapshot` is `['channel' => 'mini_program', 'items' => [], 'delivery' => [], 'placedAt' => '…']`; call `createFromTradeOrderSnapshot()` with the same values normalized by the service (which produces `['items' => …, 'delivery' => …, 'placedAt' => …, 'channel' => …]`). `matchesSnapshot()` returns `false` → `LogicException`. (Confirmed via the skipped test below.)
- **Proposed fix:** compare order-insensitively, e.g. use `==` for the snapshot comparison, or `ksort()` both arrays before `===`.

### Bug 2 — `acceptAction()` accepts an empty `reservationId` and silently clears the reservation (low-medium, data integrity)
- **File/line:** `src/Store/Controller/Staff/StoreOrderController.php:64-68` (and `src/Store/Entity/StoreOrder.php:129`)
- **Description:** `$reservationId = $data['reservationId'] ?? null;` only rejects non-string values. An empty string `''` passes, then `StoreOrder::accept('')` sets `$this->reservationId = '' ?? $this->reservationId` → `''`, overwriting a previously assigned reservation id. The outbox event then records `reservationId => ''`.
- **Impact:** a client can POST `{"reservationId": ""}` and silently wipe the reservation id of an accepted (or awaiting-inventory) order, producing a misleading `store.order.accepted.v1` payload that downstream consumers key on.
- **Reproduction:** `awaitInventory('r-1')`, then accept with body `{"reservationId": ""}` — `getReservationId()` becomes `''` and the outbox payload carries an empty reservation.
- **Proposed fix:** treat empty/whitespace `reservationId` as `null` (or reject it): `if ($reservationId !== null && trim($reservationId) === '') { $reservationId = null; }` before calling `accept()`.

### Bug 3 — `grantMemberAction()` swallows `\Throwable` and masks programming errors as client errors (low, observability)
- **File/line:** `src/Store/Controller/Manage/StoreController.php:102-106`
- **Description:** `catch (\Throwable $exception)` catches **all** errors — including `TypeError`, `AssertionError`, `PDOException` — and returns them as a 400 `warning` with `$exception->getMessage()`.
- **Impact:** real server/programming bugs are hidden behind HTTP 400 responses and the raw exception message is echoed to the caller (minor info leak); the failure mode is indistinguishable from a bad request.
- **Reproduction:** make `StoreMembershipService::grant()` throw any non-`InvalidArgumentException` (e.g. a DB error) — the controller returns 400 "DB connection lost…" instead of 500.
- **Proposed fix:** catch the domain exception(s) the grant can realistically raise (e.g. `\InvalidArgumentException`) and let `Error`/other exceptions bubble to the normal error handler.

### Bug 4 — `grant()` silently reactivates revoked memberships (low, semantics)
- **File/line:** `src/Store/Service/StoreMembershipService.php:42-43`
- **Description:** when a membership already exists, `grant()` always calls `$membership->setRole($role)->activate()`. A membership that was explicitly `revoke()`d is reactivated by any subsequent grant for the same store+user.
- **Impact:** an administrator who revoked a user's access can have that revocation silently undone by any later grant call (e.g. a replayed request or an automated grant) — revocation is not sticky.
- **Reproduction:** `revoke()` a membership, then `grant($store, $userUuid, ROLE_CLERK)` — the same row is `active` again with no indication it had been revoked.
- **Proposed fix:** if the intent is "grant only creates/updates active memberships", skip reactivation of revoked rows (or require an explicit reactivation step); otherwise document and assert the intended semantics.

### Bug 5 — empty `X-Store-Channel` header overrides the `'api'` default (negligible, edge)
- **File/line:** `src/Store/Service/StoreContextResolver.php:37`
- **Description:** `$request->headers->get('X-Store-Channel', 'api')` returns the header verbatim; an explicitly-present-but-empty header yields channel `''` instead of `'api'`.
- **Impact:** `StoreContext->channel` becomes `''` for such clients, which can break channel-conditional logic downstream.
- **Reproduction:** send `X-Store-Code: demo` and `X-Store-Channel:` (empty) — `resolve()->channel === ''`.
- **Proposed fix:** use `$request->headers->get('X-Store-Channel') ?: 'api'`.

## Skipped tests

**One** correct-behavior test is skipped so the suite stays green while Bug 1 remains in `src/`:

- `StoreOrderServiceTest::testCreateFromSnapshotIsIdempotentDespiteSnapshotKeyOrder` — asserts the idempotency contract (identical snapshot values with a different JSON key order returns the existing order). Against current `src/` it fails with `LogicException('Trade order snapshot conflicts…')`; skipped and documented under Bug 1. Once `matchesSnapshot()` is made order-insensitive, remove the `markTestSkipped` and the test will pass.

All other tests pass against the current `src/` code.

## Notes

- Controller unit tests follow the `tests/Trade/Controller/Manage/OrderControllerTest.php` pattern (mocked collaborators, `#[AllowMockObjectsWithoutExpectations]`, `setRequestStack`/`setSerializer`/`setTranslator`), plus `setContainer()` with a stubbed `security.token_storage` so `AbstractController::getUser()` resolves a real `User` for the scoped authorization path.
- No `ReflectionProperty::setAccessible()` is used anywhere; the two reflection usages are `ReflectionProperty->setValue()` (grant tests, to give a `Store` a fake id) and `ReflectionMethod->invoke()` (to reach protected controller helpers), both fine on PHP 8.5.
- The suite runs with `phpunit.dist.xml` (`failOnDeprecation/Notice/Warning`) — no deprecations/notices/warnings were emitted.
