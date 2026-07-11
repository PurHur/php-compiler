--TEST--
stdlib current()/key() — TypeError for null array argument JIT (#11984, ext/standard/array.c)
--JIT--
--FILE--
<?php
foreach (['current', 'key'] as $fn) {
    try {
        $fn(null);
        echo "{$fn}: uncaught\n";
    } catch (TypeError $e) {
        echo $fn, ': ', $e->getMessage(), "\n";
    }
}
?>
--EXPECT--
current: current(): Argument #1 ($array) must be of type array, null given
key: key(): Argument #1 ($array) must be of type array, null given
