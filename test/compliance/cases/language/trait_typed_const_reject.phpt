--TEST--
Language: typed trait constants rejected on 8.2 target (#5212, Zend/zend_compile.c)
--SKIPIF--
<?php
if (!class_exists('PHPCompiler\\CompilerVersion')) {
    require __DIR__ . '/../../../../vendor/autoload.php';
}
if (PHPCompiler\CompilerVersion::supportsTypedTraitConstants()) {
    die('skip typed trait constants enabled on 8.3+ target');
}
?>
--FILE--
<?php
trait T {
    public const string X = 'a';
}
class C {
    use T;
}
echo C::X, "\n";
--EXPECT_EXIT--
255
