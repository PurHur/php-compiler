--TEST--
Language: public private(set) unparenthesized — compile fatal on reference profile (#18805, Zend/zend_compile.c)
--SKIPIF--
<?php
if (!class_exists('PHPCompiler\\CompilerVersion')) {
    require __DIR__ . '/../../../../vendor/autoload.php';
}
if (PHPCompiler\CompilerVersion::supportsParenthesizedAsymmetricSetModifier()) {
    die('skip bare public private(set) accepted on PHP 8.4 forward profile (#18820)');
}
?>
--ENV--
PHP_COMPILER_PROFILE=8.4
--FILE--
<?php
class C {
    public private(set) int $x = 1;
}
echo (new C())->x, "\n";
--EXPECT_EXIT--
255
