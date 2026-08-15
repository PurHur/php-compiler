--TEST--
Language: final promoted ctor param is compile fatal on PROFILE=8.4 (#31153, Zend/zend_compile.c)
--SKIPIF--
<?php
if (!class_exists('PHPCompiler\\CompilerVersion')) {
    require __DIR__ . '/../../../../vendor/autoload.php';
}
putenv('PHP_COMPILER_PROFILE=8.4');
if (PHPCompiler\CompilerVersion::supportsFinalPromotedProperties()) {
    die('skip PROFILE=8.4 unexpectedly enables final promotion (#31153)');
}
?>
--ENV--
PHP_COMPILER_PROFILE=8.4
--FILE--
<?php
class C { public function __construct(final public int $x) {} }
echo (new C(1))->x, "\n";
--EXPECT_EXIT--
255
--EXPECTF--
PHP Fatal error:  Cannot use the final modifier on a parameter in %s on line %d
