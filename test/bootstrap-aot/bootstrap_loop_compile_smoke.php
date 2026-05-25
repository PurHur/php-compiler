<?php

declare(strict_types=1);

/**
 * M4 gen-1→gen-2 native emit chain (issue #1498).
 *
 * Reuses helloworld_compile_smoke until the bootstrap_loop bundle grows beyond the
 * M3 HelloWorld spine (bin/compile.php / src/cli.php — #1467).
 *
 * Default gen-2 smoke target: test/bootstrap-aot/compiler_smoke_standalone.php
 */

require_once __DIR__.'/helloworld_compile_smoke.php';

function bootstrap_loop_compile_smoke(string $sourceFile, string $outFile): int
{
    if (0 !== helloworld_compile_smoke($sourceFile, $outFile)) {
        echo "bootstrap_loop_compile_smoke: gen-2 compile failed (see helloworld_compile_smoke output above)\n";

        return 1;
    }

    echo 'bootstrap_loop_compile_smoke: gen-2 compile OK -> '.$outFile."\n";

    return 0;
}
