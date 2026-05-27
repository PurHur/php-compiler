<?php

declare(strict_types=1);

/**
 * Zend CFG smoke for compiler unit probe (#2216, #2618).
 * Native emit uses compile_smoke_m3_emit via compiler_unit_probe_m3_emit_native_entry.php.
 */

function compiler_unit_probe_compile_smoke()
{
    return 'compiler unit probe';
}
