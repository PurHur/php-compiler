<?php
/** Repro #19770 — nested LimitIterator(InfiniteIterator(ArrayIterator)) must wrap forever. */
error_reporting(E_ALL);
$it = new LimitIterator(new InfiniteIterator(new ArrayIterator([7, 8])), 0, 5);
echo implode(',', iterator_to_array($it, false)) . "\n";
