--TEST--
Language: die(message:) named parameter rejected on reference profile (#12435, basic_functions.stub.php)
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
die(message: 'bye');
--EXPECT_EXIT--
255
