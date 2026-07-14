--TEST--
Language: class constant bare `new Class` on PHP 8.4 forward profile (#18816, Zend/zend_compile.c)
--ENV--
PHP_COMPILER_PROFILE=8.4
--SKIPIF--
<?php
if (!class_exists('PHPCompiler\\CompilerVersion')) {
    require __DIR__ . '/../../../../vendor/autoload.php';
}
if (!PHPCompiler\CompilerVersion::supportsNewWithoutParensInConstAndStaticInitializers()) {
    die('skip bare new in class constants requires PHP_COMPILER_PROFILE=8.4');
}
?>
--FILE--
<?php
class Holder {
    public const OBJ = new stdClass;
}
echo get_class(Holder::OBJ), "\n";
echo Holder::OBJ === Holder::OBJ ? "1\n" : "0\n";
--EXPECT--
stdClass
1
