<?php

namespace GraphQL\Doctrine\Helper;

use Doctrine\ORM\EntityManager;
use GraphQL\Doctrine\Definition\EntityID;
use InvalidArgumentException;
use GraphQL;

abstract class GraphQLEntity implements DoctrineUniqueInterface {

    /**
     * @param EntityManager $em
     * @param array         $data
     * @param string|null   $entity
     */
    public function hydrate(EntityManager $em, array $data, ?string $entity = null) {

        $entity = $entity ?? static::class;

        foreach ($em->getClassMetadata($entity)->fieldMappings as $field) {
            $fieldName = $field['fieldName'];

            if (!$field['nullable'] &&
                !($field['id'] ?? false)) {

                if (empty($data[$fieldName])) {
                    throw new InvalidArgumentException(sprintf("Field %s is required but not provided", $fieldName));
                }
                $this->hydrateField($fieldName, $data[$fieldName]);
                unset($data[$fieldName]);
            }
        }

        foreach ($data as $key => $value) {
            if (property_exists($entity, $key)) {
                $this->hydrateField($key, $value);
            }
        }
    }

    /**
     * Hydrates a field, parsing the value if it is relational.
     *
     * @param string $key
     * @param object $value
     */
    private function hydrateField(string $key, $value): void {
        if ($value instanceof EntityID) {
            try {
                $value = $value->getEntity();
            } catch (GraphQL\Error\Error $e) {
                throw new InvalidArgumentException(sprintf("Entity %s was not found in %s", $value->getId(), static::class));
            }
        }
        $this->{$key} = $value;
    }

    public static function buildFromJson(EntityManager $em, array $input) {
        $entity = new static();
        $entity->hydrate($em, $input);
        return $entity;
    }

    public abstract static function hasPermission(EntityManager $em, APIUserInterface $user, string $entityId): bool;

}