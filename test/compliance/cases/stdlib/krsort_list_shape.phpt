--TEST--
stdlib krsort()/ksort() on list-shaped keyed arrays (#10836)
--FILE--
<?php
$a = [0 => 'x', 1 => 'y'];
krsort($a);
var_export($a);
echo "\n";
$b = [1 => 'y', 0 => 'x'];
ksort($b);
var_export($b);
echo "\n";
--EXPECT--
array (
  1 => 'y',
  0 => 'x',
)
array (
  0 => 'x',
  1 => 'y',
)
