--TEST--
stdlib var_export() comparison !== false + return true (#17250, lib/Compiler.php)
--FILE--
<?php
echo var_export(1 !== false, true), "\n";
echo var_export(1 != false, true), "\n";
echo var_export([1] !== false, true), "\n";
$a = ['x' => 1];
echo var_export(array_search('x', $a, true) !== false, true), "\n";
echo var_export(0 === false, true), "\n";
echo "ok\n";
?>
--EXPECT--
true
true
true
false
false
ok
