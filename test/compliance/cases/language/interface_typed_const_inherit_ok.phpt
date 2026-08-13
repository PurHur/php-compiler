--TEST--
Language: interface typed constant inheritance — compatible class override (#5982)
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
    public const array X = [2, 3];
}
echo C::X[0], "\n";
--EXPECT--
2
