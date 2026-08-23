<?php

declare(strict_types=1);

/**
 * Minimal repro for #33990 — ReflectionClassConstant::$class/$name under thin AOT.
 *
 * @see php-src ext/reflection/php_reflection.c zim_ReflectionClassConstant___construct
 */
class B
{
    public const X = 1;
}

$r = new ReflectionClassConstant(B::class, 'X');
echo $r->class, '|', $r->name, PHP_EOL;
