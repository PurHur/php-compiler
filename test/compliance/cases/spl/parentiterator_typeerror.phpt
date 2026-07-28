--TEST--
ParentIterator non-RecursiveIterator inner — TypeError (#24273, ext/spl/spl_iterators.c)
--FILE--
<?php
try {
    new ParentIterator(new ArrayIterator([1]));
    echo "no throw\n";
} catch (TypeError $e) {
    echo $e->getMessage(), "\n";
} catch (InvalidArgumentException $e) {
    echo 'InvalidArgumentException:', $e->getMessage(), "\n";
}
?>
--EXPECT--
ParentIterator::__construct(): Argument #1 ($iterator) must be of type RecursiveIterator, ArrayIterator given
