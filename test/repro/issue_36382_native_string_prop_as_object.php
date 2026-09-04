<?php

declare(strict_types=1);

/**
 * #36382 — Native object-param compileArg must not __value__readObject a __string__*
 * prop load (module verify: Call parameter type does not match). php-src: Zend/zend_API.c
 */
class Box
{
    public string $name = 'x';
}

function takesObj(?object $o): void
{
    echo null === $o ? 'null' : 'obj';
    echo "\n";
}

$b = new Box();
takesObj($b->name);
