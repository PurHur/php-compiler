<?php

declare(strict_types=1);

/**
 * Minimal repro for #34001 — ReflectionClass / ReflectionObject $name under thin AOT.
 *
 * @see php-src ext/reflection/php_reflection.c zim_ReflectionClass___construct
 * @see php-src ext/reflection/php_reflection.c zim_ReflectionObject___construct
 */
class A {}
$rc = new ReflectionClass('A');
echo $rc->name, '|', $rc->getName(), PHP_EOL;
$ro = new ReflectionObject(new A);
echo $ro->name, '|', $ro->getName(), PHP_EOL;
