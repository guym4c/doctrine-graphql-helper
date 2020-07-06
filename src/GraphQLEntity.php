<?php

namespace GraphQL\Doctrine\Helper;

use Doctrine\ORM\EntityManager;
use GraphQL\Doctrine\Definition\EntityID as Relation;
use GraphQL\Doctrine\Helper\Error\InternalError;
use InvalidArgumentException;
use GraphQL\Doctrine\Annotation as API;
use GraphQL;

abstract class GraphQLEntity implements DoctrineUniqueInterface {

    /**
     * @param EntityManager $em
     * @param array $data
     * @param string|null $entityName
     * @param bool $isUpdate
     * @throws InternalError
     */
    public function hydrate(EntityManager $em, array $data, ?string $entityName = null, bool $isUpdate = false): void {

        $entityName = $entityName ?? static::class;

        foreach ($em->getClassMetadata($entityName)->fieldMappings as $field) {
            $fieldName = $field['fieldName'];

            if (
                !$field['nullable']
                && !($field['id'] ?? false)
            ) {
                if (
                    empty($this->{$fieldName})
                    || $isUpdate
                ) {
                    if (
                        empty($data[$fieldName])
                        && !$isUpdate
                    ) {
                        throw new InvalidArgumentException(sprintf("Field %s is required but not provided", $fieldName));
                    } else {
                        $this->hydrateField($fieldName, $data[$fieldName]);
                    }
                }

                unset($data[$fieldName]);
            }
        }

        foreach ($data as $key => $value) {
            if (property_exists($entityName, $key)) {
                $this->hydrateField($key, $value);
            }
        }
    }

    /**
     * @param EntityManager $em
     * @param array $data
     * @throws InternalError
     */
    public function update(EntityManager $em, array $data): void {
        $this->hydrate($em, $data, null, true);
    }

    /**
     * Hydrates a field, parsing the value if it is relational.
     *
     * @param string $key
     * @param object $value
     * @throws InternalError
     */
    private function hydrateField(string $key, $value): void {
        if ($value instanceof Relation) {
            try {
                $value = $value->getEntity();
            } catch (GraphQL\Error\Error $e) {
                throw new InternalError(
                    sprintf(
                        "Could not fetch %s whilst mapping relations from %s ID %s",
                        $value->getId(),
                        static::class,
                        $this->getIdentifier()
                    ),
                );
            }
        }
        $this->{$key} = $value;
    }

    /**
     * @param EntityManager $em
     * @param array $input
     * @return static
     * @throws InternalError
     */
    public static function buildFromJson(EntityManager $em, array $input) {
        $entity = new static();
        $entity->hydrate($em, $input);
        return $entity;
    }

    /**
     * @API\Exclude
     *
     * @param EntityManager $em
     * @param DoctrineUniqueInterface $user
     * @param array $context
     * @param string $method
     * @return bool
     */
    abstract public function hasPermission(
        EntityManager $em,
        DoctrineUniqueInterface $user,
        array $context,
        string $method
    ): bool;

    /*
     * Events - implement if required
     */

    public function beforeDelete(EntityManager $em, array $args): void {}

    public function beforeUpdate(EntityManager $em, array $args): void {}

}