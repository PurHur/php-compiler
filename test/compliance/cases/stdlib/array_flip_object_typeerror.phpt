--TEST--
stdlib array_flip() — TypeError for stdClass argument (#11974, ext/standard/array.c)
--FILE--
<?php
try {
    array_flip(new stdClass());
    echo "uncaught\n";
} catch (TypeError $e) {
    echo $e->getMessage(), "\n";
}
?>
--EXPECT--
array_flip(): Argument #1 ($array) must be of type array, stdClass given
