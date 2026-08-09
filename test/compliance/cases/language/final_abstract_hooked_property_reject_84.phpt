--TEST--
Language: final+abstract hooked property compile-fatal (#29424, php-src GH-17916, Zend/zend_compile.c)
--ENV--
PHP_COMPILER_PROFILE=8.4
--SKIPIF--
<?php
if (!class_exists('PHPCompiler\\CompilerVersion')) {
    require __DIR__ . '/../../../../vendor/autoload.php';
}
if (!PHPCompiler\CompilerVersion::supportsPropertyHooks()) {
    die('skip property hooks disabled on reference profile');
}
?>
--FILE--
<?php
abstract class A {
    final abstract public string $x { get; }
}
echo "parsed\n";
--EXPECT_EXIT--
255
--EXPECTF--
PHP Fatal error:  Cannot use the final modifier on an abstract property in %s on line %d
