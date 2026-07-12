<?php
class C {
    public function __construct(public int $x, int $y) {}
}
$rp = (new ReflectionMethod(C::class, '__construct'))->getParameters()[0];
echo $rp->isPromoted() ? "promoted\n" : "not\n";
$rp2 = (new ReflectionMethod(C::class, '__construct'))->getParameters()[1];
echo $rp2->isPromoted() ? "promoted\n" : "not\n";

function f(int $a) {}
$fp = (new ReflectionFunction('f'))->getParameters()[0];
echo $fp->isPromoted() ? "promoted\n" : "not\n";
