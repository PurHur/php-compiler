<?php
// Repro #34119 — ReflectionClass::getReflectionConstants thin AOT
class GrcParent
{
    public const PUB = 1;
    protected const PROT = 2;
    private const PRIV = 3;
}

class GrcChild extends GrcParent
{
    public const OWN = 9;
}

$r = new ReflectionClass(GrcChild::class);
$names = [];
foreach ($r->getReflectionConstants() as $c) {
    $names[] = $c->getName();
}
sort($names);
echo implode(',', $names), '|';
$pub = [];
foreach ($r->getReflectionConstants(ReflectionClassConstant::IS_PUBLIC) as $c) {
    $pub[] = $c->getName();
}
sort($pub);
echo implode(',', $pub), '|';
$null = [];
foreach ($r->getReflectionConstants(null) as $c) {
    $null[] = $c->getName();
}
sort($null);
echo implode(',', $null), "\n";
