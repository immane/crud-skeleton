<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Tests\Integration\DatabaseBootstrapTrait;
use App\Tests\Integration\IntegrationWebTestCase;

/**
 * End-to-end coverage of the OpenApiEnricherListener on GET /api/doc.json:
 *  - spec is valid JSON, openapi 3.1.0
 *  - module tags (Products, Orders, System, Wallet, ...) present in tag list
 *  - every operation carries a module tag (not an operation-type tag)
 *  - generic operation-type tags (List/Detail/Create/Update/Delete/Workflow)
 *    are removed from the tag list and from operations
 */
final class CoreOpenApiEnricherApiTest extends IntegrationWebTestCase
{
    use DatabaseBootstrapTrait;

    private const GENERIC_TAGS = ['List', 'Detail', 'Create', 'Update', 'Delete', 'Workflow'];

    protected function setUp(): void
    {
        $this->bootTestDatabase();
        self::ensureKernelShutdown();
    }

    private function fetchSpec(): array
    {
        $client = static::createClient();
        $client->request('GET', '/api/doc.json');

        self::assertSame(200, $client->getResponse()->getStatusCode());
        self::assertStringContainsString('application/json', (string) $client->getResponse()->headers->get('content-type'));

        return json_decode((string) $client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR);
    }

    public function testSpecIsOpenApi31(): void
    {
        $doc = $this->fetchSpec();
        self::assertSame('3.1.0', $doc['openapi']);
        self::assertArrayHasKey('paths', $doc);
        self::assertArrayHasKey('/api/v1/manage/contents', $doc['paths']);
    }

    public function testModuleTagsArePresent(): void
    {
        $doc = $this->fetchSpec();
        $tagNames = array_column($doc['tags'] ?? [], 'name');

        foreach (['Auth', 'Products', 'Orders', 'System', 'Wallet', 'Categories', 'Media', 'Payment', 'Wechat', 'Store', 'Inventory', 'Promotions', 'PromotionTemplates', 'Settlement', 'Pictures'] as $expected) {
            self::assertContains($expected, $tagNames, "missing tag $expected");
        }
    }

    public function testNoGenericOperationTagsRemainInTagList(): void
    {
        $doc = $this->fetchSpec();
        $tagNames = array_column($doc['tags'] ?? [], 'name');

        foreach (self::GENERIC_TAGS as $generic) {
            self::assertNotContains($generic, $tagNames, "generic tag $generic should be removed");
        }
    }

    public function testNoGenericOperationTagsRemainOnAnyOperation(): void
    {
        $doc = $this->fetchSpec();

        foreach ($doc['paths'] as $path => $methods) {
            foreach ($methods as $method => $operation) {
                if (!is_array($operation)) {
                    continue;
                }
                foreach (($operation['tags'] ?? []) as $tag) {
                    self::assertNotContains($tag, self::GENERIC_TAGS, "generic tag $tag left on $path $method");
                }
            }
        }
    }

    public function testSystemEndpointsTaggedSystem(): void
    {
        $doc = $this->fetchSpec();
        self::assertSame(['System'], $doc['paths']['/system/entities']['get']['tags']);
        self::assertSame(['System'], $doc['paths']['/system/entities/{entityName}']['get']['tags']);
        self::assertSame(['System'], $doc['paths']['/system/router']['get']['tags']);
    }

    public function testKeyEndpointsTaggedByModule(): void
    {
        $doc = $this->fetchSpec();
        self::assertSame(['Products'], $doc['paths']['/api/v1/manage/products']['get']['tags']);
        self::assertSame(['Products'], $doc['paths']['/api/v1/manage/products/{id}']['get']['tags']);
        self::assertSame(['Orders'], $doc['paths']['/api/v1/manage/orders']['get']['tags']);
        self::assertSame(['Categories'], $doc['paths']['/api/v1/manage/categories']['get']['tags']);
        self::assertSame(['Auth'], $doc['paths']['/api/auth/login']['post']['tags']);
        self::assertSame(['Wechat'], $doc['paths']['/api/wechat/miniapp/login']['post']['tags']);
        self::assertSame(['Payment'], $doc['paths']['/api/payment/notify/{payment}']['post']['tags']);
    }

    public function testOperationsHaveModuleTagsNotOperationTypeTags(): void
    {
        $doc = $this->fetchSpec();

        // These specific operations were historically tagged with operation-type tags.
        $cases = [
            ['/api/v1/manage/categories', 'post', 'Categories'],
            ['/api/v1/manage/categories/{id}', 'put', 'Categories'],
            ['/api/v1/manage/categories/{id}', 'delete', 'Categories'],
            ['/api/v1/manage/products', 'post', 'Products'],
            ['/api/v1/manage/orders/{id}', 'put', 'Orders'],
            ['/api/v1/manage/orders/{id}', 'delete', 'Orders'],
            ['/api/v1/app/orders/{id}/cancel', 'post', 'Orders'],
        ];

        foreach ($cases as [$path, $method, $expectedTag]) {
            $tags = $doc['paths'][$path][$method]['tags'] ?? [];
            self::assertSame([$expectedTag], $tags, "expected $expectedTag on $path $method, got " . implode(',', $tags));
        }
    }
}
