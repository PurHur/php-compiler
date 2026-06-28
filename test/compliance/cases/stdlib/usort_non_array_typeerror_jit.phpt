--TEST--
stdlib uasort()/usort()/uksort() — TypeError for non-array operand JIT (#13235, ext/standard/array.c)
--JIT--
--FILE--
<?php
foreach (['uasort', 'usort', 'uksort'] as $fn) {
    try {
        $fn(new stdClass(), static fn () => 0);
        echo "{$fn}: uncaught\n";
    } catch (TypeError $e) {
        echo $fn, ': ', $e->getMessage(), "\n";
    }
}
?>
--EXPECT--
uasort: uasort(): Argument #1 ($array) must be of type array, stdClass given
usort: usort(): Argument #1 ($array) must be of type array, stdClass given
uksort: uksort(): Argument #1 ($array) must be of type array, stdClass given
