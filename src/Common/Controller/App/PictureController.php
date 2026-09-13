<?php /** @noinspection PhpMissingParentConstructorInspection */

namespace App\Common\Controller\App;

use App\Common\Entity\Picture;
use App\Common\Service\PictureServiceInterface;
use App\Core\Controller\RestController;
use App\Core\Query\DqlExpression;
use App\Core\View\ApiViewMessages;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;
use App\Core\View\ApiView;
use App\Core\View\CreateApiViewMixin;
use App\Core\View\DeleteApiViewMixin;
use App\Core\View\DetailApiViewMixin;
use App\Core\View\ListApiViewMixin;
use App\Core\View\UpdateApiViewMixin;
use App\Identity\Entity\User;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/app/pictures', name: 'app-pictures-')]
class PictureController extends RestController
{
    use ApiView, DetailApiViewMixin, ListApiViewMixin,
        CreateApiViewMixin, UpdateApiViewMixin, DeleteApiViewMixin;

    /** @var list<string> */
    protected array $requiredCreateProperties = ['category', 'image'];
    /** @var list<string> */
    protected array $acceptedCreateProperties = ['title', 'category', 'image', 'metadata'];
    /** @var list<string> */
    protected array $acceptedUpdateProperties = ['title', 'category', 'image', 'metadata'];

    public function __construct(
        protected readonly PictureServiceInterface $service
    ) {}

    /** @return array<string, mixed>|DqlExpression */
    protected function commonFilter(): array|DqlExpression
    {
        $user = $this->getUser();
        if (!$user instanceof User) {
            return new DqlExpression('!entity.getUser()');
        }

        return new DqlExpression(
            '!entity.getUser() || entity.getUser() == user',
            ['user' => $user],
        );
    }

    protected function authorizeApiAction(string $action, ?object $entity = null): void
    {
        // Public (user IS NULL) pictures are read-only via /app; writes stay owner-only.
        if (($action === 'update' || $action === 'delete') && $entity instanceof Picture) {
            $user = $this->getUser();
            $owner = $entity->getUser();
            if (!$user instanceof User || $owner === null || $owner->getId() !== $user->getId()) {
                throw new AccessDeniedException(ApiViewMessages::ACCESS_DENIED);
            }
        }
    }

    /**
     * @param array<string, mixed> $content
     */
    protected function processEntity(array $content, object $entity): object
    {
        $user = $this->getUser();
        if ($user instanceof User && method_exists($entity, 'setUser')) {
            $entity->setUser($user);
        }

        return $entity;
    }
}
