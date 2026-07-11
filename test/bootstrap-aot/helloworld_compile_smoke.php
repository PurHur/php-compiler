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
require_once __DIR__.'/../../lib/AOT/phpc_run_command_polyfill.php';

function helloworld_compile_smoke(string $sourceFile, string $outFile): int
{
    // This probe is only used on the self-host path. Ensure the compile-driver lowering
    // allowlist is enabled even when invoked from a compiled argv driver where env
    // propagation can be incomplete (#3004).
    if (\function_exists('putenv')) {
        $vendorPrelink = getenv('PHP_COMPILER_VENDOR_PRELINK');
        if ('1' === $vendorPrelink || 'true' === strtolower((string) $vendorPrelink)) {
            putenv('PHP_COMPILER_SELFHOST_AOT=0');
            putenv('PHP_COMPILER_KEEP_OBJECT_FILE=1');
            putenv('PHP_COMPILER_M3_EMIT_HELPER_SPINE=1');
            putenv('PHP_COMPILER_M3_COMPILE_DRIVER=0');
            putenv('PHP_COMPILER_M3_COMPILE_DRIVER_MAIN=0');
        } else {
            $selfhostAot = getenv('PHP_COMPILER_SELFHOST_AOT');
            if (false === $selfhostAot || '' === $selfhostAot) {
                putenv('PHP_COMPILER_SELFHOST_AOT=1');
            }
            $m3CompileDriver = getenv('PHP_COMPILER_M3_COMPILE_DRIVER');
            if (false === $m3CompileDriver || '' === $m3CompileDriver) {
                putenv('PHP_COMPILER_M3_COMPILE_DRIVER=1');
            }
            $m3CompileDriverMain = getenv('PHP_COMPILER_M3_COMPILE_DRIVER_MAIN');
            if (false === $m3CompileDriverMain || '' === $m3CompileDriverMain) {
                putenv('PHP_COMPILER_M3_COMPILE_DRIVER_MAIN=1');
            }
        }
    }
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
    $compilePath = \PHPCompiler\JIT\M3EmitTuTrivialEchoAot::normalizeSidecarSourcePath($resolved) ?? $resolved;
    try {
        $script = $runtime->parse($code, $compilePath);
    } catch (\Throwable $e) {
        $detail = \PHPCompiler\Runtime::getLastParseFailure();
        if (null === $detail || '' === $detail) {
            $detail = $runtime->formatParseAndCompileNullDetail(null);
        }
        if (null === $detail || '' === $detail) {
            $detail = $e->getMessage();
        }
        echo sprintf("parseAndCompile failure: target=%s: %s\n", $compilePath, $detail);
        $excFile = $e->getFile();
        if ('' !== $excFile && 0 !== $e->getLine()) {
            echo sprintf("%s in %s:%d\n", $e::class, $excFile, $e->getLine());
        }
        $extra = null !== $detail && '' !== $detail ? ' — '.$detail : '';
        echo 'helloworld_compile_smoke: parse failed'.$extra."\n";
        echo "helloworld_compile_smoke: native emit failed at phase=parse\n";

        return 1;
    }
    $block = $runtime->compile($script);
    if (null !== $block) {
        $block->setScriptPath($compilePath);
    }
    if (null === $block) {
        $detail = \PHPCompiler\Runtime::getLastParseFailure();
        if (null === $detail || '' === $detail) {
            $detail = $runtime->formatParseAndCompileNullDetail($script);
        }
        if (null !== $detail && '' !== $detail) {
            echo sprintf("parseAndCompile failure: target=%s: %s\n", $compilePath, $detail);
        }
        $extra = null !== $detail && '' !== $detail ? ' — '.$detail : '';
        echo "helloworld_compile_smoke: parseAndCompile returned null (CFG/compile spine)".$extra."\n";
        echo "helloworld_compile_smoke: native emit failed at phase=parseAndCompile\n";

        return 1;
    }

    $envTruthy = static function (string $name): bool {
        if (!\function_exists('getenv')) {
            return false;
        }
        $v = getenv($name);

        return false !== $v && ('1' === $v || 'true' === strtolower((string) $v));
    };
    $vendorPrelink = $envTruthy('PHP_COMPILER_VENDOR_PRELINK');
    $keepObject = $envTruthy('PHP_COMPILER_KEEP_OBJECT_FILE');
    $selfhostAot = $envTruthy('PHP_COMPILER_SELFHOST_AOT');
    // Vendor .o rebuild (#3036), spine link with prelinked vendor (#3052), or gen-0 argv driver (#3053).
    $vendorPrelinkEmit = $vendorPrelink && ($keepObject || $selfhostAot);
    if ($vendorPrelinkEmit || $selfhostAot) {
        // Inventory driver stubs Runtime::standalone (sidecar copy). Emit via compileToFile (#3036, #3052, #3053).
        $jit = $runtime->loadJit();
        $context = $runtime->loadJitContext();
        $context->setAotSourceFilename($resolved);
        $context->setMain($jit->compile($block));
        $context->compileToFile($outFile);
    } else {
        $runtime->standalone($block, $outFile, $code, $resolved);
    }

    echo 'helloworld_compile_smoke: compile OK -> '.$outFile."\n";

    return 0;
}
