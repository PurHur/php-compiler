--TEST--
stdlib array_reverse() preserve_keys on associative arrays (#4335)
--FILE--
<?php
$assoc = ['a' => 1, 'b' => 2, 'c' => 3];
var_export(array_reverse($assoc));
echo "\n";
var_export(array_reverse($assoc, true));
echo "\n";

$mixed = [0 => 'x', 'k' => 'y', 1 => 'z'];
var_export(array_reverse($mixed, true));
echo "\n";

$a = [1, 2, 3];
$b = array_reverse($a);
echo $b[0], $b[2], "\n";
--EXPECT--
array (
  'c' => 3,
  'b' => 2,
  'a' => 1,
)
array (
  'c' => 3,
  'b' => 2,
  'a' => 1,
)
array (
  1 => 'z',
  'k' => 'y',
  0 => 'x',
)
31
