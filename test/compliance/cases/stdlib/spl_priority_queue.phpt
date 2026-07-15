--TEST--
SplPriorityQueue extract flags + priority ordering (#4387)
--FILE--
<?php
$pq = new SplPriorityQueue();
$pq->setExtractFlags(SplPriorityQueue::EXTR_BOTH);
$pq->insert('low', 1);
$pq->insert('high', 100);
$first = $pq->extract();
$second = $pq->extract();
echo $first['data'], ':', $first['priority'], "\n";
echo $second['data'], ':', $second['priority'], "\n";

$pq2 = new SplPriorityQueue();
$pq2->setExtractFlags(SplPriorityQueue::EXTR_DATA);
$pq2->insert('low', 1);
$pq2->insert('high', 100);
echo $pq2->extract(), "\n";

$pq3 = new SplPriorityQueue();
$pq3->setExtractFlags(SplPriorityQueue::EXTR_PRIORITY);
$pq3->insert('a', 5);
$pq3->insert('b', 9);
echo $pq3->extract(), "\n";
?>
--EXPECT--
high:100
low:1
high
9
