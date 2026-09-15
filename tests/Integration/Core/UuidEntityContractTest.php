<?php

declare(strict_types=1);

namespace App\Tests\Integration\Core;

use App\Tests\Integration\IntegrationKernelTestCase;
use Doctrine\ORM\EntityManagerInterface;

final class UuidEntityContractTest extends IntegrationKernelTestCase
{
    public function testEveryDoctrineEntityHasANonNullableUuid(): void
    {
        self::bootKernel();

        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        foreach ($entityManager->getMetadataFactory()->getAllMetadata() as $metadata) {
            self::assertTrue($metadata->hasField('uuid'), sprintf('%s must define uuid.', $metadata->getName()));
            self::assertSame('string', $metadata->getTypeOfField('uuid'));
            self::assertFalse($metadata->isNullable('uuid'));
        }
    }
}
