--TEST--
Language: static property `new` default compile-rejects (#10095, Zend/zend_compile.c)
--SKIPIF--
<?php
if (!class_exists('PHPCompiler\\CompilerVersion')) {
    require __DIR__ . '/../../../../vendor/autoload.php';
}
if (PHPCompiler\CompilerVersion::supportsStaticPropertyDefaultObjectExpressions()) {
    die('skip static property default new allowed on PHP 8.4 forward profile');
}
?>
--FILE--
<?php
class C {
    public static DateTime $d = new DateTime('2020-01-01');
}
--EXPECT_EXIT--
255
--EXPECTF--
parseAndCompile failure: target=%s: New expressions are not supported in this context
