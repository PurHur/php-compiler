--TEST--
Hooked property array append/dim without &get → Indirect modification Error (#28590, zend_property_hooks.c)
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
try {
    $c->items[] = 'a';
    echo "WROTE\n";
} catch (Throwable $e) {
    echo get_class($e), ": ", $e->getMessage(), "\n";
}

class D {
    public array $items {
        get => $this->items ?? [];
        set => $this->items = $value;
    }
}
$d = new D();
try {
    $d->items[] = 'b';
    echo "WROTE\n";
} catch (Throwable $e) {
    echo get_class($e), ": ", $e->getMessage(), "\n";
}

class E {
    private array $a = [1];
    public array $x {
        &get => $this->a;
    }
}
$e = new E;
$e->x[] = 2;
echo implode(',', $e->x), "\n";
--EXPECT--
Error: Indirect modification of C::$items is not allowed
Error: Indirect modification of D::$items is not allowed
1,2
