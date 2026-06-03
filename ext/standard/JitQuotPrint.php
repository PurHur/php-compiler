<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Builtin\TypeErrorRaise;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\InternalStrictArg as JitInternalStrictArg;
use PHPCompiler\JIT\JitStringArg;
use PHPCompiler\JIT\JitValueBox;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\VM\Variable as VmVariable;
use PHPLLVM\Builder;
use PHPLLVM\Value;

/** LLVM JIT/AOT string-arg lowering for quoted_printable_* (php-src ext/standard/quot_print.c, #4828). */
final class JitQuotPrint
{
    public static function lowerStringSubject(
        Context $context,
        JITVariable $arg,
        string $function,
        int $argIndex = 1,
        string $paramName = 'string'
    ): Value {
        if (JITVariable::TYPE_HASHTABLE === $arg->type) {
            self::emitTypeErrorAndAbort($context, self::typeErrorMessage($function, $argIndex, $paramName, 'array'));

            return self::unreachableStringPtr($context);
        }
        if (JITVariable::TYPE_OBJECT === $arg->type) {
            self::emitTypeErrorAndAbort($context, self::typeErrorMessage($function, $argIndex, $paramName, 'object'));

            return self::unreachableStringPtr($context);
        }
        if (JITVariable::TYPE_VALUE === $arg->type) {
            return self::lowerBoxedStringSubject($context, $arg, $function, $argIndex, $paramName);
        }
        if ($context->callerStrictTypes) {
            JitInternalStrictArg::requireString($context, $arg, $function, $paramName, $argIndex);
        }

        return JitStringArg::lower($context, $arg, sprintf('%s() argument #%d', $function, $argIndex));
    }

    private static function lowerBoxedStringSubject(
        Context $context,
        JITVariable $arg,
        string $function,
        int $argIndex,
        string $paramName
    ): Value {
        TypeErrorRaise::registerDeclarations($context);
        TypeErrorRaise::ensureLinked($context);
        $valuePtr = JitValueBox::valuePtrFromVariable($context, $arg);
        $map = $context->structFieldMap['__value__'];
        $typeByte = $context->builder->load(
            $context->builder->structGep($valuePtr, $map['type'])
        );
        $i8 = $context->getTypeFromString('int8');
        $arrayTy = $i8->constInt(VmVariable::TYPE_ARRAY, false);
        $objectTy = $i8->constInt(VmVariable::TYPE_OBJECT, false);

        $okBlock = BasicBlockHelper::append($context, 'quotprint_str_ok');
        $arrayBlock = BasicBlockHelper::append($context, 'quotprint_str_array');
        $objectBlock = BasicBlockHelper::append($context, 'quotprint_str_object');
        $strictBlock = BasicBlockHelper::append($context, 'quotprint_str_strict');

        $isArray = $context->builder->icmp(Builder::INT_EQ, $typeByte, $arrayTy);
        $isObject = $context->builder->icmp(Builder::INT_EQ, $typeByte, $objectTy);
        $context->builder->branchIf($isArray, $arrayBlock, $okBlock);

        $context->builder->positionAtEnd($arrayBlock);
        self::emitTypeErrorAndAbort($context, self::typeErrorMessage($function, $argIndex, $paramName, 'array'));

        $context->builder->positionAtEnd($okBlock);
        $context->builder->branchIf($isObject, $objectBlock, $strictBlock);

        $context->builder->positionAtEnd($objectBlock);
        self::emitTypeErrorAndAbort($context, self::typeErrorMessage($function, $argIndex, $paramName, 'object'));

        $context->builder->positionAtEnd($strictBlock);
        if ($context->callerStrictTypes) {
            $isString = $context->builder->icmp(
                Builder::INT_EQ,
                $typeByte,
                $i8->constInt(VmVariable::TYPE_STRING, false)
            );
            $coerceBlock = BasicBlockHelper::append($context, 'quotprint_str_coerce');
            $strictErrBlock = BasicBlockHelper::append($context, 'quotprint_str_strict_err');
            $context->builder->branchIf($isString, $coerceBlock, $strictErrBlock);
            $context->builder->positionAtEnd($strictErrBlock);
            self::emitTypeErrorAndAbort($context, self::typeErrorMessage($function, $argIndex, $paramName, 'mixed'));
            $context->builder->positionAtEnd($coerceBlock);
        }

        return $context->builder->call(
            $context->lookupFunction('__value__readString'),
            $valuePtr
        );
    }

    private static function typeErrorMessage(
        string $function,
        int $argIndex,
        string $paramName,
        string $given
    ): string {
        return sprintf(
            '%s(): Argument #%d ($%s) must be of type string, %s given',
            $function,
            $argIndex,
            $paramName,
            $given
        );
    }

    private static function emitTypeErrorAndAbort(Context $context, string $message): void
    {
        TypeErrorRaise::registerDeclarations($context);
        TypeErrorRaise::ensureLinked($context);
        TypeErrorRaise::emitRaise($context, $message);
        $context->builder->call($context->lookupFunction('abort'));
    }

    private static function unreachableStringPtr(Context $context): Value
    {
        return $context->getTypeFromString('__string__*')->constNull();
    }
}
