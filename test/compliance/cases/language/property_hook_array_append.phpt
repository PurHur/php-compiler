--TEST--
Hooked property array append read-modify-write via get/set hooks (#19171, zend_property_hooks.c)
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
    public array $items {
        get {
            return $this->items ?? [];
        }
        set {
            $this->items = $value;
        }
    }
}
$c = new C();
$c->items[] = 'a';
echo count($c->items), "\n";
echo $c->items[0], "\n";

class D {
    public array $items {
        get => $this->items ?? [];
        set => $this->items = $value;
    }
}
$d = new D();
$d->items[] = 'b';
echo count($d->items), "\n";
echo $d->items[0], "\n";
--EXPECT--
1
a
1
b
