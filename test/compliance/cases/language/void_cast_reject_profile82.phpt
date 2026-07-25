--TEST--
Language: (void) cast rejected on PROFILE=8.2 (#23037, Zend/zend_language_scanner.l)
--SKIPIF--
<?php
if (!class_exists('PHPCompiler\\CompilerVersion')) {
    require __DIR__ . '/../../../../vendor/autoload.php';
}
putenv('PHP_COMPILER_PROFILE=8.2');
if (PHPCompiler\CompilerVersion::supportsVoidCast()) {
    die('skip PROFILE=8.2 unexpectedly enables (void) cast (#23037)');
}
?>
--ENV--
PHP_COMPILER_PROFILE=8.2
--FILE--
<?php
$a = (void) strlen('x');
var_export($a);
--EXPECT_EXIT--
255
--EXPECTF--
%aSyntax error%a
