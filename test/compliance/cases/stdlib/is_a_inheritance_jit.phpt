--TEST--
stdlib is_a() extends chain JIT (issue #3478)
--FILE--
<?php
class B {}
class C extends B {}

$c = new C();
echo is_a($c, 'B') ? '1' : '0';
echo is_a('C', 'B', true) ? '1' : '0';
echo is_a('B', 'B', true) ? '1' : '0';
echo "\n";
--EXPECT--
111
