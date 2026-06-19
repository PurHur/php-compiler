--TEST--
Language: class constants with object expressions — JIT (#3196, #9850, Zend/zend_constants.c)
--SKIPIF--
<?php
if (!class_exists('PHPCompiler\\CompilerVersion')) {
    require __DIR__ . '/../../../../vendor/autoload.php';
}
if (!PHPCompiler\CompilerVersion::supportsClassConstObjectExpressions()) {
    die('skip class const object expressions require CompilerVersion 8.3+');
}
?>
--FILE--
<?php
class C {
    public const X = new stdClass();
}
var_export(C::X);
--EXPECT--
(object) array(
)
