--TEST--
Language: typed trait constants — value/type mismatch compile error (#6012, Zend/zend_compile.c)
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
trait TBad {
    public const int X = '1';
}

final class C {
    use TBad;
}
--EXPECT_EXIT--
255
