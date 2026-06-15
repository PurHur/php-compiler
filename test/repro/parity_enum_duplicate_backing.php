<?php

declare(strict_types=1);

/**
 * Repro for #5773 / #8687 — duplicate backed enum values compile-time fatal (zend_enum.c).
 */

enum E: int
{
    case A = 1;
    case B = 1;
}

echo "compiled\n";
