<?php

declare(strict_types=1);

namespace App\Store\Controller\Staff;

use App\Core\Controller\RestController;
use App\Core\View\ScopedDetailApiViewMixin;
use App\Core\View\ScopedListApiViewMixin;
use App\Store\Entity\Store;
use App\Store\Entity\StoreOrder;
use App\Store\Service\StoreOrderServiceInterface;
use App\Store\Service\StoreServiceInterface;
use App\Store\View\StoreScopedAuthorizationApiMixin;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/store/{scopeId}/orders', name: 'store-orders-', requirements: ['scopeId' => '\d+|[0-9a-fA-F-]{36}'])]
#[IsGranted('ROLE_USER')]
final class StoreOrderController extends RestController
{
    use StoreScopedAuthorizationApiMixin, ScopedListApiViewMixin, ScopedDetailApiViewMixin;

    public function __construct(
        protected readonly StoreOrderServiceInterface $service,
        private readonly StoreServiceInterface $storeService,
    ) {
    }

    /** @return array<string, mixed> */
    protected function storeScopedFilter(Store $store): array
    {
        return ['store' => $store];
    }

    protected function storeService(): StoreServiceInterface
    {
        return $this->storeService;
    }

    /** @return array<string, mixed> */
    protected function scopedListFilter(string $scopeId): array
    {
        return $this->storeScopedFilter($this->storeForAuthorization());
    }

    /** @return array<string, mixed> */
    protected function scopedDetailFilter(string $scopeId, string $id): array
    {
        return [$this->identifierField($id) => $id, ...$this->storeScopedFilter($this->storeForAuthorization())];
    }

    /** @return array<string, string> */
    protected function storeActionPermissions(): array
    {
        return [
            'accept' => 'store:order:accept',
            'reject' => 'store:order:reject',
            'fulfill' => 'store:order:fulfill',
            'verify' => 'store:order:verify',
        ];
    }

    protected function storeAuthorizationResource(): string
    {
        return 'order';
    }

    #[Route('/{orderUuid}/accept', name: 'accept', methods: ['POST'], requirements: ['orderUuid' => '\d+|[0-9a-fA-F-]{36}'])]
    public function acceptAction(Request $request, string $scopeId, string $orderUuid): Response
    {
        $this->authorizeStoreAction('accept');
        $order = $this->storeOrder($orderUuid);
        if ($order === null) {
            return $this->warning('Store order not found or access denied.', 404, '', 404);
        }
        if (!in_array($order->getOperationalStatus(), [StoreOrder::STATUS_PENDING_VALIDATION, StoreOrder::STATUS_AWAITING_INVENTORY], true)) {
            return $this->warning('Store order cannot be accepted in its current status.', 400, '', 400);
        }

        $data = $this->body($request);
        $reservationId = $data['reservationId'] ?? null;
        if ($reservationId !== null && !is_string($reservationId)) {
            return $this->warning('reservationId must be a string.', 400, '', 400);
        }
        $this->service->accept($order, $reservationId);

        return $this->success($order, 'Store order accepted.');
    }

    #[Route('/{orderUuid}/reject', name: 'reject', methods: ['POST'], requirements: ['orderUuid' => '\d+|[0-9a-fA-F-]{36}'])]
    public function rejectAction(Request $request, string $scopeId, string $orderUuid): Response
    {
        $this->authorizeStoreAction('reject');
        $order = $this->storeOrder($orderUuid);
        if ($order === null) {
            return $this->warning('Store order not found or access denied.', 404, '', 404);
        }
        if (!in_array($order->getOperationalStatus(), [StoreOrder::STATUS_PENDING_VALIDATION, StoreOrder::STATUS_AWAITING_INVENTORY], true)) {
            return $this->warning('Store order cannot be rejected in its current status.', 400, '', 400);
        }

        $data = $this->body($request);
        if (!is_string($data['code'] ?? null) || trim($data['code']) === '' || !is_string($data['reason'] ?? null) || trim($data['reason']) === '') {
            return $this->warning('code and reason are required.', 400, '', 400);
        }
        $this->service->reject($order, $data['code'], $data['reason']);

        return $this->success($order, 'Store order rejected.');
    }

    #[Route('/{orderUuid}/fulfill', name: 'fulfill', methods: ['POST'], requirements: ['orderUuid' => '\d+|[0-9a-fA-F-]{36}'])]
    public function fulfillAction(Request $request, string $scopeId, string $orderUuid): Response
    {
        $this->authorizeStoreAction('fulfill');
        $order = $this->storeOrder($orderUuid);
        if ($order === null) {
            return $this->warning('Store order not found or access denied.', 404, '', 404);
        }
        if (!in_array($order->getOperationalStatus(), [StoreOrder::STATUS_ACCEPTED, StoreOrder::STATUS_FULFILLMENT_PENDING, StoreOrder::STATUS_FULFILLING], true)) {
            return $this->warning('Store order cannot be fulfilled in its current status.', 400, '', 400);
        }

        $data = $this->body($request);
        $fulfillmentData = $data['fulfillmentData'] ?? null;
        if ($fulfillmentData !== null && !is_array($fulfillmentData)) {
            return $this->warning('fulfillmentData must be an object.', 400, '', 400);
        }
        $this->service->fulfill($order, $fulfillmentData);

        return $this->success($order, 'Store order fulfilled.');
    }

    #[Route('/{orderUuid}/verify', name: 'verify', methods: ['POST'], requirements: ['orderUuid' => '\d+|[0-9a-fA-F-]{36}'])]
    public function verifyAction(Request $request, string $scopeId, string $orderUuid): Response
    {
        $this->authorizeStoreAction('verify');
        $order = $this->storeOrder($orderUuid);
        if ($order === null) {
            return $this->warning('Store order not found or access denied.', 404, '', 404);
        }
        if ($order->getOperationalStatus() !== StoreOrder::STATUS_FULFILLED) {
            return $this->warning('Store order cannot be verified in its current status.', 400, '', 400);
        }
        if ($order->getVerifiedAt() !== null) {
            return $this->warning('Store order already verified.', 400, '', 400);
        }

        $data = $this->body($request);
        $verificationCode = $data['verificationCode'] ?? null;
        if (!is_string($verificationCode) || trim($verificationCode) === '') {
            return $this->warning('verificationCode is required.', 400, '', 400);
        }
        $verificationCode = trim($verificationCode);
        if (strlen($verificationCode) > 64) {
            return $this->warning('verificationCode must not exceed 64 characters.', 400, '', 400);
        }

        $user = $this->getUser();
        $verifiedBy = null;
        if ($user !== null && method_exists($user, 'getUuid')) {
            $verifiedBy = $user->getUuid();
        }

        $this->service->verify($order, $verificationCode, $verifiedBy);

        return $this->success($order, 'Store order verified.');
    }

    /** @return array<string, mixed> */
    private function body(Request $request): array
    {
        $data = json_decode($request->getContent(), true);
        return is_array($data) ? $data : [];
    }

    private function storeOrder(string $orderUuid): ?StoreOrder
    {
        $order = $this->service->get($this->mixIdToCommonFilter($orderUuid), false);
        return $order instanceof StoreOrder ? $order : null;
    }
}
