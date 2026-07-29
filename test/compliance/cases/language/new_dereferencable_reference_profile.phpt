--TEST--
Language: dereferencable `new` without outer parentheses rejected on PROFILE=8.2 (#19684, #24755, Zend/zend_language_parser.y)
--SKIPIF--
<?php
if (!class_exists('PHPCompiler\\CompilerVersion')) {
    require __DIR__ . '/../../../../vendor/autoload.php';
}
$profile = getenv('PHP_COMPILER_PROFILE');
if (!\is_string($profile) || '8.2' !== trim($profile)) {
    die('skip requires PHP_COMPILER_PROFILE=8.2 reference profile');
}
?>
--ENV--
PHP_COMPILER_PROFILE=8.2
--FILE--
<?php
class A { public function x(){ return 1; } }
echo new A()->x(), "\n";
--EXPECT_EXIT--
255
