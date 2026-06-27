--TEST--
stdlib array_values() after unset() skips removed slots (#12723, ext/standard/array.c)
--FILE--
<?php
declare(strict_types=1);
$a = ['a' => 1, 'b' => 2, 'c' => 3];
unset($a['b']);
var_export(array_values($a));
echo "\n";
--EXPECT--
array (
  0 => 1,
  1 => 3,
)
