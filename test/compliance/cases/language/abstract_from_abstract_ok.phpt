--TEST--
Language: abstract override of abstract/interface method still OK (#25660)
--FILE--
<?php
abstract class A { abstract public function f(): void; }
abstract class B extends A { abstract public function f(): void; }
interface I { public function g(): void; }
abstract class C implements I { abstract public function g(): void; }
echo "LOADED\n";
--EXPECT--
LOADED
