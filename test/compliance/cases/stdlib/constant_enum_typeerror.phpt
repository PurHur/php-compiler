--TEST--
stdlib constant() — enum case operand TypeError names enum class (#7215, ext/standard/basic_functions.c)
--FILE--
<?php
declare(strict_types=1);

enum E: string { case A = 'x'; }

try {
    constant(E::A);
    echo "uncaught\n";
} catch (TypeError $e) {
    echo $e->getMessage(), "\n";
}
?>
--EXPECT--
constant(): Argument #1 ($name) must be of type string, E given
