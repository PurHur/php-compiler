--TEST--
Language: class constant bare `new` on PHP 8.4 forward profile (#18816, Zend/zend_compile.c)
--ENV--
PHP_COMPILER_PROFILE=8.4
--SKIPIF--
<?php
if (!class_exists('PHPCompiler\\CompilerVersion')) {
    require __DIR__ . '/../../../../vendor/autoload.php';
}
if (!PHPCompiler\CompilerVersion::supportsClassConstObjectExpressions()
    || !PHPCompiler\CompilerVersion::supportsPropertyDefaultObjectExpressions()) {
    die('skip bare new in class const requires PHP_COMPILER_PROFILE=8.4');
}
?>
--FILE--
<?php
class Foo {
    public function __construct(public int $x = 0) {}
}
class Holder {
    public const FOO = new Foo;
}
echo get_class(Holder::FOO), "\n";
echo Holder::FOO->x === 0 ? "1\n" : "0\n";
echo Holder::FOO === Holder::FOO ? "1\n" : "0\n";
?>
--EXPECT--
Foo
1
1
