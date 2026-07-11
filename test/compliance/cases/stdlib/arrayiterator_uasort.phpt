--TEST--
ArrayIterator/ArrayObject uasort/uksort in-place (issue #9356, php-src ext/spl/spl_array.c)
--FILE--
<?php
$it = new ArrayIterator([2, 1]);
$it->uasort(fn($a, $b) => $a <=> $b);
var_export(iterator_to_array($it));
echo "\n";
$ao = new ArrayObject([2, 1]);
$ao->uasort(fn($a, $b) => $a <=> $b);
var_export($ao->getArrayCopy());
echo "\n";
$it2 = new ArrayIterator(['b' => 2, 'a' => 1]);
$it2->uksort(fn($k1, $k2) => strcmp($k1, $k2));
var_export(iterator_to_array($it2));
echo "\n";
--EXPECT--
array (
  1 => 1,
  0 => 2,
)
array (
  1 => 1,
  0 => 2,
)
array (
  'a' => 1,
  'b' => 2,
)
