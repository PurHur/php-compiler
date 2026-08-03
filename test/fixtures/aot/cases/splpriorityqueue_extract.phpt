--TEST--
AOT: SplPriorityQueue::extract() by priority (#27277)
--FILE--
<?php
$q = new SplPriorityQueue();
$q->insert('a', 1);
$q->insert('b', 10);
$q->insert('c', 5);
echo $q->extract(), ',', $q->extract(), "\n";
echo $q->extract(), "\n";
echo $q->count(), "\n";
--EXPECT--
b,c
a
0
