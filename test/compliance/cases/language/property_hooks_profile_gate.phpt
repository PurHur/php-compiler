--TEST--
Language: property hooks rejected on default reference profile (#30483, re-#22371, Zend/zend_language_parser.y)
--SKIPIF--
<?php
if (!class_exists('PHPCompiler\\CompilerVersion')) {
    require __DIR__ . '/../../../../vendor/autoload.php';
}
putenv('PHP_COMPILER_PROFILE');
if (PHPCompiler\CompilerVersion::supportsPropertyHooks()) {
    die('skip property hooks unexpectedly enabled on default profile');
}
?>
--FILE--
<?php
class User {
    public string $name {
        set(string $value) { $this->name = ucfirst($value); }
        get => $this->name;
    }
}
--EXPECT_EXIT--
255
--EXPECTF--
Parse error: syntax error, unexpected token "{", expecting "," or ";" in %s on line %d
