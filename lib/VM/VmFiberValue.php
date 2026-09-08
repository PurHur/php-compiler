<?php

declare(strict_types=1);

namespace PHPCompiler\VM;

use PHPCompiler\JIT\Variable as JitVariable;
use PHPCompiler\Lint\UnsupportedFeature;

/**
 * Fiber suspend/resume value-box write guards for VM + JIT lowering (#10079).
 *
 * php-src: Zend/zend_fibers.c — zend_fiber_suspend / resume argument boxing
 */
final class VmFiberValue
{
    /** @deprecated use UnsupportedFeature catalog id fiber-value-type-jit (#36396) */
    public const ERROR_UNSUPPORTED = 'Unsupported fiber value type in JIT (issue #4019)';

    public static function unsupportedMessage(): string
    {
        return UnsupportedFeature::message('fiber-value-type-jit');
    }

    /** @return null|'__value__writeString'|'__value__writeLong'|'__value__writeDouble'|'__value__writeBool'|'__value__writeNull' */
    public static function writeFunctionForJitType(int $type): ?string
    {
        return match ($type) {
            JitVariable::TYPE_STRING => '__value__writeString',
            JitVariable::TYPE_NATIVE_LONG => '__value__writeLong',
            JitVariable::TYPE_NATIVE_DOUBLE => '__value__writeDouble',
            JitVariable::TYPE_NATIVE_BOOL => '__value__writeBool',
            JitVariable::TYPE_NULL => '__value__writeNull',
            default => null,
        };
    }

    public static function isValueBoxCopy(int $type): bool
    {
        return JitVariable::TYPE_VALUE === $type;
    }
}
