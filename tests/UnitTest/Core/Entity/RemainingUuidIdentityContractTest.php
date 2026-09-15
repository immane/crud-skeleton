<?php

declare(strict_types=1);

namespace App\Tests\UnitTest\Core\Entity;

use App\Authorization\Entity\AuditLog;
use App\Authorization\Entity\Role;
use App\Authorization\Entity\RoleFieldGrant;
use App\Identity\Entity\RefreshToken;
use App\Identity\Entity\User;
use App\Inventory\Entity\InventoryConsumedEvent;
use App\Inventory\Entity\InventoryOutboxMessage;
use App\Inventory\Entity\Material;
use App\Inventory\Entity\RecipeLine;
use App\Inventory\Entity\ReservationLine;
use App\Inventory\Entity\Stock;
use App\Settlement\Entity\SettlementConsumedEvent;
use App\Settlement\Entity\SettlementOutboxMessage;
use App\Store\Entity\Membership;
use App\Store\Entity\Store;
use App\Store\Entity\StoreConsumedEvent;
use App\Store\Entity\StoreOutboxMessage;
use App\Store\Entity\StoreTradeOrderCancellation;
use App\Trade\Entity\OrderStoreLifecycle;
use App\Trade\Entity\TradeConsumedEvent;
use App\Trade\Entity\TradeOutboxMessage;
use App\Wallet\Entity\Voucher;
use App\Wallet\Entity\VoucherComment;
use App\Wallet\Entity\Wallet;
use PHPUnit\Framework\TestCase;

final class RemainingUuidIdentityContractTest extends TestCase
{
    public function testRemainingEntitiesHaveAnImmediatelyAvailableValidUuid(): void
    {
        $user = new User();
        $user->setEmail('uuid@example.com');
        $user->setUsername('uuid-user');
        $user->setPassword('hash');
        $store = new Store('uuid-store', 'UUID Store');
        $material = new Material('uuid-material', 'UUID Material', Material::KIND_RAW, 'unit');
        $wallet = new Wallet($user);
        $voucher = new Voucher($wallet, Voucher::DIRECTION_CREDIT, Voucher::FUND_SOURCE_EXTERNAL, Voucher::VOUCHER_TYPE_MANUAL, 'voucher-id', 100, 'USD', 'reference-id', 'admin');
        $role = new Role('uuid-role', 'UUID Role', Role::SCOPE_GLOBAL);
        $eventId = 'event-id';
        $hash = str_repeat('a', 64);

        $entities = [
            new RefreshToken($user, 'hash', new \DateTimeImmutable('+1 day')),
            new AuditLog('create', 'resource'),
            new RoleFieldGrant($role, 'resource', 'read', ['field']),
            new Membership($store, $user->getUuid(), Membership::ROLE_OWNER),
            new StoreTradeOrderCancellation('trade-order-id', $store->getUuid(), new \DateTimeImmutable()),
            new StoreConsumedEvent($eventId, 'topic', 'aggregate-id', $hash),
            new StoreOutboxMessage('topic', 'aggregate', 'aggregate-id', []),
            new OrderStoreLifecycle('trade-order-id', $store->getUuid()),
            new TradeConsumedEvent($eventId, 'topic', 'aggregate-id', $hash),
            new TradeOutboxMessage('topic', 'aggregate', 'aggregate-id', []),
            new Stock($store->getUuid(), $material),
            new RecipeLine($material, '1'),
            new ReservationLine($material, '1', []),
            new InventoryConsumedEvent($eventId, 'topic', 'aggregate-id', $hash),
            new InventoryOutboxMessage('topic', 'aggregate', 'aggregate-id', []),
            new SettlementConsumedEvent($eventId, 'topic', 'aggregate', 'aggregate-id', $hash),
            new SettlementOutboxMessage('topic', 'aggregate', 'aggregate-id', []),
            new VoucherComment($voucher, 'admin', 'comment'),
        ];

        foreach ($entities as $entity) {
            self::assertMatchesRegularExpression('/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i', $entity->getUuid());
        }
    }
}
