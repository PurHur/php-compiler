<?php
/**
 * #22044 — ReflectionAttribute::getTarget() returns Attribute::TARGET_* for the
 * declaration site (php-src zim_reflection_attribute_getTarget).
 */
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

echo 'class=', (new ReflectionClass(T::class))->getAttributes()[0]->getTarget(), "\n";
echo 'method=', (new ReflectionMethod(M::class, 'm'))->getAttributes()[0]->getTarget(), "\n";
echo 'prop=', (new ReflectionProperty(M::class, 'p'))->getAttributes()[0]->getTarget(), "\n";
echo 'const=', (new ReflectionClassConstant(M::class, 'C'))->getAttributes()[0]->getTarget(), "\n";
echo 'param=', (new ReflectionMethod(M::class, 'q'))->getParameters()[0]->getAttributes()[0]->getTarget(), "\n";
echo 'func=', (new ReflectionFunction('f'))->getAttributes()[0]->getTarget(), "\n";
echo 'TARGET_CLASS=', Attribute::TARGET_CLASS, "\n";
echo 'TARGET_FUNCTION=', Attribute::TARGET_FUNCTION, "\n";
echo 'TARGET_METHOD=', Attribute::TARGET_METHOD, "\n";
echo 'TARGET_PROPERTY=', Attribute::TARGET_PROPERTY, "\n";
echo 'TARGET_CLASS_CONSTANT=', Attribute::TARGET_CLASS_CONSTANT, "\n";
echo 'TARGET_PARAMETER=', Attribute::TARGET_PARAMETER, "\n";
