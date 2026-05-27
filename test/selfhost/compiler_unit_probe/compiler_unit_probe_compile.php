<?php

declare(strict_types=1);

/**
 * Standalone AOT fixture for compiler unit probe native emit (issue #2618).
 * Exercises Runtime::parseAndCompileEmitSmoke / lib/Compiler.php via M3 emit helper.
 */

function compiler_unit_probe_greeting()
{
    return 'compiler unit probe compile OK';
}

echo compiler_unit_probe_greeting(), "\n";
