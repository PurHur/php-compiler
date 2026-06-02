--TEST--
Language: isset() on defined scalar locals returns true (JIT bool lowering, #4081)
--FILE--
<?php
$x = 1;
$y = 2.5;
$z = true;
$s = 'hi';
var_export(isset($x));
echo "\n";
var_export(isset($y));
echo "\n";
var_export(isset($z));
echo "\n";
var_export(isset($s));
echo "\n";
var_export(isset($x[0]));
echo "\n";
--EXPECT--
true
true
true
true
false
