--TEST--
Language: #[\Override] without parent method compiles on reference profile (#12201, #11559)
--SKIPIF--
<?php
if (PHPCompiler\CompilerVersion::supportsOverrideAttribute()) {
    echo "skip — requires PHP 8.2 reference profile\n";
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
