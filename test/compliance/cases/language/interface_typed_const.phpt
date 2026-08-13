--TEST--
Language: typed interface constants (PHP 8.3, issue #5980)
--ENV--
PHP_COMPILER_PROFILE=8.3
--SKIPIF--
<?php
if (!class_exists('PHPCompiler\\CompilerVersion')) {
    require __DIR__ . '/../../../../vendor/autoload.php';
}
if (!PHPCompiler\CompilerVersion::supportsInterfaceTypedConstants()) {
    die('skip typed interface constants require forward profile 8.3+ (#24917)');
}
?>
--FILE--
<?php
interface I {
    public const string X = 'a';
}
class C implements I {}
echo C::X, "\n";
--EXPECT--
a

