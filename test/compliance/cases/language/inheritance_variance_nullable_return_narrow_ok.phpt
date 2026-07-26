--TEST--
inheritance variance: nullable return narrow allowed (issue #23504)
--FILE--
<?php
class A { public function f(): ?string { return null; } }
class B extends A { public function f(): string { return "x"; } }
echo (new B())->f(), "\n";
--EXPECT--
x
