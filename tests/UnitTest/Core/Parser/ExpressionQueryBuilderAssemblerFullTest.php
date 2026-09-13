<?php

declare(strict_types=1);

namespace App\Tests\UnitTest\Core\Parser;

use App\Core\Parser\ExpressionDqlParser;
use App\Core\Parser\ExpressionQueryBuilderAssembler;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Query\Parameter;
use Doctrine\ORM\QueryBuilder;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Validator\Exception\ValidatorException;

final class ExpressionQueryBuilderAssemblerFullTest extends TestCase
{
    private function createEm(): EntityManagerInterface
    {
        $em = $this->createMock(EntityManagerInterface::class);

        $qb = $this->createMock(QueryBuilder::class);
        $qb->method('select')->willReturn($qb);
        $qb->method('from')->willReturn($qb);
        $qb->method('andWhere')->willReturn($qb);
        $qb->method('setParameter')->willReturn($qb);
        $qb->method('leftJoin')->willReturn($qb);
        $qb->method('getRootAliases')->willReturn(['e']);
        $qb->method('getAllAliases')->willReturn(['e']);
        $qb->method('getParameters')->willReturn(new \Doctrine\Common\Collections\ArrayCollection([
            new Parameter('existing', 'val')
        ]));

        $em->method('createQueryBuilder')->willReturn($qb);
        return $em;
    }

    #[Group('low-value')]
    public function testBuildQueryBuilder(): void
    {
        $parser = new ExpressionDqlParser();
        $parser->setDataClass('App\Common\Entity\Content');
        $parser->setValues(['entity' => '']);
        $parser->setExpression('entity.getId() == 1');
        $parser->compile();

        $assembler = new ExpressionQueryBuilderAssembler($this->createEm());
        $qb = $assembler->buildQueryBuilder($parser);

        self::assertInstanceOf(QueryBuilder::class, $qb);
    }

    #[Group('low-value')]
    public function testBuildQueryBuilderWithOptions(): void
    {
        $parser = new ExpressionDqlParser();
        $parser->setDataClass('App\Common\Entity\Content');
        $parser->setValues(['entity' => '']);
        $parser->setExpression('entity.getId() == 1');
        $parser->compile();

        $assembler = new ExpressionQueryBuilderAssembler($this->createEm());
        $qb = $assembler->buildQueryBuilder($parser, ['rootAlias' => 'custom']);

        self::assertInstanceOf(QueryBuilder::class, $qb);
    }

    public function testBuildQueryBuilderWithEmptyDataClass(): void
    {
        $parser = $this->createMock(ExpressionDqlParser::class);
        $parser->method('getDataClass')->willReturn('');

        $assembler = new ExpressionQueryBuilderAssembler($this->createEm());

        $this->expectException(\Symfony\Component\Validator\Exception\ValidatorException::class);
        $assembler->buildQueryBuilder($parser);
    }

    #[Group('low-value')]
    public function testApplyToQueryBuilder(): void
    {
        $parser = new ExpressionDqlParser();
        $parser->setDataClass('App\Common\Entity\Content');
        $parser->setValues(['entity' => '']);
        $parser->setExpression('entity.getId() == 1');
        $parser->compile();

        $assembler = new ExpressionQueryBuilderAssembler($this->createEm());
        $qb = $assembler->buildQueryBuilder($parser);
        $result = $assembler->applyToQueryBuilder($qb, $parser);

        self::assertInstanceOf(QueryBuilder::class, $result);
    }

    #[Group('low-value')]
    public function testApplyFragmentsWithJoinsAndParams(): void
    {
        $parser = new ExpressionDqlParser();
        $parser->setDataClass('App\Common\Entity\Content');
        $parser->setValues(['entity' => '']);
        $parser->setExpression('entity.getCategory().getId() == 1');
        $parser->compile();

        $assembler = new ExpressionQueryBuilderAssembler($this->createEm());
        $qb = $assembler->buildQueryBuilder($parser);

        self::assertInstanceOf(QueryBuilder::class, $qb);
    }

    #[Group('low-value')]
    public function testApplyToQueryBuilderWithTargetAliasOption(): void
    {
        $parser = new ExpressionDqlParser();
        $parser->setDataClass('App\Common\Entity\Content');
        $parser->setValues(['entity' => '']);
        $parser->setExpression('entity.getId() == 1');
        $parser->compile();

        $assembler = new ExpressionQueryBuilderAssembler($this->createEm());
        $qb = $assembler->buildQueryBuilder($parser);
        $result = $assembler->applyToQueryBuilder($qb, $parser, ['targetRootAlias' => 'other']);

        self::assertInstanceOf(QueryBuilder::class, $result);
    }

    public function testBuildQueryBuilderWrapsInitializationFailure(): void
    {
        $parser = $this->createMock(ExpressionDqlParser::class);
        $parser->method('getDataClass')->willReturn('App\Common\Entity\Content');
        $parser->method('getRootAlias')->willReturn('filter_entity');

        $qb = $this->createMock(QueryBuilder::class);
        $qb->method('select')->willThrowException(new \RuntimeException('cannot select'));

        $em = $this->createMock(EntityManagerInterface::class);
        $em->method('createQueryBuilder')->willReturn($qb);

        $assembler = new ExpressionQueryBuilderAssembler($em);

        $this->expectException(ValidatorException::class);
        $this->expectExceptionMessage('Failed to initialize QueryBuilder: cannot select');

        $assembler->buildQueryBuilder($parser);
    }

    public function testApplyToQueryBuilderRequiresDataClassWhenQueryBuilderHasNoFrom(): void
    {
        $parser = $this->createMock(ExpressionDqlParser::class);
        $parser->method('getFragments')->willReturn(['joins' => [], 'where' => '', 'params' => []]);
        $parser->method('getDataClass')->willReturn('');
        $parser->method('getRootAlias')->willReturn('filter_entity');

        $qb = $this->createMock(QueryBuilder::class);
        $qb->method('getRootAliases')->willReturn([]);

        $assembler = new ExpressionQueryBuilderAssembler($this->createMock(EntityManagerInterface::class));

        $this->expectException(ValidatorException::class);
        $this->expectExceptionMessage('QueryBuilder has no FROM and parser has no dataClass');

        $assembler->applyToQueryBuilder($qb, $parser);
    }

    public function testApplyToQueryBuilderRenamesCollidingParameters(): void
    {
        $parser = $this->createMock(ExpressionDqlParser::class);
        $parser->method('getFragments')->willReturn([
            'joins' => [],
            'where' => 'e.id = :existing',
            'params' => ['existing' => 99],
        ]);
        $parser->method('getDataClass')->willReturn('App\Common\Entity\Content');
        $parser->method('getRootAlias')->willReturn('filter_entity');

        $qb = $this->createMock(QueryBuilder::class);
        $qb->method('getRootAliases')->willReturn(['e']);
        $qb->method('getAllAliases')->willReturn(['e']);
        $qb->method('getParameters')->willReturn(new \Doctrine\Common\Collections\ArrayCollection([
            new Parameter('existing', 1),
        ]));
        $qb->expects(self::once())
            ->method('andWhere')
            ->with('e.id = :existing_x1')
            ->willReturn($qb);
        $qb->expects(self::once())
            ->method('setParameter')
            ->with('existing_x1', 99)
            ->willReturn($qb);

        $assembler = new ExpressionQueryBuilderAssembler($this->createMock(EntityManagerInterface::class));
        $result = $assembler->applyToQueryBuilder($qb, $parser);

        self::assertSame($qb, $result);
    }

    public function testApplyToQueryBuilderDoesNotRenameParameterPrefixes(): void
    {
        $parser = $this->createMock(ExpressionDqlParser::class);
        $parser->method('getFragments')->willReturn([
            'joins' => [],
            'where' => 'e.first = :p1 AND e.second = :p10',
            'params' => ['p1' => 1, 'p10' => 10],
        ]);
        $parser->method('getDataClass')->willReturn('App\\Common\\Entity\\Content');
        $parser->method('getRootAlias')->willReturn('filter_entity');

        $qb = $this->createMock(QueryBuilder::class);
        $qb->method('getRootAliases')->willReturn(['e']);
        $qb->method('getAllAliases')->willReturn(['e']);
        $qb->method('getParameters')->willReturn(new \Doctrine\Common\Collections\ArrayCollection([
            new Parameter('p1', 100),
        ]));
        $qb->expects(self::once())
            ->method('andWhere')
            ->with('e.first = :p1_x1 AND e.second = :p10')
            ->willReturn($qb);
        $qb->expects(self::exactly(2))
            ->method('setParameter')
            ->willReturnCallback(static function (string $name, int $value) use ($qb): QueryBuilder {
                self::assertContains([$name, $value], [['p1_x1', 1], ['p10', 10]]);
                return $qb;
            });

        $assembler = new ExpressionQueryBuilderAssembler($this->createMock(EntityManagerInterface::class));
        $assembler->applyToQueryBuilder($qb, $parser);
    }

    public function testApplyToQueryBuilderAddsFromWhenQueryBuilderHasNoRootAliases(): void
    {
        $parser = $this->createMock(ExpressionDqlParser::class);
        $parser->method('getFragments')->willReturn(['joins' => [], 'where' => '', 'params' => []]);
        $parser->method('getDataClass')->willReturn('App\Common\Entity\Content');
        $parser->method('getRootAlias')->willReturn('filter_entity');

        $qb = $this->createMock(QueryBuilder::class);
        $qb->method('getRootAliases')->willReturnOnConsecutiveCalls([], ['filter_entity']);
        $qb->expects(self::once())
            ->method('from')
            ->with('App\Common\Entity\Content', 'filter_entity')
            ->willReturn($qb);
        $qb->expects(self::once())
            ->method('select')
            ->with('filter_entity')
            ->willThrowException(new \RuntimeException('select ignored'));

        $assembler = new ExpressionQueryBuilderAssembler($this->createMock(EntityManagerInterface::class));
        $result = $assembler->applyToQueryBuilder($qb, $parser);

        self::assertSame($qb, $result);
    }

    public function testApplyToQueryBuilderIgnoresParameterSetFailure(): void
    {
        $parser = $this->createMock(ExpressionDqlParser::class);
        $parser->method('getFragments')->willReturn([
            'joins' => [],
            'where' => 'e.id = :p1',
            'params' => ['p1' => 1],
        ]);
        $parser->method('getDataClass')->willReturn('App\Common\Entity\Content');
        $parser->method('getRootAlias')->willReturn('filter_entity');

        $qb = $this->createMock(QueryBuilder::class);
        $qb->method('getRootAliases')->willReturn(['e']);
        $qb->method('getAllAliases')->willReturn(['e']);
        $qb->method('getParameters')->willReturn(new \Doctrine\Common\Collections\ArrayCollection());
        $qb->method('andWhere')->willReturn($qb);
        $qb->expects(self::once())
            ->method('setParameter')
            ->with('p1', 1)
            ->willThrowException(new \RuntimeException('cannot set parameter'));

        $assembler = new ExpressionQueryBuilderAssembler($this->createMock(EntityManagerInterface::class));
        $result = $assembler->applyToQueryBuilder($qb, $parser);

        self::assertSame($qb, $result);
    }
}
