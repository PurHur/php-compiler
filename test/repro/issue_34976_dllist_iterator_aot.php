<?php
// Repro #34976 — AOT SplDoublyLinkedList Iterator protocol (rewind/valid/current/next).
$d = new SplDoublyLinkedList();
$d->push(1);
$d->push(2);
$d->unshift(0);
echo $d->count(), '|', $d->bottom(), '|', $d->top(), '|';
$d->rewind();
while ($d->valid()) {
    echo $d->current(), ',';
    $d->next();
}
echo "\n";

// LIFO walk (SplStack / setIteratorMode)
$s = new SplStack();
$s->push('a');
$s->push('b');
$s->push('c');
$s->rewind();
while ($s->valid()) {
    echo $s->current(), ',';
    $s->next();
}
echo "\n";
