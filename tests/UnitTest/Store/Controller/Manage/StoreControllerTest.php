<?php

declare(strict_types=1);

namespace App\Tests\UnitTest\Store\Controller\Manage;

use App\Store\Controller\Manage\StoreController;
use App\Store\Entity\Store;
use App\Store\Entity\Membership;
use App\Store\Service\MembershipServiceInterface;
use App\Store\Service\StoreServiceInterface;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Serializer\SerializerInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

#[AllowMockObjectsWithoutExpectations]
final class StoreControllerTest extends TestCase
{
    private StoreServiceInterface $service;
    private MembershipServiceInterface $membershipService;
    private StoreController $controller;

    protected function setUp(): void
    {
        $this->service = $this->createMock(StoreServiceInterface::class);
        $this->membershipService = $this->createMock(MembershipServiceInterface::class);
        $this->controller = new StoreController($this->service, $this->membershipService);
    }

    public function testStatusActionReturns404WhenStoreNotFound(): void
    {
        $storeUuid = '00000000-0000-4000-8000-000000000000';
        $request = Request::create('/status/suspend', 'POST');
        $this->injectDependencies($request);
        $this->service->method('get')->with(['uuid' => $storeUuid])->willReturn(null);

        $response = $this->controller->statusAction($storeUuid, 'suspend');

        self::assertSame(404, $response->getStatusCode());
        $body = json_decode((string) $response->getContent(), true);
        self::assertSame('Store not found.', $body['message']);
    }

    public function testStatusActionUpdatesAndSuspendsStore(): void
    {
        $store = new Store('demo', 'Demo', 'Asia/Shanghai');
        $request = Request::create('/status/suspend', 'POST');
        $this->injectDependencies($request);
        $this->service->method('get')->with(['uuid' => $store->getUuid()])->willReturn($store);
        $this->service->method('update')->with($store, [])->willReturn($store);

        $response = $this->controller->statusAction($store->getUuid(), 'suspend');

        self::assertSame(200, $response->getStatusCode());
        self::assertSame(Store::STATUS_SUSPENDED, $store->getStatus());
        self::assertFalse($store->isActive());
    }

    public function testListMembersActionReturns404WhenStoreNotFound(): void
    {
        $storeUuid = '00000000-0000-4000-8000-000000000000';
        $request = Request::create('/members', 'GET');
        $this->injectDependencies($request);
        $this->service->method('get')->with(['uuid' => $storeUuid])->willReturn(null);

        $response = $this->controller->listMembersAction($storeUuid);

        self::assertSame(404, $response->getStatusCode());
        $body = json_decode((string) $response->getContent(), true);
        self::assertSame('Store not found.', $body['message']);
    }

    public function testListMembersActionReturnsMemberships(): void
    {
        $store = new Store('demo', 'Demo', 'Asia/Shanghai');
        $membership = new Membership($store, '47d07ad3-7e6e-4bfb-aea3-87bdb0e4de57', Membership::ROLE_MANAGER);
        $request = Request::create('/members', 'GET');
        $this->injectDependencies($request);
        $this->service->method('get')->with(['uuid' => $store->getUuid()])->willReturn($store);
        $this->membershipService->method('list')->with(['store' => $store], ['entity.createdAt' => 'ASC'], false)->willReturn([$membership]);

        $response = $this->controller->listMembersAction($store->getUuid());

        self::assertSame(200, $response->getStatusCode());
        $body = json_decode((string) $response->getContent(), true);
        self::assertSame(0, $body['code']);
    }

    public function testGrantMemberActionReturns404WhenStoreNotFound(): void
    {
        $storeUuid = '00000000-0000-4000-8000-000000000000';
        $request = $this->jsonRequest('/members', ['userUuid' => '47d07ad3-7e6e-4bfb-aea3-87bdb0e4de57', 'role' => 'manager']);
        $this->injectDependencies($request);
        $this->service->method('get')->with(['uuid' => $storeUuid])->willReturn(null);

        $response = $this->controller->grantMemberAction($request, $storeUuid);

        self::assertSame(404, $response->getStatusCode());
        $body = json_decode((string) $response->getContent(), true);
        self::assertSame('Store not found.', $body['message']);
    }

    public function testGrantMemberActionReturns400WhenDataInvalid(): void
    {
        $store = new Store('demo', 'Demo', 'Asia/Shanghai');
        $request = $this->jsonRequest('/members', ['userUuid' => 123]);
        $this->injectDependencies($request);
        $this->service->method('get')->with(['uuid' => $store->getUuid()])->willReturn($store);

        $response = $this->controller->grantMemberAction($request, $store->getUuid());

        self::assertSame(400, $response->getStatusCode());
        $body = json_decode((string) $response->getContent(), true);
        self::assertSame('userUuid and role are required.', $body['message']);
    }

    public function testGrantMemberActionReturns400WhenGrantFails(): void
    {
        $store = new Store('demo', 'Demo', 'Asia/Shanghai');
        $request = $this->jsonRequest('/members', ['userUuid' => '47d07ad3-7e6e-4bfb-aea3-87bdb0e4de57', 'role' => 'administrator']);
        $this->injectDependencies($request);
        $this->service->method('get')->with(['uuid' => $store->getUuid()])->willReturn($store);
        $this->membershipService->method('grant')->willThrowException(new \InvalidArgumentException('Invalid store membership role.'));

        $response = $this->controller->grantMemberAction($request, $store->getUuid());

        self::assertSame(400, $response->getStatusCode());
        $body = json_decode((string) $response->getContent(), true);
        self::assertSame('Invalid store membership role.', $body['message']);
    }

    public function testGrantMemberActionGrantsMembership(): void
    {
        $store = new Store('demo', 'Demo', 'Asia/Shanghai');
        $membership = new Membership($store, '47d07ad3-7e6e-4bfb-aea3-87bdb0e4de57', Membership::ROLE_MANAGER);
        $request = $this->jsonRequest('/members', ['userUuid' => '47d07ad3-7e6e-4bfb-aea3-87bdb0e4de57', 'role' => 'manager']);
        $this->injectDependencies($request);
        $this->service->method('get')->with(['uuid' => $store->getUuid()])->willReturn($store);
        $this->membershipService->method('grant')->with($store, '47d07ad3-7e6e-4bfb-aea3-87bdb0e4de57', 'manager')->willReturn($membership);

        $response = $this->controller->grantMemberAction($request, $store->getUuid());

        self::assertSame(201, $response->getStatusCode());
        $body = json_decode((string) $response->getContent(), true);
        self::assertSame('Membership granted.', $body['message']);
    }

    public function testCreateActionRejectsEmptyCode(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('code must be a non-empty string.');
        $this->processCreate(['code' => ' ', 'name' => 'Demo Store', 'timezone' => 'UTC']);
    }

    public function testCreateActionRejectsEmptyName(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('name must be a non-empty string.');
        $this->processCreate(['code' => 'demo', 'name' => '', 'timezone' => 'UTC']);
    }

    public function testCreateActionRejectsNonStringTimezone(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('timezone must be a string.');
        $this->processCreate(['code' => 'demo', 'name' => 'Demo Store', 'timezone' => 123]);
    }

    public function testCreateActionRejectsInvalidTimezoneValue(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('timezone must be a valid timezone.');
        $this->processCreate(['code' => 'demo', 'name' => 'Demo Store', 'timezone' => 'Invalid/Timezone']);
    }

    #[Group('low-value')]
    public function testProcessCreateContentReturnsValidContent(): void
    {
        $content = ['code' => 'demo', 'name' => 'Demo Store', 'timezone' => 'UTC'];

        $result = (new \ReflectionMethod(StoreController::class, 'processCreateContent'))->invoke($this->controller, $content, new Store());

        self::assertSame($content, $result);
    }

    #[Group('low-value')]
    public function testProcessUpdateContentReturnsValidContent(): void
    {
        $content = ['name' => 'Updated Store', 'settings' => ['acceptingOrders' => true]];

        $result = (new \ReflectionMethod(StoreController::class, 'processUpdateContent'))->invoke($this->controller, $content, new Store());

        self::assertSame($content, $result);
    }

    /** @param array<string, mixed> $content */
    private function processCreate(array $content): void
    {
        (new \ReflectionMethod(StoreController::class, 'processCreateContent'))->invoke($this->controller, $content, new Store());
    }

    public function testUpdateActionRejectsNonArraySettings(): void
    {
        $store = new Store('demo', 'Demo', 'Asia/Shanghai');
        $request = $this->jsonRequest('/' . $store->getUuid(), ['settings' => 'not-an-object']);
        $this->injectDependencies($request);
        $this->service->method('get')->with(['uuid' => $store->getUuid()])->willReturn($store);

        $response = $this->controller->updateAction($request, $store->getUuid());

        self::assertSame(400, $response->getStatusCode());
        $body = json_decode((string) $response->getContent(), true);
        self::assertSame('settings must be an object or null.', $body['message']);
    }

    private function jsonRequest(string $path, array $data): Request
    {
        return Request::create($path, 'POST', server: ['CONTENT_TYPE' => 'application/json'], content: json_encode($data, JSON_THROW_ON_ERROR));
    }

    private function injectDependencies(Request $request): void
    {
        $requestStack = new RequestStack();
        $requestStack->push($request);

        $serializer = $this->createMock(SerializerInterface::class);
        $serializer->method('serialize')->willReturnCallback(
            fn ($data, $format) => json_encode($data, JSON_THROW_ON_ERROR)
        );
        $translator = $this->createMock(TranslatorInterface::class);
        $translator->method('trans')->willReturnArgument(0);

        $container = $this->createMock(ContainerInterface::class);
        $container->method('has')->willReturn(true);

        $this->controller->setRequestStack($requestStack);
        $this->controller->setSerializer($serializer);
        $this->controller->setTranslator($translator);
        $this->controller->setContainer($container);
    }
}
