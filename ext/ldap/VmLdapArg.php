<?php

declare(strict_types=1);

namespace PHPCompiler\ext\ldap;

use PHPCompiler\VM\ObjectEntry;
use PHPCompiler\VM\Variable;

/**
 * Argument helpers for ext/ldap builtins (#3369).
 */
final class VmLdapArg
{
    public static function requireConnection(Variable $var, string $functionName, int $argNum): ObjectEntry
    {
        $var = $var->resolveIndirect();
        if (Variable::TYPE_OBJECT !== $var->type) {
            throw new \TypeError(\sprintf(
                '%s(): Argument #%d ($ldap) must be of type LDAP\\Connection, %s given',
                $functionName,
                $argNum,
                self::typeLabel($var)
            ));
        }
        $object = $var->toObject();
        $lc = strtolower($object->class->name);
        if (VmLdapConnection::CLASS_LC !== $lc) {
            throw new \TypeError(\sprintf(
                '%s(): Argument #%d ($ldap) must be of type LDAP\\Connection, %s given',
                $functionName,
                $argNum,
                $object->class->name
            ));
        }
        if (!VmLdapConnection::isLive($object)) {
            throw new \TypeError(\sprintf(
                '%s(): supplied LDAP\\Connection is not a valid ldap link resource',
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
                '%s(): Argument #%d ($result) must be of type LDAP\\Result, %s given',
                $functionName,
                $argNum,
                self::typeLabel($var)
            ));
        }
        $object = $var->toObject();
        if (VmLdapResult::RESULT_CLASS_LC !== strtolower($object->class->name)) {
            throw new \TypeError(\sprintf(
                '%s(): Argument #%d ($result) must be of type LDAP\\Result, %s given',
                $functionName,
                $argNum,
                $object->class->name
            ));
        }
        if (!VmLdapResult::isLiveResult($object)) {
            throw new \TypeError(\sprintf(
                '%s(): supplied LDAP\\Result is not a valid ldap result resource',
                $functionName
            ));
        }

        return $object;
    }

    public static function requireEntry(Variable $var, string $functionName, int $argNum): ObjectEntry
    {
        $var = $var->resolveIndirect();
        if (Variable::TYPE_OBJECT !== $var->type) {
            throw new \TypeError(\sprintf(
                '%s(): Argument #%d ($entry) must be of type LDAP\\ResultEntry, %s given',
                $functionName,
                $argNum,
                self::typeLabel($var)
            ));
        }
        $object = $var->toObject();
        if (VmLdapResult::ENTRY_CLASS_LC !== strtolower($object->class->name)) {
            throw new \TypeError(\sprintf(
                '%s(): Argument #%d ($entry) must be of type LDAP\\ResultEntry, %s given',
                $functionName,
                $argNum,
                $object->class->name
            ));
        }
        if (!VmLdapResult::isLiveEntry($object)) {
            throw new \TypeError(\sprintf(
                '%s(): supplied LDAP\\ResultEntry is not a valid ldap result entry resource',
                $functionName
            ));
        }

        return $object;
    }

    private static function typeLabel(Variable $var): string
    {
        return match ($var->type) {
            Variable::TYPE_INTEGER => 'int',
            Variable::TYPE_FLOAT => 'float',
            Variable::TYPE_BOOLEAN => 'bool',
            Variable::TYPE_STRING => 'string',
            Variable::TYPE_NULL => 'null',
            Variable::TYPE_ARRAY => 'array',
            Variable::TYPE_OBJECT => 'object',
            Variable::TYPE_ENUM_CASE => 'object',
            default => 'mixed',
        };
    }
}
