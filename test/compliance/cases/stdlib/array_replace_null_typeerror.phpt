--TEST--
stdlib array_replace() — TypeError for null array argument (#11962, ext/standard/array.c)
--FILE--
<?php
try {
    array_replace(null, [1]);
    echo "uncaught\n";
} catch (TypeError $e) {
    echo $e->getMessage(), "\n";
}
?>
--EXPECT--
array_replace(): Argument #1 ($array) must be of type array, null given
