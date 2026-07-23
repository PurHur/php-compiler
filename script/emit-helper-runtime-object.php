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
 * --prelink additionally publishes every fresh unit into the committed
 * per-arch cache prelinked/helper-runtime/<arch>/units/ (fingerprints are
 * content-based, so any clone on the same arch consumes them cold) and
 * rewrites that arch's manifest.json. Commit the result when intentional.
 *
 * Usage (pinned env, LLVM 9 required):
 *   ./script/docker-exec.sh -- bash -lc 'php script/emit-helper-runtime-object.php'
 *   ./script/docker-exec.sh -- bash -lc 'php script/emit-helper-runtime-object.php --prelink'
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

    // Unique per-unit init/shutdown symbols: the colliding __init__ was
    // muldefs-discarded at link and unit module state never initialized;
    // consumers call __init__unit_<slug> explicitly (#16075 step 4).
    $initSuffix = 'unit_'.HelperRuntimeCache::slugFor($unitPath);
    putenv('PHP_COMPILER_INIT_SYMBOL_SUFFIX='.$initSuffix);
    $_ENV['PHP_COMPILER_INIT_SYMBOL_SUFFIX'] = $initSuffix;
    $_SERVER['PHP_COMPILER_INIT_SYMBOL_SUFFIX'] = $initSuffix;

    $runtime = new Runtime(Runtime::MODE_AOT);
    $context = $runtime->loadJitContext();
    foreach ($argv as $arg) {
        if (str_starts_with($arg, '--preload=')) { // TEMP experiment #16565/#15889
            foreach (explode(',', substr($arg, 10)) as $preload) {
                $abs = $root.'/'.ltrim($preload, '/');
                $block = $runtime->parseAndCompile((string) file_get_contents($abs), basename($abs));
                if (null !== $block) {
                    (new \PHPCompiler\JIT($context))->compile($block);
                }
            }
        }
    }
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
    //
    // Declarations-only module instead of the full unit bitcode: consumers
    // (HelperRuntimeCache::tryProvide) only read the helpers' function types
    // from it, and the full module bitcode roughly doubled the committed
    // per-arch cache. Same LLVMContext, so named struct types match the ones
    // the object file was lowered with.
    $lib = $context->llvm->lib;
    $decls = $context->context->moduleCreateWithName(HelperRuntimeCache::slugFor($unitPath).'_decls');
    foreach ($helpers as $lc => $symbol) {
        $fn = $context->module->getNamedFunction($symbol);
        if (null === $fn) {
            continue;
        }
        $fnType = $lib->LLVMGetElementType($lib->LLVMTypeOf($fn->value));
        if (null === $fnType) {
            continue;
        }
        $decls->addFunction($symbol, $context->llvm->factory->type($context->context, $fnType));
    }
    $decls->writeBitcodeToFile($dir.'/unit.bc');
    // Units that construct VM objects bake class ids from THIS process's
    // registry; the consuming script numbers classes differently and the
    // helper segfaults at runtime — blocked from consumption until class-id
    // unification lands (#16075 step 5, gdb data on #15642).
    $runtimeUnsafe = [
        '/ext/standard/Bin2hexJitHelper.php' => true,
        // Peer always-helper (#20469); keep off HELPER_RUNTIME_O until unit.o green.
        '/ext/standard/HashEqualsJitHelper.php' => true,
        // Peer always-helper (#21026); NestedJIT digest path until unit.o green.
        '/ext/standard/HashCryptoJitHelper.php' => true,
        // Peer always-helper (#20487); keep off HELPER_RUNTIME_O until unit.o green.
        '/ext/standard/HtmlspecialcharsJitHelper.php' => true,
        // Peer always-helper (#20589); keep off HELPER_RUNTIME_O until unit.o green.
        '/ext/standard/VarExportJitHelper.php' => true,
    ];
    file_put_contents($dir.'/manifest.json', json_encode([
        'fingerprint' => HelperRuntimeCache::unitFingerprint($sourceAbs),
        'unit' => $unitPath,
        'helpers' => $helpers,
        'init_symbol' => '__init__'.$initSuffix,
        'shutdown_symbol' => '__shutdown__'.$initSuffix,
        'init_via_global_ctor' => true,
        'runtime_safe' => !isset($runtimeUnsafe[$unitPath]),
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
    $stderrFile = $dir.'/.emit-stderr.txt';
    if (!is_dir($dir)) {
        @mkdir($dir, 0755, true);
    }
    // Capture child stderr so unit failures name the lowering defect (#22638).
    exec($cmd.' 2>'.escapeshellarg($stderrFile), $ignored, $rc);
    $childStderr = is_file($stderrFile) ? (string) file_get_contents($stderrFile) : '';
    @unlink($stderrFile);
    if (0 !== $rc || null === HelperRuntimeCache::unitManifest($slug)) {
        ++$failedNow;
        $cause = trim($childStderr);
        if ('' === $cause) {
            $cause = '(no stderr)';
        } else {
            $cause = preg_replace('/\s+/', ' ', $cause) ?? $cause;
            if (strlen($cause) > 400) {
                $cause = substr($cause, 0, 400).'…';
            }
        }
        fwrite(STDERR, "helper-runtime-emit: FAILED {$path} (rc={$rc}) — {$cause} — nested-lowering fallback (#15642)\n");
        if (!is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }
        @unlink($dir.'/manifest.json');
        @unlink($dir.'/unit.o');
        @unlink($dir.'/unit.bc');
        file_put_contents($dir.'/failed.json', json_encode([
            'fingerprint' => $fingerprint,
            'rc' => $rc,
            'stderr' => $childStderr,
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

if (in_array('--prelink', $argv, true)) {
    $arch = HelperRuntimeCache::archKey();
    $prelinkUnits = HelperRuntimeCache::prelinkedUnitsDir();
    $archDir = \dirname($prelinkUnits);
    if (!is_dir($prelinkUnits) && !mkdir($prelinkUnits, 0755, true) && !is_dir($prelinkUnits)) {
        fwrite(STDERR, "helper-runtime-prelink: cannot create {$prelinkUnits}\n");
        exit(1);
    }
    $published = 0;
    $totalBytes = 0;
    $publishedSlugs = [];
    foreach ($sites as $path => $names) {
        $slug = HelperRuntimeCache::slugFor($path);
        $buildDir = HelperRuntimeCache::unitDir($slug);
        $sourceAbs = HelperRuntimeCache::resolveUnitSource($root, $path);
        $manifest = HelperRuntimeCache::unitManifest($slug);
        if (null === $sourceAbs || null === $manifest
            || $manifest['fingerprint'] !== HelperRuntimeCache::unitFingerprint($sourceAbs)
            || !is_file($buildDir.'/unit.o') || !is_file($buildDir.'/unit.bc')) {
            continue; // only fresh, complete units are published
        }
        $dest = $prelinkUnits.'/'.$slug;
        if (!is_dir($dest) && !mkdir($dest, 0755, true) && !is_dir($dest)) {
            continue;
        }
        foreach (['unit.o', 'unit.bc', 'manifest.json'] as $name) {
            copy($buildDir.'/'.$name, $dest.'/'.$name);
            $totalBytes += (int) @filesize($dest.'/'.$name);
        }
        @unlink($dest.'/failed.json'); // never commit crash markers — env-specific
        $publishedSlugs[$slug] = true;
        ++$published;
    }
    // Drop committed units whose helper site no longer exists (or was renamed).
    $removed = 0;
    foreach (glob($prelinkUnits.'/*', GLOB_ONLYDIR) ?: [] as $dir) {
        if (isset($publishedSlugs[basename($dir)])) {
            continue;
        }
        foreach (glob($dir.'/*') ?: [] as $file) {
            @unlink($file);
        }
        @rmdir($dir);
        ++$removed;
    }
    file_put_contents($archDir.'/manifest.json', json_encode([
        'version' => 1,
        'generated_at' => gmdate('c'),
        'arch' => $arch,
        'role' => 'committed per-arch split-compilation helper units (#15889) — consumed via PHP_COMPILER_HELPER_RUNTIME_O=1; stale units are skipped per fingerprint and recompiled locally',
        'core_fingerprint' => HelperRuntimeCache::coreFingerprint(),
        'unit_count' => $published,
        'total_bytes' => $totalBytes,
        'refresh' => 'php script/emit-helper-runtime-object.php --prelink (pinned env)',
    ], JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT)."\n");
    fwrite(STDOUT, sprintf(
        "helper-runtime-prelink: %s — %d units published (%.1f MB), %d removed — commit prelinked/helper-runtime when intentional\n",
        $arch,
        $published,
        $totalBytes / 1048576,
        $removed
    ));
}
exit($emitted + $fresh > 0 ? 0 : 1);
