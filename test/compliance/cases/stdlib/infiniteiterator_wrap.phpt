--TEST--
InfiniteIterator rewinds inner at end (php-src ext/spl/spl_iterators.c, #13170)
--FILE--
<?php
$inner = new ArrayIterator([1, 2]);
$inf = new InfiniteIterator($inner);
$inf->rewind();
$vals = [];
for ($i = 0; $i < 5; ++$i) {
    $vals[] = $inf->current();
    $inf->next();
}
echo implode(',', $vals), "\n";
--EXPECT--
1,2,1,2,1
