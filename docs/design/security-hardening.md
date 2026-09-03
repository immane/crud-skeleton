# Security Hardening Roadmap

> This document outlines recommended security hardening measures for CRUD Skeleton.
> It describes **desired end-states** — not current vulnerabilities.
>
> Sensitive details (file paths, line numbers, attack chains) are intentionally excluded.
> See the project's [security policy](https://github.com/immane/crud-skeleton/blob/dev/SECURITY.md) for the reporting process.

---

## Priority P0 — Immediate

### Serialization / Sensitive Data Exposure

- [ ] Add serializer groups or `#[Ignore]` attributes to `User`, `WechatUser`, and `RefreshToken`
  entities to prevent sensitive getters (`getPassword`, `getSessionKey`, `getRefreshTokenHash`,
  etc.) from being included in JSON responses.
- [ ] Add a field allow-list (or explicit deny-list) to `@expands`, `@display`, and `@select` dynamic
  parameters so that identity-sensitive fields and relationship traversals cannot be surfaced
  through chained getter calls or expression evaluation.
- [ ] Enforce a maximum traversal depth on `@expands` to prevent unbounded relationship traversal.
- [ ] Apply a global serializer default context with `enable_max_depth` and default groups so
  extensions/modules are protected by default.

### File Upload

- [ ] Add a deny-list of **executable file extensions** (`.php`, `.phtml`, `.pht`, `.phar`,
  `.htaccess`, etc.) in the media upload pipeline — validated server-side with `finfo` rather than
  trusting the client-supplied `Content-Type` header.
- [ ] Place a `.htaccess` (Apache) or equivalent configuration inside `public/uploads/` that
  disables PHP execution and direct script access.
- [ ] Rate-limit the upload endpoints per user to prevent disk-exhaustion via flooding.

### Authentication

- [ ] Move the OTP HMAC secret to an environment variable (`OTP_HMAC_SECRET`) instead of a
  hardcoded literal, so each deployment has a unique secret.
- [ ] Increase the OTP length from 6 digits to 8 digits.

### Payment

- [ ] Register `MockGateway` only in `dev` and `test` environments; ensure it is absent from any
  production service container.

---

## Priority P1 — This Week

### Authentication

- [x] Integrated Symfony's `RateLimiter` component and configured per-IP policies on `/api/auth/login` (+ `register`, `otp/request`, `otp/verify`, `wechat/miniapp/login`, `payment`) — see `src/Core/EventListener/RateLimitListener.php` + `config/packages/rate_limiter.yaml` (test env uses high limits; remaining: per-process filesystem cache → needs Redis for multi-worker).
- [ ] Add a progressive backoff or CAPTCHA requirement after repeated login failures.
- [ ] Unify registration error messages — use a single generic response regardless of which
  field (email / username / phone) conflicts — to prevent user enumeration.
- [ ] Enforce minimum password length of 8–12 characters with mixed character-type requirements.
- [ ] Require the caller to be authenticated for the `verify_phone` OTP purpose so that only the
  logged-in user can verify their own phone number.

### Serialization

- [ ] Add a default serializer context with attribute-based or group-based exclusion so new
  entities added in the future won't accidentally expose sensitive getters.

### Payment

- [ ] Add `#[ORM\Version]` (optimistic locking) to the `Invoice` entity to prevent lost updates
  during concurrent payment processing.
- [ ] Move the `markPaid()` "already-paid" idempotency check inside the database transaction to
  close a narrow concurrent-notification window.

### DoS / Resource Limits

- [ ] Enforce a maximum `limit` value (e.g. 100 or 200) on all list endpoints so that a single
  request cannot retrieve an unbounded number of rows.
- [ ] Force `@select` and `@groupBy` paths to go through the paginator instead of returning the
  entire result set directly.
- [ ] Add a query execution-time limit in Doctrine configuration.

---

## Priority P2 — This Month

### Authentication

- [ ] Add `nbf` (not-before) claim to JWT access tokens.
- [ ] Validate the `iss` (issuer) claim in `decodeAccessTokenWithoutBlacklist`.
- [ ] Use a cryptographic hash (e.g. SHA-256) for JTI-based blacklist cache keys instead of a
  non-cryptographic hash.
- [ ] Remove the OTP endpoint duplication between `AuthController` and `OtpController`; keep one
  canonical implementation.
- [ ] Move `#[IsGranted]` from method-level to class-level on App controllers to reduce the risk
  of a new action accidentally missing the annotation.
- [ ] Add a cooldown between failed OTP verification attempts.

### File Upload

- [ ] Add path traversal guards (strip `../`, reject path separators) in `LocalStorage::store()` so
  the storage driver itself is resilient regardless of the caller.
- [ ] Prefer server-detected image dimensions (`getimagesize`) over client-supplied metadata.

### Payment

- [ ] Add `#[ORM\Version]` to all entities involved in payment flows (`Invoice`, `Order`,
  `Wallet`) to prevent lost updates.
- [ ] Remove the dead `refund-notify` access control rule, or implement the corresponding
  webhook controller.

### DoS / Query Safety

- [ ] Add an allow-list of permitted `@order` and `@groupBy` fields for each entity or a
  maximum number of sort/group columns.
- [ ] Add a maximum recursion depth guard in `ExpressionDqlParser::recursiveCompile`.
- [ ] Add an allow-list of permitted Doctrine query hints for `@hints`.

### Serialization

- [ ] Remove the exception class name from the API error response envelope.
- [ ] Restrict `/system/entities/{entityName}` to `ROLE_ADMIN` or remove sensitive metadata
  (column types, lengths) from the response.

---

## Priority P3 — Ongoing

### Cross-Cutting

- [ ] Integrate automated dependency vulnerability scanning (Dependabot / GitHub Advisory
  Database) if not already active.
- [ ] Review `ON DELETE SET NULL` foreign keys for any media or user-owned entities — ensure
  deleted users do not cause previously private data to become public.
- [ ] Run a periodic manual IDOR review on all App controllers: verify that `commonFilter` +
  explicit ownership checks cover `detail`, `update`, `delete`, and any custom action methods.
- [ ] Add audit logging for sensitive operations (media deletion, payment state changes, wallet
  adjustments) to support forensic analysis.
