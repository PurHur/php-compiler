<?php

declare(strict_types=1);

/**
 * Repro for #15646 — var_dump(property_exists(), isset()) on uninitialized typed property.
 */

class C
{
    public int $x;
}

$o = new C();
var_dump(property_exists($o, 'x'), isset($o->x));
