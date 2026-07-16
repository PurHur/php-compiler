<?php
// Repro #19762 — SplDoublyLinkedList add()/prev()
$d = new SplDoublyLinkedList();
$d->push(1);
$d->push(2);
$d->push(3);
$d->add(1, 99);
foreach ($d as $k => $v) {
    echo $k, '=', $v, ';';
}
echo "\n";
$d->rewind();
$d->next();
$d->prev();
echo $d->key(), ',', $d->current(), "\n";
