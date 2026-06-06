<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Builtin\TypeErrorRaise;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitNativeString;
use PHPCompiler\JIT\JitValueBox;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\VM\Variable as VmVariable;
use PHPLLVM\Builder;
use PHPLLVM\Value;

/** Lower ini_set() $value with php-src union-type guards (#7017, ext/standard/ini.c). */
final class JitIniValueArg
{
    public static function lower(Context $context, JITVariable $arg, string $function): Value
    {
        if (JITVariable::TYPE_HASHTABLE === $arg->type || JITVariable::TYPE_OBJECT === $arg->type) {
            self::emitValueTypeErrorAndAbort($context, $function);

            return self::unreachableStringPtr($context);
        }
        if (\in_array($arg->type, [
            JITVariable::TYPE_NATIVE_LONG,
            JITVariable::TYPE_NATIVE_DOUBLE,
            JITVariable::TYPE_NATIVE_BOOL,
        ], true)) {
            return $context->helper->loadValue(JitNativeString::coerce($context, $arg));
        }
        if (JITVariable::TYPE_STRING === $arg->type) {
            return $context->helper->loadValue($arg);
        }
        if (JITVariable::TYPE_NULL === $arg->type && $arg->isNullConstant) {
            return $context->builder->load($context->constantStringFromString(''));
        }
        if (JITVariable::TYPE_VALUE === $arg->type) {
            return self::lowerBoxed($context, $arg, $function);
        }

        return $context->helper->loadValue(JitNativeString::coerce($context, $arg));
    }

    private static function lowerBoxed(Context $context, JITVariable $arg, string $function): Value
    {
        TypeErrorRaise::registerDeclarations($context);
        TypeErrorRaise::ensureLinked($context);
        $valuePtr = JitValueBox::valuePtrFromVariable($context, $arg);
        $map = $context->structFieldMap['__value__'];
        $typeByte = $context->builder->load(
            $context->builder->structGep($valuePtr, $map['type'])
        );
        $i8 = $context->getTypeFromString('int8');
        $typeKind = $context->builder->and($typeByte, $i8->constInt(0x7f, false));
        $arrayTy = $i8->constInt(VmVariable::TYPE_ARRAY & 0x7f, false);
        $objectTy = $i8->constInt(VmVariable::TYPE_OBJECT & 0x7f, false);
        $enumCaseTy = $i8->constInt(VmVariable::TYPE_ENUM_CASE, false);

        $okBlock = BasicBlockHelper::append($context, 'ini_val_ok');
        $arrayBlock = BasicBlockHelper::append($context, 'ini_val_array');
        $rejectBlock = BasicBlockHelper::append($context, 'ini_val_reject');
        $coerceBlock = BasicBlockHelper::append($context, 'ini_val_coerce');

        $isArray = $context->builder->icmp(Builder::INT_EQ, $typeKind, $arrayTy);
        $context->builder->branchIf($isArray, $arrayBlock, $okBlock);

        $context->builder->positionAtEnd($arrayBlock);
        self::emitValueTypeErrorAndAbort($context, $function);

        $context->builder->positionAtEnd($okBlock);
        $isObject = $context->builder->icmp(Builder::INT_EQ, $typeKind, $objectTy);
        $isEnumCase = $context->builder->icmp(Builder::INT_EQ, $typeKind, $enumCaseTy);
        $isObjOrEnum = $context->builder->or($isObject, $isEnumCase);
        $context->builder->branchIf($isObjOrEnum, $rejectBlock, $coerceBlock);

        $context->builder->positionAtEnd($rejectBlock);
        self::emitValueTypeErrorAndAbort($context, $function);

        $context->builder->positionAtEnd($coerceBlock);

        return $context->builder->call(
            $context->lookupFunction('__value__readString'),
            $valuePtr
        );
    }

    private static function emitValueTypeErrorAndAbort(Context $context, string $function): void
    {
        TypeErrorRaise::registerDeclarations($context);
        TypeErrorRaise::ensureLinked($context);
        TypeErrorRaise::emitRaise($context, VmIniValue::valueTypeError($function));
        $context->builder->call($context->lookupFunction('abort'));
    }

    private static function unreachableStringPtr(Context $context): Value
    {
        return $context->getTypeFromString('__string__*')->constNull();
    }
}
