--TEST--
Language: set(&$value) on property hook is compile-fatal (#29442)
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
    public $x {
        set(&$value) {
            $this->x = $value;
        }
    }
}
echo "accepted\n";
--EXPECT_EXIT--
255
--EXPECTF--
PHP Fatal error:  Parameter $value of set hook C::$x must not be pass-by-reference in %s on line %d
