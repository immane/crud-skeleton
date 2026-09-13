<?php

declare(strict_types=1);

namespace App\Authorization\Controller\Manage;

use App\Authorization\Entity\Assignment;
use App\Authorization\Entity\Role;
use App\Authorization\Service\AssignmentService;
use App\Authorization\Service\AuthorizationAuditService;
use App\Authorization\Service\AuthorizationCacheInvalidator;
use App\Core\Controller\RestController;
use App\Core\Query\DqlExpression;
use App\Core\Utils\UUID;
use App\Core\View\ApiView;
use App\Core\View\CreateApiViewMixin;
use App\Core\View\DeleteApiViewMixin;
use App\Core\View\DetailApiViewMixin;
use App\Core\View\ListApiViewMixin;
use App\Core\View\UpdateApiViewMixin;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/manage/assignments', name: 'manage-assignments-')]
#[IsGranted('ROLE_ADMIN')]
class AssignmentController extends RestController
{
    use ApiView, ListApiViewMixin, DetailApiViewMixin,
        CreateApiViewMixin, UpdateApiViewMixin, DeleteApiViewMixin;

    /** @var list<string> */
    protected array $acceptedCreateProperties = ['userUuid', 'user_uuid', 'roleUuid', 'role_uuid', 'roleId', 'scopeType', 'scope_type', 'scopeUuid', 'scope_uuid'];

    /** @var list<string> */
    protected array $acceptedUpdateProperties = ['userUuid', 'user_uuid', 'roleUuid', 'role_uuid', 'roleId', 'scopeType', 'scope_type', 'scopeUuid', 'scope_uuid'];

    /** @var array<int, bool> */
    private array $grantedAssignments = [];

    /** @var array<int, array{before: array<string, mixed>, oldUserUuid: string}> */
    private array $updatedAssignments = [];

    public function __construct(
        protected readonly AssignmentService $service,
        private readonly AuthorizationAuditService $auditService,
        private readonly AuthorizationCacheInvalidator $cacheInvalidator,
        private readonly \Doctrine\ORM\EntityManagerInterface $em,
    ) {
    }

    /** @param array<string, mixed>|\Doctrine\ORM\QueryBuilder|DqlExpression|null $filter */
    protected function listFilter(array|\Doctrine\ORM\QueryBuilder|DqlExpression|null $filter = null): DqlExpression
    {
        $request = $this->getRequestStack()->getCurrentRequest();
        $parts = $request?->query->getBoolean('includeRevoked', false) ? ['entity.getId() > 0'] : ['!entity.getRevokedAt()'];
        $values = [];
        foreach (['userUuid', 'scopeType', 'scopeUuid'] as $field) {
            $value = $request?->query->get($field);
            if (\is_string($value) && $value !== '') {
                $parts[] = 'entity.get' . ucfirst($field) . '() == ' . $field;
                $values[$field] = $value;
            }
        }
        return new DqlExpression(implode(' && ', $parts), $values);
    }

    /**
     * @param array<string, mixed> $content
     * @return array{userUuid: string, roleUuid: string, scopeType: string, scopeUuid: ?string}
     */
    protected function processCreateContent(array $content, object $entity): array
    {
        $userUuid = trim((string) ($content['userUuid'] ?? $content['user_uuid'] ?? ''));
        $roleUuid = trim((string) ($content['roleUuid'] ?? $content['role_uuid'] ?? $content['roleId'] ?? ''));
        $scopeType = trim((string) ($content['scopeType'] ?? $content['scope_type'] ?? ''));
        $scopeUuid = isset($content['scopeUuid']) || isset($content['scope_uuid']) ? trim((string) ($content['scopeUuid'] ?? $content['scope_uuid'] ?? '')) : null;

        if ($userUuid === '' || $roleUuid === '' || $scopeType === '') {
            throw new \InvalidArgumentException('userUuid, roleUuid and scopeType are required');
        }
        if (!UUID::is_valid($userUuid)) {
            throw new \InvalidArgumentException('Invalid userUuid');
        }
        if (!\in_array($scopeType, [Assignment::SCOPE_GLOBAL, Assignment::SCOPE_STORE], true)) {
            throw new \InvalidArgumentException('Invalid scopeType, expected global or store');
        }
        if ($scopeType === Assignment::SCOPE_GLOBAL && $scopeUuid !== null && $scopeUuid !== '') {
            throw new \InvalidArgumentException('scopeUuid must be null for global scope');
        }
        if ($scopeType === Assignment::SCOPE_STORE && ($scopeUuid === null || $scopeUuid === '' || !UUID::is_valid($scopeUuid))) {
            throw new \InvalidArgumentException('Valid scopeUuid required for store scope');
        }
        return [
            'userUuid' => $userUuid,
            'roleUuid' => $roleUuid,
            'scopeType' => $scopeType,
            'scopeUuid' => $scopeType === Assignment::SCOPE_GLOBAL ? null : $scopeUuid,
        ];
    }

    /**
     * @param array{userUuid: string, roleUuid: string, scopeType: string, scopeUuid: ?string} $content
     */
    protected function processEntity(array $content, object $entity): object
    {
        $role = $this->resolveRole($content['roleUuid']);
        if ($role === null) {
            throw $this->createNotFoundException('Role not found');
        }
        if ($role->getScopeType() !== $content['scopeType']) {
            throw new \InvalidArgumentException(sprintf('Role scope "%s" incompatible with assignment scope "%s"', $role->getScopeType(), $content['scopeType']));
        }

        $repository = $this->em->getRepository(Assignment::class);
        $assignment = $repository->findActiveAssignment($content['userUuid'], $role, $content['scopeType'], $content['scopeUuid']);
        if ($assignment !== null) {
            $this->grantedAssignments[spl_object_id($assignment)] = false;

            return $assignment;
        }

        $roleId = $role->getId();
        \assert($roleId !== null);
        $assignment = $repository->findAnyByUserRoleScope($content['userUuid'], $roleId, $content['scopeType'], $content['scopeUuid']);
        if ($assignment !== null && $assignment->isRevoked()) {
            $assignment->setRevokedAt(null);
        } else {
            $actorUuid = $this->getUser() instanceof \App\Identity\Entity\User ? $this->getUser()->getUuid() : null;
            $assignment = new Assignment($role, $content['userUuid'], $content['scopeType'], $content['scopeUuid'], $actorUuid);
        }
        $this->grantedAssignments[spl_object_id($assignment)] = true;

        return $assignment;
    }

    protected function afterCreated(object|false $entity): mixed
    {
        if (!$entity instanceof Assignment || !($this->grantedAssignments[spl_object_id($entity)] ?? false)) {
            return $entity;
        }

        $actorUuid = $this->getUser() instanceof \App\Identity\Entity\User ? $this->getUser()->getUuid() : null;
        $this->auditService->record($actorUuid, 'assignment.granted', 'assignment', $entity->getUuid(), null, [
            'userUuid' => $entity->getUserUuid(),
            'roleCode' => $entity->getRole()->getCode(),
            'scopeType' => $entity->getScopeType(),
            'scopeUuid' => $entity->getScopeUuid(),
        ]);
        $this->em->flush();
        $this->cacheInvalidator->invalidateUser($entity->getUserUuid());

        return $entity;
    }

    /**
     * @param array<string, mixed> $content
     * @return array<string, mixed>
     */
    protected function processUpdateContent(array $content, ?object $entity = null): array
    {
        if (!$entity instanceof Assignment) {
            return $content;
        }
        if ($content === []) {
            throw new \InvalidArgumentException('No updatable fields');
        }

        $userUuid = trim((string) ($content['userUuid'] ?? $content['user_uuid'] ?? $entity->getUserUuid()));
        $roleIdentifier = trim((string) ($content['roleUuid'] ?? $content['role_uuid'] ?? $content['roleId'] ?? $entity->getRole()->getUuid()));
        $scopeType = trim((string) ($content['scopeType'] ?? $content['scope_type'] ?? $entity->getScopeType()));
        $scopeUuid = array_key_exists('scopeUuid', $content) || array_key_exists('scope_uuid', $content)
            ? trim((string) ($content['scopeUuid'] ?? $content['scope_uuid'] ?? ''))
            : $entity->getScopeUuid();
        $normalized = $this->normalizeAssignmentInput($userUuid, $roleIdentifier, $scopeType, $scopeUuid);

        $role = $this->resolveRole($normalized['roleUuid']);
        if ($role === null) {
            throw $this->createNotFoundException('Role not found');
        }
        if ($role->getScopeType() !== $normalized['scopeType']) {
            throw new \InvalidArgumentException(sprintf('Role scope "%s" incompatible with assignment scope "%s"', $role->getScopeType(), $normalized['scopeType']));
        }

        $existing = $this->em->getRepository(Assignment::class)->findActiveAssignment($normalized['userUuid'], $role, $normalized['scopeType'], $normalized['scopeUuid']);
        if ($existing !== null && $existing->getId() !== $entity->getId()) {
            throw new \InvalidArgumentException('Assignment already exists');
        }

        $this->updatedAssignments[spl_object_id($entity)] = [
            'before' => $this->assignmentData($entity),
            'oldUserUuid' => $entity->getUserUuid(),
        ];
        $entity->setUserUuid($normalized['userUuid']);
        $entity->setRole($role);
        $entity->setScopeType($normalized['scopeType']);
        $entity->setScopeUuid($normalized['scopeUuid']);

        return [];
    }

    protected function afterUpdated(object|false $entity): mixed
    {
        if (!$entity instanceof Assignment || !isset($this->updatedAssignments[spl_object_id($entity)])) {
            return $entity;
        }

        $state = $this->updatedAssignments[spl_object_id($entity)];
        unset($this->updatedAssignments[spl_object_id($entity)]);
        $after = $this->assignmentData($entity);
        if ($state['before'] === $after) {
            return $entity;
        }

        $actorUuid = $this->getUser() instanceof \App\Identity\Entity\User ? $this->getUser()->getUuid() : null;
        $this->auditService->record($actorUuid, 'assignment.updated', 'assignment', $entity->getUuid(), $state['before'], $after);
        $this->em->flush();
        $this->cacheInvalidator->invalidateUser($state['oldUserUuid']);
        $this->cacheInvalidator->invalidateUser($entity->getUserUuid());

        return $entity;
    }

    protected function processDeletion(object $entity): ?Response
    {
        if (!$entity instanceof Assignment) {
            return null;
        }
        if ($entity->isRevoked()) {
            return $this->success('', 'SUCCESS', 204);
        }

        $actorUuid = $this->getUser() instanceof \App\Identity\Entity\User ? $this->getUser()->getUuid() : null;
        $entity->setRevokedAt(new \DateTimeImmutable());
        $this->auditService->record($actorUuid, 'assignment.revoked', 'assignment', $entity->getUuid(), null, [
            'userUuid' => $entity->getUserUuid(),
            'roleCode' => $entity->getRole()->getCode(),
            'scopeType' => $entity->getScopeType(),
            'scopeUuid' => $entity->getScopeUuid(),
        ]);
        $this->em->flush();
        $this->cacheInvalidator->invalidateUser($entity->getUserUuid());

        return $this->success('', 'SUCCESS', 204);
    }

    private function resolveRole(string $identifier): ?Role
    {
        $repository = $this->em->getRepository(Role::class);
        if (UUID::is_valid($identifier)) {
            $role = $repository->findOneBy(['uuid' => $identifier]);
            if ($role !== null) {
                return $role;
            }
        }
        if (ctype_digit($identifier)) {
            $role = $repository->find((int) $identifier);
            if ($role !== null) {
                return $role;
            }
        }

        return $repository->findOneBy(['code' => $identifier]);
    }

    /**
     * @return array{userUuid: string, roleUuid: string, scopeType: string, scopeUuid: ?string}
     */
    private function normalizeAssignmentInput(string $userUuid, string $roleUuid, string $scopeType, ?string $scopeUuid): array
    {
        if ($userUuid === '' || $roleUuid === '' || $scopeType === '') {
            throw new \InvalidArgumentException('userUuid, roleUuid and scopeType are required');
        }
        if (!UUID::is_valid($userUuid)) {
            throw new \InvalidArgumentException('Invalid userUuid');
        }
        if (!\in_array($scopeType, [Assignment::SCOPE_GLOBAL, Assignment::SCOPE_STORE], true)) {
            throw new \InvalidArgumentException('Invalid scopeType, expected global or store');
        }
        if ($scopeType === Assignment::SCOPE_GLOBAL && $scopeUuid !== null && $scopeUuid !== '') {
            throw new \InvalidArgumentException('scopeUuid must be null for global scope');
        }
        if ($scopeType === Assignment::SCOPE_STORE && ($scopeUuid === null || $scopeUuid === '' || !UUID::is_valid($scopeUuid))) {
            throw new \InvalidArgumentException('Valid scopeUuid required for store scope');
        }

        return [
            'userUuid' => $userUuid,
            'roleUuid' => $roleUuid,
            'scopeType' => $scopeType,
            'scopeUuid' => $scopeType === Assignment::SCOPE_GLOBAL ? null : $scopeUuid,
        ];
    }

    /**
     * @return array{userUuid: string, roleCode: string, scopeType: string, scopeUuid: ?string}
     */
    private function assignmentData(Assignment $assignment): array
    {
        return [
            'userUuid' => $assignment->getUserUuid(),
            'roleCode' => $assignment->getRole()->getCode(),
            'scopeType' => $assignment->getScopeType(),
            'scopeUuid' => $assignment->getScopeUuid(),
        ];
    }
}
