--TEST--
Language: PHP 8.4 parenthesized asymmetric visibility public (private(set)) (#6897)
--FILE--
<?php
declare(strict_types=1);

trait T {
    public static (protected(set)) string $name = 't';
}

class C {
    use T;
}

echo C::$name, "\n";
try {
    C::$name = 'b';
} catch (Error $e) {
    echo get_class($e), ': ', $e->getMessage(), "\n";
}
echo C::$name, "\n";

class User {
    public function __construct(
        public (private(set)) string $name,
    ) {}
}

$u = new User('alice');
echo $u->name, "\n";
try {
    $u->name = 'bob';
} catch (Error $e) {
    echo get_class($e), ': ', $e->getMessage(), "\n";
}
echo $u->name, "\n";
--EXPECT--
t
Error: Cannot modify protected(set) property T::$name from global scope
t
alice
Error: Cannot modify private(set) property User::$name from global scope
alice
