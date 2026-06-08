--TEST--
stdlib chop()/pos() legacy aliases (#4965)
--FILE--
<?php
var_dump(function_exists('chop'), function_exists('pos'));
echo chop("  hi  "), "\n";
$a = [10 => 'x', 20 => 'y'];
reset($a);
echo pos($a), "\n";
--EXPECT--
bool(true)
bool(true)
  hi
x
