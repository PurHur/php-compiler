<?php

declare(strict_types=1);

namespace PHPCompiler\ext\gnupg;

use PHPCompiler\VM\ObjectEntry;
use PHPCompiler\VM\Variable;

/**
 * Argument helpers for ext/gnupg builtins (#6668).
 */
final class VmGnupgArg
{
    public static function requireGnupg(Variable $var, string $functionName, int $argNum): ObjectEntry
    {
        $var = $var->resolveIndirect();
        if (Variable::TYPE_OBJECT !== $var->type) {
            throw new \TypeError(\sprintf(
                '%s(): Argument #%d ($gnupg) must be of type gnupg, %s given',
                $functionName,
                $argNum,
                self::typeLabel($var)
            ));
        }
        $object = $var->toObject();
        $lc = strtolower($object->class->name);
        if (VmGnupgObject::CLASS_LC !== $lc) {
            throw new \TypeError(\sprintf(
                '%s(): Argument #%d ($gnupg) must be of type gnupg, %s given',
                $functionName,
                $argNum,
                $object->class->name
            ));
        }
        if (!VmGnupgObject::isLive($object)) {
            throw new \ValueError('Invalid or uninitialized gnupg object');
        }

        return $object;
    }

    public static function requireString(Variable $var, string $functionName, int $argNum, string $paramName): string
    {
        $var = $var->resolveIndirect();
        if (Variable::TYPE_STRING !== $var->type) {
            throw new \TypeError(\sprintf(
                '%s(): Argument #%d ($%s) must be of type string, %s given',
                $functionName,
                $argNum,
                $paramName,
                self::typeLabel($var)
            ));
        }

        return $var->toString();
    }

    public static function optionalArray(Variable $var, string $functionName, int $argNum): ?array
    {
        $var = $var->resolveIndirect();
        if (Variable::TYPE_NULL === $var->type) {
            return null;
        }
        if (Variable::TYPE_ARRAY !== $var->type) {
            throw new \TypeError(\sprintf(
                '%s(): Argument #%d ($options) must be of type ?array, %s given',
                $functionName,
                $argNum,
                self::typeLabel($var)
            ));
        }

        $out = [];
        foreach ($var->toArray()->exportKeyValuePairs(true) as [$keyVar, $valueVar]) {
            $key = match ($keyVar->type) {
                Variable::TYPE_STRING => $keyVar->toString(),
                Variable::TYPE_INTEGER => (string) $keyVar->toInt(),
                default => $keyVar->toString(),
            };
            if (Variable::TYPE_STRING === $valueVar->type) {
                $out[$key] = $valueVar->toString();
            }
        }

        return $out;
    }

    private static function typeLabel(Variable $var): string
    {
        return match ($var->type) {
            Variable::TYPE_NULL => 'null',
            Variable::TYPE_BOOLEAN => 'bool',
            Variable::TYPE_INTEGER => 'int',
            Variable::TYPE_FLOAT => 'float',
            Variable::TYPE_STRING => 'string',
            Variable::TYPE_ARRAY => 'array',
            Variable::TYPE_OBJECT => $var->toObject()->class->name,
            Variable::TYPE_RESOURCE => 'resource',
            default => 'mixed',
        };
    }
}
