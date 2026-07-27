<?php
/**
 * PROFILE=8.4: public private(set) + set hook (RFC asymmetric visibility × hooks).
 */
error_reporting(E_ALL);

class Foo {
    public private(set) string $foo {
        set => strtoupper($value);
    }

    public function __construct(string $foo) {
        $this->foo = $foo;
    }

    public function setFoo(string $foo): void {
        $this->foo = $foo;
    }
}

$o = new Foo('ada');
echo 'get=', $o->foo, "\n";
$o->setFoo('bob');
echo 'after=', $o->foo, "\n";
try {
    $o->foo = 'carol';
    echo "outside_ok\n";
} catch (Throwable $e) {
    echo 'outside=', $e::class, ':', $e->getMessage(), "\n";
}
