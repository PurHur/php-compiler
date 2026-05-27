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
    if ('' !== $normalized && str_contains($normalized, 'bootstrap-aot/')) {
        // M3 native emit TU: self-host M3 allowlist (not full bootstrap JIT) (#1937, #1983).
        $m3EmitEntry = str_contains($normalized, 'compile_smoke_m3_emit_native_entry.php')
            || str_contains($normalized, 'compiler_unit_probe_m3_emit_native_entry.php')
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
            if (str_contains($normalized, 'runtime_m3_emit_native_entry.php')) {
                putenv('PHP_COMPILER_M3_EMIT_LOG_PREFIX=runtime_compile_smoke_m3_emit');
                putenv('PHP_COMPILER_M3_EMIT_HELPER_SPINE=1');
            } elseif (str_contains($normalized, 'helloworld_compile_m3_emit_native_entry.php')) {
                putenv('PHP_COMPILER_M3_EMIT_LOG_PREFIX=helloworld_compile_smoke');
            } elseif (str_contains($normalized, 'helloworld_m3_emit_native_entry.php')) {
                // HelloWorld strict probe emit TU (#2610); M5 bin/compile.php uses helloworld_compile_m3_emit_native_entry (#2681).
                putenv('PHP_COMPILER_M3_EMIT_LOG_PREFIX=compile_smoke_m3_emit');
            } else {
                putenv('PHP_COMPILER_M3_EMIT_LOG_PREFIX=compile_smoke_m3_emit');
            }
        } else {
            // Bootstrap AOT fixtures require real JIT lowering; ignore inherited self-host stub env (#1086).
            putenv('PHP_COMPILER_SELFHOST_AOT=0');
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
        if (str_contains($normalized, 'compiler_helloworld_smoke/compile_driver.php')) {
            putenv('PHP_COMPILER_M3_EMIT_LOG_PREFIX=helloworld_compile_smoke');
        }
    }
    if ('' !== $normalized && str_contains($normalized, 'jit_result_stub.php')) {
        putenv('PHP_COMPILER_SELFHOST_AOT=1');
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
        $runtime->standalone($block, $options['-o']);
    }
}

// Use literal require paths so self-host AOT/JIT can fold includes (#54, #1492).
chdir(__DIR__.'/..');
require_once 'src/cli.php';
require_once 'src/cli_driver.php';
