<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\VM\Variable;

/** ini_set() $value operand — php-src Z_PARAM_STR_OR_LONG|Z_PARAM_DOUBLE|Z_PARAM_BOOL|Z_PARAM_NULL (#7017). */
final class VmIniValue
{
    public static function coerceValueArg(Variable $var, string $function): string
    {
        $var = $var->resolveIndirect();
        if (self::isAcceptedScalarType($var->type)) {
            return self::toIniString($var);
        }

        throw new \TypeError(self::valueTypeError($function));
    }

    private static function isAcceptedScalarType(int $type): bool
    {
        return \in_array($type, [
            Variable::TYPE_NULL,
            Variable::TYPE_STRING,
            Variable::TYPE_INTEGER,
            Variable::TYPE_FLOAT,
            Variable::TYPE_BOOLEAN,
        ], true);
    }

    private static function toIniString(Variable $var): string
    {
        if (Variable::TYPE_NULL === $var->type) {
            return '';
        }

        return $var->toString();
    }

    public static function valueTypeError(string $function): string
    {
        return \sprintf(
            '%s(): Argument #2 ($value) must be of type string|int|float|bool|null',
            $function
        );
    }
}
