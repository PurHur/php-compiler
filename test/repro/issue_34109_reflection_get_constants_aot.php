<?php

class A
{
    public const K = 2;
    protected const P = 'p';
    private const HID = 1;
}

class B extends A
{
    public const OWN = 9;
}

$ra = new ReflectionClass(A::class);
$rb = new ReflectionClass(B::class);
$rs = new ReflectionClass(stdClass::class);

echo json_encode($ra->getConstants()), "\n";
echo json_encode($rb->getConstants()), "\n";
echo json_encode($rs->getConstants()), "\n";
echo json_encode($ra->getConstants(ReflectionClassConstant::IS_PUBLIC)), "\n";
echo json_encode($rb->getConstants(ReflectionClassConstant::IS_PUBLIC)), "\n";
