<?php
declare(strict_types=1);

namespace App\Core\Service\Concern;

use App\Core\Parser\ExpressionDqlParser;
use App\Core\Parser\ExpressionQueryBuilderAssembler;
use App\Core\Query\DqlExpression;
use App\Core\Utils\UUID;
use Doctrine\ORM\NonUniqueResultException;
use Doctrine\ORM\NoResultException;
use Doctrine\ORM\Query\Expr;
use Doctrine\ORM\QueryBuilder;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Component\Validator\Exception\ValidatorException;

/** @template TEntity of object */
trait BaseServiceReadListTrait
{
    /**
     * @param TEntity|int|string|array<string, mixed>|QueryBuilder|DqlExpression $object
     * @return TEntity|null
     */
    public function get(mixed $object, bool $directly = false)
    {
        if ($object === null) {
            return null;
        }

        if ($object instanceof DqlExpression) {
            $qb = $this->getQueryBuilderFactory()->create($this->entityClass, 'entity');
            $this->applyDqlExpression($qb, $object);
            try {
                $entity = $qb->getQuery()->getSingleResult();
            } catch (NoResultException | NonUniqueResultException $e) {
                $entity = null;
            }

            return $entity;
        }

        if ($object instanceof QueryBuilder) {
            try {
                $entity = $object->getQuery()->getSingleResult();
            } catch (NoResultException | NonUniqueResultException $e) {
                $entity = null;
            }
        }
        elseif (is_object($object) && method_exists($object, 'getId')) {
            $entityId = $object->getId();
            $entity = $entityId === null ? null : $this->rep->find($entityId);
        }
        elseif (is_array($object)) {
            $entity = $this->rep->findOneBy($object);
        } elseif (is_string($object) && UUID::is_valid($object)) {
            $metadata = $this->getEntityManager()->getClassMetadata($this->entityClass);
            $entity = $metadata->hasField('uuid') ? $this->rep->findOneBy(['uuid' => $object]) : null;
        } else {
            $entity = $this->rep->find($object);
        }

        return $entity;
    }

    /**
     * @param array<string, mixed>|QueryBuilder|DqlExpression|null $object
     * @param array<string, 'ASC'|'DESC'>|null $order
     * @return int|mixed|string
     * @throws \Exception
     */
    public function list(
        mixed $object = null,
        mixed $order = null,
        bool $disableRequest = true
    ): mixed {
        $em = $this->getEntityManager();
        $request = $this->getCurrentRequest();

        if ($object instanceof DqlExpression) {
            $alias = 'entity';
            $qb = $this->getQueryBuilderFactory()->create($this->entityClass, $alias);
            $this->applyDqlExpression($qb, $object);
        } elseif($object instanceof QueryBuilder) {
            $qb = $object;

            $aliases = $object->getRootAliases();
            if(empty($aliases)) {
                throw new ValidatorException('Invalid query build aliases');
            }
            $alias = $aliases[0];
        }
        else {
            $alias = 'entity';

            $qb = $this->getQueryBuilderFactory()
                ->create($this->entityClass, $alias)
            ;

            if(is_array($object)) {
                foreach ($object as $key => $value) {
                    $qb
                        ->andWhere("entity.$key = :value_$key")
                        ->setParameter("value_$key", $value)
                    ;
                }
            }
        }

        if ($request && !$disableRequest) {
            $this->assertPrivilegedQueryParameters($request);
        }

        if ($request && !$disableRequest && ($subDql = $request->query->get('@dql'))) {
            $subDql = $em->createQuery($subDql);
            $qb->andWhere((new Expr())->in("$alias.id", $subDql->getDQL()));
        }

        $filterError = false;
        if ($request && !$disableRequest && ($filter = $request->query->get('@filter'))) {
            $backupQb = clone $qb;

            try {
                $expressionService = $this->getExpressionService();
                $result = $expressionService->buildFilter($filter, $this->entityClass, $this->externalExpressionValues(), $this->getEntityManager());

                /** @var QueryBuilder $filterQb */
                $filterQb = $result['qb'];
                $filterDql = $filterQb->getDQL();
                // Avoid parameter name collision between commonFilter (DqlExpression) and @filter
                $existingParams = [];
                if (is_object($qb) && method_exists($qb, 'getParameters')) {
                    foreach ($qb->getParameters() as $p) {
                        if (is_object($p) && method_exists($p, 'getName')) {
                            $existingParams[$p->getName()] = true;
                        }
                    }
                }
                foreach ($result['parameters'] as $parameter) {
                    $oldName = $parameter->getName();
                    $newName = $oldName;
                    if (isset($existingParams[$oldName])) {
                        $counter = 0;
                        do {
                            $newName = $oldName . '_' . (++$counter);
                        } while (isset($existingParams[$newName]));
                        $filterDql = str_replace(':' . $oldName, ':' . $newName, $filterDql);
                    }
                    $qb->setParameter($newName, $parameter->getValue());
                    $existingParams[$newName] = true;
                }
                $qb->andWhere((new Expr())->in("$alias.id", $filterDql));
            } catch (\Exception $exception) {
                $this->logger->error('Filter validation exception: '. $exception->getMessage());
                $this->logger->error('Filter source: '. $filter);

                if (!$this->hasAdminRole()) {
                    throw new AccessDeniedHttpException('@filter expressions that require in-memory evaluation are restricted to administrators.');
                }

                $filterError = true;
                $qb = $backupQb;
            }
        }

        $object = $qb;

        $joins = [];
        $joiner = function(?string &$expression, array &$joins, string $rootAlias): void {
            if (!is_string($expression)) {
                return;
            }
            $expressionAlias = 'entity';
            $aliasPattern = "/$expressionAlias((\.\w+)+)/";
            $aliasReplacement = "$rootAlias$1";
            $expression = preg_replace($aliasPattern, $aliasReplacement, $expression);

            $joinPattern = '/(\w+\s*\.\s*)+\w+/';
            if(preg_match_all($joinPattern, $expression, $matches)) { // @phpstan-ignore argument.type
                foreach ($matches[0] as $item) {
                    $itemParts = explode('.', $item);
                    $joinKey = '';
                    foreach ($itemParts as $i => $match) {
                        if($i == 0) {
                            $joinKey = $match; continue;
                        }
                        $exportValue = $joinKey . '.' . $match;
                        $joinKey .= '_' . $match;

                        if($i >= count($itemParts) -1) break;
                        $joins[$joinKey] = $exportValue;
                    }
                }
            }

            $expression = preg_replace('/\.(\w+)(?=\.)/', '_$1', $expression); // @phpstan-ignore argument.type
        };

        $select = null;
        $select = $request?->query->all()['@select'] ?? null;
        if (!$disableRequest && $select !== null && $select !== '') {
            if (!is_string($select)) {
                throw new ValidatorException('@select must be a string.');
            }
            $this->assertSafeSelect($select);
            $joiner($select, $joins, $alias);
            $qb->select($select);
        }

        $groupBy = null;
        if ($request && !$disableRequest && ($groupBy = $request->query->get('@groupBy'))) {
            $joiner($groupBy, $joins, $alias);
            $qb->addGroupBy($groupBy);
        }

        if ($request && !$disableRequest && ($preOrders = $request->query->get('@order'))) {
            $joiner($preOrders, $joins, $alias);

            $preOrders = explode(',', trim($preOrders));
            $order = [];

            foreach ($preOrders as $o) {
                $t = explode('|', $o);
                if (count($t) == 2) {
                    $order[trim($t[0])] = trim($t[1]);
                }
            }
        }
        if($order) {
            foreach ($order as $key => $value) {
                $object->addOrderBy($key, $value);
            }
        }

        foreach ($joins as $key => $value) {
            $qb->leftJoin($value, $key);
        }

        $query = $object->getQuery();

        if ($request && !$disableRequest && ($hints = $request->query->get('@hints'))) {
            $hints = json_decode($hints);
            foreach($hints as $k => $v) {
                $query->setHint($k, $v);
            }
        }

        if ($request && !$disableRequest && $request->query->get('@showDQL')) {
            throw new ValidatorException('DQL: '. $qb->getDQL());
        }

        if ($request && !$disableRequest && $request->query->get('@sort')) {
            $filterError = true;
        }

        if (!$disableRequest && !$filterError) {
            if($select || $groupBy) {
                return $query->getResult();
            }
            else {
                return $object;
            }
        }

        else {
            if($select || $groupBy) {
                throw new ValidatorException('Filter error from grouping by or selection.');
            }
            else {
                $entities = $query->getResult();
            }

            if ($request && !$disableRequest) {
                if ($filter = $request->query->get('@filter')) {
                    $entities = array_filter(
                        $entities,
                        function ($entity) use ($filter) {
                            try {
                                return $this->getLegacyEvaluator()->evaluateBool($filter, array_merge(['entity' => $entity], $this->externalExpressionValues()));
                            } catch (\Exception $e) {
                                return false;
                            }
                        }
                    );
                }

                if ($sorter = $request->query->get('@sort')) {
                    usort(
                        $entities,
                        function ($x, $y) use ($sorter) {
                            try {
                                return $this->getLegacyEvaluator()->evaluateBool($sorter, array_merge(['x' => $x, 'y' => $y], $this->externalExpressionValues())) ? 1 : -1;
                            } catch (\Exception $e) {
                                return 0;
                            }
                        }
                    );
                }
            }

            return $entities;
        }
    }

    private function assertPrivilegedQueryParameters(\Symfony\Component\HttpFoundation\Request $request): void
    {
        foreach (['@dql', '@sort', '@hints'] as $parameter) {
            if ($request->query->has($parameter) && !$this->hasAdminRole()) {
                throw new AccessDeniedHttpException(sprintf('%s is restricted to administrators.', $parameter));
            }
        }

        if ($request->query->has('@showDQL') && !$this->isDevelopmentEnvironment()) {
            throw new AccessDeniedHttpException('@showDQL is only available in the dev environment.');
        }
    }

    private function assertSafeSelect(string $select): void
    {
        $identityFields = 'user|profile|password|roles|email|phone|phoneVerified|refreshToken|sessionKey|rawData';
        if (
            str_starts_with($this->entityClass, 'App\\Identity\\')
            || preg_match('/(?:^|[.\s,])(?:' . $identityFields . ')\b/i', $select) === 1
        ) {
            throw new AccessDeniedHttpException('@select cannot access identity data.');
        }
    }

    private function hasAdminRole(): bool
    {
        return $this->user instanceof UserInterface
            && in_array('ROLE_ADMIN', $this->user->getRoles(), true);
    }

    private function isDevelopmentEnvironment(): bool
    {
        return $this->container->hasParameter('kernel.environment')
            && $this->container->getParameter('kernel.environment') === 'dev';
    }

    private function applyDqlExpression(QueryBuilder $qb, DqlExpression $expression): void
    {
        $values = $expression->values;
        if ($expression->context() !== null) {
            $values['this'] = $expression->context();
        } elseif ($expression->usesThis()) {
            throw new \LogicException('DqlExpression uses "this" without controller context.');
        }

        try {
            $parser = new ExpressionDqlParser();
            $parser->setDataClass($this->entityClass)
                ->setValues($values)
                ->setExpression($expression->expression)
                ->compile();
            $parser->validateFragments($this->getEntityManager());
        } catch (\Throwable $e) {
            throw new \LogicException('Invalid server DQL common filter: ' . $e->getMessage(), 0, $e);
        }

        $assembler = new ExpressionQueryBuilderAssembler($this->getEntityManager());
        $assembler->applyToQueryBuilder($qb, $parser);

        $criteria = $expression->criteria();
        if ($criteria === []) {
            return;
        }

        $em = $this->getEntityManager();
        $meta = $em->getClassMetadata($this->entityClass);
        $rootAlias = $qb->getRootAliases()[0] ?? 'entity';

        // Collect existing parameter names to avoid collision
        $existingParams = [];
        foreach ($qb->getParameters() as $p) {
            $existingParams[$p->getName()] = true;
        }

        $counter = 0;
        foreach ($criteria as $field => $value) {
            if (!$meta->hasField($field) && !$meta->hasAssociation($field) && !in_array($field, $meta->getIdentifierFieldNames(), true)) {
                throw new \LogicException(sprintf('Invalid criteria field "%s" for %s', $field, $this->entityClass));
            }
            // Ensure unique parameter name
            $base = '_common_filter_criterion_' . $field;
            $param = $base;
            while (isset($existingParams[$param])) {
                $param = $base . '_' . (++$counter);
            }
            $existingParams[$param] = true;
            $qb->andWhere(sprintf('%s.%s = :%s', $rootAlias, $field, $param))
                ->setParameter($param, $value);
        }
    }
}
