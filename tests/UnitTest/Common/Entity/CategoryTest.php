<?php

namespace App\Tests\UnitTest\Common\Entity;

use App\Common\Entity\Category;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

final class CategoryTest extends TestCase
{
    public function testConstructorInitializesFields(): void
    {
        $entity = new Category('Test Category', 'test-category');

        self::assertSame('Test Category', $entity->getName());
        self::assertSame('test-category', $entity->getSlug());
        self::assertMatchesRegularExpression('/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/', $entity->getUuid());
        self::assertNull($entity->getDescription());
        self::assertNull($entity->getParent());
        self::assertEmpty($entity->getChildren());
        self::assertSame(0, $entity->getSortOrder());
        self::assertTrue($entity->isEnabled());
        self::assertInstanceOf(\DateTimeImmutable::class, $entity->getCreatedAt());
        self::assertNull($entity->getUpdatedAt());
        self::assertSame('Test Category', (string) $entity);
    }

    #[Group('low-value')]
    public function testSettersAreFluent(): void
    {
        $entity = new Category('before', 'before');

        $entity->setName('after')->setSlug('after-slug')
            ->setDescription('desc')->setSortOrder(5)->setEnabled(false);

        self::assertSame('after', $entity->getName());
        self::assertSame('after-slug', $entity->getSlug());
        self::assertSame('desc', $entity->getDescription());
        self::assertSame(5, $entity->getSortOrder());
        self::assertFalse($entity->isEnabled());
    }

    public function testTouchUpdatesTimestamp(): void
    {
        $entity = new Category('name', 'slug');
        self::assertNull($entity->getUpdatedAt());

        $entity->touch();
        self::assertInstanceOf(\DateTimeImmutable::class, $entity->getUpdatedAt());
    }

    public function testParentChildRelationships(): void
    {
        $parent = new Category('Parent', 'parent');
        $child = new Category('Child', 'child');

        $parent->addChild($child);

        self::assertCount(1, $parent->getChildren());
        self::assertTrue($parent->getChildren()->contains($child));
        self::assertSame($parent, $child->getParent());

        $parent->removeChild($child);
        self::assertCount(0, $parent->getChildren());
        self::assertNull($child->getParent());
    }

    public function testSetParentDirectly(): void
    {
        $parent = new Category('Parent', 'parent');
        $child = new Category('Child', 'child');

        $child->setParent($parent);
        self::assertSame($parent, $child->getParent());

        $child->setParent(null);
        self::assertNull($child->getParent());
    }

    public function testAddChildDoesNotDuplicate(): void
    {
        $parent = new Category('Parent', 'parent');
        $child = new Category('Child', 'child');

        $parent->addChild($child);
        $parent->addChild($child);

        self::assertCount(1, $parent->getChildren());
    }

    public function testRemoveChildFromWrongParentDoesNothing(): void
    {
        $parent = new Category('Parent', 'parent');
        $other = new Category('Other', 'other');

        $parent->removeChild($other);
        self::assertCount(0, $parent->getChildren());
    }

    public function testPrePersistWhenCreatedFromReflection(): void
    {
        $reflection = new \ReflectionClass(Category::class);
        $entity = $reflection->newInstanceWithoutConstructor();

        $entity->prePersist();
        self::assertInstanceOf(\DateTimeImmutable::class, $entity->getCreatedAt());
    }

    public function testPrePersistPreservesExistingCreatedAt(): void
    {
        $entity = new Category('preserve', 'preserve');
        $createdAt = $entity->getCreatedAt();

        $entity->prePersist();
        self::assertSame($createdAt, $entity->getCreatedAt());
    }
}
