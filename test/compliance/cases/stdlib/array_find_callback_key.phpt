--TEST--
stdlib array_find family — callback receives value and key; *_key family receives key and value (PHP 8.4 ext/standard/array.c)
--FILE--
<?php
$a = ['x' => 10, 'y' => 20];
echo array_find($a, fn ($v, $k) => $k === 'y'), "\n";
echo array_find_key($a, fn ($k, $v) => $v === 20), "\n";
echo array_any($a, fn ($v, $k) => $k === 'x') ? 'y' : 'n', "\n";
echo array_all($a, fn ($v, $k) => is_int($v)) ? 'y' : 'n', "\n";

$b = [1, 2, 3];
echo array_find_key($b, fn ($k, $v) => $k === 1), "\n";
?>
--EXPECT--
20
y
y
y
1
