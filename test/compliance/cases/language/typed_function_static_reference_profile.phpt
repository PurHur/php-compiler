--TEST--
Language: typed function-local static rejected on reference profile (#16512, Zend/zend_compile.c)
--SKIPIF--
<?php
if (!class_exists('PHPCompiler\\CompilerVersion')) {
    require __DIR__ . '/../../../../vendor/autoload.php';
}
if (PHPCompiler\CompilerVersion::supportsTypedFunctionStatic()) {
    die('skip typed function static enabled on forward profile');
}
?>
--FILE--
<?php
function f(): int {
    static int $x = 0;
    return ++$x;
}
echo f(), "\n";
--EXPECT_EXIT--
255
