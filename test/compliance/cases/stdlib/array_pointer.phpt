--TEST--
stdlib array pointer key/current/next/prev/reset/end
--FILE--
<?php
$a = ['a' => 1, 'b' => 2];
var_export(key($a));
echo "\n";
var_export(current($a));
echo "\n";
next($a);
var_export(key($a));
echo "\n";
var_export(current($a));
echo "\n";
reset($a);
var_export(key($a));
echo "\n";
var_export(end($a));
echo "\n";
var_export(key($a));
echo "\n";
var_export(prev($a));
echo "\n";
var_export(key($a));
echo "\n";
var_export(function_exists('key'));
echo "\n";
var_export(function_exists('current'));
echo "\n";
var_export(function_exists('next'));
echo "\n";
var_export(function_exists('reset'));
echo "\n";
var_export(function_exists('end'));
echo "\n";
var_export(function_exists('pos'));
echo "\n";
--EXPECT--
'a'
1
'b'
2
'a'
2
'b'
1
'a'
true
true
true
true
true
true
