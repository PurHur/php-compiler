--TEST--
Language: typed class constants rejected on reference profile (#12798, Zend/zend_compile.c)
--SKIPIF--
<?php
if (!class_exists('PHPCompiler\\CompilerVersion')) {
    require __DIR__ . '/../../../../vendor/autoload.php';
}
putenv('PHP_COMPILER_PROFILE=8.2');
if (PHPCompiler\CompilerVersion::supportsTypedClassConstants()) {
    die('skip PHP_COMPILER_PROFILE=8.2 unexpectedly enables typed class constants');
}
?>
--ENV--
PHP_COMPILER_PROFILE=8.2
--FILE--
<?php
class C {
    public const string K = 'v';
}
echo C::K, "\n";
--EXPECT_EXIT--
255
