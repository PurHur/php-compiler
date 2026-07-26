--TEST--
inheritance variance: class param widen to object allowed (issue #23504)
--FILE--
<?php
class A { public function f(stdClass $x): void {} }
class B extends A { public function f(object $x): void {} }
echo "ok\n";
--EXPECT--
ok
