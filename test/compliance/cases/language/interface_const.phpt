--TEST--
Language: interface constants — I::X fetch and implements inheritance (#3403)
--FILE--
<?php
interface I { public const X = 1; }
echo I::X, "\n";

class C implements I {}
echo C::X, "\n";

interface A { public const Y = 2; }
interface B extends A {}
echo B::Y, "\n";
--EXPECT--
1
1
2
