--TEST--
SPL aggregate iterator constructor excess argc (#31071, spl_iterators.c / spl_array.c)
--FILE--
<?php
$inner = new ArrayIterator([1, 2, 3]);
foreach ([
    ['AppendIterator', static fn () => new AppendIterator(1)],
    ['LimitIterator', static fn () => new LimitIterator($inner, 0, 1, 1)],
    ['CachingIterator', static fn () => new CachingIterator($inner, 0, 1)],
    ['MultipleIterator', static fn () => new MultipleIterator(0, 1)],
    ['NoRewindIterator', static fn () => new NoRewindIterator($inner, 1)],
    ['InfiniteIterator', static fn () => new InfiniteIterator($inner, 1)],
    ['ArrayIterator', static fn () => new ArrayIterator([1, 2, 3], 0, 1)],
    ['ArrayObject', static fn () => new ArrayObject([1, 2, 3], 0, null, 1)],
] as [$name, $fn]) {
    try {
        $fn();
        echo "$name ACCEPTED\n";
    } catch (ArgumentCountError $e) {
        echo $name, ' ', $e->getMessage(), "\n";
    }
}
?>
--EXPECT--
AppendIterator AppendIterator::__construct() expects exactly 0 arguments, 1 given
LimitIterator LimitIterator::__construct() expects at most 3 arguments, 4 given
CachingIterator CachingIterator::__construct() expects at most 2 arguments, 3 given
MultipleIterator MultipleIterator::__construct() expects at most 1 argument, 2 given
NoRewindIterator NoRewindIterator::__construct() expects exactly 1 argument, 2 given
InfiniteIterator InfiniteIterator::__construct() expects exactly 1 argument, 2 given
ArrayIterator ArrayIterator::__construct() expects at most 2 arguments, 3 given
ArrayObject ArrayObject::__construct() expects at most 3 arguments, 4 given
