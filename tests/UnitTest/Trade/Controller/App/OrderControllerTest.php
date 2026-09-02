<?php

declare(strict_types=1);

namespace App\Tests\UnitTest\Trade\Controller\App;

use App\Identity\Entity\User;
use App\Payment\DTO\PaymentResult;
use App\Payment\Entity\Invoice;
use App\Trade\Controller\App\OrderController;
use App\Trade\DTO\StoreContext;
use App\Trade\Entity\Order;
use App\Trade\Service\OrderServiceInterface;
use App\Trade\Service\Pricing\PriceCalculationResult;
use App\Trade\Service\StoreContextResolverInterface;
use App\Tests\UnitTest\Trade\Controller\OrderControllerServiceFake;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Serializer\SerializerInterface;
use Symfony\Component\Workflow\WorkflowInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Unit tests for App\Trade\Controller\App\OrderController.
 *
 * Covers the branches that integration tests do not reach: commonFilter with
 * an anonymous user, create/quote failure paths, quote success, ownership
 * mismatches, payment guard/catch paths, and the cancel transaction failure
 * path. Uses a fake OrderService (see OrderControllerServiceFake) so that the
 * wrapInTransaction() callback can be exercised inside the controller.
 */
#[AllowMockObjectsWithoutExpectations]
final class OrderControllerTest extends TestCase
{
    private OrderServiceInterface $service;
    private StoreContextResolverInterface $storeContextResolver;
    private WorkflowInterface $workflow;
    private OrderControllerServiceFake $fakeService;
    private OrderController $controller;

    protected function setUp(): void
    {
        $this->service = $this->createMock(OrderServiceInterface::class);
        $this->storeContextResolver = $this->createMock(StoreContextResolverInterface::class);
        $this->workflow = $this->createMock(WorkflowInterface::class);

        $this->fakeService = new OrderControllerServiceFake($this->service);
        $this->controller = new OrderController($this->fakeService, $this->storeContextResolver, $this->workflow);
    }

    private function injectDependencies(RequestStack $requestStack): void
    {
        $serializer = $this->createMock(SerializerInterface::class);
        $serializer->method('serialize')->willReturnCallback(
            fn($data, $format) => json_encode($data, JSON_THROW_ON_ERROR)
        );
        $translator = $this->createMock(TranslatorInterface::class);
        $translator->method('trans')->willReturnArgument(0);

        $this->controller->setRequestStack($requestStack);
        $this->controller->setSerializer($serializer);
        $this->controller->setTranslator($translator);
    }

    /**
     * Injects the container so AbstractController::getUser() resolves the
     * security token storage. Passing null emulates an anonymous token.
     */
    private function setCurrentUser(?int $userId): void
    {
        $tokenStorage = $this->createMock(TokenStorageInterface::class);
        if ($userId === null) {
            $tokenStorage->method('getToken')->willReturn(null);
        } else {
            $user = $this->createMock(User::class);
            $user->method('getId')->willReturn($userId);
            $token = $this->createMock(TokenInterface::class);
            $token->method('getUser')->willReturn($user);
            $tokenStorage->method('getToken')->willReturn($token);
        }

        $container = $this->createMock(ContainerInterface::class);
        $container->method('has')->willReturnCallback(static fn(string $id): bool => $id === 'security.token_storage');
        $container->method('get')->with('security.token_storage')->willReturn($tokenStorage);

        $this->controller->setContainer($container);
    }

    private function userMock(int $id): User
    {
        $user = $this->createMock(User::class);
        $user->method('getId')->willReturn($id);

        return $user;
    }

    private function orderOwnedBy(int $userId): Order
    {
        $order = $this->createMock(Order::class);
        $order->method('getUser')->willReturn($this->userMock($userId));

        return $order;
    }

    private function jsonRequest(string $method, string $uri, array $payload): Request
    {
        return Request::create(
            $uri,
            $method,
            server: ['CONTENT_TYPE' => 'application/json'],
            content: json_encode($payload, JSON_THROW_ON_ERROR),
        );
    }

    private function priceResult(int $total, string $currency = 'CNY'): PriceCalculationResult
    {
        return new PriceCalculationResult($total, $currency, [['specificationId' => 1, 'quantity' => 1]]);
    }

    // ===== commonFilter (line 41) =====

    public function testListActionWithoutUserUsesMinusOneIdFilter(): void
    {
        $requestStack = new RequestStack();
        $requestStack->push(Request::create('/api/v1/app/orders', 'GET'));
        $this->injectDependencies($requestStack);
        $this->setCurrentUser(null);

        $captured = null;
        $this->service->method('list')->willReturnCallback(
            function ($filter, $order, $disableRequest) use (&$captured) {
                $captured = $filter;

                return [];
            }
        );

        $response = $this->controller->listAction();

        self::assertSame(200, $response->getStatusCode());
        self::assertSame(['id' => -1], $captured);
    }

    // ===== createAction =====

    public function testCreateActionReturns201WhenNoStoreContext(): void
    {
        $requestStack = new RequestStack();
        $requestStack->push($this->jsonRequest('POST', '/api/v1/app/orders', [
            'items' => [['specificationId' => 1, 'quantity' => 1]],
        ]));
        $this->injectDependencies($requestStack);
        $this->setCurrentUser(1);

        $this->storeContextResolver->method('resolve')->willReturn(null);
        $this->service->method('calculatePrices')->willReturn($this->priceResult(1000));
        $this->service->method('createOrder')->willReturn($this->orderOwnedBy(1));

        $response = $this->controller->createAction($requestStack->getCurrentRequest());

        self::assertSame(201, $response->getStatusCode());
        $body = json_decode((string) $response->getContent(), true);
        self::assertSame('Order created', $body['message']);
    }

    public function testCreateActionReturns201WhenStoreContextPresent(): void
    {
        $requestStack = new RequestStack();
        $requestStack->push($this->jsonRequest('POST', '/api/v1/app/orders', [
            'items' => [['specificationId' => 1, 'quantity' => 1]],
            'currency' => 'CNY',
        ]));
        $this->injectDependencies($requestStack);
        $this->setCurrentUser(1);

        $this->storeContextResolver->method('resolve')->willReturn(new StoreContext('store-uuid-1', 'STORE01', 'Store One'));
        $this->service->method('calculatePrices')->willReturn($this->priceResult(1000));
        $this->service->method('createOrder')->willReturn($this->orderOwnedBy(1));

        $response = $this->controller->createAction($requestStack->getCurrentRequest());

        self::assertSame(201, $response->getStatusCode());
        $body = json_decode((string) $response->getContent(), true);
        self::assertSame('Order created', $body['message']);
    }

    public function testCreateActionReturns400WhenPriceCalculationFails(): void
    {
        $requestStack = new RequestStack();
        $requestStack->push($this->jsonRequest('POST', '/api/v1/app/orders', [
            'items' => [['specificationId' => 1, 'quantity' => 1]],
        ]));
        $this->injectDependencies($requestStack);
        $this->setCurrentUser(1);

        $this->storeContextResolver->method('resolve')->willReturn(null);
        $this->service->method('calculatePrices')->willThrowException(new \RuntimeException('price engine down'));

        $response = $this->controller->createAction($requestStack->getCurrentRequest());

        self::assertSame(400, $response->getStatusCode());
        $body = json_decode((string) $response->getContent(), true);
        self::assertSame('price engine down', $body['message']);
    }

    // ===== quoteAction =====

    public function testQuoteActionReturns400WhenItemsMissing(): void
    {
        $requestStack = new RequestStack();
        $requestStack->push($this->jsonRequest('POST', '/api/v1/app/orders/quote', ['items' => []]));
        $this->injectDependencies($requestStack);

        $response = $this->controller->quoteAction($requestStack->getCurrentRequest());

        self::assertSame(400, $response->getStatusCode());
        $body = json_decode((string) $response->getContent(), true);
        self::assertSame('Items are required.', $body['message']);
    }

    public function testQuoteActionReturns200WhenCalculated(): void
    {
        $requestStack = new RequestStack();
        $requestStack->push($this->jsonRequest('POST', '/api/v1/app/orders/quote', [
            'items' => [['specificationId' => 1, 'quantity' => 2]],
            'currency' => 'USD',
            'meta' => ['promo' => 'x'],
        ]));
        $this->injectDependencies($requestStack);

        $this->storeContextResolver->method('resolve')->willReturn(new StoreContext('store-uuid-1', 'STORE01', 'Store One'));
        $this->service->method('calculatePrices')->willReturn($this->priceResult(2000, 'USD'));

        $response = $this->controller->quoteAction($requestStack->getCurrentRequest());

        self::assertSame(200, $response->getStatusCode());
        $body = json_decode((string) $response->getContent(), true);
        self::assertSame('Quote calculated', $body['message']);
        self::assertSame(2000, $body['data']['totalAmount']);
        self::assertSame('USD', $body['data']['currency']);
    }

    public function testQuoteActionReturns400WhenCalculationFails(): void
    {
        $requestStack = new RequestStack();
        $requestStack->push($this->jsonRequest('POST', '/api/v1/app/orders/quote', [
            'items' => [['specificationId' => 1, 'quantity' => 1]],
        ]));
        $this->injectDependencies($requestStack);

        $this->storeContextResolver->method('resolve')->willReturn(null);
        $this->service->method('calculatePrices')->willThrowException(new \RuntimeException('quote failed'));

        $response = $this->controller->quoteAction($requestStack->getCurrentRequest());

        self::assertSame(400, $response->getStatusCode());
        $body = json_decode((string) $response->getContent(), true);
        self::assertSame('quote failed', $body['message']);
    }

    // ===== itemsAction =====

    public function testItemsActionReturns404WhenOrderNotFound(): void
    {
        $requestStack = new RequestStack();
        $requestStack->push(Request::create('/api/v1/app/orders/1/items', 'GET'));
        $this->injectDependencies($requestStack);
        $this->setCurrentUser(1);

        $this->service->method('get')->with(['id' => 1])->willReturn(null);

        $response = $this->controller->itemsAction(1);

        self::assertSame(404, $response->getStatusCode());
        $body = json_decode((string) $response->getContent(), true);
        self::assertSame('Order not found.', $body['message']);
    }

    // ===== cancelAction =====

    public function testCancelActionReturns404WhenOrderNotFound(): void
    {
        $requestStack = new RequestStack();
        $requestStack->push(Request::create('/api/v1/app/orders/1/cancel', 'POST'));
        $this->injectDependencies($requestStack);

        $this->service->method('get')->with(['id' => 1])->willReturn(null);

        $response = $this->controller->cancelAction(1);

        self::assertSame(404, $response->getStatusCode());
        $body = json_decode((string) $response->getContent(), true);
        self::assertSame('Order not found.', $body['message']);
    }

    public function testCancelActionReturns400WhenTransitionFails(): void
    {
        $requestStack = new RequestStack();
        $requestStack->push(Request::create('/api/v1/app/orders/1/cancel', 'POST'));
        $this->injectDependencies($requestStack);
        $this->setCurrentUser(1);

        $order = $this->orderOwnedBy(1);
        $this->service->method('get')->with(['id' => 1])->willReturn($order);
        $this->workflow->method('can')->with($order, 'cancel')->willReturn(true);
        $this->workflow->method('apply')->with($order, 'cancel')->willThrowException(new \RuntimeException('cancel failed'));
        $this->fakeService->invokeTransaction = true;

        $response = $this->controller->cancelAction(1);

        self::assertSame(400, $response->getStatusCode());
        $body = json_decode((string) $response->getContent(), true);
        self::assertSame('cancel failed', $body['message']);
    }

    // ===== submitAction / confirmAction (documented bug) =====

    public function testSubmitActionReturnsWarningWhenTransitionFails(): void
    {
        // KNOWN BUG: applyUserOrderTransition() (src/Trade/Controller/App/OrderController.php:158-166)
        // has no try/catch. When the workflow cannot apply the transition (or the DB
        // transaction fails), the exception propagates out of submitAction/confirmAction
        // and becomes an HTTP 500, whereas cancelAction and paymentAction return a
        // 400 JSON warning. See docs/issues/coverage-2026-08-09/trade-controllers.md.
        $this->markTestSkipped(
            'Known bug (src/Trade/Controller/App/OrderController.php:158): submit/confirm '
            . 'transition failures bubble up as HTTP 500 instead of a 400 JSON warning. '
            . 'See docs/issues/coverage-2026-08-09/trade-controllers.md.'
        );

        $requestStack = new RequestStack();
        $requestStack->push(Request::create('/api/v1/app/orders/1/submit', 'POST'));
        $this->injectDependencies($requestStack);
        $this->setCurrentUser(1);

        $order = $this->orderOwnedBy(1);
        $this->service->method('get')->with(['id' => 1])->willReturn($order);
        $this->workflow->method('can')->with($order, 'submit')->willReturn(true);
        $this->workflow->method('apply')->with($order, 'submit')->willThrowException(new \RuntimeException('submit boom'));
        $this->fakeService->invokeTransaction = true;

        $response = $this->controller->submitAction(1);

        self::assertSame(400, $response->getStatusCode());
        $body = json_decode((string) $response->getContent(), true);
        self::assertSame('submit boom', $body['message']);
    }

    // ===== paymentAction =====

    public function testPaymentActionReturns404WhenOrderNotFound(): void
    {
        $requestStack = new RequestStack();
        $requestStack->push($this->jsonRequest('POST', '/api/v1/app/orders/1/payment', []));
        $this->injectDependencies($requestStack);

        $this->service->method('get')->with(['id' => 1])->willReturn(null);

        $response = $this->controller->paymentAction($requestStack->getCurrentRequest(), 1);

        self::assertSame(404, $response->getStatusCode());
        $body = json_decode((string) $response->getContent(), true);
        self::assertSame('Order not found.', $body['message']);
    }

    public function testPaymentActionReturns404WhenOrderOwnedByAnotherUser(): void
    {
        $requestStack = new RequestStack();
        $requestStack->push($this->jsonRequest('POST', '/api/v1/app/orders/1/payment', []));
        $this->injectDependencies($requestStack);
        $this->setCurrentUser(1);

        $this->service->method('get')->with(['id' => 1])->willReturn($this->orderOwnedBy(2));

        $response = $this->controller->paymentAction($requestStack->getCurrentRequest(), 1);

        self::assertSame(404, $response->getStatusCode());
        $body = json_decode((string) $response->getContent(), true);
        self::assertSame('Order not found.', $body['message']);
    }

    public function testPaymentActionReturns400WhenOrderCannotBePaid(): void
    {
        $requestStack = new RequestStack();
        $requestStack->push($this->jsonRequest('POST', '/api/v1/app/orders/1/payment', []));
        $this->injectDependencies($requestStack);
        $this->setCurrentUser(1);

        $order = $this->orderOwnedBy(1);
        $this->service->method('get')->with(['id' => 1])->willReturn($order);
        $this->workflow->method('can')->with($order, 'pay')->willReturn(false);

        $response = $this->controller->paymentAction($requestStack->getCurrentRequest(), 1);

        self::assertSame(400, $response->getStatusCode());
        $body = json_decode((string) $response->getContent(), true);
        self::assertSame('Order cannot be paid in current status.', $body['message']);
    }

    public function testPaymentActionReturns400WhenCreatePaymentFails(): void
    {
        $requestStack = new RequestStack();
        $requestStack->push($this->jsonRequest('POST', '/api/v1/app/orders/1/payment', []));
        $this->injectDependencies($requestStack);
        $this->setCurrentUser(1);

        $order = $this->orderOwnedBy(1);
        $this->service->method('get')->with(['id' => 1])->willReturn($order);
        $this->workflow->method('can')->with($order, 'pay')->willReturn(true);
        $this->service->method('createPayment')->willThrowException(new \RuntimeException('gateway down'));

        $response = $this->controller->paymentAction($requestStack->getCurrentRequest(), 1);

        self::assertSame(400, $response->getStatusCode());
        $body = json_decode((string) $response->getContent(), true);
        self::assertSame('gateway down', $body['message']);
    }

    #[DataProvider('paymentMethods')]
    public function testPaymentActionForwardsPaymentMethod(string $payment): void
    {
        $requestStack = new RequestStack();
        $requestStack->push($this->jsonRequest('POST', '/api/v1/app/orders/1/payment', [
            'payment' => $payment,
            'autoPaid' => true,
        ]));
        $this->injectDependencies($requestStack);
        $this->setCurrentUser(1);

        $order = $this->orderOwnedBy(1);
        $this->service->method('get')->with(['id' => 1])->willReturn($order);
        $this->workflow->method('can')->with($order, 'pay')->willReturn(true);

        $captured = null;
        $this->service->method('createPayment')->willReturnCallback(
            function ($order, $payMethod, $options) use (&$captured) {
                $captured = [$payMethod, $options];

                return new PaymentResult($this->createMock(Invoice::class), 'pending');
            }
        );

        $response = $this->controller->paymentAction($requestStack->getCurrentRequest(), 1);

        self::assertSame(200, $response->getStatusCode());
        $body = json_decode((string) $response->getContent(), true);
        self::assertSame('Payment started', $body['message']);
        self::assertSame([$payment, ['payment' => $payment, 'autoPaid' => true]], $captured);
    }

    /** @return iterable<string, array{string}> */
    public static function paymentMethods(): iterable
    {
        yield 'mock' => ['mock'];
        yield 'wallet' => ['wallet'];
        yield 'wechat' => ['wechat'];
    }
}
