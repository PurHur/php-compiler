--TEST--
stdlib array_pop/array_shift/array_unshift on associative arrays (#4789)
--FILE--
<?php
$a = ['x' => 1, 'y' => 2];
var_export(array_pop($a));
echo "\n";
var_export($a);
echo "\n";
$b = ['x' => 1, 'y' => 2];
var_export(array_shift($b));
echo "\n";
var_export($b);
echo "\n";
$c = ['x' => 1];
var_export(array_unshift($c, 'z'));
echo "\n";
var_export($c);
echo "\n";
--EXPECT--
2
array (
  'x' => 1,
)
1
array (
  'y' => 2,
)
2
array (
  0 => 'z',
  'x' => 1,
)
