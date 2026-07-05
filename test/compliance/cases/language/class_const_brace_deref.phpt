--TEST--
Language: class constant brace dereference on PHP 8.3+ profile (#16597, Zend/zend_compile.c)
--SKIPIF--
<?php
if (!class_exists('PHPCompiler\\CompilerVersion')) {
    require __DIR__ . '/../../../../vendor/autoload.php';
}
if (!PHPCompiler\CompilerVersion::supportsClassConstBraceDereference()) {
    die('skip class const brace deref disabled on reference profile');
}
?>
--FILE--
<?php
class C
{
    public const X = 42;
    public const Y = 'ok';
}
echo C::{'X'}, "\n";
echo C::{"Y"}, "\n";
--EXPECT--
42
ok
