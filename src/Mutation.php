<?php


namespace Guym4c\GraphQL\Doctrine\Helper;

use GraphQL\Doctrine\Types;
use GraphQL\Type\Definition\Type;

class Mutation {

    /** @var string */
    private $name;

    /** @var string */
    private $entity;

    /** @var callable */
    private $resolver;

    /** @var Type|null */
    private $type;

    /** @var array */
    private $args;

    /** @var EntitySchemaBuilder */
    private $builder;

    /** @var Types */
    private $types;

    /**
     * Mutation constructor. To be called from the factory in an EntitySchemaBuilder
     * @param EntitySchemaBuilder $builder
     * @param Types               $types
     */
    public function __construct(EntitySchemaBuilder $builder, Types $types) {
        $this->builder = $builder;
        $this->types = $types;
    }

    /**
     * Mutation constructor.
     * @param string    $name     The name of this mutation
     * @param string    $entity   The entity classname that this mutation will operate on
     * @param callable  $resolver The resolver class, in the format function ($root, $args, $context)
     * @param Type|null $type     The return type. If not provided, this defaults to a list of $entity
     * @param array     $args
     */
    public function hydrate(string $name, string $entity, callable $resolver, ?Type $type = null, array $args = []) {
        $this->name = $name;
        $this->entity = $entity;
        $this->resolver = $resolver;
        $this->type = $type ?? Type::listOf($this->types->getOutput($entity));
    }

    public function getMutator(): array {
        return $this->builder->getMutator($this->entity, $this->args, $this->resolver, $this->type);
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
     * @return Types
     */
    public function types(): Types {
        return $this->types;
    }

    /**
     * @return string
     */
    public function name(): string {
        return $this->name;
    }
}