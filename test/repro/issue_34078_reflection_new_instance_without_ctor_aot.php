<?php
// Repro #34078 — ReflectionClass::newInstanceWithoutConstructor thin AOT
class Foo
{
    public $x = 1;

    public function __construct()
    {
        $this->x = 99;
        echo "CTOR\n";
    }
}

$o = (new ReflectionClass(Foo::class))->newInstanceWithoutConstructor();
echo get_class($o), ':', $o->x, "\n";

abstract class Abs
{
    public $y = 2;
}
try {
    (new ReflectionClass(Abs::class))->newInstanceWithoutConstructor();
    echo "ABS_OK\n";
} catch (Throwable $e) {
    echo get_class($e), ':', $e->getMessage(), "\n";
}
