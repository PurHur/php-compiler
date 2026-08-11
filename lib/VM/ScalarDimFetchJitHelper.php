<?php

declare(strict_types=1);

namespace PHPCompiler\VM;

use PHPCompiler\JIT\Variable as JitVariable;

/**
 * Lowered into JIT/AOT modules for ZEND_FETCH_DIM_R on scalar containers (#10271, #10343, php-in-PHP).
 *
 * php-src: Zend/zend_execute.c — ZEND_FETCH_DIM_R on non-array/object
 * SSOT: {@see ErrorReporter::arrayOffsetOnNonContainer}
 *
 * Bool containers use synthetic type codes so PROFILE≥8.3 can emit {@code true}/{@code false}
 * (zend_zval_value_name) without a second ABI argument (#30053).
 */
final class ScalarDimFetchJitHelper
{
    /** Synthetic JIT type code: constant/runtime bool true (#30053). */
    public const JIT_BOOL_TRUE = 100;

    /** Synthetic JIT type code: constant/runtime bool false (#30053). */
    public const JIT_BOOL_FALSE = 101;

    public static function jitTypeLabel(int $jitTypeByte): string
    {
        if (self::JIT_BOOL_TRUE === $jitTypeByte || self::JIT_BOOL_FALSE === $jitTypeByte) {
            if (ErrorReporter::usesShortArrayOffsetTypeWarning()) {
                return self::JIT_BOOL_TRUE === $jitTypeByte ? 'true' : 'false';
            }

            return 'bool';
        }

        return match ($jitTypeByte) {
            JitVariable::TYPE_NULL => 'null',
            JitVariable::TYPE_NATIVE_BOOL => 'bool',
            JitVariable::TYPE_NATIVE_LONG => 'int',
            JitVariable::TYPE_NATIVE_DOUBLE => 'float',
            default => 'unknown',
        };
    }

    public static function warningMessageForJitType(int $jitTypeByte): string
    {
        return ErrorReporter::arrayOffsetOnNonContainerMessage(self::jitTypeLabel($jitTypeByte));
    }

    /**
     * Emit Zend E_WARNING for scalar dim read; compiled into JIT/AOT via ScalarDimFetchRuntime bridge.
     */
    public static function emitWarningForJitType(int $jitTypeByte): void
    {
        compiler_language_warning(self::warningMessageForJitType($jitTypeByte));
    }
}
