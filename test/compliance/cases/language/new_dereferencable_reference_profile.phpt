--TEST--
Language: dereferencable `new` without outer parentheses rejected on reference profile (#19684, Zend/zend_language_parser.y)
--SKIPIF--
<?php
if (!class_exists('PHPCompiler\\CompilerVersion')) {
    require __DIR__ . '/../../../../vendor/autoload.php';
}
if (PHPCompiler\CompilerVersion::supportsDereferencableNewWithoutOuterParens()) {
    die('skip dereferencable new enabled on PHP 8.4.0+ target');
}
?>
--FILE--
<?php
class A { public function x(){ return 1; } }
echo new A()->x(), "\n";
--EXPECT_EXIT--
255
