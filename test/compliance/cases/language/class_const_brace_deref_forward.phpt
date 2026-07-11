--TEST--
Language: class constant brace dereference on forward profile (#16597, Zend/zend_compile.c)
--SKIPIF--
<?php
if (!class_exists('PHPCompiler\\CompilerVersion')) {
    require __DIR__ . '/../../../../vendor/autoload.php';
}
if (!PHPCompiler\CompilerVersion::supportsClassConstBraceDeref()) {
    die('skip class const brace deref disabled on reference profile');
}
?>
--FILE--
<?php
class C {
    public const X = 42;
}
echo C::{'X'}, "\n";
--EXPECT--
42
