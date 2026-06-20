--TEST--
stdlib key() after unset on current element (#10349, ext/standard/array.c)
--FILE--
<?php
declare(strict_types=1);

$a = ['x' => 1, 'y' => 2];
unset($a['x']);
var_export(key($a));
echo "\n";

$b = ['a' => 1, 'b' => 2];
unset($b['b']);
var_export(key($b));
echo "\n";

$c = ['only' => 1];
unset($c['only']);
var_export(key($c));
echo "\n";
var_export(current($c));
echo "\n";
--EXPECT--
'y'
'a'
NULL
false
