<?php

declare(strict_types=1);

namespace App\Tests\UnitTest\Core\Serializer\Normalizer;

use App\Core\Serializer\Normalizer\FlatNormalizer;
use App\Core\Serializer\ExpansionMetadata;
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use Symfony\Component\PropertyAccess\PropertyAccess;
use Symfony\Component\PropertyAccess\PropertyAccessorInterface;
use Symfony\Component\Serializer\Normalizer\NormalizerAwareInterface;
use Symfony\Component\Serializer\Normalizer\NormalizerInterface;
use Symfony\Component\Serializer\Normalizer\ObjectNormalizer;
use Symfony\Component\Serializer\SerializerAwareInterface;
use Symfony\Component\Serializer\SerializerInterface;

/**
 * Covers the remaining branches of FlatNormalizer not exercised by
 * FlatNormalizerExtendedTest.
 */
#[AllowMockObjectsWithoutExpectations]
final class FlatNormalizerCoverageTest extends TestCase
{
    public function testNormalizeNonObjectReturnsNull(): void
    {
        $normalizer = new FlatNormalizer(new ObjectNormalizer(), PropertyAccess::createPropertyAccessor(), new ExpansionMetadata());

        self::assertNull($normalizer->normalize(null, 'json'));
        self::assertNull($normalizer->normalize(123, 'json'));
        self::assertNull($normalizer->normalize('plain string', 'json'));
    }

    public function testNormalizeDoctrineOrmObjectWithToStringReturnsString(): void
    {
        $normalizer = new FlatNormalizer(new ObjectNormalizer(), PropertyAccess::createPropertyAccessor(), new ExpansionMetadata());

        $obj = new \Doctrine\ORM\Mapping\ClassMetadata('App\Some\Entity');

        $result = $normalizer->normalize($obj, 'json');

        self::assertIsString($result);
    }

    public function testNormalizeDecoratedFailureReturnsIdAndToString(): void
    {
        $decorated = $this->createMock(NormalizerInterface::class);
        $decorated->method('normalize')->willThrowException(new \RuntimeException('decorated boom'));
        $normalizer = new FlatNormalizer($decorated, $this->createMock(PropertyAccessorInterface::class), new ExpansionMetadata());

        $obj = new class {
            public function getId(): int
            {
                return 42;
            }

            public function getUuid(): string
            {
                return 'uuid-42';
            }

            public function __toString(): string
            {
                return 'id-42';
            }
        };

        $result = $normalizer->normalize($obj, 'json');

        self::assertSame(['id' => 42, 'uuid' => 'uuid-42', '__toString' => 'id-42'], $result);
    }

    public function testNormalizeDecoratedFailureFallsBackToClass(): void
    {
        $decorated = $this->createMock(NormalizerInterface::class);
        $decorated->method('normalize')->willThrowException(new \RuntimeException('decorated boom'));
        $normalizer = new FlatNormalizer($decorated, $this->createMock(PropertyAccessorInterface::class), new ExpansionMetadata());

        $result = $normalizer->normalize(new \stdClass(), 'json');

        self::assertSame(['__class' => \stdClass::class], $result);
    }

    public function testRelationReduceTransformUsesMetadataMethod(): void
    {
        $relation = new class {
            public function getId(): int
            {
                return 7;
            }

            public function getUuid(): string
            {
                return 'uuid-7';
            }

            public function __metadata(): array
            {
                return ['source' => 'method'];
            }

            public function __toString(): string
            {
                return 'relation-7';
            }
        };
        $obj = new class($relation) {
            public function __construct(public object $relation)
            {
            }
        };

        $decorated = $this->createMock(NormalizerInterface::class);
        $decorated->method('normalize')->willReturn(['relation' => ['id' => 7]]);
        $normalizer = new FlatNormalizer($decorated, PropertyAccess::createPropertyAccessor(), new ExpansionMetadata());

        $result = $normalizer->normalize($obj, 'json');

        self::assertSame(
            ['id' => 7, 'uuid' => 'uuid-7', '__toString' => 'relation-7', '__metadata' => ['source' => 'method']],
            $result['relation'],
        );
    }

    public function testRelationReduceTransformUsesMetadataProperty(): void
    {
        $relation = new class {
            public function getId(): int
            {
                return 8;
            }

            public function getUuid(): string
            {
                return 'uuid-8';
            }

            public array $__metadata = ['source' => 'property'];

            public function __toString(): string
            {
                return 'relation-8';
            }
        };
        $obj = new class($relation) {
            public function __construct(public object $relation)
            {
            }
        };

        $decorated = $this->createMock(NormalizerInterface::class);
        $decorated->method('normalize')->willReturn(['relation' => ['id' => 8]]);
        $normalizer = new FlatNormalizer($decorated, PropertyAccess::createPropertyAccessor(), new ExpansionMetadata());

        $result = $normalizer->normalize($obj, 'json');

        self::assertSame(
            ['id' => 8, 'uuid' => 'uuid-8', '__toString' => 'relation-8', '__metadata' => ['source' => 'property']],
            $result['relation'],
        );
    }

    public function testRelationReduceTransformUsesExpansionMetadataWithoutEntityMutation(): void
    {
        $relation = new class {
            public function getId(): int
            {
                return 9;
            }
        };
        $obj = new class($relation) {
            public function __construct(public object $relation)
            {
            }
        };

        $metadata = new ExpansionMetadata();
        $metadata->mark($relation);
        $decorated = $this->createMock(NormalizerInterface::class);
        $decorated->method('normalize')->willReturn(['relation' => ['id' => 9]]);
        $normalizer = new FlatNormalizer($decorated, PropertyAccess::createPropertyAccessor(), $metadata);

        $result = $normalizer->normalize($obj, 'json');

        self::assertSame($relation, $result['relation']['__metadata']);
        self::assertFalse(property_exists($relation, '__metadata'));
    }

    public function testNormalizeDecodesJsonStringAttribute(): void
    {
        $obj = new class {
            public string $meta = '{"a":1,"b":"x"}';
            public string $plain = 'not-json';
        };

        $decorated = $this->createMock(NormalizerInterface::class);
        $decorated->method('normalize')->willReturn([
            'meta' => '{"a":1,"b":"x"}',
            'plain' => 'not-json',
        ]);
        $normalizer = new FlatNormalizer($decorated, PropertyAccess::createPropertyAccessor(), new ExpansionMetadata());

        $result = $normalizer->normalize($obj, 'json');

        self::assertSame(['a' => 1, 'b' => 'x'], $result['meta']);
        self::assertSame('not-json', $result['plain']);
    }

    public function testDenormalizeThrowsWhenDecoratedCannotDenormalize(): void
    {
        $decorated = $this->createMock(NormalizerInterface::class);
        $normalizer = new FlatNormalizer($decorated, $this->createMock(PropertyAccessorInterface::class), new ExpansionMetadata());

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('Decorated normalizer cannot denormalize values.');
        $normalizer->denormalize(['name' => 'x'], \stdClass::class, 'json');
    }

    public function testSetSerializerForwardsAsNormalizerToNormalizerAwareDecorated(): void
    {
        $decorated = new NormalizerAwareOnlyNormalizerStub();
        $normalizer = new FlatNormalizer($decorated, $this->createMock(PropertyAccessorInterface::class), new ExpansionMetadata());

        $serializer = new SerializerAndNormalizerStub();
        $normalizer->setSerializer($serializer);

        self::assertSame($serializer, $decorated->normalizer);
    }

    public function testSetNormalizerForwardsToNormalizerAwareDecorated(): void
    {
        $decorated = new NormalizerAwareOnlyNormalizerStub();
        $normalizer = new FlatNormalizer($decorated, $this->createMock(PropertyAccessorInterface::class), new ExpansionMetadata());

        $inner = $this->createMock(NormalizerInterface::class);
        $normalizer->setNormalizer($inner);

        self::assertSame($inner, $decorated->normalizer);
    }
}

final class NormalizerAwareOnlyNormalizerStub implements NormalizerInterface, NormalizerAwareInterface
{
    public ?NormalizerInterface $normalizer = null;

    public function normalize(mixed $data, ?string $format = null, array $context = []): array|string|int|float|bool|\ArrayObject|null
    {
        return [];
    }

    public function supportsNormalization(mixed $data, ?string $format = null, array $context = []): bool
    {
        return true;
    }

    public function getSupportedTypes(?string $format): array
    {
        return ['object' => true];
    }

    public function setNormalizer(NormalizerInterface $normalizer): void
    {
        $this->normalizer = $normalizer;
    }
}

final class SerializerAndNormalizerStub implements SerializerInterface, NormalizerInterface
{
    public function normalize(mixed $data, ?string $format = null, array $context = []): array|string|int|float|bool|\ArrayObject|null
    {
        return [];
    }

    public function supportsNormalization(mixed $data, ?string $format = null, array $context = []): bool
    {
        return true;
    }

    public function getSupportedTypes(?string $format): array
    {
        return ['object' => true];
    }

    public function serialize(mixed $data, string $format, array $context = []): string
    {
        return '';
    }

    public function deserialize(mixed $data, string $type, string $format, array $context = []): mixed
    {
        return null;
    }
}
