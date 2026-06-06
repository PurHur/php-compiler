--TEST--
stdlib unpack() * repeat specifiers (issue #6720)
--FILE--
<?php
$r = unpack('H2', 'ab');
var_export($r);
echo "\n";
$r = unpack('H*', 'ab');
var_export($r);
echo "\n";
$r = unpack('H*', 'abcd');
var_export($r);
echo "\n";
$r = unpack('n*', pack('nn', 0x1234, 0x5678));
var_export($r);
echo "\n";
$r = unpack('a*', 'hello');
var_export($r);
echo "\n";
$r = unpack('Z*', "hi\x00there");
var_export($r);
echo "\n";
$r = unpack('c*', 'abc');
var_export($r);
echo "\n";
$r = unpack('H*', '');
var_export($r);
echo "\n";
--EXPECT--
array (
  1 => '61',
)
array (
  1 => '6162',
)
array (
  1 => '61626364',
)
array (
  1 => 4660,
  2 => 22136,
)
array (
  1 => 'hello',
)
array (
  1 => 'hi',
)
array (
  1 => 97,
  2 => 98,
  3 => 99,
)
array (
  1 => '',
)
