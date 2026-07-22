--TEST--
SplHeap / SplPriorityQueue iterator key() = remaining count − 1 (#22290)
--FILE--
<?php
$max = new SplMaxHeap();
$max->insert(3);
$max->insert(1);
$max->insert(2);
$max->rewind();
echo $max->key(), ',', $max->current(), "\n";
$max->next();
echo $max->key(), ',', $max->current(), "\n";
$max->next();
echo $max->key(), ',', $max->current(), "\n";

$max2 = new SplMaxHeap();
$max2->insert(3);
$max2->insert(1);
$max2->insert(2);
echo json_encode(iterator_to_array($max2)), "\n";

$min = new SplMinHeap();
$min->insert(3);
$min->insert(1);
$min->insert(2);
echo json_encode(iterator_to_array($min)), "\n";

$pq = new SplPriorityQueue();
$pq->insert('a', 3);
$pq->insert('b', 1);
$pq->insert('c', 2);
$pq->setExtractFlags(SplPriorityQueue::EXTR_DATA);
echo json_encode(iterator_to_array($pq)), "\n";
?>
--EXPECT--
2,3
1,2
0,1
{"2":3,"1":2,"0":1}
{"2":1,"1":2,"0":3}
{"2":"a","1":"c","0":"b"}
