--TEST--
Language: exit(status:) named parameter rejected on reference profile (#12413, basic_functions.stub.php)
--SKIPIF--
<?php
if (!class_exists('PHPCompiler\\CompilerVersion')) {
    require __DIR__ . '/../../../../vendor/autoload.php';
}
if (PHPCompiler\CompilerVersion::supportsExitFunctionForm()) {
    die('skip exit function form enabled on PHP 8.4.0+ target');
}
?>
--FILE--
<?php
exit(status: 0);
--EXPECT_EXIT--
255
