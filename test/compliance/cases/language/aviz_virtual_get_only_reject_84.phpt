--TEST--
Language: get-only virtual + asymmetric set visibility compile-fatal (#29426, Zend/zend_inheritance.c)
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
    public private(set) string $x {
        get => 'g';
    }
}
echo "parsed\n";
--EXPECT_EXIT--
255
--EXPECTF--
PHP Fatal error:  Read-only virtual property C::$x must not specify asymmetric visibility in %s on line %d
