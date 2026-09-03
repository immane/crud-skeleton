<?php

declare(strict_types=1);

namespace App\Tests\UnitTest\Core\View;

use App\Core\Controller\RestController;
use App\Core\Service\BaseServiceInterface;
use App\Core\View\ApiView;
use App\Core\View\ApiViewMessages;
use App\Core\View\WorkflowApiViewMixin;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\Container;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Serializer\Encoder\JsonEncoder;
use Symfony\Component\Serializer\Normalizer\ObjectNormalizer;
use Symfony\Component\Serializer\Serializer;
use Symfony\Component\Translation\Translator;
use Symfony\Component\Workflow\WorkflowInterface;

final class WorkflowApiViewMixinTest extends TestCase
{
    private function createController(WorkflowFakeService $service, WorkflowInterface $workflow, object $doctrine): object
    {
        $controller = new class($service) extends RestController {
            use ApiView, WorkflowApiViewMixin;

            protected WorkflowFakeService $service;
            protected string $workflow = 'workflow.test';

            public function __construct(WorkflowFakeService $service)
            {
                $this->service = $service;
            }
        };

        $requestStack = new RequestStack();
        $requestStack->push(Request::create('/', 'GET'));
        $serializer = new Serializer([new ObjectNormalizer()], [new JsonEncoder()]);

        $container = new Container();
        $container->set('workflow.test', $workflow);
        $container->set('doctrine', $doctrine);
        $container->set('request_stack', $requestStack);
        $container->set('serializer', $serializer);
        $container->set('translator', new Translator('en'));

        $controller->setContainer($container);
        $controller->setRequestStack($requestStack);
        $controller->setSerializer($serializer);
        $controller->setTranslator(new Translator('en'));

        return $controller;
    }

    private function createDoctrine(): object
    {
        $manager = new class {
            public bool $flushed = false;

            public function flush(): void
            {
                $this->flushed = true;
            }
        };

        return new class($manager) {
            public function __construct(public object $manager)
            {
            }

            public function getManager(): object
            {
                return $this->manager;
            }
        };
    }

    private function createWorkflow(): WorkflowInterface
    {
        return $this->createStub(WorkflowInterface::class);
    }

    // ──────────────────────── todoAction ────────────────────────

    public function testTodoActionReturnsOnlyEntitiesWithEnabledTransitions(): void
    {
        $keep = new WorkflowTestEntity(1);
        $drop = new WorkflowTestEntity(2);

        $service = new WorkflowFakeService();
        $service->listResult = [$keep, $drop];
        $service->lastListArgs = null;

        $workflow = $this->createMock(WorkflowInterface::class);
        $workflow->expects(self::exactly(2))->method('getEnabledTransitions')->willReturnCallback(
            static fn (object $entity): array => $entity === $keep ? [new WorkflowTestTransition('next')] : []
        );

        $controller = $this->createController($service, $workflow, $this->createDoctrine());
        $response = $controller->todoAction();

        self::assertSame(200, $response->getStatusCode());
        self::assertStringContainsString('"id":1', $response->getContent());
        self::assertStringNotContainsString('"id":2', $response->getContent());
        self::assertSame([null, null, false], $service->lastListArgs);
    }

    public function testTodoActionHandlesEmptyList(): void
    {
        $service = new WorkflowFakeService();
        $service->listResult = [];

        $workflow = $this->createMock(WorkflowInterface::class);
        $workflow->expects(self::never())->method('getEnabledTransitions');

        $controller = $this->createController($service, $workflow, $this->createDoctrine());
        $response = $controller->todoAction();

        self::assertSame(200, $response->getStatusCode());
    }

    // ────────────────── availableTransitionsAction ──────────────────

    public function testAvailableTransitionsActionReturnsTransitions(): void
    {
        $entity = new WorkflowTestEntity(5);
        $transition = new WorkflowTestTransition('advance');

        $service = new WorkflowFakeService();
        $service->getResult = $entity;

        $workflow = $this->createMock(WorkflowInterface::class);
        $workflow->expects(self::once())->method('getEnabledTransitions')->with($entity)->willReturn([$transition]);

        $controller = $this->createController($service, $workflow, $this->createDoctrine());
        $response = $controller->availableTransitionsAction(5);

        self::assertSame(200, $response->getStatusCode());
        self::assertStringContainsString('advance', $response->getContent());
        self::assertSame(['id' => 5], $service->lastGetCriteria);
    }

    public function testAvailableTransitionsActionMissingEntityThrowsTypeError(): void
    {
        $service = new WorkflowFakeService();
        $service->getResult = null;

        $workflow = $this->createWorkflow();

        $controller = $this->createController($service, $workflow, $this->createDoctrine());
        $response = $controller->availableTransitionsAction(99);

        self::assertSame(404, $response->getStatusCode());
        self::assertStringContainsString(ApiViewMessages::ENTITY_NOT_FOUND, $response->getContent());
    }

    // ────────────────── doTransitionAction ──────────────────

    public function testDoTransitionActionHappyPathAppliesAndUpdates(): void
    {
        $entity = new WorkflowTestEntity(1);

        $service = new WorkflowFakeService();
        $service->getResult = $entity;

        $workflow = $this->createMock(WorkflowInterface::class);
        $workflow->expects(self::once())->method('can')->with($entity, 'approve')->willReturn(true);
        $workflow->expects(self::once())->method('apply')->with($entity, 'approve');

        $controller = $this->createController($service, $workflow, $this->createDoctrine());
        $request = Request::create('/', 'POST', server: ['CONTENT_TYPE' => 'application/json'], content: '{"note":"hi"}');
        $response = $controller->doTransitionAction($request, 1, 'approve');

        self::assertSame(200, $response->getStatusCode());
        self::assertTrue($service->transactionRan);
        self::assertSame(1, $service->updateCalls);
        self::assertSame(['note' => 'hi'], $service->updatedData);
    }

    public function testDoTransitionActionCannotTransitionReturnsWarning(): void
    {
        $entity = new WorkflowTestEntity(1);

        $service = new WorkflowFakeService();
        $service->getResult = $entity;

        $workflow = $this->createMock(WorkflowInterface::class);
        $workflow->expects(self::once())->method('can')->with($entity, 'approve')->willReturn(false);
        $workflow->expects(self::never())->method('apply');

        $controller = $this->createController($service, $workflow, $this->createDoctrine());
        $request = Request::create('/', 'POST', server: ['CONTENT_TYPE' => 'application/json'], content: '{"note":"hi"}');
        $response = $controller->doTransitionAction($request, 1, 'approve');

        self::assertSame(400, $response->getStatusCode());
        self::assertStringContainsString(ApiViewMessages::TRANSITION_CANNOT_APPLY, $response->getContent());
        self::assertSame(0, $service->updateCalls);
    }

    public function testDoTransitionActionMalformedJsonSkipsUpdate(): void
    {
        $entity = new WorkflowTestEntity(1);

        $service = new WorkflowFakeService();
        $service->getResult = $entity;

        $workflow = $this->createMock(WorkflowInterface::class);
        $workflow->method('can')->willReturn(true);
        $workflow->expects(self::once())->method('apply')->with($entity, 'approve');

        $controller = $this->createController($service, $workflow, $this->createDoctrine());
        $request = Request::create('/', 'POST', server: ['CONTENT_TYPE' => 'application/json'], content: 'not-json');
        $response = $controller->doTransitionAction($request, 1, 'approve');

        self::assertSame(200, $response->getStatusCode());
        self::assertSame(0, $service->updateCalls);
    }

    public function testDoTransitionActionExceptionReturnsWarning(): void
    {
        $entity = new WorkflowTestEntity(1);

        $service = new WorkflowFakeService();
        $service->getResult = $entity;

        $workflow = $this->createWorkflow();
        $workflow->method('can')->willReturn(true);
        $workflow->method('apply')->willThrowException(new \RuntimeException('workflow exploded'));

        $controller = $this->createController($service, $workflow, $this->createDoctrine());
        $request = Request::create('/', 'POST', server: ['CONTENT_TYPE' => 'application/json'], content: '{}');
        $response = $controller->doTransitionAction($request, 1, 'approve');

        self::assertSame(500, $response->getStatusCode());
        self::assertStringContainsString('workflow exploded', $response->getContent());
    }

    public function testDoTransitionActionMissingEntityWarnsAboutTypeError(): void
    {
        $service = new WorkflowFakeService();
        $service->getResult = null;

        $workflow = $this->createWorkflow();

        $controller = $this->createController($service, $workflow, $this->createDoctrine());
        $request = Request::create('/', 'POST', server: ['CONTENT_TYPE' => 'application/json'], content: '{}');
        $response = $controller->doTransitionAction($request, 404, 'approve');

        self::assertSame(404, $response->getStatusCode());
        self::assertStringContainsString(ApiViewMessages::ENTITY_NOT_FOUND, $response->getContent());
    }

    // ────────────────── resetMarkingAction ──────────────────

    public function testResetMarkingActionClearsStatusAndFlushes(): void
    {
        $entity = new WorkflowTestEntity(1);
        $entity->status = ['current' => ['on'], 'places' => []];

        $service = new WorkflowFakeService();
        $service->getResult = $entity;
        $workflow = $this->createWorkflow();
        $doctrine = $this->createDoctrine();

        $controller = $this->createController($service, $workflow, $doctrine);
        $response = $controller->resetMarkingAction(1);

        self::assertSame([], $entity->status);
        self::assertTrue($doctrine->getManager()->flushed);
        self::assertSame(200, $response->getStatusCode());
        self::assertSame(['id' => 1], $service->lastGetCriteria);
    }

    public function testResetMarkingRoutePlaceholderMatchesActionArgument(): void
    {
        $controller = $this->createController(
            new WorkflowFakeService(),
            $this->createWorkflow(),
            $this->createDoctrine(),
        );

        $method = new \ReflectionMethod($controller, 'resetMarkingAction');
        $attributes = $method->getAttributes(Route::class);
        self::assertNotEmpty($attributes, 'resetMarkingAction must be routed');

        /** @var Route $route */
        $route = $attributes[0]->newInstance();
        $path = method_exists($route, 'getPath') ? $route->getPath() : ($route->path ?? '');
        preg_match_all('/\{(\w+)\}/', (string) $path, $matches);
        $variables = $matches[1] ?? [];

        $argumentNames = array_map(
            static fn (\ReflectionParameter $p): string => $p->getName(),
            $method->getParameters(),
        );

        foreach ($variables as $variable) {
            self::assertContains(
                $variable,
                $argumentNames,
                sprintf('Route placeholder {%s} must map to a controller argument', $variable),
            );
        }
    }
}

final class WorkflowFakeService implements BaseServiceInterface
{
    public array $listResult = [];
    public ?object $getResult = null;
    public ?array $lastListArgs = null;
    public ?array $lastGetCriteria = null;
    public int $updateCalls = 0;
    public array $updatedData = [];
    public bool $transactionRan = false;

    public function get($object, bool $directly = false)
    {
        $this->lastGetCriteria = is_array($object) ? $object : null;

        return $this->getResult;
    }

    public function list($object = null, $order = null, bool $disableRequest = true)
    {
        $this->lastListArgs = [$object, $order, $disableRequest];

        return $this->listResult;
    }

    public function new()
    {
        return new \stdClass();
    }

    public function update($object, ?array $data = null, bool $noFlush = false)
    {
        ++$this->updateCalls;
        $this->updatedData = $data ?? [];

        return $object;
    }

    public function remove($object): bool
    {
        return true;
    }

    public function wrapInTransaction(callable $fn): mixed
    {
        $this->transactionRan = true;

        return $fn(new \stdClass());
    }
}

final class WorkflowTestEntity
{
    public array $status = [];

    public function __construct(public int $id)
    {
    }

    public function getId(): int
    {
        return $this->id;
    }

    public function setStatus(array $status): void
    {
        $this->status = $status;
    }
}

final class WorkflowTestTransition
{
    public function __construct(public string $name)
    {
    }

    public function getName(): string
    {
        return $this->name;
    }
}
