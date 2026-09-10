# Dynamic Query System — Complete Reference

The dynamic query system is driven by `BaseServiceReadListTrait::list()`
(`src/Core/Service/Concern/BaseServiceReadListTrait.php`) and the expression
engine in `src/Core/Parser/` plus `src/Core/Service/ExpressionService.php`.
Any list endpoint built on `ListApiViewMixin` (or any service called with
`list(..., false)` — i.e. `$disableRequest = false`) accepts these query
parameters on a `GET` request.

The system has two tiers:

1. **SQL/DQL tier** — `@filter`, `@dql`, `@order`, `@select`, `@groupBy`,
   `@hints` are compiled into the Doctrine `QueryBuilder`.
2. **In-memory tier** — `@sort` (and `@filter` when DQL compilation fails for a
   non-admin) are evaluated in PHP over the already-fetched result set.

---

## 1. Parameter Reference

| Parameter | Type | Tier | Description | Example |
|-----------|------|------|-------------|---------|
| `page` | int | SQL | Page number (1‑based) | `2` |
| `limit` | int | SQL | Items per page (default `100` via controller; the paginator honors `page`/`limit`) | `20` |
| `@filter` | expression | DQL + fallback | Expression WHERE clause | `entity.status == "active"` |
| `@dql` | DQL | SQL | Raw DQL sub-query used as `id IN (...)` | `entity.price > 100` |
| `@order` | list | SQL | ORDER BY fields | `createdAt\|DESC` |
| `@select` | list | SQL | SELECT override (projection) | `entity.id, entity.name` |
| `@groupBy` | expression | SQL | GROUP BY clause | `entity.category` |
| `@hints` | JSON | SQL | Query hints set on the Doctrine query | `{"hint.name": "value"}` |
| `@sort` | expression | In-memory | In-memory comparator (admin-only) | `x.getPrice() <=> y.getPrice()` |
| `@showDQL` | boolean | Info | Dump the generated DQL (dev-only) | `true` |
| `@expands` | JSON / list | View | Nested relation expansion (`__metadata`) — accepts JSON array `["a","b"]`, single value `a`, or comma-separated `a,b` (all equivalent) | `specifications` / `category,tags` |
| `@display` | string | View | Response projection: `complex`, `reduce`, JSON projection | `reduce` |
| `@transform` | JSON | View | Expression-based content transform (create/update actions) | `{"category": "Service.get(...)"}` |
| `@partial` | boolean | View | Disable the surrounding transaction (create/batch-update) | `true` |
| `@mode` | string | View | Batch update mode: `update` / `mixed` (upsert) | `mixed` |
| `@basis` | list | View | Match fields for batch update/upsert | `id,sku` |

> The view-level parameters (`@expands`, `@display`, `@transform`, `@partial`,
> `@mode`, `@basis`) are applied by `RestController::success()/requestProcess()`
> and the create/update mixins — they are not part of `list()` itself but
> complement it on CRUD endpoints. This reference covers them for completeness.

---

## 2. Core Query Parameters

### `page` / `limit`

Paginate the result set. `RestController::pagination()` applies them
server-side (over a `QueryBuilder` via Doctrine `Paginator` for an accurate
total, or over an array/`ArrayCollection` with `array_slice`). The generated
`paginator` metadata looks like:

```json
{
  "total": 100, "page": 2, "limit": 20, "pages": 5,
  "has_previous": true, "has_next": true
}
```

### `@filter`

An expression evaluated against a root alias of `entity`. The same syntax is
usable as a raw `QueryBuilder` fragment (the DQL tier) and as an in-memory
predicate (the fallback).

Supported operators (shared by client `@filter` and server `DqlExpression`):

| Operator | Meaning | DQL mapping |
|----------|---------|-------------|
| `==` / `!=` | equal / not equal | `=` / `!=` |
| `>` `>=` `<` `<=` | comparison | same |
| `&&` / `||` | logical AND / OR | `AND` / `OR` |
| `!` | logical NOT (attr check) | `prop IS NULL` on the child |
| `in` / `not in` | collection membership | `IN (:param)` / `NOT IN (:param)` — bound as array parameters; empty `in []` becomes `1 = 0`, empty `not in []` becomes `1 = 1` |
| `matches` | regex / LIKE | `REGEXP(...) = TRUE` or `LIKE '%...%'` |
| `+ - * /` | arithmetic | same |

**Field references** are the root alias plus dotted getters, e.g.
`entity.status`, `entity.getCategory().getName()` (chained attribute access
generates the necessary joins). A bare attribute (no operator) compiles to
`prop IS NOT NULL`.

Examples:

```
# Equality
@filter=entity.status == "active"

# Comparison + logic
@filter=entity.price >= 100 && entity.status != "deleted"

# Chained attribute (joins category)
@filter=entity.getCategory().getName() == "Electronics"

# Regex match
@filter=entity.email matches "/@example\.com$/i"

# LIKE match (plain string pattern → %...%)
@filter=entity.name matches "pro"

# NOT — attr is null
@filter=!entity.deletedAt
```

**Access and fallback**: An `@filter` that requires in-memory evaluation (e.g.
when DQL compilation fails or the filter touches non-persistent data) is
restricted to admins. For non-admins a DQL compilation error raises
`AccessDeniedHttpException`; admins fall back to
`LegacyEvaluator::evaluateBool()` over each entity.

**Server-owned `DqlExpression` vs client `@filter`**: `DqlExpression` reuses the
same expression compiler, but variables are bound in PHP code (e.g.
`new DqlExpression('entity.getUser() == user', ['user' => $this->getUser()])`
or `new DqlExpression('entity.getStoreUuid() in this.getAllowedStoreUuids()')`
— the latter is shorthand for explicit binding and is only available inside
`commonFilter()` via `ApiView::resolvedCommonFilter()`). It never uses the
`LegacyEvaluator`, never falls back to in-memory filtering, and a compilation
or metadata-validation failure is a 500 configuration error that rejects the
request. Like `commonFilter()` arrays, it is automatically `AND`ed with the
`id`/`uuid` added by `mixIdToCommonFilter`, so detail/update/delete cannot be
bypassed. `in`/`not in` with an empty collection is compiled to the constant
predicates `1 = 0` / `1 = 1` rather than an invalid `IN ()`.

### `@dql`

A raw DQL sub-query whose matching ids are used as `id IN (subquery)`:

```
@dql=SELECT e.id FROM App\Trade\Entity\Product e WHERE e.price > 100
```

Restricted to `ROLE_ADMIN`.

### `@order`

ORDER BY fields. Comma-separated `field|DIRECTION` pairs:

```
@order=createdAt|DESC,id|ASC
```

Joins are auto-derived if you order by a chained path (the `joiner` function
registers `leftJoin`s). Non-admin note: `@order` itself isn't gated, but the
DQL compilation must be safe.

### `@select`

Overrides the SELECT clause (projection). Chained paths expand to joins:

```
@select=entity.id, entity.name, entity.category.name
```

Guarded by `assertSafeSelect()`: selecting into `App\Identity\*` entities or
any of `user|profile|password|roles|email|phone|phoneVerified|refreshToken|sessionKey|rawData`
raises `AccessDeniedHttpException` ("@select cannot access identity data.").
When `@select` (or `@groupBy`) is present, `list()` returns query results
directly rather than a `QueryBuilder`.

### `@groupBy`

Appends a GROUP BY clause:

```
@groupBy=entity.category
```

Like `@select`, chained paths auto-join and using it returns results directly.

### `@hints`

JSON object of Doctrine query hints applied to the executed query:

```
@hints={"Doctrine\\ORM\\Query::HINT_FORCE_PARTIAL_LOAD": true}
```

Restricted to `ROLE_ADMIN`.

### `@sort`

An **in-memory** comparator expression evaluated with `x` and `y` as the two
entities being compared (uses `LegacyEvaluator::evaluateBool`; a truthy result
sorts `x` first). Because it runs in PHP, it is restricted to `ROLE_ADMIN`.

```
@sort=x.getTotalAmount() > y.getTotalAmount()
```

When `@sort` is present, the DQL-tier filter result is applied but the ordering
falls back to `usort` in memory.

### `@showDQL`

When truthy, instead of returning results the endpoint throws
`ValidatorException('DQL: ' . $qb->getDQL())`, which surfaces as a JSON error
showing the generated DQL. Useful for debugging. Only available in the `dev`
environment (any environment raises `AccessDeniedHttpException` otherwise).

---

## 3. View-Level Parameters

Applied after the query, in `RestController::success()`/`requestProcess()`, and
in the create/update mixins.

### `@expands`

JSON array (single quotes allowed, `FixJSON` handles them) **or** plain
comma-separated list / single value (all equivalent) of dotted relation chains
to expand by attaching a `__metadata` attribute (clone of the related object) to
each related node, so `FlatNormalizer` can include the full normalized data:

```
# GET /api/v1/manage/contents?@expands=['category','tags']
# GET /api/v1/app/products?@expands=specifications
# GET /api/v1/app/products?@expands=specifications,category
# GET /api/v1/app/products?@expands=["specifications"]
```

When `@expands` is present, `RestController::expandObjects()` sets
`__metadata = clone node` (avoids self-reference recursion) and
`FlatNormalizer` returns the full decorated normalization for that relation
(`id`, `uuid`, `name`, `price`, etc. plus `__metadata` with the same full data)
instead of the reduced `id/__toString` view. Example for `Product`:

```
# GET /api/v1/app/products?@expands=specifications&X-Store-Code=BUND
# → specifications: [{id:3, name:"经典", price:68000, ..., __metadata:{id:3, name:"经典", price:68000, ...}}]
```

### `@display`

Controls response projection:

- **`@display=reduce`** — each item becomes `{id, __toString}`.
- **`@display=<json array>`** — e.g. `['id','name','category.name']` produces
  a flat object with the requested fields (dotted paths traversed via getters).
- **`@display=<json object>`** — keys are output fields, values are
  `ExpressionLanguage` expressions evaluated with `entity`, `Math`, and
  `ArrayCommon`.
- omitted (default `complex`) — full serialized entity.

Example:

```
# GET /api/v1/app/products?@display={"title":"entity.name","score":"Math.round(entity.price/100)"}
```

### `@transform`

Used by `CreateApiViewMixin`, `UpdateApiViewMixin`, and `Single*` mixins. A
JSON object mapping fields to `ExpressionLanguage` expressions that rewrite the
submitted content. `:value` is substituted with the submitted field value, and
`Service` (a gateway to the related entity's service), `entity`, `Math`,
`ArrayCommon` are available.

```
# POST /api/v1/manage/contents
# body:    {"title": "Hi", "category": "Test"}
# @transform={"category": "Service.get({'name': ':value'}).getId()"}

# GET /api/v1/manage/contents?@transform={"category":"Service.get({'name':':value'}).getId()"}
```

For a to-one relation on the entity, the matching `*Service` is auto-resolved by
convention (`Entity` → `Service` in the class name). Returns the related
entity's id.

### `@partial`

Boolean. On create and batch-update, when `false` (default) the whole batch is
wrapped in one transaction; when `true` each item is handled individually and
transactional wrappers are skipped (partial-mode batch-update also swallows
per-item exceptions).

### `@mode` and `@basis` (batch update)

`UpdateApiViewMixin::batchUpdateAction` (`POST /batch-update`):

- `@mode=update` — only update existing matches.
- `@mode=mixed` (default) — upsert: create when no matched entity exists.
- `@basis` — comma-separated fields used to match an existing entity for each
  submitted row (e.g. `@basis=id,sku`). When empty, no matching occurs (matters
  for upsert decisions).

```
# POST /api/v1/manage/products/batch-update?@basis=sku&@mode=mixed
```

---

## 4. How `list()` Workflow Flow Works

(Abstracted from `BaseServiceReadListTrait::list()`.)

1. **Build root** — a `QueryBuilder` over the service's entity class with root
   alias `entity`; an associative `$object` array becomes `entity.key = :value_key`
   conditions; a passed `QueryBuilder` is used as-is.
2. **`@dql`** — appended as `id IN (subDql)`.
3. **`@filter`** — compiled by `ExpressionService::buildFilter()` →
   `ExpressionDqlParser` → `ExpressionQueryBuilderAssembler`; the resulting
   ids are applied via `id IN (filterQb)` with parameters merged (parameter
   names are de-duplicated when `commonFilter` is a `DqlExpression` — e.g.
   `Store` `App/Product`'s `(!store||store==store) && status=='active'` now
   coexists with `@filter=entity.getName()=="金汤力"` as
   `filter_parameter_1` vs `filter_parameter_1_1`). On compilation failure,
   non-admins get `AccessDeniedHttpException`, admins fall back to in-memory
   filtering.
4. **`@select` / `@groupBy`** — applied; the `joiner` derives `leftJoin`s for
   dotted paths.
5. **`@order`** — applied (`field|DIRECTION`), joins derived as needed.
6. **`@hints`** — set on the executed query.
7. **`@showDQL`** — throws with the generated DQL.
8. If `@select`/`@groupBy` present → return query results.
9. Otherwise return the `QueryBuilder` (for the controller to paginate) — or,
   when the filter/order fell back to in-memory, fetch results and apply
   `@filter` (`evaluateBool`) and `@sort` (`usort`) over them.

---

## 5. Security Summary

| Parameter | Requires `ROLE_ADMIN` | Notes |
|-----------|------------------------|-------|
| `@dql` | ✅ | Raw SQL surface |
| `@sort` | ✅ | In-memory evaluation |
| `@hints` | ✅ | Raw query hints |
| `@filter` | only for the in-memory fallback | DQL-fast-path is allowed; non-admin failures → `AccessDeniedHttpException` |
| `@showDQL` | ✅ (env-gated) | `dev` only |
| `@select` | guarded, not gated | `assertSafeSelect()` blocks identity data |
| `DqlExpression` (`commonFilter` server scope) | N/A — code-owned | Fail-closed, never `PUBLIC_ACCESS`; compilation/validation failure → 500, never in-memory fallback |

> `DqlExpression` is the server-owned counterpart to `@filter` for row-level
> authorization: `commonFilter()` may return `new DqlExpression(...)`; it shares the
> operator set (including `in`/`not in`) but is constructed in PHP, bound via
> `withCriteria()`/`withContext()` and validated before any query executes.

---

## 6. Operators & People Notes

- Chained attribute access uses the root alias `entity.getX().getY()` form in
  `@filter`; the DQL tier maps these to joins (`filter_entity_x_y`).
- `matches` with a `/pattern/flags` value compiles to `REGEXP(...) = TRUE`
  (supported flags `gimsux`); a plain string compiles to `LIKE '%...%'` with
  `!` escaping.
- The `ExternalExpressionValues` available inside filter expressions include
  `math`/`Math`, `datetime`/`Datetime`, and `ArrayCommon`.

For parsing internals and class-level details, see
[core-framework.md](core-framework.md) (§4 Parser & Expression Engine).
