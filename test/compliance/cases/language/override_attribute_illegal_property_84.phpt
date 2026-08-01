--TEST--
Language: #[\Override] rejects property under PROFILE=8.4 (#25138, Zend/zend_attributes.stub.php)
--SKIPIF--
<?php
if (!class_exists('PHPCompiler\\CompilerVersion')) {
    require __DIR__ . '/../../../../vendor/autoload.php';
}
if (!PHPCompiler\CompilerVersion::supportsOverrideAttribute()) {
    die('skip requires Override validation (PROFILE≥8.3)');
}
if (PHPCompiler\CompilerVersion::supportsOverridePropertyTarget()) {
    die('skip property Override allowed on PROFILE≥8.5');
}
?>
--ENV--
PHP_COMPILER_PROFILE=8.4
--FILE--
<?php
class A { public int $x = 1; }
class B extends A {
    #[\Override]
    public int $x = 2;
}
echo "unreachable\n";
--EXPECT_EXIT--
255
--EXPECTF--
parseAndCompile failure: target=%s: Attribute "Override" cannot target property (allowed targets: method)
