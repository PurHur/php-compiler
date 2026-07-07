--TEST--
Language: promoted public protected(set) unparenthesized — parses and reads (#16161, Zend/zend_compile.c)
--SKIPIF--
<?php
if (!class_exists('PHPCompiler\\CompilerVersion')) {
    require __DIR__ . '/../../../../vendor/autoload.php';
}
if (!PHPCompiler\CompilerVersion::supportsAsymmetricVisibility()) {
    die('skip requires PHP_COMPILER_PROFILE=8.4 asymmetric visibility gate');
}
?>
--ENV--
PHP_COMPILER_PROFILE=8.4
--FILE--
<?php
class D {
    public function __construct(public protected(set) string $n = 'ok') {}
}
echo (new D())->n, "\n";
--EXPECT--
ok
