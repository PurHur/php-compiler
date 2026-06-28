--TEST--
Language: class constant `new` expression rejected under JIT (#10391, Zend/zend_compile.c)
--SKIPIF--
<?php
if (!class_exists('PHPCompiler\\CompilerVersion')) {
    require __DIR__ . '/../../../../vendor/autoload.php';
}
if (PHPCompiler\CompilerVersion::supportsClassConstObjectExpressions()) {
    die('skip class const object expressions enabled on 8.3+ target');
}
?>
--FILE--
<?php
class C {
    public const X = new stdClass();
}
var_export(C::X);
--EXPECT_EXIT--
255
