<?php
// #35111 — SPL Serializable::serialize() under thin AOT
$a = new ArrayObject([1, 2]);
echo $a->serialize(), "\n";
$d = new SplDoublyLinkedList();
$d->push(1);
$d->push(2);
echo $d->serialize(), "\n";
$q = new SplQueue();
$q->enqueue("x");
echo $q->serialize(), "\n";
$d2 = new SplDoublyLinkedList();
$d2->unserialize("i:0;:i:1;:i:2;");
echo $d2->count(), "|", $d2->bottom(), "|", $d2->top(), "\n";
$q2 = new SplQueue();
$q2->unserialize("i:4;:s:1:\"x\";");
echo $q2->count(), "|", $q2->dequeue(), "\n";
$a2 = new ArrayObject();
$a2->unserialize("x:i:0;a:2:{i:0;i:1;i:1;i:2;};m:a:0:{}");
echo $a2->count(), "|", implode(",", $a2->getArrayCopy()), "\n";
