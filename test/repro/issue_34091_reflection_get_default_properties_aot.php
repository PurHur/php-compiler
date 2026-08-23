<?php
// Repro #34091 — ReflectionClass::getDefaultProperties thin AOT
class BaseGdp
{
    private $p = 1;
    protected $r = 3;
    public $s = 4;
    public static $ss = 40;
}

class ChildGdp extends BaseGdp
{
    public $q = 2;
    private $t = 5;
    public static $sq = 20;
    private static $st = 50;
    public $u;
}

class SimpleGdp
{
    public $a = 1;
    public $b = 'x';
}

$d = (new ReflectionClass('ChildGdp'))->getDefaultProperties();
ksort($d);
echo json_encode($d), "\n";

$simple = (new ReflectionClass('SimpleGdp'))->getDefaultProperties();
ksort($simple);
echo json_encode($simple), "\n";
