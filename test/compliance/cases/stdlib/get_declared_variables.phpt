--TEST--
stdlib get_declared_variables() returns caller local names (#4780)
--FILE--
<?php
$a = 1;
$b = 2;
$vars = get_declared_variables();
sort($vars);
var_export($vars);
echo "\n";
var_export(in_array('a', $vars, true) && in_array('b', $vars, true));
echo "\n";
--EXPECT--
array (
  0 => 'a',
  1 => 'b',
)
true
