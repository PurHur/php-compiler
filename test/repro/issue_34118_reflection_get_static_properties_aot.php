<?php
// Repro #34118 — ReflectionClass::getStaticProperties thin AOT
class BaseGsp
{
    private static $p = 1;
    protected static $r = 3;
    public static $s = 4;
}

class ChildGsp extends BaseGsp
{
    public static $q = 2;
    private static $t = 5;
}

$d = (new ReflectionClass(ChildGsp::class))->getStaticProperties();
ksort($d);
echo json_encode($d), "\n";

$simple = (new ReflectionClass(BaseGsp::class))->getStaticProperties();
ksort($simple);
echo json_encode($simple), "\n";
