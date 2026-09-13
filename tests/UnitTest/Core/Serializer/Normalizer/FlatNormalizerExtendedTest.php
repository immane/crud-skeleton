<?php

namespace App\Tests\UnitTest\Core\Serializer\Normalizer;

use App\Core\Serializer\Normalizer\FlatNormalizer;
use App\Core\Serializer\ExpansionMetadata;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Symfony\Component\PropertyAccess\PropertyAccess;
use Symfony\Component\Serializer\Normalizer\ObjectNormalizer;

final class FlatNormalizerExtendedTest extends TestCase
{
    private function createNormalizer(): FlatNormalizer
    {
        $objectNormalizer = new ObjectNormalizer();
        $accessor = PropertyAccess::createPropertyAccessor();
        return new FlatNormalizer($objectNormalizer, $accessor, new ExpansionMetadata());
    }

    public function testNormalizeAddsToString(): void
    {
        $normalizer = $this->createNormalizer();
        $obj = new class {
            public function getId(): int { return 1; }
            public function getName(): string { return 'Test'; }
            public function __toString(): string { return 'TheTest'; }
        };

        $result = $normalizer->normalize($obj, 'json');

        self::assertIsArray($result);
        self::assertArrayHasKey('id', $result);
        self::assertSame(1, $result['id']);
        self::assertArrayHasKey('__toString', $result);
        self::assertSame('TheTest', $result['__toString']);
    }

    public function testSupportsNormalizationForObjects(): void
    {
        $normalizer = $this->createNormalizer();

        self::assertTrue($normalizer->supportsNormalization(new \stdClass()));
    }

    public function testGetSupportedTypes(): void
    {
        $normalizer = $this->createNormalizer();
        $types = $normalizer->getSupportedTypes('json');

        self::assertIsArray($types);
        self::assertArrayHasKey('object', $types);
        self::assertTrue($types['object']);
    }

    public function testSupportsDenormalization(): void
    {
        $normalizer = $this->createNormalizer();
        self::assertTrue($normalizer->supportsDenormalization([], \stdClass::class, 'json'));
    }

    public function testDenormalizeProducesObject(): void
    {
        $normalizer = $this->createNormalizer();
        $result = $normalizer->denormalize(['name' => 'test'], \stdClass::class);
        self::assertInstanceOf(\stdClass::class, $result);
    }

    #[Group('low-value')]
    public function testSetSerializerDoesNotThrow(): void
    {
        $normalizer = $this->createNormalizer();
        $serializer = $this->createMock(\Symfony\Component\Serializer\SerializerInterface::class);
        $normalizer->setSerializer($serializer);
        self::assertTrue(true);
    }

    #[Group('low-value')]
    public function testSetNormalizerDoesNotThrow(): void
    {
        $normalizer = $this->createNormalizer();
        $inner = $this->createMock(\Symfony\Component\Serializer\Normalizer\NormalizerInterface::class);
        $normalizer->setNormalizer($inner);
        self::assertTrue(true);
    }

    public function testNormalizeNullReturnsNull(): void
    {
        $normalizer = $this->createNormalizer();
        // Test that null (not an object) is not treated as a normalizable object
        self::assertFalse($normalizer->supportsNormalization(null));
        self::assertFalse($normalizer->supportsNormalization(123));
        self::assertFalse($normalizer->supportsNormalization('string'));
    }
}
