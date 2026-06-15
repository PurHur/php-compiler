<?php

/**
 * Repro for #8687 — duplicate backed enum values must compile-time fatal (zend_enum.c).
 */

enum E: int {
    case A = 1;
    case B = 1;
}

echo "compiled\n";
