--TEST--
Language: property hooks on reference profile parse error (#18019, Zend/zend_language_parser.y)
--SKIPIF--
<?php
if (!class_exists('PHPCompiler\\CompilerVersion')) {
    require __DIR__ . '/../../../../vendor/autoload.php';
}
if (PHPCompiler\CompilerVersion::supportsPropertyHooks()) {
    die('skip property hooks enabled on PHP 8.4 forward profile');
}
?>
--FILE--
<?php
class C {
    public int $x {
        get => $this->x;
    }
}
--EXPECT_EXIT--
255
--EXPECTF--
Fatal error: syntax error, unexpected token "{", expecting "," or ";" in %s on line %d
