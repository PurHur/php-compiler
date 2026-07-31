--TEST--
Language: array_keys(ArrayObject/ArrayIterator::getArrayCopy()) nested MethodCall (#25812)
--FILE--
<?php
declare(strict_types=1);
$ao = new ArrayObject(['b' => 2, 'a' => 1]);
var_export(array_keys($ao->getArrayCopy()));
echo "\n";
$c = $ao->getArrayCopy();
var_export(array_keys($c));
echo "\n";
$ait = new ArrayIterator(['b' => 2, 'a' => 1]);
var_export(array_keys($ait->getArrayCopy()));
echo "\n";
var_export(array_keys((new ArrayObject(['b' => 2, 'a' => 1]))->getArrayCopy()));
echo "\n";
var_export(array_values($ao->getArrayCopy()));
echo "\n";
echo count($ao->getArrayCopy()), "\n";
--EXPECT--
array (
  0 => 'b',
  1 => 'a',
)
array (
  0 => 'b',
  1 => 'a',
)
array (
  0 => 'b',
  1 => 'a',
)
array (
  0 => 'b',
  1 => 'a',
)
array (
  0 => 2,
  1 => 1,
)
2
