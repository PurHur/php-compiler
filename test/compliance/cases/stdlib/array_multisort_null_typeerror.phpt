--TEST--
stdlib array_multisort() — TypeError for null argument (#11961, ext/standard/array.c)
--FILE--
<?php
try {
    array_multisort(null);
    echo "uncaught\n";
} catch (TypeError $e) {
    echo $e->getMessage(), "\n";
}
?>
--EXPECT--
array_multisort(): Argument #1 ($array) must be an array or a sort flag
