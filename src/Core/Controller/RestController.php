<?php

namespace App\Core\Controller;

use App\Core\Serializer\ExpansionMetadata;
use App\Core\Utils\ArrayCommon;
use App\Core\Utils\FixJSON;
use App\Core\Utils\Math;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\ORM\QueryBuilder;
use Doctrine\ORM\Tools\Pagination\Paginator as DoctrinePaginator;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\ExpressionLanguage\ExpressionLanguage;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Serializer\Exception\ExceptionInterface;
use Symfony\Component\Serializer\SerializerInterface;
use Symfony\Contracts\Service\Attribute\Required;
use Symfony\Contracts\Translation\TranslatorInterface;

class RestController extends AbstractController
{
    const UNKNOWN_ERROR = 'Api error occurred';

    // Allow nullable properties so child controllers may omit calling parent::__construct()
    private ?RequestStack $requestStack = null;
    private ?SerializerInterface $serializer = null;
    private ?TranslatorInterface $translator = null;
    protected ?ContainerInterface $serviceContainer = null;

    /**
     * Constructor accepts optional dependencies so subclasses can call parent::__construct()
     * with or without arguments. If dependencies are not provided, getters will fetch them
     * lazily from the container (AbstractController::$container) so child controllers don't
     * need to explicitly declare or forward those arguments.
     */
    public function __construct(
        ?RequestStack $requestStack = null,
        ?SerializerInterface $serializer = null,
        ?TranslatorInterface $translator = null
    ) {
        $this->requestStack = $requestStack;
        $this->serializer = $serializer;
        $this->translator = $translator;
    }
    #[Required]
    public function setRequestStack(RequestStack $requestStack): void
    {
        $this->requestStack = $requestStack;
    }

    #[Required]
    public function setSerializer(SerializerInterface $serializer): void
    {
        $this->serializer = $serializer;
    }

    #[Required]
    public function setTranslator(TranslatorInterface $translator): void
    {
        $this->translator = $translator;
    }

    #[Required]
    public function setServiceContainer(ContainerInterface $serviceContainer): void
    {
        $this->serviceContainer = $serviceContainer;
    }

    protected function resolveService(string $id): object
    {
        if ($this->serviceContainer === null || !$this->serviceContainer->has($id)) {
            throw new \RuntimeException(sprintf('Service "%s" is not available.', $id));
        }

        return $this->serviceContainer->get($id);
    }

    public function getService(): object
    {
        $properties = get_object_vars($this);
        $service = $properties['service'] ?? null;
        if (!is_object($service)) {
            throw new \RuntimeException('Controller service is not available.');
        }

        return $service;
    }

    protected function getRequestStack(): RequestStack
    {
        if ($this->requestStack instanceof RequestStack) {
            return $this->requestStack;
        }
        throw new \RuntimeException('RequestStack is not available in RestController');
    }

    protected function getSerializer(): SerializerInterface
    {
        if ($this->serializer instanceof SerializerInterface) {
            return $this->serializer;
        }

        throw new \RuntimeException('Serializer is not available in RestController');
    }

    protected function getTranslator(): TranslatorInterface
    {
        if ($this->translator instanceof TranslatorInterface) {
            return $this->translator;
        }

        throw new \RuntimeException('Translator is not available in RestController');
    }


    /**
     * @return array{items:mixed, paginator:array<string, int|bool>|null}
     */
    protected function pagination(mixed $collection): array
    {
        // get current request
        $request = $this->getRequestStack()->getCurrentRequest();
        if ($request === null || $request->getMethod() !== 'GET') {
            return ['items' => $collection, 'paginator' => null];
        }

        $DEFAULT_PAGE_LIMIT = 100; // PHP_INT_MAX
        $page = max(1, $request->query->getInt('page', 1));
        $limit = max(1, $request->query->getInt('limit', $DEFAULT_PAGE_LIMIT));
        $offset = ($page - 1) * $limit;

        $buildMeta = static function (int $total, int $page, int $limit): array {
            $pages = max(1, (int) ceil($total / $limit));
            return [
                'total' => $total,
                'page' => $page,
                'limit' => $limit,
                'pages' => $pages,
                'has_previous' => $page > 1,
                'has_next' => $page < $pages,
            ];
        };

        if ($collection instanceof QueryBuilder) {
            $query = $collection->getQuery();
            $total = count(new DoctrinePaginator($query, true));
            $collection->setFirstResult($offset)->setMaxResults($limit);
            return [
                'items' => $collection->getQuery()->getResult(),
                'paginator' => $buildMeta($total, $page, $limit),
            ];
        }

        if (is_array($collection) || $collection instanceof ArrayCollection) {
            $items = $collection instanceof ArrayCollection ? $collection->toArray() : $collection;
            $total = count($items);
            return [
                'items' => array_slice($items, $offset, $limit),
                'paginator' => $buildMeta($total, $page, $limit),
            ];
        }

        return ['items' => $collection, 'paginator' => null];
    }

    /**
     * @param object $entity
     * @param mixed[] $attributeSets
     */
    private function expandObjects(mixed $entity, array $attributeSets): void
    {
        if (!is_object($entity)) {
            return;
        }
        foreach ($attributeSets as $attributeSet) {
            $attributeChain = explode('.', $attributeSet);

            if (current($attributeChain) == '' || current($attributeChain) == 'entity') {
                array_shift($attributeChain);
            }
            $this->expandObjectToMetadata($entity, $attributeChain);
        }
    }

    /**
     * @param object $entity
     * @param list<string> $attributeChain
     */
    private function expandObjectToMetadata(mixed &$entity, array $attributeChain, int $level = -1): void
    {
        if (empty($entity) || 0 === count($attributeChain) || 0 === $level) return;

        if (method_exists($entity, $getter = 'get' . ucfirst(trim($attributeChain[0])))) {
            if ($next = $entity->$getter()) {
                foreach ($next instanceof \Traversable ? $next : [$next] as $node) {
                    if (is_object($node)) {
                        ExpansionMetadata::mark($node);
                    }

                    // recursive
                    $copy = $attributeChain;
                    $this->expandObjectToMetadata(
                        $node,
                        array_splice($copy, 1),
                        $level - 1
                    );
                }
            }
        }
    }

    private function requestProcess(mixed $collection): mixed
    {
        $request = $this->getRequestStack()->getCurrentRequest();

        // Expend Object - supports JSON array '["specifications"]', single value 'specifications' and comma-separated 'a,b'
        $rawExpand = $request?->query?->get('@expands', '[]') ?? '[]';
        $expands = json_decode(
            str_replace('\'', '"', (string) $rawExpand), true);
        if (!is_array($expands) && is_string($rawExpand) && trim($rawExpand) !== '' && trim($rawExpand) !== '[]') {
            $tmp = trim($rawExpand);
            // strip surrounding brackets if present but not valid JSON
            $tmp = trim($tmp, '[]');
            $parts = array_filter(array_map('trim', explode(',', $tmp)), fn($v) => $v !== '');
            // Remove surrounding quotes from each part
            $parts = array_map(fn($v) => trim($v, '"\''), $parts);
            if ($parts !== []) {
                $expands = $parts;
            }
        }
        try {
            if (is_array($expands)) {
                if ($collection && (
                        is_array($collection)
                        || $collection instanceof ArrayCollection)
                ) {
                    foreach ($collection as $entity) {
                        $this->expandObjects($entity, $expands);
                    }
                } else {
                    $this->expandObjects($collection, $expands);
                }
            }

        } catch (\Exception $exception) {
        }

        // General display
        if ($collection && (
                is_array($collection)
                || $collection instanceof ArrayCollection)
        ) {
            $rawDisplay = $request?->query?->get('@display', 'complex') ?? 'complex';
            $displayRequest = is_string($rawDisplay) ? FixJSON::fixJSON($rawDisplay) : '';
            $decoded = is_string($displayRequest) ? json_decode($displayRequest) : null;
            $display = $decoded ?? $displayRequest;

            if (is_array($display)) {
                $items = $collection instanceof ArrayCollection ? $collection->toArray() : $collection;
                return array_map(function ($entity) use ($display) {
                    $result = [];
                    foreach ($display as $part) {
                        $part = trim($part);
                        $fields = explode('.', $part);
                        if (current($fields) == '' || current($fields) == 'entity') {
                            array_shift($fields);
                        }

                        $next = $entity;
                        foreach ($fields as $field) {
                            if(is_object($next)) {
                                $fieldGetter = 'get' . ucfirst($field);
                                $next = $next->$fieldGetter();
                            }
                            elseif (is_array($next)) {
                                $next = $next[$field] ?? null;
                            }
                        }

                        $result[$part] = $next;
                    }

                    return $result;
                }, $items);
            }

            if ($display instanceof \stdClass) {
                $displayArray = get_object_vars($display);
                $result = [];
                foreach ($collection as $item) {
                    $set = [];
                    foreach ($displayArray as $key => $value) {
                        try {
                            $expressionLanguage = new ExpressionLanguage();
                            $set[$key] = $expressionLanguage->evaluate(
                                $value, [
                                    'entity' => $item,
                                    'Math' => new Math(),
                                    'ArrayCommon' => new ArrayCommon()
                                ]
                            );
                        } catch (\Exception $e) {
                        }
                    }
                    $result[] = $set;
                }

                return $result;
            }

            if ($display === 'reduce') {
                $items = $collection instanceof ArrayCollection ? $collection->toArray() : $collection;
                return array_map(function ($entity) {
                    return [
                        'id' => $entity->getId(),
                        '__toString' => $entity->__toString(),
                    ];
                }, $items);
            }

            return $collection;
        }

        return $collection;
    }


    /**
     * @throws ExceptionInterface
     */
    protected function success(
        mixed $content = '',
        string $addition_message = 'SUCCESS',
        int $status = 200
    ): Response
    {
        if ($status === 204) {
            return new Response('', 204, ['Content-Type' => 'application/json']);
        }

        $paginated = $this->pagination($content);
        $processedContent = $this->requestProcess($paginated['items']);

        $response = [
            'data' => $processedContent,
            'code' => 0,
            'message' => $addition_message,
        ];
        if (is_array($paginated['paginator'])) {
            $response['paginator'] = $paginated['paginator'];
        }
        try {
            $serialized = $this->getSerializer()->serialize($response, 'json');
        } finally {
            ExpansionMetadata::clear();
        }

        return new Response($serialized, $status, ['Content-Type' => 'application/json']);
    }

    /**
     * @throws ExceptionInterface
     */
    protected function warning(
        string $error_msg = self::UNKNOWN_ERROR,
        int $error_code = -1,
        mixed $raw_data = '',
        int $status = 200
    ): Response
    {
        $response = [
            'code' => $error_code,
            'message' => $this->getTranslator()->trans($error_msg),
            'raw_data' => $raw_data,
        ];
        return new Response(
            $this->getSerializer()->serialize($response, 'json'),
            $status,
            ['Content-Type' => 'application/json']
        );
    }

}
