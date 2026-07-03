--TEST--
PHP 8.4 asymmetric visibility: constructor-promoted public private(set) (#6981, zend_compile.c)
--FILE--
<?php
class User {
    public function __construct(
        public private(set) string $name,
    ) {}
}
$u = new User('alice');
echo $u->name, "\n";
try {
    $u->name = 'bob';
} catch (Error $e) {
    echo get_class($e), ': ', $e->getMessage(), "\n";
}
--EXPECT--
alice
Error: Cannot modify public private(set) property User::$name from global scope
