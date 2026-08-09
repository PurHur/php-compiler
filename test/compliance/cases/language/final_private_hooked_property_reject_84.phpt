--TEST--
Language: final+private hooked property compile-fatal (#29425, Zend/zend_compile.c)
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
class C {
    final private string $x {
        get => 'g';
        set {}
    }
}
echo "parsed\n";
--EXPECT_EXIT--
255
--EXPECTF--
PHP Fatal error:  Property cannot be both final and private in %s on line %d
