--TEST--
stdlib array_intersect() — NAN operands match (#10141, ext/standard/array.c)
--FILE--
<?php
declare(strict_types=1);

$a = [NAN];
$b = [NAN];
var_export(array_intersect($a, $b));
echo "\n";
var_export(array_intersect([NAN], [NAN]));
echo "\n";
--EXPECT--
array (
  0 => NAN,
)
array (
  0 => NAN,
)
