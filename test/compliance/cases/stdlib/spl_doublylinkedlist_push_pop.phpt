--TEST--
SplDoublyLinkedList push/pop deque semantics (ext/spl/spl_dllist.c; #13080)
--FILE--
<?php
$list = new SplDoublyLinkedList();
$list->push(1);
$list->push(2);
echo $list->pop(), "\n";
$list->unshift(3);
echo $list->shift(), "\n";
echo $list->count(), "\n";
?>
--EXPECT--
2
3
1
