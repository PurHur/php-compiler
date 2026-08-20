--TEST--
AOT: SplStack/SplQueue/SplDoublyLinkedList count (#32910, ext/spl/spl_dllist.c)
--FILE--
<?php
$s = new SplStack();
$s->push(1);
$s->push(2);
echo 'stack=', $s->count(), '|', count($s), '|', $s->top(), "\n";
$q = new SplQueue();
$q->enqueue(10);
$q->enqueue(20);
$q->enqueue(30);
echo 'queue=', $q->count(), '|', $q->dequeue(), '|', $q->count(), "\n";
$d = new SplDoublyLinkedList();
$d->push('a');
$d->unshift('b');
echo 'ddl=', $d->count(), '|', $d->bottom(), '|', $d->top(), "\n";
--EXPECT--
stack=2|2|2
queue=3|10|2
ddl=2|b|a
