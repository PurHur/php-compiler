<?php
// Repro #34118 — ReflectionClass::getStaticProperties thin AOT
class BaseGsp
{
    private static $p = 1;
    protected static $r = 3;
    public static $s = 4;
    public static $ss = 40;
    public $inst = 9;
}

class ChildGsp extends BaseGsp
{
    public static $q = 2;
    private static $t = 5;
    public static $u;
    public $qi = 2;
}

class SimpleGsp
{
    public static $a = 1;
    public static $b = 'x';
    public $c = 3;
}

$d = (new ReflectionClass('ChildGsp'))->getStaticProperties();
ksort($d);
echo json_encode($d), "\n";

$simple = (new ReflectionClass('SimpleGsp'))->getStaticProperties();
ksort($simple);
echo json_encode($simple), "\n";
