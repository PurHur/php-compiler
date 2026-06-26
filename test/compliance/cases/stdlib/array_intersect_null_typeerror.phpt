--TEST--
stdlib array_intersect() — TypeError for null array argument (#11976, ext/standard/array.c)
--FILE--
<?php
try {
    array_intersect([1], null);
    echo "uncaught\n";
} catch (TypeError $e) {
    echo $e->getMessage(), "\n";
}
?>
--EXPECT--
array_intersect(): Argument #2 must be of type array, null given
