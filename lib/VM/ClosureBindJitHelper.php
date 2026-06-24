<?php

declare(strict_types=1);

namespace PHPCompiler\VM;

use PHPCompiler\JIT\Variable as JitVariable;

/**
 * Lowered into JIT/AOT modules for Closure::bind/bindTo guards (#10109, php-in-PHP).
 *
 * php-src: Zend/zend_closures.c — zend_closure_bind, scope/visibility checks
 * SSOT: {@see ClosureSupport}
 */
final class ClosureBindJitHelper
{
    public const KIND_NULL = 0;

    public const KIND_OBJECT = 1;

    public const KIND_STRING = 2;

    public const KIND_INVALID = 3;

    public const STATIC_BIND_WARNING = 'Cannot bind an instance to a static closure';

    /**
     * JIT-native $newThis type is invalid for bind/bindTo (#4192).
     */
    public static function jitTypeIsInvalidNullableObject(int $jitType): bool
    {
        return \in_array($jitType, [
            JitVariable::TYPE_NATIVE_LONG,
            JitVariable::TYPE_NATIVE_DOUBLE,
            JitVariable::TYPE_NATIVE_BOOL,
            JitVariable::TYPE_STRING,
            JitVariable::TYPE_HASHTABLE,
        ], true);
    }

    /**
     * JIT-native $newScope type is invalid for bind/bindTo (#4192).
     */
    public static function jitTypeIsInvalidNullableObjectOrString(int $jitType): bool
    {
        return \in_array($jitType, [
            JitVariable::TYPE_NATIVE_LONG,
            JitVariable::TYPE_NATIVE_DOUBLE,
            JitVariable::TYPE_NATIVE_BOOL,
            JitVariable::TYPE_HASHTABLE,
        ], true);
    }

    /** Value-box $newThis dispatch: null, object, or invalid (#10097). */
    public static function valueBoxKindForNullableObject(int $typeByte): int
    {
        if (Variable::TYPE_NULL === $typeByte) {
            return self::KIND_NULL;
        }
        if (Variable::TYPE_OBJECT === $typeByte) {
            return self::KIND_OBJECT;
        }

        return self::KIND_INVALID;
    }

    /** Value-box $newScope dispatch: null, object, string, or invalid. */
    public static function valueBoxKindForNullableObjectOrString(int $typeByte): int
    {
        if (Variable::TYPE_NULL === $typeByte) {
            return self::KIND_NULL;
        }
        if (Variable::TYPE_OBJECT === $typeByte) {
            return self::KIND_OBJECT;
        }
        if (Variable::TYPE_STRING === $typeByte) {
            return self::KIND_STRING;
        }

        return self::KIND_INVALID;
    }

    public static function resolveStaticScopeAlias(string $scope): bool
    {
        return 'static' === strtolower($scope);
    }

    public static function jitScalarTypeLabel(int $jitType): string
    {
        return match ($jitType) {
            JitVariable::TYPE_NATIVE_LONG => 'int',
            JitVariable::TYPE_NATIVE_DOUBLE => 'float',
            JitVariable::TYPE_NATIVE_BOOL => 'bool',
            JitVariable::TYPE_STRING => 'string',
            JitVariable::TYPE_HASHTABLE => 'array',
            JitVariable::TYPE_OBJECT => 'object',
            default => 'mixed',
        };
    }

    public static function thisArgLabel(string $context): string
    {
        return 'Closure::bind()' === $context ? '#2 ($newThis)' : '#1 ($newThis)';
    }

    public static function scopeArgLabel(string $context): string
    {
        return 'Closure::bind()' === $context ? '#3 ($newScope)' : '#2 ($newScope)';
    }
}
