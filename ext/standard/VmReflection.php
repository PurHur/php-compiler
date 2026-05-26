<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\Frame;
use PHPCompiler\VM\ClassEntry;
use PHPCompiler\VM\Context;
use PHPCompiler\VM\Variable;

/**
 * VM helpers for class / function introspection builtins (issues #1214–#1219).
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
        $entry = self::resolveClassEntry($ctx, $className);

        return null !== $entry && !$entry->isEnum && !$entry->isInterface && !$entry->isTrait;
    }

    public static function enumExists(Context $ctx, string $enumName): bool
    {
        return isset($ctx->enums[strtolower($enumName)]);
    }

    public static function interfaceExists(Context $ctx, string $interfaceName): bool
    {
        $entry = self::resolveClassEntry($ctx, $interfaceName);

        return null !== $entry && $entry->isInterface;
    }

    public static function traitExists(Context $ctx, string $traitName): bool
    {
        $entry = self::resolveClassEntry($ctx, $traitName);

        return null !== $entry && $entry->isTrait;
    }

    public static function functionExists(Context $ctx, string $functionName): bool
    {
        return isset($ctx->functions[strtolower($functionName)]);
    }

    public static function methodExistsOnClass(ClassEntry $class, string $method): bool
    {
        return isset($class->methods[strtolower($method)]);
    }

    public static function propertyExistsOnClass(ClassEntry $class, string $property): bool
    {
        $lc = strtolower($property);
        if (isset($class->staticProperties[$lc])) {
            return true;
        }
        foreach ($class->properties as $prop) {
            if (strtolower($prop->name) === $lc) {
                return true;
            }
        }

        return false;
    }

    public static function propertyExists(Context $ctx, Variable $objectOrClass, string $property): bool
    {
        $objectOrClass = $objectOrClass->resolveIndirect();
        if (Variable::TYPE_STRING === $objectOrClass->type) {
            $class = self::resolveClassEntry($ctx, $objectOrClass->toString());
            if (null === $class) {
                return false;
            }

            return self::propertyExistsOnClass($class, $property);
        }
        if (Variable::TYPE_OBJECT === $objectOrClass->type) {
            return self::propertyExistsOnClass($objectOrClass->toObject()->class, $property);
        }
        throw new \LogicException('property_exists() expects an object or class name string in this compiler build');
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

    /**
     * get_object_vars() — copy of accessible instance property values (issue #1370).
     */
    public static function getObjectVars(Variable $object): Variable
    {
        $object = $object->resolveIndirect();
        if (Variable::TYPE_OBJECT !== $object->type) {
            throw new \LogicException('get_object_vars() argument must be an object in this compiler build');
        }
        $result = new Variable();
        $result->newArray();
        $ht = $result->toArray();
        foreach ($object->toObject()->getProperties(0) as $name => $prop) {
            $value = $prop->resolveIndirect();
            if (Variable::TYPE_NULL === $value->type) {
                continue;
            }
            $copy = new Variable();
            $copy->copyFrom($value);
            $ht->add($name, $copy);
        }

        return $result;
    }
}
