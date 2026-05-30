--TEST--
Language: nullsafe chain with array offset short-circuit
--FILE--
<?php
class C { public array $items = [10]; }
$c = null;
var_dump($c?->items[0]);
$c2 = new C();
var_dump($c2?->items[0]);
$b = ['x' => 1];
var_dump($b['x']);
$foo = null;
var_dump($foo?->bar()[0]);
--EXPECT--
NULL
int(10)
int(1)
NULL
