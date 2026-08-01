--TEST--
Language: LSP fatal resolves self to declaring class (#26641, zend_inheritance.c)
--FILE--
<?php
class A { public function f(): static { return $this; } }
class B extends A { public function f(): self { return $this; } }
echo "survived\n";
--EXPECT_EXIT--
255
--EXPECTF--
%ADeclaration of B::f(): B must be compatible with A::f(): static%A
