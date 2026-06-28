--TEST--
SplDoublyLinkedList ArrayAccess offset API (ext/spl/spl_dllist.c; #13088)
--FILE--
<?php
$list = new SplDoublyLinkedList();
$list->push(1);
$list->push(2);
echo $list[0], "\n";
var_export($list->offsetExists(0));
echo "\n";
$list[1] = 99;
echo $list[1], "\n";
unset($list[0]);
echo $list->count(), "\n";
echo $list[0], "\n";
?>
--EXPECT--
1
true
99
1
99
