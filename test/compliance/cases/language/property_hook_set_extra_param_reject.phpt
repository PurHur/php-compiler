--TEST--
Language: set($value, $extra) on property hook is compile-fatal (#29443)
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
        set($value, $extra) {
            $this->x = $value;
        }
    }
}
echo "accepted\n";
--EXPECT_EXIT--
255
--EXPECTF--
PHP Fatal error:  set hook of property C::$x must accept exactly one parameters in %s on line %d
