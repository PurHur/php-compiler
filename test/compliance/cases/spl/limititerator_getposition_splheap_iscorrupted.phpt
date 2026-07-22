--TEST--
SPL LimitIterator::getPosition + SplHeap::isCorrupted (#22264, ext/spl/spl_iterators.c / spl_heap.c)
--FILE--
<?php
$li = new LimitIterator(new ArrayIterator([1, 2, 3, 4]), 1, 2);
echo method_exists($li, 'getPosition') ? "getPosition_ok\n" : "getPosition_missing\n";
$li->rewind();
echo 'pos=', $li->getPosition(), "\n";
$li->next();
echo 'pos=', $li->getPosition(), "\n";

$h = new SplMinHeap();
$h->insert(1);
echo method_exists($h, 'isCorrupted') ? "isCorrupted_ok\n" : "isCorrupted_missing\n";
var_export($h->isCorrupted());
echo "\n";
var_export($h->recoverFromCorruption());
echo "\n";

$p = new SplPriorityQueue();
echo method_exists($p, 'isCorrupted') ? "pq_isCorrupted_ok\n" : "pq_isCorrupted_missing\n";
var_export($p->isCorrupted());
echo "\n";
?>
--EXPECT--
getPosition_ok
pos=1
pos=2
isCorrupted_ok
false
true
pq_isCorrupted_ok
false
