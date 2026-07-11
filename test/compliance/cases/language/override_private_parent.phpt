--TEST--
Language: #[\Override] on method colliding with private parent — compile-time fatal (#6919)
--SKIPIF--
<?php
if (!PHPCompiler\CompilerVersion::supportsOverrideAttribute()) {
    echo "skip — Override validation disabled on reference profile\n";
}
?>
--FILE--
<?php
class Base {
    private function hidden(): void {}
}
class Child extends Base {
    #[\Override]
    public function hidden(): void {}
}
echo "ok\n";
--EXPECT_EXIT--
255
