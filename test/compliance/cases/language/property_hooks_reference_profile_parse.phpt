--TEST--
Language: property hooks on reference profile emit Parse error prefix (#18085, Zend/zend_language_parser.y)
--SKIPIF--
<?php
if (!class_exists('PHPCompiler\\CompilerVersion')) {
    require __DIR__ . '/../../../../vendor/autoload.php';
}
putenv('PHP_COMPILER_PROFILE=8.2');
if (PHPCompiler\CompilerVersion::supportsPropertyHooks()) {
    die('skip PHP_COMPILER_PROFILE=8.2 unexpectedly enables property hooks');
}
?>
--ENV--
PHP_COMPILER_PROFILE=8.2
--FILE--
<?php
class C {
    public string $x {
        get => 'a';
    }
}
echo "compiled\n";
--EXPECT_EXIT--
255
--EXPECTF--
Parse error: syntax error, unexpected token "{", expecting "," or ";" in %s on line %d
