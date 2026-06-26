--TEST--
stdlib ArrayObject::offsetExists(null) empty-string key coercion (#12044, ext/spl/spl_array.c)
--FILE--
<?php
$ao = new ArrayObject(['' => 1]);
var_export($ao->offsetExists(null));
echo "\n";
var_export($ao->offsetGet(null));
echo "\n";
?>
--EXPECT--
true
1
