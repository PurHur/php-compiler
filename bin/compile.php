<?php

declare(strict_types=1);

/**
 * This file is part of PHP-Compiler, a PHP CFG Compiler for PHP code
 *
 * @copyright 2015 Anthony Ferrara. All rights reserved
 * @license MIT See LICENSE at the root of the project for more info
 */

use PHPCompiler\Runtime;
use PHPCompiler\Web\DeployRoot;
use PHPCompiler\Web\LiteralIncludeDiscovery;
use PHPCompiler\Web\SourceBundler;
use PHPCompiler\Web\Superglobals;

function run(string $filename, string $code, array $options): void
{
    $normalized = '-' !== $filename ? str_replace('\\', '/', $filename) : '';
    if (\class_exists(\PHPCompiler\JIT\Progress::class, false)) {
        \PHPCompiler\JIT\Progress::notePhase('bin_compile_run_begin');
        \PHPCompiler\JIT\Progress::noteEntry($filename);
    }
    // When executing as a compiled native driver, default to self-host mode. Relying on getenv()
    // alone is fragile in early bootstrap contexts, and the native driver is only used for the
    // self-host ladder.
    if (\function_exists('php_compiler_cli_should_skip_entry_driver')) {
        $selfhostAot = getenv('PHP_COMPILER_SELFHOST_AOT');
        if (false === $selfhostAot || '' === $selfhostAot) {
            putenv('PHP_COMPILER_SELFHOST_AOT=1');
        }
        // Compiled argv drivers must enable the M3 compile-driver lowering allowlist; otherwise
        // key Runtime entrypoints can be stubbed and compilation returns null (#3004).
        $m3CompileDriver = getenv('PHP_COMPILER_M3_COMPILE_DRIVER');
        if (false === $m3CompileDriver || '' === $m3CompileDriver) {
            putenv('PHP_COMPILER_M3_COMPILE_DRIVER=1');
        }
        $m3CompileDriverMain = getenv('PHP_COMPILER_M3_COMPILE_DRIVER_MAIN');
        if (false === $m3CompileDriverMain || '' === $m3CompileDriverMain) {
            putenv('PHP_COMPILER_M3_COMPILE_DRIVER_MAIN=1');
        }
        $m4BinCompile = getenv('PHP_COMPILER_M4_BIN_COMPILE_DRIVER');
        if (false === $m4BinCompile || '' === $m4BinCompile) {
            putenv('PHP_COMPILER_M4_BIN_COMPILE_DRIVER=1');
        }
        // Gen-2+ argv driver recompiling bin/compile.php must register bootstrap-aot sidecars (#3004).
        $m5DriverHost = getenv('PHP_COMPILER_M5_DRIVER_HOST');
        if (false === $m5DriverHost || '' === $m5DriverHost) {
            putenv('PHP_COMPILER_M5_DRIVER_HOST=1');
        }
    }
    if ('' !== $normalized && str_contains($normalized, 'bootstrap-aot/')) {
        // M3 native emit TU: self-host M3 allowlist (not full bootstrap JIT) (#1937, #1983).
        $m3EmitEntry = str_contains($normalized, 'compile_smoke_m3_emit_native_entry.php')
            || str_contains($normalized, 'compiler_unit_probe_m3_emit_native_entry.php')
            || str_contains($normalized, 'jit_unit_probe_m3_emit_native_entry.php')
            || str_contains($normalized, 'runtime_m3_emit_native_entry.php')
            || str_contains($normalized, 'helloworld_m3_emit_native_entry.php')
            || str_contains($normalized, 'helloworld_compile_m3_emit_native_entry.php');
        if ($m3EmitEntry) {
            putenv('PHP_COMPILER_SELFHOST_AOT=1');
            putenv('PHP_COMPILER_EMIT_HELPER_LINK=1');
            putenv('PHP_COMPILER_M3_EMIT_TU=1');
            putenv('PHP_COMPILER_M3_EMIT_MINIMAL=1');
            if (str_contains($normalized, 'compiler_unit_probe_m3_emit_native_entry.php')) {
                putenv('PHP_COMPILER_M3_COMPILER_UNIT_PROBE_EMIT=1');
            }
            if (str_contains($normalized, 'jit_unit_probe_m3_emit_native_entry.php')) {
                putenv('PHP_COMPILER_M3_JIT_UNIT_PROBE_EMIT=1');
            }
            if (str_contains($normalized, 'runtime_m3_emit_native_entry.php')) {
                putenv('PHP_COMPILER_M3_EMIT_LOG_PREFIX=runtime_compile_smoke_m3_emit');
                putenv('PHP_COMPILER_M3_EMIT_HELPER_SPINE=1');
            } elseif (str_contains($normalized, 'helloworld_compile_m3_emit_native_entry.php')) {
                putenv('PHP_COMPILER_M3_EMIT_LOG_PREFIX=helloworld_compile_smoke');
                // M5 vendor prelink: native driver must host-lower parse/compileEmitSmoke, not null stubs (#3028).
                putenv('PHP_COMPILER_M3_EMIT_HELPER_SPINE=1');
            } elseif (str_contains($normalized, 'helloworld_m3_emit_native_entry.php')) {
                // HelloWorld strict probe emit TU (#2610); M5 bin/compile.php uses helloworld_compile_m3_emit_native_entry (#2681).
                putenv('PHP_COMPILER_M3_EMIT_LOG_PREFIX=compile_smoke_m3_emit');
            } elseif (
                str_contains($normalized, 'compile_smoke_m3_emit_native_entry.php')
                && (
                    '1' === (string) getenv('PHP_COMPILER_M4_BIN_COMPILE_DRIVER')
                    || '1' === (string) getenv('PHP_COMPILER_M3_INVENTORY_EMIT_DRIVER')
                    || '1' === (string) getenv('BOOTSTRAP_M3_USE_INVENTORY_EMIT_DRIVER')
                )
            ) {
                // M5 argv driver + inventory emit (#2894, #2900): helloworld prefix + compiler_minimal sidecar.
                putenv('PHP_COMPILER_M3_EMIT_LOG_PREFIX=helloworld_compile_smoke');
            } else {
                putenv('PHP_COMPILER_M3_EMIT_LOG_PREFIX=compile_smoke_m3_emit');
            }
        } else {
            // Bootstrap AOT fixtures require real JIT lowering; ignore inherited self-host stub env (#1086).
            //
            // But: if we're already running under a compiled self-host driver, forcing stub-off
            // can crash before we have a chance to fall back (e.g. inventory argv driver compiling
            // bootstrap-aot fixtures as part of self-host compile-smoke, #2967).
            //
            // Use a runtime-native marker instead of getenv(): self-host AOT execution stubs and
            // env access can be unreliable precisely in the scenarios we’re trying to debug.
            if (!\function_exists('php_compiler_cli_should_skip_entry_driver')) {
                putenv('PHP_COMPILER_SELFHOST_AOT=0');
            }
        }
    }
    if ('-' !== $filename && str_contains($normalized, 'test/selfhost/')) {
        $selfhostAot = getenv('PHP_COMPILER_SELFHOST_AOT');
        if (false === $selfhostAot || '' === $selfhostAot) {
            putenv('PHP_COMPILER_SELFHOST_AOT=1');
        }
    }
    if ('' !== $normalized && str_contains($normalized, 'selfhost/') && str_contains($normalized, 'compile_driver.php') && !isset($options['-l'])) {
        putenv('PHP_COMPILER_SELFHOST_AOT=1');
        putenv('PHP_COMPILER_M3_COMPILE_DRIVER=1');
        putenv('PHP_COMPILER_M3_COMPILE_DRIVER_MAIN=1');
        if (str_contains($normalized, 'compiler_helloworld_smoke/compile_driver.php')
            || str_contains($normalized, 'bootstrap_loop_smoke/compile_driver.php')) {
            putenv('PHP_COMPILER_M3_EMIT_LOG_PREFIX=helloworld_compile_smoke');
        } elseif (str_contains($normalized, 'runtime_compile_smoke/compile_driver.php')) {
            putenv('PHP_COMPILER_M3_EMIT_LOG_PREFIX=runtime_compile_smoke_m3_emit');
            putenv('PHP_COMPILER_M3_EMIT_HELPER_SPINE=1');
        } elseif (str_contains($normalized, 'compile_driver.php')) {
            putenv('PHP_COMPILER_M3_EMIT_LOG_PREFIX=compile_smoke_m3_emit');
        }
        $inventoryEmit = getenv('BOOTSTRAP_M3_USE_INVENTORY_EMIT_DRIVER') ?: getenv('PHP_COMPILER_M3_INVENTORY_EMIT_DRIVER');
        if ('1' === $inventoryEmit || 'true' === strtolower((string) $inventoryEmit)) {
            putenv('PHP_COMPILER_M3_INVENTORY_EMIT_DRIVER=1');
            // Runtime: do not force emit-helper TU/minimal mode here.
            // The inventory driver is expected to compile arbitrary sources; forcing emit-helper
            // mode can route Runtime::parseAndCompile through minimal sentinel stubs and recurse (#2967).
        }
    }
    if ('' !== $normalized && str_contains($normalized, 'jit_result_stub.php')) {
        putenv('PHP_COMPILER_SELFHOST_AOT=1');
    }
    if ('' !== $normalized && str_contains($normalized, 'bootstrap-vendor-prelink/generated/')) {
        $vendorPrelink = getenv('PHP_COMPILER_VENDOR_PRELINK');
        if ('1' === $vendorPrelink || 'true' === strtolower((string) $vendorPrelink)) {
            // Real-lower parse/compile for vendor bundles; avoid M3 emit-TU sidecar host-compile (#3028, #3036).
            putenv('PHP_COMPILER_M3_EMIT_HELPER_SPINE=1');
            putenv('PHP_COMPILER_KEEP_OBJECT_FILE=1');
        }
    }
    $includes = $options['--include'] ?? [];
    if (!is_array($includes)) {
        $includes = [] === $includes || '' === $includes ? [] : [$includes];
    }
    /** @var list<string> $includes */
    if ([] === $includes && '-' !== $filename && is_file($filename)) {
        $runtime = new Runtime(Runtime::MODE_AOT);
        $includes = LiteralIncludeDiscovery::discoverDirectAbsolutePaths($runtime, $filename);
    }
    if ([] !== $includes) {
        $projectRoot = DeployRoot::findProjectRootForPath($filename);
        [$code, $filename] = SourceBundler::bundleForAot($filename, $includes, $projectRoot);
    }

    $runtime = new Runtime(Runtime::MODE_AOT);
    $queryString = $options['-q'] ?? null;
    if (!is_string($queryString) || '' === $queryString) {
        $fromEnv = getenv('QUERY_STRING');
        if (is_string($fromEnv) && '' !== $fromEnv) {
            $queryString = $fromEnv;
        }
    }
    $postBody = $options['-p'] ?? null;
    if (!is_string($postBody) || '' === $postBody) {
        $bodyEnv = getenv('REQUEST_BODY');
        if (is_string($bodyEnv) && '' !== $bodyEnv) {
            $postBody = $bodyEnv;
        }
    }
    $scriptFilename = null;
    if ('-' !== $filename && 'Command line code' !== $filename) {
        $resolved = realpath($filename);
        if (false !== $resolved) {
            $scriptFilename = $resolved;
        }
    }
    Superglobals::populateFromEnvironment(
        $runtime->vmContext,
        is_string($queryString) ? $queryString : null,
        is_string($postBody) ? $postBody : null,
        $scriptFilename
    );
    $block = $runtime->parseAndCompile($code, $filename);
    if (null === $block) {
        if (! isset($options['-l'])) {
            $diag = $runtime->compiler->getCompileAbortDetail();
            $suffix = null !== $diag && '' !== $diag ? ' — '.$diag : '';
            fwrite(STDERR, 'compile.php: parseAndCompile returned null for '.$filename.$suffix."\n");
            exit(2);
        }
        fwrite(STDERR, "phpc lint: failed to compile {$filename}\n");
        exit(1);
    }
    if (! isset($options['-l'])) {
        if (! isset($options['-o']) || $options['-o'] === true) {
            $options['-o'] = str_replace('.php', '', $filename);
        }
        if (isset($options['-y'])) {
            $debugFile = true === $options['-y'] ? $options['-o'] : $options['-y'];
            $runtime->setDebug($debugFile);
        }
        // Literal path for self-host AOT/JIT include folding (#54, #1492).
        require_once 'lib/AOT/LinkerProcessPolyfill.php';
        if (!\function_exists('phpc_run_command')) {
            /**
             * @param array<string, string>|null $env
             *
             * @return array{code:int,stdout:string,stderr:string}|null
             */
            function phpc_run_command(string $command, ?array $env = null): ?array
            {
                return \PHPCompiler\AOT\LinkerProcessPolyfill::run($command, $env);
            }
        }
        $prevSelfHostAot = getenv('PHP_COMPILER_SELFHOST_AOT');
        $setSelfHostAotForCompile = \function_exists('putenv') && (false === $prevSelfHostAot || '' === (string) $prevSelfHostAot);
        if ($setSelfHostAotForCompile) {
            // Keep LLVM 9 stable during AOT compilation; some lowering paths are still sensitive (#2600).
            putenv('PHP_COMPILER_SELFHOST_AOT=1');
            $_ENV['PHP_COMPILER_SELFHOST_AOT'] = '1';
            $_SERVER['PHP_COMPILER_SELFHOST_AOT'] = '1';
        }
        try {
            $runtime->standalone($block, $options['-o'], $code, $filename);
        } catch (\LogicException $e) {
            fwrite(STDERR, $e->getMessage()."\n");
            exit(2);
        } finally {
            if ($setSelfHostAotForCompile) {
                putenv('PHP_COMPILER_SELFHOST_AOT=');
                unset($_ENV['PHP_COMPILER_SELFHOST_AOT'], $_SERVER['PHP_COMPILER_SELFHOST_AOT']);
            }
        }
    }
}

if (
    !(defined('PHP_COMPILER_LIB_SPINE_SMOKE') && PHP_COMPILER_LIB_SPINE_SMOKE)
    && !(\function_exists('php_compiler_cli_should_skip_entry_driver') && php_compiler_cli_should_skip_entry_driver())
) {
// Use literal require paths so self-host AOT/JIT can fold includes (#54, #1492).
chdir(__DIR__.'/..');
require_once 'src/cli.php';
require_once 'src/cli_driver.php';
php_compiler_cli_dispatch();
}
