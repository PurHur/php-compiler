<?php

declare(strict_types=1);

/**
 * Repro for #5773 — duplicate backed enum values compile; Error at first use (zend_enum.c).
 */

enum E: int
{
    case A = 1;
    case B = 1;
}

echo "before\n";
try {
    echo E::A->name, "\n";
} catch (Error $e) {
    echo $e->getMessage(), "\n";
}
echo "after\n";
