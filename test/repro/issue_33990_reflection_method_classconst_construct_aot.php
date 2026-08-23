<?php
// Repro #33990 — thin AOT ReflectionMethod / ReflectionClassConstant construct + property attrs.
class Attr {}
class B {
    #[Attr]
    public $x;
    public const X = 1;
    public function m() {}
}

$rm = new ReflectionMethod(B::class, 'm');
echo $rm->class, '|', $rm->name, PHP_EOL;
echo $rm->getName(), PHP_EOL;

$rcc = new ReflectionClassConstant(B::class, 'X');
echo $rcc->class, '|', $rcc->name, PHP_EOL;

$rp = new ReflectionProperty(B::class, 'x');
echo count($rp->getAttributes()), PHP_EOL;
