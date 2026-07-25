--TEST--
Language: (void) cast rejected on default 8.4.0-dev reference profile (#23037, Zend/zend_language_scanner.l)
--SKIPIF--
<?php
if (!class_exists('PHPCompiler\\CompilerVersion')) {
    require __DIR__ . '/../../../../vendor/autoload.php';
}
putenv('PHP_COMPILER_PROFILE');
if (PHPCompiler\CompilerVersion::supportsVoidCast()) {
    die('skip default profile unexpectedly enables (void) cast (#23037)');
}
?>
--FILE--
<?php
$a = (void) strlen('x');
var_export($a);
--EXPECT_EXIT--
255
--EXPECTF--
%aSyntax error%a
