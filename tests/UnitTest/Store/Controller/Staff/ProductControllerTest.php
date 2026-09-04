<?php

declare(strict_types=1);

namespace App\Tests\UnitTest\Store\Controller\Staff;

use App\Store\Controller\Staff\ProductController;
use App\Store\Entity\Product;
use App\Store\Entity\Store;
use App\Store\Service\ProductServiceInterface;
use App\Store\Service\StoreServiceInterface;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;
use Symfony\Component\Serializer\SerializerInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

#[AllowMockObjectsWithoutExpectations]
final class ProductControllerTest extends TestCase
{
    private Store $store;
    private ProductServiceInterface $productService;
    private StoreServiceInterface $storeService;
    private AuthorizationCheckerInterface $authorizationChecker;
    private ProductController $controller;

    protected function setUp(): void
    {
        $this->store = new Store('demo', 'Demo', 'Asia/Shanghai');
        $this->productService = $this->createMock(ProductServiceInterface::class);
        $this->storeService = $this->createMock(StoreServiceInterface::class);
        $this->authorizationChecker = $this->createMock(AuthorizationCheckerInterface::class);
        $this->authorizationChecker->method('isGranted')->willReturn(true);
        $this->controller = new ProductController($this->productService, $this->storeService);
    }

    public function testCommonFilterDelegatesToStoreScopedFilter(): void
    {
        $request = Request::create('/store/' . $this->store->getUuid() . '/products', 'GET');
        $this->injectDependencies($request);
        $this->storeService->method('get')->with(['uuid' => $this->store->getUuid()])->willReturn($this->store);
        $this->productService->expects(self::once())
            ->method('list')
            ->with(['store' => $this->store, 'isDeleted' => false], null, false)
            ->willReturn([]);

        $response = $this->controller->listAction();

        self::assertSame(200, $response->getStatusCode());
    }

    public function testStoreServiceResolutionHappyFindsStore(): void
    {
        $request = Request::create('/products', 'GET');
        $this->injectDependencies($request);
        $this->storeService->method('get')->with(['uuid' => $this->store->getUuid()])->willReturn($this->store);
        $this->productService->method('list')->willReturn([]);

        $response = $this->controller->listAction();

        self::assertSame(200, $response->getStatusCode());
    }

    public function testStoreServiceResolutionSadNotFoundMapsTo403(): void
    {
        $request = Request::create('/products', 'GET');
        $this->injectDependencies($request);
        $this->storeService->method('get')->willReturn(null);

        $response = $this->controller->listAction();

        self::assertSame(403, $response->getStatusCode());
        self::assertStringContainsString('Store not found', (string) $response->getContent());
    }

    public function testStoreServiceResolutionMissingScopeIdMapsTo403(): void
    {
        $request = Request::create('/products', 'GET');
        // do not set scopeId attribute
        $requestStack = new RequestStack();
        $requestStack->push($request);
        $this->injectWithStack($requestStack);
        $this->storeService->expects(self::never())->method('get');

        $response = $this->controller->listAction();

        self::assertSame(403, $response->getStatusCode());
        self::assertStringContainsString('Store scope is required', (string) $response->getContent());
    }

    public function testCreateBindsProductToStoreHappy(): void
    {
        $request = Request::create(
            '/store/' . $this->store->getUuid() . '/products',
            'POST',
            server: ['CONTENT_TYPE' => 'application/json'],
            content: json_encode(['name' => 'Tea'], JSON_THROW_ON_ERROR)
        );
        $this->injectDependencies($request);
        $this->storeService->method('get')->with(['uuid' => $this->store->getUuid()])->willReturn($this->store);

        $product = new Product($this->store);
        $product->setName('initial');
        $this->productService->method('new')->willReturn($product);
        $this->productService->method('wrapInTransaction')->willReturnCallback(static fn (callable $fn) => $fn(new \stdClass()));
        $captured = null;
        $this->productService->method('update')->willReturnCallback(static function (object $entity, ?array $data) use (&$captured): object {
            $captured = $data;
            if ($entity instanceof Product && isset($data['name'])) {
                $entity->setName($data['name']);
            }

            return $entity;
        });

        $response = $this->controller->createAction($request);

        self::assertSame(201, $response->getStatusCode());
        self::assertSame($this->store, $product->getStore());
        self::assertSame('Tea', $product->getName());
        self::assertIsArray($captured);
    }

    public function testCreateSadWhenStoreNotFoundReturns403(): void
    {
        $request = Request::create(
            '/products',
            'POST',
            server: ['CONTENT_TYPE' => 'application/json'],
            content: json_encode(['name' => 'Tea'], JSON_THROW_ON_ERROR)
        );
        $this->injectDependencies($request);
        $this->storeService->method('get')->willReturn(null);

        $response = $this->controller->createAction($request);

        self::assertSame(403, $response->getStatusCode());
    }

    public function testCreateSadWhenAuthorizationDeniedReturns403(): void
    {
        $this->authorizationChecker = $this->createMock(AuthorizationCheckerInterface::class);
        $this->authorizationChecker->method('isGranted')->with('store:product:create', $this->store)->willReturn(false);
        $this->controller = new ProductController($this->productService, $this->storeService);

        $request = Request::create(
            '/products',
            'POST',
            server: ['CONTENT_TYPE' => 'application/json'],
            content: json_encode(['name' => 'Tea'], JSON_THROW_ON_ERROR)
        );
        $this->injectDependencies($request);
        $this->storeService->method('get')->willReturn($this->store);

        $response = $this->controller->createAction($request);

        self::assertSame(403, $response->getStatusCode());
    }

    public function testListHappyReturnsStoreScopedFilter(): void
    {
        $request = Request::create('/products', 'GET');
        $this->injectDependencies($request);
        $this->storeService->method('get')->willReturn($this->store);
        $this->productService->expects(self::once())
            ->method('list')
            ->with(self::equalTo(['store' => $this->store, 'isDeleted' => false]), null, false)
            ->willReturn([]);

        $response = $this->controller->listAction();

        self::assertSame(200, $response->getStatusCode());
        $body = json_decode((string) $response->getContent(), true, JSON_THROW_ON_ERROR);
        self::assertSame(0, $body['code']);
    }

    public function testListSadCrossStoreFilterStillIsolatedByStore(): void
    {
        // Cross-store is isolated via filter: a product from another store will not be returned.
        // Here we ensure the service is queried with the correct store-scoped filter; if the
        // repository returns empty (because nothing matches that store), controller returns 200 with empty data.
        $otherStore = new Store('pudong', 'Pudong');
        $request = Request::create('/products', 'GET');
        $this->injectDependencies($request);
        $this->storeService->method('get')->willReturn($this->store);
        $this->productService->expects(self::once())
            ->method('list')
            ->with(['store' => $this->store, 'isDeleted' => false], null, false)
            ->willReturn([]);

        $response = $this->controller->listAction();

        self::assertSame(200, $response->getStatusCode());
        $body = json_decode((string) $response->getContent(), true, JSON_THROW_ON_ERROR);
        self::assertSame([], $body['data']);
        self::assertNotSame($otherStore->getUuid(), $this->store->getUuid());
    }

    public function testDetailHappyReturnsStoreScopedFilter(): void
    {
        $product = new Product($this->store);
        $product->setName('Tea');
        $uuid = $product->getUuid();
        $request = Request::create('/products/' . $uuid, 'GET');
        $this->injectDependencies($request);
        $this->storeService->method('get')->willReturn($this->store);
        $this->productService->expects(self::once())
            ->method('get')
            ->with(['uuid' => $uuid, 'store' => $this->store, 'isDeleted' => false], false)
            ->willReturn($product);

        $response = $this->controller->detailAction($uuid);

        self::assertSame(200, $response->getStatusCode());
    }

    public function testDetailSadCrossStoreReturns404(): void
    {
        $uuid = 'd2e7f2e2-7b1e-4f9a-9c3a-0b9e1a2b3c4d';
        $request = Request::create('/products/' . $uuid, 'GET');
        $this->injectDependencies($request);
        $this->storeService->method('get')->willReturn($this->store);
        // Service returns null because product does not belong to this store (filter mismatch)
        $this->productService->method('get')->willReturn(null);

        $response = $this->controller->detailAction($uuid);

        self::assertSame(404, $response->getStatusCode());
    }

    public function testDetailSadWhenStoreNotFoundReturns403(): void
    {
        $uuid = 'd2e7f2e2-7b1e-4f9a-9c3a-0b9e1a2b3c4d';
        $request = Request::create('/products/' . $uuid, 'GET');
        $this->injectDependencies($request);
        $this->storeService->method('get')->willReturn(null);

        $response = $this->controller->detailAction($uuid);

        self::assertSame(403, $response->getStatusCode());
    }

    public function testDetailSadWhenAuthorizationDeniedReturns403(): void
    {
        $this->authorizationChecker = $this->createMock(AuthorizationCheckerInterface::class);
        $this->authorizationChecker->method('isGranted')->willReturn(false);
        $this->controller = new ProductController($this->productService, $this->storeService);

        $request = Request::create('/products/x', 'GET');
        $this->injectDependencies($request);
        $this->storeService->method('get')->willReturn($this->store);

        $response = $this->controller->detailAction('d2e7f2e2-7b1e-4f9a-9c3a-0b9e1a2b3c4d');

        self::assertSame(403, $response->getStatusCode());
    }

    public function testUpdateHappy(): void
    {
        $product = new Product($this->store);
        $product->setName('Old');
        $uuid = $product->getUuid();
        $request = Request::create(
            '/products/' . $uuid,
            'PUT',
            server: ['CONTENT_TYPE' => 'application/json'],
            content: json_encode(['name' => 'New Tea'], JSON_THROW_ON_ERROR)
        );
        $this->injectDependencies($request);
        $this->storeService->method('get')->willReturn($this->store);
        $this->productService->method('get')->with(['uuid' => $uuid, 'store' => $this->store, 'isDeleted' => false], false)->willReturn($product);
        $this->productService->method('update')->willReturnCallback(static function (object $entity, ?array $data): object {
            if ($entity instanceof Product && isset($data['name'])) {
                $entity->setName($data['name']);
            }

            return $entity;
        });

        $response = $this->controller->updateAction($request, $uuid);

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('New Tea', $product->getName());
    }

    public function testUpdateSadWhenNotInStoreReturns404(): void
    {
        $uuid = 'd2e7f2e2-7b1e-4f9a-9c3a-0b9e1a2b3c4d';
        $request = Request::create(
            '/products/' . $uuid,
            'PUT',
            server: ['CONTENT_TYPE' => 'application/json'],
            content: json_encode(['name' => 'New'], JSON_THROW_ON_ERROR)
        );
        $this->injectDependencies($request);
        $this->storeService->method('get')->willReturn($this->store);
        $this->productService->method('get')->willReturn(null);

        $response = $this->controller->updateAction($request, $uuid);

        self::assertSame(404, $response->getStatusCode());
    }

    public function testUpdateSadWhenStoreNotFoundReturns403(): void
    {
        $uuid = 'd2e7f2e2-7b1e-4f9a-9c3a-0b9e1a2b3c4d';
        $request = Request::create(
            '/products/' . $uuid,
            'PUT',
            server: ['CONTENT_TYPE' => 'application/json'],
            content: json_encode(['name' => 'New'], JSON_THROW_ON_ERROR)
        );
        $this->injectDependencies($request);
        $this->storeService->method('get')->willReturn(null);

        $response = $this->controller->updateAction($request, $uuid);

        self::assertSame(403, $response->getStatusCode());
    }

    public function testDeleteSoftDeleteHappyReturns204AndMarksDeleted(): void
    {
        $product = new Product($this->store);
        $product->setName('Tea');
        self::assertFalse($product->getIsDeleted());
        $uuid = $product->getUuid();
        $request = Request::create('/products/' . $uuid, 'DELETE');
        $this->injectDependencies($request);
        $this->storeService->method('get')->willReturn($this->store);
        $this->productService->method('get')->with(['uuid' => $uuid, 'store' => $this->store, 'isDeleted' => false], false)->willReturn($product);
        $this->productService->expects(self::once())->method('update')->with($product, [])->willReturn($product);

        $response = $this->controller->deleteAction($uuid);

        self::assertSame(204, $response->getStatusCode());
        self::assertTrue($product->getIsDeleted());
    }

    public function testDeleteSadWhenAlreadyDeletedOrNotInStoreReturns404(): void
    {
        $uuid = 'd2e7f2e2-7b1e-4f9a-9c3a-0b9e1a2b3c4d';
        $request = Request::create('/products/' . $uuid, 'DELETE');
        $this->injectDependencies($request);
        $this->storeService->method('get')->willReturn($this->store);
        $this->productService->method('get')->willReturn(null);

        $response = $this->controller->deleteAction($uuid);

        self::assertSame(404, $response->getStatusCode());
    }

    public function testDeleteSadWhenAuthorizationDeniedReturns403(): void
    {
        $this->authorizationChecker = $this->createMock(AuthorizationCheckerInterface::class);
        $this->authorizationChecker->method('isGranted')->willReturn(false);
        $this->controller = new ProductController($this->productService, $this->storeService);

        $request = Request::create('/products/x', 'DELETE');
        $this->injectDependencies($request);
        $this->storeService->method('get')->willReturn($this->store);

        $response = $this->controller->deleteAction('d2e7f2e2-7b1e-4f9a-9c3a-0b9e1a2b3c4d');

        self::assertSame(403, $response->getStatusCode());
    }

    public function testAuthorizationIsGrantedTruePassesAndFalseTriggers403(): void
    {
        // True passes
        $request = Request::create('/products', 'GET');
        $this->injectDependencies($request);
        $this->storeService->method('get')->willReturn($this->store);
        $this->productService->method('list')->willReturn([]);
        $response = $this->controller->listAction();
        self::assertSame(200, $response->getStatusCode());

        // False denies
        $checker = $this->createMock(AuthorizationCheckerInterface::class);
        $checker->method('isGranted')->willReturn(false);
        $controller = new ProductController($this->productService, $this->storeService);
        $this->authorizationChecker = $checker;
        $this->controller = $controller;
        $request2 = Request::create('/products', 'GET');
        $this->injectDependencies($request2);
        $this->storeService->method('get')->willReturn($this->store);

        $response2 = $this->controller->listAction();
        self::assertSame(403, $response2->getStatusCode());
    }

    public function testStoreAuthorizationResourceIsProduct(): void
    {
        $ref = new \ReflectionMethod(ProductController::class, 'storeAuthorizationResource');
        $resource = $ref->invoke($this->controller);
        self::assertSame('product', $resource);
    }

    public function testStoreScopedFilterReturnsExpected(): void
    {
        $ref = new \ReflectionMethod(ProductController::class, 'storeScopedFilter');
        $filter = $ref->invoke($this->controller, $this->store);
        self::assertSame(['store' => $this->store, 'isDeleted' => false], $filter);
    }

    public function testCreateRequiresNamePropertyReturns400WhenMissing(): void
    {
        $request = Request::create(
            '/products',
            'POST',
            server: ['CONTENT_TYPE' => 'application/json'],
            content: json_encode(['description' => 'no name'], JSON_THROW_ON_ERROR)
        );
        $this->injectDependencies($request);
        $this->storeService->method('get')->willReturn($this->store);
        $this->productService->method('new')->willReturn(new Product($this->store));
        $this->productService->method('wrapInTransaction')->willReturnCallback(static fn (callable $fn) => $fn(new \stdClass()));

        $response = $this->controller->createAction($request);

        self::assertSame(400, $response->getStatusCode());
        self::assertStringContainsString('Name is required', (string) $response->getContent());
    }

    public function testListUsesCorrectPermissionViaAuthorizationChecker(): void
    {
        $checker = $this->createMock(AuthorizationCheckerInterface::class);
        $checker->expects(self::once())->method('isGranted')->with('store:product:read', $this->store)->willReturn(true);
        $this->authorizationChecker = $checker;
        $this->controller = new ProductController($this->productService, $this->storeService);

        $request = Request::create('/products', 'GET');
        $this->injectDependencies($request);
        $this->storeService->method('get')->willReturn($this->store);
        $this->productService->method('list')->willReturn([]);

        $response = $this->controller->listAction();
        self::assertSame(200, $response->getStatusCode());
    }

    public function testDetailUsesCorrectPermissionViaAuthorizationChecker(): void
    {
        $checker = $this->createMock(AuthorizationCheckerInterface::class);
        $checker->expects(self::once())->method('isGranted')->with('store:product:read', $this->store)->willReturn(true);
        $this->authorizationChecker = $checker;
        $this->controller = new ProductController($this->productService, $this->storeService);

        $product = new Product($this->store);
        $product->setName('Tea');
        $uuid = $product->getUuid();
        $request = Request::create('/products/' . $uuid, 'GET');
        $this->injectDependencies($request);
        $this->storeService->method('get')->willReturn($this->store);
        $this->productService->method('get')->willReturn($product);

        $response = $this->controller->detailAction($uuid);
        self::assertSame(200, $response->getStatusCode());
    }

    private function injectDependencies(Request $request): void
    {
        $request->attributes->set('scopeId', $this->store->getUuid());
        $requestStack = new RequestStack();
        $requestStack->push($request);
        $this->injectWithStack($requestStack);
    }

    private function injectWithStack(RequestStack $requestStack): void
    {
        $serializer = $this->createMock(SerializerInterface::class);
        $serializer->method('serialize')->willReturnCallback(static fn (mixed $data): string => json_encode($data, JSON_THROW_ON_ERROR));
        $translator = $this->createMock(TranslatorInterface::class);
        $translator->method('trans')->willReturnArgument(0);
        $container = $this->createMock(ContainerInterface::class);
        $container->method('has')->willReturn(true);
        $container->method('get')->willReturnCallback(fn (string $id): mixed => match ($id) {
            StoreServiceInterface::class => $this->storeService,
            'security.authorization_checker' => $this->authorizationChecker,
            default => null,
        });

        $this->controller->setRequestStack($requestStack);
        $this->controller->setSerializer($serializer);
        $this->controller->setTranslator($translator);
        $this->controller->setContainer($container);
        $this->controller->setServiceContainer($container);
    }
}
