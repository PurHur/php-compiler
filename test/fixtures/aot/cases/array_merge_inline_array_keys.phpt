--TEST--
AOT array_merge() inline array_keys() (#12450)
--FILE--
<?php
$src = ['a' => 1, 'b' => 2];
var_export(array_merge(array_keys($src), ['b']));
echo "\n";
--EXPECT--
array (
  0 => 'a',
  1 => 'b',
  2 => 'b',
)
