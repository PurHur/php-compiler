<?php

declare(strict_types=1);

namespace PHPCompiler\JIT;

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
        $object = $context->type->object;
        $runtimeId = $object->lookup('PHPCompiler\\Runtime');
        $runtime = $object->allocate($runtimeId);
        $context->builder->call(
            self::runtimeSpine($context, '__construct', 'void', ['__object__*', 'int64']),
            $runtime,
            $i64->constInt(self::MODE_AOT, false)
        );
        $script = $context->builder->call(
            self::runtimeSpine($context, 'parse', '__object__*', ['__object__*', '__string__*', '__string__*']),
            $runtime,
            $code,
            $sourceFile
        );
        $scriptNull = $context->builder->icmp(Builder::INT_EQ, $script, $objPtr->constNull());
        $parseFail = BasicBlockHelper::append($context, 'csm3_parse_fail_'.$tag);
        $parseOk = BasicBlockHelper::append($context, 'csm3_parse_ok_'.$tag);
        $context->builder->branchIf($scriptNull, $parseFail, $parseOk);

        $context->builder->positionAtEnd($parseFail);
        self::echoPhaseError(
            $context,
            $logPrefix,
            $logPrefix.': parse returned null (parser spine)',
            'parse'
        );
        $context->builder->returnValue($retFail);

        $context->builder->positionAtEnd($parseOk);
        $block = $context->builder->call(
            self::runtimeSpine($context, 'compileemitsmoke', '__object__*', ['__object__*', '__object__*']),
            $runtime,
            $script
        );
        $blockNull = $context->builder->icmp(Builder::INT_EQ, $block, $objPtr->constNull());
        $pacFail = BasicBlockHelper::append($context, 'csm3_pac_fail_'.$tag);
        $pacOk = BasicBlockHelper::append($context, 'csm3_pac_ok_'.$tag);
        $context->builder->branchIf($blockNull, $pacFail, $pacOk);

        $context->builder->positionAtEnd($pacFail);
        self::echoPhaseError(
            $context,
            $logPrefix,
            $logPrefix.': compileEmitSmoke returned null (CFG/compile spine)',
            'compileEmitSmoke'
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
    private static function compilerSpine(
        Context $context,
        string $methodLc,
        string $returnTypeName,
        array $paramTypeNames
    ): Value {
        $logical = 'PHPCompiler\\Compiler::'.$methodLc;
        $lc = strtolower($logical);
        $mangled = strtolower(preg_replace('/[^a-zA-Z0-9_]/', '_', $logical) ?? $logical);
        $existing = $context->module->getNamedFunction($mangled);
        if (null !== $existing) {
            return $existing;
        }
        if (isset($context->functions[$lc])) {
            return $context->functions[$lc];
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
        $mangled = strtolower(preg_replace('/[^a-zA-Z0-9_]/', '_', $logical) ?? $logical);
        $existing = $context->module->getNamedFunction($mangled);
        if (null !== $existing) {
            return $existing;
        }
        if (isset($context->functions[$lc])) {
            return $context->functions[$lc];
        }

        throw new \LogicException(
            'M3 emit bridge missing lowered runtime spine: '.$logical.' (#2442)'
        );
    }
}
