--TEST--
AOT: SplDoublyLinkedList push/unshift foreach via __spl_ht (#27311)
--FILE--
<?php
$d = new SplDoublyLinkedList();
$d->push(1);
$d->push(2);
$d->unshift(0);
foreach ($d as $v) {
    echo $v, ',';
}
echo "\n";
$q = new SplQueue();
$q->enqueue(10);
$q->enqueue(20);
foreach ($q as $v) {
    echo $v, ',';
}
echo "\n";
--EXPECT--
0,1,2,
10,20,
