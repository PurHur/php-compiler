<?php

declare(strict_types=1);

namespace PHPCompiler\BootstrapAot;

/**
 * M3 compile-smoke native emit (issues #1056, #1937, #1977).
 *
 * Smaller than helloworld_compile_smoke for compile-driver link: Runtime parseAndCompile + standalone
 * only (int exit + echo fixture — no assoc arrays, #1514).
 *
 * Lint: php bin/compile.php -l test/bootstrap-aot/compile_smoke_m3_emit.php
 */

require_once __DIR__.'/runtime_ctor_smoke.php';

function compile_smoke_m3_emit(string $sourceFile, string $outFile): int
{
    if (!is_file($sourceFile)) {
        echo 'compile_smoke_m3_emit: missing source '.$sourceFile."\n";
        echo "compile_smoke_m3_emit: native emit failed at phase=source\n";

        return 1;
    }

    $resolved = realpath($sourceFile);
    if (false === $resolved) {
        echo 'compile_smoke_m3_emit: realpath failed '.$sourceFile."\n";
        echo "compile_smoke_m3_emit: native emit failed at phase=source\n";

        return 1;
    }

    $code = file_get_contents($resolved);
    if (!is_string($code) || '' === $code) {
        echo 'compile_smoke_m3_emit: empty source '.$resolved."\n";
        echo "compile_smoke_m3_emit: native emit failed at phase=source\n";

        return 1;
    }

    if (0 !== runtime_ctor_smoke()) {
        return 1;
    }

    $runtime = new \PHPCompiler\Runtime(\PHPCompiler\Runtime::MODE_AOT);
    $block = $runtime->parseAndCompile($code, $resolved);
    if (null === $block) {
        echo "compile_smoke_m3_emit: parseAndCompile returned null (CFG/compile spine)\n";
        echo "compile_smoke_m3_emit: native emit failed at phase=parseAndCompile\n";

        return 1;
    }

    $runtime->standalone($block, $outFile);

    echo 'compile_smoke_m3_emit: compile OK -> '.$outFile."\n";

    return 0;
}
