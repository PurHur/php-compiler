<?php

declare(strict_types=1);

namespace PHPCompiler\VM;

use PHPCompiler\JIT\Variable as JitVariable;

/**
 * Lowered into JIT/AOT modules for dynamic instanceof RHS guards (#10078, php-in-PHP).
 *
 * php-src: Zend/zend_execute.c — ZEND_INSTANCEOF class operand
 * SSOT: {@see InstanceOfClassName}
 */
final class InstanceOfJitHelper
{
    public const RHS_KIND_INVALID = 0;
    public const RHS_KIND_STRING = 1;
    public const RHS_KIND_OBJECT = 2;

    /**
     * JIT-native RHS type is invalid for instanceof class operand (#4339).
     */
    public static function jitRhsTypeIsInvalidClass(int $jitType): bool
    {
        return \in_array($jitType, [
            JitVariable::TYPE_NATIVE_LONG,
            JitVariable::TYPE_NATIVE_DOUBLE,
            JitVariable::TYPE_NATIVE_BOOL,
            JitVariable::TYPE_NULL,
            JitVariable::TYPE_HASHTABLE,
        ], true);
    }

    /**
     * Value-box RHS dispatch: string class name, object class, or invalid (#4339).
     */
    public static function valueBoxRhsKind(int $typeByte): int
    {
        if (Variable::TYPE_STRING === $typeByte) {
            return self::RHS_KIND_STRING;
        }
        if (Variable::TYPE_OBJECT === $typeByte) {
            return self::RHS_KIND_OBJECT;
        }

        return self::RHS_KIND_INVALID;
    }
}
