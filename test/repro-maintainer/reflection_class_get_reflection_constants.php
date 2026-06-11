<?php
class C {
    public const PUB = 1;
    protected const PROT = 2;
    private const PRIV = 3;
}
enum E: int {
    case A = 1;
    private const X = 0;
}

$rc = new ReflectionClass(C::class);
echo 'class method=', var_export(method_exists($rc, 'getReflectionConstants'), true), "\n";
$map = $rc->getReflectionConstants();
echo 'count=', count($map), "\n";
$first = $map[0];
echo 'first=', $first->getName(), '=', $first->getValue(), "\n";

$re = new ReflectionClass(E::class);
echo 'enum method=', var_export(method_exists($re, 'getReflectionConstants'), true), "\n";
$emap = $re->getReflectionConstants();
echo 'enum cases=', count($emap), "\n";
