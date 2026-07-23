<?php

declare(strict_types=1);

namespace PHPCompiler\ext\pspell;

use PHPCompiler\VM\ObjectEntry;
use PHPCompiler\VM\Variable;

/**
 * Argument helpers for ext/pspell builtins (#6294).
 */
final class VmPspellArg
{
    public static function requireDictionary(Variable $var, string $functionName, int $argNum): ObjectEntry
    {
        $var = $var->resolveIndirect();
        if (Variable::TYPE_OBJECT !== $var->type) {
            throw new \TypeError(\sprintf(
                '%s(): Argument #%d ($dictionary) must be of type PSpell\\Dictionary, %s given',
                $functionName,
                $argNum,
                self::typeLabel($var)
            ));
        }
        $object = $var->toObject();
        $lc = strtolower($object->class->name);
        if (VmPspellDictionary::CLASS_LC !== $lc) {
            throw new \TypeError(\sprintf(
                '%s(): Argument #%d ($dictionary) must be of type PSpell\\Dictionary, %s given',
                $functionName,
                $argNum,
                $object->class->name
            ));
        }
        if (!VmPspellDictionary::isLive($object)) {
            throw new \ValueError('Invalid or uninitialized PSpell\\Dictionary object');
        }

        return $object;
    }

    public static function requireConfig(Variable $var, string $functionName, int $argNum): ObjectEntry
    {
        $var = $var->resolveIndirect();
        if (Variable::TYPE_OBJECT !== $var->type) {
            throw new \TypeError(\sprintf(
                '%s(): Argument #%d ($config) must be of type PSpell\\Config, %s given',
                $functionName,
                $argNum,
                self::typeLabel($var)
            ));
        }
        $object = $var->toObject();
        $lc = strtolower($object->class->name);
        if (VmPspellConfig::CLASS_LC !== $lc) {
            throw new \TypeError(\sprintf(
                '%s(): Argument #%d ($config) must be of type PSpell\\Config, %s given',
                $functionName,
                $argNum,
                $object->class->name
            ));
        }
        if (!VmPspellConfig::isLive($object)) {
            throw new \ValueError('Invalid or uninitialized PSpell\\Config object');
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
