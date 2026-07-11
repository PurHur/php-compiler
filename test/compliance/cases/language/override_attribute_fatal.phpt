--TEST--
Language: invalid #[\Override] — compile-time fatal (#4995)
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
class Bad extends Base {
    #[\Override]
    public function bar(): void {}
}
echo "ok\n";
--EXPECT_EXIT--
255
