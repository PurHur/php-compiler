--TEST--
stdlib defined() — enum case operand TypeError (#7172, ext/standard/basic_functions.c)
--FILE--
<?php
declare(strict_types=1);

enum E: string { case A = 'x'; }

try {
    defined(E::A);
    echo "uncaught\n";
} catch (TypeError $e) {
    echo $e->getMessage(), "\n";
}

define('OK', 1);
var_dump(defined('OK'));
?>
--EXPECT--
defined(): Argument #1 ($constant) must be of type string, E given
bool(true)
