--TEST--
Language: bare `new Name->prop` without ctor parentheses — Parse error on forward profile (#20598)
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
    public string $name = 'n';
}
echo new Greeter->name, "\n";
--EXPECT_EXIT--
255
--EXPECTF--
Parse error: syntax error, unexpected token "->", expecting "," or ";" in %s on line %d
