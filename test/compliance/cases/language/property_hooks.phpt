--TEST--
Language: property hooks get/set on PHP_COMPILER_PROFILE=8.4 forward profile (#15994, Zend/zend_compile.c)
--SKIPIF--
<?php
if (!class_exists('PHPCompiler\\CompilerVersion')) {
    require __DIR__ . '/../../../../vendor/autoload.php';
}
putenv('PHP_COMPILER_PROFILE=8.4');
if (!PHPCompiler\CompilerVersion::supportsPropertyHooks()) {
    die('skip property hooks require PHP_COMPILER_PROFILE=8.4');
}
?>
--ENV--
PHP_COMPILER_PROFILE=8.4
--FILE--
<?php
class C {
    public string $x {
        get => $this->x ?? 'd';
        set(string $v) {
            $this->x = strtoupper($v);
        }
    }
}
$c = new C();
$c->x = 'hi';
echo $c->x, "\n";
--EXPECT--
HI
