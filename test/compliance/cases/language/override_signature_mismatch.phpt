--TEST--
Language: #[\Override] with incompatible signature fails at compile time (issue #4529)
--SKIPIF--
<?php
if (!PHPCompiler\CompilerVersion::supportsOverrideAttribute()) {
    echo "skip — Override validation disabled on reference profile\n";
}
?>
--FILE--
<?php
class Base {
    public function foo(int $x): void {}
}
class Child extends Base {
    #[\Override]
    public function foo(string $x): void {}
}
new Child();
--EXPECT_EXIT--
255
