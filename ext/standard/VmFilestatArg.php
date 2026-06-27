<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\VM\EnumCaseSupport;
use PHPCompiler\VM\Variable;

/** Shared VM argument guards for filestat permission builtins (php-src ext/standard/filestat.c; #6079). */
final class VmFilestatArg
{
    /**
     * @throws \TypeError
     */
    public static function coerceFilenameArg(Variable $var, string $function): string
    {
        return VmString::coerceTypedStringBuiltinArg($var, $function, 0, 'filename');
    }

    /**
     * @throws \TypeError
     */
    public static function rejectEnumCaseIntOrStringArg(
        Variable $var,
        string $function,
        int $argIndex,
        string $paramName
    ): void {
        $var = $var->resolveIndirect();
        if (!EnumCaseSupport::isEnumCaseVariable($var)) {
            return;
        }
        throw new \TypeError(self::intOrStringTypeError(
            $function,
            $argIndex,
            $paramName,
            EnumCaseSupport::typeNameForVariable($var)
        ));
    }

    /**
     * @throws \TypeError
     */
    public static function rejectEnumCaseIntArg(
        Variable $var,
        string $function,
        int $argIndex,
        string $paramName
    ): void {
        $var = $var->resolveIndirect();
        if (!EnumCaseSupport::isEnumCaseVariable($var)) {
            return;
        }
        throw new \TypeError(self::intTypeError(
            $function,
            $argIndex,
            $paramName,
            EnumCaseSupport::typeNameForVariable($var)
        ));
    }

    /**
     * @throws \TypeError|\LogicException
     */
    public static function requireIntOrStringArg(
        Variable $var,
        string $function,
        int $argIndex,
        string $paramName
    ): Variable {
        $var = $var->resolveIndirect();
        self::rejectEnumCaseIntOrStringArg($var, $function, $argIndex, $paramName);
        if (!\in_array($var->type, [Variable::TYPE_INTEGER, Variable::TYPE_STRING], true)) {
            throw new \LogicException(
                $function.'() '.$paramName.' must be int or string in this compiler build'
            );
        }

        return $var;
    }

    /**
     * @throws \TypeError|\LogicException
     */
    public static function requireIntArg(
        Variable $var,
        string $function,
        int $argIndex,
        string $paramName
    ): int {
        $var = $var->resolveIndirect();
        self::rejectEnumCaseIntArg($var, $function, $argIndex, $paramName);
        if (Variable::TYPE_INTEGER !== $var->type) {
            throw new \LogicException(
                $function.'() '.$paramName.' must be an integer in this compiler build'
            );
        }

        return $var->toInt();
    }

    private static function intOrStringTypeError(
        string $function,
        int $argIndex,
        string $paramName,
        string $given
    ): string {
        return \sprintf(
            '%s(): Argument #%d ($%s) must be of type string|int, %s given',
            $function,
            $argIndex + 1,
            $paramName,
            $given
        );
    }

    private static function intTypeError(
        string $function,
        int $argIndex,
        string $paramName,
        string $given
    ): string {
        return \sprintf(
            '%s(): Argument #%d ($%s) must be of type int, %s given',
            $function,
            $argIndex + 1,
            $paramName,
            $given
        );
    }
}
