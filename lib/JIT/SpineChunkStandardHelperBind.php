<?php

declare(strict_types=1);

namespace PHPCompiler\JIT;

use PHPCompiler\JIT\Call;

/**
 * On-demand NestedJIT for ext/standard helpers under SPINE_CHUNK (#36155 Phase C).
 *
 * Consumer chunks (ext/ds, …) call stdlib slices that live in separate translation units.
 * Without a hub manifest, those resolve to ExternalMethod null stubs (#579). This binds
 * known phpcompiler\ext\standard\* leaves via {@see JitVmHelperLink::ensureCompiled} when
 * the hub chunk OOMs and helper-runtime units omit rarely-called methods.
 */
final class SpineChunkStandardHelperBind
{
    /** Lowercase class short name → repo-root path (isolated *JitHelper leaves only). */
    private const CLASS_FILES = [
        'errorsilencejithelper' => '/ext/standard/ErrorSilenceJitHelper.php',
        'executionlimitsjithelper' => '/ext/standard/ExecutionLimitsJitHelper.php',
        'includepathjithelper' => '/ext/standard/IncludePathJitHelper.php',
        'includepathresolvejithelper' => '/ext/standard/IncludePathResolveJitHelper.php',
    ];

    private const PREFIXES = [
        'phpcompiler\\ext\\standard\\',
    ];

    public static function tryBind(Context $context, string $proxyName): ?Call
    {
        return SpineChunkOnDemandBind::tryBind(
            $context,
            $proxyName,
            self::CLASS_FILES,
            self::PREFIXES,
            'spine-chunk-standard',
        );
    }
}
