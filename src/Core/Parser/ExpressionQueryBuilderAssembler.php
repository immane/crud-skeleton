<?php

namespace App\Core\Parser;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\QueryBuilder;
use Symfony\Component\Validator\Exception\ValidatorException;

/**
 * Assembles a Doctrine QueryBuilder from an ExpressionDqlParser's fragments.
 */
class ExpressionQueryBuilderAssembler
{
    private EntityManagerInterface $em;

    public function __construct(EntityManagerInterface $em)
    {
        $this->em = $em;
    }

    /**
     * Create a QueryBuilder for the parser's dataClass and apply compiled fragments.
     *
     * @param array<string, mixed> $options optional ['rootAlias' => string]
     * @throws ValidatorException
     */
    public function buildQueryBuilder(ExpressionDqlParser $parser, array $options = []): QueryBuilder
    {
        $dataClass = $parser->getDataClass();
        if (trim($dataClass) === '') {
            throw new ValidatorException('Data class is not set on parser');
        }

        $rootAlias = $options['rootAlias'] ?? $parser->getRootAlias();

        $qb = $this->em->createQueryBuilder();
        try {
            $qb->select($rootAlias);
            $qb->from($dataClass, $rootAlias);
        } catch (\Throwable $e) {
            throw new ValidatorException('Failed to initialize QueryBuilder: ' . $e->getMessage());
        }

        $fragments = $parser->getFragments();
        $this->applyFragmentsToQueryBuilder($qb, $parser, $fragments);

        return $qb;
    }

    /**
     * Apply parser fragments to an existing QueryBuilder.
     *
     * @param array<string, mixed> $options optional ['targetRootAlias' => string]
     */
    public function applyToQueryBuilder(QueryBuilder $qb, ExpressionDqlParser $parser, array $options = []): QueryBuilder
    {
        $fragments = $parser->getFragments();
        $this->applyFragmentsToQueryBuilder($qb, $parser, $fragments, $options);
        return $qb;
    }

    /**
     * Core assembly: apply fragments to given QueryBuilder. Non-mutating for parser.
     *
     * @param QueryBuilder $qb
     * @param ExpressionDqlParser $parser
     * @param array{joins?: array<string, string>, where?: string, params?: array<string, mixed>} $fragments
     * @param array<string, mixed> $options
     */
    private function applyFragmentsToQueryBuilder(QueryBuilder $qb, ExpressionDqlParser $parser, array $fragments, array $options = []): void
    {
        $joins = $fragments['joins'] ?? [];
        $where = $fragments['where'] ?? '';
        $params = $fragments['params'] ?? [];

        // Determine existing root aliases on QB
        try {
            $existingRootAliases = $qb->getRootAliases();
        } catch (\Exception $e) {
            $existingRootAliases = [];
        }

        // If QB has no FROM, either use provided targetRootAlias or parser root
        if (empty($existingRootAliases)) {
            $dataClass = $parser->getDataClass();
            if (trim($dataClass) === '') {
                throw new ValidatorException('QueryBuilder has no FROM and parser has no dataClass');
            }
            $rootAlias = $options['rootAlias'] ?? $parser->getRootAlias();
            $qb->from($dataClass, $rootAlias);
            try { $qb->select($rootAlias); } catch (\Exception $e) { /* ignore */ }
            $existingRootAliases = [$rootAlias];
        }

        // Remap joins/where if QB's first root alias differs from parser root alias
        $targetRoot = $existingRootAliases[0] ?? $parser->getRootAlias();
        if ($targetRoot !== $parser->getRootAlias()) {
            foreach ($joins as $alias => $path) {
                if (strpos($path, $parser->getRootAlias() . '.') === 0) {
                    $joins[$alias] = $targetRoot . substr($path, strlen($parser->getRootAlias()));
                }
            }
            if ($where !== '') {
                $where = str_replace($parser->getRootAlias() . '.', $targetRoot . '.', $where);
            }
        }

        // Prepare existing aliases to avoid duplicate join aliases
        try {
            $existingAliases = $qb->getAllAliases();
        } catch (\Exception $e) {
            $existingAliases = $existingRootAliases;
        }

        // Apply joins
        foreach ($joins as $alias => $path) {
            if (in_array($alias, $existingAliases, true)) {
                continue;
            }
            try {
                $qb->leftJoin($path, $alias);
                $existingAliases[] = $alias;
            } catch (\Exception $e) {
                // ignore join errors
            }
        }

        // Manage parameter name collisions
        $existingParamNames = [];
        try {
            foreach ($qb->getParameters() as $p) {
                $existingParamNames[$p->getName()] = true;
            }
        } catch (\Exception $e) {
            // ignore
        }

        $toSet = [];
        $counter = 0;
        foreach ($params as $name => $value) {
            if (isset($existingParamNames[$name])) {
                do {
                    $newName = $name . '_x' . (++$counter);
                } while (isset($existingParamNames[$newName]) || isset($toSet[$newName]));
                if ($where !== '') {
                    $where = preg_replace(
                        '/(?<![A-Za-z0-9_]):' . preg_quote($name, '/') . '(?![A-Za-z0-9_])/',
                        ':' . $newName,
                        $where,
                    ) ?? $where;
                }
                $toSet[$newName] = $value;
                $existingParamNames[$newName] = true;
            } else {
                $toSet[$name] = $value;
                $existingParamNames[$name] = true;
            }
        }

        // Apply where
        if ($where !== '') {
            $qb->andWhere($where);
        }

        // Set parameters
        foreach ($toSet as $n => $v) {
            try {
                $qb->setParameter($n, $v);
            } catch (\Exception $e) {
                // ignore parameter set errors
            }
        }
    }
}
