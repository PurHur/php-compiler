<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\Frame;
use PHPCompiler\VM\ClassEntry;
use PHPCompiler\VM\Context;
use PHPCompiler\VM\Variable;

/**
 * VM helpers for class / function introspection builtins (issues #1214–#1219, #1370–#1373).
 */
final class VmReflection
{
    public static function requireContext(Frame $frame): Context
    {
        if (null === $frame->vmContext) {
            throw new \LogicException('Reflection builtins require VM context');
        }

        return $frame->vmContext;
    }

    public static function stringArg(Variable $var, string $label): string
    {
        $var = $var->resolveIndirect();
        if (Variable::TYPE_STRING !== $var->type) {
            throw new \LogicException("{$label} must be a string in this compiler build");
        }

        return $var->toString();
    }

    public static function resolveClassEntry(Context $ctx, string $className): ?ClassEntry
    {
        $lc = strtolower($className);

        return $ctx->classes[$lc] ?? null;
    }

    public static function classExists(Context $ctx, string $className): bool
    {
        return self::typeExistsOfKind($ctx, $className, ClassEntry::KIND_CLASS);
    }

    public static function traitExists(Context $ctx, string $traitName): bool
    {
        return self::typeExistsOfKind($ctx, $traitName, ClassEntry::KIND_TRAIT);
    }

    public static function interfaceExists(Context $ctx, string $interfaceName): bool
    {
        return self::typeExistsOfKind($ctx, $interfaceName, ClassEntry::KIND_INTERFACE);
    }

    public static function enumExists(Context $ctx, string $enumName): bool
    {
        return self::typeExistsOfKind($ctx, $enumName, ClassEntry::KIND_ENUM);
    }

    public static function typeExistsOfKind(Context $ctx, string $name, int $kind): bool
    {
        $entry = self::resolveClassEntry($ctx, $name);

        return null !== $entry && $entry->kind === $kind;
    }

    public static function functionExists(Context $ctx, string $functionName): bool
    {
        return isset($ctx->functions[strtolower($functionName)]);
    }

    public static function methodExistsOnClass(ClassEntry $class, string $method): bool
    {
        return isset($class->methods[strtolower($method)]);
    }

    public static function resolveClassFromArg(Context $ctx, Variable $arg): ClassEntry
    {
        $arg = $arg->resolveIndirect();
        if (Variable::TYPE_STRING === $arg->type) {
            $entry = self::resolveClassEntry($ctx, $arg->toString());
            if (null === $entry) {
                throw new \LogicException('Unknown class name in this compiler build');
            }

            return $entry;
        }
        if (Variable::TYPE_OBJECT === $arg->type) {
            return $arg->toObject()->class;
        }
        throw new \LogicException('Expected object or class name string in this compiler build');
    }

    /**
     * Arguments passed to the innermost enclosing user function (excludes $this).
     *
     * @return list<Variable>
     */
    public static function userCallArgs(Frame $frame): array
    {
        for ($f = $frame->parent; null !== $f; $f = $f->parent) {
            if (null !== $f->block && null !== $f->block->func && !$f->hasHandler()) {
                $args = $f->calledArgs;
                if (null !== $f->block->func->class) {
                    return array_slice($args, 1);
                }

                return $args;
            }
        }
        throw new \LogicException('Must be called from a function context');
    }

    /** @param list<Variable> $args */
    public static function copyArgsToArray(array $args): Variable
    {
        $result = new Variable();
        $result->newArray();
        $ht = $result->toArray();
        foreach ($args as $arg) {
            $copy = new Variable();
            $copy->copyFrom($arg);
            $ht->append($copy);
        }

        return $result;
    }

    public static function isSameClass(Variable $value, string $className): bool
    {
        $value = $value->resolveIndirect();
        if (Variable::TYPE_OBJECT !== $value->type) {
            return false;
        }

        return strtolower($value->toObject()->class->name) === strtolower($className);
    }

    public static function propertyExistsOnClass(ClassEntry $class, string $property): bool
    {
        foreach ($class->properties as $prop) {
            if ($prop->name === $property) {
                return true;
            }
        }

        return false;
    }

    public static function propertyExists(Context $ctx, Variable $objectOrClass, string $property): bool
    {
        $objectOrClass = $objectOrClass->resolveIndirect();
        if (Variable::TYPE_OBJECT === $objectOrClass->type) {
            $props = $objectOrClass->toObject()->getProperties(ClassEntry::PROP_PURPOSE_DEBUG);

            return \array_key_exists($property, $props);
        }
        if (Variable::TYPE_STRING === $objectOrClass->type) {
            $entry = self::resolveClassEntry($ctx, $objectOrClass->toString());
            if (null === $entry) {
                return false;
            }

            return self::propertyExistsOnClass($entry, $property);
        }

        return false;
    }

    public static function getObjectVars(\PHPCompiler\VM\ObjectEntry $object): Variable
    {
        $result = new Variable();
        $result->newArray();
        $ht = $result->toArray();
        foreach ($object->getProperties(ClassEntry::PROP_PURPOSE_DEBUG) as $name => $value) {
            $copy = new Variable();
            $copy->copyFrom($value->resolveIndirect());
            $ht->add($name, $copy);
        }

        return $result;
    }
}
