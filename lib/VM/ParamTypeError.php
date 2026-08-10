<?php

declare(strict_types=1);

namespace PHPCompiler\VM;

/**
 * Zend-shaped TypeError messages for user call-site strict scalar checks (issues #156, #4482).
 */
final class ParamTypeError
{
    public static function forUserCall(
        string $function,
        int $paramIndex,
        string $paramName,
        int $expectedConstraint,
        Variable $argument,
        string $scriptPath,
        int $callSiteLine,
        ?string $literalBoolType = null,
        bool $omitParamName = false
    ): \TypeError {
        $message = sprintf(
            '%s(): %s must be of type %s, %s given',
            $function,
            self::argumentLabel($paramIndex, $paramName, $omitParamName),
            TypeCheck::typeNameForConstraint($expectedConstraint, $literalBoolType),
            EnumCaseSupport::typeNameForTypeErrorActual($argument)
        );
        if ($callSiteLine > 0 && '' !== $scriptPath) {
            $message .= sprintf(', called in %s on line %d', $scriptPath, $callSiteLine);
        }

        return new \TypeError($message);
    }

    public static function forUserCallWithExpectedType(
        string $function,
        int $paramIndex,
        string $paramName,
        string $expectedType,
        Variable $argument,
        string $scriptPath,
        int $callSiteLine,
        bool $omitParamName = false
    ): \TypeError {
        $message = sprintf(
            '%s(): %s must be of type %s, %s given',
            $function,
            self::argumentLabel($paramIndex, $paramName, $omitParamName),
            $expectedType,
            self::givenTypeName($argument)
        );
        if ($callSiteLine > 0 && '' !== $scriptPath) {
            $message .= sprintf(', called in %s on line %d', $scriptPath, $callSiteLine);
        }

        return new \TypeError($message);
    }

    private static function argumentLabel(int $paramIndex, string $paramName, bool $omitParamName): string
    {
        if ($omitParamName) {
            return sprintf('Argument #%d', $paramIndex + 1);
        }

        return sprintf('Argument #%d ($%s)', $paramIndex + 1, $paramName);
    }

    private static function givenTypeName(Variable $argument): string
    {
        // Always via typeNameForTypeErrorActual so anon classes strip NUL+path (#29569).
        return EnumCaseSupport::typeNameForTypeErrorActual($argument);
    }
}
