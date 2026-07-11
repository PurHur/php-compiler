--TEST--
Language: property hook block rejected on reference profile (#12574, Zend/zend_compile.c)
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
        get { return $this->x; }
        set => $this->x = $value;
    }
}
--EXPECT_EXIT--
255
