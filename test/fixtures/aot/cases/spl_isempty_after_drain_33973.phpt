--TEST--
AOT: SplQueue/DDL/Stack/Heap/PriorityQueue isEmpty after drain (#33973)
--FILE--
<?php
$q = new SplQueue();
echo 'freshQ=', $q->isEmpty() ? 'Y' : 'N', "\n";
$q->enqueue(1);
$q->enqueue(2);
echo 'fullQ=', $q->isEmpty() ? 'Y' : 'N', '|', $q->count(), "\n";
$q->dequeue();
$q->dequeue();
echo 'drainQ=', $q->isEmpty() ? 'Y' : 'N', '|', $q->count(), "\n";

$d = new SplDoublyLinkedList();
$d->push('a');
$d->pop();
echo 'drainD=', $d->isEmpty() ? 'Y' : 'N', "\n";

$s = new SplStack();
$s->push('x');
$s->pop();
echo 'drainS=', $s->isEmpty() ? 'Y' : 'N', "\n";

$h = new SplMaxHeap();
$h->insert(3);
$h->extract();
echo 'drainH=', $h->isEmpty() ? 'Y' : 'N', '|', $h->count(), "\n";

$p = new SplPriorityQueue();
$p->insert('a', 1);
$p->extract();
echo 'drainP=', $p->isEmpty() ? 'Y' : 'N', '|', $p->count(), "\n";
--EXPECT--
freshQ=Y
fullQ=N|2
drainQ=Y|0
drainD=Y
drainS=Y
drainH=Y|0
drainP=Y|0
