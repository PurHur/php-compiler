--TEST--
Language: invalid #[\Override] on missing parent method — compile-time fatal (issue #6355)
--SKIPIF--
<?php
if (!PHPCompiler\CompilerVersion::supportsOverrideAttribute()) {
    echo "skip — Override validation disabled on reference profile\n";
}
?>
--FILE--
<?php
class Base {}
class Child extends Base {
    #[\Override]
    public function typo(): void {}
}
echo "ok\n";
--EXPECT_EXIT--
255
