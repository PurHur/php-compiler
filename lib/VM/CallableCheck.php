<?php

declare(strict_types=1);

namespace PHPCompiler\VM;

use PHPCompiler\ext\standard\VmCallable;
use PHPCompiler\Frame;

/**
 * Zend callable pseudo-type at call sites (zend_type.c IS_CALLABLE, #17742).
 */
final class CallableCheck
{
    public const TYPE_LABEL = 'callable';

    public static function isCallable(Variable $value, Context $context, ?Frame $frame = null): bool
    {
        return VmCallable::isCallable($context, $value, false, null, $frame);
    }

    public static function valueTypeName(Variable $value): string
    {
        return EnumCaseSupport::typeNameForVariable($value);
    }

    public static function assertParameter(
        Variable $value,
        Context $context,
        ?Frame $frame = null,
        string $kind = 'Argument'
    ): void {
        if (self::isCallable($value, $context, $frame)) {
            return;
        }

        throw new \TypeError(sprintf(
            '%s must be of type %s, %s given',
            $kind,
            self::TYPE_LABEL,
            self::valueTypeName($value)
        ));
    }
}
