<?php

namespace App\Core\View;

use App\Core\Query\DqlExpression;
use App\Core\Utils\UUID;
use Doctrine\ORM\QueryBuilder;

trait ApiView
{
    protected function entityNotFoundMessage(): string { return 'Entity not found'; }

    /**
     * Optional authorization lifecycle hook for API view actions.
     *
     * Controllers that do not override this hook retain their current behavior.
     */
    protected function authorizeApiAction(string $action, ?object $entity = null): void
    {
    }

    use TransformContent;

    // protected $service = null;
    protected ?string $serviceClass = null;

    /** @return array<string, mixed>|QueryBuilder|DqlExpression */
    protected function commonFilter()
    {
        /** common filter for all entities */
        return [];
    }

    /**
     * Resolve commonFilter and bind internal `this` context when needed.
     *
     * @return array<string, mixed>|QueryBuilder|DqlExpression
     */
    protected function resolvedCommonFilter(): array|QueryBuilder|DqlExpression
    {
        $filter = $this->commonFilter();
        if ($filter instanceof DqlExpression && $filter->usesThis() && $filter->context() === null) {
            return $filter->withContext($this);
        }

        return $filter;
    }

    /**
     * Resolve a single resource identifier to its canonical field name.
     *
     * Digit-only values map to `id`, canonical UUID strings map to `uuid`.
     * Use this helper when building scope-aware or custom filters so that
     * both forms remain unambiguous and no fallback heuristic is used.
     */
    protected function identifierField(int|string $value): string
    {
        return UUID::is_valid((string) $value) ? 'uuid' : 'id';
    }

    /**
     * Build a single-entry criteria array for a resource identifier.
     *
     * @return array<string, int|string>
     */
    protected function identifierCriteria(int|string $value): array
    {
        return [$this->identifierField($value) => $value];
    }

    /**
     * @param array<string, mixed>|QueryBuilder|DqlExpression|null $commonFilter
     * @return array<string, mixed>|QueryBuilder|DqlExpression
     */
    protected function mixIdToCommonFilter(int|string $id, array|QueryBuilder|DqlExpression|null $commonFilter = null)
    {
        return $this->mixToCommonFilter($this->identifierCriteria($id), $commonFilter);
    }

    /**
     * @param array<string, mixed> $data
     * @param array<string, mixed>|QueryBuilder|DqlExpression|null $commonFilter
     * @return array<string, mixed>|QueryBuilder|DqlExpression
     */
    protected function mixToCommonFilter(array $data, array|QueryBuilder|DqlExpression|null $commonFilter = null)
    {
        $filter = $this->resolvedCommonFilter();

        if ($filter instanceof DqlExpression) {
            return $filter->withCriteria($data);
        }

        if ($filter instanceof QueryBuilder) {
            $alias = $filter->getRootAliases()[0];
            foreach ($data as $key => $item) {
                $filter->andWhere("$alias.$key = :$key")->setParameter($key, $item);
            }

            return $filter;
        }

        $base = $commonFilter ?? $this->resolvedCommonFilter();
        if ($base instanceof DqlExpression) {
            $resolved = $base;
            if ($resolved->usesThis() && $resolved->context() === null) {
                $resolved = $resolved->withContext($this);
            }

            return $resolved->withCriteria($data);
        }

        if ($base instanceof QueryBuilder) {
            $alias = $base->getRootAliases()[0];
            foreach ($data as $key => $item) {
                $base->andWhere("$alias.$key = :$key")->setParameter($key, $item);
            }

            return $base;
        }

        $filter = array_merge($data, $base);

        return $filter;
    }

    /**
     * Build a filter for a scoped parent identifier.
     *
     * For scoped routes such as `/store/{scopeId}/orders/{id}`, both `scopeId`
     * and `id` follow the same numeric-or-UUID rule: digit-only values resolve
     * as `id`, canonical UUID values resolve as `uuid`. Implementations of
     * `scopedDetailFilter()` / `scopedListFilter()` should use `identifierCriteria()`
     * (or `identifierField()`) for both the parent scope and the child resource
     * to keep resolution explicit and unambiguous.
     *
     * @return array<string, int|string>
     */
    protected function scopeIdentifierCriteria(int|string $scopeId): array
    {
        return $this->identifierCriteria($scopeId);
    }
}
