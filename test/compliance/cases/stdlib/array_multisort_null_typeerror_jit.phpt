--TEST--
stdlib array_multisort() JIT — TypeError for null argument (#11961)
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
