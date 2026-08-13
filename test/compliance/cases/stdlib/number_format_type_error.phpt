--TEST--
stdlib number_format() — array $num operand TypeError (#4163, ext/standard/number_format.c)
--FILE--
<?php
try {
    number_format(['x']);
} catch (TypeError $e) {
    echo $e->getMessage(), "\n";
}
--EXPECT--
number_format(): Argument #1 ($num) must be of type int|float, array given
