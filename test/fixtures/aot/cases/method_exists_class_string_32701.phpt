--TEST--
AOT: method_exists() class string + boxed instance (#32701 leftover of #31966)
--FILE--
<?php
class C {
    public function foo() {}
}
$name = 'C';
$c = new C;
var_dump(method_exists('C', 'foo'));
var_dump(method_exists($name, 'foo'));
var_dump(method_exists($c, 'foo'));
var_dump(method_exists($c, 'missing'));
--EXPECT--
bool(true)
bool(true)
bool(true)
bool(false)
--EXPECT_EXIT--
0
