--TEST--
stdlib unpack() repeated format specifiers — JIT (issue #10413, ext/standard/pack.c)
--JIT--
--FILE--
<?php
$r = unpack('a2a2', 'abcd');
var_export($r);
echo "\n";
$r = unpack('A2A2', 'abcd');
var_export($r);
echo "\n";
$r = unpack('Z2Z2', "a\x00b\x00");
var_export($r);
echo "\n";
$r = unpack('h2h2', 'abcd');
var_export($r);
echo "\n";
$r = unpack('C2foo', 'AB');
var_export($r);
echo "\n";
--EXPECT--
array (
  'a2' => 'ab',
)
array (
  'A2' => 'ab',
)
array (
  'Z2' => 'a',
)
array (
  'h2' => '16',
)
array (
  'foo1' => 65,
  'foo2' => 66,
)
