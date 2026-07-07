--TEST--
Stdlib: ReflectionClass::getConstants() — filter flags + inherited/private behavior (#4479)
--FILE--
<?php
declare(strict_types=1);

class A {
    public const PA = 1;
    protected const PTA = 10;
    private const XA = 2;
}
class B extends A {
    public const PB = 3;
    private const XB = 4;
}

$r = new ReflectionClass(B::class);

echo "all:", implode(',', array_keys($r->getConstants())), "\n";
$pub = $r->getConstants(ReflectionClassConstant::IS_PUBLIC);
echo "public:", implode(',', array_keys($pub)), "\n";
echo "public_values:", $pub['PB'], ',', $pub['PA'], "\n";
echo "private:", implode(',', array_keys($r->getConstants(ReflectionClassConstant::IS_PRIVATE))), "\n";
echo "protected:", implode(',', array_keys($r->getConstants(ReflectionClassConstant::IS_PROTECTED))), "\n";
$mix = $r->getConstants(ReflectionClassConstant::IS_PUBLIC | ReflectionClassConstant::IS_PROTECTED);
echo "pub_prot:", implode(',', array_keys($mix)), "\n";
--EXPECT--
all:PB,XB,PA,PTA
public:PB,PA
public_values:3,1
private:XB
protected:PTA
pub_prot:PB,PA,PTA
