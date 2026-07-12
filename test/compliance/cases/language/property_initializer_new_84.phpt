--TEST--
Language: typed instance property `new` default on PHP 8.4 forward profile (#18040, Zend/zend_compile.c)
--ENV--
PHP_COMPILER_PROFILE=8.4
--SKIPIF--
<?php
if (!class_exists('PHPCompiler\\CompilerVersion')) {
    require __DIR__ . '/../../../../vendor/autoload.php';
}
if (!PHPCompiler\CompilerVersion::supportsPropertyDefaultObjectExpressions()) {
    die('skip property default new requires PHP_COMPILER_PROFILE=8.4');
}
?>
--FILE--
<?php
class Logger {}
class S {
    public Logger $l = new Logger();
}
$o = new S();
echo $o->l instanceof Logger ? "ok\n" : "no\n";
?>
--EXPECT--
ok
