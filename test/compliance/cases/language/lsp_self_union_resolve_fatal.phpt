--TEST--
Language: LSP fatal resolves self|int union arm (#26641, zend_inheritance.c)
--FILE--
<?php
class A { public function f(): static|int { return $this; } }
class B extends A { public function f(): self|int { return $this; } }
echo "survived\n";
--EXPECT_EXIT--
255
--EXPECTF--
%ADeclaration of B::f(): B|int must be compatible with A::f(): static|int%A
