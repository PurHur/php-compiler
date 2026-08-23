<?php

declare(strict_types=1);

/**
 * Minimal repro for #33990 — ReflectionMethod::$class/$name under thin AOT.
 *
 * @see php-src ext/reflection/php_reflection.c zim_ReflectionMethod___construct
 */
class B
{
    public function m(): void
    {
    }
}

$r = new ReflectionMethod(B::class, 'm');
echo $r->class, '|', $r->name, PHP_EOL;
