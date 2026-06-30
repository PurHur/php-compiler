--TEST--
Language: property hook short syntax rejected on reference profile (#14062, Zend/zend_compile.c)
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
    public int $x {
        get => $this->x;
    }
}
--EXPECT_EXIT--
255
