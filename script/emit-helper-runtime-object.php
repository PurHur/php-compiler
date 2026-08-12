#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * Incremental emitter for split-compilation helper TUs (#15889).
 *
 * Discovers every JitVmHelperLink helper unit (*HELPER_PATH /
 * *COMPILED_HELPERS, and peer *VALIDATE_PATH / *COMPILED_VALIDATE pairs —
 * FILTER_VALIDATE_{EMAIL,IP,URL} #27068/#27207/#27206), and for each unit:
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
 * Prune policy (#25377 / artifact-honesty): by default --prelink only removes
 * committed units whose helper site no longer exists (orphan rename/delete).
 * Units that still have a live site but failed or incomplete local emit are
 * KEPT — deleting them made check-helper-runtime-prelink --strict green by
 * absence while cold builds lost coverage. Pass --prelink-prune-stale to
 * restore the old "delete anything not freshly published" behaviour, or
 * --prelink-no-prune to keep every committed unit including orphans.
 *
 * Usage (pinned env, LLVM 9 required):
 *   ./script/docker-exec.sh -- bash -lc 'php script/emit-helper-runtime-object.php'
 *   ./script/docker-exec.sh -- bash -lc 'php script/emit-helper-runtime-object.php --prelink'
 *   ./script/docker-exec.sh -- bash -lc 'php script/emit-helper-runtime-object.php --migrate-deps'
 *     # rewrite legacy manifests to v2 deps[] without re-emitting .o (#23458)
 *   ./script/docker-exec.sh -- bash -lc 'php script/emit-helper-runtime-object.php --refresh-global-fingerprints'
 *     # recompute v2 unit + arch fingerprints when global material changed (patches/lock)
 *     # but helper sources/deps are unchanged — no .o re-emit (#24302)
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
$migrateDeps = in_array('--migrate-deps', $argv, true);
$refreshGlobal = in_array('--refresh-global-fingerprints', $argv, true);

if ($refreshGlobal) {
    $unitsRoot = HelperRuntimeCache::prelinkedUnitsDir();
    $refreshed = 0;
    $skipped = 0;
    if (!is_dir($unitsRoot)) {
        fwrite(STDERR, "helper-runtime-refresh-global: no prelinked units dir for ".HelperRuntimeCache::archKey()."\n");
        exit(1);
    }
    foreach (glob($unitsRoot.'/*/manifest.json') ?: [] as $manifestPath) {
        $unitDir = dirname($manifestPath);
        $slug = basename($unitDir);
        if (!is_file($unitDir.'/unit.o') || !is_file($unitDir.'/unit.bc')) {
            ++$skipped;
            continue;
        }
        $manifest = HelperRuntimeCache::unitManifest($slug, $unitDir);
        if (null === $manifest) {
            ++$skipped;
            continue;
        }
        $sourceAbs = HelperRuntimeCache::resolveUnitSource($root, (string) $manifest['unit']);
        if (null === $sourceAbs) {
            ++$skipped;
            continue;
        }
        $deps = isset($manifest['deps']) && \is_array($manifest['deps']) ? $manifest['deps'] : [];
        $manifest['deps'] = $deps;
        $manifest['fingerprint'] = HelperRuntimeCache::fingerprintV2($sourceAbs, $deps);
        $manifest['fingerprint_version'] = 2;
        file_put_contents($manifestPath, json_encode($manifest, JSON_UNESCAPED_SLASHES)."\n");
        ++$refreshed;
    }
    $archManifestPath = \dirname($unitsRoot).'/manifest.json';
    if (is_file($archManifestPath)) {
        $arch = json_decode((string) file_get_contents($archManifestPath), true);
        if (\is_array($arch)) {
            $arch['core_fingerprint'] = HelperRuntimeCache::coreFingerprint();
            $arch['llvm_identity_token'] = HelperRuntimeCache::llvmIdentityToken();
            $arch['global_refreshed_at'] = gmdate('c');
            file_put_contents($archManifestPath, json_encode($arch, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT)."\n");
        }
    }
    fwrite(STDOUT, "helper-runtime-refresh-global: {$refreshed} rewritten, {$skipped} skipped (#24302)\n");
    exit($refreshed > 0 ? 0 : 1);
}

if ($migrateDeps) {
    $roots = [HelperRuntimeCache::unitsDir(), HelperRuntimeCache::prelinkedUnitsDir()];
    $migrated = 0;
    $skipped = 0;
    foreach ($roots as $unitsRoot) {
        if (!is_dir($unitsRoot)) {
            continue;
        }
        foreach (glob($unitsRoot.'/*/manifest.json') ?: [] as $manifestPath) {
            $unitDir = dirname($manifestPath);
            $slug = basename($unitDir);
            $manifest = HelperRuntimeCache::unitManifest($slug, $unitDir);
            if (null === $manifest) {
                ++$skipped;
                continue;
            }
            $sourceAbs = HelperRuntimeCache::resolveUnitSource($root, (string) $manifest['unit']);
            if (null === $sourceAbs) {
                ++$skipped;
                continue;
            }
            $next = HelperRuntimeCache::migrateManifestToV2($manifest, $sourceAbs);
            if (null === $next) {
                ++$skipped;
                continue;
            }
            file_put_contents($manifestPath, json_encode($next, JSON_UNESCAPED_SLASHES)."\n");
            ++$migrated;
        }
    }
    $archManifest = dirname(HelperRuntimeCache::prelinkedUnitsDir()).'/manifest.json';
    if (is_file($archManifest)) {
        $arch = json_decode((string) file_get_contents($archManifest), true);
        if (\is_array($arch)) {
            $arch['fingerprint_schema'] = 2;
            $arch['core_fingerprint'] = HelperRuntimeCache::coreFingerprint();
            $arch['legacy_lowering_fingerprint'] = HelperRuntimeCache::legacyLoweringFingerprint();
            $arch['migrated_at'] = gmdate('c');
            $arch['role'] = 'committed per-arch split-compilation helper units (#15889, #23458 per-unit deps) — consumed via PHP_COMPILER_HELPER_RUNTIME_O=1; stale units are skipped per fingerprint and recompiled locally';
            file_put_contents($archManifest, json_encode($arch, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT)."\n");
        }
    }
    fwrite(STDOUT, "helper-runtime-migrate-deps: {$migrated} rewritten, {$skipped} skipped (#23458)\n");
    exit($migrated > 0 ? 0 : 1);
}

// 1. Discover (helperPath, logicalNames[]) pairs via reflection.
// Optional *HELPER_BUNDLE (list of repo-root paths) NestedJITs deps + unit in one
// scope — required for PackJitHelper (#22981 / #22843 solo-unit non-termination).
$sites = [];
$bundles = [];
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
            if (!\is_string($path)) {
                continue;
            }
            // HELPER_PATH + COMPILED_HELPERS (default), or VALIDATE_PATH +
            // COMPILED_VALIDATE (thin-AOT filter peers — not discovered before
            // left orphan Filter*JitHelper prelink dirs stale after #27911–13).
            if (str_ends_with($constName, 'HELPER_PATH')) {
                $prefix = substr($constName, 0, -\strlen('HELPER_PATH'));
                $names = $constants[$prefix.'COMPILED_HELPERS'] ?? null;
                $bundleKey = $prefix.'HELPER_BUNDLE';
            } elseif (str_ends_with($constName, 'VALIDATE_PATH')) {
                $prefix = substr($constName, 0, -\strlen('VALIDATE_PATH'));
                $names = $constants[$prefix.'COMPILED_VALIDATE'] ?? null;
                $bundleKey = $prefix.'HELPER_BUNDLE';
            } else {
                continue;
            }
            if (!\is_array($names) || [] === $names) {
                continue;
            }
            $sites[$path] = array_values(array_unique(array_merge($sites[$path] ?? [], array_map('strval', $names))));
            $bundle = $constants[$bundleKey] ?? null;
            if (\is_array($bundle) && [] !== $bundle) {
                $bundles[$path] = array_values(array_map('strval', $bundle));
            }
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
    $bundle = $bundles[$unitPath] ?? [$unitPath];
    if (\count($bundle) > 1) {
        JitVmHelperLink::ensureCompiledBundle($context, $bundle, $names, 'helper-runtime-emit');
    } else {
        JitVmHelperLink::ensureCompiled($context, $unitPath, $names, 'helper-runtime-emit');
    }

    // NestedJIT closure for per-unit deps (#23458) — before stub/main finalize.
    $compiledAbs = [];
    foreach ($context->jitAotIncludedCompileDone as $key => $_) {
        $parts = explode("\0", (string) $key, 2);
        $compiledAbs[] = $parts[1] ?? $parts[0];
    }
    foreach ($context->jitIncludedFiles as $included) {
        if (\is_string($included) && '' !== $included) {
            $compiledAbs[] = $included;
        }
    }
    $deps = HelperRuntimeCache::dependencyRelPathsForEmit($sourceAbs, $compiledAbs);
    $unitFingerprint = HelperRuntimeCache::fingerprintV2($sourceAbs, $deps);

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
        // unit.o returns "" for method-return / dynamic string args; NestedJIT recursive
        // escapeFrom works (#25345 MiniWebApp $appName). Re-enable when unit.o matches.
        '/ext/standard/HtmlspecialcharsJitHelper.php' => true,
        // #28104 — self-contained NestedJIT escapeFrom is green in-process; helper-runtime
        // unit.o still segfaults under HELPER_RUNTIME_O=1 (peer htmlspecialchars #25345).
        '/ext/standard/AddslashesJitHelper.php' => true,
        // #28104 — mutual $i+1 stripFrom/emitEscaped NestedJIT green in-process; unit.o peer.
        '/ext/standard/StripslashesJitHelper.php' => true,
        // Peer always-helper (#20589); keep off HELPER_RUNTIME_O until unit.o green.
        '/ext/standard/VarExportJitHelper.php' => true,
        // var_dump/print_r need consumer-module NestedJIT + live VM (#16075 / #23540).
        '/ext/standard/VarDumpJitHelper.php' => true,
        '/ext/standard/PrintRJitHelper.php' => true,
        // #26772 — unit.o stubs format → null; NestedJIT self-contained helper into user AOT.
        '/ext/standard/DateTimeFormatJitHelper.php' => true,
        // #27068 — NestedJIT FilterEmailValidate into user AOT (const path folds in JitFilter).
        '/ext/filter/FilterEmailValidate.php' => true,
        // #26989 — unit.o calls __compiler_preg_match without a provider in the helper TU;
        // NestedJIT into the user module so PregMatchRuntime can link (cold-build hello-world).
        '/ext/standard/PendingHeadersJitHelper.php' => true,
    ];
    file_put_contents($dir.'/manifest.json', json_encode([
        'fingerprint' => $unitFingerprint,
        'fingerprint_version' => 2,
        'unit' => $unitPath,
        'deps' => $deps,
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
/** @var array<string, array{path: string, dir: string, fingerprint: string}> */
$pendingUnits = [];
foreach ($sites as $path => $names) {
    $slug = HelperRuntimeCache::slugFor($path);
    $dir = HelperRuntimeCache::unitDir($slug);
    $sourceAbs = HelperRuntimeCache::resolveUnitSource($root, $path);
    if (null === $sourceAbs) {
        fwrite(STDERR, "helper-runtime-emit: SKIP {$path} (source not found)\n");

        continue;
    }
    // Freshness: prefer on-disk manifest deps (v2) or legacy v1 key (#23458).
    $fingerprint = null;
    if (!$force) {
        $manifest = HelperRuntimeCache::unitManifest($slug);
        if (null !== $manifest && is_file($dir.'/unit.o') && is_file($dir.'/unit.bc')
            && HelperRuntimeCache::manifestFingerprintMatches($manifest, $sourceAbs)) {
            ++$fresh;

            continue;
        }
        $fingerprint = null !== $manifest
            ? HelperRuntimeCache::expectedFingerprintForManifest($manifest, $sourceAbs)
            : HelperRuntimeCache::unitFingerprint($sourceAbs);
        $failure = HelperRuntimeCache::unitFailure($slug);
        if (null !== $failure && $failure['fingerprint'] === $fingerprint) {
            ++$knownBroken;

            continue; // remembered crash; re-attempt only on source/deps/global change
        }
    }
    if (null === $fingerprint) {
        $fingerprint = HelperRuntimeCache::unitFingerprint($sourceAbs);
    }

    $pendingUnits[$slug] = ['path' => $path, 'dir' => $dir, 'fingerprint' => $fingerprint];
}

/**
 * Record one child lowering outcome. Identical bookkeeping whatever the job count.
 */
$recordUnitResult = static function (string $slug, array $unit, int $rc) use (&$emitted, &$failedNow): void {
    $dir = $unit['dir'];
    $stderrFile = $dir.'/.emit-stderr.txt';
    $childStderr = is_file($stderrFile) ? (string) file_get_contents($stderrFile) : '';
    @unlink($stderrFile);
    if (0 === $rc && null !== HelperRuntimeCache::unitManifest($slug)) {
        ++$emitted;

        return;
    }
    ++$failedNow;
    // Capture child stderr so unit failures name the lowering defect (#22638).
    $cause = trim($childStderr);
    if ('' === $cause) {
        $cause = '(no stderr)';
    } else {
        $cause = preg_replace('/\s+/', ' ', $cause) ?? $cause;
        if (strlen($cause) > 400) {
            $cause = substr($cause, 0, 400).'…';
        }
    }
    fwrite(STDERR, "helper-runtime-emit: FAILED {$unit['path']} (rc={$rc}) — {$cause} — nested-lowering fallback (#15642)\n");
    if (!is_dir($dir)) {
        @mkdir($dir, 0755, true);
    }
    @unlink($dir.'/manifest.json');
    @unlink($dir.'/unit.o');
    @unlink($dir.'/unit.bc');
    file_put_contents($dir.'/failed.json', json_encode([
        'fingerprint' => $unit['fingerprint'],
        'rc' => $rc,
        'stderr' => $childStderr,
    ])."\n");
};

// Fan the child lowerings out. Every unit already runs in its own `--unit=` child
// writing only to build/helper-runtime-cache/units/<slug>/, so there is no shared
// mutable state between them. Default nproc-2 mirrors bin/lint.php's
// PHP_COMPILER_LINT_JOBS; PHP_COMPILER_EMIT_JOBS=1 restores the serial sweep.
$emitJobs = (int) (getenv('PHP_COMPILER_EMIT_JOBS') ?: 0);
if ($emitJobs < 1) {
    $nproc = (int) shell_exec('nproc 2>/dev/null');
    $emitJobs = $nproc > 2 ? $nproc - 2 : 1;
}
$emitJobs = max(1, min($emitJobs, max(1, count($pendingUnits))));

// A unit whose lowering never finishes must not hang the corpus. Without a cap the reap loop
// below waits on it forever, so `warmForUserAotBuild()` — and therefore every cold `phpc build`
// — blocks with no diagnostic and no failed.json to remember it by (#22843 PackJitHelper).
// 0 disables the cap and restores the old unbounded wait.
$emitUnitTimeout = getenv('PHP_COMPILER_EMIT_UNIT_TIMEOUT');
$emitUnitTimeout = false === $emitUnitTimeout || '' === $emitUnitTimeout
    ? 600
    : max(0, (int) $emitUnitTimeout);

if ([] !== $pendingUnits) {
    fwrite(STDOUT, sprintf(
        "helper-runtime-emit: lowering %d unit(s) across %d job(s)%s\n",
        count($pendingUnits),
        $emitJobs,
        $emitUnitTimeout > 0 ? ", {$emitUnitTimeout}s/unit cap" : ', no per-unit cap'
    ));
}

$running = [];
$queue = $pendingUnits;
while ([] !== $queue || [] !== $running) {
    while ([] !== $queue && count($running) < $emitJobs) {
        $slug = array_key_first($queue);
        $unit = $queue[$slug];
        unset($queue[$slug]);
        if (!is_dir($unit['dir'])) {
            @mkdir($unit['dir'], 0755, true);
        }
        $cmd = escapeshellarg(PHP_BINARY).' '.escapeshellarg(__FILE__)
            .' --unit='.escapeshellarg($unit['path']);
        $proc = proc_open($cmd, [
            1 => ['file', '/dev/null', 'w'],
            2 => ['file', $unit['dir'].'/.emit-stderr.txt', 'w'],
        ], $pipes);
        if (!\is_resource($proc)) {
            $recordUnitResult($slug, $unit, -1);

            continue;
        }
        $running[$slug] = ['proc' => $proc, 'unit' => $unit, 'started' => time()];
    }

    $reaped = false;
    foreach ($running as $slug => $entry) {
        $status = proc_get_status($entry['proc']);
        if ($status['running']) {
            if ($emitUnitTimeout > 0 && (time() - $entry['started']) >= $emitUnitTimeout) {
                // SIGKILL: this child is wedged in LLVM lowering and will not honour SIGTERM.
                proc_terminate($entry['proc'], 9);
                proc_close($entry['proc']);
                fwrite(STDERR, sprintf(
                    "helper-runtime-emit: TIMEOUT %s after %ds — recorded as failed so the corpus can finish"
                    ." (raise PHP_COMPILER_EMIT_UNIT_TIMEOUT, or 0 to disable)\n",
                    $entry['unit']['path'],
                    $emitUnitTimeout
                ));
                $recordUnitResult($slug, $entry['unit'], 124);
                unset($running[$slug]);
                $reaped = true;
            }

            continue;
        }
        $rc = proc_close($entry['proc']);
        // proc_close returns -1 once proc_get_status has already reaped the exit code.
        $recordUnitResult($slug, $entry['unit'], -1 === $rc ? (int) $status['exitcode'] : $rc);
        unset($running[$slug]);
        $reaped = true;
    }
    if (!$reaped && [] !== $running) {
        usleep(20000);
    }
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
            || !HelperRuntimeCache::manifestFingerprintMatches($manifest, $sourceAbs)
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
    // Live helper-site slugs (presence in corpus), independent of emit success.
    $liveSlugs = [];
    foreach ($sites as $path => $_) {
        $liveSlugs[HelperRuntimeCache::slugFor($path)] = true;
    }
    $noPrune = in_array('--prelink-no-prune', $argv, true);
    $pruneStale = in_array('--prelink-prune-stale', $argv, true);
    // Drop committed units whose helper site no longer exists (or was renamed).
    // Do NOT delete live-site units that failed/incomplete emit — that made
    // check-helper-runtime-prelink --strict green by absence (#25377).
    $removed = 0;
    $keptLiveUnpublished = 0;
    foreach (glob($prelinkUnits.'/*', GLOB_ONLYDIR) ?: [] as $dir) {
        $slug = basename($dir);
        if (isset($publishedSlugs[$slug])) {
            continue;
        }
        if ($noPrune) {
            ++$keptLiveUnpublished;
            continue;
        }
        if (!$pruneStale && isset($liveSlugs[$slug])) {
            ++$keptLiveUnpublished;
            continue;
        }
        foreach (glob($dir.'/*') ?: [] as $file) {
            @unlink($file);
        }
        @rmdir($dir);
        ++$removed;
    }
    $committedDirs = glob($prelinkUnits.'/*', GLOB_ONLYDIR) ?: [];
    $unitCount = \count($committedDirs);
    // Recount bytes for kept-but-unpublished dirs so manifest total_bytes is honest.
    if ($keptLiveUnpublished > 0) {
        $totalBytes = 0;
        foreach ($committedDirs as $dir) {
            foreach (['unit.o', 'unit.bc', 'manifest.json'] as $name) {
                $totalBytes += (int) @filesize($dir.'/'.$name);
            }
        }
    }
    file_put_contents($archDir.'/manifest.json', json_encode([
        'version' => 1,
        'generated_at' => gmdate('c'),
        'arch' => $arch,
        'role' => 'committed per-arch split-compilation helper units (#15889) — consumed via PHP_COMPILER_HELPER_RUNTIME_O=1; stale units are skipped per fingerprint and recompiled locally',
        'core_fingerprint' => HelperRuntimeCache::coreFingerprint(),
        'llvm_identity_token' => HelperRuntimeCache::llvmIdentityToken(),
        'unit_count' => $unitCount,
        'published_fresh' => $published,
        'kept_live_unpublished' => $keptLiveUnpublished,
        'total_bytes' => $totalBytes,
        'refresh' => 'php script/emit-helper-runtime-object.php --prelink (pinned env; live-site prune guard #25377)',
    ], JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT)."\n");
    fwrite(STDOUT, sprintf(
        "helper-runtime-prelink: %s — %d fresh published (%.1f MB), %d removed, %d kept live-unpublished, %d committed — commit prelinked/helper-runtime when intentional\n",
        $arch,
        $published,
        $totalBytes / 1048576,
        $removed,
        $keptLiveUnpublished,
        $unitCount
    ));
}
exit($emitted + $fresh > 0 ? 0 : 1);
