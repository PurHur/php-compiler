--TEST--
SplDoublyLinkedList::current() on invalid position — NULL (#24326, ext/spl/spl_dllist.c)
--FILE--
<?php
$d = new SplDoublyLinkedList();
$d->push(1);
$d->rewind();
$d->next();
echo 'valid=', (int) $d->valid(), ' key=', $d->key(), ' current=', var_export($d->current(), true), "\n";
$d2 = new SplDoublyLinkedList();
$d2->setIteratorMode(SplDoublyLinkedList::IT_MODE_LIFO);
$d2->push('a');
$d2->push('b');
$d2->rewind();
$d2->next();
$d2->next();
echo 'lifo valid=', (int) $d2->valid(), ' key=', $d2->key(), ' current=', var_export($d2->current(), true), "\n";
?>
--EXPECT--
valid=0 key=1 current=NULL
lifo valid=0 key=-1 current=NULL
