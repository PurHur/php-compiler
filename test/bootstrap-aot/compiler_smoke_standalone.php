<?php

declare(strict_types=1);

/**
 * Standalone AOT echo fixture for compiler_compile_smoke native run gate (wave 7A).
 * Always prints greeting stdout (unlike bundled compiler_smoke.php which skips echo when Compiler is loaded).
 */

function compiler_smoke_greeting()
{
    return 'compiler smoke';
}

echo compiler_smoke_greeting(), "\n";
