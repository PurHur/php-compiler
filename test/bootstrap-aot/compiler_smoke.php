<?php

declare(strict_types=1);

/**
 * Single named function for bundled Compiler CFG smoke (self-host compile probe).
 */

function compiler_smoke_greeting(): string
{
    return 'compiler smoke';
}

echo compiler_smoke_greeting(), "\n";
