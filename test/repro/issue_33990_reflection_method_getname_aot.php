<?php
// Follow-up #33990 — thin AOT getName() still silent-null after construct (#33994).
class B {
    public const X = 1;
    public function m() {}
}

$rm = new ReflectionMethod(B::class, 'm');
echo $rm->class, '|', $rm->name, '|', $rm->getName(), PHP_EOL;

$rcc = new ReflectionClassConstant(B::class, 'X');
echo $rcc->class, '|', $rcc->name, '|', $rcc->getName(), PHP_EOL;
