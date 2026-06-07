--TEST--
stdlib get_resource_id() — enum case operand TypeError (#5845, ext/standard/basic_functions.c)
--FILE--
<?php
enum I: int { case A = 1; }
try {
    $x = get_resource_id(I::A);
} catch (Throwable $e) {
    echo get_class($e), "\n";
    echo $e->getMessage(), "\n";
}
--EXPECT--
TypeError
get_resource_id(): Argument #1 ($resource) must be of type resource, I given
