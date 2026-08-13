--TEST--
stdlib number_format() — enum case $num operand TypeError (#5892, ext/standard/number_format.c, php-src-strict)
--FILE--
<?php
enum E: int { case A = 1; }
try {
    number_format(E::A);
} catch (TypeError $e) {
    echo $e->getMessage(), "\n";
}
--EXPECT--
number_format(): Argument #1 ($num) must be of type int|float, E given
