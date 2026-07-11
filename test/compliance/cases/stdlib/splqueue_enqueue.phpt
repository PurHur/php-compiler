--TEST--
SplQueue enqueue/dequeue FIFO semantics (ext/spl/spl_dllist.c; #13222)
--FILE--
<?php
$q = new SplQueue();
$q->enqueue(1);
$q->enqueue(2);
echo $q->dequeue(), "\n";
echo $q->dequeue(), "\n";
$s = new SplStack();
$s->push(1);
echo $s->pop(), "\n";
?>
--EXPECT--
1
2
1
