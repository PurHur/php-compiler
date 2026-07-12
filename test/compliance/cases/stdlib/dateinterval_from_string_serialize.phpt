--TEST--
stdlib DateInterval::createFromDateString serialize/unserialize Zend wire (#10692)
--FILE--
<?php
$iv = DateInterval::createFromDateString('1 day');
echo serialize($iv), "\n";
$round = unserialize(serialize($iv));
var_export([$round->d, $round->h]);
echo "\n";
$zend = unserialize('O:12:"DateInterval":2:{s:11:"from_string";b:1;s:11:"date_string";s:5:"1 day";}');
var_export([$zend->d, $zend->h]);
echo "\n";
?>
--EXPECT--
O:12:"DateInterval":2:{s:11:"from_string";b:1;s:11:"date_string";s:5:"1 day";}
array (
  0 => 1,
  1 => 0,
)
array (
  0 => 1,
  1 => 0,
)
