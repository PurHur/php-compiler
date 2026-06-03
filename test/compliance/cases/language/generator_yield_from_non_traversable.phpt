--TEST--
Generator yield from non-traversable throws Error (issue #5195, zend_generators.c)
--FILE--
<?php
function f_int() { yield from 1; }
function f_obj() { yield from new stdClass(); }

foreach ([f_int(), f_obj()] as $g) {
    try {
        $g->current();
        echo "current: no throw\n";
    } catch (Throwable $e) {
        echo "current: ", $e::class, ": ", $e->getMessage(), "\n";
    }
    try {
        iterator_to_array($g);
        echo "iter: completed\n";
    } catch (Throwable $e) {
        echo "iter: ", $e::class, "\n";
    }
}
--EXPECT--
current: Error: Can use "yield from" only with arrays and Traversables
iter: Exception
current: Error: Can use "yield from" only with arrays and Traversables
iter: Exception
