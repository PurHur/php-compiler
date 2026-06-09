--TEST--
stdlib array_replace_key() — replace existing keys only (PHP 8.4, issue #5650)
--FILE--
<?php
$a = [1 => 'a', 2 => 'b'];
$b = array_replace_key($a, [2 => 'c', 3 => 'd']);
var_export($b);
echo "\n";
$x = ['color' => 'red', 'shape' => 'circle'];
$y = array_replace_key($x, ['color' => 'green', 'size' => 5]);
var_export($y);
echo "\n";
$list = [0 => 'zero', 1 => 'one'];
$merged = array_replace_key($list, [0 => 'ZERO', 2 => 'two']);
var_export($merged);
--EXPECT--
array (
  1 => 'a',
  2 => 'c',
)
array (
  'color' => 'green',
  'shape' => 'circle',
)
array (
  0 => 'ZERO',
  1 => 'one',
)
