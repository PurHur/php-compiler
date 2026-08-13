--TEST--
Language: typed interface constant override must be compatible (PHP 8.3, issue #5980)
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
class C implements I {
    public const int X = 1;
}
echo "unreachable\n";
--EXPECTF--
Fatal error: Type of C::X must be compatible with I::X of type string in %s on line %d
--EXPECT_EXIT--
255
