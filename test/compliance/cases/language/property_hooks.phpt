--TEST--
Language: property hooks get/set on default 8.4 profile (#15994, Zend/zend_compile.c)
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
