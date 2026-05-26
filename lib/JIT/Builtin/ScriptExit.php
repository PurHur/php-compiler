<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Builtin;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable;
use PHPLLVM\Builder;
use PHPLLVM\Value;

/** LLVM lowering for exit/die (issue #269). */
final class ScriptExit
{
    public static function emit(Context $context, Variable $arg): void
    {
        switch ($arg->type) {
            case Variable::TYPE_STRING:
                self::echoString($context, $context->helper->loadValue($arg));
                self::callLibcExit($context, 0);
                break;
            case Variable::TYPE_NATIVE_LONG:
                self::callLibcExit($context, $context->helper->loadValue($arg));
                break;
            case Variable::TYPE_VALUE:
                self::emitBoxed($context, $context->helper->loadValue($arg));
                break;
            default:
                throw new \LogicException('exit() only supports string or integer status in this compiler build');
        }
    }

    private static function emitBoxed(Context $context, Value $boxedPtr): void
    {
        $map = $context->structFieldMap['__value__'];
        $typeByte = $context->builder->load(
            $context->builder->structGep($boxedPtr, $map['type'])
        );
        $i8 = $context->getTypeFromString('int8');
        $i32 = $context->getTypeFromString('int32');
        $i64 = $context->getTypeFromString('int64');

        $stringBlock = BasicBlockHelper::append($context, 'exit_boxed_string');
        $longBlock = BasicBlockHelper::append($context, 'exit_boxed_long');
        $badBlock = BasicBlockHelper::append($context, 'exit_boxed_bad');

        $isString = $context->builder->icmp(
            Builder::INT_EQ,
            $typeByte,
            $i8->constInt(Variable::TYPE_STRING, false)
        );
        $isLong = $context->builder->icmp(
            Builder::INT_EQ,
            $typeByte,
            $i8->constInt(Variable::TYPE_NATIVE_LONG, false)
        );

        $afterStringProbe = BasicBlockHelper::append($context, 'exit_boxed_after_string');
        $context->builder->branchIf($isString, $stringBlock, $afterStringProbe);

        $context->builder->positionAtEnd($stringBlock);
        $strPtr = $context->builder->call(
            $context->lookupFunction('__value__readString'),
            $boxedPtr
        );
        self::echoString($context, $strPtr);
        self::callLibcExit($context, 0);

        $context->builder->positionAtEnd($afterStringProbe);
        $context->builder->branchIf($isLong, $longBlock, $badBlock);

        $context->builder->positionAtEnd($longBlock);
        $longVal = $context->builder->call(
            $context->lookupFunction('__value__readLong'),
            $boxedPtr
        );
        self::callLibcExit($context, $longVal);

        $context->builder->positionAtEnd($badBlock);
        $context->builder->call(
            $context->lookupFunction('exit'),
            $i32->constInt(1, false)
        );
    }

    private static function echoString(Context $context, Value $strPtr): void
    {
        $offset = $context->structFieldIndex($strPtr, 'length');
        $length = $context->builder->load($context->builder->structGep($strPtr, $offset));
        $offset = $context->structFieldIndex($strPtr, 'value');
        $valuePtr = $context->builder->structGep($strPtr, $offset);
        $fmt = $context->builder->pointerCast(
            $context->constantFromString('%.*s'),
            $context->getTypeFromString('char*')
        );
        $context->builder->call(
            $context->lookupFunction('printf'),
            $fmt,
            $length,
            $valuePtr
        );
    }

    private static function callLibcExit(Context $context, Value $status): void
    {
        if (Builtin::LOAD_TYPE_STANDALONE === $context->loadType) {
            PendingHeaders::emitFlushForStandalone($context);
        }
        $i32 = $context->getTypeFromString('int32');
        $trunc = $context->builder->trunc($status, $i32);
        $context->builder->call($context->lookupFunction('exit'), $trunc);
    }
}
