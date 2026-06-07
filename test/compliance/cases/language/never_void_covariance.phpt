--TEST--
Language: never return covariant with void parent/interface/abstract (issue #6733)
--FILE--
<?php
interface I { public function f(): void; }
class C implements I { public function f(): never { throw new Exception('x'); } }

abstract class A { abstract public function g(): void; }
class B extends A { public function g(): never { exit; } }

class R { public function __clone(): never { throw new Exception('no clone'); } }

echo "ok\n";
--EXPECT--
ok
