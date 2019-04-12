# sussex-ldap

This package is built to allow easy authentication with the Sussex LDAP server. It supports LDAP with an SSH proxy to allow for remote authentication. The package includes a proxyless option to allow for its seamless use in production on local Sussex network.

## Install
Via a local composer repository
```bash
git clone https://github.com/guym4c/sussex-ldap.git sussex-ldap/
```

Add the following to your ```composer.json```:
```json
"repositories": [
    {
        "type": "path",
        "url":"sussex-ldap/"
    }
]
```

Then install
```
composer require guym4c/sussex-ldap
```

This package requires ```php-ssh2```, which must be installed manually from PECL on most PHP installations.

## Usage

### Without proxy

Open a connection to the server:

```php
$ldap = Connection::withoutProxy();
```

To authenticate a user, pass only the bare username (e.g. ```gm335```) and their password.

```php
$result = $ldap->authenticate($user, $password);
if ($result->isBound()) {
    // user was logged in
}
```

If the user was not authorised, the ```BindResult``` that ```authorise()``` returns exposes the error using ```$result->getError()```.

### Over proxy

Open a connection to the server:

```php
$ldap = Connection::overProxy($proxyUsername, $proxyPassword);
```

You may then authenticate a user as above.

## Advanced

The package automatically appends the user domain to the provided username for authentication. Over a proxy, you may provide this using the optional parameter in the relevant constructor (see below). Otherwise, this takes the ```$host``` if provided. In both cases, it defaults to the ```LDAP_DOMAIN``` constant. You may read and configure this using ```$connection->getUserDomain()``` and ```$connection->setUserDomain()``` respectively. All other options are immutable and if you wish to change the configuration after instantiation, simply create a new ```Connection```.

A ```Connection``` exposes its proxy status with ```$connection->isProxied()```.

### Without proxy

The ```Connection::withoutProxy()``` constructor accepts the following options. All default to the Sussex setup at time of the latest release.

```$host```: the LDAP server hostname  
```$baseDn```: the LDAP base DN  
```$options```: an array of PHP ```ext-ldap``` options as defined [here](https://www.php.net/manual/en/ldap.constants.php).

### With proxy

The package sets up the proxy connection over SSH using the provided ```$proxyUsername``` and ```$proxyPassword```. Where no ```$proxyPassword``` is provided it will fall back to username-only SSH agent proxy authentication.

You may also provide the following options:

```$proxyDomain```: the proxy host  
```$userDomain```: the domain that is appended to each user (see above)

## Contributing
Contributions are via a PR.

"# doctrine-graphql-helper" 
