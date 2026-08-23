<?php

declare(strict_types=1);

/**
 * Minimal repro for #34001 — ReflectionObject::$name under thin AOT.
 *
 * @see php-src ext/reflection/php_reflection.c zim_ReflectionObject___construct
 */
class A
{
}

$r = new ReflectionObject(new A());
echo $r->name, PHP_EOL;
echo $r->getName(), PHP_EOL;
