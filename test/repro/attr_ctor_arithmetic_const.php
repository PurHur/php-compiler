<?php
// Issue #21725 — attribute ctor arithmetic const exprs (php-src-strict).
#[Attribute]
class A
{
    public function __construct(public int $x)
    {
    }
}
#[A(1 + 2)]
class C
{
}
$r = (new ReflectionClass(C::class))->getAttributes()[0]->newInstance();
echo $r->x, PHP_EOL;
