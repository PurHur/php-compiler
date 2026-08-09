--TEST--
Property hook ??= — Zend null-checks get-hook return, then set (#29266 / #6472, zend_object_handlers.c)
--SKIPIF--
<?php
if (!class_exists('PHPCompiler\CompilerVersion')) {
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
    private ?string $x = null;
    public string $y {
        get => $this->x ?? 'default';
        set => $this->x = $value;
    }
}
$c = new C();
$c->y ??= 'assigned';
echo $c->y, "\n";

class D {
    private string $x = 'kept';
    public string $y {
        get => $this->x;
        set => $this->x = $value;
    }
}
$d = new D();
$d->y ??= 'ignored';
echo $d->y, "\n";

class E {
    public ?string $y {
        get => null;
        set { $this->store = $value; }
    }
    private ?string $store = null;
}
$e = new E();
$e->y ??= 'via-null-get';
var_export($e->y);
echo "\n";
--EXPECT--
default
kept
NULL
