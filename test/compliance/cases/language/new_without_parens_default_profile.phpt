--TEST--
Language: `new Class()->method()` parse-error on default 8.4.0-dev profile (phpversion 8.2.31, #31164, re-#24883, Zend/zend_language_parser.y)
--SKIPIF--
<?php
if (!class_exists('PHPCompiler\\CompilerVersion')) {
    require __DIR__ . '/../../../../vendor/autoload.php';
}
putenv('PHP_COMPILER_PROFILE');
if (PHPCompiler\CompilerVersion::supportsDereferencableNewWithoutOuterParens()) {
    die('skip default profile unexpectedly enables dereferencable new (#31164)');
}
?>
--FILE--
<?php
class C { function m() { return 2; } }
echo new C()->m(), "\n";
echo PHP_VERSION, "\n";
--EXPECT_EXIT--
255
--EXPECTF--
Parse error: syntax error, unexpected token "->", expecting "," or ";" in %s on line %d
