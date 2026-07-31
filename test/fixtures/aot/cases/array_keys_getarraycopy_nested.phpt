--TEST--
AOT: array_keys(ArrayObject::getArrayCopy()) nested MethodCall (#25812)
--FILE--
<?php
$ao = new ArrayObject(['b' => 2, 'a' => 1]);
echo implode(',', array_keys($ao->getArrayCopy())), "\n";
$c = $ao->getArrayCopy();
echo implode(',', array_keys($c)), "\n";
echo implode(',', array_values($ao->getArrayCopy())), "\n";
--EXPECT--
b,a
b,a
2,1
