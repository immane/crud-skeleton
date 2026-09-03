<?php

declare(strict_types=1);

namespace App\Store\View;

use App\Core\Query\DqlExpression;
use App\Core\View\ApiView;
use App\Store\Entity\Store;
use App\Store\Service\StoreServiceInterface;
use Doctrine\ORM\QueryBuilder;
use App\Core\View\ApiViewMessages;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;

/**
 * Adds Store-scoped authorization to Core API view lifecycles.
 *
 * CRUD permissions default to store:{resource}:{action}; controllers only need
 * to declare extra command permissions or a non-standard resource name.
 * Existing Core API controllers retain the no-op authorization lifecycle.
 */
trait StoreScopedAuthorizationApiMixin
{
    use ApiView;

    protected string $storeScopeRouteParameter = 'scopeId';

    /** @return array<string, string> */
    protected function storeActionPermissions(): array
    {
        return [];
    }

    /**
     * @return array<string, mixed>|QueryBuilder|DqlExpression
     */
    abstract protected function storeScopedFilter(Store $store): array|QueryBuilder|DqlExpression;

    abstract protected function storeService(): StoreServiceInterface;

    protected function authorizeApiAction(string $action, ?object $entity = null): void
    {
        $permissions = [
            'list' => sprintf('store:%s:read', $this->storeAuthorizationResource()),
            'detail' => sprintf('store:%s:read', $this->storeAuthorizationResource()),
            'create' => sprintf('store:%s:create', $this->storeAuthorizationResource()),
            'update' => sprintf('store:%s:update', $this->storeAuthorizationResource()),
            'delete' => sprintf('store:%s:delete', $this->storeAuthorizationResource()),
        ];
        $permission = ($this->storeActionPermissions() + $permissions)[$action] ?? null;
        if ($permission === null) {
            return;
        }

        $this->denyAccessUnlessGranted($permission, $this->storeForAuthorization());
    }

    /**
     * Authorize Store-specific command endpoints that do not use a Core CRUD lifecycle.
     */
    protected function authorizeStoreAction(string $action): void
    {
        $this->authorizeApiAction($action);
    }

    /**
     * @return array<string, mixed>|QueryBuilder|DqlExpression
     */
    protected function commonFilter(): array|QueryBuilder|DqlExpression
    {
        return $this->storeScopedFilter($this->storeForAuthorization());
    }

    protected function storeAuthorizationResource(): string
    {
        $name = (new \ReflectionClass($this))->getShortName();
        $resource = preg_replace('/Controller$/', '', $name) ?? $name;
        $resource = preg_replace('/(?<!^)[A-Z]/', '_$0', $resource) ?? $resource;

        return strtolower($resource);
    }

    protected function storeForAuthorization(): Store
    {
        $request = $this->getRequestStack()->getCurrentRequest();
        $storeIdentifier = $request?->attributes->get($this->storeScopeRouteParameter);
        if (!is_string($storeIdentifier) || $storeIdentifier === '') {
            throw new AccessDeniedException(ApiViewMessages::STORE_SCOPE_REQUIRED);
        }

        $store = $this->storeService()->get($this->identifierCriteria($storeIdentifier), false);
        if (!$store instanceof Store) {
            throw new AccessDeniedException(ApiViewMessages::STORE_NOT_FOUND_OR_ACCESS_DENIED);
        }

        return $store;
    }
}
