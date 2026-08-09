<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\JIT\Builtin\TypeErrorRaise;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitStringBuiltinArg;
use PHPCompiler\JIT\JitValueBox;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\VM\PathSupport;
use PHPLLVM\Builder;
use PHPLLVM\Value;

/** LLVM lowering for error_log() (#3380 JIT/AOT; soft-null message #24965 / re-#24178). */
final class JitErrorLog
{
    private const FILE_APPEND = 8;

    public static function errorLog(
        Context $context,
        JITVariable $message,
        ?JITVariable $messageType,
        ?JITVariable $destination
    ): Value {
        $compileType = self::tryCompileTimeMessageType($context, $messageType);
        if (3 === $compileType) {
            return self::lowerFileAppend($context, $message, $destination);
        }

        $msgStr = self::lowerMessageString($context, $message);
        $nullOperand = JITVariable::TYPE_NULL === $message->type
            || ($message->isNullConstant ?? false);
        if ($nullOperand && $context->callerStrictTypes) {
            $slot = JitValueBox::alloc($context);

            return JitValueBox::pointer($context, $slot);
        }

        $savedInsert = null;
        try {
            $savedInsert = $context->builder->getInsertBlock();
        } catch (\Throwable) {
        }
        \PHPCompiler\JIT\Builtin\StringErrorLog::ensureLinked($context);
        if (null !== $savedInsert) {
            $context->builder->positionAtEnd($savedInsert);
        }
        $ok = $context->builder->call(
            $context->lookupFunction('__compiler_error_log'),
            $msgStr
        );

        return self::boolFromI1($context, $ok);
    }

    public static function emitArgumentCountError(Context $context, string $message): Value
    {
        TypeErrorRaise::ensureLinked($context);
        TypeErrorRaise::emitArgumentCountError($context, $message);
        $slot = JitValueBox::alloc($context);

        return JitValueBox::pointer($context, $slot);
    }

    private static function lowerFileAppend(
        Context $context,
        JITVariable $message,
        ?JITVariable $destination
    ): Value {
        if (null === $destination || JITVariable::TYPE_NULL === $destination->type) {
            TypeErrorRaise::ensureLinked($context);
            TypeErrorRaise::emitValueError($context, PathSupport::EMPTY_PATH_VALUE_ERROR_MESSAGE);
            $context->builder->call($context->lookupFunction('abort'));
            $context->llvm->lib->LLVMBuildUnreachable($context->builder->builder);
            $slot = JitValueBox::alloc($context);

            return JitValueBox::pointer($context, $slot);
        }

        $pathStr = JitFilestatArg::lowerFilename($context, $destination, 'error_log');
        $msgStr = self::lowerMessageString($context, $message);
        $nullOperand = JITVariable::TYPE_NULL === $message->type
            || ($message->isNullConstant ?? false);
        if ($nullOperand && $context->callerStrictTypes) {
            $slot = JitValueBox::alloc($context);

            return JitValueBox::pointer($context, $slot);
        }
        $flags = $context->getTypeFromString('int64')->constInt(self::FILE_APPEND, false);
        $result = JitFilePutContents::invoke($context, $pathStr, $msgStr, $flags);
        $i64 = $context->getTypeFromString('int64');
        $written = $context->builder->call(
            $context->lookupFunction('__value__readLong'),
            $result
        );
        $msgLen = $context->builder->call(
            $context->lookupFunction('__string__strlen'),
            $msgStr
        );
        $minusOne = $i64->constInt(-1, false);
        $openFail = $context->builder->icmp(Builder::INT_EQ, $written, $minusOne);
        $lenOk = $context->builder->icmp(Builder::INT_EQ, $written, $msgLen);
        $ok = $context->builder->and(
            $context->builder->not($openFail),
            $lenOk
        );

        return self::boolFromI1($context, $ok);
    }

    /**
     * Soft-null: compile-time/constant null → "" without DEP IR (AOT-safe; VM emits DEP).
     * Non-null / boxed null go through trim-family lowering (#24965; avoids #24197 poison).
     */
    private static function lowerMessageString(Context $context, JITVariable $message): Value
    {
        $nullOperand = JITVariable::TYPE_NULL === $message->type
            || ($message->isNullConstant ?? false);
        if ($nullOperand && $context->callerStrictTypes) {
            return JitStringBuiltinArg::lowerZparamStr($context, $message, 'error_log', 0, 'message');
        }
        if ($nullOperand) {
            return $context->builder->load($context->constantStringFromString(''));
        }

        return JitStringBuiltinArg::lowerTrimFamilyString($context, $message, 'error_log', 0, 'message');
    }

    private static function tryCompileTimeMessageType(Context $context, ?JITVariable $arg): ?int
    {
        if (null === $arg) {
            return 0;
        }
        if (null !== ($arg->compileTimeLong ?? null)) {
            return (int) $arg->compileTimeLong;
        }
        if (JITVariable::TYPE_NATIVE_LONG === $arg->type) {
            $lib = $context->llvm->lib;
            if (null !== $arg->value && null !== $lib->LLVMIsAConstantInt($arg->value->value)) {
                return (int) $lib->LLVMConstIntGetSExtValue($arg->value->value);
            }
        }

        return null;
    }

    private static function boolFromI1(Context $context, Value $okI1): Value
    {
        $slot = JitValueBox::alloc($context);
        $ptr = JitValueBox::pointer($context, $slot);
        $i32 = $context->getTypeFromString('int32');
        $context->builder->call(
            $context->lookupFunction('__value__writeBool'),
            $ptr,
            $context->builder->zExt($okI1, $i32)
        );

        return $ptr;
    }
}
