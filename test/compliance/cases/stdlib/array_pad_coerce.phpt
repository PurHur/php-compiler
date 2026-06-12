--TEST--
stdlib array_pad() numeric-string and float length coercion (#4269, ext/standard/array.c)
--FILE--
<?php
declare(strict_types=1);

var_export(array_pad([1, 2], '5', 'x'));
echo "\n";
var_export(array_pad([1, 2], 5.7, 'x'));
echo "\n";

try {
    array_pad([1, 2], 'abc', 'x');
    echo "no throw\n";
} catch (TypeError $e) {
    echo $e->getMessage(), "\n";
}
--EXPECT--
array (
  0 => 1,
  1 => 2,
  2 => 'x',
  3 => 'x',
  4 => 'x',
)
array (
  0 => 1,
  1 => 2,
  2 => 'x',
  3 => 'x',
  4 => 'x',
)
array_pad(): Argument #2 ($length) must be of type int, string given
