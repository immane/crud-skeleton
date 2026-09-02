<?php

declare(strict_types=1);

namespace App\Tests\Integration\Trade;

use App\Tests\Integration\DatabaseBootstrapTrait;
use App\Tests\Integration\IntegrationWebTestCase;
use App\Trade\DTO\StoreContext;
use App\Store\Entity\Product;
use App\Store\Entity\Specification;
use App\Trade\Repository\TradeOutboxMessageRepository;
use App\Trade\Service\OrderServiceInterface;
use Doctrine\ORM\EntityManagerInterface;

final class TradeStoreOrderEventTest extends IntegrationWebTestCase
{
    use DatabaseBootstrapTrait;

    protected function setUp(): void
    {
        $this->bootTestDatabase();
        self::ensureKernelShutdown();
        $client = static::createClient();
        $em = $client->getContainer()->get(EntityManagerInterface::class);
        $em->createQuery('DELETE FROM App\\Trade\\Entity\\TradeOutboxMessage message')->execute();
        $em->createQuery('DELETE FROM App\\Trade\\Entity\\OrderItem item')->execute();
        $em->createQuery('DELETE FROM App\\Trade\\Entity\\Order tradeOrder')->execute();
        self::ensureKernelShutdown();
    }

    public function testDuplicateSpecificationItemsUseDistinctOrderLineIds(): void
    {
        $client = static::createClient();
        $container = $client->getContainer();
        $em = $container->get(EntityManagerInterface::class);
        $product = (new Product())->setName('Duplicate line product');
        $specification = (new Specification())->setProduct($product)->setName('Duplicate line specification')->setPrice(100);
        $em->persist($product);
        $em->persist($specification);
        $em->flush();

        $store = new \App\Store\Entity\Store('duplicate-lines', 'Duplicate Lines');
        $store->setSettings(['order' => ['requireAcceptance' => true]]);
        $ref = new \ReflectionProperty(\App\Store\Entity\Store::class, 'uuid');
        $ref->setValue($store, '00000000-0000-4000-8000-000000000050');
        $em->persist($store);
        $em->flush();

        $container->get(OrderServiceInterface::class)->createOrder([
            ['specification' => $specification, 'quantity' => 1, 'unitPrice' => 100, 'price' => 100, 'specSnapshot' => [], 'productSnapshot' => []],
            ['specification' => $specification, 'quantity' => 2, 'unitPrice' => 100, 'price' => 200, 'specSnapshot' => [], 'productSnapshot' => []],
        ], null, 300, 'CNY', null, [], new StoreContext('00000000-0000-4000-8000-000000000050', 'duplicate-lines', 'Duplicate Lines'));

        $outbox = $container->get(TradeOutboxMessageRepository::class)->findUnpublished();
        self::assertCount(1, $outbox);
        $items = $outbox[0]->getPayload()['items'];
        self::assertSame($specification->getUuid(), $items[0]['catalogReference']);
        self::assertSame($specification->getUuid(), $items[1]['catalogReference']);
        self::assertNotSame($items[0]['lineId'], $items[1]['lineId']);
    }
}
