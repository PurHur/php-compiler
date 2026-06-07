--TEST--
Language: isset() on nullsafe ?-> property fetch short-circuits (#4980)
--FILE--
<?php
$o = null;
var_export(isset($o?->x));
echo "\n";
class C { public int $n = 1; }
$c = new C;
var_export(isset($c?->n));
echo "\n";
var_export(isset($c?->missing));
echo "\n";
$a = null;
var_export(isset($a?->b?->c));
echo "\n";
class B { }
class A { public B $b; }
$a = new A();
$a->b = new B();
var_export(isset($a?->b?->c));
echo "\n";
--EXPECT--
false
true
false
false
false
