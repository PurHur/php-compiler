--TEST--
stdlib spl_object_id() — TypeError on non-object (#3172)
--FILE--
<?php
try {
    spl_object_id(1);
    echo "no error\n";
} catch (TypeError $e) {
    echo $e->getMessage(), "\n";
}
--EXPECT--
spl_object_id(): Argument #1 ($object) must be of type object, int given
