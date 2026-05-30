--TEST--
stdlib is_a() extends chain (issue #3478)
--FILE--
<?php
class B {}
class C extends B {}
class D extends C {}

$c = new C();
$d = new D();

echo is_a($c, 'B') ? '1' : '0';
echo is_a($d, 'B') ? '1' : '0';
echo is_a($c, 'C') ? '1' : '0';
echo is_a($c, 'D') ? '1' : '0';
echo is_a('C', 'B', true) ? '1' : '0';
echo is_a('D', 'B', true) ? '1' : '0';
echo is_a('B', 'B', true) ? '1' : '0';
echo "\n";
--EXPECT--
1110111
