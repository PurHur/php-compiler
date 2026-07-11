--TEST--
stdlib array_splice() negative offset with replacement array (#9329, ext/standard/array.c)
--FILE--
<?php
declare(strict_types=1);

$a = [0, 1, 2, 3, 4];
array_splice($a, -2, 1, ['x']);
var_export($a);
echo "\n";

$b = [0, 1, 2, 3, 4];
array_splice($b, 2, 1, ['x']);
var_export($b);
echo "\n";

$c = [0, 1, 2, 3, 4];
array_splice($c, -2, 1);
var_export($c);
echo "\n";
?>
--EXPECT--
array (
  0 => 0,
  1 => 1,
  2 => 2,
  3 => 'x',
  4 => 4,
)
array (
  0 => 0,
  1 => 1,
  2 => 'x',
  3 => 3,
  4 => 4,
)
array (
  0 => 0,
  1 => 1,
  2 => 2,
  3 => 4,
)
