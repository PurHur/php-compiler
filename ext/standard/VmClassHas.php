<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\VM\ClassEntry;
use PHPCompiler\VM\Context;
use PHPCompiler\VM\EnumCaseSupport;
use PHPCompiler\VM\EnumSupport;
use PHPCompiler\VM\Variable;

/**
 * Shared VM helpers for class_has_method/property/constant() (#9989).
 *
 * php-src: ext/standard/basic_functions.c — procedural ReflectionClass::has* wrappers.
 */
final class VmClassHas
{
    /**
     * @param list<Variable> $calledArgs
     */
    public static function parseAutoload(array $calledArgs, string $function, int $argIndex = 2): bool
    {
        if (\count($calledArgs) <= $argIndex) {
            return true;
        }

        return VmMath::parseBoolBuiltinArg($calledArgs[$argIndex], $function, $argIndex + 1, 'autoload');
    }

    /**
     * @param list<Variable> $calledArgs
     */
    public static function parseAllowString(array $calledArgs, string $function, int $argIndex = 3): bool
    {
        if (\count($calledArgs) <= $argIndex) {
            return true;
        }

        return VmMath::parseBoolBuiltinArg($calledArgs[$argIndex], $function, $argIndex + 1, 'allow_string');
    }

    public static function resolveClassEntryForHas(
        Context $ctx,
        Variable $objectOrClass,
        bool $autoload,
        bool $allowString
    ): ?ClassEntry {
        $objectOrClass = $objectOrClass->resolveIndirect();
        if (Variable::TYPE_STRING === $objectOrClass->type) {
            if (!$allowString) {
                return null;
            }
            $className = $objectOrClass->toString();
            $lc = strtolower(ltrim($className, '\\'));
            if (isset($ctx->classes[$lc])) {
                return $ctx->classes[$lc];
            }
            if (!$autoload) {
                return null;
            }
            $ctx->autoloadClass($className);

            return $ctx->classes[$lc] ?? null;
        }
        if (Variable::TYPE_OBJECT === $objectOrClass->type) {
            $object = $objectOrClass->toObject();
            if (EnumCaseSupport::isEnumCase($object)) {
                return EnumSupport::resolveRuntimeEnumClass($ctx, $object->class);
            }

            return $object->class;
        }
        if (Variable::TYPE_ENUM_CASE === $objectOrClass->type) {
            return EnumSupport::resolveRuntimeEnumClass($ctx, $objectOrClass->toEnumCase()->enumClass);
        }

        return null;
    }

    public static function requireObjectOrClass(
        Variable $objectOrClass,
        string $function,
        string $paramLabel
    ): void {
        $objectOrClass = $objectOrClass->resolveIndirect();
        if (Variable::TYPE_STRING === $objectOrClass->type
            || Variable::TYPE_OBJECT === $objectOrClass->type
            || Variable::TYPE_ENUM_CASE === $objectOrClass->type) {
            return;
        }
        throw new \TypeError(\sprintf(
            '%s(): Argument #1 ($%s) must be of type object|string, %s given',
            $function,
            $paramLabel,
            self::vmTypeName($objectOrClass->type)
        ));
    }

    /** php-src ext/standard/class.c — get_parent_class() operand (#12689). */
    public static function requireObjectOrValidClassName(Variable $objectOrClass, string $function): void
    {
        $objectOrClass = $objectOrClass->resolveIndirect();
        if (Variable::TYPE_STRING === $objectOrClass->type
            || Variable::TYPE_OBJECT === $objectOrClass->type
            || Variable::TYPE_ENUM_CASE === $objectOrClass->type) {
            return;
        }
        throw new \TypeError(\sprintf(
            '%s(): Argument #1 ($object_or_class) must be an object or a valid class name, %s given',
            $function,
            self::vmTypeName($objectOrClass->type)
        ));
    }

    public static function vmTypeName(int $type): string
    {
        switch ($type) {
            case Variable::TYPE_INTEGER:
                return 'int';
            case Variable::TYPE_FLOAT:
                return 'float';
            case Variable::TYPE_BOOLEAN:
                return 'bool';
            case Variable::TYPE_STRING:
                return 'string';
            case Variable::TYPE_NULL:
                return 'null';
            case Variable::TYPE_ARRAY:
                return 'array';
            case Variable::TYPE_OBJECT:
                return 'object';
            case Variable::TYPE_ENUM_CASE:
                return 'object';
            default:
                return 'mixed';
        }
    }
}
