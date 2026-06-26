--TEST--
Language: #[\Override] on class compile-time fatal (#6864)
--SKIPIF--
<?php
if (!PHPCompiler\CompilerVersion::supportsOverrideAttribute()) {
    echo "skip — Override validation disabled on reference profile\n";
}
?>
--FILE--
<?php
class Base {
    public function foo(): void {}
}
#[\Override]
class Child extends Base {
    public function foo(): void {}
}
echo "ok\n";
--EXPECT_EXIT--
255
