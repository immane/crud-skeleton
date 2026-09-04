<?php

declare(strict_types=1);

namespace App\Tests\UnitTest\Store\Service;

use App\Store\Entity\Store;
use App\Store\Repository\StoreRepository;
use App\Store\Service\StoreContextResolver;
use App\Trade\DTO\StoreContext;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;

#[AllowMockObjectsWithoutExpectations]
final class StoreContextResolverTest extends TestCase
{
    public function testResolveReturnsNullWhenNoStoreCodeHeader(): void
    {
        $repository = $this->createMock(StoreRepository::class);
        $requestStack = new RequestStack();
        $requestStack->push(Request::create('/api/v1/app/orders', 'POST'));
        $resolver = new StoreContextResolver($requestStack, $repository);

        self::assertNull($resolver->resolve());
        $repository->expects(self::never())->method('findOneByCode');
    }

    public function testResolveThrowsWhenStoreIsMissing(): void
    {
        $repository = $this->createMock(StoreRepository::class);
        $repository->method('findOneByCode')->with('missing')->willReturn(null);
        $requestStack = new RequestStack();
        $requestStack->push(Request::create('/api/v1/app/orders', 'POST', server: ['HTTP_X_STORE_CODE' => 'missing']));
        $resolver = new StoreContextResolver($requestStack, $repository);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Store is not available.');
        $resolver->resolve();
    }

    public function testResolveThrowsWhenStoreIsNotActive(): void
    {
        $store = new Store('xuhui', 'Xuhui', 'Asia/Shanghai');
        $store->suspend();
        $repository = $this->createMock(StoreRepository::class);
        $repository->method('findOneByCode')->with('xuhui')->willReturn($store);
        $requestStack = new RequestStack();
        $requestStack->push(Request::create('/api/v1/app/orders', 'POST', server: ['HTTP_X_STORE_CODE' => 'xuhui']));
        $resolver = new StoreContextResolver($requestStack, $repository);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Store is not available.');
        $resolver->resolve();
    }

    public function testResolveBuildsContextWithChannelHeader(): void
    {
        $store = new Store('xuhui', 'Xuhui', 'Asia/Shanghai');
        $repository = $this->createMock(StoreRepository::class);
        $repository->method('findOneByCode')->with('xuhui')->willReturn($store);
        $requestStack = new RequestStack();
        $requestStack->push(Request::create('/api/v1/app/orders', 'POST', server: [
            'HTTP_X_STORE_CODE' => 'xuhui',
            'HTTP_X_STORE_CHANNEL' => 'wechat',
        ]));
        $resolver = new StoreContextResolver($requestStack, $repository);

        $context = $resolver->resolve();

        self::assertInstanceOf(StoreContext::class, $context);
        self::assertSame($store->getUuid(), $context->storeUuid);
        self::assertSame('xuhui', $context->storeCode);
        self::assertSame('Xuhui', $context->storeName);
        self::assertSame('wechat', $context->channel);
        self::assertSame('CNY', $context->currency);
    }
}
