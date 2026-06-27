--TEST--
Language: public private(set) — Zend message on reference profile (#12576, Zend/zend_language_parser.y)
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
    public private(set) int $x = 1;
}
--EXPECT_EXIT--
255
--EXPECTF--
Fatal error: Multiple access type modifiers are not allowed in %s on line %d
