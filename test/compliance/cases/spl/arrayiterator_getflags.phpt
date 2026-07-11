--TEST--
SPL ArrayIterator::getFlags()/setFlags() + class constants (#16641, ext/spl/spl_array.c)
--FILE--
<?php
$it = new ArrayIterator([1, 2, 3]);
var_export($it->getFlags());
echo "\n";
$it2 = new ArrayIterator(['a' => 1], ArrayIterator::ARRAY_AS_PROPS);
var_export($it2->getFlags());
echo "\n";
$it2->setFlags(ArrayIterator::STD_PROP_LIST);
var_export($it2->getFlags());
echo "\n";
var_export(ArrayIterator::STD_PROP_LIST);
echo "\n";
var_export(ArrayIterator::ARRAY_AS_PROPS);
?>
--EXPECT--
0
2
1
1
2
