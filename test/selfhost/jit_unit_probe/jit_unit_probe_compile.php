<?php

declare(strict_types=1);

/**
 * Standalone AOT fixture for JIT unit probe native emit (issue #2778).
 * Trivial echo — sidecar-cached at emit-helper link like compiler_unit_probe_compile.php.
 */

function jit_unit_probe_greeting()
{
    return 'jit unit probe compile OK';
}

echo jit_unit_probe_greeting(), "\n";
