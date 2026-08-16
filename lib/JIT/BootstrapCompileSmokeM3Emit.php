<?php

declare(strict_types=1);

namespace PHPCompiler\JIT;

require_once __DIR__.'/EmitTuMode.php';
require_once __DIR__.'/RuntimeEmitTuAlloc.php';
require_once __DIR__.'/RuntimeEmitTuInit.php';

use PHPCompiler\ext\standard\JitStringSearch;
use PHPCompiler\JIT\Builtin\StringGetenv;
use PHPLLVM\Builder;
use PHPLLVM\Value;

/**
 * Thin native LLVM bridge for BootstrapAot\compile_smoke_m3_emit (#1983, approach 3).
 *
 * Calls already-linked Runtime spine symbols instead of full PHP CFG lowering.
 */
final class BootstrapCompileSmokeM3Emit
{
    private const MODE_AOT = 2;

    private static int $seq = 0;

    /** @var array<string, true> */
    private static array $runtimeSpineStubbed = [];

    /** M3 emit TU {main}: argv `-o OUT SOURCE` (preferred) or env PHP_COMPILER_M3_* (#1937, #2697, #2866). */
    public static function emitMainEntry(Context $context, string $logPrefix): void
    {
        self::emitEnsureRepoRootEnvIfUnset($context);
        $i64 = $context->getTypeFromString('int64');
        $strPtr = $context->getTypeFromString('__string__*');
        $retFail = $i64->constInt(1, false);
        $sourceFile = self::getenvAsPhpcString($context, 'PHP_COMPILER_M3_SOURCE');
        $outFile = self::getenvAsPhpcString($context, 'PHP_COMPILER_M3_OUT');
        $srcNull = $context->builder->icmp(Builder::INT_EQ, $sourceFile, $strPtr->constNull());
        $outNull = $context->builder->icmp(Builder::INT_EQ, $outFile, $strPtr->constNull());
        $envBad = $context->builder->or($srcNull, $outNull);
        $envOk = BasicBlockHelper::append($context, 'csm3_env_ok');
        $envTryArgv = BasicBlockHelper::append($context, 'csm3_try_argv');
        $context->builder->branchIf($envBad, $envTryArgv, $envOk);
        $context->builder->positionAtEnd($envOk);
        self::emit($context, $sourceFile, $outFile, $logPrefix);

        $context->builder->positionAtEnd($envTryArgv);
        self::emitArgvOrFail($context, $logPrefix, $retFail);
    }

    private static function emitArgvOrFail(Context $context, string $logPrefix, Value $retFail): void
    {
        $i32 = $context->getTypeFromString('int32');
        $i64 = $context->getTypeFromString('int64');
        $strPtr = $context->getTypeFromString('__string__*');
        $argc = $context->builder->call($context->lookupFunction('__phpc_cli_argc'));
        // Support:
        // - `DRIVER -o OUT SOURCE.php` (argc >= 4)
        // - `DRIVER -l SOURCE.php` (argc >= 3) for lint parity with bin/compile.php (#2957).
        $argcOk = $context->builder->icmp(Builder::INT_SGE, $argc, $i64->constInt(3, false));
        $argvOk = BasicBlockHelper::append($context, 'csm3_argv_parse');
        $argvFail = BasicBlockHelper::append($context, 'csm3_argv_fail');
        $context->builder->branchIf($argcOk, $argvOk, $argvFail);

        $context->builder->positionAtEnd($argvOk);
        $flagCstr = $context->builder->call($context->lookupFunction('__phpc_cli_argv_cstr'), $i32->constInt(1, false));
        $isMinusO = self::cstrEqualsLiteral($context, $flagCstr, '-o');
        $isMinusL = self::cstrEqualsLiteral($context, $flagCstr, '-l');
        $i8p = $context->getTypeFromString('int8*');
        $argvEmit = BasicBlockHelper::append($context, 'csm3_argv_emit');
        $argvLint = BasicBlockHelper::append($context, 'csm3_argv_lint');
        $argvWhichFail = BasicBlockHelper::append($context, 'csm3_argv_which_fail');
        $context->builder->branchIf($isMinusO, $argvEmit, $argvWhichFail);
        $context->builder->positionAtEnd($argvWhichFail);
        $context->builder->branchIf($isMinusL, $argvLint, $argvFail);

        $context->builder->positionAtEnd($argvLint);
        $srcLintCstr = $context->builder->call($context->lookupFunction('__phpc_cli_argv_cstr'), $i32->constInt(2, false));
        $srcLintFile = self::cstrAsPhpcString($context, $srcLintCstr);
        $srcLintNull = $context->builder->icmp(Builder::INT_EQ, $srcLintCstr, $i8p->constNull());
        $srcLintPhpcNull = $context->builder->icmp(Builder::INT_EQ, $srcLintFile, $strPtr->constNull());
        $lintBad = $context->builder->or($srcLintNull, $srcLintPhpcNull);
        $lintOk = BasicBlockHelper::append($context, 'csm3_lint_ok');
        $lintFail = BasicBlockHelper::append($context, 'csm3_lint_fail');
        $context->builder->branchIf($lintBad, $lintFail, $lintOk);
        $context->builder->positionAtEnd($lintOk);
        self::emitLint($context, $srcLintFile, $logPrefix);
        $context->builder->positionAtEnd($lintFail);
        self::echoPhaseError(
            $context,
            $logPrefix,
            $logPrefix.': lint usage: DRIVER -l SOURCE.php',
            'argv'
        );
        $context->builder->call(
            $context->lookupFunction('exit'),
            $context->builder->trunc($retFail, $i32)
        );

        $context->builder->positionAtEnd($argvEmit);
        $outCstr = $context->builder->call($context->lookupFunction('__phpc_cli_argv_cstr'), $i32->constInt(2, false));
        $srcCstr = $context->builder->call($context->lookupFunction('__phpc_cli_argv_cstr'), $i32->constInt(3, false));
        $outFile = self::cstrAsPhpcString($context, $outCstr);
        $sourceFile = self::cstrAsPhpcString($context, $srcCstr);
        $outNull = $context->builder->icmp(Builder::INT_EQ, $outCstr, $i8p->constNull());
        $srcNull = $context->builder->icmp(Builder::INT_EQ, $srcCstr, $i8p->constNull());
        $outPhpcNull = $context->builder->icmp(Builder::INT_EQ, $outFile, $strPtr->constNull());
        $srcPhpcNull = $context->builder->icmp(Builder::INT_EQ, $sourceFile, $strPtr->constNull());
        $argvBad = $context->builder->or($outNull, $srcNull);
        $argvBad = $context->builder->or($argvBad, $outPhpcNull);
        $argvBad = $context->builder->or($argvBad, $srcPhpcNull);
        $argvEmitOk = BasicBlockHelper::append($context, 'csm3_argv_emit_ok');
        $context->builder->branchIf($argvBad, $argvFail, $argvEmitOk);
        $context->builder->positionAtEnd($argvEmitOk);
        self::emit($context, $sourceFile, $outFile, $logPrefix);

        $context->builder->positionAtEnd($argvFail);
        self::echoPhaseError(
            $context,
            $logPrefix,
            $logPrefix.': set PHP_COMPILER_M3_SOURCE and PHP_COMPILER_M3_OUT, or run: DRIVER -o OUT SOURCE.php',
            'env'
        );
        $context->builder->call(
            $context->lookupFunction('exit'),
            $context->builder->trunc($retFail, $i32)
        );
    }

    /** Lint path for compiled driver: parseAndCompile only, no standalone emit (#2957). */
    private static function emitLint(Context $context, Value $sourceFile, string $logPrefix = 'compile_smoke_m3_emit'): void
    {
        $tag = 'csm3l'.(string) ++self::$seq;
        $i64 = $context->getTypeFromString('int64');
        $strPtr = $context->getTypeFromString('__string__*');
        $objPtr = $context->getTypeFromString('__object__*');
        $retOk = $i64->constInt(0, false);
        $retFail = $i64->constInt(1, false);

        [$code, $readOk] = self::emitSourceReadOrFail(
            $context,
            $sourceFile,
            'csm3_lint_read_'.$tag,
            $logPrefix.': empty source (lint)',
            $logPrefix,
            $retFail
        );
        $context->builder->positionAtEnd($readOk);
        $runtime = RuntimeEmitTuAlloc::emit($context);
        $mode = $i64->constInt(self::MODE_AOT, false);
        RuntimeEmitTuInit::emitInitSequence($context, $runtime, $mode);
        $block = M3EmitTuTrivialEchoAot::emitParseAndCompileWithTrivialFallback(
            $context,
            $runtime,
            $code,
            $sourceFile,
            [self::class, 'emitRuntimeParseAndCompileDefault']
        );
        $blockNull = $context->builder->icmp(Builder::INT_EQ, $block, $objPtr->constNull());
        $lintFail = BasicBlockHelper::append($context, 'csm3_lint_pac_fail_'.$tag);
        $lintOk = BasicBlockHelper::append($context, 'csm3_lint_pac_ok_'.$tag);
        $context->builder->branchIf($blockNull, $lintFail, $lintOk);

        $context->builder->positionAtEnd($lintFail);
        self::echoPhaseError(
            $context,
            $logPrefix,
            $logPrefix.': lint failed (parseAndCompile returned null)',
            'parseAndCompile',
            true
        );
        self::exitWithStatus($context, $retFail);

        $context->builder->positionAtEnd($lintOk);
        $context->builder->returnValue($retOk);
    }

    private static function cstrEqualsLiteral(Context $context, Value $cstr, string $literal): Value
    {
        $charPtr = $context->getTypeFromString('char*');
        $i32 = $context->getTypeFromString('int32');
        $literalCstr = $context->builder->pointerCast($context->constantFromString($literal), $charPtr);
        $eq = $context->builder->call($context->lookupFunction('__phpc_cli_str_eq'), $cstr, $literalCstr);

        return $context->builder->icmp(Builder::INT_NE, $eq, $i32->constInt(0, false));
    }

    private static function cstrAsPhpcString(Context $context, Value $cstr): Value
    {
        $tag = 'csm3c'.(string) ++self::$seq;
        $i8p = $context->getTypeFromString('int8*');
        $i64 = $context->getTypeFromString('int64');
        $strPtr = $context->getTypeFromString('__string__*');
        $isNull = $context->builder->icmp(Builder::INT_EQ, $cstr, $i8p->constNull());
        $fail = BasicBlockHelper::append($context, 'csm3_cstr_fail_'.$tag);
        $ok = BasicBlockHelper::append($context, 'csm3_cstr_ok_'.$tag);
        $merge = BasicBlockHelper::append($context, 'csm3_cstr_done_'.$tag);
        $context->builder->branchIf($isNull, $fail, $ok);
        $context->builder->positionAtEnd($fail);
        $nullStr = $strPtr->constNull();
        $context->builder->branch($merge);
        $context->builder->positionAtEnd($ok);
        $len = $context->builder->call($context->lookupFunction('strlen'), $cstr);
        $phpcStr = $context->builder->call(
            $context->lookupFunction('__string__init'),
            $len,
            $cstr
        );
        $context->builder->branch($merge);
        $context->builder->positionAtEnd($merge);
        $phi = $context->builder->phi($strPtr);
        $phi->addIncoming($nullStr, $fail);
        $phi->addIncoming($phpcStr, $ok);

        return $phi;
    }

    private static function getenvAsPhpcString(Context $context, string $envKey): Value
    {
        StringGetenv::ensureLibcGetenv($context);
        $tag = 'g'.substr(md5($envKey), 0, 6);
        $i8p = $context->getTypeFromString('int8*');
        $i64 = $context->getTypeFromString('int64');
        $strPtr = $context->getTypeFromString('__string__*');
        $charPtr = $context->getTypeFromString('char*');
        $cstr = $context->builder->pointerCast($context->constantFromString($envKey), $charPtr);
        $env = $context->builder->call($context->lookupFunction('getenv'), $cstr);
        $isNull = $context->builder->icmp(Builder::INT_EQ, $env, $i8p->constNull());
        $fail = BasicBlockHelper::append($context, 'csm3_genv_fail_'.$tag);
        $ok = BasicBlockHelper::append($context, 'csm3_genv_ok_'.$tag);
        $merge = BasicBlockHelper::append($context, 'csm3_genv_done_'.$tag);
        $context->builder->branchIf($isNull, $fail, $ok);
        $context->builder->positionAtEnd($fail);
        $nullStr = $strPtr->constNull();
        $context->builder->branch($merge);
        $context->builder->positionAtEnd($ok);
        $len = $context->builder->call($context->lookupFunction('strlen'), $env);
        $phpcStr = $context->builder->call(
            $context->lookupFunction('__string__init'),
            $len,
            $env
        );
        $context->builder->branch($merge);
        $context->builder->positionAtEnd($merge);
        $phi = $context->builder->phi($strPtr);
        $phi->addIncoming($nullStr, $fail);
        $phi->addIncoming($phpcStr, $ok);

        return $phi;
    }

    public static function emit(Context $context, Value $sourceFile, Value $outFile, string $logPrefix = 'compile_smoke_m3_emit'): void
    {
        $tag = 'csm3'.(string) ++self::$seq;
        $i64 = $context->getTypeFromString('int64');
        $sizeT = $context->getTypeFromString('size_t');
        $strPtr = $context->getTypeFromString('__string__*');
        $objPtr = $context->getTypeFromString('__object__*');
        $strMap = $context->structFieldMap['__string__'];
        $retOk = $i64->constInt(0, false);
        $retFail = $i64->constInt(1, false);

        [$code, $readOk] = self::emitSourceReadOrFail(
            $context,
            $sourceFile,
            'csm3_read_'.$tag,
            $logPrefix.': empty source (native bridge)',
            $logPrefix,
            $retFail
        );
        $context->builder->positionAtEnd($readOk);
        $runtime = RuntimeEmitTuAlloc::emit($context);
        $mode = $i64->constInt(self::MODE_AOT, false);
        if (self::shouldUseEmitTuRealLowering($context)) {
            RuntimeEmitTuInit::emitInitSequence($context, $runtime, $mode);
        } else {
            $context->builder->call(
                self::runtimeSpine($context, '__construct', 'void', ['__object__*', 'int64']),
                $runtime,
                $mode
            );
        }
        $block = M3EmitTuTrivialEchoAot::emitParseAndCompileWithTrivialFallback(
            $context,
            $runtime,
            $code,
            $sourceFile,
            [self::class, 'emitRuntimeParseAndCompileDefault']
        );
        $blockNull = $context->builder->icmp(Builder::INT_EQ, $block, $objPtr->constNull());
        $pacFail = BasicBlockHelper::append($context, 'csm3_pac_fail_'.$tag);
        $pacOk = BasicBlockHelper::append($context, 'csm3_pac_ok_'.$tag);
        $context->builder->branchIf($blockNull, $pacFail, $pacOk);

        $context->builder->positionAtEnd($pacFail);
        self::echoPhaseError(
            $context,
            $logPrefix,
            $logPrefix.': parseAndCompile returned null (parser/CFG spine)',
            'parseAndCompile',
            true
        );
        self::exitWithStatus($context, $retFail);

        $context->builder->positionAtEnd($pacOk);
        self::emitPutenvM3CompileDriverMainForBootstrapSelfhost($context, $sourceFile);
        $context->builder->call(
            self::runtimeSpine($context, 'standalone', 'void', ['__object__*', '__object__*', '__string__*']),
            $runtime,
            $block,
            $outFile
        );

        ValueEchoHelper::echoLiteral($context, $logPrefix.': compile OK -> ');
        $outLen = $context->builder->load($context->builder->structGep($outFile, $strMap['length']));
        $outChars = $context->builder->structGep($outFile, $strMap['value']);
        $context->builder->call(
            $context->lookupFunction('__phpc_ob_echo_substr'),
            $outChars,
            $context->builder->zExt($outLen, $sizeT)
        );
        ValueEchoHelper::echoLiteral($context, "\n");
        $context->builder->returnValue($retOk);
    }

    /**
     * Module-local putenv(3) after LibcExtern always-on drop (#31582).
     */
    private static function ensureLibcPutenv(Context $context): void
    {
        try {
            $context->lookupFunction('putenv');
        } catch (\Throwable) {
            $i8p = $context->getTypeFromString('int8*');
            $i32 = $context->getTypeFromString('int32');
            $fn = $context->module->addFunction(
                'putenv',
                $context->context->functionType($i32, false, $i8p)
            );
            $context->registerFunction('putenv', $fn);
        }
    }

    /** Default PHP_COMPILER_REPO_ROOT for gen-0 argv drivers when unset (#12486, #3046). */
    private static function emitEnsureRepoRootEnvIfUnset(Context $context): void
    {
        self::ensureLibcPutenv($context);
        StringGetenv::ensureLibcGetenv($context);
        $charPtr = $context->getTypeFromString('char*');
        $i8p = $context->getTypeFromString('int8*');
        $key = $context->builder->pointerCast(
            $context->constantFromString('PHP_COMPILER_REPO_ROOT'),
            $charPtr
        );
        $existing = $context->builder->call($context->lookupFunction('getenv'), $key);
        $isUnset = $context->builder->icmp(Builder::INT_EQ, $existing, $i8p->constNull());
        $setBb = BasicBlockHelper::append($context, 'csm3_repo_root_set');
        $skipBb = BasicBlockHelper::append($context, 'csm3_repo_root_skip');
        $doneBb = BasicBlockHelper::append($context, 'csm3_repo_root_done');
        $context->builder->branchIf($isUnset, $setBb, $skipBb);
        $context->builder->positionAtEnd($setBb);
        $bakedRoot = str_replace('\\', '/', dirname(__DIR__, 2));
        $context->builder->call(
            $context->lookupFunction('putenv'),
            $context->builder->pointerCast(
                $context->constantFromString('PHP_COMPILER_REPO_ROOT='.$bakedRoot),
                $charPtr
            )
        );
        $context->builder->branch($doneBb);
        $context->builder->positionAtEnd($skipBb);
        $context->builder->branch($doneBb);
        $context->builder->positionAtEnd($doneBb);
    }

    /**
     * Mirror {@see Context::isBootstrapNonSpineSelfhostEntry()} for stale gen-0 argv drivers (#11642, #12486).
     */
    private static function emitPutenvM3CompileDriverMainForBootstrapSelfhost(Context $context, Value $sourceFile): void
    {
        self::ensureLibcPutenv($context);
        BasicBlockHelper::ensureOpenInsertBlock($context, 'csm3_putenv_m3main_entry');
        $charPtr = $context->getTypeFromString('char*');
        $i32 = $context->getTypeFromString('int32');
        $notFound = $i32->constInt(JitStringSearch::NOT_FOUND, true);
        $selfhostNeedle = $context->builder->load(
            $context->constantStringFromString('/test/selfhost/')
        );
        $spineNeedle = $context->builder->load(
            $context->constantStringFromString('/test/selfhost/compiler_lib_spine_smoke/main.php')
        );
        $foundSelfhost = JitStringSearch::findOffsetI32($context, $sourceFile, $selfhostNeedle);
        $foundSpine = JitStringSearch::findOffsetI32($context, $sourceFile, $spineNeedle);
        $hasSelfhost = $context->builder->icmp(Builder::INT_NE, $foundSelfhost, $notFound);
        $isSpine = $context->builder->icmp(Builder::INT_NE, $foundSpine, $notFound);
        $shouldSet = $context->builder->and($hasSelfhost, $context->builder->not($isSpine));
        // JitVmHelperLink::ensureBridge (via findOffsetI32) may clear the LLVM insert block (#15597).
        BasicBlockHelper::ensureOpenInsertBlock($context, 'csm3_putenv_m3main_before_branch');
        $setBb = BasicBlockHelper::append($context, 'csm3_putenv_m3main_set');
        $skipBb = BasicBlockHelper::append($context, 'csm3_putenv_m3main_skip');
        $doneBb = BasicBlockHelper::append($context, 'csm3_putenv_m3main_done');
        $context->builder->branchIf($shouldSet, $setBb, $skipBb);
        $context->builder->positionAtEnd($setBb);
        $context->builder->call(
            $context->lookupFunction('putenv'),
            $context->builder->pointerCast(
                $context->constantFromString('PHP_COMPILER_M3_COMPILE_DRIVER_MAIN=1'),
                $charPtr
            )
        );
        $context->builder->branch($doneBb);
        $context->builder->positionAtEnd($skipBb);
        $context->builder->branch($doneBb);
        $context->builder->positionAtEnd($doneBb);
    }

    /**
     * Read source via __compiler_file_get_contents; fail before __string__strlen on null (#3046).
     *
     * @return array{Value, \PHPLLVM\BasicBlock} [$code, $readOkBlock]
     */
    private static function emitSourceReadOrFail(
        Context $context,
        Value $sourceFile,
        string $tagPrefix,
        string $emptyMessage,
        string $logPrefix,
        Value $retFail
    ): array {
        $i64 = $context->getTypeFromString('int64');
        $strPtr = $context->getTypeFromString('__string__*');

        $code = $context->builder->call(
            $context->lookupFunction('__compiler_file_get_contents'),
            $sourceFile
        );
        $codeNull = $context->builder->icmp(Builder::INT_EQ, $code, $strPtr->constNull());
        $readNullFail = BasicBlockHelper::append($context, $tagPrefix.'_null_fail');
        $readCheckLen = BasicBlockHelper::append($context, $tagPrefix.'_check_len');
        $context->builder->branchIf($codeNull, $readNullFail, $readCheckLen);

        $context->builder->positionAtEnd($readNullFail);
        self::echoPhaseError($context, $logPrefix, $emptyMessage, 'source');
        self::exitWithStatus($context, $retFail);

        $context->builder->positionAtEnd($readCheckLen);
        $codeLen = $context->builder->call($context->lookupFunction('__string__strlen'), $code);
        $codeEmpty = $context->builder->icmp(Builder::INT_EQ, $codeLen, $i64->constInt(0, false));
        $readOk = BasicBlockHelper::append($context, $tagPrefix.'_ok');
        $readEmptyFail = BasicBlockHelper::append($context, $tagPrefix.'_empty_fail');
        $context->builder->branchIf($codeEmpty, $readEmptyFail, $readOk);

        $context->builder->positionAtEnd($readEmptyFail);
        self::echoPhaseError($context, $logPrefix, $emptyMessage, 'source');
        self::exitWithStatus($context, $retFail);

        return [$code, $readOk];
    }

    private static function shouldUseEmitTuRealLowering(Context $context): bool
    {
        unset($context);
        $emitTu = getenv('PHP_COMPILER_M3_EMIT_TU');
        if ('1' === $emitTu || 'true' === strtolower((string) $emitTu)) {
            return true;
        }
        $inventoryEmit = getenv('PHP_COMPILER_M3_INVENTORY_EMIT');
        if ('1' === $inventoryEmit || 'true' === strtolower((string) $inventoryEmit)) {
            return true;
        }
        // Full M5 argv / gen-0 seed drivers must bake RuntimeEmitTuInit + real parseAndCompile
        // even though PHP_COMPILER_M3_COMPILE_DRIVER=1 (that flag alone selects stub spine for
        // helloworld inventory argv — #12036). Without this, functional smoke dies at
        // parseAndCompile null after source read succeeds (#26756 / re-#23468).
        $m5Host = getenv('PHP_COMPILER_M5_DRIVER_HOST');
        if ('1' === $m5Host || 'true' === strtolower((string) $m5Host)) {
            return true;
        }
        $m3Driver = getenv('PHP_COMPILER_M3_COMPILE_DRIVER');
        if ('1' === $m3Driver || 'true' === strtolower((string) $m3Driver)) {
            // Zend helloworld bin/compile.php inventory argv: thin ctor, stub spine (#12036).
            return false;
        }

        // Emit-helper binaries must init parse/compiler spine (#2633); env may be unset at runtime.
        return true;
    }

    private static function echoPhaseError(
        Context $context,
        string $logPrefix,
        string $line1,
        string $phase,
        bool $appendLastParseFailure = false
    ): void {
        ValueEchoHelper::echoLiteral($context, $line1);
        if ($appendLastParseFailure) {
            self::echoLastParseFailureSuffix($context);
        }
        ValueEchoHelper::echoLiteral($context, "\n");
        ValueEchoHelper::echoLiteral($context, $logPrefix.': native emit failed at phase='.$phase."\n");
    }

    /** Append ` — {detail}` from Runtime::getLastParseFailure when native parse+compile returns null (#3037). */
    private static function echoLastParseFailureSuffix(Context $context): void
    {
        $tag = 'lpf'.(string) ++self::$seq;
        $strPtr = $context->getTypeFromString('__string__*');
        $sizeT = $context->getTypeFromString('size_t');
        $strMap = $context->structFieldMap['__string__'];
        $runtime = RuntimeEmitTuAlloc::emit($context);
        $detail = $context->builder->call(
            self::runtimeSpine($context, 'peeklastparsefailure', '__string__*', ['__object__*']),
            $runtime
        );
        $detailNull = $context->builder->icmp(Builder::INT_EQ, $detail, $strPtr->constNull());
        $hasDetail = BasicBlockHelper::append($context, 'csm3_lpf_ok_'.$tag);
        $skipDetail = BasicBlockHelper::append($context, 'csm3_lpf_skip_'.$tag);
        $mergeDetail = BasicBlockHelper::append($context, 'csm3_lpf_done_'.$tag);
        $context->builder->branchIf($detailNull, $skipDetail, $hasDetail);
        $context->builder->positionAtEnd($hasDetail);
        ValueEchoHelper::echoLiteral($context, ' — ');
        $detailLen = $context->builder->load($context->builder->structGep($detail, $strMap['length']));
        $detailChars = $context->builder->structGep($detail, $strMap['value']);
        $context->builder->call(
            $context->lookupFunction('__phpc_ob_echo_substr'),
            $detailChars,
            $context->builder->zExt($detailLen, $sizeT)
        );
        $context->builder->branch($mergeDetail);
        $context->builder->positionAtEnd($skipDetail);
        $context->builder->branch($mergeDetail);
        $context->builder->positionAtEnd($mergeDetail);
    }

    /** Propagate failure to the process exit code (return from {main} alone is not honored by AOT link). */
    private static function exitWithStatus(Context $context, Value $retFail): void
    {
        $i32 = $context->getTypeFromString('int32');
        $context->builder->call(
            $context->lookupFunction('exit'),
            $context->builder->trunc($retFail, $i32)
        );
    }

    /**
     * @param list<string> $paramTypeNames
     */
    private static function mangleLogicalFunction(string $logical): string
    {
        return preg_replace('/[^a-zA-Z0-9_]/', '_', $logical) ?? $logical;
    }

    /**
     * Native Runtime::parseandcompile* for M3 emit TU (#2516).
     *
     * Mirrors parseAndCompileEmitSmoke: parse → compileEmitSmoke (no full Compiler::compile).
     */
    public static function emitRuntimeParseAndCompileNativeMethod(
        Context $context,
        Value $runtimeThis,
        Value $code,
        Value $filename
    ): Value {
        return M3EmitTuTrivialEchoAot::emitParseAndCompileWithTrivialFallback(
            $context,
            $runtimeThis,
            $code,
            $filename,
            [self::class, 'emitRuntimeParseAndCompileDefault']
        );
    }

    public static function emitRuntimeParseAndCompileDefault(
        Context $context,
        Value $runtimeThis,
        Value $code,
        Value $filename
    ): Value {
        $objPtr = $context->getTypeFromString('__object__*');
        // M5 argv / gen-0: trivial echo via C-floor (or NestedJIT) M5TrivialEchoScript (#26756).
        $trivialFn = M5TrivialEchoNative::lookup($context) ?? M5TrivialEchoScript::lookup($context);
        if (null !== $trivialFn) {
            $tag = 'te'.(string) ++self::$seq;
            $okBb = BasicBlockHelper::append($context, 'csm3_pac_trivial_ok_'.$tag);
            $missBb = BasicBlockHelper::append($context, 'csm3_pac_trivial_miss_'.$tag);
            $doneBb = BasicBlockHelper::append($context, 'csm3_pac_trivial_done_'.$tag);
            $block = $context->builder->call($trivialFn, $code, $filename);
            $isNull = $context->builder->icmp(Builder::INT_EQ, $block, $objPtr->constNull());
            $context->builder->branchIf($isNull, $missBb, $okBb);

            $context->builder->positionAtEnd($okBb);
            $context->builder->branch($doneBb);

            $context->builder->positionAtEnd($missBb);
            $fallback = self::emitRuntimeParseAndCompileDefaultFallback(
                $context,
                $runtimeThis,
                $code,
                $filename
            );
            $missTail = $context->builder->getInsertBlock();
            $context->builder->branch($doneBb);

            $context->builder->positionAtEnd($doneBb);
            $phi = $context->builder->phi($objPtr);
            $phi->addIncoming($block, $okBb);
            $phi->addIncoming($fallback, $missTail);

            return $phi;
        }

        return self::emitRuntimeParseAndCompileDefaultFallback(
            $context,
            $runtimeThis,
            $code,
            $filename
        );
    }

    /**
     * parse → compileEmitSmoke / compile fallback when trivial-echo path misses (#26756).
     */
    private static function emitRuntimeParseAndCompileDefaultFallback(
        Context $context,
        Value $runtimeThis,
        Value $code,
        Value $filename
    ): Value {
        $objPtr = $context->getTypeFromString('__object__*');
        $parseLc = strtolower('PHPCompiler\\Runtime::parse');
        if (!isset($context->functions[$parseLc])) {
            return $objPtr->constNull();
        }
        $parseFn = $context->functions[$parseLc];
        // Inventory argv driver prefers compileEmitSmoke; gen-0 bootstrap bundles need full compile (#3004, #1492).
        // Parse once, then try each compile spine in order until one returns non-null.
        $compileFns = [];
        foreach (['compileemitsmoke', 'compile'] as $compileMethod) {
            $compileLc = strtolower('PHPCompiler\\Runtime::'.$compileMethod);
            if (isset($context->functions[$compileLc])) {
                $compileFns[] = $context->functions[$compileLc];
            }
        }
        if ([] === $compileFns) {
            return $objPtr->constNull();
        }

        $tag = 'd'.(string) ++self::$seq;
        $parseFailBb = BasicBlockHelper::append($context, 'csm3_pac_default_parse_fail_'.$tag);
        $afterParseBb = BasicBlockHelper::append($context, 'csm3_pac_default_after_parse_'.$tag);
        $doneBb = BasicBlockHelper::append($context, 'csm3_pac_default_done_'.$tag);

        $script = $context->builder->call($parseFn, $runtimeThis, $code, $filename);
        $scriptNull = $context->builder->icmp(Builder::INT_EQ, $script, $objPtr->constNull());
        $context->builder->branchIf($scriptNull, $parseFailBb, $afterParseBb);

        $context->builder->positionAtEnd($parseFailBb);
        $context->builder->call(
            self::runtimeSpine($context, 'noteparsecompilenullforscript', 'void', ['__object__*', '__object__*']),
            $runtimeThis,
            $objPtr->constNull()
        );
        $context->builder->branch($doneBb);

        $currentBb = $afterParseBb;
        /** @var list<array{Value, \PHPLLVM\BasicBlock}> $successIncoming */
        $successIncoming = [];
        $allFailedBb = $afterParseBb;

        $compileCount = count($compileFns);
        foreach ($compileFns as $index => $compileFn) {
            $tryTag = $tag.'_c'.$index;
            $tryBb = $currentBb;
            $nextBb = BasicBlockHelper::append($context, 'csm3_pac_default_next_'.$tryTag);
            $successBb = BasicBlockHelper::append($context, 'csm3_pac_default_ok_'.$tryTag);
            $recordBb = BasicBlockHelper::append($context, 'csm3_pac_default_rec_'.$tryTag);

            $context->builder->positionAtEnd($tryBb);
            $block = $context->builder->call($compileFn, $runtimeThis, $script);
            $blockNull = $context->builder->icmp(Builder::INT_EQ, $block, $objPtr->constNull());
            $context->builder->branchIf($blockNull, $recordBb, $successBb);

            $context->builder->positionAtEnd($recordBb);
            if ($index === $compileCount - 1) {
                $context->builder->call(
                    self::runtimeSpine($context, 'noteparsecompilenullforscript', 'void', ['__object__*', '__object__*']),
                    $runtimeThis,
                    $script
                );
            }
            $context->builder->branch($nextBb);

            $context->builder->positionAtEnd($successBb);
            $context->builder->branch($doneBb);
            $successIncoming[] = [$block, $successBb];

            $currentBb = $nextBb;
            $allFailedBb = $nextBb;
        }

        $context->builder->positionAtEnd($allFailedBb);
        $context->builder->branch($doneBb);

        $context->builder->positionAtEnd($doneBb);
        $phi = $context->builder->phi($objPtr);
        $phi->addIncoming($objPtr->constNull(), $parseFailBb);
        foreach ($successIncoming as [$val, $bb]) {
            $phi->addIncoming($val, $bb);
        }
        if ($allFailedBb !== $afterParseBb) {
            $phi->addIncoming($objPtr->constNull(), $allFailedBb);
        }

        return $phi;
    }

    /**
     * Runtime::parseandcompile* via parse → compileEmitSmoke (no self-recursive bridge; #2967).
     *
     * @param 'parseandcompile'|'parseandcompileemitsmoke' $targetLc
     */
    public static function declareRuntimeParseAndCompileViaParseEmitSmoke(
        Context $context,
        string $internalName,
        string $logicalName,
        string $targetLc
    ): Value {
        $lc = strtolower($logicalName);
        if (isset($context->functions[$lc])) {
            return $context->functions[$lc];
        }
        $objPtr = $context->getTypeFromString('__object__*');
        $strPtr = $context->getTypeFromString('__string__*');
        $func = $context->module->addFunction(
            $internalName,
            $context->context->functionType($objPtr, false, $objPtr, $strPtr, $strPtr)
        );
        $bb = $func->appendBasicBlock('entry');
        $saved = $context->builder;
        $context->builder = $context->context->builderCreate();
        $context->builder->positionAtEnd($bb);
        $runtime = $func->getParam(0);
        $code = $func->getParam(1);
        $filename = $func->getParam(2);
        // M5 argv / gen-0: compileEmitSmoke is a 3-byte null stub (`xor eax,eax; ret`).
        // Prefer real Runtime::compile when present so parseAndCompile can succeed (#26756).
        $m5Host = getenv('PHP_COMPILER_M5_DRIVER_HOST');
        $preferCompile = '1' === $m5Host || 'true' === strtolower((string) $m5Host);
        if ($preferCompile) {
            $context->builder->returnValue(
                self::emitRuntimeParseAndCompileDefault($context, $runtime, $code, $filename)
            );
        } else {
            $script = $context->builder->call(
                self::runtimeSpine($context, 'parse', '__object__*', ['__object__*', '__string__*', '__string__*']),
                $runtime,
                $code,
                $filename
            );
            $block = $context->builder->call(
                self::runtimeSpine($context, 'compileemitsmoke', '__object__*', ['__object__*', '__object__*']),
                $runtime,
                $script
            );
            $context->builder->returnValue($block);
        }
        $context->builder->clearInsertionPosition();
        $context->builder = $saved;
        $context->functions[$lc] = $func;
        $context->functionReturnType[$lc] = '__object__*';
        $context->functionProxies[$lc] = new Call\Native(
            $func,
            $logicalName,
            [$objPtr, $strPtr, $strPtr],
            []
        );

        return $func;
    }

    /** @deprecated use declareRuntimeParseAndCompileViaParseEmitSmoke */
    public static function declareRuntimeParseAndCompileForward(
        Context $context,
        string $internalName,
        string $logicalName
    ): Value {
        return self::declareRuntimeParseAndCompileViaParseEmitSmoke(
            $context,
            $internalName,
            $logicalName,
            'parseandcompile'
        );
    }

    /** Register native LLVM for Runtime::parseandcompile / parseandcompileemitsmoke (#2516). */
    public static function declareRuntimeParseAndCompileNative(
        Context $context,
        string $internalName,
        string $logicalName
    ): Value {
        $lc = strtolower($logicalName);
        if (isset($context->functions[$lc])) {
            return $context->functions[$lc];
        }
        $objPtr = $context->getTypeFromString('__object__*');
        $strPtr = $context->getTypeFromString('__string__*');
        $func = $context->module->addFunction(
            $internalName,
            $context->context->functionType($objPtr, false, $objPtr, $strPtr, $strPtr)
        );
        $bb = $func->appendBasicBlock('entry');
        $saved = $context->builder;
        $context->builder = $context->context->builderCreate();
        $context->builder->positionAtEnd($bb);
        $block = self::emitRuntimeParseAndCompileNativeMethod(
            $context,
            $func->getParam(0),
            $func->getParam(1),
            $func->getParam(2)
        );
        $context->builder->returnValue($block);
        $context->builder->clearInsertionPosition();
        $context->builder = $saved;
        $context->functions[$lc] = $func;
        $context->functionReturnType[$lc] = '__object__*';
        $context->functionProxies[$lc] = new Call\Native(
            $func,
            $logicalName,
            [$objPtr, $strPtr, $strPtr],
            []
        );

        return $func;
    }

    private static function compilerSpine(
        Context $context,
        string $methodLc,
        string $returnTypeName,
        array $paramTypeNames
    ): Value {
        $logical = 'PHPCompiler\\Compiler::'.$methodLc;
        $lc = strtolower($logical);
        if (isset($context->functions[$lc])) {
            return $context->functions[$lc];
        }
        $mangled = self::mangleLogicalFunction($logical);
        $existing = $context->module->getNamedFunction($mangled);
        if (null !== $existing) {
            return $existing;
        }
        $params = [];
        foreach ($paramTypeNames as $typeName) {
            $params[] = $context->getTypeFromString($typeName);
        }

        return $context->module->addFunction(
            $mangled,
            $context->context->functionType(
                $context->getTypeFromString($returnTypeName),
                false,
                ...$params
            )
        );
    }

  /** @param list<string> $paramTypeNames */
    public static function runtimeSpineFn(
        Context $context,
        string $methodLc,
        string $returnTypeName,
        array $paramTypeNames
    ): Value {
        return self::runtimeSpine($context, $methodLc, $returnTypeName, $paramTypeNames);
    }

    private static function runtimeSpine(
        Context $context,
        string $methodLc,
        string $returnTypeName,
        array $paramTypeNames
    ): Value {
        $logical = 'PHPCompiler\\Runtime::'.$methodLc;
        $lc = strtolower($logical);
        if (isset($context->functions[$lc])) {
            return $context->functions[$lc];
        }
        $mangled = self::mangleLogicalFunction($logical);
        $existing = $context->module->getNamedFunction($mangled);
        if (null !== $existing) {
            if (self::shouldEmitRuntimeSpineDiagnosticStub($methodLc)) {
                self::emitRuntimeSpineStub($context, $existing, $returnTypeName, $mangled);
            }

            return $existing;
        }
        $params = [];
        foreach ($paramTypeNames as $typeName) {
            $params[] = $context->getTypeFromString($typeName);
        }
        $fn = $context->module->addFunction(
            $mangled,
            $context->context->functionType(
                $context->getTypeFromString($returnTypeName),
                false,
                ...$params
            )
        );
        if (self::shouldEmitRuntimeSpineDiagnosticStub($methodLc)) {
            self::emitRuntimeSpineStub($context, $fn, $returnTypeName, $mangled);
        }

        return $fn;
    }

    /** M3 emit bridge-only Runtime helpers — no CFG call sites (#3037, #3023). */
    private static function shouldEmitRuntimeSpineDiagnosticStub(string $methodLc): bool
    {
        return in_array($methodLc, ['peeklastparsefailure', 'noteparsecompilenullforscript'], true);
    }

    private static function emitRuntimeSpineStub(
        Context $context,
        \PHPLLVM\Value\Function_ $fn,
        string $returnTypeName,
        string $mangled
    ): void {
        if (isset(self::$runtimeSpineStubbed[$mangled])) {
            return;
        }
        self::$runtimeSpineStubbed[$mangled] = true;
        $entry = $fn->appendBasicBlock('diag_stub');
        $saved = $context->builder;
        $context->builder = $context->context->builderCreate();
        $context->builder->positionAtEnd($entry);
        if ('void' === $returnTypeName) {
            $context->builder->returnVoid();
        } else {
            $retType = $context->getTypeFromString($returnTypeName);
            $context->builder->returnValue($retType->constNull());
        }
        $context->builder = $saved;
    }
}
