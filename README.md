# ETCD adapter for php-casbin

[![License](https://poser.pugx.org/casbin/database-adapter/license)](https://packagist.org/packages/casbin/database-adapter)

ETCD adapter for [PHP-Casbin](https://github.com/php-casbin/php-casbin).

### Usage

```php

require_once './vendor/autoload.php';

use Casbin\Enforcer;
use CasbinAdapter\Etcd\Adapter as EtcdAdapter;

$adapter = new EtcdAdapter($server, $version);

$e = new Enforcer('path/to/model.conf', $adapter);

$sub = "alice"; // the user that wants to access a resource.
$obj = "data1"; // the resource that is going to be accessed.
$act = "read"; // the operation that the user performs on the resource.

if ($e->enforce($sub, $obj, $act) === true) {
    // permit alice to read data1
} else {
    // deny the request, show an error
}
```

### Getting Help

- [php-casbin](https://github.com/php-casbin/php-casbin)

### License

This project is licensed under the [Apache 2.0 license](LICENSE).