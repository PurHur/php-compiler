<?php

declare(strict_types=1);

/**
 * Maintainer gap repro: top-level typed const on Zend 8.2 reference profile (#16651).
 *
 * Zend 8.2: parse error — unexpected identifier "X", expecting "="
 * php-compiler (reference profile): must match Zend (exit 255), not execute body.
 */
const int X = 7;

echo X, "\n";
