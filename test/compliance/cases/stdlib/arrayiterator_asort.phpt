--TEST--
ArrayIterator asort/ksort/natcasesort in-place (issue #13141)
--FILE--
<?php
$it = new ArrayIterator(['b' => 2, 'a' => 1, 'c' => 3]);
$it->asort();
var_export(iterator_to_array($it));
echo "\n";
$it2 = new ArrayIterator(['b' => 2, 'a' => 1]);
$it2->ksort();
var_export(iterator_to_array($it2));
echo "\n";
$it3 = new ArrayIterator(['img10', 'img2', 'img1']);
$it3->natcasesort();
var_export(iterator_to_array($it3));
echo "\n";
--EXPECT--
array (
  'a' => 1,
  'b' => 2,
  'c' => 3,
)
array (
  'a' => 1,
  'b' => 2,
)
array (
  2 => 'img1',
  1 => 'img2',
  0 => 'img10',
)
