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

    /** Zend zend_is_callable_ex — scalar invoke ($x()) before function lookup (#17745). */
    public static function scalarNotCallableMessage(Variable $value): string
    {
        return 'Value of type '.self::valueTypeName($value).' is not callable';
    }

    /** Zend zend_is_callable_ex — object without __invoke (#17745). */
    public static function objectNotCallableMessage(Variable $value): string
    {
        return 'Object of type '.self::valueTypeName($value).' is not callable';
    }

    /** Zend zend_is_callable_ex — array callback count (#17745). */
    public static function arrayCallbackTwoElementsMessage(): string
    {
        return 'Array callback must have exactly two elements';
    }

    /** Zend zend_is_callable_ex — array[0] kind (#28937, FCC / invoke). */
    public static function firstArrayMemberInvalidMessage(): string
    {
        return 'First array member is not a valid class name or object';
    }

    /** Zend zend_is_callable_ex — array[1] kind (#28937, FCC / invoke). */
    public static function secondArrayMemberInvalidMessage(): string
    {
        return 'Second array member is not a valid method';
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

        $ctx = TypeCheck::currentParamErrorContext();
        if (null !== $ctx && 'Argument' === $kind) {
            $ctx->throwExpectedType(self::TYPE_LABEL, $value);
        }

        throw new \TypeError(sprintf(
            '%s must be of type %s, %s given',
            $kind,
            self::TYPE_LABEL,
            self::valueTypeName($value)
        ));
    }
}
