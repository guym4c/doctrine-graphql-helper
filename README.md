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

This package is a helper package for [`graphql-php`](https://github.com/webonyx/graphql-php) and [`graphql-doctrine`](https://github.com/ecodev/graphql-doctrine), which install automatically with it. Entities used with this package must be `graphql-doctrine`-compatible. Refer to these packages' documentation for more details.

### `GraphQLEntity`

Any entities which you wish to use in your API must extend `GraphQLEntity`, and implement the required methods.

#### Entity constructors
When an entity is created over GraphQL, it is constructed using `buildFromJson()`, which in turn calls the `hydrate()` method. If your entity has a constructor with parameters, then you will need to override `buildFromJson()` in your entity class and call the constructor yourself. You may still use `hydrate()` after this call, but remember to unset any fields that you have already hydrated from the (JSON) array before you make this call or they will be overwritten.

#### Events
In addition to the events that Doctrine provides, the schema builder will include events that fire during the execution of some resolvers: `beforeUpdate()` and `beforeDelete()`. You may extend these from `GraphQLEntity`. Both fire immediately after the entity in its initial state is retrieved from Doctrine, and before any operation is performed. (For `beforeDelete()` in particular, these means all fields are accessible.)

### Building the schema

Construct a schema builder on entities of your choice. You must provide the Doctrine entity manager and an associative array where the key is the plural form of the entity's name, and the value is the class name of the entity. For example:

```php
$builder = new EntitySchemaBuilder($em, [
    'users' => User::class,
    'dogs'  => Dog::class,
    'cats'  => Cat::class,
]);
```

#### Setting permissions

If you wish to use permissions, you may also provide:
* An array of scopes and the permissions on methods acting upon them (example below)
* The class name of the user entity, which must implement `ApiUserInterface`

### Running queries

You may then retrieve just the schema, or its GraphQL server.
```php
$schema = $builder->getSchema();

// or

$server = $builder->getServer();
```

#### Providing request permissions
If you have provided the scopes and the permissions they contain above, then you can provide the request's allowed scopes and the request user ID to `getServer()`.

#### Example of scopes array

Each 'method' can be assigned a permission level per-entity. `*` may be used a wildcard. A list of methods can be found in `ResolverMethod`.

For example:

```php
class MyPermissions extends Permission {

protected static $scopes = [
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

Where a permission is defined as `permissive`, the entity's `hasPermission()` method is called.
