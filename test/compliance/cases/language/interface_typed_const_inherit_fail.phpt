--TEST--
Language: interface typed constant inheritance — incompatible class override (#5982)
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
    public const array X = [1];
}
class C implements I {
    public const string X = 'not-array';
}
echo "compiled\n";
--EXPECT_EXIT--
255
