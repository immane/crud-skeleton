<?php

namespace App\Tests\UnitTest\Core\Controller;

use App\Core\Controller\RestController;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Serializer\SerializerInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

class TestableRestController extends RestController
{
    public function callSuccess($content = '', string $addition_message = 'SUCCESS')
    {
        return parent::success($content, $addition_message);
    }

    public function callWarning(string $error_msg = self::UNKNOWN_ERROR, int $error_code = -1, $raw_data = '')
    {
        return parent::warning($error_msg, $error_code, $raw_data);
    }

    public function callSuccessWithStatus($content = '', string $message = 'SUCCESS', int $status = 200)
    {
        return parent::success($content, $message, $status);
    }
}


#[AllowMockObjectsWithoutExpectations]
class RestControllerTest extends TestCase
{
    public function testSuccessReturnsSerializedJsonResponse()
    {
        $request = Request::create('/', 'GET');
        $requestStack = new RequestStack();
        $requestStack->push($request);

        $serializer = $this->createMock(SerializerInterface::class);
        $serializer->method('serialize')->willReturnCallback(function ($data, $format) {
            // mimic serializer by returning json-encoded data for assertion
            return json_encode($data);
        });

        $translator = $this->createMock(TranslatorInterface::class);
        $translator->method('trans')->willReturnArgument(0);

        $controller = new TestableRestController($requestStack, $serializer, $translator);

        $content = [ 'foo' => 'bar' ];
        $resp = $controller->callSuccess($content, 'OK');

        $this->assertEquals(200, $resp->getStatusCode());

        $decoded = json_decode($resp->getContent(), true);
        $this->assertIsArray($decoded);
        $this->assertArrayHasKey('data', $decoded);
        $this->assertEquals($content, $decoded['data']);
        $this->assertEquals(0, $decoded['code']);
        $this->assertEquals('OK', $decoded['message']);
    }

    public function testWarningReturnsTranslatedMessageAndRawData()
    {
        $request = Request::create('/', 'GET');
        $requestStack = new RequestStack();
        $requestStack->push($request);

        $serializer = $this->createMock(SerializerInterface::class);
        $serializer->method('serialize')->willReturnCallback(function ($data, $format) {
            return json_encode($data);
        });

        $translator = $this->createMock(TranslatorInterface::class);
        // translator should be called with the error message and return something
        $translator->expects($this->once())->method('trans')->with('MY_ERROR')->willReturn('TRANSLATED');

        $controller = new TestableRestController($requestStack, $serializer, $translator);

        $resp = $controller->callWarning('MY_ERROR', -5, ['x' => 1]);

        $this->assertEquals(200, $resp->getStatusCode());
        $decoded = json_decode($resp->getContent(), true);

        $this->assertArrayHasKey('message', $decoded);
        $this->assertEquals('TRANSLATED', $decoded['message']);
        $this->assertEquals(-5, $decoded['code']);
        $this->assertArrayHasKey('raw_data', $decoded);
        $this->assertEquals(['x' => 1], $decoded['raw_data']);
    }

    public function testPaginationUsesBuiltInPaginationOnGet()
    {
        $request = Request::create('/?page=2&limit=1', 'GET');
        $requestStack = new RequestStack();
        $requestStack->push($request);

        $serializer = $this->createMock(SerializerInterface::class);
        $serializer->method('serialize')->willReturnCallback(fn($data, $format) => json_encode($data));

        $translator = $this->createMock(TranslatorInterface::class);
        $translator->method('trans')->willReturnArgument(0);

        $controller = new TestableRestController($requestStack, $serializer, $translator);

        $content = [1,2,3];
        $resp = $controller->callSuccess($content, 'OK');
        $decoded = json_decode($resp->getContent(), true);

        $this->assertEquals([2], $decoded['data']);
        $this->assertArrayHasKey('paginator', $decoded);
        $this->assertSame(3, $decoded['paginator']['total']);
        $this->assertSame(2, $decoded['paginator']['page']);
        $this->assertSame(1, $decoded['paginator']['limit']);
    }

    public function testDisplayReduceProducesIdUuidAndToString()
    {
        $entity = new class {
            public function getId() { return 123; }
            public function getUuid() { return '550e8400-e29b-41d4-a716-446655440000'; }
            public function __toString() { return 'entity-123'; }
        };

        $request = Request::create('/?@display=reduce', 'GET');
        $requestStack = new RequestStack();
        $requestStack->push($request);

        $serializer = $this->createMock(SerializerInterface::class);
        $serializer->method('serialize')->willReturnCallback(fn($data, $format) => json_encode($data));

        $translator = $this->createMock(TranslatorInterface::class);
        $translator->method('trans')->willReturnArgument(0);

        $controller = new TestableRestController($requestStack, $serializer, $translator);

        $resp = $controller->callSuccess([$entity], 'OK');
        $decoded = json_decode($resp->getContent(), true);

        $this->assertIsArray($decoded['data']);
        $this->assertEquals([[
            'id' => 123,
            '__toString' => 'entity-123',
            'uuid' => '550e8400-e29b-41d4-a716-446655440000',
        ]], $decoded['data']);
    }

    public function testExpressionEvaluationInDisplay()
    {
        $entity = new class {
            public function getId() { return 42; }
        };

        $display = json_encode(['computed' => 'entity.getId()']);
        $request = Request::create('/?@display=' . rawurlencode($display), 'GET');
        $requestStack = new RequestStack();
        $requestStack->push($request);

        $serializer = $this->createMock(SerializerInterface::class);
        $serializer->method('serialize')->willReturnCallback(fn($data, $format) => json_encode($data));

        $translator = $this->createMock(TranslatorInterface::class);
        $translator->method('trans')->willReturnArgument(0);

        $controller = new TestableRestController($requestStack, $serializer, $translator);

        $resp = $controller->callSuccess([$entity], 'OK');
        $decoded = json_decode($resp->getContent(), true);

        $this->assertIsArray($decoded['data']);
        $this->assertArrayHasKey(0, $decoded['data']);
        $this->assertArrayHasKey('computed', $decoded['data'][0]);
        $this->assertEquals(42, $decoded['data'][0]['computed']);
    }

    #[Group('low-value')]
    public function testSuccessWith204ReturnsEmptyResponse(): void
    {
        $requestStack = new RequestStack();
        $requestStack->push(Request::create('/', 'DELETE'));

        $serializer = $this->createMock(SerializerInterface::class);
        $translator = $this->createMock(TranslatorInterface::class);

        $controller = new TestableRestController($requestStack, $serializer, $translator);
        $resp = $controller->callSuccessWithStatus('', 'SUCCESS', 204);

        self::assertSame(204, $resp->getStatusCode());
        self::assertEmpty($resp->getContent());
    }

    public function testWarningWithDefaultValues(): void
    {
        $requestStack = new RequestStack();
        $requestStack->push(Request::create('/', 'GET'));

        $serializer = $this->createMock(SerializerInterface::class);
        $serializer->method('serialize')->willReturnCallback(fn($data) => json_encode($data));
        $translator = $this->createMock(TranslatorInterface::class);
        $translator->method('trans')->willReturnArgument(0);

        $controller = new TestableRestController($requestStack, $serializer, $translator);
        $resp = $controller->callWarning();

        self::assertSame(200, $resp->getStatusCode());
        $body = json_decode((string) $resp->getContent(), true);
        self::assertSame('Api error occurred', $body['message']);
        self::assertSame(-1, $body['code']);
    }

    public function testSuccessWithArrayCollection(): void
    {
        $requestStack = new RequestStack();
        $requestStack->push(Request::create('/', 'GET'));

        $serializer = $this->createMock(SerializerInterface::class);
        $serializer->method('serialize')->willReturnCallback(fn($data) => json_encode($data));
        $translator = $this->createMock(TranslatorInterface::class);
        $translator->method('trans')->willReturnArgument(0);

        $controller = new TestableRestController($requestStack, $serializer, $translator);

        $collection = new \Doctrine\Common\Collections\ArrayCollection([1, 2, 3]);
        $resp = $controller->callSuccess($collection, 'OK');

        self::assertSame(200, $resp->getStatusCode());
        $body = json_decode((string) $resp->getContent(), true);
        self::assertSame([1, 2, 3], $body['data']);
    }

    public function testSuccessWithoutRequestReturnsNoPagination(): void
    {
        $requestStack = new RequestStack();
        $requestStack->push(Request::create('/', 'DELETE'));
        $serializer = $this->createMock(SerializerInterface::class);
        $serializer->method('serialize')->willReturnCallback(fn($data) => json_encode($data));
        $translator = $this->createMock(TranslatorInterface::class);
        $translator->method('trans')->willReturnArgument(0);

        $controller = new TestableRestController($requestStack, $serializer, $translator);
        $resp = $controller->callSuccess([1, 2], 'OK');

        self::assertSame(200, $resp->getStatusCode());
        $body = json_decode((string) $resp->getContent(), true);
        self::assertArrayNotHasKey('paginator', $body);
    }
}


