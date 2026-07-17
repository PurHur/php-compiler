--TEST--
SPL LimitIterator(InfiniteIterator(ArrayIterator)) nested new wraps (#19770, ext/spl/spl_iterators.c)
--FILE--
<?php
$nested = new LimitIterator(new InfiniteIterator(new ArrayIterator([7, 8])), 0, 5);
echo implode(',', iterator_to_array($nested, false)), "\n";

$inf = new InfiniteIterator(new ArrayIterator([7, 8]));
$bound = new LimitIterator($inf, 0, 5);
echo implode(',', iterator_to_array($bound, false)), "\n";

echo get_class((new LimitIterator(new InfiniteIterator(new ArrayIterator([1])), 0, 1))->getInnerIterator()), "\n";
?>
--EXPECT--
7,8,7,8,7
7,8,7,8,7
InfiniteIterator
