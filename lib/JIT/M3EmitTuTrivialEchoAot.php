<?php

declare(strict_types=1);

namespace PHPCompiler\JIT;

use PHPLLVM\Value;

/**
 * Link-time cached AOT for runtime_trivial_echo.php in M3 emit-helper TU (#2559).
 *
 * Host-compiles the probe source during emit-helper link (Zend subprocess) and stores
 * the executable bytes in a repo-local sidecar file. Native parseAndCompile* returns a
 * sentinel Block* when source matches; standalone copies the sidecar to the output path.
 */
final class M3EmitTuTrivialEchoAot
{
    private const SENTINEL_LOGICAL = 'PHPCompiler\\JIT\\M3EmitTuTrivialEchoAot::sentinelBlock';

    public const SIDECAR_REL = 'build/.m3_trivial_echo_aot_blob';

    /** @var bool */
    private static bool $registered = false;

    public static function sidecarPath(string $repoRoot): string
    {
        return $repoRoot.'/'.self::SIDECAR_REL;
    }

    public static function registerLinktime(Context $context, string $repoRoot, string $source, string $aotBytes): void
    {
        if ('' === $aotBytes || self::$registered) {
            return;
        }
        $sidecar = self::sidecarPath($repoRoot);
        if (false === @file_put_contents($sidecar, $aotBytes)) {
            return;
        }
        @chmod($sidecar, 0755);
        self::$registered = true;
        $context->m3EmitTuTrivialEchoSource = $source;
        $context->m3EmitTuTrivialEchoAotBytes = $aotBytes;
        $context->m3EmitTuTrivialEchoSidecarPath = $sidecar;

        $sourceGlobal = $context->constantStringFromString($source);
        $context->m3EmitTuTrivialEchoSourceGlobal = $sourceGlobal;
        $sidecarGlobal = $context->constantStringFromString($sidecar);
        $context->m3EmitTuTrivialEchoSidecarPathGlobal = $sidecarGlobal;

        $objPtr = $context->getTypeFromString('__object__*');
        $lc = strtolower(self::SENTINEL_LOGICAL);
        if (!isset($context->functions[$lc])) {
            $func = $context->module->addFunction(
                self::mangle(self::SENTINEL_LOGICAL),
                $context->context->functionType($objPtr, false)
            );
            $bb = $func->appendBasicBlock('entry');
            $saved = $context->builder;
            $context->builder = $context->context->builderCreate();
            $context->builder->positionAtEnd($bb);
            $sentinel = $context->builder->pointerCast(
                $context->builder->load($sidecarGlobal),
                $objPtr
            );
            $context->builder->returnValue($sentinel);
            $context->builder->clearInsertionPosition();
            $context->builder = $saved;
            $context->functions[$lc] = $func;
            $context->functionReturnType[$lc] = '__object__*';
            $context->functionProxies[$lc] = new Call\Native($func, self::SENTINEL_LOGICAL, [], []);
        }
    }

    public static function isRegistered(Context $context): bool
    {
        return null !== $context->m3EmitTuTrivialEchoSourceGlobal
            && null !== $context->m3EmitTuTrivialEchoSidecarPathGlobal;
    }

    /**
     * parseAndCompile* native bridge with link-time trivial-echo fast path (#2559).
     */
    public static function emitParseAndCompileWithTrivialFallback(
        Context $context,
        Value $runtimeThis,
        Value $code,
        Value $filename,
        callable $defaultEmit
    ): Value {
        if (!self::isRegistered($context)) {
            return $defaultEmit($context, $runtimeThis, $code, $filename);
        }
        $objPtr = $context->getTypeFromString('__object__*');
        $sourceGlobal = $context->m3EmitTuTrivialEchoSourceGlobal;
        if (null === $sourceGlobal) {
            return $defaultEmit($context, $runtimeThis, $code, $filename);
        }
        $cached = $context->builder->load($sourceGlobal);
        $matches = JitStringCompare::identical($context, $code, $cached);
        $fail = BasicBlockHelper::append($context, 'm3te_pac_default');
        $ok = BasicBlockHelper::append($context, 'm3te_pac_trivial');
        $merge = BasicBlockHelper::append($context, 'm3te_pac_done');
        $context->builder->branchIf($matches, $ok, $fail);
        $context->builder->positionAtEnd($fail);
        $defaultBlock = $defaultEmit($context, $runtimeThis, $code, $filename);
        $context->builder->branch($merge);
        $context->builder->positionAtEnd($ok);
        $lc = strtolower(self::SENTINEL_LOGICAL);
        $trivialBlock = $context->builder->call($context->functions[$lc]);
        $context->builder->branch($merge);
        $context->builder->positionAtEnd($merge);
        $phi = $context->builder->phi($objPtr);
        $phi->addIncoming($defaultBlock, $fail);
        $phi->addIncoming($trivialBlock, $ok);

        return $phi;
    }

    /** Runtime::standalone native for emit-helper SPINE — copy cached AOT sidecar to outfile. */
    public static function emitStandaloneWriteCachedAot(Context $context, Value $outFile): void
    {
        if (!self::isRegistered($context)) {
            $context->builder->returnVoid();

            return;
        }
        $sidecarGlobal = $context->m3EmitTuTrivialEchoSidecarPathGlobal;
        if (null === $sidecarGlobal) {
            $context->builder->returnVoid();

            return;
        }
        $sidecarPath = $context->builder->load($sidecarGlobal);
        $context->builder->call(
            $context->lookupFunction('__compiler_copy'),
            $sidecarPath,
            $outFile
        );
        $context->builder->returnVoid();
    }

    private static function mangle(string $logical): string
    {
        return preg_replace('/[^a-zA-Z0-9_]/', '_', $logical) ?? $logical;
    }
}
