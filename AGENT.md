# CRUD Skeleton Agent Guide

## Language

English is the repository language. Write source code, documentation, comments,
PHPDoc, OpenAPI descriptions, commit messages, and pull request descriptions in
English. User-facing API text must use the translation system and be added to all
supported locales: `en`, `zh`, `zh_Hant`, and `ja`.

## Read Before Editing

1. Identify the affected module and read its relevant document in
   `docs/design/bundles/`.
2. Read the applicable cross-cutting design documents in `docs/design/`, especially
   `system-architecture.md`, `system-contracts.md`, `api-design.md`,
   `controller-design.md`, and `data-model.md`.
3. Read the applicable test contract in `docs/testing/crud-skeleton-production/`,
   especially `TEST_STRATEGY.md`, `BUSINESS_INVARIANTS.md`, and `FAILURE_MODES.md`.
4. Inspect the related interfaces, implementations, configuration, migrations, and
   tests before changing behavior.
5. Use `docs/ai/context.md` for the current whole-repository map, integration
   status, local PHP runtime note, and known defects.

There is currently no `docs/contracts/` directory and no ADR directory. Treat the
documents under `docs/design/` and `docs/testing/` as the repository contracts.

When sources conflict, use this precedence:

```text
Explicit user instruction
-> Documented accepted architectural decision or implementation status
-> docs/design/system-contracts.md
-> Other docs/design/ contracts
-> Existing implementation and tests
```

Report conflicts before changing a public contract, architecture boundary, or
documented integration plan.

## Architecture And Boundaries

- This is a PHP 8.4+ Symfony 8.1 API skeleton using Doctrine ORM 3.6. Follow PSR-4
  under `App\\` and use `declare(strict_types=1);` in PHP files.
- Keep the layers directional: controllers and view mixins handle HTTP only;
  services own business rules, validation orchestration, and transactions;
  repositories own queries; entities remain data/domain state and do not depend on
  services, repositories, or EntityManager.
- Controllers must not access repositories or EntityManager directly. Transactions
  belong in services, never controllers.
- Every controller must use the Core controller foundation and its `ApiView` mixin
  lifecycle where applicable. Do not reimplement or casually override list, detail,
  create, update, or delete actions; use the documented mixin hooks such as
  `commonFilter()`, `listFilter()`, `processCreateContent()`, and
  `processUpdateContent()` instead. Override an action only when the required
  behavior cannot be expressed through the existing lifecycle, and keep the override
  minimal, contract-compatible, and covered by tests.
- Preserve module boundaries under `src/{Module}/`. Cross-module code consumes a
  module's service interface, never another module's concrete service, repository,
  or entity. `src/Core/` must not depend on a business module.
- Every persisted entity keeps its integer `id` primary key. Add a UUID when an
  aggregate crosses a module boundary, appears in an integration event, or needs a
  durable public identity. Core CRUD routes accept either a numeric `id` or a valid
  UUID through their `{id}` parameter; digit-only values resolve as IDs and UUID-shaped
  values resolve as UUIDs. Do not exchange local database IDs as durable cross-module
  references or put Doctrine objects in events.
- Prefer existing extension points: `BaseService`, controller view mixins, service
  interfaces, tagged registries, Symfony events, and module configuration. Do not
  add frameworks, services, or speculative abstractions without a documented need.
- Maintain the documented ownership boundaries: Trade owns orders; Store owns the
  catalog and Store operations; Inventory owns stock/reservations; Payment owns
  invoices and generic adjustment contracts; Wallet owns wallet deductions;
  Storage owns storage drivers; Authorization owns RBAC and field grants.
- Do not bypass `commonFilter()`/`DqlExpression`, authorization voters or field
  grants, workflow guards, service interfaces, outbox/inbox idempotency, or the
  configured payment and storage registries.

## API, Security, And Data Rules

- Preserve the JSON response envelope produced by `RestController::success()` and
  `warning()`. Do not silently change public routes, response fields, query
  parameters, hook signatures, or `BaseServiceInterface`.
- Use controller field whitelists (`$requiredCreateProperties`,
  `$acceptedCreateProperties`, `$acceptedUpdateProperties`) and explicit row scopes.
  App endpoints must scope data to the authenticated user; unauthenticated scope
  resolution must fail closed.
- Keep `/api/v1/manage/*` protected by `ROLE_ADMIN`; apply scoped Authorization,
  membership, and `FieldAuthorizationService` where the module requires them.
- Route all user-facing errors through the existing warning/exception and translation
  flow. Do not expose stack traces, internal paths, credentials, tokens, passwords,
  OTP values, or unnecessary PII.
- Store money as integer cents. Preserve wallet locking, transaction, idempotency,
  voucher, reconciliation, and immutable-currency rules.
- Preserve workflow and payment invariants. Gateway implementations receive explicit
  amounts and must not interpret adjustment options; payment adjustments remain
  owned by their supplying module.
- Keep durable integration events versioned, scalar, idempotent, and transactionally
  coupled to their state mutation through the relevant outbox. Consumers must handle
  duplicate delivery and expected ordering/compensation cases.
- Do not enable Inventory outside isolated development/testing without the production
  readiness work documented in `docs/ai/context.md` and its required concurrency and
  operational review.
- Never commit secrets, private keys, production credentials, or customer data. Use
  environment-based configuration and committed example files only.

## Doctrine And Persistence

- Version every schema change with a Doctrine migration in `migrations/`; never rely
  on manual database changes.
- Keep each migration focused on one module, use the existing timestamp naming
  convention, and consider SQLite tests, PostgreSQL CI, and MySQL deployment
  compatibility.
- For destructive or irreversible changes, document a rollout, compatibility, and
  rollback/compensating-operation plan before implementation.
- Multiple related writes require a service-layer transaction. Wallet operations keep
  their explicit locking and recovery behavior.

## Code Style And Documentation

- Use explicit native property and return types wherever possible; preserve the
  generic PHPDoc contracts on `BaseService` and module service interfaces.
- Use one class, interface, or trait per matching file. Keep imports alphabetized and
  follow existing module and naming conventions.
- Prefer clear names, types, tests, and structure over comments. Comments and PHPDoc
  should explain non-obvious rationale, safety invariants, coordinate/units rules,
  external-library behavior, or temporary constraints, not restate code.
- Update relevant design, OpenAPI attributes/configuration, runbooks, translations,
  environment examples, and MkDocs documentation whenever behavior, public API,
  schema, configuration, deployment, message format, or operational recovery changes.
- Do not add placeholder implementations unless clearly marked and explicitly
  acceptable for the task.

## Testing And Verification

For every behavioral change, add or update tests at the correct layer:

- Pure deterministic domain logic: unit test.
- Service, repository, transaction, Doctrine mapping, or persistence behavior:
  integration test, including rollback/idempotency where relevant.
- Route, payload, authentication, authorization, locale, serialization, or response
  behavior: HTTP/API success and denial/validation tests.
- State transitions or money movement: valid and invalid transitions plus invariant
  and duplicate-request coverage.
- Outbox, inbox, handler, retry, or consumer behavior: first delivery, duplicate
  delivery, and failure/out-of-order handling.
- Migration or database-specific SQL: fresh migration validation and MySQL-compatible
  staging validation before release.

Tests live by layer in `tests/UnitTest/` and `tests/Integration/`. The default suite
excludes the `low-value` group. Do not delete or unskip documented skipped tests;
read the associated report in `docs/issues/coverage-2026-08-09/`, fix the underlying
defect, then update the test deliberately. Avoid redundant coverage-only test patterns
identified in `docs/issues/test-audit-2026-08-09/`.

Use PHP 8.4+ for all commands. On this macOS environment, use the documented runtime
when the default `php` is older:

```bash
/opt/homebrew/opt/php@8.5/bin/php ./vendor/bin/phpunit
composer phpstan
composer rector:types:check
```

Run the smallest relevant test set during development, then run the full commands
above for behavioral changes unless the environment prevents it. For release-level
or deployment changes, also run the relevant disposable-environment smoke scripts:

```bash
scripts/tests/api-smoke.sh
scripts/tests/store-smoke.sh
scripts/tests/inventory-smoke.sh
```

Do not run smoke scripts against shared or production infrastructure. Report the
exact commands run and their real result; never claim a check passed when it was not
executed. Report unresolved failures and environment limitations.

## Known Defects And Risk

`docs/issues/coverage-2026-08-09/README.md` is the source of truth for documented
defects. Before fixing one, read its report entry because it records the failing
behavior and intended scope. Do not reclassify known defects as fixed without the
required regression evidence. Preserve the documented production restriction on
Inventory and account for known security, payment, outbox, and portability defects
when editing adjacent code.

## Git Workflow

- Use Conventional Commits: `feat:`, `fix:`, `docs:`, `refactor:`, `test:`, `build:`,
  `ci:`, or `chore:`.
- Name branches `feat/...`, `fix/...`, `docs/...`, `refactor/...`, or `chore/...`.
- Do not create commits, push, force-push, rewrite history, or modify unrelated
  worktree changes unless explicitly requested.
