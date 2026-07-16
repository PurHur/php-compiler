--TEST--
CallbackFilterIterator nested new + inline arrow callback (#19771)
--FILE--
<?php
error_reporting(E_ALL);

$it = new CallbackFilterIterator(new ArrayIterator([1, 2, 3, 4]), fn($v) => $v % 2 === 0);
echo implode(',', iterator_to_array($it, false)), "\n";

$inner = new ArrayIterator([1, 2, 3, 4]);
$cb = fn($v) => $v % 2 === 0;
$it2 = new CallbackFilterIterator($inner, $cb);
echo implode(',', iterator_to_array($it2, false)), "\n";

function even_19771($v) { return $v % 2 === 0; }
$it3 = new CallbackFilterIterator(new ArrayIterator([1, 2, 3, 4]), 'even_19771');
echo implode(',', iterator_to_array($it3, false)), "\n";
--EXPECT--
2,4
2,4
2,4
