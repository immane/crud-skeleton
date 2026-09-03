<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Inventory\Command\PublishOutboxCommand as InventoryPublishOutboxCommand;
use App\Inventory\Message\ReservationConfirmedMessage;
use App\Inventory\Message\ReservationRejectedMessage;
use App\Inventory\Message\ReservationReleaseRequestedMessage;
use App\Inventory\Message\ReservationReleasedMessage;
use App\Inventory\Message\ReservationRequestedMessage;
use App\Inventory\MessageHandler\ReservationReleaseRequestedHandler;
use App\Inventory\MessageHandler\ReservationRequestedHandler;
use App\Inventory\Repository\InventoryOutboxMessageRepository;
use App\Store\Entity\Store;
use App\Store\MessageHandler\ReservationConfirmedHandler;
use App\Store\MessageHandler\ReservationRejectedHandler;
use App\Store\MessageHandler\ReservationReleasedHandler;
use App\Store\MessageHandler\TradeOrderCancelledHandler;
use App\Store\MessageHandler\TradeOrderCreatedHandler;
use App\Store\Repository\StoreOutboxMessageRepository;
use App\Store\Service\StoreServiceInterface;
use App\Store\Command\PublishOutboxCommand as StorePublishOutboxCommand;
use App\Trade\Command\PublishOutboxCommand as TradePublishOutboxCommand;
use App\Store\Entity\Product;
use App\Store\Entity\Specification;
use App\Trade\Message\StoreOrderAcceptedMessage;
use App\Trade\Message\StoreOrderRejectedMessage;
use App\Trade\Message\TradeOrderCancelledMessage;
use App\Trade\Message\TradeOrderCreatedMessage;
use App\Trade\MessageHandler\StoreOrderAcceptedHandler;
use App\Trade\MessageHandler\StoreOrderRejectedHandler;
use App\Trade\Repository\TradeOutboxMessageRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\Messenger\Handler\HandlersLocator;
use Symfony\Component\Messenger\MessageBus;
use Symfony\Component\Messenger\Middleware\HandleMessageMiddleware;

/**
 * Shared base for the Store <-> Trade <-> Inventory end-to-end flow tests.
 *
 * The flows are driven through the real HTTP API (X-Store-Code header) plus the
 * real outbox publish commands (app:trade:outbox:publish / app:store:outbox:publish /
 * app:inventory:outbox:publish) executed against the real SQLite DB with a
 * synchronous MessageBus wired to the real message handlers.
 *
 * Nothing under src/ is modified; the suite only adds tests.
 */
abstract class StoreTradeFlowTestCase extends IntegrationWebTestCase
{
    use DatabaseBootstrapTrait;

    protected function setUp(): void
    {
        $this->bootTestDatabase();
        self::ensureKernelShutdown();
        $client = static::createClient();
        $em = $client->getContainer()->get(EntityManagerInterface::class);
        foreach ([
            'App\\Store\\Entity\\StoreOutboxMessage',
            'App\\Store\\Entity\\StoreConsumedEvent',
            'App\\Store\\Entity\\StoreTradeOrderCancellation',
            'App\\Store\\Entity\\Membership',
            'App\\Store\\Entity\\StoreOrder',
            'App\\Store\\Entity\\Store',
            'App\\Trade\\Entity\\TradeOutboxMessage',
            'App\\Trade\\Entity\\OrderItem',
            'App\\Trade\\Entity\\Order',
            'App\\Inventory\\Entity\\InventoryOutboxMessage',
            'App\\Inventory\\Entity\\InventoryConsumedEvent',
            'App\\Inventory\\Entity\\LedgerEntry',
            'App\\Inventory\\Entity\\ReservationLine',
            'App\\Inventory\\Entity\\Reservation',
            'App\\Inventory\\Entity\\RecipeLine',
            'App\\Inventory\\Entity\\SpecificationRecipe',
            'App\\Inventory\\Entity\\Stock',
            'App\\Inventory\\Entity\\Material',
        ] as $entity) {
            $em->createQuery('DELETE FROM ' . $entity . ' entity')->execute();
        }
        self::ensureKernelShutdown();
    }

    /**
     * Builds a synchronous Messenger bus that routes every Store/Trade/Inventory
     * integration message to the real registered handler. This lets the real
     * outbox publish commands relay end-to-end inside a single test process.
     */
    protected function syncBus(ContainerInterface $container): MessageBus
    {
        return new MessageBus([
            new HandleMessageMiddleware(new HandlersLocator([
                TradeOrderCreatedMessage::class => [static fn (TradeOrderCreatedMessage $message) => $container->get(TradeOrderCreatedHandler::class)($message)],
                TradeOrderCancelledMessage::class => [static fn (TradeOrderCancelledMessage $message) => $container->get(TradeOrderCancelledHandler::class)($message)],
                StoreOrderAcceptedMessage::class => [static fn (StoreOrderAcceptedMessage $message) => $container->get(StoreOrderAcceptedHandler::class)($message)],
                StoreOrderRejectedMessage::class => [static fn (StoreOrderRejectedMessage $message) => $container->get(StoreOrderRejectedHandler::class)($message)],
                ReservationRequestedMessage::class => [static fn (ReservationRequestedMessage $message) => $container->get(ReservationRequestedHandler::class)($message)],
                ReservationReleaseRequestedMessage::class => [static fn (ReservationReleaseRequestedMessage $message) => $container->get(ReservationReleaseRequestedHandler::class)($message)],
                ReservationConfirmedMessage::class => [static fn (ReservationConfirmedMessage $message) => $container->get(ReservationConfirmedHandler::class)($message)],
                ReservationRejectedMessage::class => [static fn (ReservationRejectedMessage $message) => $container->get(ReservationRejectedHandler::class)($message)],
                ReservationReleasedMessage::class => [static fn (ReservationReleasedMessage $message) => $container->get(ReservationReleasedHandler::class)($message)],
            ])),
        ]);
    }

    protected function tradePublish(ContainerInterface $container): CommandTester
    {
        $command = new TradePublishOutboxCommand(
            $container->get(TradeOutboxMessageRepository::class),
            $container->get(EntityManagerInterface::class),
            $this->syncBus($container),
        );
        $tester = new CommandTester($command);
        $tester->execute([]);

        return $tester;
    }

    protected function storePublish(ContainerInterface $container): CommandTester
    {
        $command = new StorePublishOutboxCommand(
            $container->get(StoreOutboxMessageRepository::class),
            $container->get(EntityManagerInterface::class),
            $this->syncBus($container),
        );
        $tester = new CommandTester($command);
        $tester->execute([]);

        return $tester;
    }

    protected function inventoryPublish(ContainerInterface $container): CommandTester
    {
        $command = new InventoryPublishOutboxCommand(
            $container->get(InventoryOutboxMessageRepository::class),
            $this->syncBus($container),
        );
        $tester = new CommandTester($command);
        $tester->execute([]);

        return $tester;
    }

    protected function createStore(ContainerInterface $container, string $code): Store
    {
        $store = $container->get(StoreServiceInterface::class)->createStore($code, ucfirst($code) . ' Store', 'UTC');
        $store->setSettings(['order' => ['requireAcceptance' => true]]);
        $container->get(EntityManagerInterface::class)->flush();

        return $store;
    }

    /** @return array{0: Product, 1: Specification} */
    protected function createProduct(EntityManagerInterface $em, string $name): array
    {
        $product = new Product();
        $product->setName($name);
        $specification = new Specification();
        $specification->setName('Default')->setPrice(6400);
        $product->addSpecification($specification);
        $em->persist($product);
        $em->flush();

        return [$product, $specification];
    }

    /**
     * Places a store-scoped order through the real HTTP API and returns the
     * Trade order identity.
     *
     * @return array{uuid: string, id: int, status: string}
     */
    protected function placeStoreOrder(KernelBrowser $client, string $storeCode, int $specificationId): array
    {
        $client->setServerParameter('HTTP_X_STORE_CODE', $storeCode);
        $client->jsonRequest('POST', '/api/v1/app/orders', [
            'currency' => 'CNY',
            'items' => [['specificationId' => $specificationId, 'quantity' => 2]],
        ]);
        self::assertResponseStatusCodeSame(201);
        $data = json_decode((string) $client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR)['data'];

        return ['uuid' => $data['uuid'], 'id' => (int) $data['id'], 'status' => 'awaiting_store_acceptance'];
    }
}
