<?php

declare(strict_types=1);

/**
 * Minimal AOT fixture for compiler unit probe M3 native emit (#2618).
 * Mirrors compiler_smoke_standalone shape (emit-smoke subset).
 */

function compiler_unit_probe_greeting()
{
    return 'compiler unit probe';
}

echo compiler_unit_probe_greeting(), "\n";
