<?php
// Repro #34113 — ReflectionClass::getProperties thin AOT
class GpParent
{
    public $pub = 1;
    protected $prot = 2;
    private $priv = 3;
    public static $st = 4;
}

class GpChild extends GpParent
{
    public $child = 5;
    private $cpriv = 6;
}

$r = new ReflectionClass(GpChild::class);
$names = [];
foreach ($r->getProperties() as $p) {
    $names[] = $p->getName();
}
sort($names);
echo implode(',', $names), '|';
$pub = [];
foreach ($r->getProperties(ReflectionProperty::IS_PUBLIC) as $p) {
    $pub[] = $p->getName();
}
sort($pub);
echo implode(',', $pub), '|';
$null = [];
foreach ($r->getProperties(null) as $p) {
    $null[] = $p->getName();
}
sort($null);
echo implode(',', $null), "\n";
