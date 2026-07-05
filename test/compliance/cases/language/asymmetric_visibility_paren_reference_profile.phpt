--TEST--
Language: public (private(set)) parenthesis form rejected on reference profile (#16450, Zend/zend_compile.c)
--SKIPIF--
<?php
if (!class_exists('PHPCompiler\\CompilerVersion')) {
    require __DIR__ . '/../../../../vendor/autoload.php';
}
if (PHPCompiler\CompilerVersion::supportsParenthesizedAsymmetricSetModifier()) {
    die('skip parenthesized asymmetric set modifier enabled on PHP 8.4.0+ target');
}
?>
--FILE--
<?php
class C {
    public (private(set)) int $z = 1;
}
--EXPECT_EXIT--
255
--EXPECTF--
Fatal error: syntax error, unexpected token "private" in %s on line %d
