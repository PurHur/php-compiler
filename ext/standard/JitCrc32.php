<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\InternalStrictArg as JitInternalStrictArg;
use PHPCompiler\JIT\JitStringArg;
use PHPCompiler\JIT\Builtin\TypeErrorRaise;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitValueBox;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\VM\Variable as VmVariable;
use PHPLLVM\Builder;
use PHPLLVM\Value;

/** LLVM JIT helper for crc32() — table-driven CRC32B from ext/standard/VmCrc32.php (#5389). */
final class JitCrc32
{
    public static function compute(Context $context, Value $subject, Value $seed): Value
    {
        $checksum = JitCrcCore::computeCrc32($context, $subject, $seed);
        $slot = JitValueBox::alloc($context);
        JitValueBox::writeLong($context, $slot, $checksum);

        return $context->builder->load($slot);
    }

    /** Lower crc32() subject with Z_PARAM_STR parity (#4594, ext/standard/string.c). */
    public static function lowerStringSubject(Context $context, JITVariable $arg): Value
    {
        if (JITVariable::TYPE_HASHTABLE === $arg->type) {
            self::emitTypeErrorAndAbort($context, self::typeErrorMessage('array'));

            return self::unreachableStringPtr($context);
        }
        if (JITVariable::TYPE_OBJECT === $arg->type) {
            self::emitTypeErrorAndAbort($context, self::typeErrorMessage('object'));

            return self::unreachableStringPtr($context);
        }
        if (JITVariable::TYPE_VALUE === $arg->type) {
            return self::lowerBoxedStringSubject($context, $arg);
        }
        if ($context->callerStrictTypes) {
            JitInternalStrictArg::requireString($context, $arg, 'crc32', 'string', 1);
        }

        return JitStringArg::lower($context, $arg, 'crc32() argument #1');
    }

    private static function lowerBoxedStringSubject(Context $context, JITVariable $arg): Value
    {
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

        $okBlock = BasicBlockHelper::append($context, 'crc32_str_ok');
        $arrayBlock = BasicBlockHelper::append($context, 'crc32_str_array');
        $objectBlock = BasicBlockHelper::append($context, 'crc32_str_object');
        $strictBlock = BasicBlockHelper::append($context, 'crc32_str_strict');

        $isArray = $context->builder->icmp(Builder::INT_EQ, $typeByte, $arrayTy);
        $isObject = $context->builder->icmp(Builder::INT_EQ, $typeByte, $objectTy);
        $context->builder->branchIf($isArray, $arrayBlock, $okBlock);

        $context->builder->positionAtEnd($arrayBlock);
        self::emitTypeErrorAndAbort($context, self::typeErrorMessage('array'));

        $context->builder->positionAtEnd($okBlock);
        $context->builder->branchIf($isObject, $objectBlock, $strictBlock);

        $context->builder->positionAtEnd($objectBlock);
        self::emitTypeErrorAndAbort($context, self::typeErrorMessage('object'));

        $context->builder->positionAtEnd($strictBlock);
        if ($context->callerStrictTypes) {
            $isString = $context->builder->icmp(
                Builder::INT_EQ,
                $typeByte,
                $i8->constInt(VmVariable::TYPE_STRING, false)
            );
            $coerceBlock = BasicBlockHelper::append($context, 'crc32_str_coerce');
            $strictErrBlock = BasicBlockHelper::append($context, 'crc32_str_strict_err');
            $context->builder->branchIf($isString, $coerceBlock, $strictErrBlock);
            $context->builder->positionAtEnd($strictErrBlock);
            self::emitTypeErrorAndAbort($context, self::typeErrorMessage('mixed'));
            $context->builder->positionAtEnd($coerceBlock);
        }

        return $context->builder->call(
            $context->lookupFunction('__value__readString'),
            $valuePtr
        );
    }

    private static function typeErrorMessage(string $given): string
    {
        return sprintf(
            'crc32(): Argument #1 ($string) must be of type string, %s given',
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
