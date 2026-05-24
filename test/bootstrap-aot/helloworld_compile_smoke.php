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
 * Compile-driver link with real lowering OK (#1402, #1496); native runtime emit still blocked
 * on deny-list ctor helpers (see docs/bootstrap-m5-fast-path.md).
 */

require_once __DIR__.'/runtime_ctor_smoke.php';

/**
 * @return array{ok: bool, message: string, phase: string}
 */
function helloworld_compile_smoke(string $sourceFile, string $outFile): array
{
    if (!is_file($sourceFile)) {
        return [
            'ok' => false,
            'message' => 'helloworld_compile_smoke: missing source '.$sourceFile,
            'phase' => 'source',
        ];
    }

    $resolved = realpath($sourceFile);
    if (false === $resolved) {
        return [
            'ok' => false,
            'message' => 'helloworld_compile_smoke: realpath failed '.$sourceFile,
            'phase' => 'source',
        ];
    }

    $code = file_get_contents($resolved);
    if (!is_string($code) || '' === $code) {
        return [
            'ok' => false,
            'message' => 'helloworld_compile_smoke: empty source '.$resolved,
            'phase' => 'source',
        ];
    }

    $ctor = runtime_ctor_smoke();
    if (!$ctor['ok']) {
        return $ctor;
    }

    $runtime = new \PHPCompiler\Runtime(\PHPCompiler\Runtime::MODE_AOT);
    $block = $runtime->parseAndCompile($code, $resolved);
    if (null === $block) {
        return [
            'ok' => false,
            'message' => 'helloworld_compile_smoke: parseAndCompile returned null (CFG/compile spine)',
            'phase' => 'parseAndCompile',
        ];
    }

    $runtime->standalone($block, $outFile);

    return [
        'ok' => true,
        'message' => 'helloworld_compile_smoke: compile OK -> '.$outFile,
        'phase' => 'standalone',
    ];
}
