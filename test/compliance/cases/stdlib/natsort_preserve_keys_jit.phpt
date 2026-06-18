--TEST--
JIT: natsort()/natcasesort() preserve keys on packed lists (#9600)
--FILE--
<?php
$a = array('b', 'a10', 'a2');
natsort($a);
var_export($a);
echo "\n";
$b = array('b', 'A', 'c');
natcasesort($b);
var_export($b);
echo "\n";
--EXPECT--
array (
  2 => 'a2',
  1 => 'a10',
  0 => 'b',
)
array (
  1 => 'A',
  0 => 'b',
  2 => 'c',
)
