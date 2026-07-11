--TEST--
Language: class constant `new` expression — stable object identity (#13488, Zend/zend_compile.c)
--SKIPIF--
<?php
if (!class_exists('PHPCompiler\\CompilerVersion')) {
    require __DIR__ . '/../../../../vendor/autoload.php';
}
if (!PHPCompiler\CompilerVersion::supportsClassConstObjectExpressions()) {
    die('skip class const object expressions disabled on reference profile');
}
?>
--FILE--
<?php
class Foo {
    public function __construct(public int $n = 0) {}
}

class Holder {
    public const BAR = new Foo(42);
}

echo Holder::BAR->n, "\n";
echo Holder::BAR === Holder::BAR ? "1\n" : "0\n";
--EXPECT--
42
1
