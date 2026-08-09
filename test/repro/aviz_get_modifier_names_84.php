<?php
declare(strict_types=1);

// #29188 — PROFILE=8.4 omits private(set)/protected(set) from getModifierNames (Zend 8.4).
class C
{
    public private(set) string $x = 'a';
    public protected(set) int $y = 1;
}

foreach (['x', 'y'] as $n) {
    $rp = new ReflectionProperty(C::class, $n);
    echo $n, ':', implode(',', Reflection::getModifierNames($rp->getModifiers())),
         '|raw=', $rp->getModifiers(),
         '|privSet=', $rp->isPrivateSet() ? '1' : '0',
         '|protSet=', $rp->isProtectedSet() ? '1' : '0',
         "\n";
}
