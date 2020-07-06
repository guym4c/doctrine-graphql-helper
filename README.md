# doctrine-graphql-helper

Generate a PSR-7 GraphQL API server from an instance of the Doctrine ORM in just a few lines of PHP, with permissions and custom mutation resolvers available out-of-the-box.

## Install
Via Composer:
```bash
composer install guym4c/doctrine-graphql-helper
```

## Usage
This package is a helper package for [`graphql-php`](https://github.com/webonyx/graphql-php) and [`graphql-doctrine`](https://github.com/ecodev/graphql-doctrine), which install automatically with it. Entities used with this package must be [`graphql-doctrine`](https://github.com/ecodev/graphql-doctrine) -compatible. Refer to these packages’ documentation for more details.

### Implementing `GraphQLEntity`
Any entities which you wish to use in your API must extend `GraphQLEntity` and implement the required methods.

You probably won't want any methods you implement to be added to the schema by `graphql-doctrine`, and so you must annotate them as excluded:
```php
use GraphQL\Doctrine\Annotation as API;

/**
 * @API\Exclude
 */
public function someMethod() {}
```

#### Entity constructors
When an entity is created over GraphQL using a create mutator, it is constructed using the `buildFromJson()` static, which calls the constructor with no parameters. If your entity has a constructor with parameters, then you will need to override `buildFromJson()` in your entity class and call the constructor yourself.

After you've performed any tasks you need to, you may still use the inherited `hydrate()` call after this to fill out the object with the input data. You must unset any properties that you have already hydrated yourself from the input array `$data` before you make this call, or your existing properties will be overwritten.

#### Events
In addition to the events that Doctrine provides you with, the schema builder adds events that fire during the execution of some resolvers: `beforeUpdate()` and `beforeDelete()`. You may extend these from `GraphQLEntity`. Both fire immediately after the entity in its initial state is retrieved from the ORM, and before any operation is performed. (For `beforeDelete()` in particular, these means all fields, including generated values, are accessible.)

#### Permissions
You always need to implement `hasPermission()`, regardless of whether you intend to implement permissions at this level or not. You can find more details on implementing permissions below, or just stub it out with a `return true;` for the moment. For security reasons, the builder does not default to this.

### Building the schema
Construct a schema builder on entities of your choice. You must provide an instance of the Doctrine entity manager, and an associative array where the key is the plural form of the entity's name, and the value is the fully-qualified class name of the entity definition. For example:
```php
$builder = new EntitySchemaBuilder($em, [
    'owners' => Owner::class,
    'dogs'  => Dog::class,
    'cats'  => Cat::class,
]);
```

### Running queries
You may use your built schema in a GraphQL server of your choice, or use the helper’s integration with `graphql-php` to retrieve a server object already set up with your schema by calling `getServer()`.

The server returned accepts a request object in its `executeRequest()` method. In some cases you may wish to run a raw JSON payload through the server. To do this, can parse the JSON to a format which the server will accept as a parameter to `executeRequest()` by calling `EntitySchemaBuilder::jsonToOperation($json)`.

### Using permissions
Permissions are managed using the handler you implemented when extending `GraphQLEntity`. The `hasPermission()` handler is passed 4 parameters to help you implement this:
```php
abstract public function hasPermission(
    EntityManager $em, // an instance of the entity manager
    DoctrineUniqueInterface $user, // the user you passed to getServer()
    array $context, // array of additional context you optionally passed to getServer()
    string $method // action method verb
): bool;
```
The `method` corresponds to the action verb assigned to the currently executing query or mutation. The generated queries and mutators use pre-set CRUD-like verbs: `get`, `create`, `update` and `delete`, but you can use any verb you choose when writing your own mutators.

## Using custom mutators
The schema generator exposes a simple API for adding your own mutators, and a class (`Mutation`). This wraps some advanced functionality of graphql-doctrine, and so reference to that package’s documentation may or will be required using this feature.
You must instantiate `Mutation` by passing the name of the mutator to the factory method of an `EntitySchemaBuilder`. This associates the `Mutation` with the builder and will include it in any schema generated with it. 

There are two methods of hydrating the new `Mutation` returned by the factory: using the chainable methods exposed by `Mutation`, or by providing all parameters at once to its `hydrate()` method. The examples below use method chaining, but the same principles apply to `hydrate()`.

**`setEntity()`:** Set the class name of the entity that this mutator operates on. This must be set, and is used to auto-generate the type if none was provided.

**`setType()`:** Set the GraphQL return type of the mutator. If not configured, this will be a non-empty list of the entity provided in `setEntity()`. The default type is compatible with the builder’s `resolveQuery()` method, if called in your resolver function (see below).

**`setDescription()`:**	Set a description returned by the server in introspection queries (optional).

**`setArgs()`:** Set the arguments that may be given when this mutator is queried. By default, this is a non-null (required) ID type, allowing you to retrieve the entity that the ID refers to using the entity manager.

**`setMethod()`:** Set the action verb that this mutator uses for permissions purposes.

**`setResolver()`:**	You must provide a callable here that takes two arguments – an array of your mutator’s args and the API user making the request. You must return the data that you wish to be returned with the response to the query, and that data must of the correct type – methods that can assist with this are provided in `EntitySchemaBuilder`, and it is suggested that you define this callable in a variable scope where you have access to it and the entity manager. Failure to resolve data of the correct type will result in the server returning an error.

## Methods exposed by the builder
The schema builder exposes a variety of methods which may be of use when writing resolver functions. You may wish to consult the documentation of `graphql-php` and `graphql-doctrine` for more information on the values that some of these methods return.

**`listOfType()`:** When given an entity’s class name, returns a GraphQL return type of a list of the entity.

**`resolveQuery()`:** Resolves a query using the entity manager. Requires the args array given with the query, and the class name of the root entity being queried. You may also pass in an instance of the entity as the third parameter to fully resolve and then return this entity.

**`getMutator()`:**	Generates a mutator (in its array form, not a `Mutation`) from the provided type, args and resolver.  

**`setUserEntity()`:** Changes the user from that given during instantiation.

**`getTypes()`:** Retrieve the types that have been generated for use in the schema.

**`isPermitted()`:** Resolves the permission level of a query, given its args, query context and entity class name.


