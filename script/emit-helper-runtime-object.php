#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * Emit the split-compilation helper-runtime TUs (#15889).
 *
 * Discovers every JitVmHelperLink::ensureCompiled unit (self::HELPER_PATH /
 * self::COMPILED_HELPERS class constants), lowers each unit in an ISOLATED
 * subprocess into its own translation unit, and writes
 *
 *   build/helper-runtime-cache/<fingerprint>/
 *     <unit>.bc      — bitcode (per-script builds read function types from it)
 *     <unit>.o       — object merged by the Linker (-z muldefs)
 *     manifest.json  — logical callee → {symbol, unit}
 *
 * Units whose lowering crashes (#15642 class) are skipped and keep falling
 * back to nested lowering. Per-unit isolation sidesteps cross-unit signature
 * drift that breaks verification when all helpers share one module.
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
        // Pair every *HELPER_PATH constant with its sibling *COMPILED_HELPERS
        // list (prefix match: FOO_HELPER_PATH ↔ FOO_COMPILED_HELPERS;
        // HELPER_PATH ↔ COMPILED_HELPERS).
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

$entryDir = HelperRuntimeCache::cacheDir().'/'.HelperRuntimeCache::fingerprint();

// --unit=<path> child mode: lower ONE unit into its own TU and write
// <slug>.bc / <slug>.o / <slug>.manifest.json fragments.
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
    $slug = preg_replace('#[^A-Za-z0-9]+#', '_', trim($unitPath, '/'));
    $runtime = new Runtime(Runtime::MODE_AOT);
    $context = $runtime->loadJitContext();
    JitVmHelperLink::ensureCompiled($context, $unitPath, $names, 'helper-runtime-emit');

    // A stub main drives the same pending-bridge completion a real script
    // build performs — without it, base-module bridge callsites keep
    // placeholder signatures and module verification fails. The duplicate
    // stub main in each unit object is discarded by -z muldefs (script
    // object is listed first at link).
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
    $context->compileToFile($entryDir.'/'.$slug.'.o');
    // Bitcode AFTER compileToFile: compileCommon finalizes lazy builtins and
    // verifies — a pre-finalization snapshot parses back as invalid bitcode.
    $context->module->writeBitcodeToFile($entryDir.'/'.$slug.'.bc');
    file_put_contents($entryDir.'/'.$slug.'.manifest.json', json_encode([
        'unit' => $unitPath,
        'slug' => $slug,
        'helpers' => $helpers,
    ], JSON_UNESCAPED_SLASHES)."\n");
    exit(0);
}

// Parent: one subprocess per unit; crashes/failures are skipped, not fatal.
fwrite(STDOUT, 'helper-runtime-emit: '.count($sites)." helper units discovered\n");
if (!is_dir($entryDir) && !mkdir($entryDir, 0755, true) && !is_dir($entryDir)) {
    fwrite(STDERR, "helper-runtime-emit: cannot create {$entryDir}\n");
    exit(1);
}

$units = [];
$helpers = [];
$skipped = 0;
foreach ($sites as $path => $names) {
    $cmd = escapeshellarg(PHP_BINARY).' '.escapeshellarg(__FILE__).' --unit='.escapeshellarg($path);
    exec($cmd.' 2>/dev/null', $ignored, $rc);
    $slug = preg_replace('#[^A-Za-z0-9]+#', '_', trim($path, '/'));
    $fragment = $entryDir.'/'.$slug.'.manifest.json';
    if (0 !== $rc || !is_file($fragment)) {
        ++$skipped;
        fwrite(STDERR, "helper-runtime-emit: SKIP {$path} (unit emit rc={$rc} — nested lowering fallback, see #15642)\n");
        @unlink($entryDir.'/'.$slug.'.bc');
        @unlink($entryDir.'/'.$slug.'.o');
        @unlink($fragment);

        continue;
    }
    $decoded = json_decode((string) file_get_contents($fragment), true);
    if (!\is_array($decoded) || !isset($decoded['helpers'])) {
        ++$skipped;

        continue;
    }
    $units[$slug] = $path;
    foreach ((array) $decoded['helpers'] as $logical => $symbol) {
        $helpers[$logical] = ['symbol' => (string) $symbol, 'unit' => $slug];
    }
}

if ([] === $helpers) {
    fwrite(STDERR, "helper-runtime-emit: nothing compiled — aborting\n");
    exit(1);
}

file_put_contents($entryDir.'/manifest.json', json_encode([
    'fingerprint' => HelperRuntimeCache::fingerprint(),
    'generated_at' => date('c'),
    'units' => $units,
    'helpers' => $helpers,
], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n");

$totalKb = 0;
foreach (glob($entryDir.'/*.o') as $object) {
    $totalKb += (int) round(filesize($object) / 1024);
}
fwrite(STDOUT, 'helper-runtime-emit: OK '.count($units).' unit objects, '
    .count($helpers)." helpers, {$skipped} skipped, {$totalKb} KB total — {$entryDir}\n");
