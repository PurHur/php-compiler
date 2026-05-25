<?php

declare(strict_types=1);

namespace PHPCompiler\BootstrapAot;

/**
 * M3 HelloWorld native emit chain (issue #1056, #1402):
 *
 *   runtime_ctor_smoke()  →  Runtime::__construct (MODE_AOT, loadCoreModules)
 *   parseAndCompile()    →  parse → compile → Block (#1496: parse/compile real-lowered on link spine)
 *   standalone()         →  loadJitContext → compileToFile
 *
 * Compile-driver link with real lowering OK (#1402, #1496); native runtime emit blocked
 * on deny-list ctor helpers (see docs/bootstrap-m5-fast-path.md).
 *
 * Int exit codes + echo (no assoc arrays) for real-lowered hashtable safety (#1514).
 */

require_once __DIR__.'/runtime_ctor_smoke.php';

function helloworld_compile_smoke(string $sourceFile, string $outFile): int
{
    if (!is_file($sourceFile)) {
        echo 'helloworld_compile_smoke: missing source '.$sourceFile."\n";
        echo "helloworld_compile_smoke: native emit failed at phase=source\n";

        return 1;
    }

    $resolved = realpath($sourceFile);
    if (false === $resolved) {
        echo 'helloworld_compile_smoke: realpath failed '.$sourceFile."\n";
        echo "helloworld_compile_smoke: native emit failed at phase=source\n";

        return 1;
    }

    $code = file_get_contents($resolved);
    if (!is_string($code) || '' === $code) {
        echo 'helloworld_compile_smoke: empty source '.$resolved."\n";
        echo "helloworld_compile_smoke: native emit failed at phase=source\n";

        return 1;
    }

    if (0 !== runtime_ctor_smoke()) {
        return 1;
    }

    $runtime = new \PHPCompiler\Runtime(\PHPCompiler\Runtime::MODE_AOT);
    $block = $runtime->parseAndCompile($code, $resolved);
    if (null === $block) {
        echo "helloworld_compile_smoke: parseAndCompile returned null (CFG/compile spine)\n";
        echo "helloworld_compile_smoke: native emit failed at phase=parseAndCompile\n";

        return 1;
    }

    $runtime->standalone($block, $outFile);

    echo 'helloworld_compile_smoke: compile OK -> '.$outFile."\n";

    return 0;
}
