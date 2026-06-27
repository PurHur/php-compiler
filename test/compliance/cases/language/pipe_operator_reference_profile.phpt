--TEST--
Language: pipe operator |> rejected on reference profile (#12424, Zend/zend_language_parser.y)
--SKIPIF--
<?php
if (!class_exists('PHPCompiler\\CompilerVersion')) {
    require __DIR__ . '/../../../../vendor/autoload.php';
}
if (PHPCompiler\CompilerVersion::supportsPipeOperator()) {
    die('skip pipe operator enabled on PHP 8.4.0+ target');
}
?>
--FILE--
<?php
$x = 5 |> fn ($v) => $v * 2;
--EXPECT_EXIT--
255
