--TEST--
Regression: array_map() named haystack after sort()/array_walk() must not bind prior EXEC_RETURN (#24730)
--FILE--
<?php
$arr = [1, 2, 3, 4];
array_walk($arr, function (&$value, $key) {
    $value = $value * 2;
});
var_export($arr);
echo "\n";

$assoc = ['a' => 1, 'b' => 2, 'c' => 3];
$result = array_map(fn ($v) => $v * 10, $assoc);
var_export($result);
echo "\n";

$a = [1, 2];
sort($a);
$b = [10, 20];
var_export(array_map(fn ($x) => $x + 1, $b));
echo "\n";
--EXPECT--
array (
  0 => 2,
  1 => 4,
  2 => 6,
  3 => 8,
)
array (
  'a' => 10,
  'b' => 20,
  'c' => 30,
)
array (
  0 => 11,
  1 => 21,
)
