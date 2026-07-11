--TEST--
SPL RecursiveIteratorIterator non-recursive inner — InvalidArgumentException (#16917, ext/spl/spl_iterators.c)
--FILE--
<?php
try {
    new RecursiveIteratorIterator(new ArrayIterator([1]));
    echo "no exception\n";
} catch (InvalidArgumentException $e) {
    echo 'InvalidArgumentException:', $e->getMessage(), "\n";
} catch (LogicException $e) {
    echo 'LogicException:', $e->getMessage(), "\n";
}
--EXPECT--
InvalidArgumentException:An instance of RecursiveIterator or IteratorAggregate creating it is required
