<?php

declare(strict_types=1);

/**
 * Standalone AOT echo fixture for compiler unit probe native emit gate (#2618).
 * Always prints greeting stdout (mirror compiler_smoke_standalone shape for emit-smoke subset).
 */

function compiler_unit_probe_greeting()
{
    return 'compiler unit probe';
}

echo compiler_unit_probe_greeting(), "\n";
