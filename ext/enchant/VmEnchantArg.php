<?php

declare(strict_types=1);

namespace PHPCompiler\ext\enchant;

use PHPCompiler\VM\ObjectEntry;
use PHPCompiler\VM\Variable;

/**
 * Argument helpers for ext/enchant builtins (#6230).
 */
final class VmEnchantArg
{
    public static function requireBroker(Variable $var, string $functionName, int $argNum): ObjectEntry
    {
        $var = $var->resolveIndirect();
        if (Variable::TYPE_OBJECT !== $var->type) {
            throw new \TypeError(\sprintf(
                '%s(): Argument #%d ($broker) must be of type EnchantBroker, %s given',
                $functionName,
                $argNum,
                self::typeLabel($var)
            ));
        }
        $object = $var->toObject();
        $lc = strtolower($object->class->name);
        if (VmEnchantBroker::CLASS_LC !== $lc) {
            throw new \TypeError(\sprintf(
                '%s(): Argument #%d ($broker) must be of type EnchantBroker, %s given',
                $functionName,
                $argNum,
                $object->class->name
            ));
        }
        if (!VmEnchantBroker::isLive($object)) {
            throw new \ValueError('Invalid or uninitialized EnchantBroker object');
        }

        return $object;
    }

    public static function requireDictionary(Variable $var, string $functionName, int $argNum): ObjectEntry
    {
        $var = $var->resolveIndirect();
        if (Variable::TYPE_OBJECT !== $var->type) {
            throw new \TypeError(\sprintf(
                '%s(): Argument #%d ($dictionary) must be of type EnchantDictionary, %s given',
                $functionName,
                $argNum,
                self::typeLabel($var)
            ));
        }
        $object = $var->toObject();
        $lc = strtolower($object->class->name);
        if (VmEnchantDictionary::CLASS_LC !== $lc) {
            throw new \TypeError(\sprintf(
                '%s(): Argument #%d ($dictionary) must be of type EnchantDictionary, %s given',
                $functionName,
                $argNum,
                $object->class->name
            ));
        }
        if (!VmEnchantDictionary::isLive($object)) {
            throw new \ValueError('Invalid or uninitialized EnchantDictionary object');
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
