--TEST--
stdlib class_uses_recursive() — int operand TypeError (#16773, ext/standard/class.c)
--ENV--
PHP_COMPILER_PROFILE=8.4
--FILE--
<?php
try {
    class_uses_recursive(123);
    echo "fail\n";
} catch (TypeError $e) {
    echo $e->getMessage(), "\n";
}
--EXPECT--
class_uses_recursive(): Argument #1 ($object_or_class) must be of type object|string, int given
