<?php

declare(strict_types=1);

namespace App\Store\Service;

use App\Store\Repository\StoreRepository;
use App\Trade\DTO\StoreContext;
use App\Trade\Service\StoreContextResolverInterface;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

final readonly class StoreContextResolver implements StoreContextResolverInterface
{
    public function __construct(
        private RequestStack $requestStack,
        private StoreRepository $storeRepository,
    ) {
    }

    public function resolve(): ?StoreContext
    {
        $request = $this->requestStack->getCurrentRequest();
        $code = $request?->headers->get('X-Store-Code');
        if ($code === null || trim($code) === '') {
            return null;
        }

        $store = $this->storeRepository->findOneByCode($code);
        if ($store === null || !$store->isActive()) {
            throw new NotFoundHttpException('Store is not available.');
        }

        return new StoreContext(
            $store->getUuid(),
            $store->getCode(),
            $store->getName(),
            $request->headers->get('X-Store-Channel', 'api'),
        );
    }
}
