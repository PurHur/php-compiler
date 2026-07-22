--TEST--
Stdlib: ReflectionAttribute::getTarget() returns Attribute::TARGET_* bitmask (#22044)
--FILE--
<?php
#[Attribute]
class RA
{
}

#[RA]
class T
{
}

class M
{
    #[RA]
    public function m()
    {
    }

    #[RA]
    public $p;

    #[RA]
    public const C = 1;

    public function q(#[RA] $x)
    {
    }
}

#[RA]
function f()
{
}

echo (new ReflectionClass(T::class))->getAttributes()[0]->getTarget(), "\n";
echo (new ReflectionMethod(M::class, 'm'))->getAttributes()[0]->getTarget(), "\n";
echo (new ReflectionProperty(M::class, 'p'))->getAttributes()[0]->getTarget(), "\n";
echo (new ReflectionClassConstant(M::class, 'C'))->getAttributes()[0]->getTarget(), "\n";
echo (new ReflectionMethod(M::class, 'q'))->getParameters()[0]->getAttributes()[0]->getTarget(), "\n";
echo (new ReflectionFunction('f'))->getAttributes()[0]->getTarget(), "\n";
--EXPECT--
1
4
8
16
32
2
