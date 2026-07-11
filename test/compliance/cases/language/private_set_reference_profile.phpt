--TEST--
Language: private(set) rejected on reference profile (#12508, Zend/zend_language_parser.y)
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
    public function __construct(private(set) int $x) {}
}
--EXPECT_EXIT--
255
