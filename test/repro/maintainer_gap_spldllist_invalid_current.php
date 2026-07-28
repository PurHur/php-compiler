<?php
$d = new SplDoublyLinkedList();
$d->push(1);
$d->rewind();
$d->next();
echo 'valid=', var_export($d->valid(), true), ' key=', var_export($d->key(), true), ' current=', var_export($d->current(), true), "\n";
$d2 = new SplDoublyLinkedList();
$d2->setIteratorMode(SplDoublyLinkedList::IT_MODE_LIFO);
$d2->push('a');
$d2->push('b');
$d2->rewind();
$d2->next();
$d2->next();
echo 'lifo valid=', var_export($d2->valid(), true), ' key=', var_export($d2->key(), true), ' current=', var_export($d2->current(), true), "\n";
