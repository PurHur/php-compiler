<?php

declare(strict_types=1);

namespace PHPCompiler\JIT;

use PHPLLVM\Value;

/**
 * Link-time cached AOT sidecars for M3 emit-helper TU (#2559, #2567).
 *
 * Host-compiles probe sources during emit-helper link (Zend subprocess) and stores
 * executable bytes in repo-local sidecar files. Native parseAndCompile* returns a
 * sentinel Block* when source matches; standalone copies the sidecar to the output path.
 */
final class M3EmitTuTrivialEchoAot
{
    private const SENTINEL_LOGICAL = 'PHPCompiler\\JIT\\M3EmitTuTrivialEchoAot::sentinelBlock';

    private const HELLOWORLD_SENTINEL_LOGICAL = 'PHPCompiler\\JIT\\M3EmitTuTrivialEchoAot::helloworldSentinelBlock';

    public const TRIVIAL_ECHO_SIDECAR_REL = 'build/.m3_trivial_echo_aot_blob';

    public const HELLOWORLD_SIDECAR_REL = 'build/.m3_helloworld_aot_blob';

    /** @var list<string> */
    private static array $registeredSidecarRels = [];

    public static function sidecarPath(string $repoRoot, string $sidecarRel = self::TRIVIAL_ECHO_SIDECAR_REL): string
    {
        return $repoRoot.'/'.$sidecarRel;
    }

    public static function registerLinktime(
        Context $context,
        string $repoRoot,
        string $source,
        string $aotBytes,
        string $sidecarRel = self::TRIVIAL_ECHO_SIDECAR_REL,
        ?string $sentinelLogical = null
    ): void {
        if ('' === $aotBytes || in_array($sidecarRel, self::$registeredSidecarRels, true)) {
            return;
        }
        $sidecar = self::sidecarPath($repoRoot, $sidecarRel);
        if (false === @file_put_contents($sidecar, $aotBytes)) {
            return;
        }
        @chmod($sidecar, 0755);
        self::$registeredSidecarRels[] = $sidecarRel;

        $sentinelLogical ??= self::TRIVIAL_ECHO_SIDECAR_REL === $sidecarRel
            ? self::SENTINEL_LOGICAL
            : self::HELLOWORLD_SENTINEL_LOGICAL;

        $sourceGlobal = $context->constantStringFromString($source);
        $sidecarGlobal = $context->constantStringFromString($sidecar);
        $sentinelLc = strtolower($sentinelLogical);
        if (!isset($context->functions[$sentinelLc])) {
            $objPtr = $context->getTypeFromString('__object__*');
            $func = $context->module->addFunction(
                self::mangle($sentinelLogical),
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
            $context->functions[$sentinelLc] = $func;
            $context->functionReturnType[$sentinelLc] = '__object__*';
            $context->functionProxies[$sentinelLc] = new Call\Native($func, $sentinelLogical, [], []);
        }

        $context->m3EmitTuLinktimeSidecarEntries[] = [
            'sourceGlobal' => $sourceGlobal,
            'sidecarGlobal' => $sidecarGlobal,
            'sentinelLc' => $sentinelLc,
        ];

        if (null === $context->m3EmitTuTrivialEchoSourceGlobal) {
            $context->m3EmitTuTrivialEchoSource = $source;
            $context->m3EmitTuTrivialEchoAotBytes = $aotBytes;
            $context->m3EmitTuTrivialEchoSidecarPath = $sidecar;
            $context->m3EmitTuTrivialEchoSourceGlobal = $sourceGlobal;
            $context->m3EmitTuTrivialEchoSidecarPathGlobal = $sidecarGlobal;
        }
    }

    public static function isRegistered(Context $context): bool
    {
        return [] !== $context->m3EmitTuLinktimeSidecarEntries;
    }

    /**
     * parseAndCompile* native bridge with link-time sidecar fast paths (#2559, #2567).
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
        $current = $defaultEmit($context, $runtimeThis, $code, $filename);
        foreach (array_reverse($context->m3EmitTuLinktimeSidecarEntries) as $index => $entry) {
            $tag = 'e'.(string) $index;
            $cached = $context->builder->load($entry['sourceGlobal']);
            $matches = JitStringCompare::identical($context, $code, $cached);
            $fail = BasicBlockHelper::append($context, 'm3te_pac_prev_'.$tag);
            $ok = BasicBlockHelper::append($context, 'm3te_pac_sidecar_'.$tag);
            $merge = BasicBlockHelper::append($context, 'm3te_pac_done_'.$tag);
            $context->builder->branchIf($matches, $ok, $fail);
            $context->builder->positionAtEnd($fail);
            $context->builder->branch($merge);
            $context->builder->positionAtEnd($ok);
            $sidecarBlock = $context->builder->call($context->functions[$entry['sentinelLc']]);
            $context->builder->branch($merge);
            $context->builder->positionAtEnd($merge);
            $phi = $context->builder->phi($objPtr);
            $phi->addIncoming($current, $fail);
            $phi->addIncoming($sidecarBlock, $ok);
            $current = $phi;
        }

        return $current;
    }

    /** Runtime::standalone native for emit-helper SPINE — copy matched sidecar to outfile. */
    public static function emitStandaloneWriteCachedAot(Context $context, Value $block, Value $outFile): void
    {
        unset($block);
        if (!self::isRegistered($context)) {
            $context->builder->returnVoid();

            return;
        }
        $entry = $context->m3EmitTuLinktimeSidecarEntries[array_key_last($context->m3EmitTuLinktimeSidecarEntries)];
        $sidecarPath = $context->builder->load($entry['sidecarGlobal']);
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
