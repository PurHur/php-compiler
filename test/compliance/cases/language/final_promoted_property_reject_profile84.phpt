--TEST--
Language: final promoted ctor property rejected on PROFILE=8.4 (#27123, Zend/zend_compile.c)
--SKIPIF--
<?php
if (!class_exists('PHPCompiler\\CompilerVersion')) {
    require __DIR__ . '/../../../../vendor/autoload.php';
}
putenv('PHP_COMPILER_PROFILE=8.4');
if (PHPCompiler\CompilerVersion::supportsFinalPromotedProperties()) {
    die('skip PROFILE=8.4 unexpectedly enables final promotion (#27123)');
}
?>
--ENV--
PHP_COMPILER_PROFILE=8.4
--FILE--
<?php
class C {
    public function __construct(public final string $name) {}
}
echo (new C('x'))->name;
--EXPECT_EXIT--
255
--EXPECTF--
Fatal error: Cannot use the final modifier on a parameter in %s on line %d
