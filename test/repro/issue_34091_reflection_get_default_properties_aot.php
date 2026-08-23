<?php
// Repro #34091 — ReflectionClass::getDefaultProperties thin AOT
class A
{
    public $a = 1;
    protected $b = 2;
    private $c = 3;
    public static $sa = 10;
    private static $sc = 30;
}

class B extends A
{
    public $d = 4;
    private $e = 5;
    protected $f = 6;
    public static $sd = 40;
    private static $se = 50;
}

$d = (new ReflectionClass(B::class))->getDefaultProperties();
ksort($d);
foreach ($d as $k => $v) {
    echo $k, '=', var_export($v, true), "\n";
}

class G
{
    public $a = 1;
    public $b;
    public int $c = 2;
    public $d = null;
    public int $e;
}
$g = (new ReflectionClass(G::class))->getDefaultProperties();
ksort($g);
echo 'G:';
foreach ($g as $k => $v) {
    echo ' ', $k, '=', var_export($v, true);
}
echo "\n";
