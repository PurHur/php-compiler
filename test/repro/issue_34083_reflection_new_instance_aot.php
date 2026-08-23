<?php
// Repro #34083 — ReflectionClass::newInstance thin AOT
class Foo
{
    public $x = 1;

    public function __construct(int $n = 0)
    {
        $this->x = $n + 1;
        echo "CTOR:$n\n";
    }
}

$o = (new ReflectionClass(Foo::class))->newInstance(5);
echo get_class($o), ':', $o->x, "\n";

class Bar
{
    public $y = 7;
}
$b = (new ReflectionClass(Bar::class))->newInstance();
echo get_class($b), ':', $b->y, "\n";

abstract class Abs
{
    public $z = 2;
}
try {
    (new ReflectionClass(Abs::class))->newInstance();
    echo "ABS_OK\n";
} catch (Throwable $e) {
    echo get_class($e), ':', $e->getMessage(), "\n";
}
