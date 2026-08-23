<?php

declare(strict_types=1);

/**
 * Minimal repro for #33990 — ReflectionProperty::getAttributes() under thin AOT.
 *
 * @see php-src ext/reflection/php_reflection.c zim_ReflectionProperty_getAttributes
 */
#[Attribute]
class A
{
}

class B
{
    #[A]
    public int $x = 1;
}

$attrs = (new ReflectionProperty(B::class, 'x'))->getAttributes();
echo count($attrs), '|', $attrs[0]->getName(), PHP_EOL;
