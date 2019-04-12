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
composer require guym4c/doctrine-graphql-helper
```

## Usage

Construct a schema builder:

```php
$builder = new EntitySchema($em);
```

Where `$em` is an instance of the Doctrine entity manager.
If you wish to use permissions, you may also provide the class of the user entity (this must implement `ApiUserInterface`).

Then, build the schema on the provided entities.

```php
$schema = $builder->build([
    'users' => User::class,
    'dogs'  => Dog::class,
    'cats'  => Cat::class,
]);
```

A shorthand method is also given to construct a server using the schema.

```php
EntitySchema::getServer($schema);
```

### Custom constructors
You may extend `buildFromJson()`, if you need to customise how parameters are set when entities are first created. You can call `parent::hydrate()` for any other parameters - in this case, you must unset the parameters that your subclass has used, so that they are not overwritten.

### Permissions

When constructing a server, you may provide the `$scopes` and `$userId` of the user making the request. 
To assign permissions to each scope, you must extend the `Permission` class and simply assign a permission level to each method (in `ResolverMethod`) of each entity. `*` may be used a wildcard.

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

Where a permission is defined as `permissive`, the entity's `hasPermission()` method is called, which must be implemented.