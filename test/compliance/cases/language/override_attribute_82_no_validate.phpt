--TEST--
Language: #[\Override] is not validated before PHP 8.3 (#11559)
--SKIPIF--
<?php
if (PHPCompiler\CompilerVersion::supportsOverrideAttribute()) {
    echo "skip — Override attribute validation enabled on 8.3+ target\n";
}
?>
--FILE--
<?php
class A {}
class B extends A {
    #[\Override]
    public function foo(): void {}
}
echo "ok\n";
--EXPECT--
ok
