<?php

declare(strict_types=1);

namespace PHPCompiler\JIT;

use PHPCompiler\JIT\Builtin\StringFsDir;
use PHPCompiler\JIT\Builtin\StringGetenv;
use PHPLLVM\Builder;
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

    private const COMPILE_SMOKE_SENTINEL_LOGICAL = 'PHPCompiler\\JIT\\M3EmitTuTrivialEchoAot::compileSmokeSentinelBlock';

    private const COMPILER_UNIT_PROBE_SENTINEL_LOGICAL = 'PHPCompiler\\JIT\\M3EmitTuTrivialEchoAot::compilerUnitProbeSentinelBlock';

    private const JIT_UNIT_PROBE_SENTINEL_LOGICAL = 'PHPCompiler\\JIT\\M3EmitTuTrivialEchoAot::jitUnitProbeSentinelBlock';

    private const JIT_UNIT_PROBE_COMPILE_DRIVER_SENTINEL_LOGICAL = 'PHPCompiler\\JIT\\M3EmitTuTrivialEchoAot::jitUnitProbeCompileDriverSentinelBlock';

    private const COMPILER_LIB_SENTINEL_LOGICAL = 'PHPCompiler\\JIT\\M3EmitTuTrivialEchoAot::compilerLibSentinelBlock';

    private const COMPILE_DRIVER_SENTINEL_LOGICAL = 'PHPCompiler\\JIT\\M3EmitTuTrivialEchoAot::compileDriverSentinelBlock';

    private const HELLOWORLD_SMOKE_MAIN_SENTINEL_LOGICAL = 'PHPCompiler\\JIT\\M3EmitTuTrivialEchoAot::helloworldSmokeMainSentinelBlock';

    private const BOOTSTRAP_LOOP_SMOKE_MAIN_SENTINEL_LOGICAL = 'PHPCompiler\\JIT\\M3EmitTuTrivialEchoAot::bootstrapLoopSmokeMainSentinelBlock';

    public const TRIVIAL_ECHO_SIDECAR_REL = 'build/.m3_trivial_echo_aot_blob';

    public const HELLOWORLD_SIDECAR_REL = 'build/.m3_helloworld_aot_blob';

    public const COMPILE_SMOKE_SIDECAR_REL = 'build/.m3_compile_smoke_aot_blob';

    public const COMPILER_UNIT_PROBE_SIDECAR_REL = 'build/.m3_compiler_unit_probe_aot_blob';

    public const JIT_UNIT_PROBE_SIDECAR_REL = 'build/.m3_jit_unit_probe_aot_blob';

    public const JIT_UNIT_PROBE_COMPILE_DRIVER_SIDECAR_REL = 'build/.m3_jit_unit_probe_compile_driver_aot_blob';

    public const COMPILER_LIB_SIDECAR_REL = 'build/.m3_compiler_lib_aot_blob';

    public const COMPILER_LIB_SOURCE_PATH_NORM = 'test/selfhost/compiler_lib_spine_smoke/main.php';

    public const COMPILER_MINIMAL_SIDECAR_REL = 'build/.m3_compiler_minimal_aot_blob';

    public const COMPILE_DRIVER_SIDECAR_REL = 'build/.m3_compile_driver_aot_blob';

    public const HELLOWORLD_SMOKE_MAIN_SIDECAR_REL = 'build/.m3_helloworld_smoke_main_aot_blob';

    public const BOOTSTRAP_LOOP_SMOKE_MAIN_SIDECAR_REL = 'build/.m3_bootstrap_loop_smoke_main_aot_blob';

    public const COMPILER_PHP_SIDECAR_REL = 'build/.m3_compiler_php_aot_blob';

    public const BIN_COMPILE_SIDECAR_REL = 'build/.m3_bin_compile_aot_blob';

    public const BIN_VM_SIDECAR_REL = 'build/.m3_bin_vm_aot_blob';

    public const CLI_DRIVER_SIDECAR_REL = 'build/.m3_cli_driver_aot_blob';

    public const VENDOR_PHP_CFG_SIDECAR_REL = 'build/.m3_vendor_php_cfg_prelink.o';

    public const VENDOR_PHP_TYPES_SIDECAR_REL = 'build/.m3_vendor_php_types_prelink.o';

    public const VENDOR_PHP_LLVM_SIDECAR_REL = 'build/.m3_vendor_php_llvm_prelink.o';

    /** @var list<string> */
    private static array $registeredSidecarRels = [];

    public static function sidecarPath(string $repoRoot, string $sidecarRel = self::TRIVIAL_ECHO_SIDECAR_REL): string
    {
        return $repoRoot.'/'.$sidecarRel;
    }

    /**
     * Stable repo-relative path keys for link-time sidecar entries (#3046, #2967).
     *
     * Inventory argv drivers pass realpath() absolutes to parseAndCompile; content-hash sidecars
     * go stale when bundled entrypoints change, so path keys must match both forms.
     */
    public static function normalizeSidecarSourcePath(string $path): ?string
    {
        $norm = str_replace('\\', '/', $path);
        if (str_ends_with($norm, '/bin/vm.php')) {
            $resolved = realpath($path);

            return false !== $resolved ? str_replace('\\', '/', $resolved) : $norm;
        }
        if (str_ends_with($norm, '/'.self::COMPILER_LIB_SOURCE_PATH_NORM)) {
            return self::COMPILER_LIB_SOURCE_PATH_NORM;
        }
        if (str_ends_with($norm, '/test/selfhost/compiler_minimal/main.php')) {
            return 'test/selfhost/compiler_minimal/main.php';
        }
        if (str_ends_with($norm, '/test/selfhost/compiler_helloworld_smoke/main.php')) {
            return 'test/selfhost/compiler_helloworld_smoke/main.php';
        }
        if (str_ends_with($norm, '/test/selfhost/bootstrap_loop_smoke/main.php')) {
            return 'test/selfhost/bootstrap_loop_smoke/main.php';
        }
        if (str_ends_with($norm, '/bin/compile.php')) {
            return 'bin/compile.php';
        }

        return null;
    }

    public static function sidecarSourcePathMatches(string $filename, string $pathKey): bool
    {
        $filenameNorm = str_replace('\\', '/', $filename);
        $keyNorm = str_replace('\\', '/', $pathKey);
        if ($filenameNorm === $keyNorm) {
            return true;
        }
        $suffix = '/'.$keyNorm;

        return str_ends_with($filenameNorm, $suffix);
    }

    public static function compilerLibSpineEntryPath(string $repoRoot): string
    {
        return $repoRoot.'/test/selfhost/compiler_lib_spine_smoke/main.php';
    }

    public static function compilerLibSpineEntrySha(string $repoRoot): ?string
    {
        $entry = self::compilerLibSpineEntryPath($repoRoot);
        if (!is_readable($entry)) {
            return null;
        }
        $sha = @sha1_file($entry);

        return is_string($sha) && '' !== $sha ? $sha : null;
    }

    public static function compilerLibSidecarStampMatches(string $repoRoot, string $stampPath): bool
    {
        $want = self::compilerLibSpineEntrySha($repoRoot);
        if (null === $want || !is_readable($stampPath)) {
            return false;
        }
        $have = trim((string) file_get_contents($stampPath));

        return $have === $want;
    }

    public static function isCompilerLibSidecarRel(string $sidecarRel): bool
    {
        return self::COMPILER_LIB_SIDECAR_REL === $sidecarRel;
    }

    public static function isCompilerLibSourcePathNorm(?string $sourcePathNorm): bool
    {
        return self::COMPILER_LIB_SOURCE_PATH_NORM === $sourcePathNorm;
    }

    public static function registerLinktime(
        Context $context,
        string $repoRoot,
        string $source,
        string $aotBytes,
        string $sidecarRel = self::TRIVIAL_ECHO_SIDECAR_REL,
        ?string $sentinelLogical = null,
        bool $objectOnlySidecar = false,
        ?string $sourcePathNorm = null
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

        if (null === $sentinelLogical) {
            $sentinelLogical = match ($sidecarRel) {
                self::TRIVIAL_ECHO_SIDECAR_REL => self::SENTINEL_LOGICAL,
                self::COMPILE_SMOKE_SIDECAR_REL => self::COMPILE_SMOKE_SENTINEL_LOGICAL,
                self::COMPILER_UNIT_PROBE_SIDECAR_REL => self::COMPILER_UNIT_PROBE_SENTINEL_LOGICAL,
                self::JIT_UNIT_PROBE_SIDECAR_REL => self::JIT_UNIT_PROBE_SENTINEL_LOGICAL,
                self::JIT_UNIT_PROBE_COMPILE_DRIVER_SIDECAR_REL => self::JIT_UNIT_PROBE_COMPILE_DRIVER_SENTINEL_LOGICAL,
                self::COMPILER_LIB_SIDECAR_REL => self::COMPILER_LIB_SENTINEL_LOGICAL,
                self::COMPILER_MINIMAL_SIDECAR_REL => 'PHPCompiler\\JIT\\M3EmitTuTrivialEchoAot::compilerMinimalSentinelBlock',
                self::COMPILE_DRIVER_SIDECAR_REL => self::COMPILE_DRIVER_SENTINEL_LOGICAL,
                self::HELLOWORLD_SMOKE_MAIN_SIDECAR_REL => self::HELLOWORLD_SMOKE_MAIN_SENTINEL_LOGICAL,
                self::BOOTSTRAP_LOOP_SMOKE_MAIN_SIDECAR_REL => self::BOOTSTRAP_LOOP_SMOKE_MAIN_SENTINEL_LOGICAL,
                self::COMPILER_PHP_SIDECAR_REL => 'PHPCompiler\\JIT\\M3EmitTuTrivialEchoAot::compilerPhpSentinelBlock',
                self::BIN_COMPILE_SIDECAR_REL => 'PHPCompiler\\JIT\\M3EmitTuTrivialEchoAot::binCompileSentinelBlock',
                self::BIN_VM_SIDECAR_REL => 'PHPCompiler\\JIT\\M3EmitTuTrivialEchoAot::binVmSentinelBlock',
                self::CLI_DRIVER_SIDECAR_REL => 'PHPCompiler\\JIT\\M3EmitTuTrivialEchoAot::cliDriverSentinelBlock',
                self::VENDOR_PHP_CFG_SIDECAR_REL => 'PHPCompiler\\JIT\\M3EmitTuTrivialEchoAot::vendorPhpCfgSentinelBlock',
                self::VENDOR_PHP_TYPES_SIDECAR_REL => 'PHPCompiler\\JIT\\M3EmitTuTrivialEchoAot::vendorPhpTypesSentinelBlock',
                self::VENDOR_PHP_LLVM_SIDECAR_REL => 'PHPCompiler\\JIT\\M3EmitTuTrivialEchoAot::vendorPhpLlvmSentinelBlock',
                default => self::HELLOWORLD_SENTINEL_LOGICAL,
            };
        }

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

        $sourcePathGlobal = null;
        if (is_string($sourcePathNorm) && '' !== $sourcePathNorm) {
            $sourcePathGlobal = $context->constantStringFromString($sourcePathNorm);
        }
        $context->m3EmitTuLinktimeSidecarEntries[] = [
            'sourceGlobal' => $sourceGlobal,
            'sidecarGlobal' => $sidecarGlobal,
            'sentinelLc' => $sentinelLc,
            'objectOnly' => $objectOnlySidecar,
            'sourcePathGlobal' => $sourcePathGlobal,
            'contentMatchOnly' => self::isCompilerLibSidecarRel($sidecarRel) && !$objectOnlySidecar,
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
        $entries = array_reverse($context->m3EmitTuLinktimeSidecarEntries);
        $merge = BasicBlockHelper::append($context, 'm3te_pac_merge');
        $defaultBb = BasicBlockHelper::append($context, 'm3te_pac_default');

        /** @var list<array{Value,\PHPLLVM\BasicBlock}> $incoming */
        $incoming = [];

        // Avoid calling $defaultEmit eagerly: the trivial sources are common and $defaultEmit may
        // exercise heavy compiler paths (and historically can trip LLVM 9 emit-TU runtime init).
        foreach ($entries as $index => $entry) {
            $tag = 'e'.(string) $index;
            $cached = $context->builder->load($entry['sourceGlobal']);
            $contentMatch = JitStringCompare::identical($context, $code, $cached);
            $pathMatch = null;
            if (isset($entry['sourcePathGlobal']) && null !== $entry['sourcePathGlobal']) {
                $pathKey = $context->builder->load($entry['sourcePathGlobal']);
                $pathExact = JitStringCompare::identical($context, $filename, $pathKey);
                $pathSuffix = JitStringCompare::suffixIdentical($context, $filename, $pathKey);
                $pathMatch = $context->builder->or($pathExact, $pathSuffix);
            }
            $matches = $contentMatch;
            if (null !== $pathMatch && empty($entry['contentMatchOnly'])) {
                // compiler_lib spine: path is stable but entry body changes — path-only match
                // must not resurrect a stale sidecar when the SHA stamp is behind (#2201, #8559).
                $matches = $context->builder->or($contentMatch, $pathMatch);
            }
            $ok = BasicBlockHelper::append($context, 'm3te_pac_sidecar_'.$tag);
            $fail = BasicBlockHelper::append($context, 'm3te_pac_next_'.$tag);
            $context->builder->branchIf($matches, $ok, $fail);

            $context->builder->positionAtEnd($ok);
            $sidecarBlock = $context->builder->call($context->functions[$entry['sentinelLc']]);
            $context->builder->branch($merge);
            $incoming[] = [$sidecarBlock, $ok];

            $context->builder->positionAtEnd($fail);
        }

        // No sidecar matched — fall back to the real parse+compile path.
        $context->builder->branch($defaultBb);
        $context->builder->positionAtEnd($defaultBb);
        $default = $defaultEmit($context, $runtimeThis, $code, $filename);
        $defaultTail = $context->builder->getInsertBlock();
        $context->builder->branch($merge);
        $incoming[] = [$default, $defaultTail];

        $context->builder->positionAtEnd($merge);
        $phi = $context->builder->phi($objPtr);
        foreach ($incoming as [$val, $bb]) {
            $phi->addIncoming($val, $bb);
        }

        return $phi;
    }

    /** Ensure __compiler_copy + resolve sidecar ABIs before standalone stub builder swap (#21417). */
    public static function ensureSidecarCopyAbisForLink(Context $context): void
    {
        $savedInsert = BasicBlockHelper::tryGetInsertBlock($context);
        StringFsDir::ensureLinked($context);
        if (null !== $savedInsert) {
            BasicBlockHelper::restoreInsertBlock($context, $savedInsert);
        } else {
            $context->builder->clearInsertionPosition();
        }
    }

    /**
     * Runtime::standalone dispatch: sidecar copy unless PHP_COMPILER_KEEP_OBJECT_FILE=1 and no
     * sentinel match — then call real standalone lowering (vendor prelink .o — #3036).
     */
    public static function emitStandaloneWithKeepObjectDispatch(
        Context $context,
        Value $runtime,
        Value $block,
        Value $outFile,
        Value $realStandaloneFn
    ): void {
        StringGetenv::ensureLibcGetenv($context);
        $charPtr = $context->getTypeFromString('char*');
        $i8p = $context->getTypeFromString('int8*');
        $strPtr = $context->getTypeFromString('__string__*');
        $keepKey = $context->builder->pointerCast(
            $context->constantFromString('PHP_COMPILER_KEEP_OBJECT_FILE'),
            $charPtr
        );
        $vendorKey = $context->builder->pointerCast(
            $context->constantFromString('PHP_COMPILER_VENDOR_PRELINK'),
            $charPtr
        );
        $keepEnv = $context->builder->call($context->lookupFunction('getenv'), $keepKey);
        $vendorEnv = $context->builder->call($context->lookupFunction('getenv'), $vendorKey);
        $useReal = self::envIsTruthy($context, $keepEnv, $i8p, $charPtr);
        $useReal = $context->builder->or($useReal, self::envIsTruthy($context, $vendorEnv, $i8p, $charPtr));
        $sidecarBb = BasicBlockHelper::append($context, 'm3te_std_sidecar_path');
        $realBb = BasicBlockHelper::append($context, 'm3te_std_keepobject_real');
        $context->builder->branchIf($useReal, $realBb, $sidecarBb);
        $context->builder->positionAtEnd($sidecarBb);
        if (self::isRegistered($context)) {
            self::emitStandaloneWriteCachedAot($context, $block, $outFile);
        } else {
            $context->builder->returnVoid();
        }
        $context->builder->positionAtEnd($realBb);
        $nullStr = $strPtr->constNull();
        $context->builder->call($realStandaloneFn, $runtime, $block, $outFile, $nullStr, $nullStr);
        $context->builder->returnVoid();
    }

    private static function envIsTruthy(Context $context, Value $env, Value $i8p, Value $charPtr): Value
    {
        $envNull = $context->builder->icmp(Builder::INT_EQ, $env, $i8p->constNull());
        $checkBb = BasicBlockHelper::append($context, 'm3te_env_chk');
        $falseBb = BasicBlockHelper::append($context, 'm3te_env_false');
        $mergeBb = BasicBlockHelper::append($context, 'm3te_env_done');
        $context->builder->branchIf($envNull, $falseBb, $checkBb);
        $context->builder->positionAtEnd($checkBb);
        $first = $context->builder->load($env);
        $isOne = $context->builder->icmp(Builder::INT_EQ, $first, $charPtr->constInt(ord('1'), false));
        $context->builder->branch($mergeBb);
        $context->builder->positionAtEnd($falseBb);
        $context->builder->branch($mergeBb);
        $context->builder->positionAtEnd($mergeBb);
        $phi = $context->builder->phi($context->getTypeFromString('int1'));
        $phi->addIncoming($context->getTypeFromString('int1')->constInt(0, false), $falseBb);
        $phi->addIncoming($isOne, $checkBb);

        return $phi;
    }

    /** Runtime::standalone native for emit-helper SPINE — copy matched sidecar to outfile. */
    public static function emitStandaloneWriteCachedAot(Context $context, Value $block, Value $outFile): void
    {
        // M5 gen-0 never-seen echo: C-floor sentinel → cc-built ELF (#26756).
        if (M5TrivialEchoNative::isRegistered($context)) {
            [$handled, $merge] = M5TrivialEchoNative::emitStandaloneSentinelCheck(
                $context,
                $block,
                $outFile,
                'cached'
            );
            $cont = BasicBlockHelper::append($context, 'm5_te_cached_cont');
            $done = BasicBlockHelper::append($context, 'm5_te_cached_done');
            $context->builder->positionAtEnd($merge);
            $context->builder->branchIf($handled, $done, $cont);
            $context->builder->positionAtEnd($done);
            $context->builder->returnVoid();
            $context->builder->positionAtEnd($cont);
        }
        if (!self::isRegistered($context)) {
            $context->builder->returnVoid();

            return;
        }
        // Sidecar copy lowering calls __compiler_resolve_sidecar_source_path + __compiler_copy (#6982).
        // ABIs are pre-linked in {@see ensureSidecarCopyAbisForLink} before stub builder swap (#21417).
        $objPtr = $context->getTypeFromString('__object__*');
        $strPtr = $context->getTypeFromString('__string__*');
        $i64 = $context->getTypeFromString('int64');
        $lastEntry = $context->m3EmitTuLinktimeSidecarEntries[array_key_last($context->m3EmitTuLinktimeSidecarEntries)];
        $currentSidecarPath = $context->builder->load($lastEntry['sidecarGlobal']);
        foreach (array_reverse($context->m3EmitTuLinktimeSidecarEntries) as $index => $entry) {
            $tag = 's'.(string) $index;
            $sidecarPath = $context->builder->load($entry['sidecarGlobal']);
            $sentinelBlock = $context->builder->pointerCast($sidecarPath, $objPtr);
            $matches = $context->builder->icmp(
                Builder::INT_EQ,
                $context->builder->ptrtoint($block, $i64),
                $context->builder->ptrtoint($sentinelBlock, $i64)
            );
            $fail = BasicBlockHelper::append($context, 'm3te_std_prev_'.$tag);
            $ok = BasicBlockHelper::append($context, 'm3te_std_sidecar_'.$tag);
            $merge = BasicBlockHelper::append($context, 'm3te_std_done_'.$tag);
            $context->builder->branchIf($matches, $ok, $fail);
            $context->builder->positionAtEnd($fail);
            $context->builder->branch($merge);
            $context->builder->positionAtEnd($ok);
            $matchedSidecarPath = $sidecarPath;
            $context->builder->branch($merge);
            $context->builder->positionAtEnd($merge);
            $phi = $context->builder->phi($strPtr);
            $phi->addIncoming($currentSidecarPath, $fail);
            $phi->addIncoming($matchedSidecarPath, $ok);
            $currentSidecarPath = $phi;
        }
        $resolvedSidecar = $context->builder->call(
            $context->lookupFunction('__compiler_resolve_sidecar_source_path'),
            $currentSidecarPath
        );
        $copyOk = $context->builder->call(
            $context->lookupFunction('__compiler_copy'),
            $resolvedSidecar,
            $outFile
        );
        $i32 = $context->getTypeFromString('int32');
        $copyFailed = $context->builder->icmp(
            Builder::INT_EQ,
            $copyOk,
            $i32->constInt(0, false)
        );
        $copyFailBb = BasicBlockHelper::append($context, 'm3te_std_copy_fail');
        $copyDoneBb = BasicBlockHelper::append($context, 'm3te_std_copy_done');
        $context->builder->branchIf($copyFailed, $copyFailBb, $copyDoneBb);
        $context->builder->positionAtEnd($copyFailBb);
        $context->builder->call(
            $context->lookupFunction('exit'),
            $i32->constInt(1, false)
        );
        $context->builder->returnVoid();
        $context->builder->positionAtEnd($copyDoneBb);
        $context->builder->returnVoid();
    }

    private static function mangle(string $logical): string
    {
        return preg_replace('/[^a-zA-Z0-9_]/', '_', $logical) ?? $logical;
    }
}
