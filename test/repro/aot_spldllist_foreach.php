<?php
// Repro for #27311 — AOT SplDoublyLinkedList push/unshift + foreach.
$d = new SplDoublyLinkedList();
$d->push(1);
$d->push(2);
$d->unshift(0);
foreach ($d as $v) {
    echo $v, ',';
}
echo "\n";
