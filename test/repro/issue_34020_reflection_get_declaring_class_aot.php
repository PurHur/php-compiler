<?php

declare(strict_types=1);

/**
 * Minimal repro for #34020 — getDeclaringClass()->getName() under thin AOT.
 *
 * @see php-src ext/reflection/php_reflection.c zim_ReflectionMethod_getDeclaringClass
 * @see php-src ext/reflection/php_reflection.c zim_ReflectionProperty_getDeclaringClass
 */
class U
{
    public int $x = 1;

    public function m(): void
    {
    }
}

$rm = new ReflectionMethod('U', 'm');
echo $rm->getDeclaringClass()->getName(), PHP_EOL;
echo $rm->getDeclaringClass()->name, PHP_EOL;

$rp = new ReflectionProperty('U', 'x');
echo $rp->getDeclaringClass()->getName(), PHP_EOL;
echo $rp->getDeclaringClass()->name, PHP_EOL;
