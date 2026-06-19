--TEST--
Language: readonly class extends readonly parent — parent::__construct initializes inherited props (#10124, Zend/zend_object_handlers.c)
--FILE--
<?php
declare(strict_types=1);

readonly class P {
    public function __construct(public readonly int $x) {}
}

readonly class C extends P {
    public function __construct(int $x, public readonly int $y) {
        parent::__construct($x);
    }
}

$c = new C(1, 2);
var_export([$c->x, $c->y]);
echo "\n";

class Parent_ {
    public function __construct(public readonly string $x) {}
}

class Child extends Parent_ {
    public function __construct(string $x) {
        $this->x = $x;
    }
}

try {
    new Child('a');
    echo "child: ok\n";
} catch (Throwable $e) {
    echo 'child: ', get_class($e), ': ', $e->getMessage(), "\n";
}
--EXPECT--
array (
  0 => 1,
  1 => 2,
)
child: Error: Cannot initialize readonly property Parent_::$x from scope Child
