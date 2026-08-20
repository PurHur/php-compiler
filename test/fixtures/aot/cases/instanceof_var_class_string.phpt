--TEST--
AOT: instanceof with runtime string class name (#32766)
--FILE--
<?php
class A {}
class B {}
$o = new A();
var_dump($o instanceof A);
$n = 'A';
var_dump($o instanceof $n);
$m = 'B';
var_dump($o instanceof $m);
$other = new A();
var_dump($o instanceof $other);
--EXPECT--
bool(true)
bool(true)
bool(false)
bool(true)
--EXPECT_EXIT--
0
