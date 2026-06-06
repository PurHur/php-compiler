<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\Frame;
use PHPCompiler\Func;
use PHPCompiler\Func\Internal as FuncInternal;
use PHPCompiler\MethodVisibility;
use PHPCompiler\VM\ClassEntry;
use PHPCompiler\VM\ClassProperty;
use PHPCompiler\VM\Context;
use PHPCompiler\VM\EnumCaseSupport;
use PHPCompiler\VM\EnumSupport;
use PHPCompiler\VM\InterfaceCheck;
use PHPCompiler\VM\ReflectionSupport;
use PHPCompiler\VM\TypedPropertyCheck;
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

    /**
     * get_declared_interfaces() — numerically indexed interface name list (issue #3176).
     *
     * php-src: ext/standard/basic_functions.c — PHP_FUNCTION(get_declared_interfaces)
     */
    public static function declaredInterfacesTable(Context $ctx): \PHPCompiler\VM\HashTable
    {
        $result = new \PHPCompiler\VM\HashTable();
        foreach ($ctx->classes as $lc => $entry) {
            if (!$entry->isInterface || isset($ctx->classAliases[$lc])) {
                continue;
            }
            $value = new Variable();
            $value->string($entry->name);
            $result->append($value);
        }

        return $result;
    }

    /**
     * get_declared_classes() — numerically indexed class name list (issue #3128).
     *
     * php-src: ext/standard/basic_functions.c — PHP_FUNCTION(get_declared_classes)
     */
    public static function declaredClassesTable(Context $ctx): \PHPCompiler\VM\HashTable
    {
        $result = new \PHPCompiler\VM\HashTable();
        foreach ($ctx->classes as $lc => $entry) {
            if ($entry->isInterface || $entry->isTrait || isset($ctx->classAliases[$lc])) {
                continue;
            }
            $value = new Variable();
            $value->string($entry->name);
            $result->append($value);
        }

        return $result;
    }

    /**
     * get_declared_traits() — numerically indexed trait name list (issue #3128).
     *
     * php-src: ext/standard/basic_functions.c — PHP_FUNCTION(get_declared_traits)
     */
    public static function declaredTraitsTable(Context $ctx): \PHPCompiler\VM\HashTable
    {
        $result = new \PHPCompiler\VM\HashTable();
        foreach ($ctx->classes as $lc => $entry) {
            if (!$entry->isTrait || isset($ctx->classAliases[$lc])) {
                continue;
            }
            $value = new Variable();
            $value->string($entry->name);
            $result->append($value);
        }

        return $result;
    }

    /** @var list<string>|null */
    private static ?array $internalFunctionNames = null;

    /**
     * Registered ext Module internal function names (php-src internal bucket).
     *
     * @return list<string>
     */
    public static function internalFunctionNameList(): array
    {
        if (null !== self::$internalFunctionNames) {
            return self::$internalFunctionNames;
        }
        $names = [];
        foreach ([new Module(), new \PHPCompiler\ext\types\Module()] as $module) {
            foreach ($module->getFunctions() as $func) {
                $names[] = $func->getName();
            }
        }
        $names = array_values(array_unique($names));
        sort($names);
        self::$internalFunctionNames = $names;

        return self::$internalFunctionNames;
    }

    /**
     * get_defined_functions() — internal/user name lists (issue #3128).
     *
     * php-src: ext/standard/basic_functions.c — PHP_FUNCTION(get_defined_functions)
     */
    public static function definedFunctionsTable(Context $ctx): \PHPCompiler\VM\HashTable
    {
        $result = new \PHPCompiler\VM\HashTable();

        $internalVar = new Variable();
        $internalVar->newArray();
        $internalHt = $internalVar->toArray();
        foreach (self::internalFunctionNameList() as $name) {
            $value = new Variable();
            $value->string($name);
            $internalHt->append($value);
        }
        $result->add('internal', $internalVar);

        $userVar = new Variable();
        $userVar->newArray();
        $userHt = $userVar->toArray();
        foreach ($ctx->functions as $func) {
            if ($func instanceof FuncInternal) {
                continue;
            }
            $value = new Variable();
            $value->string($func->getName());
            $userHt->append($value);
        }
        $result->add('user', $userVar);

        return $result;
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
        $methodLc = strtolower($method);
        if ($class->isEnum) {
            EnumSupport::ensureBuiltinCasesMethod($class);
            if ('cases' === $methodLc) {
                return true;
            }
            if (null !== $class->backedType && ('from' === $methodLc || 'tryfrom' === $methodLc)) {
                return true;
            }
        }

        return isset($class->methods[$methodLc]);
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

    /**
     * Declared instance property name on $class or an ancestor, or null.
     *
     * php-src: zend_get_property_info — walk CE hierarchy
     */
    public static function findInstancePropertyName(ClassEntry $class, string $property, Context $ctx): ?string
    {
        $meta = self::findClassProperty($class, $property, $ctx);

        return null !== $meta ? $meta->name : null;
    }

    /**
     * Instance property metadata on $class or an ancestor, or null (#4395).
     */
    public static function findClassProperty(ClassEntry $class, string $property, Context $ctx): ?ClassProperty
    {
        $lc = strtolower($property);
        $current = $class;
        while (true) {
            foreach ($current->properties as $prop) {
                if (strtolower($prop->name) === $lc) {
                    return $prop;
                }
            }
            if (null === $current->parentLc || !isset($ctx->classes[$current->parentLc])) {
                return null;
            }
            $current = $ctx->classes[$current->parentLc];
        }
    }

    /**
     * Build a ReflectionProperty VM object for $prop on reflected class $reflectedClassName.
     */
    public static function reflectionPropertyObject(
        Context $ctx,
        string $reflectedClassName,
        ClassProperty $prop
    ): \PHPCompiler\VM\ObjectEntry {
        $rpClass = $ctx->classes[ReflectionSupport::REFLECTION_PROPERTY] ?? null;
        if (null === $rpClass) {
            throw new \LogicException('ReflectionProperty is not registered in this compiler build');
        }
        $obj = new \PHPCompiler\VM\ObjectEntry($rpClass);
        $obj->constructed = true;
        $obj->getProperty(ReflectionSupport::PROP_CLASS_NAME)->string($reflectedClassName);
        $obj->getProperty(ReflectionSupport::PROP_PROPERTY_NAME)->string($prop->name);

        return $obj;
    }

    /** Static property storage key on $class or an ancestor, or null. */
    public static function findStaticPropertyKey(ClassEntry $class, string $property, Context $ctx): ?string
    {
        $lc = strtolower($property);
        $current = $class;
        while (true) {
            if (isset($current->staticProperties[$lc])) {
                return $lc;
            }
            if (null === $current->parentLc || !isset($ctx->classes[$current->parentLc])) {
                return null;
            }
            $current = $ctx->classes[$current->parentLc];
        }
    }

    /**
     * Class constant value storage key on $class or an ancestor, or null.
     */
    public static function findClassConstantKey(ClassEntry $class, string $constant, Context $ctx): ?string
    {
        $lc = strtolower($constant);
        $current = $class;
        while (true) {
            if (isset($current->constants[$lc])) {
                return $lc;
            }
            if (null === $current->parentLc || !isset($ctx->classes[$current->parentLc])) {
                return null;
            }
            $current = $ctx->classes[$current->parentLc];
        }
    }

    public static function requireFunction(Context $ctx, string $functionName): Func
    {
        $lc = strtolower(ltrim($functionName, '\\'));
        if (!isset($ctx->functions[$lc])) {
            throw new \LogicException("Function {$functionName} does not exist");
        }

        return $ctx->functions[$lc];
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
            $object = $objectOrClass->toObject();
            if (EnumCaseSupport::isEnumCase($object)) {
                return EnumCaseSupport::propertyExistsOnCase($object->class, $property);
            }

            return self::propertyExistsOnClass($object->class, $property);
        }
        if (Variable::TYPE_ENUM_CASE === $objectOrClass->type) {
            return EnumCaseSupport::propertyExistsOnCase(
                $objectOrClass->toEnumCase()->enumClass,
                $property
            );
        }
        throw new \TypeError('property_exists(): Argument #1 ($object_or_class) must be of type object|string, '
            .self::propertyExistsInvalidTypeName($objectOrClass->type).' given');
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

    /**
     * trait name => trait name (Zend class_uses map).
     *
     * @return array<string, string>
     */
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
     * interface name => interface name (Zend class_implements map).
     *
     * @return array<string, string>
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

    /**
     * trait name => trait name — direct + nested trait uses (#6469).
     *
     * php-src: ext/standard/class.c — PHP_FUNCTION(class_uses_recursive)
     *
     * @return array<string, string>
     */
    public static function traitUsesRecursiveMap(Context $ctx, ClassEntry $class): array
    {
        $result = [];
        self::collectTraitUsesRecursive($ctx, $class, $result);

        return $result;
    }

    /**
     * @param array<string, string> $result
     */
    private static function collectTraitUsesRecursive(Context $ctx, ClassEntry $entry, array &$result): void
    {
        foreach (self::traitUsesMap($entry) as $traitName) {
            $result[$traitName] = $traitName;
            $traitLc = strtolower(ltrim($traitName, '\\'));
            if (isset($ctx->classes[$traitLc])) {
                self::collectTraitUsesRecursive($ctx, $ctx->classes[$traitLc], $result);
            }
        }
    }

    public static function classUsesRecursiveArray(ClassEntry $class, Context $ctx): Variable
    {
        $result = new Variable();
        $result->newArray();
        $ht = $result->toArray();
        foreach (self::traitUsesRecursiveMap($ctx, $class) as $traitName) {
            $value = new Variable();
            $value->string($traitName);
            $ht->add($traitName, $value);
        }

        return $result;
    }

    /**
     * Parent class FQCN for get_parent_class() / class_parents() (issue #3483).
     *
     * php-src: ext/standard/class.c — PHP_FUNCTION(get_parent_class)
     */
    public static function parentClassName(ClassEntry $entry, Context $ctx): ?string
    {
        if (null === $entry->parentLc || !isset($ctx->classes[$entry->parentLc])) {
            return null;
        }

        return $ctx->classes[$entry->parentLc]->name;
    }

    /**
     * class_parents() — ordered parent class names from immediate parent to root (#3159).
     *
     * php-src: ext/standard/class.c — PHP_FUNCTION(class_parents)
     *
     * @return list<string>
     */
    public static function classParentsList(ClassEntry $entry, Context $ctx): array
    {
        $parents = [];
        $current = $entry;
        while (null !== $current->parentLc && isset($ctx->classes[$current->parentLc])) {
            $parent = $ctx->classes[$current->parentLc];
            $parents[] = $parent->name;
            $current = $parent;
        }

        return $parents;
    }

    /** Empty VM array for class_parents() enum-case / no-parent operands (#6336). */
    public static function emptyArray(): Variable
    {
        $result = new Variable();
        $result->newArray();

        return $result;
    }

    /**
     * class_parents() result as a numerically indexed VM array (#3159).
     */
    public static function classParentsArray(ClassEntry $entry, Context $ctx): Variable
    {
        $result = new Variable();
        $result->newArray();
        $ht = $result->toArray();
        foreach (self::classParentsList($entry, $ctx) as $parentName) {
            $value = new Variable();
            $value->string($parentName);
            $ht->add($parentName, $value);
        }

        return $result;
    }

    /**
     * get_class_vars() — default values for public properties declared on $entry (#3159).
     *
     * php-src: ext/standard/class.c — PHP_FUNCTION(get_class_vars)
     */
    public static function getClassVarsArray(ClassEntry $entry): Variable
    {
        $result = new Variable();
        $result->newArray();
        $ht = $result->toArray();
        foreach ($entry->properties as $prop) {
            if (!MethodVisibility::isPublic($prop->visibility)) {
                continue;
            }
            $copy = new Variable();
            // php-src add_class_vars: skip ZEND_ACC_VIRTUAL (no class-level get-hook invocation).
            if ($prop->propertyHookVirtual) {
                continue;
            }
            if (null !== $prop->default && !$prop->hasRuntimeDefaultInit()) {
                $copy->copyFrom($prop->default);
            } else {
                $src = $prop->getVariable();
                if ($src->isUndefined()) {
                    $copy->null();
                } else {
                    $copy->copyFrom($src);
                }
            }
            $ht->add($prop->name, $copy);
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
            $object = $arg->toObject();
            if (EnumCaseSupport::isEnumCase($object)) {
                return EnumSupport::resolveRuntimeEnumClass($ctx, $object->class);
            }

            return $object->class;
        }
        if (Variable::TYPE_ENUM_CASE === $arg->type) {
            return EnumSupport::resolveRuntimeEnumClass($ctx, $arg->toEnumCase()->enumClass);
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
        $enumMatch = EnumCaseSupport::valueMatchesInstanceOfClassName($object, $className, $ctx);
        if (null !== $enumMatch) {
            return $enumMatch;
        }
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
     * get_object_vars() — accessible instance properties; get hooks invoked (#5203, #6453).
     *
     * php-src: zend_get_properties_for(..., ZEND_PROP_PURPOSE_GET_OBJECT_VARS)
     */
    public static function getObjectVars(Variable $object, Frame $frame): Variable
    {
        $object = $object->resolveIndirect();
        if (Variable::TYPE_OBJECT !== $object->type) {
            throw new \TypeError(\sprintf(
                'get_object_vars(): Argument #1 ($object) must be of type object, %s given',
                VmStreamArg::debugTypeName($object)
            ));
        }
        $ctx = self::requireContext($frame);
        $result = new Variable();
        $result->newArray();
        $ht = $result->toArray();
        foreach ($ctx->runtime->vm()->collectObjectVarsForBuiltin($object->toObject(), $frame) as $name => $value) {
            $ht->add($name, $value);
        }

        return $result;
    }

    /**
     * get_mangled_object_vars() — all set instance properties with Zend-mangled keys (issue #3497).
     *
     * php-src: ext/standard/var.c — PHP_FUNCTION(get_mangled_object_vars)
     */
    public static function getMangledObjectVars(Variable $object, Context $ctx): Variable
    {
        $object = $object->resolveIndirect();
        if (Variable::TYPE_OBJECT !== $object->type) {
            throw new \LogicException('get_mangled_object_vars() argument must be an object in this compiler build');
        }
        $obj = $object->toObject();
        $result = new Variable();
        $result->newArray();
        $ht = $result->toArray();
        foreach ($obj->class->properties as $meta) {
            if (!$obj->hasProperty($meta->name)) {
                continue;
            }
            $value = $obj->getProperty($meta->name)->resolveIndirect();
            if (TypedPropertyCheck::omitFromPropertyEnumeration($value)) {
                continue;
            }
            $key = self::manglePropertyKey($meta, $ctx);
            $copy = new Variable();
            $copy->copyFrom($value);
            $ht->add($key, $copy);
        }

        return $result;
    }

    /**
     * Zend property hash key for ZEND_PROP_PURPOSE_DEBUG (php-src zend_mangle_property_name).
     */
    public static function manglePropertyKey(ClassProperty $meta, Context $ctx): string
    {
        if (MethodVisibility::isPublic($meta->visibility)) {
            return $meta->name;
        }
        if (($meta->visibility & \PHPCfg\Func::FLAG_PROTECTED) !== 0) {
            return "\0*\0".$meta->name;
        }

        return "\0".self::declaringClassDisplay($meta, $ctx)."\0".$meta->name;
    }

    private static function declaringClassDisplay(ClassProperty $meta, Context $ctx): string
    {
        if ('' !== $meta->declaringClassLc && isset($ctx->classes[$meta->declaringClassLc])) {
            return $ctx->classes[$meta->declaringClassLc]->name;
        }

        return $meta->declaringClassLc;
    }

    /** Default visibility filter: public | protected | private (php-src get_class_methods). */
    public const METHOD_FILTER_DEFAULT = \PHPCfg\Func::FLAG_PUBLIC
        | \PHPCfg\Func::FLAG_PROTECTED
        | \PHPCfg\Func::FLAG_PRIVATE;

    /**
     * get_class_methods() operand — object or class name string (#3118).
     */
    public static function resolveClassForGetClassMethods(Context $ctx, Variable $arg): ?ClassEntry
    {
        $arg = $arg->resolveIndirect();
        if (Variable::TYPE_OBJECT === $arg->type) {
            return $arg->toObject()->class;
        }
        if (Variable::TYPE_STRING === $arg->type) {
            $className = $arg->toString();
            $lc = strtolower(ltrim($className, '\\'));
            if (!isset($ctx->classes[$lc])) {
                $ctx->autoloadClass($className);
            }

            return $ctx->classes[$lc] ?? null;
        }

        throw new \LogicException('get_class_methods() argument must be an object or class name string in this compiler build');
    }

    /**
     * @return list<string>
     */
    public static function classMethodsList(ClassEntry $entry, int $filter = 7): array
    {
        $names = [];
        foreach ($entry->methods as $methodLc => $_method) {
            $vis = $entry->methodVisibility[$methodLc] ?? \PHPCfg\Func::FLAG_PUBLIC;
            if (0 !== ($filter & 7) && 0 === ($vis & $filter & 7)) {
                continue;
            }
            $names[] = $entry->methodNames[$methodLc] ?? $methodLc;
        }

        return $names;
    }

    public static function classMethodsArray(ClassEntry $entry, int $filter = 7): Variable
    {
        $result = new Variable();
        $result->newArray();
        $ht = $result->toArray();
        foreach (self::classMethodsList($entry, $filter) as $methodName) {
            $value = new Variable();
            $value->string($methodName);
            $ht->append($value);
        }

        return $result;
    }

    /**
     * Called class for get_called_class() (issue #3218).
     *
     * php-src: ext/standard/basic_functions.c — php_get_called_class()
     */
    public static function getCalledClass(Frame $frame): string
    {
        $current = $frame->parent;
        if (null === $current) {
            throw new \Error('get_called_class() must be called from within a class');
        }
        if (null !== $current->calledClass && '' !== $current->calledClass) {
            return $current->calledClass;
        }
        if (null === $current->block || null === $current->block->func || null === $current->block->func->class) {
            throw new \Error('get_called_class() must be called from within a class');
        }
        $thisIdx = $current->block->slotIndexForVariableName('this');
        if (null !== $thisIdx && isset($current->scope[$thisIdx])) {
            $thisVar = $current->scope[$thisIdx]->resolveIndirect();
            if (Variable::TYPE_OBJECT === $thisVar->type) {
                return $thisVar->toObject()->class->name;
            }
        }

        return $current->block->func->class->value;
    }

    /** php-src ReflectionProperty::IS_* visibility bitmask (not PHPCfg flags). */
    public const REFLECTION_IS_PUBLIC = 256;

    public const REFLECTION_IS_PROTECTED = 512;

    public const REFLECTION_IS_PRIVATE = 1024;

    /**
     * Class hierarchy from $entry to root parent (child-first).
     *
     * @return list<ClassEntry>
     */
    public static function classHierarchyChain(ClassEntry $entry, Context $ctx): array
    {
        $chain = [$entry];
        $current = $entry;
        while (null !== $current->parentLc && isset($ctx->classes[$current->parentLc])) {
            $current = $ctx->classes[$current->parentLc];
            $chain[] = $current;
        }

        return $chain;
    }

    public static function matchesReflectionVisibilityFilter(int $cfgVisibility, int $filter): bool
    {
        if (0 === $filter) {
            return true;
        }

        return (self::visibilityToReflectionBitmask($cfgVisibility) & $filter) !== 0;
    }

    public static function visibilityToReflectionBitmask(int $cfgVisibility): int
    {
        if (($cfgVisibility & \PHPCfg\Func::FLAG_PRIVATE) !== 0) {
            return self::REFLECTION_IS_PRIVATE;
        }
        if (($cfgVisibility & \PHPCfg\Func::FLAG_PROTECTED) !== 0) {
            return self::REFLECTION_IS_PROTECTED;
        }

        return self::REFLECTION_IS_PUBLIC;
    }

    /**
     * Instance properties visible on $entry (child overrides parent), php-src ReflectionClass::getProperties.
     *
     * @return list<ClassProperty>
     */
    public static function collectClassPropertiesForReflection(ClassEntry $entry, Context $ctx, int $filter = 0): array
    {
        $byLc = [];
        foreach (array_reverse(self::classHierarchyChain($entry, $ctx)) as $class) {
            foreach ($class->properties as $prop) {
                if (!self::matchesReflectionVisibilityFilter($prop->visibility, $filter)) {
                    continue;
                }
                $byLc[strtolower($prop->name)] = $prop;
            }
        }

        return array_values($byLc);
    }

    /**
     * Methods visible on $entry (child overrides parent), php-src ReflectionClass::getMethods.
     *
     * @return list<array{methodLc: string, display: string, declaring: ClassEntry}>
     */
    public static function collectClassMethodsForReflection(ClassEntry $entry, Context $ctx, int $filter = 0): array
    {
        $byLc = [];
        foreach (array_reverse(self::classHierarchyChain($entry, $ctx)) as $class) {
            foreach ($class->methods as $methodLc => $_func) {
                $vis = $class->methodVisibility[$methodLc] ?? \PHPCfg\Func::FLAG_PUBLIC;
                if (!self::matchesReflectionVisibilityFilter($vis, $filter)) {
                    continue;
                }
                $byLc[$methodLc] = [
                    'methodLc' => $methodLc,
                    'display' => $class->methodNames[$methodLc] ?? $methodLc,
                    'declaring' => $class,
                ];
            }
        }

        return array_values($byLc);
    }

    /**
     * ReflectionClass::getProperties() result array (#3815).
     */
    public static function reflectionPropertiesArray(
        Context $ctx,
        ClassEntry $entry,
        string $reflectedClassName,
        int $filter = 0
    ): Variable {
        $rpClass = $ctx->classes[\PHPCompiler\VM\ReflectionSupport::REFLECTION_PROPERTY] ?? null;
        if (null === $rpClass) {
            throw new \LogicException('ReflectionProperty is not registered in this compiler build');
        }
        $result = new Variable();
        $result->newArray();
        $ht = $result->toArray();
        foreach (self::collectClassPropertiesForReflection($entry, $ctx, $filter) as $prop) {
            $obj = new \PHPCompiler\VM\ObjectEntry($rpClass);
            $obj->constructed = true;
            $obj->getProperty(\PHPCompiler\VM\ReflectionSupport::PROP_CLASS_NAME)->string($reflectedClassName);
            $obj->getProperty(\PHPCompiler\VM\ReflectionSupport::PROP_PROPERTY_NAME)->string($prop->name);
            $slot = new Variable(Variable::TYPE_OBJECT);
            $slot->object($obj);
            $ht->append($slot);
        }

        return $result;
    }

    /**
     * ReflectionClass::getMethods() result array (#3815).
     */
    public static function reflectionMethodsArray(
        Context $ctx,
        ClassEntry $entry,
        string $reflectedClassName,
        int $filter = 0
    ): Variable {
        $rmClass = $ctx->classes[\PHPCompiler\VM\ReflectionSupport::REFLECTION_METHOD] ?? null;
        if (null === $rmClass) {
            throw new \LogicException('ReflectionMethod is not registered in this compiler build');
        }
        $result = new Variable();
        $result->newArray();
        $ht = $result->toArray();
        foreach (self::collectClassMethodsForReflection($entry, $ctx, $filter) as $spec) {
            $obj = new \PHPCompiler\VM\ObjectEntry($rmClass);
            $obj->constructed = true;
            $obj->getProperty(\PHPCompiler\VM\ReflectionSupport::PROP_CLASS_NAME)->string($reflectedClassName);
            $obj->getProperty(\PHPCompiler\VM\ReflectionSupport::PROP_METHOD_NAME)->string($spec['display']);
            $slot = new Variable(Variable::TYPE_OBJECT);
            $slot->object($obj);
            $ht->append($slot);
        }

        return $result;
    }

    public static function optionalReflectionFilterArg(Frame $frame, int $argIndex): int
    {
        if (\count($frame->calledArgs) <= $argIndex) {
            return 0;
        }
        $filterArg = $frame->calledArgs[$argIndex]->resolveIndirect();
        if (Variable::TYPE_INTEGER !== $filterArg->type) {
            return 0;
        }

        return $filterArg->toInt();
    }

    /**
     * Build a constructed ReflectionEnumUnitCase for an enum case (#4121).
     */
    public static function newReflectionEnumUnitCase(
        Context $ctx,
        ClassEntry $enumEntry,
        string $caseName
    ): \PHPCompiler\VM\ObjectEntry {
        $reucClass = $ctx->classes[\PHPCompiler\VM\ReflectionSupport::REFLECTION_ENUM_UNIT_CASE] ?? null;
        if (null === $reucClass) {
            throw new \LogicException('ReflectionEnumUnitCase is not registered in this compiler build');
        }
        $caseLc = strtolower($caseName);
        if (!isset($enumEntry->enumCaseCanonicalNames[$caseLc])) {
            throw new \LogicException('Enum '.$enumEntry->name.' has no case named '.$caseName);
        }
        $obj = new \PHPCompiler\VM\ObjectEntry($reucClass);
        $obj->constructed = true;
        $obj->getProperty(\PHPCompiler\VM\ReflectionSupport::PROP_CLASS_NAME)->string($enumEntry->name);
        $obj->getProperty(\PHPCompiler\VM\ReflectionSupport::PROP_ENUM_CASE_NAME)->string(
            $enumEntry->enumCaseCanonicalNames[$caseLc]
        );

        return $obj;
    }

    /**
     * attribute_exists() — whether a class declares the given attribute (#6468).
     *
     * php-src: ext/reflection/php_reflection.c — PHP_FUNCTION(attribute_exists)
     */
    public static function attributeExists(Context $ctx, string $className, string $attributeName): bool
    {
        $entry = self::resolveClassEntry($ctx, $className);
        if (null === $entry) {
            return false;
        }
        if ([] !== ReflectionSupport::filterEntriesByName($entry->attributeEntries, $attributeName)) {
            return true;
        }

        return [] !== ReflectionSupport::filterByName($entry->attributeNames, $attributeName);
    }

    /**
     * ReflectionEnum::getCases() result array (#4121).
     */
    public static function reflectionEnumCasesArray(Context $ctx, ClassEntry $enumEntry): Variable
    {
        $result = new Variable();
        $result->newArray();
        $ht = $result->toArray();
        foreach ($enumEntry->enumCases as $case) {
            $obj = self::newReflectionEnumUnitCase($ctx, $enumEntry, $case['name']);
            $slot = new Variable(Variable::TYPE_OBJECT);
            $slot->object($obj);
            $ht->append($slot);
        }

        return $result;
    }

    private static function propertyExistsInvalidTypeName(int $type): string
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
            default:
                return 'mixed';
        }
    }
}
