--TEST--
AOT is_a() extends chain (issue #3478)
--FILE--
<?php
class B {}
class C extends B {}

$c = new C();
echo is_a($c, 'B') ? '1' : '0';
echo is_subclass_of('C', 'B') ? '1' : '0';
--EXPECT--
11
