<?php

declare(strict_types=1);

namespace PHPCompiler\VM;

use PHPCompiler\JIT\Variable as JitVariable;

/**
 * Lowered into JIT/AOT modules for `$needle in $haystack` guards (#10172, php-in-PHP).
 *
 * php-src: Zend/zend_execute.c — ZEND_IN handler
 * SSOT: {@see InOperator}
 */
final class InOperatorJitHelper
{
    /**
     * Value-box haystack is array when type tag is VM array or JIT hashtable (#9248).
     */
    public static function valueBoxHaystackIsArray(int $typeByte): bool
    {
        return Variable::TYPE_ARRAY === $typeByte
            || JitVariable::TYPE_HASHTABLE === $typeByte;
    }

    public static function vmOperandLabel(int $vmType): string
    {
        return match ($vmType) {
            Variable::TYPE_NULL => 'null',
            Variable::TYPE_BOOLEAN => 'bool',
            Variable::TYPE_INTEGER => 'int',
            Variable::TYPE_FLOAT => 'float',
            Variable::TYPE_STRING => 'string',
            Variable::TYPE_ARRAY => 'array',
            Variable::TYPE_OBJECT => 'object',
            Variable::TYPE_ENUM_CASE => 'enum',
            default => 'mixed',
        };
    }

    public static function jitOperandLabel(int $jitType, bool $isNativeArray = false): string
    {
        if (JitVariable::TYPE_VALUE === $jitType) {
            return 'mixed';
        }

        return match ($jitType) {
            JitVariable::TYPE_NULL => 'null',
            JitVariable::TYPE_NATIVE_BOOL => 'bool',
            JitVariable::TYPE_NATIVE_LONG => 'int',
            JitVariable::TYPE_NATIVE_DOUBLE => 'float',
            JitVariable::TYPE_STRING => 'string',
            JitVariable::TYPE_OBJECT => 'object',
            default => $isNativeArray || JitVariable::TYPE_HASHTABLE === $jitType
                ? 'array'
                : 'mixed',
        };
    }
}
