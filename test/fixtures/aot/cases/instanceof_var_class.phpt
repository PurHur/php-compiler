--TEST--
AOT: instanceof against runtime string class name (#32766)
--FILE--
<?php
class A
{
}
$o = new A();
$n = 'A';
var_dump($o instanceof $n);
var_dump($o instanceof A);
--EXPECT--
bool(true)
bool(true)
--EXPECT_EXIT--
0
