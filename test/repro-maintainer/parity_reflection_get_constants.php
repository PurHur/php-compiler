<?php

declare(strict_types=1);

class A
{
    public const PA = 1;
    protected const PTA = 10;
    private const XA = 2;
}

class B extends A
{
    public const PB = 3;
    private const XB = 4;
}

$r = new ReflectionClass(B::class);

echo "all keys:\n";
var_export(array_keys($r->getConstants()));
echo "\npublic only:\n";
var_export($r->getConstants(ReflectionClassConstant::IS_PUBLIC));
echo "\nprivate only:\n";
var_export(array_keys($r->getConstants(ReflectionClassConstant::IS_PRIVATE)));
echo "\n";
