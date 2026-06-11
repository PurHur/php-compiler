--TEST--
Stdlib: ReflectionClass::getReflectionConstants() — ReflectionClassConstant map (VM, #6662)
--FILE--
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
echo method_exists($rc, 'getReflectionConstants') ? 'class:yes' : 'class:no', "\n";
$map = $rc->getReflectionConstants();
echo 'class_count=', count($map), "\n";
$names = [];
foreach ($map as $c) {
    $names[] = $c->getName() . '=' . $c->getValue();
}
sort($names);
echo implode(',', $names), "\n";

$re = new ReflectionClass(E::class);
echo method_exists($re, 'getReflectionConstants') ? 'enum:yes' : 'enum:no', "\n";
$emap = $re->getReflectionConstants();
echo 'enum_count=', count($emap), "\n";
$enames = [];
foreach ($emap as $c) {
    $enames[] = $c->getName();
}
sort($enames);
echo implode(',', $enames), "\n";
--EXPECT--
class:yes
class_count=3
PRIV=3,PROT=2,PUB=1
enum:yes
enum_count=2
A,X
