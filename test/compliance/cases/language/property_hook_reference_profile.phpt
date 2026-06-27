--TEST--
Language: property hooks rejected on reference profile (#12574, Zend/zend_language_parser.y)
--SKIPIF--
<?php
if (!class_exists('PHPCompiler\\CompilerVersion')) {
    require __DIR__ . '/../../../../vendor/autoload.php';
}
if (PHPCompiler\CompilerVersion::supportsPropertyHooks()) {
    die('skip property hooks enabled on PHP 8.4.0+ target');
}
?>
--FILE--
<?php
class C {
    public string $label = 'default' {
        get => $this->label;
    }
}
--EXPECT_EXIT--
255
