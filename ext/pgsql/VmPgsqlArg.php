<?php

declare(strict_types=1);

namespace PHPCompiler\ext\pgsql;

use PHPCompiler\VM\ObjectEntry;
use PHPCompiler\VM\Variable;

/**
 * Argument helpers for ext/pgsql builtins (#3741).
 */
final class VmPgsqlArg
{
    public static function requireConnection(Variable $var, string $functionName, int $argNum): ObjectEntry
    {
        $var = $var->resolveIndirect();
        if (Variable::TYPE_OBJECT !== $var->type) {
            throw new \TypeError(\sprintf(
                '%s(): Argument #%d ($connection) must be of type PgSql\\Connection, %s given',
                $functionName,
                $argNum,
                self::typeLabel($var)
            ));
        }
        $object = $var->toObject();
        $lc = strtolower($object->class->name);
        if (VmPgsqlConnection::CLASS_LC !== $lc) {
            throw new \TypeError(\sprintf(
                '%s(): Argument #%d ($connection) must be of type PgSql\\Connection, %s given',
                $functionName,
                $argNum,
                $object->class->name
            ));
        }
        if (!VmPgsqlConnection::isLive($object)) {
            throw new \TypeError(\sprintf(
                '%s(): supplied resource is not a valid PostgreSQL link resource',
                $functionName
            ));
        }

        return $object;
    }

    public static function requireResult(Variable $var, string $functionName, int $argNum): ObjectEntry
    {
        $var = $var->resolveIndirect();
        if (Variable::TYPE_OBJECT !== $var->type) {
            throw new \TypeError(\sprintf(
                '%s(): Argument #%d ($result) must be of type PgSql\\Result, %s given',
                $functionName,
                $argNum,
                self::typeLabel($var)
            ));
        }
        $object = $var->toObject();
        $lc = strtolower($object->class->name);
        if (VmPgsqlResult::CLASS_LC !== $lc) {
            throw new \TypeError(\sprintf(
                '%s(): Argument #%d ($result) must be of type PgSql\\Result, %s given',
                $functionName,
                $argNum,
                $object->class->name
            ));
        }
        if (!VmPgsqlResult::isLive($object)) {
            throw new \TypeError(\sprintf(
                '%s(): supplied resource is not a valid PostgreSQL result resource',
                $functionName
            ));
        }

        return $object;
    }

    /**
     * Optional PgSql\Connection or null → default link (php-src; #20574).
     */
    public static function optionalConnection(Variable $var, string $functionName, int $argNum): ?ObjectEntry
    {
        $var = $var->resolveIndirect();
        if (Variable::TYPE_NULL === $var->type) {
            return null;
        }

        return self::requireConnection($var, $functionName, $argNum);
    }

    public static function requireLob(Variable $var, string $functionName, int $argNum): ObjectEntry
    {
        $var = $var->resolveIndirect();
        if (Variable::TYPE_OBJECT !== $var->type) {
            throw new \TypeError(\sprintf(
                '%s(): Argument #%d ($lob) must be of type PgSql\\Lob, %s given',
                $functionName,
                $argNum,
                self::typeLabel($var)
            ));
        }
        $object = $var->toObject();
        $lc = strtolower($object->class->name);
        if (VmPgsqlLob::CLASS_LC !== $lc) {
            throw new \TypeError(\sprintf(
                '%s(): Argument #%d ($lob) must be of type PgSql\\Lob, %s given',
                $functionName,
                $argNum,
                $object->class->name
            ));
        }
        if (!VmPgsqlLob::isLive($object)) {
            throw new \TypeError(\sprintf(
                '%s(): supplied resource is not a valid PostgreSQL large object',
                $functionName
            ));
        }

        return $object;
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
