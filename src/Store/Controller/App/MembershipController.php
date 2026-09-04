<?php

declare(strict_types=1);

namespace App\Store\Controller\App;

use App\Core\Controller\RestController;
use App\Core\View\ApiViewMessages;
use App\Identity\Entity\User;
use App\Store\Entity\Membership;
use App\Store\Entity\Store;
use App\Store\Repository\MembershipRepository;
use App\Store\Service\MembershipServiceInterface;
use App\Store\Service\StoreServiceInterface;
use OpenApi\Attributes as OA;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/app/stores/{uuid}/membership', name: 'app-stores-membership-')]
#[IsGranted('ROLE_USER')]
final class MembershipController extends RestController
{
    public function __construct(
        private readonly StoreServiceInterface $storeService,
        private readonly MembershipServiceInterface $membershipService,
        private readonly MembershipRepository $membershipRepository,
    ) {
    }

    #[OA\Post(
        path: '/api/v1/app/stores/{uuid}/membership',
        summary: 'Join store as member (self-service, idempotent)',
        description: 'Authenticated user joins the store as a member. Fixed role=clerk, status=active. Idempotent: repeated calls return the same membership. Request body is ignored - special fields like role/status cannot be set.',
        tags: ['Store'],
        parameters: [
            new OA\Parameter(name: 'uuid', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'), description: 'Store UUID'),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Already a member or joined successfully'),
            new OA\Response(response: 201, description: 'Joined as member'),
            new OA\Response(response: 401, description: 'Unauthorized'),
            new OA\Response(response: 404, description: 'Store not found'),
        ]
    )]
    #[Route('', name: 'join', methods: ['POST'], requirements: ['uuid' => '[0-9a-fA-F-]{36}'])]
    public function joinAction(string $uuid): Response
    {
        $store = $this->storeService->get(['uuid' => $uuid]);
        if (!$store instanceof Store) {
            return $this->warning(ApiViewMessages::STORE_NOT_FOUND, 404, '', 404);
        }

        if (!$store->isActive()) {
            return $this->warning('Store is not active.', 400, '', 400);
        }

        $user = $this->getUser();
        if (!$user instanceof User) {
            return $this->warning(ApiViewMessages::ACCESS_DENIED, 401, '', 401);
        }

        $userUuid = $user->getUuid();

        // Idempotency: any active membership (any role) is kept as-is, no downgrade
        $existing = $this->membershipRepository->findForStoreAndUser($store, $userUuid);
        if ($existing instanceof Membership && $existing->isActive()) {
            return $this->success($existing, 'Already a member.');
        }

        // Fixed role=clerk, cannot be overridden by request body
        try {
            $membership = $this->membershipService->grant($store, $userUuid, Membership::ROLE_CLERK);
        } catch (\Throwable $e) {
            return $this->warning($e->getMessage(), 400, '', 400);
        }

        // 201 on first creation, 200 when reactivated from suspended/revoked
        $isNew = $existing === null;
        $status = $isNew ? 201 : 200;
        $message = $isNew ? 'Joined as member.' : 'Membership reactivated.';

        return $this->success($membership, $message, $status);
    }

    #[OA\Get(
        path: '/api/v1/app/stores/{uuid}/membership',
        summary: 'Get own membership for store',
        tags: ['Store'],
        parameters: [
            new OA\Parameter(name: 'uuid', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Membership found'),
            new OA\Response(response: 404, description: 'Store or membership not found'),
        ]
    )]
    #[Route('', name: 'status', methods: ['GET'], requirements: ['uuid' => '[0-9a-fA-F-]{36}'])]
    public function statusAction(string $uuid): Response
    {
        $store = $this->storeService->get(['uuid' => $uuid]);
        if (!$store instanceof Store) {
            return $this->warning(ApiViewMessages::STORE_NOT_FOUND, 404, '', 404);
        }

        $user = $this->getUser();
        if (!$user instanceof User) {
            return $this->warning(ApiViewMessages::ACCESS_DENIED, 401, '', 401);
        }

        $membership = $this->membershipRepository->findForStoreAndUser($store, $user->getUuid());
        if (!$membership instanceof Membership) {
            return $this->warning('Membership not found.', 404, '', 404);
        }

        return $this->success($membership);
    }
}
