<?php
$l = new SplDoublyLinkedList();
$l->push('a');
$l->push('b');
$l->push('c');
echo $l->offsetGet(1), '|', $l->offsetExists(2) ? 'y' : 'n', '|', $l->count(), "\n";
echo $l[1], '|', isset($l[2]) ? 'y' : 'n', "\n";
$l->setIteratorMode(SplDoublyLinkedList::IT_MODE_LIFO);
echo 'mode=', $l->getIteratorMode(), "\n";
foreach ($l as $v) {
    echo $v, ',';
}
echo "\n";
$s = new SplStack();
echo 'stack_mode=', $s->getIteratorMode(), "\n";
$q = new SplQueue();
echo 'queue_mode=', $q->getIteratorMode(), "\n";
