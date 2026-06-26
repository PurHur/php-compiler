--TEST--
stdlib array_count_values() — TypeError for null argument (#11963, ext/standard/array.c)
--FILE--
<?php
try {
    array_count_values(null);
    echo "uncaught\n";
} catch (TypeError $e) {
    echo $e->getMessage(), "\n";
}
?>
--EXPECT--
array_count_values(): Argument #1 ($array) must be of type array, null given
