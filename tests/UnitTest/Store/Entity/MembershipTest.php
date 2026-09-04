<?php

declare(strict_types=1);

namespace App\Tests\UnitTest\Store\Entity;

use App\Store\Entity\Store;
use App\Store\Entity\Membership;
use PHPUnit\Framework\TestCase;

final class MembershipTest extends TestCase
{
    public function testRoleAndStatusLifecycle(): void
    {
        $membership = new Membership(new Store('demo', 'Demo', 'Asia/Shanghai'), '47d07ad3-7e6e-4bfb-aea3-87bdb0e4de57', Membership::ROLE_MANAGER);

        self::assertTrue($membership->isActive());
        self::assertSame(Membership::ROLE_MANAGER, $membership->getRole());

        $membership->suspend()->revoke();
        self::assertSame(Membership::STATUS_REVOKED, $membership->getStatus());
        self::assertInstanceOf(\DateTimeImmutable::class, $membership->getUpdatedAt());
    }

    public function testInvalidRoleIsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new Membership(new Store('demo', 'Demo', 'Asia/Shanghai'), '47d07ad3-7e6e-4bfb-aea3-87bdb0e4de57', 'administrator');
    }
}
