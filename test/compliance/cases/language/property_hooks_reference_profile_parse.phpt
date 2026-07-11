--TEST--
Language: property hooks on reference profile hint PHP_COMPILER_PROFILE=8.4 (#17610, Zend/zend_language_parser.y)
--SKIPIF--
<?php
if (!class_exists('PHPCompiler\\CompilerVersion')) {
    require __DIR__ . '/../../../../vendor/autoload.php';
}
if (PHPCompiler\CompilerVersion::supportsPropertyHooks()) {
    die('skip property hooks enabled on PHP 8.4 forward profile');
}
?>
--FILE--
<?php
class C {
    public int $x {
        get => $this->x;
    }
}
--EXPECT_EXIT--
255
--EXPECTF--
Fatal error: Property hooks require PHP_COMPILER_PROFILE=8.4 (PHP 8.4 forward profile) in %s on line %d
