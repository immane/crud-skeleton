<?php

namespace App\Core\View;

use App\Core\Service\BaseService;
use App\Core\Utils\UUID;
use OpenApi\Attributes as OA;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\Validator\Exception\ValidatorException;

trait WorkflowApiViewMixin
{
    // protected $workflow;

    #[OA\Get(
        tags: ['Workflow'],
        parameters: [
            new OA\Parameter(name: 'page', in: 'query', schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'limit', in: 'query', schema: new OA\Schema(type: 'string')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Todo list'),
        ]
    )]
    #[Route('/todo', name: 'todo-list', methods: ['GET'])]
    public function todoAction()
    {
        $service = $this->service ?? $this->container->get($this->serviceClass);
        $entities = BaseService::listResultToCollection(
            $service->list(null, null, false)
        )->toArray();

        // TODO: this method will VERY SLOW when reached the large apply entry.
        $entities = array_filter($entities, function ($entity) {
            $workflow = $this->container->get($this->workflow);
            return count($workflow->getEnabledTransitions($entity));
        });

        return $this->success($entities);
    }

    #[OA\Get(
        tags: ['Workflow'],
        responses: [
            new OA\Response(response: 200, description: 'List enabled transitions'),
        ]
    )]
    #[Route('/{id}/transitions', name: 'available-transition', methods: ['GET'], requirements: ['id' => '\d+|[0-9a-fA-F-]{36}'])]
    public function availableTransitionsAction(int|string $id): Response
    {
        try {
            $this->authorizeApiAction('workflow');
            $service = $this->service ?? $this->container->get($this->serviceClass);
            $filter = method_exists($this, 'mixIdToCommonFilter')
                ? $this->mixIdToCommonFilter($id)
                : [UUID::is_valid((string) $id) ? 'uuid' : 'id' => $id];
            $entity = $service->get($filter, false);
            if ($entity === null) {
                return $this->warning(ApiViewMessages::ENTITY_NOT_FOUND, 404, '', 404);
            }
            $this->authorizeApiAction('workflow', $entity);
            $workflow = $this->container->get($this->workflow);
            $transitions = $workflow->getEnabledTransitions($entity);

            return $this->success($transitions);
        } catch (\Symfony\Component\Security\Core\Exception\AccessDeniedException $e) {
            return $this->warning($e->getMessage() ?: ApiViewMessages::ACCESS_DENIED, 403, '', 403);
        } catch (\Throwable $e) {
            return $this->warning($e->getMessage() ?: self::UNKNOWN_ERROR, 500, '', 500);
        }
    }

    #[OA\Post(
        tags: ['Workflow'],
        responses: [
            new OA\Response(response: 200, description: 'Do transition'),
        ]
    )]
    #[Route('/{id}/do/{transition}', name: 'do-transition', methods: ['POST'], requirements: ['id' => '\d+|[0-9a-fA-F-]{36}'])]
    public function doTransitionAction(Request $request, int|string $id, string $transition): Response
    {
        try {
            $this->authorizeApiAction('workflow');
            $service = $this->service ?? $this->container->get($this->serviceClass);
            $filter = method_exists($this, 'mixIdToCommonFilter')
                ? $this->mixIdToCommonFilter($id)
                : [UUID::is_valid((string) $id) ? 'uuid' : 'id' => $id];
            $entity = $service->get($filter, false);
            if ($entity === null) {
                return $this->warning(ApiViewMessages::ENTITY_NOT_FOUND, 404, '', 404);
            }
            $this->authorizeApiAction('workflow', $entity);
            $workflow = $this->container->get($this->workflow);

            if (!$workflow->can($entity, $transition)) {
                throw new ValidatorException(ApiViewMessages::TRANSITION_CANNOT_APPLY);
            }

            $content = json_decode($request->getContent(), true);

            $service->wrapInTransaction(function ($em) use ($service, $entity, $content, $workflow, $transition) {
                if ($content) {
                    $service->update($entity, $content);
                }
                $workflow->apply($entity, $transition);
            });
        } catch (ValidatorException $e) {
            return $this->warning($e->getMessage(), 400, '', 400);
        } catch (\Symfony\Component\Security\Core\Exception\AccessDeniedException $e) {
            return $this->warning($e->getMessage() ?: ApiViewMessages::ACCESS_DENIED, 403, '', 403);
        } catch (\Throwable $e) {
            return $this->warning($e->getMessage() ?: self::UNKNOWN_ERROR, 500, '', 500);
        }

        return $this->success();
    }

    #[OA\Put(
        tags: ['Workflow'],
        responses: [
            new OA\Response(response: 200, description: 'Reset marking'),
        ]
    )]
    #[IsGranted('ROLE_ADMIN')]
    #[Route('/{id}/status-reset', name: 'reset-status', methods: ['PUT'], requirements: ['id' => '\d+|[0-9a-fA-F-]{36}'])]
    public function resetMarkingAction(int|string $id): Response
    {
        try {
            $service = $this->service ?? $this->container->get($this->serviceClass);
            $filter = method_exists($this, 'mixIdToCommonFilter')
                ? $this->mixIdToCommonFilter($id)
                : [UUID::is_valid((string) $id) ? 'uuid' : 'id' => $id];
            $entity = $service->get($filter, false);
            if ($entity === null) {
                return $this->warning(ApiViewMessages::ENTITY_NOT_FOUND, 404, '', 404);
            }
            $this->authorizeApiAction('workflow', $entity);
            if (!method_exists($entity, 'setStatus')) {
                return $this->warning('Entity does not support status reset.', 400, '', 400);
            }
            $entity->setStatus([]);
            $this->container->get('doctrine')->getManager()->flush();

            return $this->success();
        } catch (\Symfony\Component\Security\Core\Exception\AccessDeniedException $e) {
            return $this->warning($e->getMessage() ?: ApiViewMessages::ACCESS_DENIED, 403, '', 403);
        } catch (\Throwable $e) {
            return $this->warning($e->getMessage() ?: self::UNKNOWN_ERROR, 500, '', 500);
        }
    }
}
