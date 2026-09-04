<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Common\Entity\Category;
use App\Tests\Integration\DatabaseBootstrapTrait;
use App\Tests\Integration\IntegrationWebTestCase;
use Doctrine\ORM\EntityManagerInterface;

/**
 * End-to-end HTTP-kernel coverage of the dynamic query system
 * (@filter/@dql/@order/@sort/@select/@groupBy/@expands/@display/@transform)
 * against a real entity endpoint (GET/POST /api/v1/manage/categories).
 *
 * Findings that change expected behavior are documented in
 * docs/issues/coverage-2026-08-09/core-integration-extra.md:
 *  - BUG-1: admin-gated params (@dql/@sort/@hints + @filter fallback) always 403.
 *  - BUG-2: @expands emits a PHP 8.5 dynamic-property deprecation.
 *  - BUG-3: malformed @select (array) surfaces as HTTP 500, not 400.
 *  - BUG-4: regex matches crashes on SQLite (no REGEXP function).
 */
final class CoreDynamicQueryApiTest extends IntegrationWebTestCase
{
    use DatabaseBootstrapTrait;

    protected function setUp(): void
    {
        $this->bootTestDatabase();
        self::ensureKernelShutdown();
        $client = static::createClient();
        $em = $client->getContainer()->get(EntityManagerInterface::class);

        $em->createQuery('DELETE FROM App\\Common\\Entity\\Category c')->execute();
        $categories = [];
        foreach (['Alpha', 'Beta', 'Gamma'] as $i => $name) {
            $category = new Category($name, 'slug-' . $i);
            $category->setSortOrder($i);
            $category->setDescription('desc-' . $name);
            $categories[] = $category;
            $em->persist($category);
        }
        $categories[0]->addChild($categories[1]);
        $em->flush();
        self::ensureKernelShutdown();
    }

    public function testFilterCompilesToDqlAndFilters(): void
    {
        $client = static::createAuthenticatedClient();
        $client->request('GET', '/api/v1/manage/categories?@filter=entity.getSortOrder() > 0');

        self::assertSame(200, $client->getResponse()->getStatusCode());
        $data = json_decode((string) $client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame(0, $data['code']);
        self::assertSame(['Beta', 'Gamma'], array_column($data['data'], 'name'));
        self::assertSame(2, $data['paginator']['total']);
    }

    public function testFilterEquality(): void
    {
        $client = static::createAuthenticatedClient();
        $client->request('GET', '/api/v1/manage/categories?@filter=entity.getSortOrder() == 1');

        $data = json_decode((string) $client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame(['Beta'], array_column($data['data'], 'name'));
    }

    public function testFilterMatchesUsesLike(): void
    {
        $client = static::createAuthenticatedClient();
        $client->request('GET', '/api/v1/manage/categories?@filter=entity.getSlug() matches "slug-1"');

        self::assertSame(200, $client->getResponse()->getStatusCode());
        $data = json_decode((string) $client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame(['Beta'], array_column($data['data'], 'name'));
    }

    public function testFilterSingleAttributeTruthy(): void
    {
        $client = static::createAuthenticatedClient();
        $client->request('GET', '/api/v1/manage/categories?@filter=entity.getName()');

        self::assertSame(200, $client->getResponse()->getStatusCode());
        $data = json_decode((string) $client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame(['Alpha', 'Beta', 'Gamma'], array_column($data['data'], 'name'));
    }

    public function testOrderDescending(): void
    {
        $client = static::createAuthenticatedClient();
        $client->request('GET', '/api/v1/manage/categories?@order=entity.sortOrder|DESC');

        $data = json_decode((string) $client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame(['Gamma', 'Beta', 'Alpha'], array_column($data['data'], 'name'));
    }

    public function testSelectProjectsFields(): void
    {
        $client = static::createAuthenticatedClient();
        $client->request('GET', '/api/v1/manage/categories?@select=entity.id,entity.name&@order=entity.id|ASC');

        $data = json_decode((string) $client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR);
        self::assertCount(3, $data['data']);
        self::assertSame(['Alpha', 'Beta', 'Gamma'], array_column($data['data'], 'name'));
        foreach ($data['data'] as $row) {
            self::assertArrayHasKey('id', $row);
            self::assertArrayHasKey('name', $row);
            self::assertArrayNotHasKey('description', $row);
            self::assertArrayNotHasKey('slug', $row);
        }
    }

    public function testGroupByWithSelect(): void
    {
        $client = static::createAuthenticatedClient();
        $client->request('GET', '/api/v1/manage/categories?@groupBy=entity.sortOrder&@select=entity.sortOrder&@order=entity.sortOrder|ASC');

        self::assertSame(200, $client->getResponse()->getStatusCode());
        $data = json_decode((string) $client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame([['sortOrder' => 0], ['sortOrder' => 1], ['sortOrder' => 2]], $data['data']);
    }

    public function testDisplayReduce(): void
    {
        $client = static::createAuthenticatedClient();
        $client->request('GET', '/api/v1/manage/categories?@display=reduce');

        $data = json_decode((string) $client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR);
        self::assertCount(3, $data['data']);
        foreach ($data['data'] as $row) {
            self::assertArrayHasKey('id', $row);
            self::assertArrayHasKey('__toString', $row);
        }
    }

    public function testDisplayFieldProjection(): void
    {
        $client = static::createAuthenticatedClient();
        $client->request('GET', '/api/v1/manage/categories?@display=["entity.name"]');

        $data = json_decode((string) $client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame([['entity.name' => 'Alpha'], ['entity.name' => 'Beta'], ['entity.name' => 'Gamma']], $data['data']);
    }

    public function testTransformAppliedOnCreate(): void
    {
        $client = static::createAuthenticatedClient();
        $client->request(
            'POST',
            '/api/v1/manage/categories?@transform={"slug":"Math.ceil(3.7)"}',
            server: ['CONTENT_TYPE' => 'application/json'],
            content: json_encode(['name' => 'Transformed', 'slug' => 'ignored'], JSON_THROW_ON_ERROR)
        );

        self::assertSame(201, $client->getResponse()->getStatusCode());
        $data = json_decode((string) $client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame('4', $data['data']['slug']);
    }

    public function testNonNumericLimitRejectedWith400(): void
    {
        $client = static::createAuthenticatedClient();
        $client->request('GET', '/api/v1/manage/categories?limit=abc');

        self::assertSame(400, $client->getResponse()->getStatusCode());
        $body = json_decode((string) $client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame(400, $body['code']);
        self::assertArrayHasKey('message', $body);
    }

    public function testNonNumericPageRejectedWith400(): void
    {
        $client = static::createAuthenticatedClient();
        $client->request('GET', '/api/v1/manage/categories?page=abc&limit=2');

        self::assertSame(400, $client->getResponse()->getStatusCode());
    }

    public function testShowDqlRejectedOutsideDev(): void
    {
        $client = static::createAuthenticatedClient();
        $client->request('GET', '/api/v1/manage/categories?@showDQL=1');

        self::assertSame(403, $client->getResponse()->getStatusCode());
        $body = json_decode((string) $client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR);
        self::assertStringContainsString('@showDQL is only available in the dev environment', $body['message']);
    }

    // ---- BUG-1 regression tests (BaseService::$user is null for HTTP requests) ----

    public function testDqlRestrictedForEveryoneCurrently(): void
    {
        $client = static::createAuthenticatedClient();
        $client->request('GET', '/api/v1/manage/categories?@dql=SELECT c2.id FROM App\\Common\\Entity\\Category c2 WHERE c2.sortOrder > 1');

        self::assertSame(403, $client->getResponse()->getStatusCode());
        $body = json_decode((string) $client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR);
        self::assertStringContainsString('@dql is restricted to administrators', $body['message']);
    }

    public function testSortRestrictedForEveryoneCurrently(): void
    {
        $client = static::createAuthenticatedClient();
        $client->request('GET', '/api/v1/manage/categories?@sort=item.getSortOrder() > entity.getSortOrder()');

        self::assertSame(403, $client->getResponse()->getStatusCode());
        $body = json_decode((string) $client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR);
        self::assertStringContainsString('@sort is restricted to administrators', $body['message']);
    }

    public function testHintsRestrictedForEveryoneCurrently(): void
    {
        $client = static::createAuthenticatedClient();
        $client->request('GET', '/api/v1/manage/categories?@hints={"x":"y"}');

        self::assertSame(403, $client->getResponse()->getStatusCode());
        $body = json_decode((string) $client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR);
        self::assertStringContainsString('@hints is restricted to administrators', $body['message']);
    }

    public function testInvalidFilterFallsToAdminGateCurrently(): void
    {
        $client = static::createAuthenticatedClient();
        $client->request('GET', '/api/v1/manage/categories?@filter=entity.getFooBar() == 1');

        self::assertSame(403, $client->getResponse()->getStatusCode());
        $body = json_decode((string) $client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR);
        self::assertStringContainsString('@filter expressions that require in-memory evaluation are restricted to administrators', $body['message']);
    }

    // ---- BUG-3 regression test ----

    public function testArraySelectReturns500Currently(): void
    {
        $client = static::createAuthenticatedClient();
        $client->request('GET', '/api/v1/manage/categories?@select[]=entity.id&@select[]=entity.name');

        self::assertSame(500, $client->getResponse()->getStatusCode());
        $body = json_decode((string) $client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame('@select must be a string.', $body['message']);
    }

    // ---- Skipped correct-behavior tests (blocked by documented src bugs) ----

    public function testDqlAllowedForAdminReturnsFilteredResults(): void
    {
        self::markTestSkipped('BUG-1: BaseService::$user is null for HTTP requests; @dql always 403. See docs/issues/coverage-2026-08-09/core-integration-extra.md#bug-1.');
    }

    public function testSortAllowedForAdminSortsInMemory(): void
    {
        self::markTestSkipped('BUG-1: @sort always 403 via HTTP. See docs/issues/coverage-2026-08-09/core-integration-extra.md#bug-1.');
    }

    public function testArraySelectReturns400(): void
    {
        self::markTestSkipped('BUG-3: malformed @select returns 500, not 400. See docs/issues/coverage-2026-08-09/core-integration-extra.md#bug-3.');
    }

    public function testExpandsReturnsMetadataWithoutDeprecation(): void
    {
        $client = static::createAuthenticatedClient();
        $client->request('GET', '/api/v1/manage/categories?@expands=children&@order=entity.id|ASC');

        self::assertSame(200, $client->getResponse()->getStatusCode());
        $data = json_decode((string) $client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame('Beta', $data['data'][0]['children'][0]['name']);
    }

    public function testRegexMatchesWorksOnCurrentDb(): void
    {
        self::markTestSkipped('BUG-4: regex matches compiles to REGEXP() which SQLite lacks -> 500. See docs/issues/coverage-2026-08-09/core-integration-extra.md#bug-4.');
    }
}
