--TEST--
Language: class constant brace dereference rejected on 8.2 reference profile (#16597, Zend/zend_compile.c)
--SKIPIF--
<?php
if (!class_exists('PHPCompiler\\CompilerVersion')) {
    require __DIR__ . '/../../../../vendor/autoload.php';
}
if (PHPCompiler\CompilerVersion::supportsClassConstBraceDeref()) {
    die('skip class const brace deref enabled on 8.3+ forward profile');
}
?>
--FILE--
<?php
class C {
    public const X = 42;
}
echo C::{'X'};
--EXPECT_EXIT--
255
