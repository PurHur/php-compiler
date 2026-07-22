--TEST--
Language: #[\Override] without parent method is CompileError under PROFILE=8.4 (#22142, re-#19822)
--ENV--
PHP_COMPILER_PROFILE=8.4
--FILE--
<?php
class A { public function f(): void {} }
class B extends A {
    #[\Override]
    public function g(): void {}
}
echo "ok\n";
--EXPECT_EXIT--
255
--EXPECTF--
parseAndCompile failure: target=%s: B::g() has #[\Override] attribute, but no matching parent method exists
