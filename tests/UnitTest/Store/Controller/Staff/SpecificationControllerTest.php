<?php

declare(strict_types=1);

namespace App\Tests\UnitTest\Store\Controller\Staff;

use App\Store\Controller\Staff\SpecificationController;
use App\Store\Entity\Product;
use App\Store\Entity\Specification;
use App\Store\Entity\Store;
use App\Store\Service\ProductServiceInterface;
use App\Store\Service\SpecificationServiceInterface;
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
final class SpecificationControllerTest extends TestCase
{
    private Store $store;
    private Product $product;
    private SpecificationServiceInterface $specService;
    private ProductServiceInterface $productService;
    private StoreServiceInterface $storeService;
    private AuthorizationCheckerInterface $authorizationChecker;
    private SpecificationController $controller;

    protected function setUp(): void
    {
        $this->store = new Store('demo', 'Demo', 'Asia/Shanghai');
        $this->product = new Product($this->store);
        $this->product->setName('Tea');
        $this->specService = $this->createMock(SpecificationServiceInterface::class);
        $this->productService = $this->createMock(ProductServiceInterface::class);
        $this->storeService = $this->createMock(StoreServiceInterface::class);
        $this->authorizationChecker = $this->createMock(AuthorizationCheckerInterface::class);
        $this->authorizationChecker->method('isGranted')->willReturn(true);
        $this->controller = new SpecificationController($this->specService, $this->productService, $this->storeService);
    }

    public function testCommonFilterDelegatesToStoreScopedFilterWithStoreScope(): void
    {
        $request = Request::create('/specs', 'GET');
        $this->injectDependencies($request);
        $this->storeService->method('get')->willReturn($this->store);
        $this->productService->method('get')->with([
            'uuid' => $this->product->getUuid(),
            'store' => $this->store,
            'isDeleted' => false,
        ], false)->willReturn($this->product);
        $this->specService->expects(self::once())
            ->method('list')
            ->with(['product' => $this->product, 'isDeleted' => false], null, false)
            ->willReturn([]);

        $response = $this->controller->listAction();

        self::assertSame(200, $response->getStatusCode());
    }

    public function testCommonFilterReturnsIdMinusOneWhenProductNotInStore(): void
    {
        $request = Request::create('/specs', 'GET');
        $this->injectDependencies($request);
        $this->storeService->method('get')->willReturn($this->store);
        $this->productService->method('get')->willReturn(null);
        $this->specService->expects(self::once())
            ->method('list')
            ->with(['id' => -1], null, false)
            ->willReturn([]);

        $response = $this->controller->listAction();

        self::assertSame(200, $response->getStatusCode());
        $body = json_decode((string) $response->getContent(), true, JSON_THROW_ON_ERROR);
        self::assertSame([], $body['data']);
    }

    public function testStoreServiceResolutionHappyFindsStore(): void
    {
        $request = Request::create('/specs', 'GET');
        $this->injectDependencies($request);
        $this->storeService->method('get')->willReturn($this->store);
        $this->productService->method('get')->willReturn($this->product);
        $this->specService->method('list')->willReturn([]);

        $response = $this->controller->listAction();
        self::assertSame(200, $response->getStatusCode());
    }

    public function testStoreServiceResolutionSadNotFoundMapsTo403(): void
    {
        $request = Request::create('/specs', 'GET');
        $this->injectDependencies($request);
        $this->storeService->method('get')->willReturn(null);

        $response = $this->controller->listAction();

        self::assertSame(403, $response->getStatusCode());
        self::assertStringContainsString('Store not found', (string) $response->getContent());
    }

    public function testStoreServiceResolutionMissingScopeIdMapsTo403(): void
    {
        $request = Request::create('/specs', 'GET');
        $request->attributes->set('productUuid', $this->product->getUuid());
        // no scopeId
        $stack = new RequestStack();
        $stack->push($request);
        $this->injectWithStack($stack);
        $this->storeService->expects(self::never())->method('get');

        $response = $this->controller->listAction();

        self::assertSame(403, $response->getStatusCode());
        self::assertStringContainsString('Store scope is required', (string) $response->getContent());
    }

    public function testCreateBindsSpecificationToProductHappy(): void
    {
        $request = Request::create(
            '/specs',
            'POST',
            server: ['CONTENT_TYPE' => 'application/json'],
            content: json_encode(['name' => 'Large', 'price' => 6400], JSON_THROW_ON_ERROR)
        );
        $this->injectDependencies($request);
        $this->storeService->method('get')->willReturn($this->store);
        $this->productService->method('get')->willReturn($this->product);

        $spec = new Specification();
        $spec->setName('initial');
        $spec->setPrice(100);
        $this->specService->method('new')->willReturn($spec);
        $this->specService->method('wrapInTransaction')->willReturnCallback(static fn (callable $fn) => $fn(new \stdClass()));
        $this->specService->method('update')->willReturnCallback(static function (object $entity, ?array $data): object {
            if ($entity instanceof Specification && isset($data['name'])) {
                $entity->setName($data['name']);
            }
            if ($entity instanceof Specification && isset($data['price'])) {
                $entity->setPrice((int) $data['price']);
            }

            return $entity;
        });

        $response = $this->controller->createAction($request);

        self::assertSame(201, $response->getStatusCode());
        self::assertSame($this->product, $spec->getProduct());
        self::assertSame('Large', $spec->getName());
        self::assertSame(6400, $spec->getPrice());
    }

    public function testCreateSadWhenProductNotInStoreReturns404(): void
    {
        $request = Request::create(
            '/specs',
            'POST',
            server: ['CONTENT_TYPE' => 'application/json'],
            content: json_encode(['name' => 'Large', 'price' => 6400], JSON_THROW_ON_ERROR)
        );
        $this->injectDependencies($request);
        $this->storeService->method('get')->willReturn($this->store);
        $this->productService->method('get')->willReturn(null);
        $this->specService->method('new')->willReturn(new Specification());
        $this->specService->method('wrapInTransaction')->willReturnCallback(static fn (callable $fn) => $fn(new \stdClass()));

        $response = $this->controller->createAction($request);

        self::assertSame(404, $response->getStatusCode());
        self::assertStringContainsString('Product not found', (string) $response->getContent());
    }

    public function testCreateSadWhenProductDeletedReturns404(): void
    {
        // Deleted product is filtered with isDeleted=false, so get returns null
        $request = Request::create(
            '/specs',
            'POST',
            server: ['CONTENT_TYPE' => 'application/json'],
            content: json_encode(['name' => 'Large', 'price' => 6400], JSON_THROW_ON_ERROR)
        );
        $this->injectDependencies($request);
        $this->storeService->method('get')->willReturn($this->store);
        $this->productService->method('get')->with([
            'uuid' => $this->product->getUuid(),
            'store' => $this->store,
            'isDeleted' => false,
        ], false)->willReturn(null);
        $this->specService->method('new')->willReturn(new Specification());
        $this->specService->method('wrapInTransaction')->willReturnCallback(static fn (callable $fn) => $fn(new \stdClass()));

        $response = $this->controller->createAction($request);

        self::assertSame(404, $response->getStatusCode());
    }

    public function testCreateSadWhenStoreNotFoundReturns403(): void
    {
        $request = Request::create(
            '/specs',
            'POST',
            server: ['CONTENT_TYPE' => 'application/json'],
            content: json_encode(['name' => 'Large', 'price' => 6400], JSON_THROW_ON_ERROR)
        );
        $this->injectDependencies($request);
        $this->storeService->method('get')->willReturn(null);

        $response = $this->controller->createAction($request);

        self::assertSame(403, $response->getStatusCode());
    }

    public function testCreateSadWhenAuthorizationDeniedReturns403(): void
    {
        $checker = $this->createMock(AuthorizationCheckerInterface::class);
        $checker->method('isGranted')->with('store:specification:create', $this->store)->willReturn(false);
        $this->authorizationChecker = $checker;
        $this->controller = new SpecificationController($this->specService, $this->productService, $this->storeService);

        $request = Request::create(
            '/specs',
            'POST',
            server: ['CONTENT_TYPE' => 'application/json'],
            content: json_encode(['name' => 'Large', 'price' => 6400], JSON_THROW_ON_ERROR)
        );
        $this->injectDependencies($request);
        $this->storeService->method('get')->willReturn($this->store);

        $response = $this->controller->createAction($request);

        self::assertSame(403, $response->getStatusCode());
    }

    public function testListHappyReturnsStoreScopedFilter(): void
    {
        $request = Request::create('/specs', 'GET');
        $this->injectDependencies($request);
        $this->storeService->method('get')->willReturn($this->store);
        $this->productService->method('get')->willReturn($this->product);
        $this->specService->expects(self::once())->method('list')->with(['product' => $this->product, 'isDeleted' => false], null, false)->willReturn([]);

        $response = $this->controller->listAction();
        self::assertSame(200, $response->getStatusCode());
    }

    public function testListSadCrossStoreReturnsEmptyFilterIdMinusOne(): void
    {
        $request = Request::create('/specs', 'GET');
        $this->injectDependencies($request);
        $this->storeService->method('get')->willReturn($this->store);
        $this->productService->method('get')->willReturn(null);
        $this->specService->expects(self::once())->method('list')->with(['id' => -1], null, false)->willReturn([]);

        $response = $this->controller->listAction();
        self::assertSame(200, $response->getStatusCode());
    }

    public function testListSadWhenAuthorizationDeniedReturns403(): void
    {
        $checker = $this->createMock(AuthorizationCheckerInterface::class);
        $checker->method('isGranted')->willReturn(false);
        $this->authorizationChecker = $checker;
        $this->controller = new SpecificationController($this->specService, $this->productService, $this->storeService);

        $request = Request::create('/specs', 'GET');
        $this->injectDependencies($request);
        $this->storeService->method('get')->willReturn($this->store);

        $response = $this->controller->listAction();
        self::assertSame(403, $response->getStatusCode());
    }

    public function testDetailHappyReturnsStoreScopedFilter(): void
    {
        $spec = new Specification();
        $spec->setName('Large');
        $spec->setPrice(6400);
        $spec->setProduct($this->product);
        $uuid = $spec->getUuid();

        $request = Request::create('/specs/' . $uuid, 'GET');
        $this->injectDependencies($request);
        $this->storeService->method('get')->willReturn($this->store);
        $this->productService->method('get')->willReturn($this->product);
        $this->specService->expects(self::once())
            ->method('get')
            ->with(['uuid' => $uuid, 'product' => $this->product, 'isDeleted' => false], false)
            ->willReturn($spec);

        $response = $this->controller->detailAction($uuid);
        self::assertSame(200, $response->getStatusCode());
    }

    public function testDetailSadCrossStoreReturns404(): void
    {
        // When product not in store, filter is ['id'=>-1] merged with uuid => ['uuid'=>id,'id'=>-1] => service returns null => 404
        $uuid = 'd2e7f2e2-7b1e-4f9a-9c3a-0b9e1a2b3c4d';
        $request = Request::create('/specs/' . $uuid, 'GET');
        $this->injectDependencies($request);
        $this->storeService->method('get')->willReturn($this->store);
        $this->productService->method('get')->willReturn(null);
        $this->specService->method('get')->willReturn(null);

        $response = $this->controller->detailAction($uuid);
        self::assertSame(404, $response->getStatusCode());
    }

    public function testDetailSadWhenProductDeletedReturnsEmptyFilterAnd404(): void
    {
        $uuid = 'd2e7f2e2-7b1e-4f9a-9c3a-0b9e1a2b3c4d';
        $request = Request::create('/specs/' . $uuid, 'GET');
        $this->injectDependencies($request);
        $this->storeService->method('get')->willReturn($this->store);
        $this->productService->method('get')->willReturn(null);
        $this->specService->method('get')->with(['uuid' => $uuid, 'id' => -1], false)->willReturn(null);

        $response = $this->controller->detailAction($uuid);
        self::assertSame(404, $response->getStatusCode());
    }

    public function testDetailSadWhenStoreNotFoundReturns403(): void
    {
        $request = Request::create('/specs/x', 'GET');
        $this->injectDependencies($request);
        $this->storeService->method('get')->willReturn(null);

        $response = $this->controller->detailAction('d2e7f2e2-7b1e-4f9a-9c3a-0b9e1a2b3c4d');
        self::assertSame(403, $response->getStatusCode());
    }

    public function testUpdateHappy(): void
    {
        $spec = new Specification();
        $spec->setName('Old');
        $spec->setPrice(1000);
        $spec->setProduct($this->product);
        $uuid = $spec->getUuid();

        $request = Request::create(
            '/specs/' . $uuid,
            'PUT',
            server: ['CONTENT_TYPE' => 'application/json'],
            content: json_encode(['name' => 'New', 'price' => 2000], JSON_THROW_ON_ERROR)
        );
        $this->injectDependencies($request);
        $this->storeService->method('get')->willReturn($this->store);
        $this->productService->method('get')->willReturn($this->product);
        $this->specService->method('get')->with(['uuid' => $uuid, 'product' => $this->product, 'isDeleted' => false], false)->willReturn($spec);
        $this->specService->method('update')->willReturnCallback(static function (object $entity, ?array $data): object {
            if ($entity instanceof Specification && isset($data['name'])) {
                $entity->setName($data['name']);
            }
            if ($entity instanceof Specification && isset($data['price'])) {
                $entity->setPrice((int) $data['price']);
            }

            return $entity;
        });

        $response = $this->controller->updateAction($request, $uuid);
        self::assertSame(200, $response->getStatusCode());
        self::assertSame('New', $spec->getName());
        self::assertSame(2000, $spec->getPrice());
    }

    public function testUpdateSadWhenProductNotInStoreReturns404(): void
    {
        $uuid = 'd2e7f2e2-7b1e-4f9a-9c3a-0b9e1a2b3c4d';
        $request = Request::create(
            '/specs/' . $uuid,
            'PUT',
            server: ['CONTENT_TYPE' => 'application/json'],
            content: json_encode(['name' => 'New', 'price' => 2000], JSON_THROW_ON_ERROR)
        );
        $this->injectDependencies($request);
        $this->storeService->method('get')->willReturn($this->store);
        $this->productService->method('get')->willReturn($this->product);
        $this->specService->method('get')->willReturn(null);

        $response = $this->controller->updateAction($request, $uuid);
        self::assertSame(404, $response->getStatusCode());
    }

    public function testDeleteSoftDeleteHappyReturns204AndMarksDeleted(): void
    {
        $spec = new Specification();
        $spec->setName('ToDelete');
        $spec->setPrice(1000);
        $spec->setProduct($this->product);
        self::assertFalse($spec->getIsDeleted());
        $uuid = $spec->getUuid();
        $request = Request::create('/specs/' . $uuid, 'DELETE');
        $this->injectDependencies($request);
        $this->storeService->method('get')->willReturn($this->store);
        $this->productService->method('get')->willReturn($this->product);
        $this->specService->method('get')->with(['uuid' => $uuid, 'product' => $this->product, 'isDeleted' => false], false)->willReturn($spec);
        $this->specService->expects(self::once())->method('update')->with($spec, [])->willReturn($spec);

        $response = $this->controller->deleteAction($uuid);
        self::assertSame(204, $response->getStatusCode());
        self::assertTrue($spec->getIsDeleted());
    }

    public function testDeleteSadWhenAlreadyDeletedReturns404(): void
    {
        $uuid = 'd2e7f2e2-7b1e-4f9a-9c3a-0b9e1a2b3c4d';
        $request = Request::create('/specs/' . $uuid, 'DELETE');
        $this->injectDependencies($request);
        $this->storeService->method('get')->willReturn($this->store);
        $this->productService->method('get')->willReturn($this->product);
        $this->specService->method('get')->willReturn(null);

        $response = $this->controller->deleteAction($uuid);
        self::assertSame(404, $response->getStatusCode());
    }

    public function testDeleteSadWhenNotInStoreReturnsEmptyFilterAnd404(): void
    {
        $uuid = 'd2e7f2e2-7b1e-4f9a-9c3a-0b9e1a2b3c4d';
        $request = Request::create('/specs/' . $uuid, 'DELETE');
        $this->injectDependencies($request);
        $this->storeService->method('get')->willReturn($this->store);
        $this->productService->method('get')->willReturn(null);
        $this->specService->method('get')->willReturn(null);

        $response = $this->controller->deleteAction($uuid);
        self::assertSame(404, $response->getStatusCode());
    }

    public function testDeleteSadWhenAuthorizationDeniedReturns403(): void
    {
        $checker = $this->createMock(AuthorizationCheckerInterface::class);
        $checker->method('isGranted')->willReturn(false);
        $this->authorizationChecker = $checker;
        $this->controller = new SpecificationController($this->specService, $this->productService, $this->storeService);

        $request = Request::create('/specs/x', 'DELETE');
        $this->injectDependencies($request);
        $this->storeService->method('get')->willReturn($this->store);

        $response = $this->controller->deleteAction('d2e7f2e2-7b1e-4f9a-9c3a-0b9e1a2b3c4d');
        self::assertSame(403, $response->getStatusCode());
    }

    public function testAuthorizationIsGrantedTruePassesAndFalseTriggers403(): void
    {
        $request = Request::create('/specs', 'GET');
        $this->injectDependencies($request);
        $this->storeService->method('get')->willReturn($this->store);
        $this->productService->method('get')->willReturn($this->product);
        $this->specService->method('list')->willReturn([]);
        $response = $this->controller->listAction();
        self::assertSame(200, $response->getStatusCode());

        $checker = $this->createMock(AuthorizationCheckerInterface::class);
        $checker->method('isGranted')->willReturn(false);
        $controller = new SpecificationController($this->specService, $this->productService, $this->storeService);
        $this->authorizationChecker = $checker;
        $this->controller = $controller;
        $request2 = Request::create('/specs', 'GET');
        $this->injectDependencies($request2);
        $this->storeService->method('get')->willReturn($this->store);

        $response2 = $this->controller->listAction();
        self::assertSame(403, $response2->getStatusCode());
    }

    public function testStoreAuthorizationResourceIsSpecification(): void
    {
        $ref = new \ReflectionMethod(SpecificationController::class, 'storeAuthorizationResource');
        $resource = $ref->invoke($this->controller);
        self::assertSame('specification', $resource);
    }

    public function testStoreScopedFilterReturnsExpectedHappy(): void
    {
        $ref = new \ReflectionMethod(SpecificationController::class, 'storeScopedFilter');
        $request = Request::create('/specs', 'GET');
        $this->injectDependencies($request);
        $this->storeService->method('get')->willReturn($this->store);
        $this->productService->method('get')->willReturn($this->product);
        // need storeProduct to work via request attributes
        $filter = $ref->invoke($this->controller, $this->store);
        self::assertSame(['product' => $this->product, 'isDeleted' => false], $filter);
    }

    public function testStoreScopedFilterReturnsIdMinusOneSad(): void
    {
        $ref = new \ReflectionMethod(SpecificationController::class, 'storeScopedFilter');
        $request = Request::create('/specs', 'GET');
        $this->injectDependencies($request);
        $this->storeService->method('get')->willReturn($this->store);
        $this->productService->method('get')->willReturn(null);
        $filter = $ref->invoke($this->controller, $this->store);
        self::assertSame(['id' => -1], $filter);
    }

    public function testCreateRequiresPricePropertyReturns400WhenMissing(): void
    {
        $request = Request::create(
            '/specs',
            'POST',
            server: ['CONTENT_TYPE' => 'application/json'],
            content: json_encode(['name' => 'Large'], JSON_THROW_ON_ERROR)
        );
        $this->injectDependencies($request);
        $this->storeService->method('get')->willReturn($this->store);
        $this->productService->method('get')->willReturn($this->product);
        $this->specService->method('new')->willReturn(new Specification());
        $this->specService->method('wrapInTransaction')->willReturnCallback(static fn (callable $fn) => $fn(new \stdClass()));

        $response = $this->controller->createAction($request);
        self::assertSame(400, $response->getStatusCode());
        self::assertStringContainsString('Price is required', (string) $response->getContent());
    }

    public function testListUsesCorrectPermissionViaAuthorizationChecker(): void
    {
        $checker = $this->createMock(AuthorizationCheckerInterface::class);
        $checker->expects(self::once())->method('isGranted')->with('store:specification:read', $this->store)->willReturn(true);
        $this->authorizationChecker = $checker;
        $this->controller = new SpecificationController($this->specService, $this->productService, $this->storeService);

        $request = Request::create('/specs', 'GET');
        $this->injectDependencies($request);
        $this->storeService->method('get')->willReturn($this->store);
        $this->productService->method('get')->willReturn($this->product);
        $this->specService->method('list')->willReturn([]);

        $response = $this->controller->listAction();
        self::assertSame(200, $response->getStatusCode());
    }

    private function injectDependencies(Request $request): void
    {
        $request->attributes->set('scopeId', $this->store->getUuid());
        $request->attributes->set('productUuid', $this->product->getUuid());
        $stack = new RequestStack();
        $stack->push($request);
        $this->injectWithStack($stack);
    }

    private function injectWithStack(RequestStack $stack): void
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

        $this->controller->setRequestStack($stack);
        $this->controller->setSerializer($serializer);
        $this->controller->setTranslator($translator);
        $this->controller->setContainer($container);
        $this->controller->setServiceContainer($container);
    }
}
