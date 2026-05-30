--TEST--
stdlib get_object_id() — TypeError for non-object (#3537)
--FILE--
<?php
try {
    $x = get_object_id(1);
} catch (Throwable $e) {
    echo get_class($e), "\n";
    echo $e->getMessage(), "\n";
}
--EXPECT--
TypeError
get_object_id(): Argument #1 ($object) must be of type object, int given
