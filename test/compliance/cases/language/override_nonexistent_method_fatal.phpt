--TEST--
Language: #[\Override] on method not in parent — compile-time fatal (#21388)
--SKIPIF--
<?php
if (!PHPCompiler\CompilerVersion::supportsOverrideAttribute()) {
    echo "skip — Override validation disabled on reference profile\n";
}
?>
--FILE--
<?php
class Base {
    public function greet(): string { return "hello"; }
}
class Bad extends Base {
    #[\Override]
    public function nonExistent(): void {}
}
echo "should not reach here\n";
--EXPECT_EXIT--
255
