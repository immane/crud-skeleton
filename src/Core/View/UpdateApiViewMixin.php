<?php

namespace App\Core\View;

use App\Core\Validator\JsonSchemaValidator;
use OpenApi\Attributes as OA;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Validator\Exception\ValidatorException;
use App\Core\View\ApiViewMessages;

trait UpdateApiViewMixin
{
    protected static int $MODE_CREATE = 0;
    protected static int $MODE_UPDATE = 1;

    //protected $requiredUpdateProperties = [];
    //protected $acceptedUpdateProperties = [];
    /** @return array<string, mixed> */
    protected function defaultValues(): array
    {
        /** Default values */
        return [];
    }

    /**
     * @param array<string, mixed> $content
     * @return array<string, mixed>
     */
    protected function processContent(array $content, ?object $entity = null): array
    {
        /** Default values */
        return $content;
    }

    /**
     * @param array<string, mixed> $content
     */
    private function validateUpdateJsonSchemas(array $content): void
    {
        if (!property_exists($this, 'jsonSchemas') || empty($this->jsonSchemas)) {
            return;
        }
        $container = $this->serviceContainer ?? $this->container ?? null;
        if ($container === null || !$container->has(JsonSchemaValidator::class)) {
            return;
        }
        $validator = $container->get(JsonSchemaValidator::class);
        if (!$validator instanceof JsonSchemaValidator) {
            return;
        }
        foreach ($this->jsonSchemas as $field => $schemaName) {
            if (array_key_exists($field, $content) && $content[$field] !== null) {
                $validator->validate($content[$field], $schemaName);
            }
        }
    }

    /**
     */
    protected function after(object|false $entity): mixed
    {
        /** Updated entity */
        return $entity;
    }


    /**
     * @return array<string, mixed>
     */
    protected function defaultUpdateValues(): array
    {
        return $this->defaultValues();
    }

    /**
     * @param array<string, mixed> $content
     * @return array<string, mixed>
     */
    protected function processUpdateContent(array $content, ?object $entity = null): array
    {
        return $this->processContent($content, $entity);
    }

    /**
     */
    protected function afterUpdated(object|false $entity): mixed
    {
        return $this->after($entity);
    }

    /**
     * @param array<string, mixed> $content
     * @param array<string, string>|null $transformer
     */
    private function updateSingle(object $entity, array $content, ?array $transformer = null, int $writeMode = 1 /* MODE_UPDATE */, bool $noFlush = false): mixed
    {
        $service = $this->service;

        // Properties process.
        // FIXED: Add properties null checker for inherit
        if(
            (property_exists($this, 'requiredUpdateProperties') && $this->requiredUpdateProperties) ||
            (property_exists($this, 'acceptedUpdateProperties') && $this->acceptedUpdateProperties)
        ) {
            $data = [];

            if (property_exists($this, 'requiredUpdateProperties')) {
                foreach ($this->requiredUpdateProperties as $property) {
                    if (!array_key_exists($property, $content)) {
                        throw new ValidatorException(ApiViewMessages::propertyCannotBeEmpty($property));
                    }
                    $data[$property] = $content[$property];
                }
            }

            if (property_exists($this, 'acceptedUpdateProperties')) {
                foreach ($this->acceptedUpdateProperties as $property) {
                    if (array_key_exists($property, $content)) {
                        $data[$property] = $content[$property];
                    }
                }
            }


            $content = $data;
        }

        // Process content
        // TODO: May be bug occur here. Use other function instead of 'array_merge'
        $content = array_merge($content, $writeMode ? $this->defaultUpdateValues() : $this->defaultCreateValues());

        if($transformer) {
            $content = $this->transformContent($content, $transformer, $entity);
        }
        $this->validateUpdateJsonSchemas($content);
        $content = $writeMode
            ? $this->processUpdateContent($content, $entity)
            : $this->processCreateContent($content, $entity);

        // remove id
        unset($content['id']);

        // save
        $entity = $service->update($entity, $content, $noFlush);
        return $writeMode ? $this->afterUpdated($entity) : $this->afterCreated($entity);
    }

    /**
     * @throws \Exception
     */
    private function updateRecords(Request $request, int|string|null $id = null): mixed
    {
        // No explicit service injection.
        $service = $this->service;

        // External content
        $content = json_decode($request->getContent(), true) ? : [];

        // Batch mode
        // update, mixed
        $mode = $request->query->get('@mode', 'mixed');

        // Update basis
        // eg: id, name, ...
        $basis = $request->query->get('@basis');
        $basis = $basis ? array_map(function($item) { return trim($item); }, explode(',', $basis)) : [];

        // Partial create / update
        $partial = $request->query->getBoolean('@partial', false);

        // Transformer
        $transformer = $request->query->get('@transform');
        if($transformer) {
            $transformer = json_decode($transformer, true);
        }

        // Start

        if($id) {
            // Single update
            $filter = $this->mixIdToCommonFilter($id);
            $entity = $service->get($filter, false);

            if (!$entity) {
                throw new NotFoundHttpException(ApiViewMessages::ENTITY_NOT_FOUND);
            }

            $this->authorizeApiAction('update', $entity);
            return $this->updateSingle($entity, $content, $transformer);
        }
        elseif(is_array($content)) {
            // Multiple update
            $response = [];

            if (!$partial) {
                $service->wrapInTransaction(function ($em) use ($content, $service, $basis, $mode, $transformer, &$response) {
                    foreach ($content as $item) {
                        $data = [];
                        foreach ($basis as $basisItem) {
                            $data[$basisItem] = $item[$basisItem];
                        }
                        $filter = $this->mixToCommonFilter($data);
                        $entity = $service->get($filter, false);
                        $writeMode = self::$MODE_UPDATE;
                        if(empty($entity) || empty($basis)) {
                            if($mode == 'mixed') {
                                $writeMode = self::$MODE_CREATE;
                                $entity = $service->new();
                            }
                            else continue;
                        }
                        $this->authorizeApiAction($writeMode === self::$MODE_UPDATE ? 'update' : 'create', $entity);
                        $response[] = $this->updateSingle($entity, $item, $transformer, $writeMode, true);
                    }
                });
            } else {
                foreach ($content as $item) {
                    try {
                        $data = [];
                        foreach ($basis as $basisItem) {
                            $data[$basisItem] = $item[$basisItem];
                        }
                        $filter = $this->mixToCommonFilter($data);
                        $entity = $service->get($filter, false);
                        $writeMode = self::$MODE_UPDATE;
                        if(empty($entity) || empty($basis)) {
                            if($mode == 'mixed') {
                                $writeMode = self::$MODE_CREATE;
                                $entity = $service->new();
                            }
                            else continue;
                        }
                        $this->authorizeApiAction($writeMode === self::$MODE_UPDATE ? 'update' : 'create', $entity);
                        $response[] = $this->updateSingle($entity, $item, $transformer, $writeMode, false);
                    } catch (\Exception) {
                        // Partial mode: skip failed items
                    }
                }
            }
        }
        else {
            throw new ValidatorException(ApiViewMessages::CONTENT_TYPE_ERROR);
        }

        return $response;
    }

    #[OA\Post(
        requestBody: new OA\RequestBody(required: false, content: new OA\JsonContent(type: 'object')),
        tags: ['Update'],
        parameters: [
            new OA\Parameter(name: '@mode', in: 'query', schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: '@basis', in: 'query', schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: '@partial', in: 'query', schema: new OA\Schema(type: 'boolean')),
            new OA\Parameter(name: '@transform', in: 'query', schema: new OA\Schema(type: 'string')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Api multiple update view'),
        ]
    )]
    #[Route('/batch-update', name: 'batch-update', methods: ['POST'])]
    public function batchUpdateAction(Request $request): Response
    {
        $response = $this->updateRecords($request);

        if($response === null) {
            throw new ValidatorException(ApiViewMessages::BATCH_UPDATE_ERROR);
        }
        else {
            return $this->success($response);
        }
    }

    #[OA\Put(
        requestBody: new OA\RequestBody(required: false, content: new OA\JsonContent(type: 'object')),
        tags: ['Update'],
        parameters: [
            new OA\Parameter(name: '@transform', in: 'query', schema: new OA\Schema(type: 'string')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Api update view'),
        ]
    )]
    #[Route('/{id}', name: 'update', methods: ['PUT'], requirements: ['id' => '\\d+|[0-9a-fA-F-]{36}'])]
    public function updateAction(Request $request, int|string $id): Response
    {
        try {
            $this->authorizeApiAction('update');
            $response = $this->updateRecords($request, $id);
        } catch (ValidatorException $exception) {
            return $this->warning($exception->getMessage(), 400, '', 400);
        } catch (\InvalidArgumentException $exception) {
            return $this->warning($exception->getMessage(), 400, '', 400);
        } catch (NotFoundHttpException $exception) {
            return $this->warning($exception->getMessage(), 404, '', 404);
        } catch (\Symfony\Component\Security\Core\Exception\AccessDeniedException $exception) {
            return $this->warning($exception->getMessage() ?: ApiViewMessages::ACCESS_DENIED, 403, '', 403);
        } catch (\Exception $exception) {
            return $this->warning($exception->getMessage() ?: self::UNKNOWN_ERROR, 500, '', 500);
        }

        if ($response) {
            return $this->success($response);
        } else {
            return $this->warning();
        }
    }
}
