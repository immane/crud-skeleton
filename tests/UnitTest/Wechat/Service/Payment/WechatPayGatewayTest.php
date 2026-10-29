<?php

declare(strict_types=1);

namespace App\Tests\UnitTest\Wechat\Service\Payment;

use App\Payment\DTO\PaymentNotifyResult;
use App\Payment\DTO\PaymentRefundResult;
use App\Payment\DTO\PaymentResult;
use App\Payment\Entity\Invoice;
use App\Payment\Exception\PaymentVerificationException;
use App\Wechat\Entity\WechatUser;
use App\Wechat\Repository\WechatUserRepository;
use App\Wechat\Service\Payment\WechatPayGateway;
use App\Wechat\Service\WechatServiceInterface;
use EasyWeChat\Kernel\HttpClient\Response as WechatResponse;
use EasyWeChat\MiniApp\Application as MiniApp;
use EasyWeChat\MiniApp\Account as MiniAccount;
use EasyWeChat\Pay\Application as PayApp;
use EasyWeChat\Pay\Merchant;
use EasyWeChat\Pay\Utils;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Symfony\Bridge\PsrHttpMessage\HttpMessageFactoryInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

final class WechatPayGatewayTest extends TestCase
{
    private WechatServiceInterface $wechatService;
    private WechatUserRepository $wechatUserRepo;
    private HttpMessageFactoryInterface $psrHttpFactory;
    private WechatPayGateway $gateway;

    protected function setUp(): void
    {
        $this->wechatService = $this->createMock(WechatServiceInterface::class);
        $this->wechatUserRepo = $this->createMock(WechatUserRepository::class);
        $this->psrHttpFactory = $this->createMock(HttpMessageFactoryInterface::class);

        $this->gateway = new WechatPayGateway(
            $this->wechatService,
            $this->wechatUserRepo,
            $this->psrHttpFactory,
            'https://example.com/notify/wechat',
        );
    }

    #[Group('low-value')]
    public function testGetName(): void
    {
        self::assertSame('wechat', $this->gateway::getName());
        self::assertSame(Invoice::PAYMENT_WECHAT, $this->gateway::getName());
    }

    #[Group('low-value')]
    public function testPayUnsupportedTradeTypeThrows(): void
    {
        $invoice = $this->createMock(Invoice::class);
        $invoice->method('getTradeType')->willReturn('unsupported');
        $invoice->method('getAmount')->willReturn(100);
        $invoice->method('getCurrency')->willReturn('CNY');
        $invoice->method('getSubject')->willReturn('Test');
        $invoice->method('getDescription')->willReturn(null);
        $invoice->method('getOutTradeNo')->willReturn('TXN001');

        self::expectException(\InvalidArgumentException::class);
        self::expectExceptionMessage('Unsupported WeChat trade type');

        $this->gateway->pay($invoice, 100);
    }

    public function testPayJsapiWithoutWechatUserThrows(): void
    {
        $invoice = $this->createMock(Invoice::class);
        $invoice->method('getTradeType')->willReturn('jsapi');
        $invoice->method('getAmount')->willReturn(100);
        $invoice->method('getCurrency')->willReturn('CNY');
        $invoice->method('getSubject')->willReturn('Test');
        $invoice->method('getDescription')->willReturn(null);
        $invoice->method('getOutTradeNo')->willReturn('TXN001');
        $invoice->method('getPayer')->willReturn(null);

        self::expectException(\RuntimeException::class);
        self::expectExceptionMessage('WeChat user not found');

        $this->gateway->pay($invoice, 100);
    }

    public function testNotifyThrowsOnSignatureFailure(): void
    {
        self::expectException(PaymentVerificationException::class);

        $this->gateway->notify(Request::create('/notify', 'POST', content: 'invalid'));
    }

    public function testNotifyThrowsOnUnsupportedEvent(): void
    {
        $payApp = $this->createMock(PayApp::class);
        $server = $this->createMock(\EasyWeChat\Pay\Server::class);

        $this->wechatService->method('getPayApp')->willReturn($payApp);
        $payApp->method('getServer')->willReturn($server);

        $server->method('handlePaid')->willReturnSelf();
        $server->method('serve')->willReturn($this->createMock(\Psr\Http\Message\ResponseInterface::class));

        self::expectException(PaymentVerificationException::class);
        self::expectExceptionMessage('WeChat notify failed');

        $this->gateway->notify(Request::create('/notify', 'POST'));
    }

    public function testGetNotifySuccessResponse(): void
    {
        $result = new PaymentNotifyResult(
            payment: 'wechat',
            outTradeNo: 'TXN001',
            status: Invoice::STATUS_PAID,
            amount: 100,
            transactionId: 'WX_TXN_001',
            responseBody: json_encode(['code' => 'SUCCESS', 'message' => 'OK']),
        );

        $response = $this->gateway->getNotifySuccessResponse($result);
        self::assertSame(200, $response->getStatusCode());
        $body = json_decode((string) $response->getContent(), true);
        self::assertSame('SUCCESS', $body['code']);
    }

    public function testNotifySuccessResponseIsJson(): void
    {
        $result = new PaymentNotifyResult(
            payment: 'wechat',
            outTradeNo: 'TXN001',
            status: Invoice::STATUS_PAID,
            amount: 100,
            responseBody: json_encode(['code' => 'SUCCESS']),
        );

        $response = $this->gateway->getNotifySuccessResponse($result);
        self::assertStringContainsString('application/json', (string) $response->headers->get('Content-Type'));
    }

    public function testGetNotifySuccessResponseFallbackBody(): void
    {
        $result = new PaymentNotifyResult(
            payment: 'wechat',
            outTradeNo: 'TXN001',
            status: Invoice::STATUS_PAID,
            amount: 100,
            responseBody: 'invalid json',
        );

        $response = $this->gateway->getNotifySuccessResponse($result);
        $body = json_decode((string) $response->getContent(), true);
        self::assertSame('SUCCESS', $body['code']);
    }

    public function testPayNativeSuccess(): void
    {
        $payApp = $this->createMock(PayApp::class);
        $merchant = $this->createMock(Merchant::class);
        $merchant->method('getMerchantId')->willReturn(1234567890);
        $payApp->method('getMerchant')->willReturn($merchant);

        $clientResponse = $this->createMock(WechatResponse::class);
        $clientResponse->method('toArray')->willReturn(['code_url' => 'weixin://wxpay/bizpayurl?pr=abc123']);

        $payClient = $this->createMock(\EasyWeChat\Pay\Client::class);
        $payClient->method('postJson')->willReturn($clientResponse);
        $payApp->method('getClient')->willReturn($payClient);

        $this->wechatService->method('getPayApp')->willReturn($payApp);

        $invoice = $this->createMock(Invoice::class);
        $invoice->method('getTradeType')->willReturn('native');
        $invoice->method('getAmount')->willReturn(100);
        $invoice->method('getCurrency')->willReturn('CNY');
        $invoice->method('getSubject')->willReturn('Test Order');
        $invoice->method('getDescription')->willReturn(null);
        $invoice->method('getOutTradeNo')->willReturn('TXN_NATIVE');

        $result = $this->gateway->pay($invoice, 100);

        self::assertInstanceOf(PaymentResult::class, $result);
        self::assertSame(Invoice::STATUS_PAYING, $result->status);
        self::assertSame('weixin://wxpay/bizpayurl?pr=abc123', $result->payUrl);
        self::assertSame('WeChat Native order created', $result->message);
    }

    public function testPayJsapiSuccess(): void
    {
        $payer = new \App\Identity\Entity\User();

        $wechatUser = $this->createMock(WechatUser::class);
        $wechatUser->method('getOpenid')->willReturn('o_jsapi_user');

        $this->wechatUserRepo->method('findByUserAndAppId')
            ->with($payer, 'wx_mini_app')
            ->willReturn($wechatUser);

        $payApp = $this->createMock(PayApp::class);
        $merchant = $this->createMock(Merchant::class);
        $merchant->method('getMerchantId')->willReturn(1234567890);
        $payApp->method('getMerchant')->willReturn($merchant);

        $clientResponse = $this->createMock(WechatResponse::class);
        $clientResponse->method('toArray')->willReturn(['prepay_id' => 'wx_prepay_jsapi_001']);

        $payClient = $this->createMock(\EasyWeChat\Pay\Client::class);
        $payClient->method('postJson')->willReturn($clientResponse);
        $payApp->method('getClient')->willReturn($payClient);

        $payUtils = $this->createMock(Utils::class);
        $payUtils->method('buildMiniAppConfig')
            ->with('wx_prepay_jsapi_001', 'wx_mini_app', 'RSA')
            ->willReturn([
                'timeStamp' => '1234567890',
                'nonceStr' => 'abc123',
                'package' => 'prepay_id=wx_prepay_jsapi_001',
                'signType' => 'RSA',
                'paySign' => 'sign_abc',
            ]);
        $payApp->method('getUtils')->willReturn($payUtils);

        $this->wechatService->method('getPayApp')->willReturn($payApp);

        $miniApp = $this->createMock(MiniApp::class);
        $miniAccount = $this->createMock(MiniAccount::class);
        $miniAccount->method('getAppId')->willReturn('wx_mini_app');
        $miniApp->method('getAccount')->willReturn($miniAccount);
        $this->wechatService->method('getMiniApp')->willReturn($miniApp);

        $invoice = $this->createMock(Invoice::class);
        $invoice->method('getTradeType')->willReturn('jsapi');
        $invoice->method('getAmount')->willReturn(100);
        $invoice->method('getCurrency')->willReturn('CNY');
        $invoice->method('getSubject')->willReturn('Test JSAPI Order');
        $invoice->method('getDescription')->willReturn(null);
        $invoice->method('getOutTradeNo')->willReturn('TXN_JSAPI');
        $invoice->method('getPayer')->willReturn($payer);

        $result = $this->gateway->pay($invoice, 100);

        self::assertInstanceOf(PaymentResult::class, $result);
        self::assertSame(Invoice::STATUS_PAYING, $result->status);
        self::assertSame('WeChat JSAPI order created', $result->message);
        self::assertNotNull($result->payload);
        self::assertSame('1234567890', $result->payload['timeStamp']);
        self::assertSame('abc123', $result->payload['nonceStr']);
        self::assertSame('sign_abc', $result->payload['paySign']);
    }

    public function testPayNativeWithDescriptionFallback(): void
    {
        $payApp = $this->createMock(PayApp::class);
        $merchant = $this->createMock(Merchant::class);
        $merchant->method('getMerchantId')->willReturn(999);
        $payApp->method('getMerchant')->willReturn($merchant);

        $clientResponse = $this->createMock(WechatResponse::class);
        $clientResponse->method('toArray')->willReturn(['code_url' => 'weixin://pay/native']);

        $payClient = $this->createMock(\EasyWeChat\Pay\Client::class);
        $payClient->method('postJson')->willReturn($clientResponse);
        $payApp->method('getClient')->willReturn($payClient);

        $this->wechatService->method('getPayApp')->willReturn($payApp);

        $invoice = $this->createMock(Invoice::class);
        $invoice->method('getTradeType')->willReturn('native');
        $invoice->method('getAmount')->willReturn(200);
        $invoice->method('getCurrency')->willReturn('CNY');
        $invoice->method('getSubject')->willReturn(null);
        $invoice->method('getDescription')->willReturn('Fallback description');
        $invoice->method('getOutTradeNo')->willReturn('TXN_DESC');

        $result = $this->gateway->pay($invoice, 200);

        self::assertSame(Invoice::STATUS_PAYING, $result->status);
        self::assertSame('weixin://pay/native', $result->payUrl);
    }

    public function testPayNativeFallsBackToPaymentWhenSubjectAndDescriptionAreNull(): void
    {
        $payApp = $this->createMock(PayApp::class);
        $merchant = $this->createMock(Merchant::class);
        $merchant->method('getMerchantId')->willReturn(999);
        $payApp->method('getMerchant')->willReturn($merchant);

        $clientResponse = $this->createMock(WechatResponse::class);
        $clientResponse->method('toArray')->willReturn(['code_url' => 'weixin://pay/fallback']);

        $payClient = $this->createMock(\EasyWeChat\Pay\Client::class);
        $payClient->method('postJson')->willReturn($clientResponse);
        $payApp->method('getClient')->willReturn($payClient);

        $this->wechatService->method('getPayApp')->willReturn($payApp);

        $invoice = $this->createMock(Invoice::class);
        $invoice->method('getTradeType')->willReturn('native');
        $invoice->method('getAmount')->willReturn(300);
        $invoice->method('getCurrency')->willReturn('CNY');
        $invoice->method('getSubject')->willReturn(null);
        $invoice->method('getDescription')->willReturn(null);
        $invoice->method('getOutTradeNo')->willReturn('TXN_NULL_SUBJECT');

        $result = $this->gateway->pay($invoice, 300);

        self::assertSame(Invoice::STATUS_PAYING, $result->status);
        self::assertSame('weixin://pay/fallback', $result->payUrl);
    }

    public function testRefundSuccess(): void
    {
        $payApp = $this->createMock(PayApp::class);
        $clientResponse = $this->createMock(WechatResponse::class);
        $clientResponse->method('toArray')->willReturn([
            'refund_id' => 'REF_001',
            'status' => 'SUCCESS',
        ]);

        $payClient = $this->createMock(\EasyWeChat\Pay\Client::class);
        $payClient->method('postJson')->willReturn($clientResponse);
        $payApp->method('getClient')->willReturn($payClient);

        $this->wechatService->method('getPayApp')->willReturn($payApp);

        $invoice = $this->createMock(Invoice::class);
        $invoice->method('getOutTradeNo')->willReturn('TXN001');
        $invoice->method('getAmount')->willReturn(200);
        $invoice->method('getCurrency')->willReturn('CNY');
        $invoice->method('getRefundedAmount')->willReturn(0);
        $invoice->method('getStatus')->willReturn(Invoice::STATUS_PAID);

        $result = $this->gateway->refund($invoice, 100, 200, 'Customer request', ['refundIdempotencyKey' => 'attempt-1']);

        self::assertInstanceOf(PaymentRefundResult::class, $result);
        self::assertSame(100, $result->amount);
        self::assertSame('REF_001', $result->refundId);
        self::assertSame(Invoice::STATUS_PARTIAL_REFUNDED, $result->status);
    }

    public function testRefundFullAmount(): void
    {
        $payApp = $this->createMock(PayApp::class);
        $clientResponse = $this->createMock(WechatResponse::class);
        $clientResponse->method('toArray')->willReturn([
            'refund_id' => 'REF_FULL',
            'status' => 'SUCCESS',
        ]);

        $payClient = $this->createMock(\EasyWeChat\Pay\Client::class);
        $payClient->method('postJson')->willReturn($clientResponse);
        $payApp->method('getClient')->willReturn($payClient);

        $this->wechatService->method('getPayApp')->willReturn($payApp);

        $invoice = $this->createMock(Invoice::class);
        $invoice->method('getOutTradeNo')->willReturn('TXN002');
        $invoice->method('getAmount')->willReturn(500);
        $invoice->method('getCurrency')->willReturn('CNY');
        $invoice->method('getRefundedAmount')->willReturn(100);
        $invoice->method('getStatus')->willReturn(Invoice::STATUS_PAID);

        $result = $this->gateway->refund($invoice, 400, 500, 'Full refund', ['refundIdempotencyKey' => 'attempt-2']);

        self::assertSame(400, $result->amount);
        self::assertSame(Invoice::STATUS_REFUNDED, $result->status);
    }

    public function testRefundPendingStatus(): void
    {
        $payApp = $this->createMock(PayApp::class);
        $clientResponse = $this->createMock(WechatResponse::class);
        $clientResponse->method('toArray')->willReturn(['status' => 'PROCESSING']);

        $payClient = $this->createMock(\EasyWeChat\Pay\Client::class);
        $payClient->method('postJson')->willReturn($clientResponse);
        $payApp->method('getClient')->willReturn($payClient);

        $this->wechatService->method('getPayApp')->willReturn($payApp);

        $invoice = $this->createMock(Invoice::class);
        $invoice->method('getOutTradeNo')->willReturn('TXN003');
        $invoice->method('getAmount')->willReturn(300);
        $invoice->method('getCurrency')->willReturn('CNY');
        $invoice->method('getRefundedAmount')->willReturn(0);
        $invoice->method('getStatus')->willReturn(Invoice::STATUS_PAID);

        self::expectException(\RuntimeException::class);
        self::expectExceptionMessage('has not confirmed success');
        $this->gateway->refund($invoice, 100, 300, 'Processing', ['refundIdempotencyKey' => 'attempt-3']);
    }

    public function testRefundUsesStableDistinctProviderNumbers(): void
    {
        $payApp = $this->createMock(PayApp::class);
        $response = $this->createMock(WechatResponse::class);
        $response->method('toArray')->willReturn(['status' => 'SUCCESS', 'refund_id' => 'refund']);
        $numbers = [];
        $client = $this->createMock(\EasyWeChat\Pay\Client::class);
        $client->expects(self::exactly(3))->method('postJson')->willReturnCallback(
            static function (string $url, array $body) use (&$numbers, $response): WechatResponse {
                self::assertSame('v3/refund/domestic/refunds', $url);
                $numbers[] = $body['out_refund_no'];
                return $response;
            },
        );
        $payApp->method('getClient')->willReturn($client);
        $this->wechatService->method('getPayApp')->willReturn($payApp);
        $invoice = $this->createMock(Invoice::class);
        $invoice->method('getOutTradeNo')->willReturn('TXN001');
        $invoice->method('getCurrency')->willReturn('CNY');
        $invoice->method('getRefundedAmount')->willReturn(0);

        $this->gateway->refund($invoice, 100, 300, 'test', ['refundIdempotencyKey' => 'first']);
        $this->gateway->refund($invoice, 100, 300, 'test', ['refundIdempotencyKey' => 'first']);
        $this->gateway->refund($invoice, 100, 300, 'test', ['refundIdempotencyKey' => 'second']);
        self::assertSame($numbers[0], $numbers[1]);
        self::assertNotSame($numbers[0], $numbers[2]);
        self::assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $numbers[0]);
    }

    public function testRefundRejectsMissingIdempotencyKeyBeforeContactingWechat(): void
    {
        $this->wechatService->expects(self::never())->method('getPayApp');
        self::expectException(\InvalidArgumentException::class);
        $this->gateway->refund($this->createStub(Invoice::class), 100, 100, 'test');
    }

    public function testNotifySuccess(): void
    {
        $requestBody = json_encode([
            'id' => 'evt_001',
            'event_type' => 'TRANSACTION.SUCCESS',
            'resource' => ['ciphertext' => 'test', 'associated_data' => '', 'nonce' => ''],
        ]);

        $payApp = $this->createMock(PayApp::class);
        $server = $this->createMock(\EasyWeChat\Pay\Server::class);

        // Simulate handlePaid invoking the callback with a message
        $message = new class {
            public string $out_trade_no = 'TXN_NOTIFY';
            public string $transaction_id = 'WX_TXN_NOTIFY';
            public string $success_time = '2026-06-25T10:00:00+08:00';
            public array $amount = ['total' => 200, 'currency' => 'CNY'];

            public function toArray(): array
            {
                return [
                    'out_trade_no' => $this->out_trade_no,
                    'transaction_id' => $this->transaction_id,
                ];
            }
        };

        $server->method('handlePaid')->willReturnCallback(function ($cb) use ($message, $server) {
            $next = fn($m) => $m;
            $cb($message, $next);
            return $server;
        });
        $server->method('serve')->willReturn($this->createMock(\Psr\Http\Message\ResponseInterface::class));

        $validator = new class implements \EasyWeChat\Pay\Contracts\Validator {
            public function validate(mixed $message): void {}
        };
        $payApp->method('getValidator')->willReturn($validator);
        $payApp->method('getServer')->willReturn($server);

        $this->wechatService->method('getPayApp')->willReturn($payApp);

        $request = Request::create('/notify', 'POST', [], [], [], [], $requestBody);
        $result = $this->gateway->notify($request);

        self::assertSame('wechat', $result->payment);
        self::assertSame('TXN_NOTIFY', $result->outTradeNo);
        self::assertSame('WX_TXN_NOTIFY', $result->transactionId);
        self::assertSame(200, $result->amount);
        self::assertSame(Invoice::STATUS_PAID, $result->status);
        self::assertSame('CNY', $result->currency);
    }
}
