<?php

declare(strict_types=1);

namespace PHPCompiler\BootstrapAot;

/**
 * M3 Runtime native emit — parseAndCompile + standalone (#2294).
 *
 * Lint: php bin/compile.php -l test/bootstrap-aot/runtime_compile_smoke_m3_emit.php
 */

function runtime_compile_smoke_m3_emit(string $sourceFile, string $outFile): int
{
    if (!is_file($sourceFile)) {
        echo 'runtime_compile_smoke_m3_emit: missing source '.$sourceFile."\n";
        echo "runtime_compile_smoke_m3_emit: native emit failed at phase=source\n";

        return 1;
    }

    $resolved = realpath($sourceFile);
    if (false === $resolved) {
        echo 'runtime_compile_smoke_m3_emit: realpath failed '.$sourceFile."\n";
        echo "runtime_compile_smoke_m3_emit: native emit failed at phase=source\n";

        return 1;
    }

    $code = file_get_contents($resolved);
    if (!is_string($code) || '' === $code) {
        echo 'runtime_compile_smoke_m3_emit: empty source '.$resolved."\n";
        echo "runtime_compile_smoke_m3_emit: native emit failed at phase=source\n";

        return 1;
    }

    if (\function_exists('putenv')) {
        putenv('PHP_COMPILER_SELFHOST_AOT');
        putenv('PHP_COMPILER_M3_COMPILE_DRIVER');
        putenv('PHP_COMPILER_EMIT_HELPER_LINK');
        putenv('PHP_COMPILER_M3_EMIT_MINIMAL=1');
    }

    $runtime = new \PHPCompiler\Runtime(\PHPCompiler\Runtime::MODE_AOT);
    if (!isset($runtime->compiler, $runtime->vmContext)) {
        echo "runtime_compile_smoke_m3_emit: MODE_AOT ctor incomplete (compiler/vmContext spine)\n";
        echo "runtime_compile_smoke_m3_emit: native emit failed at phase=ctor\n";

        return 1;
    }

    $block = $runtime->parseAndCompileEmitSmoke($code, $resolved);
    if (null === $block) {
        echo "runtime_compile_smoke_m3_emit: parseAndCompileEmitSmoke returned null (parser/CFG spine)\n";
        echo "runtime_compile_smoke_m3_emit: native emit failed at phase=parseAndCompile\n";

        return 1;
    }

    require_once __DIR__.'/compile_smoke_m3_emit.php';
    bootstrap_m3_emit_ensure_phpc_run_command();
    $runtime->standalone($block, $outFile);

    echo 'runtime_compile_smoke_m3_emit: compile OK -> '.$outFile."\n";

    return 0;
}
