--TEST--
SplHeap/SplMaxHeap/SplMinHeap valid()/key() after insert without rewind (#31600)
--FILE--
<?php
error_reporting(E_ALL);
$max = new SplMaxHeap();
$max->insert(1);
$max->insert(2);
echo 'max_valid=', $max->valid() ? '1' : '0', ' key=', $max->key(), "\n";
$max->next();
echo 'max_after_next count=', $max->count(), ' cur=', var_export($max->current(), true), "\n";

$min = new SplMinHeap();
$min->insert(5);
echo 'min_valid=', $min->valid() ? '1' : '0', ' key=', $min->key(), ' cur=', $min->current(), "\n";

$empty = new SplMaxHeap();
echo 'empty_valid=', $empty->valid() ? '1' : '0', ' key=', var_export($empty->key(), true), "\n";
?>
--EXPECT--
max_valid=1 key=1
max_after_next count=1 cur=1
min_valid=1 key=0 cur=5
empty_valid=0 key=-1
