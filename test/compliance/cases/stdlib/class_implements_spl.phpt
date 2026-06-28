--TEST--
class_implements() — SplFixedArray and SplDoublyLinkedList (#13089, ext/standard/basic_functions.c)
--FILE--
<?php
$fa = new SplFixedArray(0);
$faIfaces = class_implements($fa);
var_export(isset($faIfaces['ArrayAccess']));
echo "\n";
var_export(isset($faIfaces['JsonSerializable']));
echo "\n";
$list = new SplDoublyLinkedList();
$listIfaces = class_implements($list);
var_export(isset($listIfaces['ArrayAccess']));
echo "\n";
var_export(isset($listIfaces['Iterator']));
?>
--EXPECT--
true
true
true
true
