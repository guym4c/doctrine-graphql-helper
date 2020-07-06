<?php

namespace GraphQL\Doctrine\Helper;

use Doctrine\ORM\EntityManager;
use Doctrine\ORM\OptimisticLockException;
use Doctrine\ORM\ORMException;
use GraphQL\Doctrine\DefaultFieldResolver;
use GraphQL\Doctrine\Helper\Error\InternalError;
use GraphQL\Doctrine\Helper\Error\PermissionsError;
use GraphQL\Doctrine\Types;
use GraphQL\Error\UserError;
use GraphQL\GraphQL;
use GraphQL\Server\OperationParams;
use GraphQL\Server\ServerConfig;
use GraphQL\Server\StandardServer;
use GraphQL\Type\Definition\ObjectType;
use GraphQL\Type\Definition\Type;
use GraphQL\Type\Schema;
use GraphQL\Doctrine\Helper\ActionMethod as Resolver;
use League\Container\Container;
use ReflectionClass;
use ReflectionException;

class EntitySchemaBuilder {

    const DEFAULT_RESULT_LIMIT = 50;

    private Schema $schema;

    private ?DoctrineUniqueInterface $user;

    private int $resultLimit;

    private EntityManager $em;

    private Types $types;

    /** @var Mutation[] */
    private array $mutators = [];

    /**
     * EntitySchema constructor.
     * @param EntityManager $em An instance of the entity manager.
     * @param array $entities associative array of the plural form of the fully qualified class name of the entity
     * @param int $resultLimit The maximum amount of results that can be returned by the API.
     */
    public function __construct(
        EntityManager $em,
        array $entities,
        int $resultLimit = self::DEFAULT_RESULT_LIMIT
    ) {
        $types = new Container();
        $types->add('datetime', new DateTimeType());

        $this->em = $em;
        $this->resultLimit = $resultLimit;
        $this->types = new Types($this->em, $types);

        $this->buildSchema($entities);
    }

    public function getServer(DoctrineUniqueInterface $user = null, array $context = []): StandardServer {
        $this->user = $user;

        return new StandardServer(ServerConfig::create()
            ->setSchema($this->schema)
            ->setContext($context)
        );
    }

    private function buildSchema(array $entities): void {
        GraphQL::setDefaultFieldResolver(new DefaultFieldResolver());

        $parsedMutators = [];
        foreach ($this->mutators as $mutator) {
            $parsedMutators[$mutator->getName()] = $mutator->getMutator();
        }

        $this->schema = new Schema([
            'query'    => new ObjectType([
                'name'   => 'query',
                'fields' => $this->getAllQueries($entities),
            ]),
            'mutation' => new ObjectType([
                'name'   => 'mutation',
                'fields' => array_merge(
                    $this->generateMutatorsForEntities(array_values($entities)),
                    $parsedMutators),
            ]),
        ]);
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
            $queries[$key] = $this->listOfQuery($entity);
        }
        return $queries;
    }

    private static string $ID_ARG_DOC = 'Shorthand for a filter for a single ID. You may encounter a 403 response where you do not have permission for a full query against that resource. In this case, you may provide this argument to select an entity you are permitted to access.';
    private static string $LIMIT_ARG_DOC = 'Limits the amount of results returned - %d by default. If you require more results, paginate your requests using limit and offset';
    private static string $OFFSET_ARG_DOC = 'The number of the first record your result set will begin from, inclusive.';

    /**
     * Return a GraphQL list type of entity $entity, with default sorting options and comprehensive Doctrine-compatible sorting.
     *
     * @param string $entity Class name of entity to convert to a GraphQL list
     *
     * @return array The field entry within ObjectType.fields
     */
    private function listOfQuery(string $entity): array {
        return [
            'type'    => $this->listOfType($entity),
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
                    'description' => self::$ID_ARG_DOC,
                ],
                [
                    'name'        => 'limit',
                    'type'        => Type::int(),
                    'description' => sprintf(self::$LIMIT_ARG_DOC, $this->resultLimit),
                ],
                [
                    'name'        => 'offset',
                    'type'        => Type::int(),
                    'description' => self::$OFFSET_ARG_DOC,
                ],
            ],
            'resolve' => function ($root, $args, $context) use ($entity) {
                return $this->resolveQuery($args, $entity, null, $context);
            }
        ];
    }

    public function listOfType(string $entity): Type {
        return Type::listOf($this->types->getOutput($entity));
    }

    /**
     * Resolve a simple 'get' query against entity $entity, parsing filtering and sorting as given by listOf().
     * If $only is not provided, then you must provide the query $context.
     *
     * @param array $args The arguments posted with this query
     * @param string $entityName The entity classname to query against
     * @param DoctrineUniqueInterface|null $only Optionally, restrict unfiltered queries to only return this entity.
     * @param array $context
     * @return mixed If successful, an array of results
     */
    public function resolveQuery(array $args, string $entityName, ?DoctrineUniqueInterface $only = null, array $context = []) {

        if (!empty($only)) {
            $args['id'] = $only->getIdentifier();
        }

        if (!$this->isPermitted($args, $entityName, $context)) {
            throw new PermissionsError($entityName);
        }

        $queryBuilder = $this->types->createFilteredQueryBuilder($entityName,
            $args['filter'] ?? [],
            $args['sorting'] ?? []);

        $query = $queryBuilder->select();

        if (!empty($args['id'])) {
            $query->where($queryBuilder->expr()
                ->eq($query->getRootAliases()[0] . '.identifier', ':id')
            );
            $query->setParameter('id', $args['id']);
        }

        $n = $args['limit'] ?? self::DEFAULT_RESULT_LIMIT;
        $offset = $args['offset'] ?? 0;

        return $query->getQuery()
            ->setFirstResult($offset)
            ->setMaxResults($n)
            ->execute();
    }

    /**
     * Wraps getMutators() and generates mutators for all entities in $entities.
     * @param array $entities An array of entity type class names that the mutators should act upon.
     * @return array A list of mutator types
     * @see self::getMutators()
     */
    private function generateMutatorsForEntities(array $entities): array {
        $mutators = [];
        foreach ($entities as $entity) {
            $mutators = array_merge($mutators, $this->generateMutatorsForEntity($entity));
        }
        return $mutators;
    }

    /**
     * Generates create, update and delete mutators against $entity, which must implement DoctrineUniqueInterface.
     *
     * @param string $entity The entity type class name that the mutators should act upon.
     * @return array A list of mutator types
     */
    private function generateMutatorsForEntity(string $entity): ?array {

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
            }, null, Type::nonNull(Type::id()))
        ];
    }

    /**
     * Generates a mutator from the provided type, args and resolver. By default, the mutator returns a list type of $entity using listOf().
     *
     * @param string $entityName The entity which the mutator acts against
     * @param array $args An array of args that this mutator will possess
     * @param callable $resolver A callable resolver in the format function($root, $args)
     * @param string|null $description
     * @param Type|null $type If specified, a type that will override the default given above
     *
     * @return array The mutator type
     */
    public function getMutator(string $entityName, array $args, callable $resolver, ?string $description = null, ?Type $type = null): array {
        return [
            'type'        => $type ?? Type::listOf($this->types->getOutput($entityName)),
            'args'        => $args,
            'resolve'     => $resolver,
            'description' => $description ?? '',
        ];
    }

    /**
     * @param array $args
     * @param string $entityName
     * @param array $context
     * @param string $method
     * @return bool
     */
    public function isPermitted(array $args, string $entityName, array $context, string $method = Resolver::GET): bool {
        if ($this->user === null) {
            return true;
        }

        /** @var GraphQLEntity $entity */
        $entity = $this->em->getRepository($entityName)
            ->find($args['id']);
        return $entity->hasPermission($this->em, $this->user, $context, $method);
    }

    /**
     * Dispatches mutations to the appropriate resolver for their $method.
     *
     * @param array $args
     * @param array $context
     * @param string $entityName
     * @param string $method
     * @return mixed
     * @throws ORMException|OptimisticLockException|UserError|InternalError
     */
    private function mutationResolver(array $args, array $context, string $entityName, string $method) {

        if (!$this->isPermitted($args, $entityName, $context, $method)) {
            throw new PermissionsError($entityName, $method);
        }

        switch ($method) {
            case ActionMethod::CREATE:
                return $this->createResolver($args, $entityName);
            case ActionMethod::UPDATE:
                return $this->updateResolver($args, $entityName);
            case ActionMethod::DELETE:
                return $this->deleteResolver($args, $entityName);
            default:
                throw new InternalError('Invalid action %s on entity %s', $method, $entityName);
        }
    }

    /**
     * Calls $entity::buildFromJson and persists the result, where $entity is the class name of a GraphQLConstructableInterface, and then resolves the rest of the query.
     *
     * @param array $args The query args from the calling mutator
     * @param string $entityName The entity which the resolver acts against
     *
     * @return mixed The rest of the query, resolved
     * @throws ORMException
     * @throws OptimisticLockException
     */
    private function createResolver(array $args, string $entityName) {

        /** @var GraphQLEntity $new */
        $new = call_user_func($entityName . '::buildFromJson', $this->em, $args['input']);

        $this->em->persist($new);
        $this->em->flush();

        return $this->resolveQuery($args, $entityName, $new);
    }

    /**
     * Calls $entity::updateFromJson and persists the result, where $entity is the class name of a GraphQLEntity, and then resolves the rest of the query.
     *
     * @param array $args The query args from the calling mutator
     * @param string $entityName The entity which the resolver acts against
     *
     * @return mixed The rest of the query, resolved
     * @throws ORMException|OptimisticLockException|InternalError
     */
    private function updateResolver(array $args, string $entityName) {

        /** @var GraphQLEntity $entity */
        $entity = $this->em->getRepository($entityName)
            ->find($args['id']);

        $entity->beforeUpdate($this->em, $args);

        $entity->hydrate($this->em, $args['input'], $entityName, true);

        $this->em->flush();

        return $this->resolveQuery($args, $entityName, $entity);
    }

    /**
     * Removes entity $entity and returns its ID.
     *
     * @param array $args The query args from the calling mutator
     * @param string $entityName The entity which the resolver acts against
     * @return mixed
     * @throws ORMException
     * @throws OptimisticLockException
     */
    private function deleteResolver(array $args, string $entityName) {
        /** @var GraphQLEntity $condemned */
        $condemned = $this->em->getRepository($entityName)->find($args['id']);

        $condemned->beforeDelete($this->em, $args);

        $this->em->remove($condemned);
        $this->em->flush();

        return $args['id'];
    }

    public function getSchema(): Schema {
        return $this->schema;
    }

    public function getTypes(): Types {
        return $this->types;
    }

    public function getUser(): DoctrineUniqueInterface {
        return $this->user;
    }

    /**
     * Generate a blank mutation pre-associated with this builder
     *
     * @param string $name
     * @return Mutation
     */
    public function mutation(string $name): Mutation {
        $mutation = new Mutation($this, $name);
        $this->mutators[$name] = $mutation;
        return $mutation;
    }

    public static function jsonToOperation(array $json): OperationParams {
        return OperationParams::create([
            'query'     => $json['query'],
            'variables' => $json['variables'] ?? null,
        ]);
    }
}
