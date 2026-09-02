<?php

declare(strict_types=1);

namespace App\Authorization\Command;

use App\Authorization\Entity\Permission;
use App\Authorization\Entity\Role;
use App\Authorization\Entity\RoleFieldGrant;
use App\Authorization\Service\AuthorizationResourceRegistry;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'app:authorization:seed', description: 'Seed Authorization permissions, roles and field grants')]
final class SeedAuthorizationCommand extends Command
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly AuthorizationResourceRegistry $registry,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $em = $this->em;
        $permRepo = $em->getRepository(Permission::class);
        $roleRepo = $em->getRepository(Role::class);
        $fieldGrantRepo = $em->getRepository(RoleFieldGrant::class);

        $permissionsData = $this->getPermissionsData();
        $createdPermissions = 0;
        foreach ($permissionsData as $data) {
            $code = $data['code'];
            $permission = $permRepo->findOneBy(['code' => $code]);
            if ($permission === null) {
                $permission = new Permission($code, $data['module'], $data['resource'], $data['action'], $data['name']);
                $permission->setDescription($data['description'] ?? null);
                $permission->setIsSystem(true);
                $em->persist($permission);
                ++$createdPermissions;
            } else {
                // Update name/description if changed, but do not widen automatically
                if ($permission->getName() !== $data['name']) {
                    $permission->setName($data['name']);
                    $permission->touch();
                }
                // Ensure isSystem
                if (!$permission->isSystem()) {
                    $permission->setIsSystem(true);
                    $permission->touch();
                }
            }
        }
        $em->flush();
        $output->writeln(sprintf('Permissions: %d created, %d total', $createdPermissions, \count($permissionsData)));

        // Roles
        $rolesData = $this->getRolesData();
        $createdRoles = 0;
        $updatedRoles = 0;
        foreach ($rolesData as $data) {
            $code = $data['code'];
            $role = $roleRepo->findOneBy(['code' => $code]);
            if ($role === null) {
                $role = new Role($code, $data['name'], $data['scopeType']);
                $role->setIsSystem($data['isSystem'] ?? true);
                $em->persist($role);
                ++$createdRoles;
            } else {
                $changed = false;
                if ($role->getName() !== $data['name']) {
                    $role->setName($data['name']);
                    $changed = true;
                }
                if ($role->getScopeType() !== $data['scopeType']) {
                    $output->writeln(sprintf('<error>Incompatible scopeType for system role "%s": expected "%s" got "%s"</error>', $code, $role->getScopeType(), $data['scopeType']));
                    return Command::FAILURE;
                }
                if ($changed) {
                    $role->touch();
                    ++$updatedRoles;
                }
            }
            // Ensure permissions
            $permissionCodes = $data['permissions'] ?? [];
            $permissions = [];
            foreach ($permissionCodes as $pCode) {
                $p = $permRepo->findOneBy(['code' => $pCode]);
                if ($p === null) {
                    $output->writeln(sprintf('<error>Permission "%s" not found for role "%s"</error>', $pCode, $code));
                    return Command::FAILURE;
                }
                $permissions[] = $p;
            }
            // Check if permissions differ
            $currentCodes = array_map(static fn (Permission $p): string => $p->getCode(), $role->getPermissions()->toArray());
            sort($currentCodes);
            $sortedNew = $permissionCodes;
            sort($sortedNew);
            if ($currentCodes !== $sortedNew) {
                // For system roles, we reconcile only if not present? But we should not automatically widen? However seed should ensure system roles have expected permissions.
                // We will set to expected if different, but report
                $role->getPermissions()->clear();
                foreach ($permissions as $p) {
                    $role->addPermission($p);
                }
                $role->touch();
                ++$updatedRoles;
            }
        }
        $em->flush();
        $output->writeln(sprintf('Roles: %d created, %d updated', $createdRoles, $updatedRoles));

        // Field grants
        $fieldGrantsData = $this->getFieldGrantsData();
        $createdGrants = 0;
        $updatedGrants = 0;
        foreach ($fieldGrantsData as $data) {
            $roleCode = $data['role'];
            $role = $roleRepo->findOneBy(['code' => $roleCode]);
            if ($role === null) {
                $output->writeln(sprintf('<error>Role "%s" not found for field grant</error>', $roleCode));
                return Command::FAILURE;
            }
            $resource = $data['resource'];
            $action = $data['action'];
            $fields = $data['fields'];

            try {
                $this->registry->assertValidFields($resource, $action, $fields);
            } catch (\InvalidArgumentException $e) {
                $output->writeln(sprintf('<error>Invalid field grant for role "%s": %s</error>', $roleCode, $e->getMessage()));
                return Command::FAILURE;
            }

            $grant = $fieldGrantRepo->findOneBy(['role' => $role->getId(), 'resource' => $resource, 'action' => $action]);
            if ($grant === null) {
                $grant = new RoleFieldGrant($role, $resource, $action, $fields);
                $em->persist($grant);
                ++$createdGrants;
            } else {
                $currentFields = $grant->getFields();
                sort($currentFields);
                $sortedNew = $fields;
                sort($sortedNew);
                if ($currentFields !== $sortedNew) {
                    $grant->setFields($fields);
                    $grant->touch();
                    ++$updatedGrants;
                }
            }
        }
        $em->flush();
        $output->writeln(sprintf('Field grants: %d created, %d updated', $createdGrants, $updatedGrants));

        $output->writeln('<info>Authorization seed completed</info>');

        return Command::SUCCESS;
    }

    /**
     * @return list<array{code: string, module: string, resource: string, action: string, name: string, description?: string}>
     */
    private function getPermissionsData(): array
    {
        return [
            ['code' => 'authorization:role:manage', 'module' => 'authorization', 'resource' => 'role', 'action' => 'manage', 'name' => 'Manage roles', 'description' => 'Create and manage roles'],
            ['code' => 'authorization:assignment:manage', 'module' => 'authorization', 'resource' => 'assignment', 'action' => 'manage', 'name' => 'Manage assignments', 'description' => 'Grant and revoke assignments'],
            ['code' => 'common:content:read', 'module' => 'common', 'resource' => 'content', 'action' => 'read', 'name' => 'Read content'],
            ['code' => 'common:content:create', 'module' => 'common', 'resource' => 'content', 'action' => 'create', 'name' => 'Create content'],
            ['code' => 'common:content:update', 'module' => 'common', 'resource' => 'content', 'action' => 'update', 'name' => 'Update content'],
            ['code' => 'common:content:delete', 'module' => 'common', 'resource' => 'content', 'action' => 'delete', 'name' => 'Delete content'],
            ['code' => 'store:order:read', 'module' => 'store', 'resource' => 'order', 'action' => 'read', 'name' => 'Read store orders'],
            ['code' => 'store:order:accept', 'module' => 'store', 'resource' => 'order', 'action' => 'accept', 'name' => 'Accept store orders'],
            ['code' => 'store:order:reject', 'module' => 'store', 'resource' => 'order', 'action' => 'reject', 'name' => 'Reject store orders'],
            ['code' => 'store:order:fulfill', 'module' => 'store', 'resource' => 'order', 'action' => 'fulfill', 'name' => 'Fulfill store orders'],
            ['code' => 'store:order:verify', 'module' => 'store', 'resource' => 'order', 'action' => 'verify', 'name' => 'Verify store orders'],
            ['code' => 'store:product:read', 'module' => 'store', 'resource' => 'product', 'action' => 'read', 'name' => 'Read store products'],
            ['code' => 'store:product:create', 'module' => 'store', 'resource' => 'product', 'action' => 'create', 'name' => 'Create store products'],
            ['code' => 'store:product:update', 'module' => 'store', 'resource' => 'product', 'action' => 'update', 'name' => 'Update store products'],
            ['code' => 'store:product:delete', 'module' => 'store', 'resource' => 'product', 'action' => 'delete', 'name' => 'Delete store products'],
            ['code' => 'store:specification:read', 'module' => 'store', 'resource' => 'specification', 'action' => 'read', 'name' => 'Read store specifications'],
            ['code' => 'store:specification:create', 'module' => 'store', 'resource' => 'specification', 'action' => 'create', 'name' => 'Create store specifications'],
            ['code' => 'store:specification:update', 'module' => 'store', 'resource' => 'specification', 'action' => 'update', 'name' => 'Update store specifications'],
            ['code' => 'store:specification:delete', 'module' => 'store', 'resource' => 'specification', 'action' => 'delete', 'name' => 'Delete store specifications'],
            ['code' => 'wallet:voucher:manual', 'module' => 'wallet', 'resource' => 'voucher', 'action' => 'manual', 'name' => 'Manual voucher operations'],
        ];
    }

    /**
     * @return list<array{code: string, name: string, scopeType: string, isSystem: bool, permissions: list<string>}>
     */
    private function getRolesData(): array
    {
        return [
            [
                'code' => 'store_content_editor',
                'name' => 'Store Content Editor',
                'scopeType' => Role::SCOPE_STORE,
                'isSystem' => true,
                'permissions' => ['common:content:read', 'common:content:create', 'common:content:update', 'common:content:delete'],
            ],
            [
                'code' => 'store_content_metadata_editor',
                'name' => 'Store Content Metadata Editor',
                'scopeType' => Role::SCOPE_STORE,
                'isSystem' => true,
                'permissions' => ['common:content:read', 'common:content:create', 'common:content:update', 'common:content:delete'],
            ],
            [
                'code' => 'store_catalog_manager',
                'name' => 'Store Catalog Manager',
                'scopeType' => Role::SCOPE_STORE,
                'isSystem' => true,
                'permissions' => [
                    'store:product:read', 'store:product:create', 'store:product:update', 'store:product:delete',
                    'store:specification:read', 'store:specification:create', 'store:specification:update', 'store:specification:delete',
                ],
            ],
            [
                'code' => 'authorization_administrator',
                'name' => 'Authorization Administrator',
                'scopeType' => Role::SCOPE_GLOBAL,
                'isSystem' => true,
                'permissions' => ['authorization:role:manage', 'authorization:assignment:manage'],
            ],
        ];
    }

    /**
     * @return list<array{role: string, resource: string, action: string, fields: list<string>}>
     */
    private function getFieldGrantsData(): array
    {
        return [
            ['role' => 'store_content_editor', 'resource' => 'common:content', 'action' => 'create', 'fields' => ['title', 'body', 'category', 'tags']],
            ['role' => 'store_content_editor', 'resource' => 'common:content', 'action' => 'update', 'fields' => ['title', 'body', 'category', 'tags']],
            ['role' => 'store_content_metadata_editor', 'resource' => 'common:content', 'action' => 'create', 'fields' => ['title', 'body', 'category', 'tags', 'metadata']],
            ['role' => 'store_content_metadata_editor', 'resource' => 'common:content', 'action' => 'update', 'fields' => ['title', 'body', 'category', 'tags', 'metadata']],
        ];
    }
}
