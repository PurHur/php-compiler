--TEST--
stdlib call_user_func() / is_callable() / call_user_func_array() (issue #3132)
--FILE--
<?php
function add(int $a, int $b): int { return $a + $b; }
$fn = 'add';
var_export(is_callable($fn));
echo "\n";
echo call_user_func($fn, 2, 3), "\n";
echo call_user_func_array($fn, [4, 5]), "\n";
$c = function (int $x): int { return $x * 2; };
var_export(is_callable($c));
echo "\n";
echo call_user_func($c, 7), "\n";
?>
--EXPECT--
true
5
9
true
14
