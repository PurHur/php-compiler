--TEST--
Language: instance typed property `new` default compile-rejects (#10693, Zend/zend_compile.c)
--SKIPIF--
<?php
if (!class_exists('PHPCompiler\\CompilerVersion')) {
    require __DIR__ . '/../../../../vendor/autoload.php';
}
if (PHPCompiler\CompilerVersion::supportsPropertyDefaultObjectExpressions()) {
    die('skip property default new enabled on PHP 8.4 forward profile');
}
?>
--FILE--
<?php
class Logger {}
class C {
    public Logger $l = new Logger();
}
--EXPECT_EXIT--
255
--EXPECTF--
parseAndCompile failure: target=%s: New expressions are not supported in this context
