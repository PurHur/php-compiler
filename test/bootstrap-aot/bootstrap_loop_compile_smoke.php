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

/**
 * @return array{ok: bool, message: string, phase: string, emit_path: string}
 */
function bootstrap_loop_compile_smoke(string $sourceFile, string $outFile): array
{
    $result = helloworld_compile_smoke($sourceFile, $outFile);
    $emitPath = $result['ok'] ? 'native' : 'native_blocked';

    if ($result['ok']) {
        return [
            'ok' => true,
            'message' => 'bootstrap_loop_compile_smoke: gen-2 compile OK -> '.$outFile,
            'phase' => $result['phase'],
            'emit_path' => $emitPath,
        ];
    }

    return [
        'ok' => false,
        'message' => 'bootstrap_loop_compile_smoke: '.$result['message'],
        'phase' => $result['phase'],
        'emit_path' => $emitPath,
    ];
}
