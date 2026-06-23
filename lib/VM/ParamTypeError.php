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
        ?string $literalBoolType = null
    ): \TypeError {
        $value = $argument->resolveIndirect();
        $message = sprintf(
            '%s(): Argument #%d ($%s) must be of type %s, %s given',
            $function,
            $paramIndex + 1,
            $paramName,
            TypeCheck::typeNameForConstraint($expectedConstraint, $literalBoolType),
            EnumCaseSupport::typeNameForVariable($argument)
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
        int $callSiteLine
    ): \TypeError {
        $message = sprintf(
            '%s(): Argument #%d ($%s) must be of type %s, %s given',
            $function,
            $paramIndex + 1,
            $paramName,
            $expectedType,
            self::givenTypeName($argument)
        );
        if ($callSiteLine > 0 && '' !== $scriptPath) {
            $message .= sprintf(', called in %s on line %d', $scriptPath, $callSiteLine);
        }

        return new \TypeError($message);
    }

    private static function givenTypeName(Variable $argument): string
    {
        $resolved = $argument->resolveIndirect();
        if (Variable::TYPE_OBJECT === $resolved->type) {
            return $resolved->toObject()->class->name;
        }

        return EnumCaseSupport::typeNameForVariable($argument);
    }
}
