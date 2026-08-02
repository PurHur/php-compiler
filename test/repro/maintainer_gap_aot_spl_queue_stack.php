<?php
// Repro for #26790 — AOT SplQueue dequeue / SplStack pop return empty.
$q = new SplQueue();
$q->enqueue(1);
$q->enqueue(2);
echo $q->dequeue(), ',', $q->dequeue(), "\n";
$s = new SplStack();
$s->push('a');
$s->push('b');
echo $s->pop(), ',', $s->pop(), "\n";
