<?php

declare(strict_types=1);

/**
 * Repro for #5710 — duplicate backed enum values must not compile (zend_enum.c).
 *
 * Zend: Fatal error: Duplicate value in enum E for cases A and B
 * VM:   CompileError at parseAndCompile time (exit 255 via bin/vm.php)
 */

enum E: int
{
    case A = 1;
    case B = 1;
}

var_export(E::A === E::B);
