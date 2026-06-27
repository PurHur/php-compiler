--TEST--
Language: typed class constants rejected on reference profile (#12798, Zend/zend_compile.c)
--SKIPIF--
<?php
if (!class_exists('PHPCompiler\\CompilerVersion')) {
    require __DIR__ . '/../../../../vendor/autoload.php';
}
if (PHPCompiler\CompilerVersion::supportsTypedClassConstants()) {
    die('skip typed class constants enabled on 8.4.0+ target');
}
?>
--FILE--
<?php
class C {
    public const string K = 'v';
}
echo C::K, "\n";
--EXPECT_EXIT--
255
