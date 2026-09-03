<?php

namespace App\Core\View;

use App\Core\Controller\RestController;
use App\Core\Service\BaseService;
use App\Core\Utils\UUID;
use OpenApi\Attributes as OA;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\Validator\Exception\ValidatorException;
use Symfony\Component\Workflow\WorkflowInterface;

/**
 * @phpstan-require-extends RestController
 */
trait WorkflowApiViewMixin
{
    /**
     * Resolve the Symfony Workflow instance for this controller.
     *
     * Override point for controllers that inject the workflow as a typed property
     * (e.g. OrderController's $orderWorkflow). The default resolves the workflow
     * service by id from the container (`$this->workflow` holds the service id,
     * e.g. 'state_machine.order'). This makes the trait work with both the
     * service-locator-based controller container and constructor-injected workflows.
     */
    protected function workflow(): WorkflowInterface
    {
        // Prefer an injected workflow property if the controller has one (e.g. $orderWorkflow)
        foreach (['orderWorkflow', 'workflowService', 'stateMachine'] as $prop) {
            if (isset($this->$prop) && $this->$prop instanceof WorkflowInterface) {
                return $this->$prop;
            }
        }
        // Fallback: $this->workflow holds the service id string (e.g. 'state_machine.order')
        if ($this->workflow !== '') {
            /** @var WorkflowInterface $workflow */
            $workflow = $this->container->get($this->workflow);
            return $workflow;
        }
        throw new \LogicException('Workflow not configured. Define $workflow service id or override workflow() to return the WorkflowInterface.');
    }

    /**
     * Authorize a workflow transition. Override to add ownership or role checks.
     * Default delegates to authorizeApiAction('workflow', $entity).
     */
    protected function authorizeTransition(string $transition, object $entity): void
    {
        $this->authorizeApiAction('workflow', $entity);
    }

    /**
     * Hook before applying a workflow transition. Override for side effects
     * (e.g. cancel linked invoice, validate business rules).
     *
     * @param array<string, mixed>|null $content
     */
    protected function beforeTransition(string $transition, object $entity, ?array $content): void
    {
    }

    /**
     * Hook after applying a workflow transition.
     *
     * @param array<string, mixed>|null $content
     */
    protected function afterTransition(string $transition, object $entity, ?array $content): void
    {
    }

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
    public function todoAction(): Response
    {
        /** @var BaseService<object> $service */
        $service = $this->getService();
        $entities = BaseService::listResultToCollection(
            $service->list(null, null, false)
        )->toArray();

        $entities = array_filter($entities, function ($entity): bool {
            $workflow = $this->workflow();
            return count($workflow->getEnabledTransitions($entity)) > 0;
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
            /** @var BaseService<object> $service */
            $service = $this->getService();
            $filter = $this->mixIdToCommonFilter($id);
            $entity = $service->get($filter, false);
            if ($entity === null) {
                return $this->warning(ApiViewMessages::ENTITY_NOT_FOUND, 404, '', 404);
            }
            $this->authorizeTransition('__transitions__', $entity);
            $workflow = $this->workflow();
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
            /** @var BaseService<object> $service */
            $service = $this->getService();
            $filter = $this->mixIdToCommonFilter($id);
            $entity = $service->get($filter, false);
            if ($entity === null) {
                return $this->warning(ApiViewMessages::ENTITY_NOT_FOUND, 404, '', 404);
            }
            $this->authorizeTransition($transition, $entity);
            $workflow = $this->workflow();

            if (!$workflow->can($entity, $transition)) {
                throw new ValidatorException(ApiViewMessages::TRANSITION_CANNOT_APPLY);
            }

            $rawContent = json_decode($request->getContent(), true);
            $content = is_array($rawContent) ? $rawContent : null;
            // Whitelist update payload via controller's acceptedUpdateProperties to prevent
            // arbitrary field tampering through the transition body (e.g. totalAmount).
            $filteredContent = $content;
            if (is_array($content)) {
                $vars = get_object_vars($this);
                $accepted = $vars['acceptedUpdateProperties'] ?? null;
                if (is_array($accepted) && $accepted !== []) {
                    $filtered = array_intersect_key($content, array_flip($accepted));
                    $filteredContent = $filtered !== [] ? $filtered : null;
                }
            }

            $service->wrapInTransaction(function ($em) use ($service, $entity, $content, $filteredContent, $workflow, $transition): void {
                $this->beforeTransition($transition, $entity, $content);
                if (null !== $filteredContent && $filteredContent !== []) {
                    $service->update($entity, $filteredContent);
                }
                $workflow->apply($entity, $transition);
            });

            $this->afterTransition($transition, $entity, $content);
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
            /** @var BaseService<object> $service */
            $service = $this->getService();
            $filter = $this->mixIdToCommonFilter($id);
            $entity = $service->get($filter, false);
            if ($entity === null) {
                return $this->warning(ApiViewMessages::ENTITY_NOT_FOUND, 404, '', 404);
            }
            $this->authorizeTransition('reset', $entity);
            if (!method_exists($entity, 'setStatus')) {
                return $this->warning('Entity does not support status reset.', 400, '', 400);
            }
            $param = (new \ReflectionMethod($entity, 'setStatus'))->getParameters()[0] ?? null;
            $type = $param?->getType();
            if ($type !== null && (string) $type === 'string') {
                return $this->warning('Entity does not support workflow status reset.', 400, '', 400);
            }
            // Use reflection to avoid phpstan's "object::setStatus" undefined method error
            $reflection = new \ReflectionMethod($entity, 'setStatus');
            $reflection->invoke($entity, []);
            $this->container->get('doctrine')->getManager()->flush();

            return $this->success();
        } catch (\Symfony\Component\Security\Core\Exception\AccessDeniedException $e) {
            return $this->warning($e->getMessage() ?: ApiViewMessages::ACCESS_DENIED, 403, '', 403);
        } catch (\Throwable $e) {
            return $this->warning($e->getMessage() ?: self::UNKNOWN_ERROR, 500, '', 500);
        }
    }
}
