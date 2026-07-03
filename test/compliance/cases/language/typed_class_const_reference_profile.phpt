--TEST--
Language: typed class constants rejected on PHP 8.2 reference profile (#15662, Zend/zend_compile.c)
--SKIPIF--
<?php
if (!class_exists('PHPCompiler\\CompilerVersion')) {
    require __DIR__ . '/../../../../vendor/autoload.php';
}
if (PHPCompiler\CompilerVersion::supportsTypedClassConstants()) {
    die('skip typed class constants enabled on forward profile');
}
?>
--FILE--
<?php
class Foo
{
    public const string FOO = 'bar';
}
echo Foo::FOO, "\n";
--EXPECT_EXIT--
255
