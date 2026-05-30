--TEST--
stdlib is_subclass_of() extends chain (issue #3478)
--FILE--
<?php
class B {}
class C extends B {}
class D extends C {}

$c = new C();

echo is_subclass_of($c, 'B') ? '1' : '0';
echo is_subclass_of($c, 'C') ? '1' : '0';
echo is_subclass_of('C', 'B') ? '1' : '0';
echo is_subclass_of('D', 'B') ? '1' : '0';
echo is_subclass_of('B', 'B') ? '1' : '0';
echo is_subclass_of('C', 'B', false) ? '1' : '0';
echo "\n";
--EXPECT--
111100
