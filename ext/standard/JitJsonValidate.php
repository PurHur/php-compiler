<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Builtin\StringJsonDecode;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\ExceptionBridge;
use PHPCompiler\JIT\JitLongArg;
use PHPCompiler\JIT\JitStringBuiltinArg;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Builder;
use PHPLLVM\Value;

/** LLVM lowering for json_validate() via __compiler_json_validate (issue #3101, #29069). */
final class JitJsonValidate
{
    private static int $guardSeq = 0;

    public static function invoke(Context $context, JITVariable $json, JITVariable $depth, ?JITVariable $flags = null): Value
    {
        $jsonPtr = JitStringBuiltinArg::lower($context, $json, 'json_validate', 0, 'json');
        $depthVal = JitLongArg::lower($context, $depth, 'json_validate() argument #2');
        $flagsVal = null === $flags
            ? $context->getTypeFromString('int64')->constInt(0, false)
            : JitLongArg::lower($context, $flags, 'json_validate() argument #3');

        return self::invokeWithDepth($context, $jsonPtr, $depthVal, $flagsVal);
    }

    public static function invokeWithDepth(
        Context $context,
        Value $jsonPtr,
        Value $depthVal,
        ?Value $flagsVal = null
    ): Value {
        StringJsonDecode::ensureLinked($context);
        $flags = $flagsVal ?? $context->getTypeFromString('int64')->constInt(0, false);
        self::emitValidateFlagsGuard($context, $flags);
        $code = $context->builder->call(
            $context->lookupFunction('__compiler_json_validate'),
            $jsonPtr,
            $depthVal,
            $flags
        );

        // RESULT_VALID === 1 (VmJsonScanner); depth/syntax → false + last_error set by helper (#23007).
        return $context->builder->icmp(
            Builder::INT_EQ,
            $code,
            $context->getTypeFromString('int64')->constInt(1, false)
        );
    }

    /** php-src: only 0 or exact JSON_INVALID_UTF8_IGNORE (#29069). */
    private static function emitValidateFlagsGuard(Context $context, Value $flags): void
    {
        $tag = 'jvf'.(string) ++self::$guardSeq;
        $i64 = $context->getTypeFromString('int64');
        $zero = $i64->constInt(0, false);
        $ignore = $i64->constInt(VmJsonFlags::INVALID_UTF8_IGNORE, false);
        $isZero = $context->builder->icmp(Builder::INT_EQ, $flags, $zero);
        $isIgnore = $context->builder->icmp(Builder::INT_EQ, $flags, $ignore);
        $okFlag = $context->builder->or($isZero, $isIgnore);
        $ok = BasicBlockHelper::append($context, 'json_validate_flags_ok_'.$tag);
        $err = BasicBlockHelper::append($context, 'json_validate_flags_err_'.$tag);
        $context->builder->branchIf($okFlag, $ok, $err);
        $context->builder->positionAtEnd($err);
        ExceptionBridge::emitValueErrorAndAbort($context, VmJsonFlags::VALIDATE_FLAGS_ERROR);
        BasicBlockHelper::ensureOpenInsertBlock($context, 'json_validate_flags_err_dead_'.$tag);
        $context->builder->positionAtEnd($ok);
    }
}
