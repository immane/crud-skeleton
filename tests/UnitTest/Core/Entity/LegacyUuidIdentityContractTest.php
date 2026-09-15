<?php

declare(strict_types=1);

namespace App\Tests\UnitTest\Core\Entity;

use App\Authorization\Entity\Assignment;
use App\Authorization\Entity\Role;
use App\Inventory\Entity\Material;
use App\Wallet\Entity\Transaction;
use PHPUnit\Framework\TestCase;

final class LegacyUuidIdentityContractTest extends TestCase
{
    public function testConstructorGeneratesValidUuid(): void
    {
        $material = new Material('uuid-code', 'UUID Material', Material::KIND_RAW, 'unit');

        self::assertMatchesRegularExpression(
            '/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i',
            $material->getUuid(),
        );

        $other = new Material('uuid-code-2', 'UUID Material 2', Material::KIND_RAW, 'unit');

        self::assertMatchesRegularExpression(
            '/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i',
            $other->getUuid(),
        );
        self::assertNotSame($material->getUuid(), $other->getUuid());
    }

    public function testExistingUuidOverrideApisRemainSupported(): void
    {
        $uuid = '9f81d52b-39d3-4e90-98d3-7d7b7f87b986';
        $role = new Role('uuid-role', 'UUID Role', Role::SCOPE_GLOBAL);
        $assignment = new Assignment($role, 'user-uuid', Assignment::SCOPE_GLOBAL);

        self::assertSame($assignment, $assignment->setUuid($uuid));
        self::assertSame($uuid, $assignment->getUuid());
        self::assertSame($role, $role->setUuid($uuid));
        self::assertSame($uuid, $role->getUuid());
        self::assertSame($uuid, (new Transaction($uuid, 100, Transaction::TYPE_DEPOSIT))->getUuid());
        self::assertMatchesRegularExpression(
            '/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i',
            (new Transaction(null, 100, Transaction::TYPE_DEPOSIT))->getUuid(),
        );
    }
}
