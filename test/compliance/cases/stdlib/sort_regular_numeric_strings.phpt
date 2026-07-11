--TEST--
stdlib sort(SORT_REGULAR) on numeric strings — zend_compare parity (#13028, ext/standard/array.c)
--FILE--
<?php
$a = ['10', '2', '1'];
sort($a, SORT_REGULAR);
var_export($a);
echo "\n";
$b = ['10', 2, '1'];
sort($b, SORT_REGULAR);
var_export($b);
--EXPECT--
array (
  0 => '1',
  1 => '2',
  2 => '10',
)
array (
  0 => '1',
  1 => 2,
  2 => '10',
)
