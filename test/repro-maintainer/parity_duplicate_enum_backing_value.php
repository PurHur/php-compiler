<?php

/**
 * Repro for #8687 / #8876 — duplicate backed enum values Error at first use (zend_enum.c).
 */

enum E: int {
    case A = 1;
    case B = 1;
}

try {
    echo E::A->name, "\n";
} catch (Throwable $e) {
    echo get_class($e), ': ', $e->getMessage(), "\n";
}
