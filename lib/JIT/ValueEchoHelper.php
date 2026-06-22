<?php

declare(strict_types=1);

/**
 * Echo/print for boxed __value__ variables in JIT (native LLVM).
 *
 * SSOT: {@see \PHPCompiler\VM\ValueEchoSupport}
 * Standalone AOT: inline LLVM dispatch; embed JIT: {@see \PHPCompiler\JIT\Builtin\ValueEchoRuntime}
 */

namespace PHPCompiler\JIT;

use PHPCompiler\JIT\Builtin;
use PHPCompiler\JIT\Builtin\ValueEchoRuntime;
use PHPCompiler\VM\ValueEchoSupport;
use PHPLLVM\Builder;
use PHPLLVM\Value;

final class ValueEchoHelper
{
    private static int $seq = 0;

    public static function echoLiteral(Context $context, string $literal): void
    {
        $charPtr = $context->getTypeFromString('char*');
        $context->builder->call(
            $context->lookupFunction('__phpc_ob_echo_cstr'),
            $context->builder->pointerCast(
                $context->constantFromString($literal),
                $charPtr
            )
        );
    }

    /**
     * Echo a native long, formatting stream/dir resources like Zend (ext/standard, #4740).
     */
    public static function echoNativeLong(Context $context, Value $longVal): void
    {
        Builtin\StringDir::ensureLinked($context);
        $tag = 'enl'.(string) ++self::$seq;
        $i64 = $context->getTypeFromString('int64');
        $handle = $context->builder->zExt($longVal, $i64);
        $isRes = JitValueCompare::nativeLongIsResource($context, $handle);

        $plainBlock = BasicBlockHelper::append($context, 'echo_native_long_plain_'.$tag);
        $resBlock = BasicBlockHelper::append($context, 'echo_native_long_res_'.$tag);
        $doneBlock = BasicBlockHelper::append($context, 'echo_native_long_done_'.$tag);

        $context->builder->branchIf($isRes, $resBlock, $plainBlock);

        $context->builder->positionAtEnd($plainBlock);
        $context->builder->call(
            $context->lookupFunction('__phpc_ob_echo_ll'),
            $handle
        );
        $context->builder->branch($doneBlock);

        $context->builder->positionAtEnd($resBlock);
        $charPtr = $context->getTypeFromString('char*');
        $sizeT = $context->getTypeFromString('size_t');
        $bufSize = $sizeT->constInt(32, false);
        $buf = $context->builder->call($context->lookupFunction('__mm__malloc'), $bufSize);
        $bufChar = $context->builder->pointerCast($buf, $charPtr);
        $fmt = $context->builder->pointerCast(
            $context->constantFromString(ValueEchoSupport::RESOURCE_FORMAT),
            $charPtr
        );
        $written = $context->builder->call(
            $context->lookupFunction('snprintf'),
            $bufChar,
            $bufSize,
            $fmt,
            $handle
        );
        $context->builder->call(
            $context->lookupFunction('__phpc_ob_echo_substr'),
            $bufChar,
            $context->builder->zExt($written, $sizeT)
        );
        $context->builder->call($context->lookupFunction('__mm__free'), $buf);
        $context->builder->branch($doneBlock);

        $context->builder->positionAtEnd($doneBlock);
    }

    /**
     * Echo an object via __toString when defined; otherwise raise Error (Zend cast-to-string, #4740).
     */
    public static function echoObjectVariable(Context $context, Variable $objectVar, ?string $classHint = null): void
    {
        $asString = MagicMethodDispatch::coerceObjectToString($context, $objectVar, $classHint);
        if (null !== $asString) {
            self::echoStringVariable($context, $asString);

            return;
        }
        $classHint = $classHint ?? $objectVar->type?->userType ?? '';
        $classHint = ltrim((string) $classHint, '\\');
        if ('' !== $classHint && 'object' !== strtolower($classHint)) {
            Builtin\ErrorRaise::ensureLinked($context);
            Builtin\ErrorRaise::emitRaise(
                $context,
                ValueEchoSupport::objectToStringErrorMessage($classHint)
            );

            return;
        }
        self::echoLiteral($context, ValueEchoSupport::OBJECT_FALLBACK_LABEL);
    }

    public static function echoStringVariable(Context $context, Variable $stringVar): void
    {
        $argValue = $context->helper->loadValue($stringVar);
        $offset = $context->structFieldIndex($argValue, 'length');
        $__str__length = $context->builder->load(
            $context->builder->structGep($argValue, $offset)
        );
        $offset = $context->structFieldIndex($argValue, 'value');
        $__str__value = $context->builder->structGep($argValue, $offset);
        $sizeT = $context->getTypeFromString('size_t');
        $context->builder->call(
            $context->lookupFunction('__phpc_ob_echo_substr'),
            $__str__value,
            $context->builder->zExt($__str__length, $sizeT)
        );
    }

    public static function echo(Context $context, Value $valuePtr): void
    {
        if (Builtin::LOAD_TYPE_STANDALONE === $context->loadType) {
            self::echoStandalone($context, $valuePtr);

            return;
        }
        ValueEchoRuntime::emitValue($context, $valuePtr);
    }

    private static function echoStandalone(Context $context, Value $valuePtr): void
    {
        $tag = 'ev'.(string) ++self::$seq;
        $map = $context->structFieldMap['__value__'];
        $typeByte = $context->builder->load($context->builder->structGep($valuePtr, $map['type']));
        $i8 = $context->getTypeFromString('int8');

        $nullBlock = BasicBlockHelper::append($context, 'echo_value_null_'.$tag);
        $longBlock = BasicBlockHelper::append($context, 'echo_value_long_'.$tag);
        $boolBlock = BasicBlockHelper::append($context, 'echo_value_bool_'.$tag);
        $doubleBlock = BasicBlockHelper::append($context, 'echo_value_double_'.$tag);
        $stringBlock = BasicBlockHelper::append($context, 'echo_value_string_'.$tag);
        $arrayBlock = BasicBlockHelper::append($context, 'echo_value_array_'.$tag);
        $objectBlock = BasicBlockHelper::append($context, 'echo_value_object_'.$tag);
        $doneBlock = BasicBlockHelper::append($context, 'echo_value_done_'.$tag);

        $isNull = $context->builder->icmp(Builder::INT_EQ, $typeByte, $i8->constInt(Variable::TYPE_NULL, false));
        $isLong = $context->builder->icmp(Builder::INT_EQ, $typeByte, $i8->constInt(Variable::TYPE_NATIVE_LONG, false));
        $isBool = $context->builder->icmp(Builder::INT_EQ, $typeByte, $i8->constInt(Variable::TYPE_NATIVE_BOOL, false));
        $isDouble = $context->builder->icmp(Builder::INT_EQ, $typeByte, $i8->constInt(Variable::TYPE_NATIVE_DOUBLE, false));
        $isString = $context->builder->icmp(Builder::INT_EQ, $typeByte, $i8->constInt(Variable::TYPE_STRING, false));
        $isHashtable = $context->builder->icmp(Builder::INT_EQ, $typeByte, $i8->constInt(Variable::TYPE_HASHTABLE, false));
        $isObject = $context->builder->icmp(Builder::INT_EQ, $typeByte, $i8->constInt(Variable::TYPE_OBJECT, false));

        $afterNull = BasicBlockHelper::append($context, 'echo_value_after_null_'.$tag);
        $context->builder->branchIf($isNull, $nullBlock, $afterNull);
        $context->builder->positionAtEnd($nullBlock);
        $context->builder->branch($doneBlock);

        $context->builder->positionAtEnd($afterNull);
        $afterLong = BasicBlockHelper::append($context, 'echo_value_after_long_'.$tag);
        $context->builder->branchIf($isLong, $longBlock, $afterLong);
        $context->builder->positionAtEnd($longBlock);
        self::echoNativeLong($context, $context->builder->call($context->lookupFunction('__value__readLong'), $valuePtr));
        $context->builder->branch($doneBlock);

        $context->builder->positionAtEnd($afterLong);
        $afterBool = BasicBlockHelper::append($context, 'echo_value_after_bool_'.$tag);
        $context->builder->branchIf($isBool, $boolBlock, $afterBool);
        $context->builder->positionAtEnd($boolBlock);
        $boolVal = $context->builder->call($context->lookupFunction('__value__readLong'), $valuePtr);
        $isTrue = $context->builder->icmp(Builder::INT_NE, $boolVal, $boolVal->typeOf()->constInt(0, false));
        $trueBlock = BasicBlockHelper::append($context, 'echo_value_bool_true_'.$tag);
        $falseBlock = BasicBlockHelper::append($context, 'echo_value_bool_false_'.$tag);
        $boolDone = BasicBlockHelper::append($context, 'echo_value_bool_done_'.$tag);
        $context->builder->branchIf($isTrue, $trueBlock, $falseBlock);
        $context->builder->positionAtEnd($trueBlock);
        $charPtr = $context->getTypeFromString('char*');
        $context->builder->call($context->lookupFunction('__phpc_ob_echo_cstr'), $context->builder->pointerCast($context->constantFromString('1'), $charPtr));
        $context->builder->branch($boolDone);
        $context->builder->positionAtEnd($falseBlock);
        $context->builder->branch($boolDone);
        $context->builder->positionAtEnd($boolDone);
        $context->builder->branch($doneBlock);

        $context->builder->positionAtEnd($afterBool);
        $afterDouble = BasicBlockHelper::append($context, 'echo_value_after_double_'.$tag);
        $context->builder->branchIf($isDouble, $doubleBlock, $afterDouble);
        $context->builder->positionAtEnd($doubleBlock);
        $context->builder->call($context->lookupFunction('__phpc_ob_echo_double'), $context->builder->call($context->lookupFunction('__value__readDouble'), $valuePtr));
        $context->builder->branch($doneBlock);

        $context->builder->positionAtEnd($afterDouble);
        $afterArray = BasicBlockHelper::append($context, 'echo_value_after_array_'.$tag);
        $context->builder->branchIf($isHashtable, $arrayBlock, $afterArray);
        $context->builder->positionAtEnd($arrayBlock);
        self::echoLiteral($context, ValueEchoSupport::ARRAY_LABEL);
        $context->builder->branch($doneBlock);

        $context->builder->positionAtEnd($afterArray);
        $afterObject = BasicBlockHelper::append($context, 'echo_value_after_object_'.$tag);
        $context->builder->branchIf($isObject, $objectBlock, $afterObject);
        $context->builder->positionAtEnd($objectBlock);
        $objPtr = $context->builder->call($context->lookupFunction('__value__readObject'), $valuePtr);
        self::echoObjectVariable($context, new Variable($context, Variable::TYPE_OBJECT, Variable::KIND_VALUE, $objPtr));
        $context->builder->branch($doneBlock);

        $context->builder->positionAtEnd($afterObject);
        $context->builder->branchIf($isString, $stringBlock, $doneBlock);
        $context->builder->positionAtEnd($stringBlock);
        $strPtr = $context->builder->call($context->lookupFunction('__value__readString'), $valuePtr);
        $strMap = $context->structFieldMap['__string__'];
        $strLen = $context->builder->load($context->builder->structGep($strPtr, $strMap['length']));
        $strChars = $context->builder->structGep($strPtr, $strMap['value']);
        $sizeT = $context->getTypeFromString('size_t');
        $context->builder->call($context->lookupFunction('__phpc_ob_echo_substr'), $strChars, $context->builder->zExt($strLen, $sizeT));
        $context->builder->branch($doneBlock);

        $context->builder->positionAtEnd($doneBlock);
        BasicBlockHelper::branchToFreshContinue($context, 'echo_value_continue_'.$tag);
    }
}
