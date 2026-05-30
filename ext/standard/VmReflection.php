<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\Frame;
use PHPCompiler\VM\ClassEntry;
use PHPCompiler\VM\Context;
use PHPCompiler\VM\InterfaceCheck;
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

    /**
     * get_declared_enums() — user enum class names (issue #3538).
     *
     * php-src: ext/standard/basic_functions.c — PHP_FUNCTION(get_declared_enums)
     */
    public static function declaredEnumsTable(Context $ctx): \PHPCompiler\VM\HashTable
    {
        $result = new \PHPCompiler\VM\HashTable();
        foreach ($ctx->classes as $lc => $entry) {
            if (!$entry->isEnum || isset($ctx->classAliases[$lc])) {
                continue;
            }
            $value = new Variable();
            $value->string($entry->name);
            $result->append($value);
        }

        return $result;
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

    /**
     * class_uses() operand — string or object; optional autoload (#3119).
     *
     * php-src: ext/standard/spl_functions.c — PHP_FUNCTION(class_uses)
     */
    public static function resolveClassForClassUses(Context $ctx, Variable $arg, bool $autoload): ?ClassEntry
    {
        $arg = $arg->resolveIndirect();
        if (Variable::TYPE_OBJECT === $arg->type) {
            return $arg->toObject()->class;
        }
        if (Variable::TYPE_STRING !== $arg->type) {
            return null;
        }
        $className = $arg->toString();
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

    /** @return array<string, string> trait name => trait name (Zend class_uses map) */
    public static function traitUsesMap(ClassEntry $class): array
    {
        return $class->usedTraits;
    }

    /**
     * class_implements() operand — string or object; optional autoload (#3099).
     *
     * php-src: ext/standard/basic_functions.c — PHP_FUNCTION(class_implements)
     */
    public static function resolveClassForClassImplements(Context $ctx, Variable $arg, bool $autoload): ?ClassEntry
    {
        return self::resolveClassForClassUses($ctx, $arg, $autoload);
    }

    /**
     * @return array<string, string> interface name => interface name (Zend class_implements map)
     */
    public static function classImplementsMap(ClassEntry $entry, Context $ctx): array
    {
        if ($entry->isTrait) {
            return [];
        }

        $result = [];
        if ($entry->isInterface) {
            self::addInterfaceAndParents($entry, $ctx, $result);

            return $result;
        }

        foreach ($entry->interfaces as $ifaceLc) {
            if (!isset($ctx->classes[$ifaceLc])) {
                continue;
            }
            self::addInterfaceAndParents($ctx->classes[$ifaceLc], $ctx, $result);
        }

        return $result;
    }

    /** @param array<string, string> $result */
    private static function addInterfaceAndParents(ClassEntry $iface, Context $ctx, array &$result): void
    {
        if (!$iface->isInterface) {
            return;
        }
        $name = $iface->name;
        $result[$name] = $name;
        foreach ($iface->interfaces as $parentLc) {
            if (!isset($ctx->classes[$parentLc])) {
                continue;
            }
            self::addInterfaceAndParents($ctx->classes[$parentLc], $ctx, $result);
        }
    }

    public static function classImplementsArray(ClassEntry $entry, Context $ctx): Variable
    {
        $result = new Variable();
        $result->newArray();
        $ht = $result->toArray();
        foreach (self::classImplementsMap($entry, $ctx) as $ifaceName) {
            $value = new Variable();
            $value->string($ifaceName);
            $ht->add($ifaceName, $value);
        }

        return $result;
    }

    public static function classUsesArray(ClassEntry $class): Variable
    {
        $result = new Variable();
        $result->newArray();
        $ht = $result->toArray();
        foreach (self::traitUsesMap($class) as $traitName) {
            $value = new Variable();
            $value->string($traitName);
            $ht->add($traitName, $value);
        }

        return $result;
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
     * is_a() / is_subclass_of() object operand — walk extends chain (#3478).
     *
     * php-src: ext/standard/class.c — instanceof_function / instanceof_function_ex
     */
    public static function isInstanceOf(Context $ctx, ClassEntry $entry, string $className): bool
    {
        return InterfaceCheck::entryIsInstanceOf($entry, strtolower(ltrim($className, '\\')), $ctx);
    }

    public static function isInstanceOfObject(Context $ctx, Variable $object, string $className): bool
    {
        $object = $object->resolveIndirect();
        if (Variable::TYPE_OBJECT !== $object->type) {
            return false;
        }

        return self::isInstanceOf($ctx, $object->toObject()->class, $className);
    }

    /**
     * is_subclass_of() class-string operand — strict subclass (excludes same class).
     */
    public static function isSubclassOf(Context $ctx, string $childName, string $parentName): bool
    {
        $child = self::resolveClassEntry($ctx, $childName);
        if (null === $child) {
            return false;
        }
        $parentLc = strtolower(ltrim($parentName, '\\'));
        if (strtolower($child->name) === $parentLc) {
            return false;
        }

        return InterfaceCheck::entryIsInstanceOf($child, $parentLc, $ctx);
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
