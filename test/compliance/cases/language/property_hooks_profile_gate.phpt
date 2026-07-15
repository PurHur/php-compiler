--TEST--
Language: property hooks rejected on default reference profile (#18531, Zend/zend_language_parser.y)
--SKIPIF--
<?php
if (!class_exists('PHPCompiler\\CompilerVersion')) {
    require __DIR__ . '/../../../../vendor/autoload.php';
}
putenv('PHP_COMPILER_PROFILE');
if (PHPCompiler\CompilerVersion::supportsPropertyHooks()) {
    die('skip default reference profile unexpectedly enables property hooks');
}
?>
--FILE--
<?php
class C {
    public int $p {
        get => 42;
    }
}
echo "compiled\n";
--EXPECT_EXIT--
255
--EXPECTF--
Parse error: syntax error, unexpected token "{", expecting "," or ";" in %s on line %d
