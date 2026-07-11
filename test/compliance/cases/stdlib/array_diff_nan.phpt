--TEST--
stdlib array_diff() — NAN operand removed from result (#10142, ext/standard/array.c)
--FILE--
<?php
declare(strict_types=1);

$a = [NAN, 1.0];
var_export(array_diff($a, [NAN]));
echo "\n";
--EXPECT--
array (
  1 => 1.0,
)
