<?php
declare(strict_types=1);

namespace App\Core\Service\Concern;

use App\Core\Utils\Inflect;
use App\Core\Utils\UUID;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\Mapping\ManyToMany;
use Doctrine\ORM\Mapping\ManyToOne;
use Doctrine\ORM\Mapping\OneToMany;
use Doctrine\ORM\Mapping\OneToOne;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Validator\Exception\ValidatorException;

/** @template TEntity of object */
trait BaseServiceMutationTrait
{
    /**
     * @return TEntity
     */
    public function new()
    {
        $ref = new \ReflectionClass($this->entityClass);
        $ctor = $ref->getConstructor();
        if ($ctor === null || $ctor->getNumberOfRequiredParameters() === 0) {
            $object = $ref->newInstance();
        } else {
            $object = $ref->newInstanceWithoutConstructor();
        }

        if ($ref->hasProperty('uuid')) {
            $uuidProperty = $ref->getProperty('uuid');
            if (!$uuidProperty->isInitialized($object)) {
                $uuidProperty->setValue($object, UUID::v4());
            }
        }

        return $object;
    }

    /**
     * @param array<string, mixed>|null $data
     * @return object|false
     * @throws \ReflectionException
     */
    public function update(mixed $object, ?array $data = null, bool $noFlush = false): object|false
    {
        if (empty($object)) {
            $this->logger->error('Object error, original data: '. json_encode($data));
            throw new ValidatorException('Update object cannot be null');
        }

        if (!is_object($object)) {
            $this->logger->error('Object error, original data: '. json_encode($data));
            throw new ValidatorException('Update object cannot be null');
        }

        if (method_exists($object, 'getId') && $object->getId()) {
            $object = $this->get($object->getId()) ?: $object;
        }

        if (!empty($data)) {
            $serializer = $this->getSerializer();

            try {
                if (!is_object($object)) {
                    throw new \RuntimeException('Object became invalid during update');
                }
                $reflect = new \ReflectionClass(get_class($object));

                foreach ($data as $key => $val) {
                    if (!$reflect->hasProperty($key)) {
                        continue;
                    }
                    $property = $reflect->getProperty($key);
                    $annotations = $this->getPropertyMetadata($property);
                    foreach ($annotations as $annotation) {
                        if (
                            $annotation instanceof ManyToOne ||
                            $annotation instanceof OneToOne
                        ) {
                            $dataClass = $annotation->targetEntity;
                            if ($dataClass === null) {
                                continue;
                            }
                            $rep = $this->em->getRepository($dataClass);

                            $entity = null;
                            if ($val && empty($entity = $rep->find($val))) {
                                throw new NotFoundHttpException("The entity of key[$key] is not found");
                            } else {
                                $setter = 'set' . ucfirst($key);
                                $object->$setter($entity);

                                unset($data[$key]);
                            }
                            break;
                        }
                        elseif(
                            $annotation instanceof ManyToMany ||
                            $annotation instanceof OneToMany
                        ) {
                            $dataClass = $annotation->targetEntity;
                            if ($dataClass === null) {
                                continue;
                            }
                            $rep = $this->em->getRepository($dataClass);

                            $ucfirst = ucfirst($key);
                            $getter = "get$ucfirst";
                            $entities = $object->$getter() ?? new ArrayCollection();
                            $entitiesIds = $entities->map(function ($entity) {
                                return $entity->getId();
                            })->toArray();

                            $removes = array_values(array_diff($entitiesIds, $val));
                            $adds = array_values(array_diff($val, $entitiesIds));

                            $singularize = ucfirst(Inflect::singularize($key));
                            $adder = "add$singularize";
                            $remover = "remove$singularize";

                            foreach ($removes as $remove) {
                                $entity = $rep->find($remove);
                                $object->$remover($entity);
                            }
                            foreach ($adds as $add) {
                                if (empty($entity = $rep->find($add))) {
                                    throw new NotFoundHttpException("The entity of key[$key] is not found");
                                } else {
                                    $object->$adder($entity);
                                }
                            }

                            unset($data[$key]);
                        }
                        else {
                            if ($this->isDateLikeMapping($annotation, $property)) {
                                $setter = 'set' . ucfirst($key);

                                if($val instanceof \DateTimeInterface) {
                                    $object->$setter($val);
                                }
                                else {
                                    $object->$setter(new \DateTime((string) $val));
                                }

                                unset($data[$key]);
                            }
                        }
                    }
                }
            } catch (\ReflectionException $e) {
                $this->logger->error('Save entity error: '.$e->getMessage());
                return false;
            } catch (\Exception $e) {
                $this->logger->error('Object error, original data: '. json_encode($data));
                throw $e;
            }

            if ($serializer === null) { // @phpstan-ignore identical.alwaysFalse
                throw new \RuntimeException('Serializer service is not available. Ensure the Symfony serializer is registered and that ServiceLocator provides it.');
            }

            if (!is_object($object)) { // @phpstan-ignore function.alreadyNarrowedType
                throw new \RuntimeException('Object became invalid during update');
            }

            $serializer->deserialize(
                json_encode($data),
                get_class($object),
                'json',
                [
                    'object_to_populate' => $object
                ]
            );
        }

        $validator = $this->getValidator();
        $errors = $validator ? $validator->validate($object) : [];
        if (count($errors) > 0) {
            $errorsString = (string)$errors;
            throw new ValidatorException($errorsString);
        }

        if (!is_object($object)) { // @phpstan-ignore function.alreadyNarrowedType
            throw new \RuntimeException('Object became invalid during update');
        }

        try {
            $this->em->persist($object);
            if (!$noFlush) {
                $this->em->flush();
            }
        }
        catch (UniqueConstraintViolationException $ex) {
            throw new ValidatorException('Duplication entries');
        }
        catch (\Exception $exception) {
            throw $exception;
        }

        return $object;
    }

    /**
     * @param TEntity|int|string|array<string, mixed> $object
     */
    public function remove($object): bool
    {
        $object = $this->get($object);

        if (!is_object($object)) {
            return false;
        }

        $this->em->remove($object);
        try {
            $this->em->flush();
            return true;
        } catch (\Exception $e) {
            return false;
        }
    }

    /**
     * @return array<int, object>
     */
    private function getPropertyMetadata(\ReflectionProperty $property): array
    {
        $metadata = [];

        foreach ($property->getAttributes() as $attribute) {
            $metadata[] = $attribute->newInstance();
        }

        return $metadata;
    }

    private function isDateLikeMapping(object $mapping, \ReflectionProperty $property): bool
    {
        if (property_exists($mapping, 'type')) {
            /** @var mixed $type */
            $type = $mapping->type;
            if (in_array($type, ['datetime', 'date', 'time', 'datetime_immutable', 'date_immutable'], true)) {
                return true;
            }
        }

        $type = $property->getType();
        if (!$type instanceof \ReflectionNamedType) {
            return false;
        }

        $name = ltrim($type->getName(), '\\');
        return in_array($name, ['DateTime', 'DateTimeImmutable', 'DateTimeInterface'], true);
    }
}
