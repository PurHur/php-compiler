--TEST--
Language: property hook &get by-reference — array append mutates backing (#21098, zend_property_hooks.c)
--SKIPIF--
<?php
if (!class_exists('PHPCompiler\\CompilerVersion')) {
    require __DIR__ . '/../../../../vendor/autoload.php';
}
putenv('PHP_COMPILER_PROFILE=8.4');
if (!PHPCompiler\CompilerVersion::supportsPropertyHooks()) {
    die('skip requires PHP_COMPILER_PROFILE=8.4 property hooks gate');
}
?>
--ENV--
PHP_COMPILER_PROFILE=8.4
--FILE--
<?php
class C {
    private array $a = [1];
    public array $x {
        &get => $this->a;
    }
}
$o = new C;
$o->x[] = 2;
echo implode(',', $o->x), "\n";

class D {
    private array $a = [1];
    public array $x {
        &get {
            return $this->a;
        }
    }
}
$d = new D;
$d->x[1] = 3;
echo implode(',', $d->x), "\n";
--EXPECT--
1,2
1,3
