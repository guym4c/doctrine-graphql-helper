# doctrine-graphql-helper

Easily create a GraphQL schema from Doctrine entities.

## Install
Via a local composer repository
```bash
git clone https://github.com/guym4c/doctrine-graphql-helper.git sussex-ldap/
```

Add the following to your ```composer.json```:
```json
"repositories": [
    {
        "type": "path",
        "url":"doctrine-graphql-helper/"
    }
]
```

Then install
```
composer require guym4c/doctrine-graphql-helper @dev
```

## Usage
This package is a helper package for [`graphql-php`](https://github.com/webonyx/graphql-php) and [`graphql-doctrine`](https://github.com/ecodev/graphql-doctrine), which install automatically with it. Entities used with this package must be graphql-doctrine-compatible. Refer to these packages’ documentation for more details.

### `GraphQLEntity`
Any entities which you wish to use in your API must extend `GraphQLEntity` and implement the required methods.

#### Entity constructors
When an entity is created over GraphQL using a create mutator, it is constructed using `buildFromJson()`, which in turn calls the `hydrate()` method. If your entity has a constructor with parameters, then you will need to override `buildFromJson()` in your entity class and call the constructor yourself. You may still use `hydrate()` after this call, but remember to unset any fields that you have already hydrated from the (JSON) array before you make this call or they will be overwritten.

#### Events
In addition to the events that Doctrine provides, the schema builder adds events that fire during the execution of some resolvers: `beforeUpdate()` and `beforeDelete()`. You may extend these from `GraphQLEntity`. Both fire immediately after the entity in its initial state is retrieved from Doctrine, and before any operation is performed. (For `beforeDelete()` in particular, these means all fields, including generated values, are accessible.)

### Building the schema
Construct a schema builder on entities of your choice. You must provide an instance of the Doctrine entity manager:
```php
$builder = new EntitySchemaBuilder($em);
```

You may then build the schema from an associative array where the key is the plural form of the entity's name, and the value is the class name of the entity. For example:
```php
$schema = $builder->build([
    'users' => User::class,
    'dogs'  => Dog::class,
    'cats'  => Cat::class,
]);
```

Note that `getSchema()` does not build the schema, but retrieves the most recently built schema.

### Setting permissions
If you wish to use permissions, you may also provide the EntitySchemaBuilder constructor with:

* An array of scopes and the permissions on methods acting upon them (example below)
* The class name of the user entity, which must implement ApiUserInterface

### Running queries
You may use your built schema in a GraphQL server of your choice, or use the helper’s integration with `graphql-php` to retrieve a server object already set up with your schema and any permissions settings you have defined by calling `getServer()`.

### Using permissions
If you have set the schema builder’s permissions during instantiation, provide the permitted scopes (as an array) and the user’s identifier to the `getServer()` method to execute the query with permissions enabled.
The schema generator generates four queries for each provided entity, which have parallels to the HTTP request methods used in REST: a simple `GET` query, and `POST` (create), `UPDATE` and `DELETE` mutators. You may define the permissions at method-level granularity using the scopes array, provided to the builder’s constructor.

For example:

```php
$scopes = [
    'admin' => ['*'],
    'vet' => [
        'dog' => [
            'get' => 'all',
            'update' => 'all',
        ],
        'cat' => [
            'get' => 'all',
            'update' => 'all',
        ],
        'user' => [
            'get' => 'permissive',
            'update' => 'permissive',
        ],
    ],
    'dog-owner' => [
        'dog' => [
            'get' => 'permissive',
            'update' => 'permissive',
        ],
    ],

    // etc.
];
```

An asterisk (\*) is a wildcard, indicating full permissions are given for this scope. Otherwise, each entity is assigned a permission on a per-method basis. Methods and entities without defined permissions will be assumed to be accessible to all users. Each method may be assigned one of three values:

* **All:** Accessible to all users with this scope
* **None:** Not accessible to users with this scope
* **Permissive:**	Users permissions with this scope are resolved in the entity’s `hasPermission()` method. If you don’t wish to use permissive, but are running the server with permissions enabled, simply implement the method with a return true. 
The `hasPermission()` static is called for all methods that are defined as permissive, and you are passed an instance of the Doctrine entity manager, an instance of your API user class as `ApiUserInterface`, and the ID of the entity that is being queried by the user. You are not given an instantiated version of the entity class being called: if you wish, you must retrieve this from the entity manager manually using the provided entity ID.

## Using custom mutators
The schema generator exposes a simple API for adding your own mutators, and a class (`Mutation`). This wraps some advanced functionality of graphql-doctrine, and so reference to that package’s documentation may or will be required using this feature.
You must instantiate `Mutation` by passing the name of the mutator to the factory method of an `EntitySchemaBuilder`. This associates the `Mutation` with the builder and will include it in any schema generated with it. 

There are two methods of hydrating the new `Mutation` returned by the factory: using the chainable methods exposed by `Mutation`, or by providing all parameters at once to its `hydrate()` method. The examples below use method chaining, but the same principles apply to `hydrate()`.

**`setEntity()`:** Set the class name of the entity that this mutator operates on. This must be set, and is used to auto-generate the type if none was provided.

**`setType()`:** Set the GraphQL return type of the mutator. If not configured, this will be a non-empty list of the entity provided in `setEntity()`. The default type is compatible with the builder’s `resolveQuery()` method, if called in your resolver function (see below).

**`setDescription()`:**	Set a description returned by the server in introspection queries (optional).

**`setArgs()`:**	Set the arguments that may be given when this mutator is queried. By default, this is a non-null (required) ID type, allowing you to retrieve the entity that ID refers to using the entity manager.

**`usePermissions()`:**	It is expected that you will implement your own permissions check in your resolver, but as a fallback you may hook in to the helper’s permissions system by setting `usePermissions()` to `true` and giving a valid query method to `setMethod()`. The helper will then use the permission level assigned to the `Mutation`’s set entity and provided method using the scopes of the request. 

**`setResolver()`:**	You must provide a callable here that takes two arguments – an array of your mutator’s args and the user ID of the user making the request. You must return the data that you wish to be returned with the response to the query, and that data must of the correct type – methods that can assist with this are provided in `EntitySchemaBuilder`, and it is suggested that you define this callable in a variable scope where you have access to it and the entity manager. Failure to resolve data of the correct type will result in the server returning 500.

## Methods exposed by the builder
The schema builder exposes a variety of methods which may be of use when writing resolver functions. You may wish to consult the documentation of `graphql-php` and `graphql-doctrine` for more information on the values that some of these methods return.

**`immutableListOf()`:** When given an entity’s class name, returns a GraphQL return type of a list of the entity.

**`resolveQuery()`:** Resolves a query using the entity manager. Requires the args array given with the query, and the class name of the root entity being queried. You may also pass in an instance of the entity as the third parameter to fully resolve and then return this entity.

**`getMutator()`:**	Generates a mutator (in its array form, not a `Mutation`) from the provided type, args and resolver.  

**`setUserEntity()`:** Changes the user entity class name from that given during instantiation.

**`getTypes()`:** Retrieve the types generated by `graphql-doctrine` from the entities provided during the most recent `build()`. 

**`isPermitted()`:** Resolves the permission level of a query, given its args, query context and entity class name. The query context is a value used internally by the schema builder and is an associative array of the following format: 

```php
$context = [
    'scopes' => [],// array of this request's scopes
    'user'   => '',// user ID
];
```



