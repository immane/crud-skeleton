<?php

declare(strict_types=1);

namespace App\Tests\UnitTest\Store\Controller\Staff;

use App\Identity\Entity\User;
use App\Store\Controller\Staff\StoreOrderController;
use App\Store\Entity\Store;
use App\Store\Entity\StoreOrder;
use App\Store\Service\StoreOrderServiceInterface;
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
final class StoreOrderControllerTest extends TestCase
{
    private Store $store;
    private User $user;
    private StoreOrderServiceInterface $orderService;
    private StoreServiceInterface $storeService;
    private AuthorizationCheckerInterface $authorizationChecker;
    private StoreOrderController $controller;

    protected function setUp(): void
    {
        $this->store = new Store('demo', 'Demo', 'Asia/Shanghai');
        $this->user = new User();
        $this->orderService = $this->createMock(StoreOrderServiceInterface::class);
        $this->storeService = $this->createMock(StoreServiceInterface::class);
        $this->authorizationChecker = $this->createMock(AuthorizationCheckerInterface::class);
        $this->authorizationChecker->method('isGranted')->willReturn(true);
        $this->controller = new StoreOrderController($this->orderService, $this->storeService);
    }

    public function testListUsesCoreLifecycleAuthorizationAndStoreFilter(): void
    {
        $request = Request::create('/orders', 'GET');
        $this->injectDependencies($request);
        $this->storeService->method('get')->with(['uuid' => $this->store->getUuid()])->willReturn($this->store);
        $this->orderService->expects(self::once())->method('list')->with(['store' => $this->store], null, false)->willReturn([]);

        $response = $this->controller->listAction($this->store->getUuid());

        self::assertSame(200, $response->getStatusCode());
    }

    private function order(): StoreOrder
    {
        return new StoreOrder(
            $this->store,
            '2beed699-4e1b-4a49-af75-2e0b0f6db0fd',
            'demo',
            'Demo',
            $this->user->getUuid(),
            'CNY',
            12800,
            ['items' => [], 'delivery' => [], 'placedAt' => '2026-07-24T12:00:00+00:00'],
        );
    }

    private function request(string $content): Request
    {
        return Request::create('/accept', 'POST', server: ['CONTENT_TYPE' => 'application/json'], content: $content);
    }

    private function injectDependencies(Request $request): void
    {
        $request->attributes->set('scopeId', $this->store->getUuid());
        $requestStack = new RequestStack();
        $requestStack->push($request);

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
