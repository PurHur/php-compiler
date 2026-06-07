--TEST--
stdlib setcookie() — enum case value TypeError (#6019, ext/standard/head.c, php-src-strict)
--FILE--
<?php
enum E: string { case A = 'v'; }
try {
    setcookie('n', E::A);
    echo "uncaught\n";
} catch (TypeError $e) {
    echo get_class($e), ': ', $e->getMessage(), "\n";
}
--EXPECT--
TypeError: setcookie(): Argument #2 ($value) must be of type string, E given
