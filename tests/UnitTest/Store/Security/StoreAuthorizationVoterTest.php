<?php

declare(strict_types=1);

namespace App\Tests\UnitTest\Store\Security;

use App\Authorization\Service\AuthorizationScope;
use App\Authorization\Service\AuthorizationServiceInterface;
use App\Identity\Entity\User;
use App\Store\Entity\Store;
use App\Store\Security\StoreAuthorizationVoter;
use App\Store\Service\MembershipServiceInterface;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\VoterInterface;

#[AllowMockObjectsWithoutExpectations]
final class StoreAuthorizationVoterTest extends TestCase
{
    public function testGrantsOnlyForAnActiveMemberWithScopedPermission(): void
    {
        $store = new Store('demo', 'Demo');
        $user = new User();
        $membership = $this->createMock(MembershipServiceInterface::class);
        $membership->expects(self::once())->method('isAuthorized')->with($store, $user->getUuid())->willReturn(true);
        $authorization = $this->createMock(AuthorizationServiceInterface::class);
        $authorization->expects(self::once())
            ->method('can')
            ->with($user, 'store:order:read', self::equalTo(AuthorizationScope::store($store->getUuid())))
            ->willReturn(true);

        $result = (new StoreAuthorizationVoter($membership, $authorization))
            ->vote($this->token($user), $store, ['store:order:read']);

        self::assertSame(VoterInterface::ACCESS_GRANTED, $result);
    }

    public function testDeniesPermissionWhenMembershipIsInactive(): void
    {
        $store = new Store('demo', 'Demo');
        $user = new User();
        $membership = $this->createMock(MembershipServiceInterface::class);
        $membership->method('isAuthorized')->willReturn(false);
        $authorization = $this->createMock(AuthorizationServiceInterface::class);
        $authorization->expects(self::never())->method('can');

        $result = (new StoreAuthorizationVoter($membership, $authorization))
            ->vote($this->token($user), $store, ['store:order:read']);

        self::assertSame(VoterInterface::ACCESS_DENIED, $result);
    }

    private function token(User $user): TokenInterface
    {
        $token = $this->createMock(TokenInterface::class);
        $token->method('getUser')->willReturn($user);

        return $token;
    }
}
