<?php
// repro: SplDoublyLinkedList offsetGet/offsetExists + setIteratorMode LIFO foreach (#33987)
$l = new SplDoublyLinkedList();
$l->push('a');
$l->push('b');
$l->push('c');
echo $l->offsetGet(1), '|', $l->offsetExists(2) ? 'y' : 'n', '|', $l->count(), "\n";
$l->setIteratorMode(SplDoublyLinkedList::IT_MODE_LIFO);
foreach ($l as $v) {
    echo $v, ',';
}
echo 'mode=', $l->getIteratorMode(), "\n";
