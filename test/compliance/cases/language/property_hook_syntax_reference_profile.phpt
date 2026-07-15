--TEST--
Language: property hook short syntax rejected on reference profile (#14062, Zend/zend_compile.c)
--SKIPIF--
<?php
if (!class_exists('PHPCompiler\\CompilerVersion')) {
    require __DIR__ . '/../../../../vendor/autoload.php';
}
putenv('PHP_COMPILER_PROFILE=8.2');
if (PHPCompiler\CompilerVersion::supportsPropertyHooks()) {
    die('skip PHP_COMPILER_PROFILE=8.2 unexpectedly enables property hooks');
}
?>
--ENV--
PHP_COMPILER_PROFILE=8.2
--FILE--
<?php
class C {
    public int $x {
        get => $this->x;
    }
}
--EXPECT_EXIT--
255
