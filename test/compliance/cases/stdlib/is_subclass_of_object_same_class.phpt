--TEST--
stdlib is_subclass_of() object operand excludes same class (issue #4358)
--FILE--
<?php
class A {}
class B extends A {}

$o = new B();
echo is_subclass_of($o, A::class) ? '1' : '0';
echo is_subclass_of($o, B::class) ? '1' : '0';
echo is_subclass_of(B::class, A::class) ? '1' : '0';
echo "\n";
--EXPECT--
101
