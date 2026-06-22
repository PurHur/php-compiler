--TEST--
Language: class constants with object expressions — AOT rejected (#10391, Zend/zend_constants.c)
--SKIPIF--
<?php
if (!class_exists('PHPCompiler\\CompilerVersion')) {
    require __DIR__ . '/../../../../vendor/autoload.php';
}
if (PHPCompiler\CompilerVersion::supportsClassConstObjectExpressions()) {
    die('skip class const object expressions enabled on this target');
}
?>
--FILE--
<?php
class C {
    public const X = new stdClass();
}
echo (C::X instanceof stdClass) ? "1\n" : "0\n";
--EXPECT_EXIT--
255
