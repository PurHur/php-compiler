<?php

declare(strict_types=1);

namespace PHPCompiler\BootstrapAot;

/**
 * M3 compile-smoke native emit (issues #1056, #1937, #1977).
 *
 * Smaller than helloworld_compile_smoke for compile-driver link: Runtime parseAndCompile + standalone
 * only (int exit + echo fixture — no assoc arrays, #1514).
 *
 * Emit-helper link uses thin native LLVM bridge (BootstrapCompileSmokeM3Emit) instead of CFG lowering (#1983).
 *
 * Lint: php bin/compile.php -l test/bootstrap-aot/compile_smoke_m3_emit.php
 */

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

    if (\function_exists('putenv')) {
        // Native emit TU is linked with self-host stubs; runtime must not re-read those flags (#1937).
        putenv('PHP_COMPILER_SELFHOST_AOT');
        putenv('PHP_COMPILER_M3_COMPILE_DRIVER');
        putenv('PHP_COMPILER_EMIT_HELPER_LINK');
        putenv('PHP_COMPILER_M3_EMIT_MINIMAL=1');
    }
    if (
        \function_exists('putenv')
        && str_ends_with(str_replace('\\', '/', $resolved), '/bin/compile.php')
    ) {
        // Bake compiled CLI mode into native bin/compile.php output (#2697).
        putenv('PHP_COMPILER_CLI_COMPILED=1');
    }

    $runtime = new \PHPCompiler\Runtime(\PHPCompiler\Runtime::MODE_AOT);
    if (!isset($runtime->compiler, $runtime->vmContext)) {
        echo "compile_smoke_m3_emit: MODE_AOT ctor incomplete (compiler/vmContext spine)\n";
        echo "compile_smoke_m3_emit: native emit failed at phase=ctor\n";

        return 1;
    }
    // M3 emit helper links with self-host stubs; keep compile path on the emit-smoke subset (#1937).
    try {
        $script = $runtime->parse($code, $resolved);
    } catch (\Throwable $e) {
        $diag = $runtime->formatParseAndCompileNullDetail(null);
        $extra = null !== $diag && '' !== $diag ? ' — '.$diag : ' — '.$e->getMessage();
        echo 'compile_smoke_m3_emit: parse failed'.$extra."\n";
        echo "compile_smoke_m3_emit: native emit failed at phase=parse\n";

        return 1;
    }
    $block = $runtime->compileEmitSmoke($script);
    if (null === $block) {
        $diag = $runtime->formatParseAndCompileNullDetail($script);
        $fallback = $runtime->compiler->getCompileAbortDetail();
        $detail = null !== $diag && '' !== $diag ? $diag : $fallback;
        $extra = null !== $detail && '' !== $detail ? ' — '.$detail : '';
        echo "compile_smoke_m3_emit: parseAndCompile returned null (CFG/compile spine)".$extra."\n";
        echo "compile_smoke_m3_emit: native emit failed at phase=parseAndCompile\n";

        return 1;
    }

    $runtime->standalone($block, $outFile);

    echo 'compile_smoke_m3_emit: compile OK -> '.$outFile."\n";

    return 0;
}
