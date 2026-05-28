<?php

declare(strict_types=1);

namespace PHPCompiler\JIT;

require_once __DIR__.'/EmitTuMode.php';
require_once __DIR__.'/RuntimeEmitTuAlloc.php';
require_once __DIR__.'/RuntimeEmitTuInit.php';

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

    /** M3 emit TU {main}: argv `-o OUT SOURCE` (preferred) or env PHP_COMPILER_M3_* (#1937, #2697, #2866). */
    public static function emitMainEntry(Context $context, string $logPrefix): void
    {
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

        $code = $context->builder->call(
            $context->lookupFunction('__compiler_file_get_contents'),
            $sourceFile
        );
        $codeLen = $context->builder->call($context->lookupFunction('__string__strlen'), $code);
        $codeBad = $context->builder->or(
            $context->builder->icmp(Builder::INT_EQ, $code, $strPtr->constNull()),
            $context->builder->icmp(Builder::INT_EQ, $codeLen, $i64->constInt(0, false))
        );
        $readOk = BasicBlockHelper::append($context, 'csm3_lint_read_ok_'.$tag);
        $readFail = BasicBlockHelper::append($context, 'csm3_lint_read_fail_'.$tag);
        $context->builder->branchIf($codeBad, $readFail, $readOk);

        $context->builder->positionAtEnd($readFail);
        self::echoPhaseError($context, $logPrefix, $logPrefix.': empty source (lint)', 'source');
        $context->builder->returnValue($retFail);

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
            'parseAndCompile'
        );
        $context->builder->returnValue($retFail);

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

        $code = $context->builder->call(
            $context->lookupFunction('__compiler_file_get_contents'),
            $sourceFile
        );
        $codeLen = $context->builder->call($context->lookupFunction('__string__strlen'), $code);
        $codeBad = $context->builder->or(
            $context->builder->icmp(Builder::INT_EQ, $code, $strPtr->constNull()),
            $context->builder->icmp(Builder::INT_EQ, $codeLen, $i64->constInt(0, false))
        );
        $readOk = BasicBlockHelper::append($context, 'csm3_read_ok_'.$tag);
        $readFail = BasicBlockHelper::append($context, 'csm3_read_fail_'.$tag);
        $context->builder->branchIf($codeBad, $readFail, $readOk);

        $context->builder->positionAtEnd($readFail);
        self::echoPhaseError($context, $logPrefix, $logPrefix.': empty source (native bridge)', 'source');
        $context->builder->returnValue($retFail);

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
            'parseAndCompile'
        );
        $context->builder->returnValue($retFail);

        $context->builder->positionAtEnd($pacOk);
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

    private static function shouldUseEmitTuRealLowering(Context $context): bool
    {
        unset($context);

        // Emit-helper binaries must init parse/compiler spine (#2633); env may be unset at runtime.
        return true;
    }

    private static function echoPhaseError(Context $context, string $logPrefix, string $line1, string $phase): void
    {
        ValueEchoHelper::echoLiteral($context, $line1."\n");
        ValueEchoHelper::echoLiteral($context, $logPrefix.': native emit failed at phase='.$phase."\n");
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
        $parseLc = strtolower('PHPCompiler\\Runtime::parse');
        $compileLc = strtolower('PHPCompiler\\Runtime::compileemitsmoke');
        if (!isset($context->functions[$parseLc], $context->functions[$compileLc])) {
            return $objPtr->constNull();
        }

        // Decompose parseAndCompile so we never call the native parseandcompile wrapper (recursion #2967).
        // M5 argv drivers link Runtime::parse + compileEmitSmoke on the compile-driver spine (#3004).
        $tag = 'd'.(string) ++self::$seq;
        $failBb = BasicBlockHelper::append($context, 'csm3_pac_default_fail_'.$tag);
        $compileBb = BasicBlockHelper::append($context, 'csm3_pac_default_compile_'.$tag);
        $tailBb = BasicBlockHelper::append($context, 'csm3_pac_default_tail_'.$tag);
        $script = $context->builder->call($context->functions[$parseLc], $runtimeThis, $code, $filename);
        $scriptNull = $context->builder->icmp(Builder::INT_EQ, $script, $objPtr->constNull());
        $context->builder->branchIf($scriptNull, $failBb, $compileBb);

        $context->builder->positionAtEnd($failBb);
        $context->builder->branch($tailBb);

        $context->builder->positionAtEnd($compileBb);
        $block = $context->builder->call($context->functions[$compileLc], $runtimeThis, $script);
        $context->builder->branch($tailBb);

        $context->builder->positionAtEnd($tailBb);
        $phi = $context->builder->phi($objPtr);
        $phi->addIncoming($objPtr->constNull(), $failBb);
        $phi->addIncoming($block, $compileBb);

        return $phi;
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
}
