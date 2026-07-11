--TEST--
SPL ArrayIterator::offsetExists(null) empty-string key coercion (#12046, ext/spl/spl_array.c)
--FILE--
<?php
$ai = new ArrayIterator(['' => 1]);
var_export($ai->offsetExists(null));
var_export($ai->offsetGet(null));
var_export(isset(class_implements($ai)['ArrayAccess']));
?>
--EXPECT--
true1true
