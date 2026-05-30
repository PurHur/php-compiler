--TEST--
stdlib is_countable() — Countable/array detection (#3452)
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
var_export(is_countable('x'));
echo "\n";
var_export(is_countable(new E()));
echo "\n";
--EXPECT--
true
true
false
false
