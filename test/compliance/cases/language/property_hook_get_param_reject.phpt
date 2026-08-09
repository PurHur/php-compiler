--TEST--
Language: get($param) on property hook is compile-fatal (#29444; supersedes #18172 allow)
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
        get($unused) {
            return 1;
        }
    }
}
echo "accepted\n";
--EXPECT_EXIT--
255
--EXPECTF--
PHP Fatal error:  get hook of property C::$x must not have a parameter list in %s on line %d
