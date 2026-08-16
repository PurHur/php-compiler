--TEST--
SplPriorityQueue valid()/key() after insert without rewind (#31601)
--FILE--
<?php
error_reporting(E_ALL);
$pq = new SplPriorityQueue();
$pq->insert('a', 1);
$pq->insert('b', 3);
$pq->setExtractFlags(SplPriorityQueue::EXTR_BOTH);
echo 'valid=', $pq->valid() ? '1' : '0', ' key=', $pq->key(), "\n";
while ($pq->valid()) {
    $c = $pq->current();
    echo $c['data'], $c['priority'], ';';
    $pq->next();
}
echo "\n";
$empty = new SplPriorityQueue();
echo 'empty_valid=', $empty->valid() ? '1' : '0', ' key=', var_export($empty->key(), true), "\n";
?>
--EXPECT--
valid=1 key=1
b3;a1;
empty_valid=0 key=-1
