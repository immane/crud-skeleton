<?php

declare(strict_types=1);

namespace App\Tests\UnitTest\Core\Controller;

use App\Core\Controller\RestController;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Serializer\SerializerInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Covers the remaining error/edge branches of RestController: dependency
 * resolution failures, getService(), requestProcess() expand/display edge
 * cases, and the display expression exception path.
 */
#[AllowMockObjectsWithoutExpectations]
final class RestControllerCoverage2Test extends TestCase
{
    private function s(): SerializerInterface
    {
        return new class implements SerializerInterface {
            public function serialize(mixed $data, string $format, array $context = []): string
            {
                return json_encode($data, JSON_THROW_ON_ERROR);
            }

            public function deserialize(mixed $data, string $type, string $format, array $context = []): mixed
            {
                return null;
            }
        };
    }

    private function t(): TranslatorInterface
    {
        return new class implements TranslatorInterface {
            public function trans(?string $id, array $p = [], ?string $d = null, ?string $l = null): string
            {
                return (string) $id;
            }

            public function getLocale(): string
            {
                return 'en';
            }
        };
    }

    private function createController(Request $request): RestController
    {
        $stack = new RequestStack();
        $stack->push($request);

        return $this->createControllerWithStack($stack);
    }

    private function createControllerWithStack(?RequestStack $stack = null): RestController
    {
        return new class($stack, $this->s(), $this->t()) extends RestController {
            public function __construct(?RequestStack $rs, SerializerInterface $s, TranslatorInterface $t)
            {
                parent::__construct($rs, $s, $t);
            }

            public function publicSuccess(mixed $content = '', string $msg = 'SUCCESS', int $status = 200): Response
            {
                return $this->success($content, $msg, $status);
            }
        };
    }

    public function testResolveServiceThrowsWhenNoServiceContainer(): void
    {
        $controller = new class extends RestController {
            public function pubResolveService(string $id): object
            {
                return $this->resolveService($id);
            }
        };

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Service "foo" is not available.');
        $controller->pubResolveService('foo');
    }

    public function testResolveServiceThrowsWhenServiceMissingFromContainer(): void
    {
        $container = $this->createMock(ContainerInterface::class);
        $container->method('has')->with('missing')->willReturn(false);

        $controller = new class extends RestController {
            public function pubResolveService(string $id): object
            {
                return $this->resolveService($id);
            }
        };
        $controller->setServiceContainer($container);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Service "missing" is not available.');
        $controller->pubResolveService('missing');
    }

    public function testResolveServiceReturnsServiceFromContainer(): void
    {
        $service = new \stdClass();
        $container = $this->createMock(ContainerInterface::class);
        $container->method('has')->with('found')->willReturn(true);
        $container->method('get')->with('found')->willReturn($service);

        $controller = new class extends RestController {
            public function pubResolveService(string $id): object
            {
                return $this->resolveService($id);
            }
        };
        $controller->setServiceContainer($container);

        self::assertSame($service, $controller->pubResolveService('found'));
    }

    #[Group('low-value')]
    public function testGetServiceThrowsWhenServicePropertyMissing(): void
    {
        $controller = new class extends RestController {
            public function pubGetService(): object
            {
                return $this->getService();
            }
        };

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Controller service is not available.');
        $controller->pubGetService();
    }

    public function testGetServiceReturnsDeclaredService(): void
    {
        $service = new \stdClass();
        $controller = new class extends RestController {
            public $service = null;

            public function pubGetService(): object
            {
                return $this->getService();
            }
        };
        $controller->service = $service;

        self::assertSame($service, $controller->pubGetService());
    }

    #[Group('low-value')]
    public function testGetRequestStackThrowsWhenNotInjected(): void
    {
        $controller = new class extends RestController {
            public function pubGetRequestStack(): RequestStack
            {
                return $this->getRequestStack();
            }
        };

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('RequestStack is not available in RestController');
        $controller->pubGetRequestStack();
    }

    #[Group('low-value')]
    public function testGetSerializerThrowsWhenNotInjected(): void
    {
        $controller = new class extends RestController {
            public function pubGetSerializer(): SerializerInterface
            {
                return $this->getSerializer();
            }
        };

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Serializer is not available in RestController');
        $controller->pubGetSerializer();
    }

    #[Group('low-value')]
    public function testGetTranslatorThrowsWhenNotInjected(): void
    {
        $controller = new class extends RestController {
            public function pubGetTranslator(): TranslatorInterface
            {
                return $this->getTranslator();
            }
        };

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Translator is not available in RestController');
        $controller->pubGetTranslator();
    }

    public function testExpandsWithEntityPrefixedAttributeShiftsChain(): void
    {
        $child = new RestCovChild();
        $entity = new RestCovExpandEntity($child);

        $req = Request::create('/api/test', 'GET', ['@expands' => '["entity.child"]']);
        $c = $this->createController($req);
        $r = $c->publicSuccess([$entity]);

        self::assertSame(200, $r->getStatusCode());
        self::assertNull($child->__metadata);
    }

    public function testExpandsCatchesGetterExceptionSilently(): void
    {
        $entity = new RestCovExpandThrowingEntity();

        $req = Request::create('/api/test', 'GET', ['@expands' => '["boom"]']);
        $c = $this->createController($req);
        $r = $c->publicSuccess([$entity]);

        $body = json_decode((string) $r->getContent(), true);
        self::assertSame(200, $r->getStatusCode());
        self::assertIsArray($body['data']);
    }

    public function testDisplayTraversesIntermediateArrays(): void
    {
        $entity = new RestCovArrayEntity();

        $req = Request::create('/api/test', 'GET', ['@display' => '["data.foo"]']);
        $c = $this->createController($req);
        $r = $c->publicSuccess([$entity]);

        $body = json_decode((string) $r->getContent(), true);
        self::assertSame('bar', $body['data'][0]['data.foo']);
    }

    public function testDisplayExpressionCatchSwallowsEvaluationErrors(): void
    {
        $entity = new RestCovBoomEntity();

        $req = Request::create('/api/test', 'GET', ['@display' => '{"val":"entity.getBoom()"}']);
        $c = $this->createController($req);
        $r = $c->publicSuccess([$entity]);

        $body = json_decode((string) $r->getContent(), true);
        self::assertSame(200, $r->getStatusCode());
        self::assertSame([], $body['data'][0]);
    }
}

final class RestCovChild
{
    public $__metadata = null;

    public function getId(): int
    {
        return 1;
    }
}

final class RestCovExpandEntity
{
    public $__metadata = null;

    public function __construct(private readonly RestCovChild $child)
    {
    }

    public function getId(): int
    {
        return 1;
    }

    public function getChild(): RestCovChild
    {
        return $this->child;
    }
}

final class RestCovExpandThrowingEntity
{
    public $__metadata = null;

    public function getId(): int
    {
        return 1;
    }

    public function getBoom(): object
    {
        throw new \RuntimeException('expand boom');
    }
}

final class RestCovArrayEntity
{
    public function getId(): int
    {
        return 1;
    }

    /** @return array<string, string> */
    public function getData(): array
    {
        return ['foo' => 'bar'];
    }
}

final class RestCovBoomEntity
{
    public function getId(): int
    {
        return 1;
    }

    public function getBoom(): int
    {
        throw new \RuntimeException('expression boom');
    }
}
