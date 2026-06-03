--TEST--
PHP 8.4 asymmetric visibility on constructor-promoted properties (#4690)
--FILE--
<?php
class User {
    public function __construct(
        public private(set) string $name,
    ) {}

    public function rename(string $n): void {
        $this->name = $n;
    }
}

$u = new User('alice');
echo $u->name, "\n";
$u->rename('bob');
echo $u->name, "\n";
try {
    $u->name = 'eve';
    echo "no error\n";
} catch (Error $e) {
    echo $e->getMessage(), "\n";
}
--EXPECT--
alice
bob
Cannot modify private(set) property User::$name from global scope
