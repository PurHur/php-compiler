--TEST--
Language: hex float literals rejected on PROFILE=8.2 (#29061, Zend/zend_language_scanner.l)
--SKIPIF--
<?php
if (!class_exists('PHPCompiler\\CompilerVersion')) {
    require __DIR__ . '/../../../../vendor/autoload.php';
}
putenv('PHP_COMPILER_PROFILE=8.2');
if (PHPCompiler\CompilerVersion::supportsHexFloatLiterals()) {
    die('skip PROFILE=8.2 unexpectedly enables hex float literals (#29061)');
}
?>
--ENV--
PHP_COMPILER_PROFILE=8.2
--FILE--
<?php
echo 0x1.8p1;
--EXPECT_EXIT--
255
--EXPECTF--
%aSyntax error%a
