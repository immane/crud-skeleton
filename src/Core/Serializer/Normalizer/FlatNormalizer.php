<?php

namespace App\Core\Serializer\Normalizer;

use App\Core\Serializer\ExpansionMetadata;
use Symfony\Component\Serializer\Exception\ExceptionInterface;
use Symfony\Component\Serializer\Normalizer\DenormalizerInterface;
use Symfony\Component\Serializer\Normalizer\NormalizerInterface;
use Symfony\Component\Serializer\SerializerAwareInterface;
use Symfony\Component\Serializer\SerializerInterface;
use Symfony\Component\Serializer\Normalizer\NormalizerAwareInterface;
use Symfony\Component\PropertyAccess\PropertyAccessorInterface;

class FlatNormalizer implements NormalizerInterface, DenormalizerInterface, NormalizerAwareInterface, SerializerAwareInterface
{
    private NormalizerInterface $decorated;
    private PropertyAccessorInterface $accessor;

    public function __construct(NormalizerInterface $decorated, PropertyAccessorInterface $accessor)
    {
        $this->decorated = $decorated;
        $this->accessor = $accessor;
    }


    public function supportsNormalization(mixed $data, ?string $format = null, array $context = []): bool
    {
        return is_object($data);
    }

    public function getSupportedTypes(?string $format): array
    {
        // indicate we support any object type and that supportsNormalization is cacheable
        return ['object' => true];
    }

    /**
     * @throws ExceptionInterface
     */
    public function normalize($object, ?string $format = null, array $context = []): float|array|bool|\ArrayObject|int|string|null
    {
        if (!is_object($object)) {
            return null;
        }

        $className = get_class($object);

        // Avoid normalizing Doctrine internal objects (ClassMetadata, etc.)
        if (str_starts_with($className, 'Doctrine\\ORM\\') || str_starts_with($className, 'Doctrine\\Persistence\\')) {
            if (method_exists($object, '__toString')) {
                return (string) $object;
            }
            return get_class($object);
        }

        // First let the decorated normalizer produce the baseline representation
        try {
            $data = $this->decorated->normalize($object, $format, $context);
        } catch (\Throwable $e) {
            // When normalization fails (e.g. Doctrine internal objects in collections),
            // return a minimal representation
            if (method_exists($object, 'getId') && method_exists($object, '__toString')) {
                return ['id' => $object->getId(), '__toString' => (string) $object];
            }
            return ['__class' => get_class($object)];
        }

        if (!is_array($data)) {
            return $data;
        }

        // Add __toString at top level when available
        if (method_exists($object, '__toString')) {
            $data['__toString'] = (string) $object;
        }

        // For each attribute, try to read the raw value via PropertyAccessor to apply the same
        // flattening logic that the old GetSetMethodNormalizer override did.
        foreach (array_keys($data) as $attribute) {
            // Skip internal __metadata marker to avoid recursion (self-reference would otherwise loop)
            if ($attribute === '__metadata') {
                continue;
            }
            try {
                // Use the accessor to call the getter (works for getXxx / isXxx / hasXxx)
                $raw = $this->accessor->getValue($object, $attribute);
            } catch (\Throwable $e) {
                // If we cannot access the raw value, skip transformation and keep normalized value
                continue;
            }

            // Reduce transform function for related objects
            $reduceTransform = function (object $o): array {
                $res = [];
                if (method_exists($o, 'getId')) {
                    $res['id'] = $o->getId();
                }
                if (method_exists($o, '__toString')) {
                    $res['__toString'] = $o->__toString();
                }
                if (method_exists($o, '__metadata')) {
                    $res['__metadata'] = $o->__metadata();
                } elseif (property_exists($o, '__metadata')) {
                    $res['__metadata'] = $o->__metadata;
                }

                return $res;
            };

            // when object is a relation
            if (is_object($raw) && method_exists($raw, 'getId')) {
                $isExpanded = ExpansionMetadata::isMarked($raw);
                if ($isExpanded) {
                    try {
                        $full = $this->decorated->normalize($raw, $format, $context);
                        if (is_array($full)) {
                            if (method_exists($raw, '__toString')) {
                                $full['__toString'] = (string) $raw;
                            }
                            $full['__metadata'] = $full;
                            $data[$attribute] = $full;
                            continue;
                        }
                    } catch (\Throwable $e) {
                        // fallback to reduced
                    }
                }
                $data[$attribute] = $reduceTransform($raw);
                continue;
            }

            // when value is traversable (collections)
            if ($raw instanceof \Traversable) {
                $tmp = [];
                foreach ($raw as $o) {
                    if (is_object($o) && method_exists($o, 'getId')) {
                        // If expanded via @expands (__metadata is object), return full normalized data
                        $isExpanded = ExpansionMetadata::isMarked($o);
                        if ($isExpanded) {
                            try {
                                $full = $this->decorated->normalize($o, $format, $context);
                                if (is_array($full)) {
                                    if (method_exists($o, '__toString')) {
                                        $full['__toString'] = (string) $o;
                                    }
                                    // Keep __metadata marker for frontend compatibility but as expanded data
                                    $full['__metadata'] = $full;
                                    $tmp[] = $full;
                                    continue;
                                }
                            } catch (\Throwable $e) {
                                // fallback to reduced
                            }
                        }
                        $tmp[] = $reduceTransform($o);
                    }
                }
                $data[$attribute] = $tmp;
                continue;
            }

            // string, including JSON object, exclude numeric string
            if (is_string($raw) && !is_numeric($raw)) {
                $json = json_decode($raw, true);
                if (json_last_error() === JSON_ERROR_NONE) {
                    $data[$attribute] = $json;
                }
                continue;
            }

            // otherwise keep the decorated normalized value (covers scalars / nested arrays already normalized)
        }

        return $data;
    }

    public function supportsDenormalization(mixed $data, string $type, ?string $format = null, array $context = []): bool
    {
        return $this->decorated instanceof DenormalizerInterface
            && $this->decorated->supportsDenormalization($data, $type, $format, $context);
    }

    public function denormalize(mixed $data, string $type, ?string $format = null, array $context = []): mixed
    {
        if (!$this->decorated instanceof DenormalizerInterface) {
            throw new \LogicException('Decorated normalizer cannot denormalize values.');
        }

        return $this->decorated->denormalize($data, $type, $format, $context);
    }

    /**
     * Forward serializer to decorated normalizer if it supports it, or
     * forward normalizer when appropriate. This avoids issues where the
     * decorated AbstractObjectNormalizer expects a serializer that also
     * implements NormalizerInterface.
     */
    public function setSerializer(SerializerInterface $serializer): void
    {
        // If the decorated normalizer is aware of serializer, forward it
        if ($this->decorated instanceof SerializerAwareInterface) {
            $this->decorated->setSerializer($serializer);
            return;
        }

        // If decorated supports being given a normalizer and the serializer
        // provided also implements NormalizerInterface, forward as normalizer.
        if ($this->decorated instanceof NormalizerAwareInterface && $serializer instanceof NormalizerInterface) {
            $this->decorated->setNormalizer($serializer);
        }
    }

    /**
     * Set the normalizer on the decorated normalizer if it supports that.
     */
    public function setNormalizer(NormalizerInterface $normalizer): void
    {
        if ($this->decorated instanceof NormalizerAwareInterface) {
            $this->decorated->setNormalizer($normalizer);
        }
        // Also, if the decorated expects a serializer instead, forward it when possible
        if ($this->decorated instanceof SerializerAwareInterface && $normalizer instanceof SerializerInterface) {
            $this->decorated->setSerializer($normalizer);
        }
    }
}
