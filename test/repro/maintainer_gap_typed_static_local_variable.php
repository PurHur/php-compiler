<?php

declare(strict_types=1);

/**
 * Maintainer gap repro: typed static local on Zend 8.2 reference profile (#16512).
 *
 * Zend 8.2: parse error — unexpected identifier "int", expecting "::"
 * php-compiler (reference profile): must match Zend (exit 255), not execute body.
 */
function f(): int
{
    static int $x = 0;

    return ++$x;
}

echo f(), "\n";
