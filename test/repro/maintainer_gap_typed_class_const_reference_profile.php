<?php

declare(strict_types=1);

/**
 * Repro for #15099 — typed class constants must be rejected on Zend 8.2 reference profile.
 *
 * Zend 8.2: PHP Parse error: unexpected identifier "NAME", expecting "="
 * VM (before fix): compiles and prints x
 */
class C
{
    public const string NAME = 'x';
}

echo C::NAME, "\n";
