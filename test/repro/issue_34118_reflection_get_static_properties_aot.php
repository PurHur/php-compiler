<?php
// Repro #34118 — ReflectionClass::getStaticProperties thin AOT
class BaseGsp
{
    public static $a = 1;
    private static $b = 2;
    protected static $d = 4;
}

class ChildGsp extends BaseGsp
{
    public static $c = 3;
    private static $e = 5;
}

$r = new ReflectionClass(ChildGsp::class);
$p = $r->getStaticProperties();
ksort($p);
echo json_encode($p), '|';
ChildGsp::$c = 99;
$p2 = $r->getStaticProperties();
ksort($p2);
echo json_encode($p2), "\n";
