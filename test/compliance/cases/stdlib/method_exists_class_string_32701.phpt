--TEST--
stdlib method_exists() class string literal and runtime (#32701, ext/standard/class.c)
--FILE--
<?php
class C {
    public function foo() {}
}
var_dump(method_exists('C', 'foo'));
$n = 'C';
var_dump(method_exists($n, 'foo'));
var_dump(method_exists($n, 'missing'));
--EXPECT--
bool(true)
bool(true)
bool(false)
