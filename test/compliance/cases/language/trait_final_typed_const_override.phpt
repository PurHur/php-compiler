--TEST--
Language: class cannot override final typed trait constant (#7043, Zend/zend_traits.c)
--ENV--
PHP_COMPILER_PROFILE=8.3
--SKIPIF--
<?php
if (!class_exists('PHPCompiler\\CompilerVersion')) {
    require __DIR__ . '/../../../../vendor/autoload.php';
}
if (!PHPCompiler\CompilerVersion::supportsTypedTraitConstants()) {
    die('skip typed trait constants require CompilerVersion 8.3+');
}
?>
--FILE--
<?php
trait T {
    final public const int X = 1;
}
class C {
    use T;
    public const int X = 2;
}
echo C::X, "\n";
--EXPECT_EXIT--
255
