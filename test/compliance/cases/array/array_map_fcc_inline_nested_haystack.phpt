--TEST--
Regression: array_map() FCC callback + inline nested haystack (#16279, ext/standard/array.c)
--FILE--
<?php
declare(strict_types=1);

$expected = [1, 2];
$inline = array_map(intval(...), str_split(str_repeat('12', 1)));
var_export($inline);
echo "\n";
var_export(array_map(intval(...), str_split('12')));
echo "\n";
var_export(array_map(static fn (string $x): int => (int) $x, str_split(str_repeat('12', 1))));
echo "\n";
--EXPECT--
array (
  0 => 1,
  1 => 2,
)
array (
  0 => 1,
  1 => 2,
)
array (
  0 => 1,
  1 => 2,
)
