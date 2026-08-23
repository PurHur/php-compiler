<?php
// Repro #34113 — ReflectionClass::getProperties thin AOT
class BaseGp
{
    private $p = 1;
    protected $r = 3;
    public $s = 4;
    public static $ss = 40;
}

class ChildGp extends BaseGp
{
    public $q = 2;
    private $t = 5;
    public static $sq = 20;
}

$all = (new ReflectionClass(ChildGp::class))->getProperties();
$names = [];
foreach ($all as $p) {
    $names[] = $p->getName().'@'.$p->class;
}
sort($names);
echo implode(',', $names), "\n";

$allNull = (new ReflectionClass(ChildGp::class))->getProperties(null);
$namesNull = [];
foreach ($allNull as $p) {
    $namesNull[] = $p->getName();
}
sort($namesNull);
echo implode(',', $namesNull), "\n";

$pub = (new ReflectionClass(ChildGp::class))->getProperties(ReflectionProperty::IS_PUBLIC);
$pubNames = [];
foreach ($pub as $p) {
    $pubNames[] = $p->getName();
}
sort($pubNames);
echo implode(',', $pubNames), "\n";
