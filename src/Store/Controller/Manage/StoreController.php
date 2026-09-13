<?php

declare(strict_types=1);

namespace App\Store\Controller\Manage;

use App\Core\Controller\RestController;
use App\Core\View\ApiView;
use App\Core\View\ApiViewMessages;
use App\Core\View\CreateApiViewMixin;
use App\Core\View\DetailApiViewMixin;
use App\Core\View\ListApiViewMixin;
use App\Core\View\UpdateApiViewMixin;
use App\Store\Entity\Store;
use App\Store\Service\MembershipServiceInterface;
use App\Store\Service\StoreServiceInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/manage/stores', name: 'manage-stores-')]
#[IsGranted('ROLE_ADMIN')]
final class StoreController extends RestController
{
    use ApiView, DetailApiViewMixin, ListApiViewMixin, CreateApiViewMixin, UpdateApiViewMixin;

    /** @var list<string> */
    protected array $requiredCreateProperties = ['code', 'name', 'timezone'];

    /** @var list<string> */
    protected array $acceptedCreateProperties = ['code', 'name', 'timezone', 'currency', 'contact', 'address', 'settings'];

    /** @var list<string> */
    protected array $acceptedUpdateProperties = ['name', 'timezone', 'currency', 'contact', 'address', 'settings'];

    /** @var array<string, string> JSON field → bundle schema */
    protected array $jsonSchemas = [
        'contact' => 'Store/StoreContact',
        'address' => 'Store/StoreAddress',
        'settings' => 'Store/StoreSettings',
    ];

    public function __construct(
        protected readonly StoreServiceInterface $service,
        private readonly MembershipServiceInterface $membershipService,
    ) {
    }

    /**
     * @param array<string, mixed> $content
     * @return array<string, mixed>
     */
    protected function processCreateContent(array $content, object $entity): array
    {
        $this->validateStoreContent($content, true);

        return $content;
    }

    /**
     * @param array<string, mixed> $content
     * @return array<string, mixed>
     */
    protected function processUpdateContent(array $content, ?object $entity = null): array
    {
        $this->validateStoreContent($content, false);

        return $content;
    }

    #[Route('/{uuid}/status/{status<activate|suspend|close>}', name: 'status', methods: ['POST'], requirements: ['uuid' => '[0-9a-fA-F-]{36}'])]
    public function statusAction(string $uuid, string $status): Response
    {
        $store = $this->store($uuid);
        if ($store === null) {
            return $this->warning(ApiViewMessages::STORE_NOT_FOUND, 404, '', 404);
        }

        $store->{$status}();
        $this->service->update($store, []);

        return $this->success($store);
    }

    #[Route('/{uuid}/members', name: 'members-list', methods: ['GET'], requirements: ['uuid' => '[0-9a-fA-F-]{36}'])]
    public function listMembersAction(string $uuid): Response
    {
        $store = $this->store($uuid);
        if ($store === null) {
            return $this->warning(ApiViewMessages::STORE_NOT_FOUND, 404, '', 404);
        }

        return $this->success($this->membershipService->list(['store' => $store], ['entity.createdAt' => 'ASC'], false));
    }

    #[Route('/{uuid}/members', name: 'members-grant', methods: ['POST'], requirements: ['uuid' => '[0-9a-fA-F-]{36}'])]
    public function grantMemberAction(Request $request, string $uuid): Response
    {
        $store = $this->store($uuid);
        if ($store === null) {
            return $this->warning(ApiViewMessages::STORE_NOT_FOUND, 404, '', 404);
        }

        $data = json_decode($request->getContent(), true);
        if (!is_array($data) || !is_string($data['userUuid'] ?? null) || !is_string($data['role'] ?? null)) {
            return $this->warning('userUuid and role are required.', 400, '', 400);
        }

        try {
            $membership = $this->membershipService->grant($store, $data['userUuid'], $data['role']);
        } catch (\Throwable $exception) {
            return $this->warning($exception->getMessage(), 400, '', 400);
        }

        return $this->success($membership, 'Membership granted.', 201);
    }

    /** @param array<string, mixed> $content */
    private function validateStoreContent(array $content, bool $creating): void
    {
        if ($creating && (!is_string($content['code'] ?? null) || trim($content['code']) === '')) {
            throw new \InvalidArgumentException('code must be a non-empty string.');
        }
        if (array_key_exists('name', $content) && (!is_string($content['name']) || trim($content['name']) === '')) {
            throw new \InvalidArgumentException('name must be a non-empty string.');
        }
        if (array_key_exists('timezone', $content)) {
            if (!is_string($content['timezone'])) {
                throw new \InvalidArgumentException('timezone must be a string.');
            }
            try {
                new \DateTimeZone($content['timezone']);
            } catch (\Exception) {
                throw new \InvalidArgumentException('timezone must be a valid timezone.');
            }
        }
        if (array_key_exists('currency', $content)) {
            if (!is_string($content['currency']) || trim($content['currency']) === '') {
                throw new \InvalidArgumentException('currency must be a non-empty string.');
            }
            if (!preg_match('/^[A-Za-z0-9._-]{1,32}$/', $content['currency'])) {
                throw new \InvalidArgumentException('currency must be 1-32 chars of letters, digits, ., _, -.');
            }
        }
        foreach (['contact', 'address', 'settings'] as $field) {
            if (array_key_exists($field, $content) && $content[$field] !== null && !is_array($content[$field])) {
                throw new \InvalidArgumentException(sprintf('%s must be an object or null.', $field));
            }
        }
        if (array_key_exists('settings', $content) && is_array($content['settings'])) {
            $this->validateStoreSettings($content['settings']);
        }
    }

    /** @param array<string, mixed> $settings */
    private function validateStoreSettings(array $settings): void
    {
        if (array_key_exists('order', $settings) && $settings['order'] !== null && !is_array($settings['order'])) {
            throw new \InvalidArgumentException('settings.order must be an object or null.');
        }
        if (array_key_exists('fulfillment', $settings) && $settings['fulfillment'] !== null && !is_array($settings['fulfillment'])) {
            throw new \InvalidArgumentException('settings.fulfillment must be an object or null.');
        }
        if (isset($settings['fulfillment']) && is_array($settings['fulfillment']) && array_key_exists('requireVerification', $settings['fulfillment'])) {
            if (!is_bool($settings['fulfillment']['requireVerification'])) {
                throw new \InvalidArgumentException('settings.fulfillment.requireVerification must be a boolean.');
            }
        }
    }

    private function store(string $uuid): ?Store
    {
        $store = $this->service->get(['uuid' => $uuid]);

        return $store instanceof Store ? $store : null;
    }
}
