--TEST--
FilterIterator is abstract — direct instantiation throws Error (php-src ext/spl/spl_iterators.c, #15153)
--FILE--
<?php
try {
    new FilterIterator(new ArrayIterator([]));
    echo 'fail';
} catch (Error $e) {
    echo $e->getMessage();
}
--EXPECT--
Cannot instantiate abstract class FilterIterator
