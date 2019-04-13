<?php

namespace GraphQL\Doctrine\Helper;

use Doctrine\ORM\EntityManager;
use Doctrine\ORM\OptimisticLockException;
use Doctrine\ORM\ORMException;
use GraphQL\Doctrine\DefaultFieldResolver;
use GraphQL\Doctrine\Types;
use GraphQL\GraphQL;
use GraphQL\Server\ServerConfig;
use GraphQL\Server\StandardServer;
use GraphQL\Type\Definition\ObjectType;
use GraphQL\Type\Definition\Type;
use GraphQL\Type\Schema;
use ReflectionClass;
use ReflectionException;
use GraphQL\Doctrine\Helper\ResolverMethod as Resolver;

class EntitySchemaBuilder {

    const DEFAULT_RESULT_LIMIT = 50;

    /** @var Schema */
    private $schema;

    /** @var string|null */
    private $userEntity;

    /** @var int */
    private $resultLimit;

    /** @var EntityManager */
    private $em;

    /** @var Types */
    private $types;

    /** @var Permissions|null */
    private $permissions;

    /**
     * EntitySchema constructor.
     * @param EntityManager    $em          An instance of the entity manager.
     * @param array            $entities    An associative array of the plural form to the fully qualified class name of the entity.
     * @param Permissions|null $permissions
     * @param string           $userEntity  The class name of the user entity. If this is null, all permissions will be given to all users.
     * @param int              $resultLimit The maximum amount of results that can be returned by the API.
     */
    public function __construct(EntityManager $em, array $entities, ?Permissions $permissions = null, ?string $userEntity = null, int $resultLimit = self::DEFAULT_RESULT_LIMIT) {
        $this->userEntity = $userEntity;
        $this->resultLimit = $resultLimit;
        $this->em = $em;
        $this->types = new Types($this->em);
        $this->permissions = $permissions;
        $this->build($entities);
    }

    public function getServer(array $scopes = [], ?string $userId = null): StandardServer {
        return new StandardServer(ServerConfig::create()
            ->setSchema($this->schema)
            ->setContext([
                'scopes' => $scopes,
                'user' => $userId,
            ]));
    }

    /**
     * Builds the schema, where $entities is an associative array of the plural form to the fully qualified class name of the entity.
     *
     * @param array $entities
     * @return Schema
     */
    public function build(array $entities): Schema {

        GraphQL::setDefaultFieldResolver(new DefaultFieldResolver());

        $this->schema = new Schema([
            'query'    => new ObjectType([
                'name'   => 'query',
                'fields' => $this->getAllQueries($entities),
            ]),
            'mutation' => new ObjectType([
                'name'   => 'mutation',
                'fields' => array_merge(
                    $this->getAllMutators(array_values($entities))
                ),
            ]),
        ]);

        return $this->schema;
    }

    /**
     * Return a list of query field types for all $entities, where $entities is an associative array mapping the plural form of the entity name to its class name.
     *
     * @param array $entities
     * @return array
     */
    private function getAllQueries(array $entities) {
        $queries = [];
        foreach ($entities as $key => $entity) {
            $queries[$key] = $this->listOf($entity);
        }
        return $queries;
    }

    /**
     * Return a GraphQL list type of entity $entity, with default sorting options and comprehensive Doctrine-compatible sorting.
     *
     * @param string $entity Class name of entity to convert to a GraphQL list
     *
     * @return array The field entry within ObjectType.fields
     */
    private function listOf(string $entity): array {
        return [
            'type'    => Type::listOf($this->types->getOutput($entity)),
            'args'    => [
                [
                    'name' => 'filter',
                    'type' => $this->types->getFilter($entity),
                ],
                [
                    'name' => 'sorting',
                    'type' => $this->types->getSorting($entity),
                ],
                [
                    'name'        => 'id',
                    'type'        => Type::id(),
                    'description' => 'Shorthand for a filter for a single ID. You may encounter a 403 response where you do not have permission for a full query against that resource. In this case, you may provide this argument to select an entity you are permitted to access.',
                ],
                [
                    'name'        => 'limit',
                    'type'        => Type::int(),
                    'description' => sprintf('Limits the amount of results returned - %d by default. If you require more results, paginate your requests using limit and offset', $this->resultLimit),
                ],
                [
                    'name'        => 'offset',
                    'type'        => Type::int(),
                    'description' => 'The number of the first record your result set will begin from, inclusive.'
                ]
            ],
            'resolve' => function ($root, $args, $context) use ($entity) {
                return $this->resolveQuery($args, $entity, null, $context);
            }
        ];
    }

    /**
     * Resolve a simple 'get' query against entity $entity, parsing filtering and sorting as given by listOf().
     * If $only is not provided, then you must provide the query $context.
     *
     * @param array                        $args   The arguments posted with this query
     * @param string                       $entity The entity to query against
     * @param DoctrineUniqueInterface|null $only   Optionally, restrict unfiltered queries to only return this entity.
     * @param array                        $context
     * @return mixed If successful, an array of
     */
    private function resolveQuery(array $args, string $entity, ?DoctrineUniqueInterface $only = null, array $context = []) {

        if (!empty($only)) {
            $args['id'] = $only->getIdentifier();
        } else if (count($context) > 0 &&
            !$this->isPermitted($args, $context, $entity)) {
            return [403];
        }

        $qb = $this->types->createFilteredQueryBuilder($entity,
            $args['filter'] ?? [],
            $args['sorting'] ?? []);

        $query = $qb->select();
        $params = [];

        if (!empty($args['id'])) {
            $query->where(
                $qb->expr()
                    ->eq($query->getRootAliases()[0] . '.identifier', ':id'));
            $params['id'] = $args['id'];
        }

        $n = $args['limit'] ?? self::DEFAULT_RESULT_LIMIT;
        $offset = $args['offset'] ?? 0;

        return $query->getQuery()
            ->setParameters($params)
            ->setFirstResult($offset)
            ->setMaxResults($n)
            ->execute();
    }

    /**
     * Generates create, update and delete mutators against $entity, which must implement DoctrineUniqueInterface.
     *
     * @param string $entity The entity type class name that the mutators should act upon.
     * @return array A list of mutator types
     */
    private function getMutators(string $entity): array {

        try {
            $entityName = (new ReflectionClass($entity))->getShortName();
        } catch (ReflectionException $e) {
            return null;
        }

        return [
            'create' . $entityName => $this->getMutator($entity, [
                'input' => Type::nonNull($this->types->getInput($entity)),
            ], function ($root, $args, $context) use ($entity) {
                return $this->mutationResolver($args, $context, $entity, Resolver::CREATE);
            }),

            'update' . $entityName => $this->getMutator($entity, [
                'id'    => Type::nonNull(Type::id()),
                'input' => $this->types->getPartialInput($entity),
            ], function ($root, $args, $context) use ($entity) {
                return $this->mutationResolver($args, $context, $entity, Resolver::UPDATE);
            }),

            'delete' . $entityName => $this->getMutator($entity, [
                'id' => Type::nonNull(Type::id()),
            ], function ($root, $args, $context) use ($entity) {
                return $this->mutationResolver($args, $context, $entity, Resolver::DELETE);
            }, Type::nonNull(Type::id()))
        ];
    }

    /**
     * Wraps getMutators() and generates mutators for all entities in $entities.
     * @param array $entities An array of entity type class names that the mutators should act upon.
     * @return array A list of mutator types
     * @see self::getMutators()
     */
    private function getAllMutators(array $entities): array {
        $mutators = [];
        foreach ($entities as $entity) {
            $mutators = array_merge($mutators, $this->getMutators($entity));
        }
        return $mutators;
    }

    /**
     * Generates a mutator from the provided type, args and resolver. By default, the mutator returns a list type of $entity using listOf().
     *
     * @param string    $entity   The entity which the mutator acts against
     * @param array     $args     An array of args that this mutator will possess
     * @param callable  $resolver A callable resolver in the format function($root, $args)
     * @param Type|null $type     If specified, a type that will override the default given above
     *
     * @return array The mutator type
     */
    private function getMutator(string $entity, array $args, callable $resolver, ?Type $type = null): array {
        if (empty($type)) {
            $type = Type::listOf($this->types->getOutput($entity));
        }
        return [
            'type'    => $type,
            'args'    => $args,
            'resolve' => $resolver,
        ];
    }

    /**
     * @param array  $args
     * @param array  $context
     * @param string $entity
     * @param string $method
     * @return bool
     */
    public function isPermitted(array $args, array $context, string $entity, string $method = 'GET'): bool {

        if ($context['user'] == null) {
            return true;
        }

        $permitted = false;
        foreach ($context['scopes'] as $scope) {
            switch ($this->permissions::getPermission($scope, $entity, $method)) {

                case $this->permissions::ALL:
                    $permitted = true;
                    break;

                case $this->permissions::PERMISSIVE:
                    $permitted = call_user_func($entity . '::hasPermission', $this->em,
                        $this->em->getRepository($this->userEntity)->find($context['user']),
                        $this->em->getRepository($entity)->find($args['id']));
                    break;

                case $this->permissions::NONE:
                    break;
            }

            if ($permitted) {
                break;
            }
        }

        return $permitted;
    }

    /**
     * Dispatches mutations to the appropriate resolver for their $method.
     *
     * @param array  $args
     * @param array  $context
     * @param string $entity
     * @param string $method
     * @return mixed
     * @throws ORMException
     * @throws OptimisticLockException
     */
    private function mutationResolver(array $args, array $context, string $entity, string $method) {

        $permitted = $this->isPermitted($args, $context, $entity, $method);

        if (!$permitted) {
            return [403];
        }

        switch ($method) {
            case 'create':
                return $this->createResolver($args, $entity);
                break;

            case 'update':
                return $this->updateResolver($args, $entity);
                break;

            case 'delete':
                return $this->deleteResolver($args, $entity);
                break;
        }

        return [400];
    }

    /**
     * Calls $entity::buildFromJson and persists the result, where $entity is the class name of a GraphQLConstructableInterface, and then resolves the rest of the query.
     *
     * @param array  $args   The query args from the calling mutator
     * @param string $entity The entity which the resolver acts against
     *
     * @return mixed The rest of the query, resolved
     * @throws ORMException
     * @throws OptimisticLockException
     */
    private function createResolver(array $args, string $entity) {

        /** @var GraphQLEntity $new */
        $new = call_user_func($entity . '::buildFromJson', $this->em, $args['input']);

        $this->em->persist($new);
        $this->em->flush();

        return $this->resolveQuery($args, $entity, $new);
    }

    /**
     * Calls $entity::updateFromJson and persists the result, where $entity is the class name of a GraphQLEntity, and then resolves the rest of the query.
     *
     * @param array  $args   The query args from the calling mutator
     * @param string $entity The entity which the resolver acts against
     *
     * @return mixed The rest of the query, resolved
     */
    private function updateResolver(array $args, string $entity) {

        /** @var GraphQLEntity $update */
        $update = $this->em->getRepository($entity)->find($args['id']);

        $update->beforeUpdate($this->em, $args);

        $update->hydrate($this->em, $args['input'], $entity);

        return $this->resolveQuery($args, $entity, $update);
    }

    /**
     * Removes entity $entity and returns its ID.
     *
     * @param array  $args   The query args from the calling mutator
     * @param string $entity The entity which the resolver acts against
     * @return mixed
     * @throws ORMException
     * @throws OptimisticLockException
     */
    private function deleteResolver(array $args, string $entity) {

        /** @var GraphQLEntity $condemned */
        $condemned = $this->em->getRepository($entity)->find($args['id']);

        $condemned->beforeDelete($this->em, $args);

        $this->em->remove($condemned);
        $this->em->flush();

        return $args['id'];
    }

    /**
     * @param string|null $userEntity
     */
    public function setUserEntity(?string $userEntity): void {
        $this->userEntity = $userEntity;
    }

    /**
     * @param int $resultLimit
     */
    public function setResultLimit(int $resultLimit): void {
        $this->resultLimit = $resultLimit;
    }

    /**
     * @return Schema
     */
    public function getSchema(): Schema {
        return $this->schema;
    }
}