--TEST--
stdlib sort()/ksort() — TypeError for non-array object operand (#12675, ext/standard/array.c)
--FILE--
<?php
foreach (['sort', 'ksort'] as $fn) {
    try {
        $fn(new stdClass());
        echo "{$fn}: uncaught\n";
    } catch (TypeError $e) {
        echo $fn, ': ', $e->getMessage(), "\n";
    }
}
?>
--EXPECT--
sort: sort(): Argument #1 ($array) must be of type array, stdClass given
ksort: ksort(): Argument #1 ($array) must be of type array, stdClass given
