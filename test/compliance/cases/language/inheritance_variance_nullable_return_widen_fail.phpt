--TEST--
inheritance variance: nullable return widen rejected (issue #23504)
--FILE--
<?php
class A { public function f(): string { return "x"; } }
class B extends A { public function f(): ?string { return null; } }
echo "ok\n";
--EXPECT_EXIT--
255
