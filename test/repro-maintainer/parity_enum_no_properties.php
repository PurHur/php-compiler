<?php

declare(strict_types=1);

/**
 * Repro for #6005 — enum instance properties must be compile-time fatal.
 */
enum E: string {
    case A = 'a';
    public string $x = 'y';
}

echo E::A->x, "\n";
