--TEST--
stdlib function_exists() — enum case operand TypeError (#5814, ext/standard/basic_functions.c)
--FILE--
<?php
declare(strict_types=1);

enum E: string { case A = 'strlen'; }

try {
    var_export(function_exists(E::A));
    echo "uncaught\n";
} catch (TypeError $e) {
    echo $e->getMessage(), "\n";
}
--EXPECT--
function_exists(): Argument #1 ($function) must be of type string, E given
