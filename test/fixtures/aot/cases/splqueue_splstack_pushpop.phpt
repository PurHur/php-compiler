--TEST--
AOT: SplQueue enqueue/dequeue and SplStack push/pop (#26790)
--FILE--
<?php
$q = new SplQueue();
$q->enqueue(1);
$q->enqueue(2);
echo $q->dequeue(), ',', $q->dequeue(), "\n";
$s = new SplStack();
$s->push('a');
$s->push('b');
echo $s->pop(), ',', $s->pop(), "\n";
--EXPECT--
1,2
b,a
