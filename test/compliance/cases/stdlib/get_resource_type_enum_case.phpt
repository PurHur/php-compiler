--TEST--
stdlib get_resource_type() — enum case operand TypeError (#5845, ext/standard/basic_functions.c)
--FILE--
<?php
enum I: int { case A = 1; }
try {
    $x = get_resource_type(I::A);
} catch (Throwable $e) {
    echo get_class($e), "\n";
    echo $e->getMessage(), "\n";
}
var_dump(is_resource(I::A));
--EXPECT--
TypeError
get_resource_type(): Argument #1 ($resource) must be of type resource, I given
bool(false)
