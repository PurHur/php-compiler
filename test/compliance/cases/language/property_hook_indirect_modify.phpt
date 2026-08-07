--TEST--
Hooked property dim/append without &get → Indirect modification Error (#28590; was #6775 RMW)
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
        get => $this->items ?? [];
        set => $this->items = $value;
    }
}
$c = new C();
try {
    $c->items[] = 1;
    echo "WROTE append\n";
} catch (Throwable $e) {
    echo get_class($e), ": ", $e->getMessage(), "\n";
}
try {
    $c->items['k'] = 'v';
    echo "WROTE dim\n";
} catch (Throwable $e) {
    echo get_class($e), ": ", $e->getMessage(), "\n";
}
try {
    unset($c->items['k']);
    echo "UNSET ok\n";
} catch (Throwable $e) {
    echo get_class($e), ": ", $e->getMessage(), "\n";
}

class A {
    private array $s = [];
    public array $items {
        get => $this->s;
        set { $this->s = $value; }
    }
}
$a = new A;
try {
    $a->items[] = 1;
    echo "WROTE separate\n";
} catch (Throwable $e) {
    echo get_class($e), ": ", $e->getMessage(), "\n";
}

class V {
    public array $items {
        get => ['a' => 1];
        set {}
    }
}
$v = new V;
try {
    $v->items['a'] = 99;
    echo "WROTE virtual\n";
} catch (Throwable $e) {
    echo get_class($e), ": ", $e->getMessage(), "\n";
}
--EXPECT--
Error: Indirect modification of C::$items is not allowed
Error: Indirect modification of C::$items is not allowed
Error: Indirect modification of C::$items is not allowed
Error: Indirect modification of A::$items is not allowed
Error: Indirect modification of V::$items is not allowed
