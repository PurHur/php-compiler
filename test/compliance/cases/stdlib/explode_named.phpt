--TEST--
stdlib explode() named separator:/string:/limit: arguments (#10034, ext/standard/string.c)
--FILE--
<?php
var_export(explode(separator: ',', string: 'a,b,c'));
echo "\n";
var_export(explode(separator: '-', string: 'a-b-c', limit: 2));
echo "\n";
var_export(explode(',', 'a,b,c'));
echo "\n";
--EXPECT--
array (
  0 => 'a',
  1 => 'b',
  2 => 'c',
)
array (
  0 => 'a',
  1 => 'b-c',
)
array (
  0 => 'a',
  1 => 'b',
  2 => 'c',
)
