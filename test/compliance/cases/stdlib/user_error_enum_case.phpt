--TEST--
stdlib user_error() — enum case message TypeError (#6183, php-src-strict)
--FILE--
<?php
declare(strict_types=1);

enum E: string { case A = 'msg'; }

try {
    user_error(E::A);
    echo "uncaught\n";
} catch (TypeError $e) {
    echo $e->getMessage(), "\n";
}
?>
--EXPECT--
user_error(): Argument #1 ($message) must be of type string, E given
