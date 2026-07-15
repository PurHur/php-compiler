--TEST--
Language: class constant bare `new UserClass` on PHP 8.4 forward profile (#19046, Zend/zend_compile.c)
--ENV--
PHP_COMPILER_PROFILE=8.4
--SKIPIF--
<?php
if (!class_exists('PHPCompiler\\CompilerVersion')) {
    require __DIR__ . '/../../../../../vendor/autoload.php';
}
if (!PHPCompiler\CompilerVersion::supportsNewWithoutParensInConstAndStaticInitializers()) {
    die('skip bare new in class constants requires PHP_COMPILER_PROFILE=8.4');
}
?>
--FILE--
<?php
class Foo {
    public function __construct(public int $n = 7) {}
}

class Holder {
    public const BAR = new Foo;
}

echo Holder::BAR->n, "\n";
echo get_class(Holder::BAR), "\n";
--EXPECT--
7
Foo
