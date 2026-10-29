<?php

declare(strict_types=1);

namespace App\Wechat\Service\Payment;

use App\Payment\DTO\PaymentNotifyResult;
use App\Payment\DTO\PaymentRefundResult;
use App\Payment\DTO\PaymentResult;
use App\Payment\Entity\Invoice;
use App\Payment\Exception\PaymentVerificationException;
use App\Payment\Service\PaymentGatewayInterface;
use App\Wechat\Repository\WechatUserRepository;
use App\Wechat\Service\WechatServiceInterface;
use Symfony\Bridge\PsrHttpMessage\HttpMessageFactoryInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Contracts\HttpClient\ResponseInterface;

final class WechatPayGateway implements PaymentGatewayInterface
{
    public function __construct(
        private readonly WechatServiceInterface $wechatService,
        private readonly WechatUserRepository $wechatUserRepository,
        private readonly HttpMessageFactoryInterface $psrHttpFactory,
        #[Autowire('%wechat.pay.notify_url%')]
        private readonly string $notifyUrl,
    ) {}

    public static function getName(): string
    {
        return Invoice::PAYMENT_WECHAT;
    }

    /** @param array<string, mixed> $options */
    public function pay(Invoice $invoice, int $amount, array $options = []): PaymentResult
    {
        $app = $this->wechatService->getPayApp();
        $tradeType = $invoice->getTradeType() ?? 'jsapi';

        $body = [
            'mchid' => (string) $app->getMerchant()->getMerchantId(),
            'out_trade_no' => $invoice->getOutTradeNo(),
            'appid' => $this->wechatService->getMiniApp()->getAccount()->getAppId(),
            'description' => $invoice->getSubject() ?? $invoice->getDescription() ?? 'Payment',
            'notify_url' => $this->notifyUrl,
            'amount' => [
                'total' => $amount,
                'currency' => $invoice->getCurrency(),
            ],
        ];

        if ($tradeType === 'jsapi') {
            $payer = $invoice->getPayer();
            if ($payer === null) {
                throw new \RuntimeException('WeChat user not found for payer. Login via WeChat first.');
            }
            $miniAppId = $this->wechatService->getMiniApp()->getAccount()->getAppId();
            $wechatUser = $this->wechatUserRepository->findByUserAndAppId($payer, $miniAppId);
            if ($wechatUser === null) {
                throw new \RuntimeException('WeChat user not found for payer. Login via WeChat first.');
            }
            $body['payer'] = ['openid' => $wechatUser->getOpenid()];

            $response = $this->postJson($app->getClient(), 'v3/pay/transactions/jsapi', $body);
            $result = $response->toArray(false);

            $prepayId = $result['prepay_id'] ?? '';
            $miniAppId = $this->wechatService->getMiniApp()->getAccount()->getAppId();
            $config = $app->getUtils()->buildMiniAppConfig($prepayId, $miniAppId, 'RSA');

            return new PaymentResult(
                invoice: $invoice,
                status: Invoice::STATUS_PAYING,
                payload: $config,
                message: 'WeChat JSAPI order created',
            );
        }

        if ($tradeType === 'native') {
            $response = $this->postJson($app->getClient(), 'v3/pay/transactions/native', $body);
            $result = $response->toArray(false);

            return new PaymentResult(
                invoice: $invoice,
                status: Invoice::STATUS_PAYING,
                payUrl: $result['code_url'] ?? null,
                message: 'WeChat Native order created',
            );
        }

        throw new \InvalidArgumentException(sprintf('Unsupported WeChat trade type: %s', $tradeType));
    }

    public function notify(Request $request): PaymentNotifyResult
    {
        $app = $this->wechatService->getPayApp();

        try {
            /** @var \EasyWeChat\Pay\Server $server */
            $server = $app->getServer();

            $notifyResult = null;
            $psrRequest = $this->psrHttpFactory->createRequest($request);
            $server->handlePaid(function ($message, \Closure $next) use ($app, $psrRequest, &$notifyResult) {
                $app->getValidator()->validate($psrRequest);

                $notifyResult = new PaymentNotifyResult(
                    payment: self::getName(),
                    outTradeNo: $message->out_trade_no ?? '',
                    status: Invoice::STATUS_PAID,
                    amount: (int) ($message->amount['total'] ?? 0),
                    currency: $message->amount['currency'] ?? 'CNY',
                    transactionId: $message->transaction_id ?? null,
                    paidAt: isset($message->success_time)
                        ? new \DateTimeImmutable($message->success_time)
                        : new \DateTimeImmutable(),
                    rawData: $message->toArray(),
                    responseBody: (string) json_encode(['code' => 'SUCCESS', 'message' => '成功']),
                );

                return $next($message);
            });

            $response = $server->serve();

            if ($notifyResult !== null) {
                return $notifyResult;
            }

            $body = (string) $response->getBody();
            throw new PaymentVerificationException(
                sprintf('WeChat notify failed: HTTP %d, body: %s', $response->getStatusCode(), $body),
            );
        } catch (PaymentVerificationException $e) {
            throw $e;
        } catch (\Throwable $e) {
            throw new PaymentVerificationException(
                'WeChat notify verification failed: ' . $e->getMessage(),
                0,
                $e,
            );
        }
    }

    /** @param array<string, mixed> $options */
    public function refund(Invoice $invoice, int $amount, int $paidAmount, string $reason, array $options = []): PaymentRefundResult
    {
        $key = $options['refundIdempotencyKey'] ?? null;
        if (!is_string($key) || $key === '') {
            throw new \InvalidArgumentException('A refundIdempotencyKey is required for WeChat refunds.');
        }
        $app = $this->wechatService->getPayApp();

        $outRefundNo = hash('sha256', $key);
        $body = [
            'out_trade_no' => $invoice->getOutTradeNo(),
            'out_refund_no' => $outRefundNo,
            'reason' => $reason,
            'amount' => [
                'refund' => $amount,
                'total' => $paidAmount,
                'currency' => $invoice->getCurrency(),
            ],
        ];

        $response = $this->postJson($app->getClient(), 'v3/refund/domestic/refunds', $body);
        $result = $response->toArray(false);
        if (($result['status'] ?? null) !== 'SUCCESS') {
            throw new \RuntimeException('WeChat refund has not confirmed success; reconcile or retry with the same idempotency key.');
        }

        $refundStatus = match ($result['status'] ?? '') {
            'SUCCESS' => ($amount >= $paidAmount - $invoice->getRefundedAmount())
                ? Invoice::STATUS_REFUNDED
                : Invoice::STATUS_PARTIAL_REFUNDED,
            default => $invoice->getStatus(),
        };

        return new PaymentRefundResult(
            invoice: $invoice,
            amount: $amount,
            status: $refundStatus,
            refundId: $result['refund_id'] ?? null,
            rawData: $result,
        );
    }

    public function getNotifySuccessResponse(PaymentNotifyResult $result): Response
    {
        return new JsonResponse(
            json_decode($result->responseBody, true) ?? ['code' => 'SUCCESS', 'message' => '成功']
        );
    }

    /**
     * EasyWeChat decorates Symfony's HTTP client with postJson().
     *
     * @param array<string, mixed> $body
     */
    private function postJson(object $client, string $url, array $body): ResponseInterface
    {
        $postJson = [$client, 'postJson'];
        if (!is_callable($postJson)) {
            throw new \RuntimeException('WeChat client does not support JSON requests.');
        }

        $response = $postJson($url, $body);
        if (!$response instanceof ResponseInterface) {
            throw new \RuntimeException('WeChat client returned an invalid response.');
        }

        return $response;
    }
}
