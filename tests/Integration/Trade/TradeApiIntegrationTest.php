<?php

declare(strict_types=1);

namespace App\Tests\Integration\Trade;

use App\Tests\Integration\DatabaseBootstrapTrait;
use App\Trade\Entity\Order;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\KernelInterface;

final class TradeApiIntegrationTest extends WebTestCase
{
    use DatabaseBootstrapTrait;

    private KernelBrowser $client;

    protected static function getKernelClass(): string
    {
        return \App\Kernel::class;
    }

    protected static function createKernel(array $options = []): KernelInterface
    {
        $class = static::getKernelClass();
        $env = $options['environment'] ?? $_ENV['APP_ENV'] ?? $_SERVER['APP_ENV'] ?? 'test';
        $debug = $options['debug'] ?? $_ENV['APP_DEBUG'] ?? $_SERVER['APP_DEBUG'] ?? true;
        return new $class($env, (bool) $debug);
    }

    protected function setUp(): void
    {
        $this->bootTestDatabase();
        self::ensureKernelShutdown();
        $this->client = static::createClient();
    }

    private function createAuthenticatedUser(): void
    {
        $em = $this->client->getContainer()
            ->get('doctrine')->getManager();

        $existing = $em->getRepository(\App\Identity\Entity\User::class)->findOneBy(['email' => 'trade@test.com']);
        if ($existing !== null) {
            return;
        }

        try {
            $em->getConnection()->executeStatement(
                "INSERT INTO users (id, uuid, email, username, password, roles, created_at, updated_at) VALUES (1, '11111111-1111-4111-8111-111111111111', 'trade@test.com', 'tradeuser', '\$2y\$13\$TestHashValue1234567890abcdefg', '[\"ROLE_ADMIN\"]', CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)"
            );
        } catch (\Throwable) {
            // User may already exist
        }
    }

    private function authHeader(): array
    {
        $this->createAuthenticatedUser();
        $tokenManager = $this->client->getContainer()->get(\App\Identity\Security\TokenManager::class);
        $userRepo = $this->client->getContainer()->get('doctrine')->getManager()
            ->getRepository(\App\Identity\Entity\User::class);
        $user = $userRepo->findOneBy(['email' => 'trade@test.com']);

        $accessToken = $tokenManager->createAccessToken($user);
        return ['HTTP_AUTHORIZATION' => 'Bearer ' . $accessToken];
    }

    private function jsonRequest(string $method, string $uri, array $data = []): array
    {
        $this->client->request(
            $method,
            $uri,
            [],
            [],
            $this->authHeader(),
            json_encode($data, JSON_THROW_ON_ERROR)
        );

        $response = $this->client->getResponse();
        $content = json_decode($response->getContent(), true) ?? [];

        return [$response, $content];
    }

    private function assertSuccess(array $content, int $expectedCode = 0): void
    {
        self::assertArrayHasKey('code', $content);
        self::assertSame($expectedCode, $content['code'], $content['message'] ?? 'Unknown error');
    }

    private function createProduct(string $name = 'Test Product'): int
    {
        [, $content] = $this->jsonRequest('POST', '/api/v1/manage/products', [
            'name' => $name,
            'status' => 'active',
        ]);
        $this->assertSuccess($content);
        self::assertArrayHasKey('data', $content);
        self::assertIsArray($content['data']);
        self::assertArrayHasKey('id', $content['data']);
        return (int) $content['data']['id'];
    }

    private function createSpecification(int $productId, string $name, int $price): int
    {
        [, $content] = $this->jsonRequest('POST', "/api/v1/manage/products/{$productId}/specifications", [
            'name' => $name,
            'price' => $price,
            'status' => 'active',
        ]);
        $this->assertSuccess($content);
        self::assertIsArray($content['data']);
        self::assertArrayHasKey('id', $content['data']);
        return (int) $content['data']['id'];
    }

    // ===== Product CRUD Tests =====

    public function testProductCreate(): void
    {
        [$response, $content] = $this->jsonRequest('POST', '/api/v1/manage/products', [
            'name' => 'iPhone 15',
            'description' => 'Latest model',
            'status' => 'active',
        ]);

        self::assertSame(Response::HTTP_CREATED, $response->getStatusCode());
        self::assertSame('SUCCESS', $content['message']);
        self::assertSame('iPhone 15', $content['data']['name']);
        self::assertSame('active', $content['data']['status']);
        self::assertArrayHasKey('uuid', $content['data']);
    }

    public function testProductList(): void
    {
        $this->createProduct('Product A');
        $this->createProduct('Product B');

        [$response, $content] = $this->jsonRequest('GET', '/api/v1/manage/products');

        self::assertSame(Response::HTTP_OK, $response->getStatusCode());
        $this->assertSuccess($content);
        self::assertIsArray($content['data']);
        self::assertGreaterThanOrEqual(2, count($content['data']));
    }

    public function testProductDetail(): void
    {
        $id = $this->createProduct('Detail Product');
        [$response, $content] = $this->jsonRequest('GET', "/api/v1/manage/products/{$id}");

        self::assertSame(Response::HTTP_OK, $response->getStatusCode());
        self::assertSame('Detail Product', $content['data']['name']);
    }

    public function testProductUpdate(): void
    {
        $id = $this->createProduct('Old Name');
        [$response, $content] = $this->jsonRequest('PUT', "/api/v1/manage/products/{$id}", [
            'name' => 'New Name',
            'status' => 'inactive',
        ]);

        self::assertSame(Response::HTTP_OK, $response->getStatusCode());
        $this->assertSuccess($content);
        self::assertSame('New Name', $content['data']['name']);
        self::assertSame('inactive', $content['data']['status']);
    }

    public function testProductDelete(): void
    {
        $id = $this->createProduct('ToDelete');
        [$response] = $this->jsonRequest('DELETE', "/api/v1/manage/products/{$id}");

        self::assertSame(Response::HTTP_NO_CONTENT, $response->getStatusCode());
    }

    public function testProductNotFound(): void
    {
        [$response] = $this->jsonRequest('GET', '/api/v1/manage/products/999999');

        self::assertSame(Response::HTTP_NOT_FOUND, $response->getStatusCode());
    }

    // ===== Specification CRUD Tests =====

    public function testSpecificationCreate(): void
    {
        $productId = $this->createProduct('Phone');
        [$response, $content] = $this->jsonRequest(
            'POST',
            "/api/v1/manage/products/{$productId}/specifications",
            ['name' => '128GB', 'price' => 10000, 'status' => 'active']
        );

        self::assertSame(Response::HTTP_CREATED, $response->getStatusCode());
        self::assertSame('128GB', $content['data']['name']);
        self::assertSame(10000, $content['data']['price']);
    }

    public function testSpecificationList(): void
    {
        $productId = $this->createProduct('ProductX');
        $this->createSpecification($productId, 'Red', 1000);
        $this->createSpecification($productId, 'Blue', 1100);

        [$response, $content] = $this->jsonRequest(
            'GET',
            "/api/v1/manage/products/{$productId}/specifications"
        );

        self::assertSame(Response::HTTP_OK, $response->getStatusCode());
        $this->assertSuccess($content);
        self::assertGreaterThanOrEqual(2, count($content['data']));
    }

    public function testSpecificationUpdate(): void
    {
        $productId = $this->createProduct('ProductY');
        $specId = $this->createSpecification($productId, 'Old', 500);

        [$response, $content] = $this->jsonRequest(
            'PUT',
            "/api/v1/manage/products/{$productId}/specifications/{$specId}",
            ['name' => 'New', 'price' => 600]
        );

        self::assertSame(Response::HTTP_OK, $response->getStatusCode());
        $this->assertSuccess($content);
        self::assertSame('New', $content['data']['name']);
        self::assertSame(600, $content['data']['price']);
    }

    public function testSpecificationDelete(): void
    {
        $productId = $this->createProduct('ProductZ');
        $specId = $this->createSpecification($productId, 'ToDelete', 100);

        [$response] = $this->jsonRequest(
            'DELETE',
            "/api/v1/manage/products/{$productId}/specifications/{$specId}"
        );

        self::assertSame(Response::HTTP_NO_CONTENT, $response->getStatusCode());
    }

    // ===== Order CRUD Tests =====

    public function testOrderCreate(): void
    {
        $productId = $this->createProduct('OrderTest');
        $specId = $this->createSpecification($productId, 'Option1', 5000);

        [$response, $content] = $this->jsonRequest('POST', '/api/v1/manage/orders', [
            'items' => [
                ['specificationId' => $specId, 'quantity' => 2],
            ],
            'currency' => 'CNY',
            'notes' => 'Test order',
        ]);

        self::assertSame(Response::HTTP_CREATED, $response->getStatusCode());
        $this->assertSuccess($content);

        $order = $content['data'];
        self::assertSame(10000, $order['totalAmount']);
        self::assertSame('CNY', $order['currency']);
        self::assertSame('draft', $order['status']);
        self::assertStringContainsString('Test order', $order['notes'] ?? '');
    }

    public function testOrderList(): void
    {
        $productId = $this->createProduct('ListOrder');
        $specId = $this->createSpecification($productId, 'Spec', 1000);
        $this->jsonRequest('POST', '/api/v1/manage/orders', [
            'items' => [['specificationId' => $specId, 'quantity' => 1]],
        ]);

        [$response, $content] = $this->jsonRequest('GET', '/api/v1/manage/orders');

        self::assertSame(Response::HTTP_OK, $response->getStatusCode());
        $this->assertSuccess($content);
        self::assertGreaterThanOrEqual(1, count($content['data']));
    }

    public function testOrderDetail(): void
    {
        $productId = $this->createProduct('DetailOrder');
        $specId = $this->createSpecification($productId, 'DetailSpec', 2000);

        [, $createContent] = $this->jsonRequest('POST', '/api/v1/manage/orders', [
            'items' => [['specificationId' => $specId, 'quantity' => 3]],
        ]);
        $orderId = $createContent['data']['id'];

        [$response, $content] = $this->jsonRequest('GET', "/api/v1/manage/orders/{$orderId}");

        self::assertSame(Response::HTTP_OK, $response->getStatusCode());
        self::assertSame(6000, $content['data']['totalAmount']);
    }

    public function testDraftOrderCanBeUpdated(): void
    {
        $productId = $this->createProduct('DraftUpdate');
        $specId = $this->createSpecification($productId, 'Spec', 1000);

        [, $createContent] = $this->jsonRequest('POST', '/api/v1/manage/orders', [
            'items' => [['specificationId' => $specId, 'quantity' => 1]],
        ]);
        $orderId = $createContent['data']['id'];

        [$response, $content] = $this->jsonRequest('PUT', "/api/v1/manage/orders/{$orderId}", [
            'notes' => 'Updated notes',
        ]);

        self::assertSame(Response::HTTP_OK, $response->getStatusCode());
        $this->assertSuccess($content);
    }

    public function testDraftOrderCanBeDeleted(): void
    {
        $productId = $this->createProduct('DraftDelete');
        $specId = $this->createSpecification($productId, 'Spec', 1000);

        [, $createContent] = $this->jsonRequest('POST', '/api/v1/manage/orders', [
            'items' => [['specificationId' => $specId, 'quantity' => 1]],
        ]);
        $orderId = $createContent['data']['id'];

        [$response] = $this->jsonRequest('DELETE', "/api/v1/manage/orders/{$orderId}");

        self::assertSame(Response::HTTP_NO_CONTENT, $response->getStatusCode());
    }

    public function testNonDraftOrderCannotBeUpdated(): void
    {
        $productId = $this->createProduct('CannotUpdate');
        $specId = $this->createSpecification($productId, 'Spec', 1000);

        [, $createContent] = $this->jsonRequest('POST', '/api/v1/manage/orders', [
            'items' => [['specificationId' => $specId, 'quantity' => 1]],
        ]);
        $orderId = $createContent['data']['id'];

        [$submitResponse, $submitContent] = $this->jsonRequest('POST', "/api/v1/manage/orders/{$orderId}/do/submit");
        $this->assertSuccess($submitContent);

        [$response, $content] = $this->jsonRequest('PUT', "/api/v1/manage/orders/{$orderId}", [
            'notes' => 'Should fail',
        ]);

        self::assertSame(Response::HTTP_BAD_REQUEST, $response->getStatusCode());
    }

    public function testNonDraftOrderCannotBeDeleted(): void
    {
        $productId = $this->createProduct('CannotDelete');
        $specId = $this->createSpecification($productId, 'Spec', 1000);

        [, $createContent] = $this->jsonRequest('POST', '/api/v1/manage/orders', [
            'items' => [['specificationId' => $specId, 'quantity' => 1]],
        ]);
        $orderId = $createContent['data']['id'];

        [$submitResponse, $submitContent] = $this->jsonRequest('POST', "/api/v1/manage/orders/{$orderId}/do/submit");
        $this->assertSuccess($submitContent);

        [$response, $content] = $this->jsonRequest('DELETE', "/api/v1/manage/orders/{$orderId}");

        self::assertSame(Response::HTTP_BAD_REQUEST, $response->getStatusCode());
    }

    // ===== Workflow Tests =====

    public function testOrderWorkflowFullFlow(): void
    {
        $productId = $this->createProduct('WFProduct');
        $specId = $this->createSpecification($productId, 'Spec', 5000);

        [, $createContent] = $this->jsonRequest('POST', '/api/v1/manage/orders', [
            'items' => [['specificationId' => $specId, 'quantity' => 1]],
        ]);
        $orderId = $createContent['data']['id'];

        $transitions = ['submit', 'confirm', 'pay', 'fulfill', 'complete'];
        $expectedStatuses = ['pending', 'confirmed', 'paid', 'fulfilled', 'completed'];

        foreach ($transitions as $i => $transition) {
            [$response, $content] = $this->jsonRequest(
                'POST',
                "/api/v1/manage/orders/{$orderId}/do/{$transition}"
            );

            self::assertSame(Response::HTTP_OK, $response->getStatusCode());
            $this->assertSuccess($content);
        }

        [$response, $content] = $this->jsonRequest('GET', "/api/v1/manage/orders/{$orderId}");
        self::assertSame('completed', $content['data']['status']);
    }

    public function testOrderCancelFromDraft(): void
    {
        $productId = $this->createProduct('CancelDraft');
        $specId = $this->createSpecification($productId, 'Spec', 1000);

        [, $createContent] = $this->jsonRequest('POST', '/api/v1/manage/orders', [
            'items' => [['specificationId' => $specId, 'quantity' => 1]],
        ]);
        $orderId = $createContent['data']['id'];

        [$response, $content] = $this->jsonRequest(
            'POST',
            "/api/v1/manage/orders/{$orderId}/do/cancel"
        );

        self::assertSame(Response::HTTP_OK, $response->getStatusCode());
        $this->assertSuccess($content);

        [, $detail] = $this->jsonRequest('GET', "/api/v1/manage/orders/{$orderId}");
        self::assertSame('cancelled', $detail['data']['status']);
    }

    public function testOrderCancelFromPending(): void
    {
        $productId = $this->createProduct('CancelPending');
        $specId = $this->createSpecification($productId, 'Spec', 1000);

        [, $createContent] = $this->jsonRequest('POST', '/api/v1/manage/orders', [
            'items' => [['specificationId' => $specId, 'quantity' => 1]],
        ]);
        $orderId = $createContent['data']['id'];

        $this->jsonRequest('POST', "/api/v1/manage/orders/{$orderId}/do/submit");

        [$response] = $this->jsonRequest('POST', "/api/v1/manage/orders/{$orderId}/do/cancel");
        self::assertSame(Response::HTTP_OK, $response->getStatusCode());

        [, $detail] = $this->jsonRequest('GET', "/api/v1/manage/orders/{$orderId}");
        self::assertSame('cancelled', $detail['data']['status']);
    }

    public function testOrderCancelFromConfirmed(): void
    {
        $productId = $this->createProduct('CancelConfirmed');
        $specId = $this->createSpecification($productId, 'Spec', 1000);

        [, $createContent] = $this->jsonRequest('POST', '/api/v1/manage/orders', [
            'items' => [['specificationId' => $specId, 'quantity' => 1]],
        ]);
        $orderId = $createContent['data']['id'];

        $this->jsonRequest('POST', "/api/v1/manage/orders/{$orderId}/do/submit");
        $this->jsonRequest('POST', "/api/v1/manage/orders/{$orderId}/do/confirm");

        [$response] = $this->jsonRequest('POST', "/api/v1/manage/orders/{$orderId}/do/cancel");
        self::assertSame(Response::HTTP_OK, $response->getStatusCode());

        [, $detail] = $this->jsonRequest('GET', "/api/v1/manage/orders/{$orderId}");
        self::assertSame('cancelled', $detail['data']['status']);
    }

    public function testOrderCannotCancelAfterPaid(): void
    {
        $productId = $this->createProduct('CannotCancelPaid');
        $specId = $this->createSpecification($productId, 'Spec', 1000);

        [, $createContent] = $this->jsonRequest('POST', '/api/v1/manage/orders', [
            'items' => [['specificationId' => $specId, 'quantity' => 1]],
        ]);
        $orderId = $createContent['data']['id'];

        $this->jsonRequest('POST', "/api/v1/manage/orders/{$orderId}/do/submit");
        $this->jsonRequest('POST', "/api/v1/manage/orders/{$orderId}/do/confirm");
        $this->jsonRequest('POST', "/api/v1/manage/orders/{$orderId}/do/pay");

        [$response] = $this->jsonRequest('POST', "/api/v1/manage/orders/{$orderId}/do/cancel");

        self::assertSame(Response::HTTP_BAD_REQUEST, $response->getStatusCode());
        $respContent = json_decode($response->getContent(), true);
        self::assertSame(400, $respContent['code'] ?? 0);
    }

    public function testOrderRefundFromCompleted(): void
    {
        $productId = $this->createProduct('RefundTest');
        $specId = $this->createSpecification($productId, 'Spec', 1000);

        [, $createContent] = $this->jsonRequest('POST', '/api/v1/manage/orders', [
            'items' => [['specificationId' => $specId, 'quantity' => 1]],
        ]);
        $orderId = $createContent['data']['id'];

        foreach (['submit', 'confirm', 'pay'] as $transition) {
            $this->jsonRequest('POST', "/api/v1/manage/orders/{$orderId}/do/{$transition}");
        }

        [$response] = $this->jsonRequest('POST', "/api/v1/manage/orders/{$orderId}/do/refund");
        self::assertSame(Response::HTTP_OK, $response->getStatusCode());

        [, $detail] = $this->jsonRequest('GET', "/api/v1/manage/orders/{$orderId}");
        self::assertSame('refunded', $detail['data']['status']);
    }

    public function testOrderCannotRefundFromDraft(): void
    {
        $productId = $this->createProduct('CannotRefundDraft');
        $specId = $this->createSpecification($productId, 'Spec', 1000);

        [, $createContent] = $this->jsonRequest('POST', '/api/v1/manage/orders', [
            'items' => [['specificationId' => $specId, 'quantity' => 1]],
        ]);
        $orderId = $createContent['data']['id'];

        [$response] = $this->jsonRequest('POST', "/api/v1/manage/orders/{$orderId}/do/refund");

        self::assertSame(Response::HTTP_BAD_REQUEST, $response->getStatusCode());
        $respContent = json_decode($response->getContent(), true);
        self::assertSame(400, $respContent['code'] ?? 0);
    }

    public function testOrderTransitionsEndpoint(): void
    {
        $productId = $this->createProduct('TransitionsTest');
        $specId = $this->createSpecification($productId, 'Spec', 1000);

        [, $createContent] = $this->jsonRequest('POST', '/api/v1/manage/orders', [
            'items' => [['specificationId' => $specId, 'quantity' => 1]],
        ]);
        $orderId = $createContent['data']['id'];

        [$response, $content] = $this->jsonRequest(
            'GET',
            "/api/v1/manage/orders/{$orderId}/transitions"
        );

        self::assertSame(Response::HTTP_OK, $response->getStatusCode());
        $this->assertSuccess($content);

        $transitionNames = array_map(fn($t) => $t['name'] ?? '', $content['data']);
        self::assertContains('submit', $transitionNames);
        self::assertContains('cancel', $transitionNames);
        self::assertNotContains('refund', $transitionNames);
    }

    public function testOrderTodoList(): void
    {
        $productId = $this->createProduct('TodoProduct');
        $specId = $this->createSpecification($productId, 'Spec', 1000);

        $this->jsonRequest('POST', '/api/v1/manage/orders', [
            'items' => [['specificationId' => $specId, 'quantity' => 1]],
        ]);

        [$response, $content] = $this->jsonRequest('GET', '/api/v1/manage/orders/todo');

        self::assertSame(Response::HTTP_OK, $response->getStatusCode());
        $this->assertSuccess($content);
    }

    // ===== App Scope Tests =====

    public function testAppProductList(): void
    {
        $productId = $this->createProduct('AppProduct');
        $this->createSpecification($productId, 'Spec', 1000);

        [$response, $content] = $this->jsonRequest('GET', '/api/v1/app/products');

        self::assertSame(Response::HTTP_OK, $response->getStatusCode());
        $this->assertSuccess($content);
    }

    public function testAppProductDetail(): void
    {
        $productId = $this->createProduct('AppDetail');
        [$response, $content] = $this->jsonRequest('GET', "/api/v1/app/products/{$productId}");

        self::assertSame(Response::HTTP_OK, $response->getStatusCode());
        $this->assertSuccess($content);
    }

    public function testAppOrderCreate(): void
    {
        $productId = $this->createProduct('AppOrderProduct');
        $specId = $this->createSpecification($productId, 'Spec', 2500);

        [$response, $content] = $this->jsonRequest('POST', '/api/v1/app/orders', [
            'items' => [
                ['specificationId' => $specId, 'quantity' => 4],
            ],
            'notes' => 'App order',
            'metadata' => [
                'receiver' => [
                    'name' => 'Zhang San',
                    'phone' => '13800138000',
                    'address' => 'Nanshan, Shenzhen',
                ],
            ],
        ]);

        self::assertSame(Response::HTTP_CREATED, $response->getStatusCode());
        self::assertSame(10000, $content['data']['totalAmount']);
        self::assertSame('Zhang San', $content['data']['metadata']['receiver']['name']);
        self::assertSame('13800138000', $content['data']['metadata']['receiver']['phone']);
        self::assertSame('Nanshan, Shenzhen', $content['data']['metadata']['receiver']['address']);
    }

    public function testAppOrderList(): void
    {
        [$response, $content] = $this->jsonRequest('GET', '/api/v1/app/orders');

        self::assertSame(Response::HTTP_OK, $response->getStatusCode());
        $this->assertSuccess($content);
        self::assertIsArray($content['data']);
    }

    public function testAppOrderCreateWithoutItemsReturnsError(): void
    {
        [$response, $content] = $this->jsonRequest('POST', '/api/v1/app/orders', [
            'items' => [],
        ]);

        self::assertSame(Response::HTTP_BAD_REQUEST, $response->getStatusCode());
    }

    public function testOrderCreateCalculatesTotalAmount(): void
    {
        $productId = $this->createProduct('CalcProduct');
        $spec1Id = $this->createSpecification($productId, 'Spec1', 1000);
        $spec2Id = $this->createSpecification($productId, 'Spec2', 2000);

        [$response, $content] = $this->jsonRequest('POST', '/api/v1/manage/orders', [
            'items' => [
                ['specificationId' => $spec1Id, 'quantity' => 2],
                ['specificationId' => $spec2Id, 'quantity' => 3],
            ],
        ]);

        self::assertSame(Response::HTTP_CREATED, $response->getStatusCode());
        self::assertSame(8000, $content['data']['totalAmount']);
    }

    public function testOrderItemSnapshotsAreCreated(): void
    {
        $productId = $this->createProduct('SnapshotProduct');
        $specId = $this->createSpecification($productId, 'SnapshotSpec', 1500);

        [, $createContent] = $this->jsonRequest('POST', '/api/v1/manage/orders', [
            'items' => [['specificationId' => $specId, 'quantity' => 1]],
        ]);
        $orderId = $createContent['data']['id'];

        [$response, $content] = $this->jsonRequest('GET', "/api/v1/manage/orders/{$orderId}");

        self::assertSame(Response::HTTP_OK, $response->getStatusCode());
        self::assertArrayHasKey('items', $content['data']);
        self::assertNotEmpty($content['data']['items']);
    }

    public function testPaginationOnProductList(): void
    {
        for ($i = 1; $i <= 5; $i++) {
            $this->createProduct("Paginated Product {$i}");
        }

        [$response, $content] = $this->jsonRequest('GET', '/api/v1/manage/products?limit=2&page=1');

        self::assertSame(Response::HTTP_OK, $response->getStatusCode());
        $this->assertSuccess($content);
        self::assertArrayHasKey('paginator', $content);
    }

    public function testAppOrderDetailAfterCreation(): void
    {
        $productId = $this->createProduct('AppOrderDetail');
        $specId = $this->createSpecification($productId, 'Spec', 1000);

        [, $orderContent] = $this->jsonRequest('POST', '/api/v1/app/orders', [
            'items' => [['specificationId' => $specId, 'quantity' => 2]],
        ]);
        $orderId = $orderContent['data']['id'];

        [$response, $content] = $this->jsonRequest('GET', "/api/v1/app/orders/{$orderId}");
        self::assertSame(Response::HTTP_OK, $response->getStatusCode());
        $this->assertSuccess($content);
        self::assertSame(2000, $content['data']['totalAmount']);
    }

    public function testSpecificationCreateWithLowestPrice(): void
    {
        $productId = $this->createProduct('PriceTest');
        [$response, $content] = $this->jsonRequest(
            'POST',
            "/api/v1/manage/products/{$productId}/specifications",
            ['name' => 'Cheap', 'price' => 50, 'status' => 'active']
        );

        self::assertSame(Response::HTTP_CREATED, $response->getStatusCode());
        self::assertSame(50, $content['data']['price']);
    }

    public function testSpecificationCreateWithZeroPrice(): void
    {
        $productId = $this->createProduct('ZeroPrice');
        [$response, $content] = $this->jsonRequest(
            'POST',
            "/api/v1/manage/products/{$productId}/specifications",
            ['name' => 'Free', 'price' => 0, 'status' => 'inactive']
        );

        self::assertSame(Response::HTTP_CREATED, $response->getStatusCode());
        self::assertSame(0, $content['data']['price']);
        self::assertSame('inactive', $content['data']['status']);
    }

    public function testOrderCreateWithMultipleItems(): void
    {
        $productId = $this->createProduct('MultiItem');
        $spec1Id = $this->createSpecification($productId, 'S', 500);
        $spec2Id = $this->createSpecification($productId, 'M', 700);
        $spec3Id = $this->createSpecification($productId, 'L', 900);

        [$response, $content] = $this->jsonRequest('POST', '/api/v1/manage/orders', [
            'items' => [
                ['specificationId' => $spec1Id, 'quantity' => 1],
                ['specificationId' => $spec2Id, 'quantity' => 2],
                ['specificationId' => $spec3Id, 'quantity' => 3],
            ],
        ]);

        self::assertSame(Response::HTTP_CREATED, $response->getStatusCode());
        self::assertSame(4600, $content['data']['totalAmount']);
    }

    public function testAppProductListFiltersInactive(): void
    {
        $activeId = $this->createProduct('ActiveProduct');
        $this->createSpecification($activeId, 'A', 100);

        $em = $this->client->getContainer()->get('doctrine')->getManager();
        $inactive = new \App\Store\Entity\Product();
        $inactive->setName('InactiveProduct');
        $inactive->setStatus('inactive');
        $em->persist($inactive);
        $em->flush();

        [$response, $content] = $this->jsonRequest('GET', '/api/v1/app/products');

        self::assertSame(Response::HTTP_OK, $response->getStatusCode());
        $this->assertSuccess($content);
        $names = array_column($content['data'], 'name');
        self::assertNotContains('InactiveProduct', $names);
    }

    public function testAppProductListFiltersDeleted(): void
    {
        $em = $this->client->getContainer()->get('doctrine')->getManager();
        $deleted = new \App\Store\Entity\Product();
        $deleted->setName('DeletedProduct');
        $deleted->setIsDeleted(true);
        $em->persist($deleted);
        $em->flush();

        [$response, $content] = $this->jsonRequest('GET', '/api/v1/app/products');

        self::assertSame(Response::HTTP_OK, $response->getStatusCode());
        $names = array_column($content['data'], 'name');
        self::assertNotContains('DeletedProduct', $names);
    }

    public function testOrderTransitionSubmitWithData(): void
    {
        $productId = $this->createProduct('SubmitData');
        $specId = $this->createSpecification($productId, 'Spec', 1000);

        [, $createContent] = $this->jsonRequest('POST', '/api/v1/manage/orders', [
            'items' => [['specificationId' => $specId, 'quantity' => 1]],
        ]);
        $orderId = $createContent['data']['id'];

        [$response, $content] = $this->jsonRequest(
            'POST',
            "/api/v1/manage/orders/{$orderId}/do/submit",
            ['notes' => 'submitted with notes']
        );

        self::assertSame(Response::HTTP_OK, $response->getStatusCode());
        $this->assertSuccess($content);
    }

    public function testOrderWorkflowCompleteFullFlow(): void
    {
        $productId = $this->createProduct('WFComplete');
        $specId = $this->createSpecification($productId, 'Spec', 500);

        [, $createContent] = $this->jsonRequest('POST', '/api/v1/manage/orders', [
            'items' => [['specificationId' => $specId, 'quantity' => 1]],
        ]);
        $orderId = $createContent['data']['id'];

        $flow = ['submit', 'confirm', 'pay', 'fulfill', 'complete'];
        foreach ($flow as $transition) {
            $this->jsonRequest('POST', "/api/v1/manage/orders/{$orderId}/do/{$transition}");
        }

        [, $detail] = $this->jsonRequest('GET', "/api/v1/manage/orders/{$orderId}");
        self::assertSame('completed', $detail['data']['status']);
        self::assertNotNull($detail['data']['completedAt'] ?? null);
    }

    public function testOrderWorkflowCancelFromDraftWithData(): void
    {
        $productId = $this->createProduct('CancelData');
        $specId = $this->createSpecification($productId, 'Spec', 1000);

        [, $createContent] = $this->jsonRequest('POST', '/api/v1/manage/orders', [
            'items' => [['specificationId' => $specId, 'quantity' => 1]],
        ]);
        $orderId = $createContent['data']['id'];

        [$response] = $this->jsonRequest(
            'POST',
            "/api/v1/manage/orders/{$orderId}/do/cancel",
            ['reason' => 'test cancel']
        );

        self::assertSame(Response::HTTP_OK, $response->getStatusCode());
    }

    public function testManageOrderCannotBeUpdatedAfterSubmit(): void
    {
        $productId = $this->createProduct('GuardUpdate');
        $specId = $this->createSpecification($productId, 'Spec', 500);

        [, $create] = $this->jsonRequest('POST', '/api/v1/manage/orders', [
            'items' => [['specificationId' => $specId, 'quantity' => 1]],
        ]);
        $orderId = $create['data']['id'];

        $this->jsonRequest('POST', "/api/v1/manage/orders/{$orderId}/do/submit");

        [$response] = $this->jsonRequest('PUT', "/api/v1/manage/orders/{$orderId}", [
            'notes' => 'should fail',
        ]);

        self::assertSame(Response::HTTP_BAD_REQUEST, $response->getStatusCode());
    }

    public function testSpecificationCreateWithoutOptionalFields(): void
    {
        $productId = $this->createProduct('MinSpec');
        [$response, $content] = $this->jsonRequest(
            'POST',
            "/api/v1/manage/products/{$productId}/specifications",
            ['name' => 'Mini', 'price' => 99]
        );

        self::assertSame(Response::HTTP_CREATED, $response->getStatusCode());
        self::assertSame('Mini', $content['data']['name']);
        self::assertSame(99, $content['data']['price']);
        self::assertSame('active', $content['data']['status']);
    }

    public function testOrderCreateWithCustomCurrency(): void
    {
        $productId = $this->createProduct('CurrencyProduct');
        $specId = $this->createSpecification($productId, 'Spec', 1500);

        [$response, $content] = $this->jsonRequest('POST', '/api/v1/manage/orders', [
            'items' => [['specificationId' => $specId, 'quantity' => 1]],
            'currency' => 'EUR',
        ]);

        self::assertSame(Response::HTTP_CREATED, $response->getStatusCode());
        self::assertSame('EUR', $content['data']['currency']);
    }

    public function testOrderCreateWithNotes(): void
    {
        $productId = $this->createProduct('NotesProduct');
        $specId = $this->createSpecification($productId, 'Spec', 300);

        [$response, $content] = $this->jsonRequest('POST', '/api/v1/manage/orders', [
            'items' => [['specificationId' => $specId, 'quantity' => 5]],
            'notes' => 'Customer requested expedited shipping',
        ]);

        self::assertSame(Response::HTTP_CREATED, $response->getStatusCode());
        self::assertStringContainsString('expedited', $content['data']['notes'] ?? '');
    }

    public function testOrderUpdateNotFound(): void
    {
        [$response] = $this->jsonRequest('PUT', '/api/v1/manage/orders/99999', ['notes' => 'x']);
        $d = json_decode((string) $response->getContent(), true);

        self::assertSame(404, $d['code']);
    }

    public function testProductBatchUpdate(): void
    {
        $a = $this->createProduct('BatchA');
        $b = $this->createProduct('BatchB');

        [, $result] = $this->jsonRequest('POST', '/api/v1/manage/products/batch-update?@basis=id&@mode=update', [
            ['id' => $a, 'name' => 'BatchA Updated'],
            ['id' => $b, 'name' => 'BatchB Updated'],
        ]);

        self::assertSame(0, $result['code']);
    }

    public function testSpecificationBatchUpdate(): void
    {
        $pid = $this->createProduct('SpecBatch');
        $s1 = $this->createSpecification($pid, 'S1', 100);
        $s2 = $this->createSpecification($pid, 'S2', 200);

        [, $result] = $this->jsonRequest(
            'POST',
            "/api/v1/manage/products/{$pid}/specifications/batch-update?@basis=id&@mode=update",
            [
                ['id' => $s1, 'name' => 'S1x', 'price' => 150],
                ['id' => $s2, 'name' => 'S2x', 'price' => 250],
            ]
        );

        self::assertSame(0, $result['code']);
    }

    public function testProductBatchUpdateWithPartial(): void
    {
        $this->createProduct('PartialBatch');
        $b = $this->createProduct('PartialBatch2');

        [, $result] = $this->jsonRequest('POST', '/api/v1/manage/products/batch-update?@basis=id&@partial=true', [
            ['id' => $b, 'name' => 'Partial Updated'],
        ]);

        self::assertSame(0, $result['code']);
    }

    public function testProductSingleUpdateWithTransform(): void
    {
        $id = $this->createProduct('Transform');
        [$response] = $this->jsonRequest('PUT', "/api/v1/manage/products/{$id}?@transform=%7B%7D", ['name' => 'Test']);

        self::assertSame(200, $response->getStatusCode());
    }

    public function testBatchUpdateMixedModeCreatesNew(): void
    {
        [, $result] = $this->jsonRequest('POST', '/api/v1/manage/products/batch-update?@basis=name&@mode=mixed', [
            ['name' => 'NewProduct', 'status' => 'active'],
        ]);

        self::assertSame(0, $result['code']);
    }

    public function testBatchUpdatePartialModeSkipErrors(): void
    {
        [, $result] = $this->jsonRequest('POST', '/api/v1/manage/products/batch-update?@basis=name&@partial=true', [
            ['name' => 'NeverExists', 'status' => 'active'],
        ]);

        self::assertSame(0, $result['code']);
    }

    public function testAppOrderDetail(): void
    {
        $productId = $this->createProduct('AppDetailOrder');
        $specId = $this->createSpecification($productId, 'Spec', 1000);

        [, $create] = $this->jsonRequest('POST', '/api/v1/app/orders', [
            'items' => [['specificationId' => $specId, 'quantity' => 1]],
        ]);
        $orderId = $create['data']['id'];

        [$response, $content] = $this->jsonRequest('GET', "/api/v1/app/orders/{$orderId}");
        self::assertSame(200, $response->getStatusCode());
        self::assertSame(1000, $content['data']['totalAmount']);
    }

    public function testProductUpdateModeCreateWithBasis(): void
    {
        [, $result] = $this->jsonRequest('POST', '/api/v1/manage/products/batch-update?@basis=name&@mode=create', [
            ['name' => 'ModeCreate', 'status' => 'active'],
        ]);

        self::assertSame(0, $result['code']);
    }

    public function testManageOrderCreateWithUserId(): void
    {
        $this->createAuthenticatedUser();
        $productId = $this->createProduct('UserIdOrder');
        $specId = $this->createSpecification($productId, 'Spec', 500);

        [$response, $content] = $this->jsonRequest('POST', '/api/v1/manage/orders', [
            'items' => [['specificationId' => $specId, 'quantity' => 2]],
            'user' => 1,
        ]);

        self::assertSame(201, $response->getStatusCode());
        self::assertSame(1000, $content['data']['totalAmount']);
    }

    // ===== New Endpoint Tests: Items, Cancel, Pay, Fulfill, Refund =====

    public function testManageOrderItems(): void
    {
        $productId = $this->createProduct('ItemsProduct');
        $specId = $this->createSpecification($productId, 'ItemsSpec', 500);

        [, $createContent] = $this->jsonRequest('POST', '/api/v1/manage/orders', [
            'items' => [['specificationId' => $specId, 'quantity' => 3]],
        ]);
        $orderId = $createContent['data']['id'];

        [$response, $content] = $this->jsonRequest('GET', "/api/v1/manage/orders/{$orderId}/items");

        self::assertSame(Response::HTTP_OK, $response->getStatusCode());
        $this->assertSuccess($content);
        self::assertIsArray($content['data']);
        self::assertCount(1, $content['data']);
    }

    public function testManageOrderItemsNotFound(): void
    {
        [$response] = $this->jsonRequest('GET', '/api/v1/manage/orders/99999/items');
        self::assertSame(Response::HTTP_NOT_FOUND, $response->getStatusCode());
    }

    public function testManageOrderFulfill(): void
    {
        $productId = $this->createProduct('FulfillProduct');
        $specId = $this->createSpecification($productId, 'Spec', 1000);

        [, $createContent] = $this->jsonRequest('POST', '/api/v1/manage/orders', [
            'items' => [['specificationId' => $specId, 'quantity' => 1]],
        ]);
        $orderId = $createContent['data']['id'];

        $transitions = ['submit', 'confirm', 'pay'];
        foreach ($transitions as $t) {
            $this->jsonRequest('POST', "/api/v1/manage/orders/{$orderId}/do/{$t}");
        }

        [$response, $content] = $this->jsonRequest('POST', "/api/v1/manage/orders/{$orderId}/fulfill", [
            'trackingNumber' => 'SF1234567890',
            'shippingAddress' => '123 Test St, Shanghai',
        ]);

        self::assertSame(Response::HTTP_OK, $response->getStatusCode());
        $this->assertSuccess($content);
        self::assertSame('fulfilled', $content['data']['status']);
    }

    public function testManageOrderFulfillWithoutOptionalData(): void
    {
        $productId = $this->createProduct('FulfillMin');
        $specId = $this->createSpecification($productId, 'Spec', 1000);

        [, $createContent] = $this->jsonRequest('POST', '/api/v1/manage/orders', [
            'items' => [['specificationId' => $specId, 'quantity' => 1]],
        ]);
        $orderId = $createContent['data']['id'];

        $transitions = ['submit', 'confirm', 'pay'];
        foreach ($transitions as $t) {
            $this->jsonRequest('POST', "/api/v1/manage/orders/{$orderId}/do/{$t}");
        }

        [$response, $content] = $this->jsonRequest('POST', "/api/v1/manage/orders/{$orderId}/fulfill");
        self::assertSame(Response::HTTP_OK, $response->getStatusCode());
        $this->assertSuccess($content);
        self::assertSame('fulfilled', $content['data']['status']);
    }

    public function testManageOrderFulfillWrongStatus(): void
    {
        $productId = $this->createProduct('FulfillWrong');
        $specId = $this->createSpecification($productId, 'Spec', 1000);

        [, $createContent] = $this->jsonRequest('POST', '/api/v1/manage/orders', [
            'items' => [['specificationId' => $specId, 'quantity' => 1]],
        ]);
        $orderId = $createContent['data']['id'];

        [$response] = $this->jsonRequest('POST', "/api/v1/manage/orders/{$orderId}/fulfill");
        self::assertSame(Response::HTTP_BAD_REQUEST, $response->getStatusCode());
    }

    public function testManageOrderPayRequiresSystemWallet(): void
    {
        $productId = $this->createProduct('PayWallet');
        $specId = $this->createSpecification($productId, 'Spec', 1000);

        [, $createContent] = $this->jsonRequest('POST', '/api/v1/manage/orders', [
            'items' => [['specificationId' => $specId, 'quantity' => 1]],
        ]);
        $orderId = $createContent['data']['id'];

        $transitions = ['submit', 'confirm'];
        foreach ($transitions as $t) {
            $this->jsonRequest('POST', "/api/v1/manage/orders/{$orderId}/do/{$t}");
        }

        [$response] = $this->jsonRequest('POST', "/api/v1/manage/orders/{$orderId}/pay", [
            'systemWalletId' => 99999,
        ]);

        self::assertSame(Response::HTTP_NOT_FOUND, $response->getStatusCode());
    }

    public function testManageOrderPayMissingSystemWallet(): void
    {
        $productId = $this->createProduct('PayNoWallet');
        $specId = $this->createSpecification($productId, 'Spec', 1000);

        [, $createContent] = $this->jsonRequest('POST', '/api/v1/manage/orders', [
            'items' => [['specificationId' => $specId, 'quantity' => 1]],
        ]);
        $orderId = $createContent['data']['id'];

        $transitions = ['submit', 'confirm'];
        foreach ($transitions as $t) {
            $this->jsonRequest('POST', "/api/v1/manage/orders/{$orderId}/do/{$t}");
        }

        [$response] = $this->jsonRequest('POST', "/api/v1/manage/orders/{$orderId}/pay");

        self::assertSame(Response::HTTP_NOT_FOUND, $response->getStatusCode());
    }

    public function testManageOrderPayWrongStatus(): void
    {
        $productId = $this->createProduct('PayWrong');
        $specId = $this->createSpecification($productId, 'Spec', 1000);

        [, $createContent] = $this->jsonRequest('POST', '/api/v1/manage/orders', [
            'items' => [['specificationId' => $specId, 'quantity' => 1]],
        ]);
        $orderId = $createContent['data']['id'];

        [$response] = $this->jsonRequest('POST', "/api/v1/manage/orders/{$orderId}/pay", [
            'systemWalletId' => 1,
        ]);

        self::assertSame(Response::HTTP_NOT_FOUND, $response->getStatusCode());
    }

    public function testManageOrderRefundRequiresSystemWallet(): void
    {
        $productId = $this->createProduct('RefundWallet');
        $specId = $this->createSpecification($productId, 'Spec', 1000);

        [, $createContent] = $this->jsonRequest('POST', '/api/v1/manage/orders', [
            'items' => [['specificationId' => $specId, 'quantity' => 1]],
        ]);
        $orderId = $createContent['data']['id'];

        $transitions = ['submit', 'confirm', 'pay'];
        foreach ($transitions as $t) {
            $this->jsonRequest('POST', "/api/v1/manage/orders/{$orderId}/do/{$t}");
        }

        [$response] = $this->jsonRequest('POST', "/api/v1/manage/orders/{$orderId}/refund", [
            'systemWalletId' => 99999,
            'reason' => 'Customer request',
        ]);

        self::assertSame(Response::HTTP_BAD_REQUEST, $response->getStatusCode());
    }

    public function testManageOrderRefundMissingReason(): void
    {
        $productId = $this->createProduct('RefundNoReason');
        $specId = $this->createSpecification($productId, 'Spec', 1000);

        [, $createContent] = $this->jsonRequest('POST', '/api/v1/manage/orders', [
            'items' => [['specificationId' => $specId, 'quantity' => 1]],
        ]);
        $orderId = $createContent['data']['id'];

        $transitions = ['submit', 'confirm', 'pay'];
        foreach ($transitions as $t) {
            $this->jsonRequest('POST', "/api/v1/manage/orders/{$orderId}/do/{$t}");
        }

        [$response] = $this->jsonRequest('POST', "/api/v1/manage/orders/{$orderId}/refund", [
            'systemWalletId' => 1,
        ]);

        self::assertSame(Response::HTTP_BAD_REQUEST, $response->getStatusCode());
    }

    public function testManageOrderRefundWrongStatus(): void
    {
        $productId = $this->createProduct('RefundWrong');
        $specId = $this->createSpecification($productId, 'Spec', 1000);

        [, $createContent] = $this->jsonRequest('POST', '/api/v1/manage/orders', [
            'items' => [['specificationId' => $specId, 'quantity' => 1]],
        ]);
        $orderId = $createContent['data']['id'];

        [$response] = $this->jsonRequest('POST', "/api/v1/manage/orders/{$orderId}/refund", [
            'systemWalletId' => 1,
            'reason' => 'Test',
        ]);

        self::assertSame(Response::HTTP_BAD_REQUEST, $response->getStatusCode());
    }

    public function testAppOrderItems(): void
    {
        $productId = $this->createProduct('AppItemsProduct');
        $specId = $this->createSpecification($productId, 'Spec', 1000);

        [, $createContent] = $this->jsonRequest('POST', '/api/v1/app/orders', [
            'items' => [['specificationId' => $specId, 'quantity' => 2]],
        ]);
        $orderId = $createContent['data']['id'];

        [$response, $content] = $this->jsonRequest('GET', "/api/v1/app/orders/{$orderId}/items");

        self::assertSame(Response::HTTP_OK, $response->getStatusCode());
        $this->assertSuccess($content);
        self::assertIsArray($content['data']);
        self::assertCount(1, $content['data']);
    }

    public function testAppOrderItemsOtherUserForbidden(): void
    {
        $productId = $this->createProduct('OtherUserProduct');
        $specId = $this->createSpecification($productId, 'Spec', 1000);

        [, $createContent] = $this->jsonRequest('POST', '/api/v1/manage/orders', [
            'items' => [['specificationId' => $specId, 'quantity' => 1]],
        ]);
        $orderId = $createContent['data']['id'];

        [$response] = $this->jsonRequest('GET', "/api/v1/app/orders/{$orderId}/items");
        self::assertSame(Response::HTTP_NOT_FOUND, $response->getStatusCode());
    }

    public function testAppOrderCancelFromDraft(): void
    {
        $productId = $this->createProduct('AppCancelDraft');
        $specId = $this->createSpecification($productId, 'Spec', 1000);

        [, $createContent] = $this->jsonRequest('POST', '/api/v1/app/orders', [
            'items' => [['specificationId' => $specId, 'quantity' => 1]],
        ]);
        $orderId = $createContent['data']['id'];

        [$response, $content] = $this->jsonRequest('POST', "/api/v1/app/orders/{$orderId}/cancel");

        self::assertSame(Response::HTTP_OK, $response->getStatusCode());
        $this->assertSuccess($content);
        self::assertSame('cancelled', $content['data']['status']);
    }

    public function testAppOrderCancelWrongUser(): void
    {
        $productId = $this->createProduct('CancelWrongUser');
        $specId = $this->createSpecification($productId, 'Spec', 1000);

        [, $createContent] = $this->jsonRequest('POST', '/api/v1/manage/orders', [
            'items' => [['specificationId' => $specId, 'quantity' => 1]],
        ]);
        $orderId = $createContent['data']['id'];

        [$response] = $this->jsonRequest('POST', "/api/v1/app/orders/{$orderId}/cancel");
        self::assertSame(Response::HTTP_NOT_FOUND, $response->getStatusCode());
    }

    public function testAppOrderCancelAfterPaidNotAllowed(): void
    {
        $productId = $this->createProduct('AppCancelPaid');
        $specId = $this->createSpecification($productId, 'Spec', 1000);

        [, $createContent] = $this->jsonRequest('POST', '/api/v1/app/orders', [
            'items' => [['specificationId' => $specId, 'quantity' => 1]],
        ]);
        $orderId = $createContent['data']['id'];

        $transitions = ['submit', 'confirm', 'pay'];
        foreach ($transitions as $t) {
            $this->jsonRequest('POST', "/api/v1/manage/orders/{$orderId}/do/{$t}");
        }

        [$response] = $this->jsonRequest('POST', "/api/v1/app/orders/{$orderId}/cancel");
        self::assertSame(Response::HTTP_BAD_REQUEST, $response->getStatusCode());
    }

    public function testWorkflowCompleteFullFlowTimestamps(): void
    {
        $productId = $this->createProduct('TSProduct');
        $specId = $this->createSpecification($productId, 'Spec', 500);

        [, $createContent] = $this->jsonRequest('POST', '/api/v1/manage/orders', [
            'items' => [['specificationId' => $specId, 'quantity' => 1]],
        ]);
        $orderId = $createContent['data']['id'];

        $flow = ['submit', 'confirm', 'pay', 'fulfill', 'complete'];
        foreach ($flow as $transition) {
            $this->jsonRequest('POST', "/api/v1/manage/orders/{$orderId}/do/{$transition}");
        }

        [, $detail] = $this->jsonRequest('GET', "/api/v1/manage/orders/{$orderId}");
        self::assertSame('completed', $detail['data']['status']);
        self::assertNotNull($detail['data']['completedAt'] ?? null);
        self::assertNotNull($detail['data']['paidAt'] ?? null);
        self::assertNotNull($detail['data']['fulfilledAt'] ?? null);
    }

    public function testWorkflowCancelSetsCancelledAt(): void
    {
        $productId = $this->createProduct('CancelTS');
        $specId = $this->createSpecification($productId, 'Spec', 500);

        [, $createContent] = $this->jsonRequest('POST', '/api/v1/manage/orders', [
            'items' => [['specificationId' => $specId, 'quantity' => 1]],
        ]);
        $orderId = $createContent['data']['id'];

        $this->jsonRequest('POST', "/api/v1/manage/orders/{$orderId}/do/cancel");

        [, $detail] = $this->jsonRequest('GET', "/api/v1/manage/orders/{$orderId}");
        self::assertSame('cancelled', $detail['data']['status']);
        self::assertNotNull($detail['data']['cancelledAt'] ?? null);
    }

    public function testWorkflowRefundSetsRefundedAt(): void
    {
        $productId = $this->createProduct('RefundTS');
        $specId = $this->createSpecification($productId, 'Spec', 500);

        [, $createContent] = $this->jsonRequest('POST', '/api/v1/manage/orders', [
            'items' => [['specificationId' => $specId, 'quantity' => 1]],
        ]);
        $orderId = $createContent['data']['id'];

        $transitions = ['submit', 'confirm', 'pay'];
        foreach ($transitions as $t) {
            $this->jsonRequest('POST', "/api/v1/manage/orders/{$orderId}/do/{$t}");
        }
        $this->jsonRequest('POST', "/api/v1/manage/orders/{$orderId}/do/refund");

        [, $detail] = $this->jsonRequest('GET', "/api/v1/manage/orders/{$orderId}");
        self::assertSame('refunded', $detail['data']['status']);
        self::assertNotNull($detail['data']['refundedAt'] ?? null);
    }
}
