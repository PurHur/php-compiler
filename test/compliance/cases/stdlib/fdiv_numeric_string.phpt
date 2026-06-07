--TEST--
stdlib fdiv() — numeric-string coercion + array TypeError (#4388, ext/standard/math.c)
--FILE--
<?php
var_dump(fdiv("6", "2"));
var_dump(fdiv("6.0", 2));
try {
    var_dump(fdiv([], 2));
} catch (TypeError $e) {
    echo $e->getMessage(), "\n";
}
--EXPECT--
float(3)
float(3)
fdiv(): Argument #1 ($num1) must be of type float, array given
