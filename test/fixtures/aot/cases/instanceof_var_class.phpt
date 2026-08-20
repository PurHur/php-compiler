--TEST--
AOT: instanceof against a runtime string class name (#32766)
--FILE--
<?php
class A {}
$o = new A;
$n = 'A';
var_dump($o instanceof A);
var_dump($o instanceof $n);
--EXPECT--
bool(true)
bool(true)
--EXPECT_EXIT--
0
