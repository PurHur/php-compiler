--TEST--
iterator_to_array() on ArrayObject copies storage without IteratorAggregate VM context (#11228, ext/spl/iterator.c)
--FILE--
<?php
$ao = iterator_to_array(new ArrayObject(['a' => 1, 'b' => 2]));
ksort($ao);
var_export($ao);
echo "\n";

$packed = iterator_to_array(new ArrayObject(['a' => 1, 'b' => 2]), false);
echo json_encode(array_values($packed)), "\n";

$ai = iterator_to_array(new ArrayIterator(['x' => 3]));
echo $ai['x'], "\n";
--EXPECT--
array (
  'a' => 1,
  'b' => 2,
)
[1,2]
3
