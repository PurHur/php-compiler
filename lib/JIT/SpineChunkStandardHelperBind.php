<?php

declare(strict_types=1);

namespace PHPCompiler\JIT;

use PHPCompiler\AOT\ExternalMethodBind;
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
    ];

    public static function tryBind(Context $context, string $proxyName): ?Call
    {
        if (!ExternalMethodBind::spineChunkMode()) {
            return null;
        }
        $lc = strtolower(ltrim($proxyName, '\\'));
        if (!str_starts_with($lc, 'phpcompiler\\ext\\standard\\')) {
            return null;
        }
        if (!str_contains($lc, '::')) {
            return null;
        }
        [$classLc, $_methodLc] = explode('::', $lc, 2);
        $short = substr($classLc, strrpos($classLc, '\\') + 1);
        $path = self::CLASS_FILES[$short] ?? null;
        if (null === $path) {
            return null;
        }
        if (isset($context->functions[$lc])) {
            return self::nativeCall($context, $lc, $proxyName);
        }
        $savedInsert = BasicBlockHelper::tryGetInsertBlock($context);
        try {
            JitVmHelperLink::ensureCompiled(
                $context,
                $path,
                [$proxyName],
                'spine-chunk-standard',
                true
            );
        } catch (\Throwable) {
            return null;
        } finally {
            if (null !== $savedInsert) {
                $context->builder->positionAtEnd($savedInsert);
            } else {
                $context->builder->clearInsertionPosition();
            }
        }
        if (!isset($context->functions[$lc])) {
            return null;
        }

        return self::nativeCall($context, $lc, $proxyName);
    }

    private static function nativeCall(Context $context, string $lc, string $proxyName): Call
    {
        $fn = $context->functions[$lc];
        $argTypes = [];
        for ($i = 0, $n = $fn->countParams(); $i < $n; ++$i) {
            $argTypes[] = $fn->getParam($i)->typeOf();
        }
        $native = new Call\Native($fn, $proxyName, $argTypes);
        $context->functionProxies[$lc] = $native;

        return $native;
    }
}
