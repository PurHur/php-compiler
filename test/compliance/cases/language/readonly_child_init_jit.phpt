--TEST--
Language: child constructor cannot initialize parent readonly promoted property JIT (#9714)
--FILE--
<?php
declare(strict_types=1);

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
    echo "ok\n";
} catch (Throwable $e) {
    echo get_class($e), ': ', $e->getMessage(), "\n";
}
--EXPECT--
Error: Cannot initialize readonly property Parent_::$x from Child
