<?php

declare(strict_types=1);

/**
 * Single named function for bundled Compiler CFG smoke (self-host compile probe).
 */

function compiler_smoke_greeting()
{
    return 'compiler smoke';
}

// Standalone bootstrap-aot ladder runs top-level echo; bundled self-host entry skips
// (typed string return in large bundle still crashes at runtime — issue #816 follow-up).
if (!class_exists(\PHPCompiler\Compiler::class, false)) {
    echo compiler_smoke_greeting(), "\n";
}
