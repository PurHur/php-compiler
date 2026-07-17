<?php
// Repro #19824 — SplDoublyLinkedList/Queue/Stack var_dump must show flags+dllist.
$d = new SplDoublyLinkedList();
$d->push(10);
$d->push(20);
var_dump($d);
$q = new SplQueue();
$q->enqueue(1);
var_dump($q);
