--TEST--
stdlib DateInterval serialize/unserialize Zend wire (#10692)
--FILE--
<?php
$iv = new DateInterval('P1DT2H');
echo serialize($iv), "\n";
$round = unserialize(serialize($iv));
var_export([$round->d, $round->h]);
echo "\n";
?>
--EXPECT--
O:12:"DateInterval":10:{s:1:"y";i:0;s:1:"m";i:0;s:1:"d";i:1;s:1:"h";i:2;s:1:"i";i:0;s:1:"s";i:0;s:1:"f";d:0;s:6:"invert";i:0;s:4:"days";b:0;s:11:"from_string";b:0;}
array (
  0 => 1,
  1 => 2,
)
