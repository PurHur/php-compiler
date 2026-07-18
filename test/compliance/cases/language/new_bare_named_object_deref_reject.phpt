--TEST--
Language: bare `new Name->…` without ctor parentheses — Parse error on forward profile (#20598, Zend/zend_language_parser.y)
--SKIPIF--
<?php
if (!class_exists('PHPCompiler\\CompilerVersion')) {
    require __DIR__ . '/../../../../vendor/autoload.php';
}
if (!PHPCompiler\CompilerVersion::supportsDereferencableNewWithoutOuterParens()) {
    die('skip requires PHP 8.4+ dereferencable new forward profile');
}
?>
--ENV--
PHP_COMPILER_PROFILE=8.4
--FILE--
<?php
class Greeter
{
    public function hello(): string
    {
        return 'hi';
    }
}
echo new Greeter->hello(), "\n";
--EXPECT_EXIT--
255
--EXPECTF--
Parse error: syntax error, unexpected token "->", expecting "," or ";" in %s on line %d
