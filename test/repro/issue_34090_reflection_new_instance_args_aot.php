<?php
// Repro #34090 — ReflectionClass::newInstanceArgs thin AOT
class Foo
{
    public $x = 1;

    public function __construct(int $n = 0)
    {
        $this->x = $n + 1;
        echo "CTOR:$n\n";
    }
}

$o = (new ReflectionClass(Foo::class))->newInstanceArgs([5]);
echo get_class($o), ':', $o->x, "\n";

$o2 = (new ReflectionClass(Foo::class))->newInstanceArgs([]);
echo get_class($o2), ':', $o2->x, "\n";

class Bar
{
    public $y = 7;
}
$b = (new ReflectionClass(Bar::class))->newInstanceArgs();
echo get_class($b), ':', $b->y, "\n";

abstract class Abs
{
    public $z = 2;
}
try {
    (new ReflectionClass(Abs::class))->newInstanceArgs([]);
    echo "ABS_OK\n";
} catch (Throwable $e) {
    echo get_class($e), ':', $e->getMessage(), "\n";
}
