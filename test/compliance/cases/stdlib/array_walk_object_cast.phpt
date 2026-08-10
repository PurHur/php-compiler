--TEST--
stdlib array_walk() — inline (object) cast by-ref Error (#15948, ext/standard/array.c)
--FILE--
<?php
try {
    array_walk((object) ['x' => 1], static fn ($v) => print($v));
} catch (Error $e) {
    echo 'walk: ', $e->getMessage(), "\n";
}
try {
    array_walk_recursive((object) ['x' => 1], static fn ($v) => print($v));
} catch (Error $e) {
    echo 'rec: ', $e->getMessage(), "\n";
}
?>
--EXPECT--
walk: array_walk(): Argument #1 ($array) could not be passed by reference
rec: array_walk_recursive(): Argument #1 ($array) could not be passed by reference
