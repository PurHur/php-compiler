<?php

declare(strict_types=1);

namespace PHPCompiler\JIT;

require_once __DIR__.'/RuntimeEmitTuAlloc.php';

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

    /** M3 emit TU {main}: read PHP_COMPILER_M3_* env and run native bridge (#1937). */
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
        $envFail = BasicBlockHelper::append($context, 'csm3_env_fail');
        $context->builder->branchIf($envBad, $envFail, $envOk);
        $context->builder->positionAtEnd($envFail);
        self::echoPhaseError($context, $logPrefix, $logPrefix.': set PHP_COMPILER_M3_SOURCE and PHP_COMPILER_M3_OUT', 'env');
        $i32 = $context->getTypeFromString('int32');
        $context->builder->call(
            $context->lookupFunction('exit'),
            $context->builder->trunc($retFail, $i32)
        );
        $context->builder->positionAtEnd($envOk);
        self::emit($context, $sourceFile, $outFile, $logPrefix);
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
        // Minimal Runtime shell — full Object_::allocate() LLVM 9 link crash (#2540).
        $runtime = RuntimeEmitTuAlloc::emit($context);
        $context->builder->call(
            self::runtimeSpine($context, '__construct', 'void', ['__object__*', 'int64']),
            $runtime,
            $i64->constInt(self::MODE_AOT, false)
        );
        $block = $context->builder->call(
            self::runtimeSpine(
                $context,
                'parseandcompileemitsmoke',
                '__object__*',
                ['__object__*', '__string__*', '__string__*']
            ),
            $runtime,
            $code,
            $sourceFile
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
        $sizeT = $context->getTypeFromString('size_t');
        $context->builder->call(
            $context->lookupFunction('__phpc_ob_echo_substr'),
            $outChars,
            $context->builder->zExt($outLen, $sizeT)
        );
        ValueEchoHelper::echoLiteral($context, "\n");
        $context->builder->returnValue($retOk);
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
        $script = $context->builder->call(
            self::runtimeSpine($context, 'parse', '__object__*', ['__object__*', '__string__*', '__string__*']),
            $runtimeThis,
            $code,
            $filename
        );

        return $context->builder->call(
            self::runtimeSpine($context, 'compileemitsmoke', '__object__*', ['__object__*', '__object__*']),
            $runtimeThis,
            $script
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
