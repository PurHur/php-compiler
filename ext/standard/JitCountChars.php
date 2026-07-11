<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\HashTableHelper;
use PHPCompiler\JIT\JitOperandTypeLabel;
use PHPCompiler\JIT\JitValueBox;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/**
 * LLVM JIT/AOT materialization helpers for count_chars() (#14692).
 *
 * Runtime histogram logic routes through {@see StringCountChars} + {@see CountCharsJitHelper}.
 */
final class JitCountChars
{
    /**
     * @param array<int, int> $histogram
     */
    public static function materializeHistogram(Context $context, array $histogram): Value
    {
        $ht = HashTableHelper::alloc($context);
        $i64 = $context->getTypeFromString('int64');
        $sizeT = $context->getTypeFromString('size_t');
        $setLong = $context->lookupFunction('__hashtable__setLongAt');
        $grow = $context->lookupFunction('__hashtable__grow');
        $oneSizeT = $sizeT->constInt(1, false);
        foreach ($histogram as $byte => $count) {
            $ord = $context->builder->truncOrBitCast(
                $i64->constInt((int) $byte, false),
                $sizeT
            );
            $need = $context->builder->addNoSignedWrap($ord, $oneSizeT);
            $context->builder->call($grow, $ht, $need);
            $context->builder->call(
                $setLong,
                $ht,
                $ord,
                $i64->constInt((int) $count, false)
            );
        }
        $slot = JitValueBox::alloc($context);
        $ptr = JitValueBox::pointer($context, $slot);
        $context->builder->call($context->lookupFunction('__value__writeHashtable'), $ptr, $ht);
        $context->refcount->addref($ht);

        return $ptr;
    }

    public static function materializeByteString(Context $context, string $bytes): Value
    {
        $len = \strlen($bytes);
        $i64 = $context->getTypeFromString('int64');
        $str = $context->builder->call(
            $context->lookupFunction('__string__alloc'),
            $i64->constInt($len, false)
        );
        if ($len > 0) {
            $map = $context->structFieldMap['__string__'];
            $dest = $context->builder->structGep($str, $map['value']);
            $i8 = $context->getTypeFromString('int8');
            for ($i = 0; $i < $len; ++$i) {
                $chPtr = $context->builder->inBoundsGEP($dest, $i64->constInt($i, false));
                $context->builder->store($i8->constInt(\ord($bytes[$i]), false), $chPtr);
            }
        }
        $slot = JitValueBox::alloc($context);
        $ptr = JitValueBox::pointer($context, $slot);
        $owned = $context->builder->call($context->lookupFunction('__string__separate'), $str);
        $context->builder->call(
            $context->lookupFunction('__value__writeString'),
            $ptr,
            $owned
        );

        return $ptr;
    }

    public static function materializeByteStringFromPtr(Context $context, Value $str): Value
    {
        $slot = JitValueBox::alloc($context);
        $ptr = JitValueBox::pointer($context, $slot);
        $owned = $context->builder->call($context->lookupFunction('__string__separate'), $str);
        $context->builder->call(
            $context->lookupFunction('__value__writeString'),
            $ptr,
            $owned
        );

        return $ptr;
    }

    public static function compileTimeMode(Context $context, JITVariable $var): int
    {
        $enumLabel = JitOperandTypeLabel::compileTimeEnumClassName($context, $var);
        if (null !== $enumLabel) {
            throw new \TypeError(self::modeTypeError($enumLabel));
        }
        if (($var->type & JITVariable::IS_NATIVE_ARRAY) || JITVariable::TYPE_HASHTABLE === $var->type) {
            throw new \TypeError(self::modeTypeError('array'));
        }
        if (JITVariable::TYPE_OBJECT === $var->type) {
            throw new \TypeError(self::modeTypeError(self::compileTimeObjectLabel($context, $var)));
        }
        if (JITVariable::TYPE_NATIVE_LONG !== $var->type
            || JITVariable::KIND_VALUE !== $var->kind) {
            throw new \LogicException(
                'count_chars() argument #2 must be a compile-time integer in this compiler build'
            );
        }
        $lib = $context->llvm->lib;
        if (null !== $lib->LLVMIsAConstantInt($var->value->value)) {
            return (int) $lib->LLVMConstIntGetZExtValue($var->value->value);
        }

        throw new \LogicException(
            'count_chars() argument #2 must be a compile-time integer in this compiler build'
        );
    }

    private static function modeTypeError(string $given): string
    {
        return sprintf(
            'count_chars(): Argument #2 ($mode) must be of type int, %s given',
            $given
        );
    }

    private static function compileTimeObjectLabel(Context $context, JITVariable $arg): string
    {
        if (JITVariable::KIND_VALUE !== $arg->kind) {
            return 'object';
        }
        $objMap = $context->structFieldMap['__object__'] ?? null;
        if (null === $objMap || !isset($objMap['class_id'])) {
            return 'object';
        }
        $classIdVal = $context->builder->load(
            $context->builder->structGep($arg->value, $objMap['class_id'])
        );
        if (!method_exists($classIdVal, 'isConstant') || !$classIdVal->isConstant()) {
            return 'object';
        }
        $classId = (int) $classIdVal->getConstantValue();

        return $context->type->object->classNameForId($classId);
    }
}
