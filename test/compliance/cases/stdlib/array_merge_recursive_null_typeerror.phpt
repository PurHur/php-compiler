--TEST--
stdlib array_merge_recursive() — TypeError for null array argument (#11973, ext/standard/array.c)
--FILE--
<?php
try {
    array_merge_recursive([1], null);
    echo "uncaught\n";
} catch (TypeError $e) {
    echo $e->getMessage(), "\n";
}
?>
--EXPECT--
array_merge_recursive(): Argument #2 must be of type array, null given
