--TEST--
inheritance variance: nullable param widen allowed (issue #23504)
--FILE--
<?php
class A { public function f(string $x): void {} }
class B extends A { public function f(?string $x): void {} }
echo "ok\n";
--EXPECT--
ok
