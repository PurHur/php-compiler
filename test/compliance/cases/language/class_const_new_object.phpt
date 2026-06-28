--TEST--
Language: class constant object `new` expression rejected (#10391, Zend/zend_compile.c)
--SKIPIF--
<?php
if (!class_exists('PHPCompiler\\CompilerVersion')) {
    require __DIR__ . '/../../../../vendor/autoload.php';
}
if (PHPCompiler\CompilerVersion::supportsClassConstObjectExpressions()) {
    die('skip class const object expressions enabled on 8.3+ target');
}
?>
--FILE--
<?php
class C {
    public const X = new stdClass();
}
class Foo {
    public function __construct(public int $x = 0) {}
}
class D {
    public const Y = new Foo(7);
}
var_export(D::Y);
echo "\n";
echo D::Y->x === 7 ? "1\n" : "0\n";
--EXPECT_EXIT--
255
