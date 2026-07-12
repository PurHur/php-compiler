--TEST--
Language: dynamic class constant fetch Class::{$name} — no TypeReconstructor warnings (#18093, Zend/zend_compile.c)
--SKIPIF--
<?php
if (!class_exists('PHPCompiler\\CompilerVersion')) {
    require __DIR__ . '/../../../../../vendor/autoload.php';
}
if (!PHPCompiler\CompilerVersion::supportsDynamicClassConstFetch()) {
    die('skip dynamic class const fetch disabled on reference profile');
}
?>
--FILE--
<?php
class C {
    public const FOO = 'bar';
}
$name = 'FOO';
echo constant(C::class.'::'.$name), "\n";
echo C::{$name}, "\n";
--EXPECT--
bar
bar
