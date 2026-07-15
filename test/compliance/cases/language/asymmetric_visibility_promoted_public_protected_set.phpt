--TEST--
Language: promoted public protected(set) unparenthesized — compile fatal on reference profile (#18805, Zend/zend_compile.c)
--SKIPIF--
<?php
if (!class_exists('PHPCompiler\\CompilerVersion')) {
    require __DIR__ . '/../../../../vendor/autoload.php';
}
if (PHPCompiler\CompilerVersion::supportsParenthesizedAsymmetricSetModifier()) {
    die('skip bare promoted public protected(set) accepted on PHP 8.4 forward profile (#18820)');
}
?>
--ENV--
PHP_COMPILER_PROFILE=8.4
--FILE--
<?php
class C {
    public function __construct(public protected(set) string $n = 'ok') {}
}
echo (new C())->n, "\n";
--EXPECT_EXIT--
255
