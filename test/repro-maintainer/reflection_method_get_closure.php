<?php

class C {
    public function mul(int $x): int
    {
        return $x * 2;
    }

    public static function add(int $a, int $b): int
    {
        return $a + $b;
    }
}

$rm = new ReflectionMethod(C::class, 'mul');
echo 'instance method_exists=', var_export(method_exists($rm, 'getClosure'), true), "\n";
$c = $rm->getClosure(new C());
echo 'bound=', $c(21), "\n";

$rms = new ReflectionMethod(C::class, 'add');
echo 'static method_exists=', var_export(method_exists($rms, 'getClosure'), true), "\n";
$cs = $rms->getClosure(null);
echo 'static bound=', $cs(10, 11), "\n";
