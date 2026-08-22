<?php
$d = new SplDoublyLinkedList;
$d->push('a');
$d->push('b');
echo serialize($d), "\n";
$e = unserialize(serialize($d));
echo $e->count(), '|', $e->bottom(), '|', $e->top(), "\n";

$q = new SplQueue;
$q->enqueue('x');
echo serialize($q), "\n";
$qe = unserialize(serialize($q));
echo $qe->count(), '|', $qe->dequeue(), "\n";

$s = new SplStack;
$s->push('y');
echo serialize($s), "\n";
$se = unserialize(serialize($s));
echo $se->count(), '|', $se->pop(), "\n";
