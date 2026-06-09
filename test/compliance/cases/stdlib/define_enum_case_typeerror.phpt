--TEST--
stdlib define() — enum case operand TypeError (#6582, ext/standard/basic_functions.c)
--FILE--
<?php
declare(strict_types=1);

enum E: string { case A = 'x'; }

try {
    define(E::A, 1);
    echo "uncaught enum\n";
} catch (TypeError $e) {
    echo $e->getMessage(), "\n";
}

try {
    define('E::A', 1);
    echo "uncaught str\n";
} catch (ValueError $e) {
    echo $e->getMessage(), "\n";
}

define('MY_CONST', 1);
var_dump(defined('MY_CONST'));
?>
--EXPECT--
define(): Argument #1 ($constant_name) must be of type string, E given
define(): Argument #1 ($constant_name) cannot be a class constant
bool(true)
