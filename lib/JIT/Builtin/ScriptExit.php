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
    public static function emitWithMessage(Context $context, Variable $statusArg, Variable $messageArg): void
    {
        self::emitMessage($context, $messageArg);
        self::emitStatusOnly($context, $statusArg);
    }

    public static function emit(Context $context, Variable $arg): void
    {
        self::emitStatusOnly($context, $arg);
    }

    private static function emitStatusOnly(Context $context, Variable $arg): void
    {
        switch ($arg->type) {
            case Variable::TYPE_NULL:
                self::callLibcExit($context, $context->getTypeFromString('int64')->constInt(0, false));
                break;
            case Variable::TYPE_STRING:
                self::echoString($context, $context->helper->loadValue($arg));
                self::callLibcExit($context, $context->getTypeFromString('int64')->constInt(0, false));
                break;
            case Variable::TYPE_NATIVE_LONG:
                self::callLibcExit($context, $context->helper->loadValue($arg));
                break;
            case Variable::TYPE_NATIVE_DOUBLE:
                $doubleVal = $context->helper->loadValue($arg);
                $context->builder->call(
                    $context->lookupFunction('__phpc_ob_echo_double'),
                    $doubleVal
                );
                self::callLibcExit($context, $context->getTypeFromString('int64')->constInt(0, false));
                break;
            case Variable::TYPE_NATIVE_BOOL:
                self::emitNativeBool($context, $context->helper->loadValue($arg));
                self::callLibcExit($context, $context->getTypeFromString('int64')->constInt(0, false));
                break;
            case Variable::TYPE_VALUE:
                self::emitBoxed($context, $context->helper->loadValue($arg));
                break;
            default:
                throw new \LogicException('exit() only supports string or integer status in this compiler build');
        }
    }

    private static function emitMessage(Context $context, Variable $arg): void
    {
        switch ($arg->type) {
            case Variable::TYPE_NULL:
                return;
            case Variable::TYPE_STRING:
                self::echoString($context, $context->helper->loadValue($arg));
                return;
            case Variable::TYPE_NATIVE_LONG:
                $context->builder->call(
                    $context->lookupFunction('__phpc_ob_echo_ll'),
                    $context->helper->loadValue($arg)
                );
                return;
            case Variable::TYPE_NATIVE_DOUBLE:
                $context->builder->call(
                    $context->lookupFunction('__phpc_ob_echo_double'),
                    $context->helper->loadValue($arg)
                );
                return;
            case Variable::TYPE_NATIVE_BOOL:
                self::emitNativeBool($context, $context->helper->loadValue($arg));
                return;
            case Variable::TYPE_VALUE:
                self::emitBoxedMessage($context, $context->helper->loadValue($arg));
                return;
            default:
                throw new \LogicException('exit() message must be string-coercible in this compiler build');
        }
    }

    private static function emitBoxedMessage(Context $context, Value $boxedPtr): void
    {
        $map = $context->structFieldMap['__value__'];
        $typeByte = $context->builder->load(
            $context->builder->structGep($boxedPtr, $map['type'])
        );
        $i8 = $context->getTypeFromString('int8');

        $nullBlock = BasicBlockHelper::append($context, 'exit_msg_boxed_null');
        $stringBlock = BasicBlockHelper::append($context, 'exit_msg_boxed_string');
        $longBlock = BasicBlockHelper::append($context, 'exit_msg_boxed_long');
        $boolBlock = BasicBlockHelper::append($context, 'exit_msg_boxed_bool');
        $doubleBlock = BasicBlockHelper::append($context, 'exit_msg_boxed_double');
        $doneBlock = BasicBlockHelper::append($context, 'exit_msg_boxed_done');

        $isNull = $context->builder->icmp(
            Builder::INT_EQ,
            $typeByte,
            $i8->constInt(Variable::TYPE_NULL, false)
        );
        $afterNull = BasicBlockHelper::append($context, 'exit_msg_boxed_after_null');
        $context->builder->branchIf($isNull, $nullBlock, $afterNull);

        $context->builder->positionAtEnd($nullBlock);
        $context->builder->branch($doneBlock);

        $context->builder->positionAtEnd($afterNull);
        $isString = $context->builder->icmp(
            Builder::INT_EQ,
            $typeByte,
            $i8->constInt(Variable::TYPE_STRING, false)
        );
        $afterStringProbe = BasicBlockHelper::append($context, 'exit_msg_boxed_after_string');
        $context->builder->branchIf($isString, $stringBlock, $afterStringProbe);

        $context->builder->positionAtEnd($stringBlock);
        $strPtr = $context->builder->call(
            $context->lookupFunction('__value__readString'),
            $boxedPtr
        );
        self::echoString($context, $strPtr);
        $context->builder->branch($doneBlock);

        $context->builder->positionAtEnd($afterStringProbe);
        $isLong = $context->builder->icmp(
            Builder::INT_EQ,
            $typeByte,
            $i8->constInt(Variable::TYPE_NATIVE_LONG, false)
        );
        $afterLong = BasicBlockHelper::append($context, 'exit_msg_boxed_after_long');
        $context->builder->branchIf($isLong, $longBlock, $afterLong);

        $context->builder->positionAtEnd($longBlock);
        $longVal = $context->builder->call(
            $context->lookupFunction('__value__readLong'),
            $boxedPtr
        );
        $context->builder->call(
            $context->lookupFunction('__phpc_ob_echo_ll'),
            $longVal
        );
        $context->builder->branch($doneBlock);

        $context->builder->positionAtEnd($afterLong);
        $isBool = $context->builder->icmp(
            Builder::INT_EQ,
            $typeByte,
            $i8->constInt(Variable::TYPE_NATIVE_BOOL, false)
        );
        $afterBool = BasicBlockHelper::append($context, 'exit_msg_boxed_after_bool');
        $context->builder->branchIf($isBool, $boolBlock, $afterBool);

        $context->builder->positionAtEnd($boolBlock);
        $boolVal = $context->builder->call(
            $context->lookupFunction('__value__readLong'),
            $boxedPtr
        );
        self::emitNativeBool($context, $boolVal);
        $context->builder->branch($doneBlock);

        $context->builder->positionAtEnd($afterBool);
        $isDouble = $context->builder->icmp(
            Builder::INT_EQ,
            $typeByte,
            $i8->constInt(Variable::TYPE_NATIVE_DOUBLE, false)
        );
        $context->builder->branchIf($isDouble, $doubleBlock, $doneBlock);

        $context->builder->positionAtEnd($doubleBlock);
        $doubleVal = $context->builder->call(
            $context->lookupFunction('__value__readDouble'),
            $boxedPtr
        );
        $context->builder->call(
            $context->lookupFunction('__phpc_ob_echo_double'),
            $doubleVal
        );
        $context->builder->branch($doneBlock);

        $context->builder->positionAtEnd($doneBlock);
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

        $nullBlock = BasicBlockHelper::append($context, 'exit_boxed_null');
        $stringBlock = BasicBlockHelper::append($context, 'exit_boxed_string');
        $longBlock = BasicBlockHelper::append($context, 'exit_boxed_long');
        $boolBlock = BasicBlockHelper::append($context, 'exit_boxed_bool');
        $doubleBlock = BasicBlockHelper::append($context, 'exit_boxed_double');
        $badBlock = BasicBlockHelper::append($context, 'exit_boxed_bad');

        $isNull = $context->builder->icmp(
            Builder::INT_EQ,
            $typeByte,
            $i8->constInt(Variable::TYPE_NULL, false)
        );
        $afterNull = BasicBlockHelper::append($context, 'exit_boxed_after_null');
        $context->builder->branchIf($isNull, $nullBlock, $afterNull);

        $context->builder->positionAtEnd($nullBlock);
        self::callLibcExit($context, $i64->constInt(0, false));

        $context->builder->positionAtEnd($afterNull);
        $isString = $context->builder->icmp(
            Builder::INT_EQ,
            $typeByte,
            $i8->constInt(Variable::TYPE_STRING, false)
        );
        $afterStringProbe = BasicBlockHelper::append($context, 'exit_boxed_after_string');
        $context->builder->branchIf($isString, $stringBlock, $afterStringProbe);

        $context->builder->positionAtEnd($stringBlock);
        $strPtr = $context->builder->call(
            $context->lookupFunction('__value__readString'),
            $boxedPtr
        );
        self::echoString($context, $strPtr);
        self::callLibcExit($context, $i64->constInt(0, false));

        $context->builder->positionAtEnd($afterStringProbe);
        $isLong = $context->builder->icmp(
            Builder::INT_EQ,
            $typeByte,
            $i8->constInt(Variable::TYPE_NATIVE_LONG, false)
        );
        $afterLong = BasicBlockHelper::append($context, 'exit_boxed_after_long');
        $context->builder->branchIf($isLong, $longBlock, $afterLong);

        $context->builder->positionAtEnd($longBlock);
        $longVal = $context->builder->call(
            $context->lookupFunction('__value__readLong'),
            $boxedPtr
        );
        self::callLibcExit($context, $longVal);

        $context->builder->positionAtEnd($afterLong);
        $isBool = $context->builder->icmp(
            Builder::INT_EQ,
            $typeByte,
            $i8->constInt(Variable::TYPE_NATIVE_BOOL, false)
        );
        $afterBool = BasicBlockHelper::append($context, 'exit_boxed_after_bool');
        $context->builder->branchIf($isBool, $boolBlock, $afterBool);

        $context->builder->positionAtEnd($boolBlock);
        $boolVal = $context->builder->call(
            $context->lookupFunction('__value__readLong'),
            $boxedPtr
        );
        self::emitNativeBool($context, $boolVal);
        self::callLibcExit($context, $i64->constInt(0, false));

        $context->builder->positionAtEnd($afterBool);
        $isDouble = $context->builder->icmp(
            Builder::INT_EQ,
            $typeByte,
            $i8->constInt(Variable::TYPE_NATIVE_DOUBLE, false)
        );
        $context->builder->branchIf($isDouble, $doubleBlock, $badBlock);

        $context->builder->positionAtEnd($doubleBlock);
        $doubleVal = $context->builder->call(
            $context->lookupFunction('__value__readDouble'),
            $boxedPtr
        );
        $context->builder->call(
            $context->lookupFunction('__phpc_ob_echo_double'),
            $doubleVal
        );
        self::callLibcExit($context, $i64->constInt(0, false));

        $context->builder->positionAtEnd($badBlock);
        $context->builder->call(
            $context->lookupFunction('exit'),
            $i32->constInt(1, false)
        );
    }

    private static function emitNativeBool(Context $context, Value $boolVal): void
    {
        $isTrue = $context->builder->icmp(
            Builder::INT_NE,
            $boolVal,
            $boolVal->typeOf()->constInt(0, false)
        );
        $trueBlock = BasicBlockHelper::append($context, 'exit_native_bool_true');
        $doneBlock = BasicBlockHelper::append($context, 'exit_native_bool_done');
        $context->builder->branchIf($isTrue, $trueBlock, $doneBlock);

        $context->builder->positionAtEnd($trueBlock);
        $charPtr = $context->getTypeFromString('char*');
        $context->builder->call(
            $context->lookupFunction('__phpc_ob_echo_cstr'),
            $context->builder->pointerCast($context->constantFromString('1'), $charPtr)
        );
        $context->builder->branch($doneBlock);

        $context->builder->positionAtEnd($doneBlock);
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
            ObOutput::emitEndAllForStandalone($context);
        }
        $i32 = $context->getTypeFromString('int32');
        $trunc = $context->builder->trunc($status, $i32);
        $context->builder->call($context->lookupFunction('exit'), $trunc);
    }
}
