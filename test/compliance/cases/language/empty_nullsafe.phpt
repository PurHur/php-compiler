--TEST--
Language: empty() on nullsafe ?-> property fetch short-circuits (#4980)
--FILE--
<?php
$o = null;
var_export(empty($o?->y));
echo "\n";
class C { public int $n = 1; }
$c = new C;
var_export(empty($c?->n));
echo "\n";
var_export(empty($c?->missing));
echo "\n";
--EXPECT--
true
false
true
