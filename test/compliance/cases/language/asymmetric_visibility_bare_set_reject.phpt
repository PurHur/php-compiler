--TEST--
Language: bare private(set)/protected(set) without read modifier — rejected on reference profile (#16313, Zend/zend_language_parser.y)
--SKIPIF--
<?php
if (!class_exists('PHPCompiler\\CompilerVersion')) {
    require __DIR__ . '/../../../../vendor/autoload.php';
}
if (PHPCompiler\CompilerVersion::supportsParenthesizedAsymmetricSetModifier()) {
    die('skip bare set shorthand enabled on PHP 8.4.0+ forward profile (#16924)');
}
?>
--FILE--
<?php
declare(strict_types=1);
class C {
    private(set) string $p = 'x';
}
echo "fail\n";
--EXPECT_EXIT--
255
--EXPECTF--
parseAndCompile failure: target=%s: syntax error, unexpected token ")", expecting variable
