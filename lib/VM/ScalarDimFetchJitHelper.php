<?php

declare(strict_types=1);

namespace PHPCompiler\VM;

use PHPCompiler\JIT\Variable as JitVariable;

/**
 * Lowered into JIT/AOT modules for ZEND_FETCH_DIM_R on scalar containers (#10271, php-in-PHP).
 *
 * php-src: Zend/zend_execute.c — ZEND_FETCH_DIM_R on non-array/object
 * SSOT: {@see ErrorReporter::arrayOffsetOnNonContainer}
 */
final class ScalarDimFetchJitHelper
{
    public static function jitTypeLabel(int $jitTypeByte): string
    {
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
}
