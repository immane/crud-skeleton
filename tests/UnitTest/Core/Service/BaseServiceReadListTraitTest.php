<?php

namespace App\Tests\UnitTest\Core\Service;

use App\Core\Service\BaseService;
use App\Core\Service\ExpressionServiceInterface;
use App\Core\Service\LegacyEvaluator;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Psr\Log\NullLogger;
use Doctrine\ORM\QueryBuilder;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Validator\Exception\ValidatorException;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\Security\Core\User\UserInterface;

final class BaseServiceReadListTraitTest extends TestCase
{
    private function createService(
        ContainerInterface $container,
        string $entityClass,
        ?ExpressionServiceInterface $expressionService = null,
        ?LegacyEvaluator $legacyEvaluator = null,
    ): BaseService
    {
        return new class($container, $entityClass, $expressionService, $legacyEvaluator) extends BaseService {
            public function __construct(
                ContainerInterface $container,
                string $entityClass,
                ?ExpressionServiceInterface $expressionService,
                ?LegacyEvaluator $legacyEvaluator,
            )
            {
                parent::__construct($container, $entityClass, null, $expressionService, $legacyEvaluator);
            }
        };
    }

    // -------------------------------------------------------
    //  get()
    // -------------------------------------------------------

    public function testGetByIntegerId(): void
    {
        $entity = new ReadListEntity(7, 'test');
        $repo = new ReadListFakeRepository([7 => $entity]);
        $container = new ReadListFakeContainer(new ReadListFakeEntityManager($repo));

        $service = $this->createService($container, ReadListEntity::class);
        $result = $service->get(7);

        self::assertSame($entity, $result);
    }

    public function testGetByUuidUsesMappedExternalIdentifier(): void
    {
        $entity = new ReadListEntity(7, 'test', '550e8400-e29b-41d4-a716-446655440000');
        $repo = new ReadListFakeRepository([7 => $entity]);
        $container = new ReadListFakeContainer(new ReadListFakeEntityManager($repo));

        $service = $this->createService($container, ReadListEntity::class);

        self::assertSame($entity, $service->get('550e8400-e29b-41d4-a716-446655440000'));
    }

    public function testGetByObjectWithId(): void
    {
        $entity = new ReadListEntity(5, 'obj');
        $repo = new ReadListFakeRepository([5 => $entity]);
        $container = new ReadListFakeContainer(new ReadListFakeEntityManager($repo));

        $service = $this->createService($container, ReadListEntity::class);
        $result = $service->get($entity);

        self::assertSame($entity, $result);
    }

    public function testGetByArrayCriteria(): void
    {
        $entity = new ReadListEntity(3, 'match');
        $repo = new ReadListFakeRepository([3 => $entity]);
        $container = new ReadListFakeContainer(new ReadListFakeEntityManager($repo));

        $service = $this->createService($container, ReadListEntity::class);
        $result = $service->get(['name' => 'match']);

        self::assertSame($entity, $result);
    }

    public function testGetReturnsNullForMissing(): void
    {
        $repo = new ReadListFakeRepository([]);
        $container = new ReadListFakeContainer(new ReadListFakeEntityManager($repo));

        $service = $this->createService($container, ReadListEntity::class);
        self::assertNull($service->get(999));
    }

    public function testGetReturnsNullForNullInput(): void
    {
        $repo = new ReadListFakeRepository([]);
        $container = new ReadListFakeContainer(new ReadListFakeEntityManager($repo));

        $service = $this->createService($container, ReadListEntity::class);
        self::assertNull($service->get(null));
    }

    public function testGetByQueryBuilderReturnsSingleResult(): void
    {
        $entity = new ReadListEntity(1, 'qb');
        $query = $this->createMock(\Doctrine\ORM\Query::class);
        $query->method('getSingleResult')->willReturn($entity);

        $qb = $this->createMock(QueryBuilder::class);
        $qb->method('getQuery')->willReturn($query);

        $repo = new ReadListFakeRepository([]);
        $container = new ReadListFakeContainer(new ReadListFakeEntityManager($repo));

        $service = $this->createService($container, ReadListEntity::class);
        $result = $service->get($qb);
        self::assertSame($entity, $result);
    }

    public function testGetByQueryBuilderNoResultReturnsNull(): void
    {
        $query = $this->createMock(\Doctrine\ORM\Query::class);
        $query->method('getSingleResult')->willThrowException(new \Doctrine\ORM\NoResultException());

        $qb = $this->createMock(QueryBuilder::class);
        $qb->method('getQuery')->willReturn($query);

        $repo = new ReadListFakeRepository([]);
        $container = new ReadListFakeContainer(new ReadListFakeEntityManager($repo));

        $service = $this->createService($container, ReadListEntity::class);
        self::assertNull($service->get($qb));
    }

    // -------------------------------------------------------
    //  list()
    // -------------------------------------------------------

    public function testListWithNoFilterReturnsAll(): void
    {
        $e1 = new ReadListEntity(1, 'alpha');
        $e2 = new ReadListEntity(2, 'beta');
        $repo = new ReadListFakeRepository([1 => $e1, 2 => $e2]);
        $em = new ReadListFakeEntityManager($repo);
        $em->setQueryResults([$e1, $e2]);
        $container = new ReadListFakeContainer($em);

        $service = $this->createService($container, ReadListEntity::class);
        $result = $service->list(null, null, true);

        self::assertIsArray($result);
        self::assertCount(2, $result);
    }

    public function testListWithArrayFilter(): void
    {
        $e1 = new ReadListEntity(1, 'alpha');
        $e2 = new ReadListEntity(2, 'beta');
        $repo = new ReadListFakeRepository([1 => $e1, 2 => $e2]);
        $em = new ReadListFakeEntityManager($repo);
        $em->setQueryResults([$e1]); // filter return alpha only
        $container = new ReadListFakeContainer($em);

        $service = $this->createService($container, ReadListEntity::class);
        $result = $service->list(['name' => 'alpha'], null, true);

        self::assertIsArray($result);
        self::assertCount(1, $result);
        self::assertSame('alpha', $result[0]->getName());
    }

    public function testListWithQueryBuilderInput(): void
    {
        $e1 = new ReadListEntity(1, 'x');
        $query = $this->createMock(\Doctrine\ORM\Query::class);
        $query->method('getResult')->willReturn([$e1]);

        $qb = $this->createMock(QueryBuilder::class);
        $qb->method('getRootAliases')->willReturn(['entity']);
        $qb->method('getQuery')->willReturn($query);

        $repo = new ReadListFakeRepository([]);
        $container = new ReadListFakeContainer(new ReadListFakeEntityManager($repo));

        $service = $this->createService($container, ReadListEntity::class);
        $result = $service->list($qb, null, true);

        self::assertIsArray($result);
        self::assertCount(1, $result);
    }

    public function testListWithNullReturnsAll(): void
    {
        $e1 = new ReadListEntity(1, 'one');
        $e2 = new ReadListEntity(2, 'two');
        $e3 = new ReadListEntity(3, 'three');
        $repo = new ReadListFakeRepository([1 => $e1, 2 => $e2, 3 => $e3]);
        $em = new ReadListFakeEntityManager($repo);
        $em->setQueryResults([$e1, $e2, $e3]);
        $container = new ReadListFakeContainer($em);

        $service = $this->createService($container, ReadListEntity::class);
        $result = $service->list(null, null, true);

        self::assertIsArray($result);
        self::assertCount(3, $result);
    }

    public function testListWithRequestDqlOrderAndHintsReturnsQueryBuilder(): void
    {
        $repo = new ReadListFakeRepository([]);
        $em = new ReadListFakeEntityManager($repo);
        $request = new Request([
            '@dql' => 'SELECT i.id FROM Items i',
            '@order' => 'entity.name|DESC',
            '@hints' => '{"HINT_CUSTOM_OUTPUT_WALKER":"walker"}',
        ]);
        $container = new ReadListFakeContainer($em, $this->createRequestStack($request), $this->createAdminUser());

        $service = $this->createService($container, ReadListEntity::class);
        $result = $service->list(null, null, false);

        self::assertIsObject($result);
        self::assertSame(['entity.name' => 'DESC'], $em->lastQueryBuilder?->orderBy ?? []);
        self::assertSame(['HINT_CUSTOM_OUTPUT_WALKER' => 'walker'], $em->lastQuery?->hints ?? []);
    }

    public function testListWithSelectAndGroupByReturnsRows(): void
    {
        $repo = new ReadListFakeRepository([]);
        $em = new ReadListFakeEntityManager($repo);
        $em->setQueryResults([['name' => 'alpha']]);
        $request = new Request([
            '@select' => 'entity.name',
            '@groupBy' => 'entity.name',
        ]);
        $container = new ReadListFakeContainer($em, $this->createRequestStack($request));

        $service = $this->createService($container, ReadListEntity::class);
        $result = $service->list(null, null, false);

        self::assertSame([['name' => 'alpha']], $result);
        self::assertSame('entity.name', $em->lastQueryBuilder?->selectClause);
        self::assertSame('entity.name', $em->lastQueryBuilder?->groupByClause);
    }

    public function testListWithShowDqlThrowsDebugException(): void
    {
        $repo = new ReadListFakeRepository([]);
        $em = new ReadListFakeEntityManager($repo);
        $container = new ReadListFakeContainer($em, $this->createRequestStack(new Request(['@showDQL' => '1'])), null, 'dev');

        $service = $this->createService($container, ReadListEntity::class);

        $this->expectException(ValidatorException::class);
        $this->expectExceptionMessage('DQL: SELECT');

        $service->list(null, null, false);
    }

    public function testListWithSelectAndSortFallbackThrowsGroupingFilterError(): void
    {
        $repo = new ReadListFakeRepository([]);
        $em = new ReadListFakeEntityManager($repo);
        $request = new Request([
            '@select' => 'entity.name',
            '@sort' => 'x.getId() > y.getId()',
        ]);
        $container = new ReadListFakeContainer($em, $this->createRequestStack($request), $this->createAdminUser());

        $service = $this->createService($container, ReadListEntity::class);

        $this->expectException(ValidatorException::class);
        $this->expectExceptionMessage('Filter error from grouping by or selection');

        $service->list(null, null, false);
    }

    public function testListWithFilterBuildSuccessAddsDqlAndParameters(): void
    {
        $repo = new ReadListFakeRepository([]);
        $em = new ReadListFakeEntityManager($repo);
        $container = new ReadListFakeContainer($em, $this->createRequestStack(new Request(['@filter' => 'entity.getId() == 1'])));

        $filterQb = $this->createMock(QueryBuilder::class);
        $filterQb->method('getDQL')->willReturn('SELECT filter_entity.id FROM Entity filter_entity');

        $parameter = new class {
            public function getName(): string { return 'filter_parameter_1'; }
            public function getValue(): int { return 1; }
        };

        $expressionService = $this->createMock(ExpressionServiceInterface::class);
        $expressionService->expects(self::once())
            ->method('buildFilter')
            ->with('entity.getId() == 1', ReadListEntity::class, self::isArray(), $em)
            ->willReturn(['qb' => $filterQb, 'parameters' => [$parameter]]);

        $service = $this->createService($container, ReadListEntity::class, $expressionService);
        $result = $service->list(null, null, false);

        self::assertIsObject($result);
        self::assertSame(1, $em->lastQueryBuilder?->params['filter_parameter_1'] ?? null);
    }

    public function testFilterParametersAreRenamedWhenCommonFilterAlreadyUsesTheirName(): void
    {
        $repo = new ReadListFakeRepository([]);
        $em = new ReadListFakeEntityManager($repo);
        $service = $this->createService(new ReadListFakeContainer($em), ReadListEntity::class);

        $qb = $this->createMock(QueryBuilder::class);
        $qb->method('getParameters')->willReturn(new \Doctrine\Common\Collections\ArrayCollection([
            new \Doctrine\ORM\Query\Parameter('scope', 1),
        ]));
        $qb->expects(self::once())
            ->method('setParameter')
            ->with('scope_filter_1', 2)
            ->willReturn($qb);

        $parameter = new class {
            public function getName(): string { return 'scope'; }
            public function getValue(): int { return 2; }
        };
        $method = new \ReflectionMethod($service, 'bindFilterParameters');
        $dql = $method->invoke($service, $qb, 'SELECT f.id FROM Entity f WHERE f.scope = :scope', [$parameter]);

        self::assertSame('SELECT f.id FROM Entity f WHERE f.scope = :scope_filter_1', $dql);
    }

    public function testListWithFilterErrorFallsBackToLegacyFilterAndSorter(): void
    {
        $alpha = new ReadListEntity(1, 'alpha');
        $beta = new ReadListEntity(2, 'beta');
        $repo = new ReadListFakeRepository([1 => $alpha, 2 => $beta]);
        $em = new ReadListFakeEntityManager($repo);
        $em->setQueryResults([$beta, $alpha]);
        $request = new Request([
            '@filter' => 'entity.getName() == "alpha"',
            '@sort' => 'x.getId() > y.getId()',
        ]);
        $container = new ReadListFakeContainer($em, $this->createRequestStack($request), $this->createAdminUser());

        $expressionService = $this->createMock(ExpressionServiceInterface::class);
        $expressionService->method('buildFilter')->willThrowException(new \RuntimeException('unsupported filter'));

        $service = $this->createService($container, ReadListEntity::class, $expressionService, new LegacyEvaluator());
        $result = $service->list(null, null, false);

        self::assertSame([$alpha], array_values($result));
    }

    public function testListRejectsLegacyFilterFallbackForNonAdmins(): void
    {
        $repo = new ReadListFakeRepository([]);
        $em = new ReadListFakeEntityManager($repo);
        $container = new ReadListFakeContainer(
            $em,
            $this->createRequestStack(new Request(['@filter' => 'entity.setName("changed")'])),
            $this->createUserWithRoles(['ROLE_USER']),
        );
        $expressionService = $this->createMock(ExpressionServiceInterface::class);
        $expressionService->method('buildFilter')->willThrowException(new \RuntimeException('unsupported filter'));
        $service = $this->createService($container, ReadListEntity::class, $expressionService, new LegacyEvaluator());

        $this->expectException(AccessDeniedHttpException::class);
        $service->list(null, null, false);
    }

    private function createRequestStack(Request $request): RequestStack
    {
        $stack = new RequestStack();
        $stack->push($request);

        return $stack;
    }

    public function testListRejectsPrivilegedParametersForNonAdmins(): void
    {
        foreach (['@dql' => 'SELECT i.id FROM Items i', '@sort' => 'x.getId()', '@hints' => '{}'] as $parameter => $value) {
            $repo = new ReadListFakeRepository([]);
            $em = new ReadListFakeEntityManager($repo);
            $container = new ReadListFakeContainer($em, $this->createRequestStack(new Request([$parameter => $value])));
            $service = $this->createService($container, ReadListEntity::class);

            try {
                $service->list(null, null, false);
                self::fail(sprintf('%s should require ROLE_ADMIN.', $parameter));
            } catch (AccessDeniedHttpException) {
                self::addToAssertionCount(1);
            }
        }
    }

    public function testListRejectsShowDqlOutsideDevelopment(): void
    {
        $repo = new ReadListFakeRepository([]);
        $em = new ReadListFakeEntityManager($repo);
        $container = new ReadListFakeContainer($em, $this->createRequestStack(new Request(['@showDQL' => '1'])));
        $service = $this->createService($container, ReadListEntity::class);

        $this->expectException(AccessDeniedHttpException::class);
        $service->list(null, null, false);
    }

    public function testListRejectsShowDqlInNamedNonDevelopmentEnvironment(): void
    {
        $repo = new ReadListFakeRepository([]);
        $em = new ReadListFakeEntityManager($repo);
        $container = new ReadListFakeContainer($em, $this->createRequestStack(new Request(['@showDQL' => '1'])), null, 'prod');
        $service = $this->createService($container, ReadListEntity::class);

        $this->expectException(AccessDeniedHttpException::class);
        $service->list(null, null, false);
    }

    public function testListRejectsIdentitySelectPaths(): void
    {
        $repo = new ReadListFakeRepository([]);
        $em = new ReadListFakeEntityManager($repo);
        $container = new ReadListFakeContainer($em, $this->createRequestStack(new Request(['@select' => 'entity.user.password'])));
        $service = $this->createService($container, ReadListEntity::class);

        $this->expectException(AccessDeniedHttpException::class);
        $service->list(null, null, false);
    }

    public function testListRejectsSelectsForIdentityEntities(): void
    {
        $repo = new ReadListFakeRepository([]);
        $em = new ReadListFakeEntityManager($repo);
        $container = new ReadListFakeContainer($em, $this->createRequestStack(new Request(['@select' => 'entity.username'])));
        $service = $this->createService($container, 'App\\Identity\\Entity\\User');

        $this->expectException(AccessDeniedHttpException::class);
        $service->list(null, null, false);
    }

    public function testListRejectsNonStringSelects(): void
    {
        $repo = new ReadListFakeRepository([]);
        $em = new ReadListFakeEntityManager($repo);
        $container = new ReadListFakeContainer($em, $this->createRequestStack(new Request(['@select' => ['entity.name']])));
        $service = $this->createService($container, ReadListEntity::class);

        $this->expectException(ValidatorException::class);
        $service->list(null, null, false);
    }

    private function createAdminUser(): UserInterface
    {
        return $this->createUserWithRoles(['ROLE_ADMIN']);
    }

    /** @param list<string> $roles */
    private function createUserWithRoles(array $roles): UserInterface
    {
        $user = $this->createMock(UserInterface::class);
        $user->method('getRoles')->willReturn($roles);

        return $user;
    }
}

// -------------------------------------------------------
//  Fake dependencies
// -------------------------------------------------------

final class ReadListEntity
{
    public function __construct(private ?int $id = null, private string $name = '', private string $uuid = '') {}
    public function getId(): ?int { return $this->id; }
    public function getName(): string { return $this->name; }
    public function getUuid(): string { return $this->uuid; }
    public function setName(string $name): self { $this->name = $name; return $this; }
}

final class ReadListFakeRepository
{
    public function __construct(private array $byId) {}
    public function find($id): ?object { return $this->byId[$id] ?? null; }
    public function findOneBy(array $criteria): ?object
    {
        foreach ($this->byId as $entity) {
            $match = true;
            foreach ($criteria as $k => $v) {
                $getter = 'get' . ucfirst($k);
                if (!method_exists($entity, $getter) || $entity->$getter() !== $v) { $match = false; break; }
            }
            if ($match) return $entity;
        }
        return null;
    }
}

final class ReadListFakeEntityManager
{
    private array $queryResults = [];
    public ?object $lastQueryBuilder = null;
    public ?object $lastQuery = null;

    public function __construct(private readonly ReadListFakeRepository $repo) {}

    public function setQueryResults(array $results): void { $this->queryResults = $results; }

    public function getRepository(string $class): ReadListFakeRepository { return $this->repo; }

    public function getClassMetadata(string $class): object
    {
        return new class {
            public function hasField(string $field): bool { return $field === 'uuid'; }
        };
    }

    public function createQuery(string $dql): object
    {
        return new class ($dql) { public function __construct(private readonly string $dql) {} public function getDQL(): string { return $this->dql; } };
    }

    public function createQueryBuilder(): object
    {
        $results = $this->queryResults;
        $qb = new class ($results, $this) {
            private array $wheres = [];
            public array $params = [];
            private string $alias = 'entity';
            public $selectClause = null;
            public array $orderBy = [];
            private array $joins = [];
            public ?string $groupByClause = null;

            public function __construct(private array $results, private readonly ReadListFakeEntityManager $em) {}
            public function select($s): self { $this->selectClause = $s; return $this; }
            public function from(string $from, string $alias): self { return $this; }
            public function where(mixed $condition): self { return $this; }
            public function andWhere(mixed $condition): self { $this->wheres[] = $condition; return $this; }
            public function setParameter(string $name, mixed $value): self { $this->params[$name] = $value; return $this; }
            public function addOrderBy(string $field, string $order): self { $this->orderBy[$field] = $order; return $this; }
            public function addGroupBy(string $group): self { $this->groupByClause = $group; return $this; }
            public function leftJoin(string $join, string $alias): self { $this->joins[$alias] = $join; return $this; }
            public function getRootAliases(): array { return [$this->alias]; }
            public function getDQL(): string { return 'SELECT ...'; }
            public function getQuery(): object
            {
                $query = new class ($this->results) {
                    public array $hints = [];
                    public function __construct(private array $results) {}
                    public function setHint(string $k, mixed $v): void { $this->hints[$k] = $v; }
                    public function getResult(): array { return $this->results; }
                    public function getSingleResult(): mixed { return $this->results[0] ?? null; }
                };
                $this->em->lastQuery = $query;
                return $query;
            }
        };
        $this->lastQueryBuilder = $qb;

        return $qb;
    }
}

final class ReadListFakeContainer implements ContainerInterface
{
    public function __construct(
        private readonly ReadListFakeEntityManager $em,
        private readonly ?RequestStack $requestStack = null,
        private readonly ?UserInterface $user = null,
        private readonly ?string $environment = null,
    ) {}

    public function get(string $id, int $invalidBehavior = self::EXCEPTION_ON_INVALID_REFERENCE): ?object
    {
        return match ($id) {
            'doctrine.orm.entity_manager' => $this->em,
            'logger' => new NullLogger(),
            'request_stack' => $this->requestStack ?? new RequestStack(),
            'security.token_storage' => new class($this->user) {
                public function __construct(private readonly ?UserInterface $user) {}
                public function getToken(): ?object
                {
                    return $this->user === null ? null : new class($this->user) {
                        public function __construct(private readonly UserInterface $user) {}
                        public function getUser(): UserInterface { return $this->user; }
                    };
                }
            },
            'serializer' => new \Symfony\Component\Serializer\Serializer([
                new \Symfony\Component\Serializer\Normalizer\ObjectNormalizer()
            ], [new \Symfony\Component\Serializer\Encoder\JsonEncoder()]),
            'validator' => null,
            default => null,
        };
    }

    public function has(string $id): bool { return true; }
    public function initialized(string $id): bool { return true; }
    public function set(string $id, ?object $service): void {}
    public function getParameter(string $name): array|bool|string|int|float|\UnitEnum|null { return $name === 'kernel.environment' ? $this->environment : null; }
    public function hasParameter(string $name): bool { return $name === 'kernel.environment' && $this->environment !== null; }
    public function setParameter(string $name, array|bool|string|int|float|\UnitEnum|null $value): void {}
}
