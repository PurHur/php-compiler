--TEST--
inheritance variance: intersection return subtype allowed (issue #23504)
--FILE--
<?php
interface I {}
interface J {}
class A { public function f(): I { return new class implements I {}; } }
class B extends A { public function f(): I&J { return new class implements I, J {}; } }
echo "ok\n";
--EXPECT--
ok
