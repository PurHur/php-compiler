--TEST--
Language: class const visibility widening and same-level override allowed (#22929)
--FILE--
<?php
class A { protected const X = 1; }
class B extends A { public const X = 2; }
class C extends B { public const X = 3; }
echo B::X, "\n";
echo C::X, "\n";
--EXPECT--
2
3
