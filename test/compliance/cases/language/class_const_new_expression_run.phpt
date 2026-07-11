--TEST--
Language: class constant `new` expression — VM run (#12940, Zend/zend_compile.c)
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
class Holder {
    public const DT = new DateTime('2020-01-01');
}
echo Holder::DT->format('Y'), "\n";
echo Holder::DT === Holder::DT ? "1\n" : "0\n";
--EXPECT--
2020
1
