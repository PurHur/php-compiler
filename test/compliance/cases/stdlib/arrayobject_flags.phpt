--TEST--
stdlib ArrayObject::getFlags()/setFlags() + class constants (#10639, ext/spl/spl_array.c)
--FILE--
<?php
$ao = new ArrayObject([1, 2]);
var_export($ao->getFlags());
echo "\n";
$ao->setFlags(ArrayObject::ARRAY_AS_PROPS);
var_export($ao->getFlags());
echo "\n";
var_export(ArrayObject::STD_PROP_LIST);
echo "\n";
var_export(ArrayObject::ARRAY_AS_PROPS);
?>
--EXPECT--
0
2
1
2
