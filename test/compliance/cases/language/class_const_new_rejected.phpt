--TEST--
Language: class constant expression must reject `new` (#10391, Zend/zend_compile.c)
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
    public function __construct(public int $n = 0) {}
}
class Holder {
    public const X = new C(1);
}
var_dump(Holder::X->n);
echo Holder::X === Holder::X ? "same\n" : "diff\n";
--EXPECT_EXIT--
255
