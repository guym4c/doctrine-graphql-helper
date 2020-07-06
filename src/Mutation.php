<?php

namespace GraphQL\Doctrine\Helper;

use GraphQL\Doctrine\Helper\Error\PermissionsError;
use GraphQL\Type\Definition\Type;

class Mutation {

    const DEFAULT_METHOD = ActionMethod::UPDATE;

    private string $name;

    private string $entity;

    /** @var callable */
    private $resolver;

    private ?Type $type;

    private array $args = [];

    private EntitySchemaBuilder $builder;

    private string $method;

    private ?string $description;

    /**
     * Mutation constructor
     * @param EntitySchemaBuilder $builder
     * @param string              $name
     */
    public function __construct(EntitySchemaBuilder $builder, string $name) {
        $this->builder = $builder;
        $this->name = $name;
        $this->method = self::DEFAULT_METHOD;
    }

    /**
     * Hydrate a mutation.
     * @param string      $entity   The entity classname that this mutation will operate on
     * @param callable    $resolver The resolver class, in the format function ($args)
     * @param Type|null   $type     The return type. If not provided, this defaults to a list of $entity
     * @param string|null $method
     * @param array       $args
     * @param string|null $description
     */
    public function hydrate(
        string $entity,
        callable $resolver,
        ?Type $type = null,
        ?string $method = null,
        array $args = [],
        ?string $description = null
    ) {
        $this->entity = $entity;
        $this->resolver = $resolver;
        $this->type = $type ?? Type::listOf($this->builder->getTypes()->getOutput($entity));
        $this->method = $method ?? self::DEFAULT_METHOD;
        $this->args = $args;
        $this->description = $description;
    }

    public function getMutator(): array {
        return $this->builder->getMutator(
            $this->entity,
            $this->args,
            $this->getResolver(),
            $this->description,
            $this->type,
        );
    }

    /**
     * @param string $name
     * @return self
     */
    public function setName(string $name): self {
        $this->name = $name;
        return $this;
    }

    /**
     * @param string $entity
     * @return self
     */
    public function setEntity(string $entity): self {
        $this->entity = $entity;
        return $this;
    }

    /**
     * @param callable $resolver
     * @return self
     */
    public function setResolver(callable $resolver): self {
        $this->resolver = $resolver;
        return $this;
    }

    /**
     * @param Type|null $type
     * @return self
     */
    public function setType(?Type $type): self {
        $this->type = $type;
        return $this;
    }

    /**
     * @param array $args
     * @return self
     */
    public function setArgs(array $args): self {
        $this->args = $args;
        return $this;
    }

    /**
     * @param string|null $description
     * @return self
     */
    public function setDescription(?string $description): self {
        $this->description = $description;
        return $this;
    }

    /**
     * @param string $method
     * @return self
     */
    public function setMethod(string $method): self {
        $this->method = $method;
        return $this;
    }

    /**
     * @return string
     */
    public function getName(): string {
        return $this->name;
    }

    /**
     * @return callable
     */
    public function getResolver(): callable {
        return function ($root, $args, $context) {

            if (!$this->builder->isPermitted($args, $this->entity, $context, $this->method)) {
                throw new PermissionsError($this->entity);
            }

            return ($this->resolver)($args, $this->builder->getUser());
        };
    }


}