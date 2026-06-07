--TEST--
stdlib unpack() * repeat specifiers JIT (issue #6720)
--JIT--
--FILE--
<?php
$r = unpack('H*', 'ab');
var_export($r);
echo "\n";
$r = unpack('n*', pack('nn', 0x1234, 0x5678));
var_export($r);
echo "\n";
$r = unpack('a*', 'hello');
var_export($r);
echo "\n";
--EXPECT--
array (
  1 => '6162',
)
array (
  1 => 4660,
  2 => 22136,
)
array (
  1 => 'hello',
)
