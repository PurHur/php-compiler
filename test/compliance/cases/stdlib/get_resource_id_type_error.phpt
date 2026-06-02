--TEST--
stdlib get_resource_id() — TypeError on non-resource (#3180)
--FILE--
<?php
try {
    $x = get_resource_id(1);
} catch (Throwable $e) {
    echo get_class($e), "\n";
    echo $e->getMessage(), "\n";
}
--EXPECT--
TypeError
get_resource_id(): Argument #1 ($resource) must be of type resource, int given
