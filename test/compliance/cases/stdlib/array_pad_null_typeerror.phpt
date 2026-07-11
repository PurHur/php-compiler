--TEST--
stdlib array_pad() — TypeError for null array argument (#11954, ext/standard/array.c)
--FILE--
<?php
try {
    array_pad(null, 2, 0);
    echo "uncaught\n";
} catch (TypeError $e) {
    echo $e->getMessage(), "\n";
}
?>
--EXPECT--
array_pad(): Argument #1 ($array) must be of type array, null given
