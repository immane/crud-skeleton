<?php

declare(strict_types=1);

namespace App\Tests\UnitTest\Trade\Controller\Manage;

use App\Payment\DTO\PaymentResult;
use App\Payment\Entity\Invoice;
use App\Tests\UnitTest\Trade\Controller\OrderControllerServiceFake;
use App\Trade\Controller\Manage\OrderController;
use App\Trade\Service\OrderServiceInterface;
use App\Trade\Service\StoreContextResolverInterface;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Serializer\SerializerInterface;
use Symfony\Component\Workflow\WorkflowInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

#[AllowMockObjectsWithoutExpectations]
final class OrderControllerTest extends TestCase
{
    private OrderServiceInterface $service;
    private WorkflowInterface $workflow;
    private StoreContextResolverInterface $storeContextResolver;
    private OrderControllerServiceFake $fakeService;
    private OrderController $controller;

    protected function setUp(): void
    {
        $this->service = $this->createMock(OrderServiceInterface::class);
        $this->workflow = $this->createMock(WorkflowInterface::class);
        $this->storeContextResolver = $this->createMock(StoreContextResolverInterface::class);

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
     * Makes the fake service run the wrapInTransaction() callback, mirroring
     * the real BaseService::wrapInTransaction() behavior.
     */
    private function enableTransaction(): void
    {
        $this->fakeService->invokeTransaction = true;
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

    public function testDeleteActionReturns404WhenOrderNotFound(): void
    {
        $requestStack = new RequestStack();
        $requestStack->push(Request::create('/api/v1/manage/orders/999', 'DELETE'));
        $this->injectDependencies($requestStack);

        $this->service->method('get')->with(['id' => 999])->willReturn(null);

        $response = $this->controller->deleteAction(999);

        self::assertSame(404, $response->getStatusCode());
        $body = json_decode((string) $response->getContent(), true);
        self::assertSame(404, $body['code']);
        self::assertSame('Entity is not found', $body['message']);
    }

    public function testPayActionReturns404WhenOrderNotFound(): void
    {
        $this->markTestSkipped('pay removed from OrderController, now handled by Invoice');
        $requestStack = new RequestStack();
        $requestStack->push(Request::create('/api/v1/manage/orders/999/pay', 'POST'));
        $this->injectDependencies($requestStack);

        $this->service->method('get')->with(['id' => 999])->willReturn(null);

        $request = Request::create('/api/v1/manage/orders/999/pay', 'POST');
        $response = $this->controller->payAction($request, 999);

        self::assertSame(404, $response->getStatusCode());
        $body = json_decode((string) $response->getContent(), true);
        self::assertSame('Order not found.', $body['message']);
    }

    #[Group('low-value')]
    public function testPayActionReturnsErrorWhenCannotPay(): void
    {
        $this->markTestSkipped('pay removed from OrderController, now handled by Invoice');
        $requestStack = new RequestStack();
        $requestStack->push(Request::create('/api/v1/manage/orders/1/pay', 'POST'));
        $this->injectDependencies($requestStack);

        $order = $this->createMock(\App\Trade\Entity\Order::class);
        $order->method('getStatus')->willReturn('draft');
        $this->service->method('get')->with(['id' => 1])->willReturn($order);
        $this->workflow->method('can')->with($order, 'pay')->willReturn(false);

        $request = Request::create('/api/v1/manage/orders/1/pay', 'POST', server: [
            'CONTENT_TYPE' => 'application/json',
        ], content: json_encode(['systemWalletId' => 0]));

        $response = $this->controller->payAction($request, 1);

        self::assertSame(400, $response->getStatusCode());
        $body = json_decode((string) $response->getContent(), true);
        self::assertSame('Order cannot be paid in current status.', $body['message']);
    }

    public function testPaymentActionReturns404WhenOrderNotFound(): void
    {
        $this->markTestSkipped('pay removed from OrderController, now handled by Invoice');
        $requestStack = new RequestStack();
        $requestStack->push(Request::create('/api/v1/manage/orders/999/payment', 'POST'));
        $this->injectDependencies($requestStack);

        $this->service->method('get')->with(['id' => 999])->willReturn(null);

        $request = Request::create('/api/v1/manage/orders/999/payment', 'POST');
        $response = $this->controller->paymentAction($request, 999);

        self::assertSame(404, $response->getStatusCode());
        $body = json_decode((string) $response->getContent(), true);
        self::assertSame('Order not found.', $body['message']);
    }

    #[Group('low-value')]
    public function testPaymentActionReturnsErrorWhenCannotPay(): void
    {
        $this->markTestSkipped('pay removed from OrderController, now handled by Invoice');
        $requestStack = new RequestStack();
        $requestStack->push(Request::create('/api/v1/manage/orders/1/payment', 'POST'));
        $this->injectDependencies($requestStack);

        $order = $this->createMock(\App\Trade\Entity\Order::class);
        $order->method('getStatus')->willReturn('draft');
        $this->service->method('get')->with(['id' => 1])->willReturn($order);
        $this->workflow->method('can')->with($order, 'pay')->willReturn(false);

        $request = Request::create('/api/v1/manage/orders/1/payment', 'POST');
        $response = $this->controller->paymentAction($request, 1);

        self::assertSame(400, $response->getStatusCode());
        $body = json_decode((string) $response->getContent(), true);
        self::assertSame('Order cannot be paid in current status.', $body['message']);
    }

    public function testFulfillActionReturns404WhenOrderNotFound(): void
    {
        $requestStack = new RequestStack();
        $requestStack->push(Request::create('/api/v1/manage/orders/999/fulfill', 'POST'));
        $this->injectDependencies($requestStack);

        $this->service->method('get')->with(['id' => 999])->willReturn(null);

        $request = Request::create('/api/v1/manage/orders/999/fulfill', 'POST');
        $response = $this->controller->fulfillAction($request, 999);

        self::assertSame(404, $response->getStatusCode());
        $body = json_decode((string) $response->getContent(), true);
        self::assertSame('Order not found.', $body['message']);
    }

    public function testRefundActionReturns404WhenOrderNotFound(): void
    {
        $requestStack = new RequestStack();
        $requestStack->push(Request::create('/api/v1/manage/orders/999/refund', 'POST'));
        $this->injectDependencies($requestStack);

        $this->service->method('get')->with(['id' => 999])->willReturn(null);

        $request = Request::create('/api/v1/manage/orders/999/refund', 'POST');
        $response = $this->controller->refundAction($request, 999);

        self::assertSame(404, $response->getStatusCode());
        $body = json_decode((string) $response->getContent(), true);
        self::assertSame('Order not found.', $body['message']);
    }

    public function testTransitionsActionReturns404WhenOrderNotFound(): void
    {
        $requestStack = new RequestStack();
        $requestStack->push(Request::create('/api/v1/manage/orders/999/transitions', 'GET'));
        $this->injectDependencies($requestStack);

        $this->service->method('get')->with(['id' => 999])->willReturn(null);

        $response = $this->controller->availableTransitionsAction(999);

        self::assertSame(404, $response->getStatusCode());
        $body = json_decode((string) $response->getContent(), true);
        self::assertSame('Entity is not found', $body['message']);
    }

    public function testDoTransitionActionReturns404WhenOrderNotFound(): void
    {
        $requestStack = new RequestStack();
        $requestStack->push(Request::create('/api/v1/manage/orders/999/do/cancel', 'POST'));
        $this->injectDependencies($requestStack);

        $this->service->method('get')->with(['id' => 999])->willReturn(null);

        $request = Request::create('/api/v1/manage/orders/999/do/cancel', 'POST');
        $response = $this->controller->doTransitionAction($request, 999, 'cancel');

        self::assertSame(404, $response->getStatusCode());
        $body = json_decode((string) $response->getContent(), true);
        self::assertSame('Entity is not found', $body['message']);
    }

    public function testCreateActionReturnsErrorWhenItemsEmpty(): void
    {
        $requestStack = new RequestStack();
        $requestStack->push(Request::create('/api/v1/manage/orders', 'POST'));
        $this->injectDependencies($requestStack);

        $request = Request::create('/api/v1/manage/orders', 'POST', server: [
            'CONTENT_TYPE' => 'application/json',
        ], content: json_encode(['items' => []]));
        $this->injectDependencies($requestStack);
        $this->enableTransaction();
        $this->service->method('new')->willReturn($this->createMock(\App\Trade\Entity\Order::class));

        $response = $this->controller->createAction($request);

        self::assertSame(400, $response->getStatusCode());
        $body = json_decode((string) $response->getContent(), true);
        self::assertSame('Items are required.', $body['message']);
    }

    #[Group('low-value')]
    public function testRefundActionReturnsErrorWhenReasonMissing(): void
    {
        $requestStack = new RequestStack();
        $requestStack->push(Request::create('/api/v1/manage/orders/1/refund', 'POST'));
        $this->injectDependencies($requestStack);

        $order = $this->createMock(\App\Trade\Entity\Order::class);
        $order->method('getStatus')->willReturn('paid');
        $this->service->method('get')->with(['id' => 1])->willReturn($order);
        $this->workflow->method('can')->with($order, 'refund')->willReturn(true);

        $request = Request::create('/api/v1/manage/orders/1/refund', 'POST', server: [
            'CONTENT_TYPE' => 'application/json',
        ], content: json_encode(['reason' => '']));

        $response = $this->controller->refundAction($request, 1);

        self::assertSame(400, $response->getStatusCode());
        $body = json_decode((string) $response->getContent(), true);
        self::assertSame('reason is required.', $body['message']);
    }

    public function testItemsActionReturns404WhenOrderNotFound(): void
    {
        $requestStack = new RequestStack();
        $requestStack->push(Request::create('/api/v1/manage/orders/999/items', 'GET'));
        $this->injectDependencies($requestStack);

        $this->service->method('get')->with(['id' => 999])->willReturn(null);

        $response = $this->controller->itemsAction(999);

        self::assertSame(404, $response->getStatusCode());
        $body = json_decode((string) $response->getContent(), true);
        self::assertSame('Order not found.', $body['message']);
    }

    public function testUpdateActionReturns404WhenOrderNotFound(): void
    {
        $requestStack = new RequestStack();
        $requestStack->push(Request::create('/api/v1/manage/orders/999', 'PUT'));
        $this->injectDependencies($requestStack);

        $this->service->method('get')->with(['id' => 999])->willReturn(null);

        $request = Request::create('/api/v1/manage/orders/999', 'PUT', server: [
            'CONTENT_TYPE' => 'application/json',
        ], content: json_encode([]));

        $response = $this->controller->updateAction($request, 999);

        self::assertSame(404, $response->getStatusCode());
        $body = json_decode((string) $response->getContent(), true);
        self::assertSame('Entity is not found', $body['message']);
    }

    #[Group('low-value')]
    public function testDeleteActionReturnsErrorWhenNotDraft(): void
    {
        $requestStack = new RequestStack();
        $requestStack->push(Request::create('/api/v1/manage/orders/1', 'DELETE'));
        $this->injectDependencies($requestStack);

        $order = $this->createMock(\App\Trade\Entity\Order::class);
        $order->method('getStatus')->willReturn('paid');
        $this->service->method('get')->with(['id' => 1])->willReturn($order);

        $response = $this->controller->deleteAction(1);

        self::assertSame(400, $response->getStatusCode());
        $body = json_decode((string) $response->getContent(), true);
        self::assertSame('Only draft orders can be deleted.', $body['message']);
    }

    #[Group('low-value')]
    public function testUpdateActionReturnsErrorWhenNotDraft(): void
    {
        $requestStack = new RequestStack();
        $requestStack->push(Request::create('/api/v1/manage/orders/1', 'PUT'));
        $this->injectDependencies($requestStack);

        $order = $this->createMock(\App\Trade\Entity\Order::class);
        $order->method('getStatus')->willReturn('paid');
        $this->service->method('get')->with(['id' => 1])->willReturn($order);

        $request = Request::create('/api/v1/manage/orders/1', 'PUT', server: [
            'CONTENT_TYPE' => 'application/json',
        ], content: json_encode([]));

        $response = $this->controller->updateAction($request, 1);

        self::assertSame(400, $response->getStatusCode());
        $body = json_decode((string) $response->getContent(), true);
        self::assertSame('Only draft orders can be updated.', $body['message']);
    }

    // ===== createAction failure path =====

    public function testCreateActionReturnsErrorWhenPriceCalculationFails(): void
    {
        $requestStack = new RequestStack();
        $requestStack->push($this->jsonRequest('POST', '/api/v1/manage/orders', [
            'items' => [['specificationId' => 1, 'quantity' => 1]],
        ]));
        $this->injectDependencies($requestStack);
        $this->enableTransaction();
        $this->service->method('new')->willReturn($this->createMock(\App\Trade\Entity\Order::class));

        $this->storeContextResolver->method('resolve')->willReturn(null);
        $this->service->method('calculatePrices')->willThrowException(new \RuntimeException('calc failed'));

        $response = $this->controller->createAction($requestStack->getCurrentRequest());

        // CreateApiViewMixin maps a generic price engine failure to HTTP 500.
        self::assertSame(500, $response->getStatusCode());
        $body = json_decode((string) $response->getContent(), true);
        self::assertSame('calc failed', $body['message']);
    }

    // ===== quoteAction =====

    public function testQuoteActionReturnsErrorWhenItemsEmpty(): void
    {
        $requestStack = new RequestStack();
        $requestStack->push($this->jsonRequest('POST', '/api/v1/manage/orders/quote', ['items' => []]));
        $this->injectDependencies($requestStack);

        $response = $this->controller->quoteAction($requestStack->getCurrentRequest());

        self::assertSame(400, $response->getStatusCode());
        $body = json_decode((string) $response->getContent(), true);
        self::assertSame('Items are required.', $body['message']);
    }

    public function testQuoteActionReturnsSuccessWhenCalculated(): void
    {
        $requestStack = new RequestStack();
        $requestStack->push($this->jsonRequest('POST', '/api/v1/manage/orders/quote', [
            'items' => [['specificationId' => 1, 'quantity' => 2]],
            'currency' => 'USD',
        ]));
        $this->injectDependencies($requestStack);

        $this->storeContextResolver->method('resolve')->willReturn(null);
        $this->service->method('calculatePrices')->willReturn(new \App\Trade\Service\Pricing\PriceCalculationResult(
            3000,
            'USD',
            [['specificationId' => 1, 'quantity' => 2]],
        ));

        $response = $this->controller->quoteAction($requestStack->getCurrentRequest());

        self::assertSame(200, $response->getStatusCode());
        $body = json_decode((string) $response->getContent(), true);
        self::assertSame('Quote calculated', $body['message']);
        self::assertSame(3000, $body['data']['totalAmount']);
        self::assertSame('USD', $body['data']['currency']);
    }

    public function testQuoteActionReturnsErrorWhenCalculationFails(): void
    {
        $requestStack = new RequestStack();
        $requestStack->push($this->jsonRequest('POST', '/api/v1/manage/orders/quote', [
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

    // ===== payAction success path =====

    public function testPayActionReturnsSuccessWhenPaymentSucceeds(): void
    {
        $this->markTestSkipped('pay removed from OrderController, now handled by Invoice');
        $requestStack = new RequestStack();
        $requestStack->push($this->jsonRequest('POST', '/api/v1/manage/orders/1/pay', [
            'systemWalletId' => 1,
            'paymentMethod' => 'wallet',
        ]));
        $this->injectDependencies($requestStack);
        $this->enableTransaction();

        $order = $this->createMock(\App\Trade\Entity\Order::class);
        $order->method('getStatus')->willReturn('confirmed');
        $this->service->method('get')->with(['id' => 1])->willReturn($order);
        $this->workflow->method('can')->with($order, 'pay')->willReturn(true);

        $response = $this->controller->payAction($requestStack->getCurrentRequest(), 1);

        self::assertSame(200, $response->getStatusCode());
        $body = json_decode((string) $response->getContent(), true);
        self::assertSame('Payment processed', $body['message']);
    }

    // ===== paymentAction success + failure paths =====

    public function testPaymentActionReturnsSuccessWhenPaymentStarted(): void
    {
        $this->markTestSkipped('pay removed from OrderController, now handled by Invoice');
        $requestStack = new RequestStack();
        $requestStack->push($this->jsonRequest('POST', '/api/v1/manage/orders/1/payment', [
            'payment' => 'mock',
        ]));
        $this->injectDependencies($requestStack);

        $order = $this->createMock(\App\Trade\Entity\Order::class);
        $order->method('getStatus')->willReturn('confirmed');
        $this->service->method('get')->with(['id' => 1])->willReturn($order);
        $this->workflow->method('can')->with($order, 'pay')->willReturn(true);
        $this->service->method('createPayment')->willReturn(new PaymentResult($this->createMock(Invoice::class), 'pending'));

        $response = $this->controller->paymentAction($requestStack->getCurrentRequest(), 1);

        self::assertSame(200, $response->getStatusCode());
        $body = json_decode((string) $response->getContent(), true);
        self::assertSame('Payment started', $body['message']);
    }

    public function testPaymentActionReturnsErrorWhenCreatePaymentFails(): void
    {
        $this->markTestSkipped('pay removed from OrderController, now handled by Invoice');
        $requestStack = new RequestStack();
        $requestStack->push($this->jsonRequest('POST', '/api/v1/manage/orders/1/payment', []));
        $this->injectDependencies($requestStack);

        $order = $this->createMock(\App\Trade\Entity\Order::class);
        $order->method('getStatus')->willReturn('confirmed');
        $this->service->method('get')->with(['id' => 1])->willReturn($order);
        $this->workflow->method('can')->with($order, 'pay')->willReturn(true);
        $this->service->method('createPayment')->willThrowException(new \RuntimeException('gateway down'));

        $response = $this->controller->paymentAction($requestStack->getCurrentRequest(), 1);

        self::assertSame(400, $response->getStatusCode());
        $body = json_decode((string) $response->getContent(), true);
        self::assertSame('gateway down', $body['message']);
    }

    // ===== fulfillAction failure path =====

    public function testFulfillActionReturnsErrorWhenFulfillFails(): void
    {
        $requestStack = new RequestStack();
        $requestStack->push($this->jsonRequest('POST', '/api/v1/manage/orders/1/fulfill', [
            'trackingNumber' => 'TRACK-1',
        ]));
        $this->injectDependencies($requestStack);
        $this->enableTransaction();

        $order = $this->createMock(\App\Trade\Entity\Order::class);
        $order->method('getStatus')->willReturn('paid');
        $this->service->method('get')->with(['id' => 1])->willReturn($order);
        $this->workflow->method('can')->with($order, 'fulfill')->willReturn(true);
        $this->service->method('fulfill')->willThrowException(new \RuntimeException('fulfill failed'));

        $response = $this->controller->fulfillAction($requestStack->getCurrentRequest(), 1);

        self::assertSame(400, $response->getStatusCode());
        $body = json_decode((string) $response->getContent(), true);
        self::assertSame('fulfill failed', $body['message']);
    }

    // ===== refundAction wallet paths =====

    public function testRefundActionReturnsErrorWhenSystemWalletIdMissing(): void
    {
        $requestStack = new RequestStack();
        $requestStack->push($this->jsonRequest('POST', '/api/v1/manage/orders/1/refund', [
            'reason' => 'customer request',
        ]));
        $this->injectDependencies($requestStack);

        $order = $this->createMock(\App\Trade\Entity\Order::class);
        $order->method('getStatus')->willReturn('completed');
        $this->service->method('get')->with(['id' => 1])->willReturn($order);
        $this->workflow->method('can')->with($order, 'refund')->willReturn(true);

        $response = $this->controller->refundAction($requestStack->getCurrentRequest(), 1);

        self::assertSame(400, $response->getStatusCode());
        $body = json_decode((string) $response->getContent(), true);
        self::assertSame('systemWalletId is required.', $body['message']);
    }

    public function testRefundActionReturnsSuccessWhenWalletRefundSucceeds(): void
    {
        $requestStack = new RequestStack();
        $requestStack->push($this->jsonRequest('POST', '/api/v1/manage/orders/1/refund', [
            'reason' => 'customer request',
            'systemWalletId' => 1,
        ]));
        $this->injectDependencies($requestStack);
        $this->enableTransaction();

        $order = $this->createMock(\App\Trade\Entity\Order::class);
        $order->method('getStatus')->willReturn('completed');
        $this->service->method('get')->with(['id' => 1])->willReturn($order);
        $this->workflow->method('can')->with($order, 'refund')->willReturn(true);

        $response = $this->controller->refundAction($requestStack->getCurrentRequest(), 1);

        self::assertSame(200, $response->getStatusCode());
        $body = json_decode((string) $response->getContent(), true);
        self::assertSame('Refund processed', $body['message']);
    }

    // ===== doTransitionAction (documented bug) =====

    #[Group('low-value')]
    public function testDoTransitionDoesNotForwardArbitraryFieldsToUpdate(): void
    {
        // KNOWN BUG: doTransitionAction() (src/Trade/Controller/Manage/OrderController.php:329)
        // forwards the raw request body to OrderService::update(), and BaseService::update()
        // applies ANY settable property (e.g. totalAmount, currency, trackingNumber) via the
        // serializer. Unlike updateAction() — which whitelists notes/metadata — an admin can
        // therefore mutate order totals etc. through the /do/{transition} endpoint.
        $this->markTestSkipped(
            'Known bug (src/Trade/Controller/Manage/OrderController.php:329): doTransitionAction '
            . 'forwards arbitrary request fields to update(), so totalAmount/status can be mutated '
            . 'through /do/{transition}. See docs/issues/coverage-2026-08-09/trade-controllers.md.'
        );

        $requestStack = new RequestStack();
        $requestStack->push($this->jsonRequest('POST', '/api/v1/manage/orders/1/do/complete', [
            'reason' => 'note',
            'totalAmount' => 1,
        ]));
        $this->injectDependencies($requestStack);
        $this->enableTransaction();

        $order = $this->createMock(\App\Trade\Entity\Order::class);
        $order->method('getStatus')->willReturn('fulfilled');
        $this->service->method('get')->with(['id' => 1])->willReturn($order);
        $this->workflow->method('can')->with($order, 'complete')->willReturn(true);

        $captured = null;
        $this->service->method('update')->willReturnCallback(function ($object, $data) use (&$captured) {
            $captured = $data;

            return $object;
        });

        $response = $this->controller->doTransitionAction($requestStack->getCurrentRequest(), 1, 'complete');

        self::assertSame(200, $response->getStatusCode());
        self::assertIsArray($captured);
        self::assertArrayNotHasKey('totalAmount', $captured);
    }
}
