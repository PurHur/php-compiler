--TEST--
PHP 8.4 asymmetric visibility: JIT on constructor-promoted private(set) (#4690)
--JIT--
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
    echo "no error\n";
} catch (Error $e) {
    echo $e->getMessage(), "\n";
}
--EXPECT--
alice
Cannot modify private(set) property User::$name from global scope
