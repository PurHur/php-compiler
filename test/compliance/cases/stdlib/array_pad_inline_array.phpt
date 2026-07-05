--TEST--
stdlib array_pad() inline array literal haystack (#9549, ext/standard/array.c)
--FILE--
<?php
var_export(array_pad([1, 2], 5, 'x'));
echo "\n";
var_export(array_pad([1, 2], 5.7, 'x'));
echo "\n";
(function (): void {
    $a = [1, 2];
    var_export(array_pad($a, '5', 'x'));
    echo "\n";
})();
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
array (
  0 => 1,
  1 => 2,
  2 => 'x',
  3 => 'x',
  4 => 'x',
)
