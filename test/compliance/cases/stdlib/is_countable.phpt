--TEST--
stdlib is_countable() — Countable/array detection (#3452 / #27552)
--FILE--
<?php
class D implements \Countable {
    public function count(): int { return 3; }
}
class E {}
var_export(is_countable(new D()));
echo "\n";
var_export(is_countable([]));
echo "\n";
var_export(is_countable([1]));
echo "\n";
echo (int)is_countable([1]), (int)is_countable(new ArrayObject([1])), (int)is_countable(1);
echo "\n";
var_export(is_countable(new stdClass()));
echo "\n";
var_export(is_countable(null));
echo "\n";
var_export(is_countable(123));
echo "\n";
var_export(is_countable('x'));
echo "\n";
var_export(is_countable(new E()));
echo "\n";
--EXPECT--
true
true
true
110
false
false
false
false
false
