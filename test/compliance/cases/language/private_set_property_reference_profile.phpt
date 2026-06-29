--TEST--
Language: private(set) property rejected on reference profile (#13838, Zend/zend_language_parser.y)
--SKIPIF--
<?php
if (!class_exists('PHPCompiler\\CompilerVersion')) {
    require __DIR__ . '/../../../../vendor/autoload.php';
}
if (PHPCompiler\CompilerVersion::supportsAsymmetricVisibility()) {
    die('skip asymmetric visibility enabled on PHP 8.4.0+ target');
}
?>
--FILE--
<?php
class C {
    private(set) int $x = 1;
}
--EXPECT_EXIT--
255
