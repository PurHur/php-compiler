#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * Incremental emitter for split-compilation helper TUs (#15889).
 *
 * Discovers every JitVmHelperLink helper unit (*HELPER_PATH /
 * *COMPILED_HELPERS class-constant pairs), and for each unit:
 *
 *   fresh manifest?          -> skip (cached)
 *   fresh failure marker?    -> skip (known-broken; re-attempted only when
 *                               the unit source or the compiler core changes)
 *   otherwise                -> lower in an ISOLATED subprocess into
 *                               build/helper-runtime-cache/units/<slug>/
 *                               {unit.bc, unit.o, manifest.json} or failed.json
 *
 * So a crash resumes at the breaking unit, and a helper edit re-emits ONE
 * unit. --force re-attempts everything (including failure markers).
 *
 * Usage (pinned env, LLVM 9 required):
 *   ./script/docker-exec.sh -- bash -lc 'php script/emit-helper-runtime-object.php'
 */

use PHPCompiler\AOT\HelperRuntimeCache;
use PHPCompiler\JIT\JitVmHelperLink;
use PHPCompiler\Runtime;

$root = dirname(__DIR__);
require $root.'/vendor/autoload.php';

HelperRuntimeCache::markEmitting();
putenv('PHP_COMPILER_AOT_USER_SCRIPT=1');
$_ENV['PHP_COMPILER_AOT_USER_SCRIPT'] = '1';
$_SERVER['PHP_COMPILER_AOT_USER_SCRIPT'] = '1';

$force = in_array('--force', $argv, true);

// 1. Discover (helperPath, logicalNames[]) pairs via reflection.
$sites = [];
foreach ([$root.'/lib', $root.'/ext'] as $dir) {
    $it = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS)
    );
    foreach ($it as $file) {
        /** @var SplFileInfo $file */
        if ('php' !== $file->getExtension()) {
            continue;
        }
        $code = (string) file_get_contents($file->getPathname());
        if (!str_contains($code, 'JitVmHelperLink::')) {
            continue;
        }
        if (!preg_match('#^namespace\s+([^;]+);#m', $code, $ns)
            || !preg_match('#^(?:final\s+|abstract\s+)?class\s+(\w+)#m', $code, $cls)) {
            continue;
        }
        try {
            $ref = new ReflectionClass(trim($ns[1]).'\\'.$cls[1]);
            $constants = $ref->getConstants();
        } catch (\Throwable $e) {
            continue;
        }
        foreach ($constants as $constName => $path) {
            if (!\is_string($path) || !str_ends_with($constName, 'HELPER_PATH')) {
                continue;
            }
            $prefix = substr($constName, 0, -\strlen('HELPER_PATH'));
            $names = $constants[$prefix.'COMPILED_HELPERS'] ?? null;
            if (!\is_array($names) || [] === $names) {
                continue;
            }
            $sites[$path] = array_values(array_unique(array_merge($sites[$path] ?? [], array_map('strval', $names))));
        }
    }
}
ksort($sites);

// --unit=<path> child mode: lower ONE unit into its own TU.
$unitPath = null;
foreach ($argv as $arg) {
    if (str_starts_with($arg, '--unit=')) {
        $unitPath = substr($arg, 7);
    }
}
if (null !== $unitPath) {
    $names = $sites[$unitPath] ?? [];
    if ([] === $names) {
        fwrite(STDERR, "unknown unit {$unitPath}\n");
        exit(1);
    }
    $slug = HelperRuntimeCache::slugFor($unitPath);
    $dir = HelperRuntimeCache::unitDir($slug);
    if (!is_dir($dir) && !mkdir($dir, 0755, true) && !is_dir($dir)) {
        fwrite(STDERR, "cannot create {$dir}\n");
        exit(1);
    }
    $sourceAbs = HelperRuntimeCache::resolveUnitSource($root, $unitPath);
    if (null === $sourceAbs) {
        fwrite(STDERR, "unit source not found for {$unitPath}\n");
        exit(1);
    }

    $runtime = new Runtime(Runtime::MODE_AOT);
    $context = $runtime->loadJitContext();
    JitVmHelperLink::ensureCompiled($context, $unitPath, $names, 'helper-runtime-emit');

    // A stub main drives the same pending-bridge completion a real script
    // build performs; the duplicate stub main per unit object is discarded by
    // -z muldefs (script object is listed first at link).
    $stub = $runtime->parseAndCompile("<?php\n", 'helper-runtime-unit-stub.php');
    if (null !== $stub) {
        $context->setMain($runtime->loadJit()->compile($stub));
    }

    $helpers = [];
    foreach ($names as $logical) {
        $lc = strtolower($logical);
        $fn = $context->functions[$lc] ?? null;
        if (null === $fn) {
            continue;
        }
        $symbol = $context->llvm->lib->LLVMGetValueName($fn->value)?->toString() ?? '';
        if ('' !== $symbol) {
            $helpers[$lc] = $symbol;
        }
    }
    if ([] === $helpers) {
        fwrite(STDERR, "unit {$unitPath}: no helpers bound\n");
        exit(1);
    }
    putenv('PHP_COMPILER_KEEP_OBJECT_FILE=1');
    $_ENV['PHP_COMPILER_KEEP_OBJECT_FILE'] = '1';
    $_SERVER['PHP_COMPILER_KEEP_OBJECT_FILE'] = '1';
    $context->compileToFile($dir.'/unit.o');
    // Bitcode AFTER compileToFile: compileCommon finalizes lazy builtins and
    // verifies — a pre-finalization snapshot parses back as invalid bitcode.
    $context->module->writeBitcodeToFile($dir.'/unit.bc');
    file_put_contents($dir.'/manifest.json', json_encode([
        'fingerprint' => HelperRuntimeCache::unitFingerprint($sourceAbs),
        'unit' => $unitPath,
        'helpers' => $helpers,
    ], JSON_UNESCAPED_SLASHES)."\n");
    @unlink($dir.'/failed.json');
    exit(0);
}

// Parent: incremental sweep.
fwrite(STDOUT, 'helper-runtime-emit: '.count($sites)." helper units discovered\n");
$fresh = 0;
$emitted = 0;
$failedNow = 0;
$knownBroken = 0;
foreach ($sites as $path => $names) {
    $slug = HelperRuntimeCache::slugFor($path);
    $dir = HelperRuntimeCache::unitDir($slug);
    $sourceAbs = HelperRuntimeCache::resolveUnitSource($root, $path);
    if (null === $sourceAbs) {
        fwrite(STDERR, "helper-runtime-emit: SKIP {$path} (source not found)\n");

        continue;
    }
    $fingerprint = HelperRuntimeCache::unitFingerprint($sourceAbs);

    if (!$force) {
        $manifest = HelperRuntimeCache::unitManifest($slug);
        if (null !== $manifest && $manifest['fingerprint'] === $fingerprint
            && is_file($dir.'/unit.o') && is_file($dir.'/unit.bc')) {
            ++$fresh;

            continue;
        }
        $failure = HelperRuntimeCache::unitFailure($slug);
        if (null !== $failure && $failure['fingerprint'] === $fingerprint) {
            ++$knownBroken;

            continue; // remembered crash; re-attempt only on source/core change
        }
    }

    $cmd = escapeshellarg(PHP_BINARY).' '.escapeshellarg(__FILE__).' --unit='.escapeshellarg($path);
    exec($cmd.' 2>/dev/null', $ignored, $rc);
    if (0 !== $rc || null === HelperRuntimeCache::unitManifest($slug)) {
        ++$failedNow;
        fwrite(STDERR, "helper-runtime-emit: FAILED {$path} (rc={$rc}) — marker written, nested-lowering fallback (#15642)\n");
        if (!is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }
        @unlink($dir.'/manifest.json');
        @unlink($dir.'/unit.o');
        @unlink($dir.'/unit.bc');
        file_put_contents($dir.'/failed.json', json_encode([
            'fingerprint' => $fingerprint,
            'rc' => $rc,
        ])."\n");

        continue;
    }
    ++$emitted;
}

fwrite(STDOUT, sprintf(
    "helper-runtime-emit: OK — %d emitted, %d fresh (skipped), %d known-broken (skipped), %d failed now\n",
    $emitted,
    $fresh,
    $knownBroken,
    $failedNow
));
exit($emitted + $fresh > 0 ? 0 : 1);
