--TEST--
Language: hex float literals rejected on default 8.4.0-dev reference profile (#29061, Zend/zend_language_scanner.l)
--SKIPIF--
<?php
if (!class_exists('PHPCompiler\\CompilerVersion')) {
    require __DIR__ . '/../../../../vendor/autoload.php';
}
putenv('PHP_COMPILER_PROFILE');
if (PHPCompiler\CompilerVersion::supportsHexFloatLiterals()) {
    die('skip default profile unexpectedly enables hex float literals (#29061)');
}
?>
--FILE--
<?php
echo 0x1.8p1;
--EXPECT_EXIT--
255
--EXPECTF--
%aSyntax error%a
