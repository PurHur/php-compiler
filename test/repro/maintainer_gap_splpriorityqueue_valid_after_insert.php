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
