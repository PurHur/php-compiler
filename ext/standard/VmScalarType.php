<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\Frame;
use PHPCompiler\VM\EnumCaseSupport;
use PHPCompiler\VM\ErrorReporter;
use PHPCompiler\VM\Variable;

/**
 * Zend scalar casts on enum case operands for intval/floatval (issue #5623, ext/standard/type.c).
 */
final class VmScalarType
{
    public static function tryEnumCaseToInt(Frame $frame, Variable $value): ?int
    {
        $className = self::enumClassName($value);
        if (null === $className) {
            return null;
        }
        self::warnObjectScalarConversion($frame, $className, 'int');
        $backing = self::backingValue($value);
        if (null === $backing) {
            return 1;
        }

        return self::coerceBackingToInt($backing);
    }

    public static function tryEnumCaseToFloat(Frame $frame, Variable $value): ?float
    {
        $className = self::enumClassName($value);
        if (null === $className) {
            return null;
        }
        self::warnObjectScalarConversion($frame, $className, 'float');
        $backing = self::backingValue($value);
        if (null === $backing) {
            return 1.0;
        }

        return self::coerceBackingToFloat($backing);
    }

    private static function enumClassName(Variable $value): ?string
    {
        $value = $value->resolveIndirect();
        if (Variable::TYPE_ENUM_CASE === $value->type) {
            return $value->toEnumCase()->enumClass->name;
        }
        if (Variable::TYPE_OBJECT === $value->type && EnumCaseSupport::isEnumCase($value->toObject())) {
            return $value->toObject()->class->name;
        }

        return null;
    }

    private static function backingValue(Variable $value): ?Variable
    {
        $value = $value->resolveIndirect();
        if (Variable::TYPE_ENUM_CASE === $value->type) {
            $entry = $value->toEnumCase();
            if (null === $entry->enumClass->backedType) {
                return null;
            }
            $backing = new Variable();
            $backing->copyFrom($entry->backingValue);

            return $backing;
        }
        if (Variable::TYPE_OBJECT === $value->type && EnumCaseSupport::isEnumCase($value->toObject())) {
            $object = $value->toObject();
            if (null === $object->class->backedType || null === $object->enumCaseValue) {
                return null;
            }
            $backing = new Variable();
            $backing->copyFrom($object->enumCaseValue);

            return $backing;
        }

        return null;
    }

    private static function coerceBackingToInt(Variable $backing): int
    {
        $backing = $backing->resolveIndirect();
        if (Variable::TYPE_INTEGER === $backing->type) {
            return $backing->toInt();
        }
        if (Variable::TYPE_FLOAT === $backing->type) {
            return (int) $backing->toFloat();
        }
        if (Variable::TYPE_STRING === $backing->type) {
            return (int) $backing->toString();
        }

        throw new \LogicException('Backed enum case value must be int, float, or string');
    }

    private static function coerceBackingToFloat(Variable $backing): float
    {
        $backing = $backing->resolveIndirect();
        if (Variable::TYPE_INTEGER === $backing->type) {
            return (float) $backing->toInt();
        }
        if (Variable::TYPE_FLOAT === $backing->type) {
            return $backing->toFloat();
        }
        if (Variable::TYPE_STRING === $backing->type) {
            return (float) $backing->toString();
        }

        throw new \LogicException('Backed enum case value must be int, float, or string');
    }

    private static function warnObjectScalarConversion(Frame $frame, string $className, string $kind): void
    {
        if (null === $frame->vmContext) {
            return;
        }
        $message = 'int' === $kind
            ? "Object of class {$className} could not be converted to int"
            : "Object of class {$className} could not be converted to float";
        $frame->vmContext->errors->triggerError(
            $message,
            ErrorReporter::E_WARNING,
            '' !== $frame->scriptPath ? $frame->scriptPath : null,
            $frame->vmContext,
            $frame
        );
    }
}
