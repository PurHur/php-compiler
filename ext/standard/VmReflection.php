<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\CompilerVersion;
use PHPCompiler\Frame;
use PHPCompiler\Func;
use PHPCompiler\Func\Internal as FuncInternal;
use PHPCompiler\Compiler\AttributeClassRegistry;
use PHPCompiler\MethodVisibility;
use PHPCfg\Func as CfgFunc;
use PHPCompiler\VM\ClassEntry;
use PHPCompiler\VM\ClassProperty;
use PHPCompiler\VM\Context;
use PHPCompiler\VM\EnumCaseSupport;
use PHPCompiler\VM\EnumSupport;
use PHPCompiler\VM\HashTable;
use PHPCompiler\VM\InterfaceCheck;
use PHPCompiler\VM\LazyGhostTraitSupport;
use PHPCompiler\VM\ReflectionSupport;
use PHPCompiler\VM\StringableSupport;
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

    /**
     * Strip leading backslash on global names for introspection builtins (#12176).
     *
     * php-src: ext/standard/basic_functions.c — php_stripcslashes / name normalization
     */
    public static function normalizeGlobalIntrospectionName(string $name): string
    {
        return ltrim($name, '\\');
    }

    /**
     * Optional $exclude_deprecated for get_declared_* (PHP 8.4, #12177 / #4711).
     *
     * php-src: ext/standard/basic_functions.c — Z_PARAM_OPTIONAL Z_PARAM_BOOL
     */
    public static function parseExcludeDeprecatedArg(Frame $frame, string $function): bool
    {
        $argc = \count($frame->calledArgs);
        if (!CompilerVersion::supportsGetDeclaredExcludeDeprecated()) {
            if ($argc > 0) {
                throw new \ArgumentCountError("{$function}() expects exactly 0 arguments, {$argc} given");
            }

            return false;
        }
        if ($argc > 1) {
            throw new \ArgumentCountError("{$function}() expects at most 1 argument, {$argc} given");
        }
        if (0 === $argc) {
            return false;
        }

        return $frame->calledArgs[0]->resolveIndirect()->toBool();
    }

    public static function isDeprecatedClassEntry(ClassEntry $entry): bool
    {
        return null !== $entry->classDeprecated;
    }

    /**
     * Coerce a string parameter for VM builtins / internal methods (php-src Z_PARAM_STR, #7163).
     *
     * @param int $calledArgsIndex index in Frame::calledArgs for Zend-shaped Argument #N
     */
    public static function stringArg(Variable $var, string $label, int $calledArgsIndex = 0): string
    {
        if (!preg_match('/^(.+?)\(\)\s+(.+)$/', $label, $m)) {
            return VmString::coerceStringBuiltinArg($var, $label, $calledArgsIndex, 'string');
        }
        $function = $m[1];
        $paramName = $m[2];
        $argIndex = str_contains($function, '::')
            ? max(0, $calledArgsIndex - 1)
            : $calledArgsIndex;

        return VmString::coerceStringBuiltinArg($var, $function, $argIndex, $paramName);
    }

    public static function resolveClassEntry(Context $ctx, string $className): ?ClassEntry
    {
        $lc = strtolower(self::normalizeGlobalIntrospectionName($className));

        return $ctx->classes[$lc] ?? null;
    }

    public static function classExists(Context $ctx, string $className): bool
    {
        $entry = self::resolveClassEntry($ctx, $className);

        return null !== $entry
            && !$entry->isInterface
            && !$entry->isTrait
            && !\PHPCompiler\VM\ResourceSupport::isHiddenPseudoClassEntry($entry);
    }

    public static function enumExists(Context $ctx, string $enumName): bool
    {
        return isset($ctx->enums[strtolower(self::normalizeGlobalIntrospectionName($enumName))]);
    }

    /**
     * unitenum_exists() — true only for pure (non-backed) user enums (#6884).
     *
     * php-src: ext/standard/basic_functions.c — PHP_FUNCTION(unitenum_exists)
     */
    public static function unitEnumExists(Context $ctx, string $enumName): bool
    {
        $entry = self::resolveClassEntry($ctx, $enumName);

        return null !== $entry && $entry->isEnum && null === $entry->backedType;
    }

    /**
     * Internal enum class name list (issue #3538; not a php-src builtin — #11248).
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
    public static function declaredInterfacesTable(Context $ctx, bool $excludeDeprecated = false): \PHPCompiler\VM\HashTable
    {
        $result = new \PHPCompiler\VM\HashTable();
        foreach ($ctx->classes as $lc => $entry) {
            if (!$entry->isInterface || isset($ctx->classAliases[$lc])) {
                continue;
            }
            if ($excludeDeprecated && self::isDeprecatedClassEntry($entry)) {
                continue;
            }
            $value = new Variable();
            $value->string($entry->name);
            $result->append($value);
        }

        return $result;
    }

    /**
     * get_included_files() / get_required_files() — loaded compile units (#3315).
     *
     * php-src: ext/standard/basic_functions.c — PHP_FUNCTION(get_included_files)
     */
    public static function includedFilesTable(Context $ctx): \PHPCompiler\VM\HashTable
    {
        $result = new \PHPCompiler\VM\HashTable();
        foreach ($ctx->includedFiles() as $path) {
            $value = new Variable();
            $value->string($path);
            $result->append($value);
        }

        return $result;
    }

    /**
     * get_declared_classes() — numerically indexed class name list (issue #3128).
     *
     * php-src: ext/standard/basic_functions.c — PHP_FUNCTION(get_declared_classes)
     */
    public static function declaredClassesTable(Context $ctx, bool $excludeDeprecated = false): \PHPCompiler\VM\HashTable
    {
        $result = new \PHPCompiler\VM\HashTable();
        foreach ($ctx->classes as $lc => $entry) {
            self::markCompilerBootstrapClassInternal($entry);
            if ($entry->isInterface || $entry->isTrait || isset($ctx->classAliases[$lc])) {
                continue;
            }
            if (\PHPCompiler\VM\ResourceSupport::isHiddenPseudoClassEntry($entry)) {
                continue;
            }
            // Hide compiler bootstrap types only — CE_INTERNAL builtins belong in the list (#11813, #11688).
            if (str_starts_with($entry->name, 'PHPCompiler\\')) {
                continue;
            }
            if ($excludeDeprecated && self::isDeprecatedClassEntry($entry)) {
                continue;
            }
            $value = new Variable();
            $value->string($entry->name);
            $result->append($value);
        }

        return $result;
    }

    /**
     * Compiler bootstrap types (PHPCompiler\\*) are CE_INTERNAL for user reflection (#11688).
     */
    public static function markCompilerBootstrapClassInternal(ClassEntry $entry): void
    {
        if (!$entry->isInternal && str_starts_with($entry->name, 'PHPCompiler\\')) {
            $entry->isInternal = true;
        }
    }

    /**
     * get_declared_traits() — numerically indexed trait name list (issue #3128).
     *
     * php-src: ext/standard/basic_functions.c — PHP_FUNCTION(get_declared_traits)
     */
    public static function declaredTraitsTable(Context $ctx, bool $excludeDeprecated = false): \PHPCompiler\VM\HashTable
    {
        $result = new \PHPCompiler\VM\HashTable();
        foreach ($ctx->classes as $lc => $entry) {
            if (!$entry->isTrait || isset($ctx->classAliases[$lc])) {
                continue;
            }
            if (LazyGhostTraitSupport::isLazyGhostTrait($entry->name)) {
                continue;
            }
            if ($excludeDeprecated && self::isDeprecatedClassEntry($entry)) {
                continue;
            }
            $value = new Variable();
            $value->string($entry->name);
            $result->append($value);
        }

        return $result;
    }

    /**
     * get_declared_attributes() — user #[Attribute] class names (#6450).
     *
     * php-src: ext/reflection/php_reflection.c — PHP_FUNCTION(get_declared_attributes)
     */
    public static function declaredAttributesTable(Context $ctx): \PHPCompiler\VM\HashTable
    {
        $result = new \PHPCompiler\VM\HashTable();
        foreach ($ctx->classes as $lc => $entry) {
            if ($entry->isInternal || isset($ctx->classAliases[$lc])) {
                continue;
            }
            if (!AttributeClassRegistry::isRegisteredAttributeClass($entry->attributeEntries)) {
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
        if (LazyGhostTraitSupport::isLazyGhostTrait($traitName)) {
            return false;
        }
        $entry = self::resolveClassEntry($ctx, $traitName);

        return null !== $entry && $entry->isTrait;
    }

    public static function functionExists(Context $ctx, string $functionName): bool
    {
        return isset($ctx->functions[strtolower(self::normalizeGlobalIntrospectionName($functionName))]);
    }

    /**
     * Owning extension module for a registered builtin (php-src internal function bucket, #6678).
     */
    public static function extensionNameForFunction(Context $ctx, string $functionName): string
    {
        $lc = strtolower($functionName);
        $registered = $ctx->functions[$lc] ?? null;
        if (null !== $registered) {
            foreach ($ctx->runtime->modules as $module) {
                foreach ($module->getFunctions() as $func) {
                    if ($func === $registered) {
                        return self::reflectionExtensionName($module->getExtensionName(), $lc);
                    }
                }
            }
        }
        $extension = 'standard';
        foreach ($ctx->runtime->modules as $module) {
            foreach ($module->getFunctions() as $func) {
                if (strtolower($func->getName()) === $lc) {
                    $extension = $module->getExtensionName();
                }
            }
        }

        return self::reflectionExtensionName($extension, $lc);
    }

    /** php-src maps Zend core builtins to extension name Core (#6678, #11461). */
    private static function reflectionExtensionName(string $moduleExtension, ?string $functionName = null): string
    {
        if (null !== $functionName && CoreExtensionFunctions::isCoreFunction($functionName)) {
            return 'Core';
        }

        return match ($moduleExtension) {
            'types' => 'Core',
            default => $moduleExtension,
        };
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

        return isset($class->methods[$methodLc]) || isset($class->abstractMethods[$methodLc]);
    }

    /**
     * method_exists() operand dispatch — php-src ext/standard/class.c (#4360).
     *
     * Class-name strings see only methods on that class table (private parent methods excluded).
     * Object operands walk inheritance (private parent methods included).
     */
    public static function methodExists(Context $ctx, Variable $objectOrClass, string $method): bool
    {
        $objectOrClass = $objectOrClass->resolveIndirect();
        if (Variable::TYPE_STRING === $objectOrClass->type) {
            $class = self::resolveClassEntry($ctx, $objectOrClass->toString());
            if (null === $class) {
                return false;
            }

            return self::methodExistsOnClass($class, $method);
        }
        if (Variable::TYPE_OBJECT === $objectOrClass->type) {
            $object = $objectOrClass->toObject();
            if (EnumCaseSupport::isEnumCase($object)) {
                $class = EnumSupport::resolveRuntimeEnumClass($ctx, $object->class);

                return self::methodExistsOnClass($class, $method);
            }

            return $ctx->runtime->vm->hasInstanceMethod($object->class, $method);
        }
        if (Variable::TYPE_ENUM_CASE === $objectOrClass->type) {
            $class = EnumSupport::resolveRuntimeEnumClass($ctx, $objectOrClass->toEnumCase()->enumClass);

            return self::methodExistsOnClass($class, $method);
        }
        if (Variable::TYPE_NULL === $objectOrClass->type) {
            throw new \TypeError(
                'method_exists(): Argument #1 ($object_or_class) must be of type object|string, null given'
            );
        }
        throw new \TypeError(\sprintf(
            'method_exists(): Argument #1 ($object_or_class) must be of type object|string, %s given',
            VmClassHas::vmTypeName($objectOrClass->type)
        ));
    }

    /**
     * class_meth_exists() — method on a class name string (#7068).
     *
     * php-src: Zend/zend_builtin_functions.c — class_meth_exists (string $class only)
     */
    public static function classMethExists(Context $ctx, string $className, string $method): bool
    {
        $entry = self::resolveClassEntry($ctx, $className);
        if (null === $entry) {
            return false;
        }
        if (self::methodExistsOnClass($entry, $method)) {
            return true;
        }
        $classLc = strtolower(ltrim($className, '\\'));
        $methodLc = strtolower($method);

        return 'closure' === $classLc && '__invoke' === $methodLc;
    }

    /**
     * is_callable() class-string probe — instance methods are not statically invokable (#12545).
     *
     * php-src: ext/standard/basic_functions.c — zend_is_callable_at_frame
     */
    public static function isStaticallyCallableMethod(Context $ctx, string $className, string $method): bool
    {
        $lcClass = strtolower(ltrim($className, '\\'));
        $methodLc = strtolower($method);
        if ('' === $lcClass || '' === $methodLc || '__construct' === $methodLc) {
            return false;
        }
        $entry = self::resolveClassEntry($ctx, $className);
        if (null === $entry) {
            $ctx->autoloadClass($className);
            $entry = self::resolveClassEntry($ctx, $className);
            if (null === $entry) {
                return false;
            }
        }
        if ($entry->isEnum) {
            EnumSupport::ensureBuiltinCasesMethod($entry);
            if ('cases' === $methodLc) {
                return true;
            }
            if (null !== $entry->backedType && ('from' === $methodLc || 'tryfrom' === $methodLc)) {
                return true;
            }
        }
        if ($entry->usesLazyGhostTrait && 'createlazyghost' === $methodLc) {
            LazyGhostTraitSupport::ensureBuiltinLazyGhostMethods($entry);

            return true;
        }
        $visited = [];
        $walk = $lcClass;
        while (!isset($visited[$walk])) {
            $visited[$walk] = true;
            if (!isset($ctx->classes[$walk])) {
                break;
            }
            $class = $ctx->classes[$walk];
            if (isset($class->methods[$methodLc])) {
                $vis = $class->methodVisibility[$methodLc] ?? CfgFunc::FLAG_PUBLIC;
                if (($vis & CfgFunc::FLAG_STATIC) !== 0) {
                    return true;
                }
                $func = $class->methods[$methodLc];
                if ($func instanceof Func\PHP) {
                    $decl = $func->block->func;
                    if (null !== $decl && (($decl->flags ?? 0) & CfgFunc::FLAG_STATIC) !== 0) {
                        return true;
                    }
                }

                return false;
            }
            if (null === $class->parentLc) {
                break;
            }
            $walk = $class->parentLc;
        }

        return self::classHasStaticMagicCall($ctx, $lcClass);
    }

    private static function classHasStaticMagicCall(Context $ctx, string $lcClass): bool
    {
        $visited = [];
        while (!isset($visited[$lcClass])) {
            $visited[$lcClass] = true;
            if (!isset($ctx->classes[$lcClass])) {
                return false;
            }
            $entry = $ctx->classes[$lcClass];
            if (isset($entry->methods['__callstatic'])) {
                return true;
            }
            if (null === $entry->parentLc) {
                return false;
            }
            $lcClass = $entry->parentLc;
        }

        return false;
    }

    /**
     * property_exists() scope check — php-src ext/standard/class.c + zend_get_property_info(silent=1).
     */
    public static function propertyExistsOnClass(ClassEntry $class, string $property, Context $ctx): bool
    {
        $meta = self::findClassProperty($class, $property, $ctx);
        if (null !== $meta) {
            $declaringLc = '' !== $meta->declaringClassLc
                ? $meta->declaringClassLc
                : strtolower(ltrim($class->name, '\\'));

            return self::propertyVisibleFromScope($ctx, $class, $meta->visibility, $declaringLc);
        }

        $lc = strtolower($property);
        $current = $class;
        while (true) {
            if (isset($current->staticProperties[$lc])) {
                $visibility = $current->staticPropertyVisibility[$lc] ?? CfgFunc::FLAG_PUBLIC;
                $declaringLc = $current->staticPropertyDeclaringClassLc[$lc]
                    ?? strtolower(ltrim($current->name, '\\'));

                return self::propertyVisibleFromScope($ctx, $class, $visibility, $declaringLc);
            }
            if (null === $current->parentLc || !isset($ctx->classes[$current->parentLc])) {
                return false;
            }
            $current = $ctx->classes[$current->parentLc];
        }
    }

    private static function propertyVisibleFromScope(
        Context $ctx,
        ClassEntry $scopeClass,
        int $visibility,
        string $declaringClassLc
    ): bool {
        if (MethodVisibility::isPublic($visibility)) {
            return true;
        }
        $scopeLc = strtolower(ltrim($scopeClass->name, '\\'));
        if (($visibility & CfgFunc::FLAG_PRIVATE) !== 0) {
            return $scopeLc === $declaringClassLc;
        }
        if (($visibility & CfgFunc::FLAG_PROTECTED) !== 0) {
            return self::isSameOrSubclassOf($ctx, $scopeLc, $declaringClassLc);
        }

        return true;
    }

    private static function isSameOrSubclassOf(Context $ctx, string $classLc, string $ancestorLc): bool
    {
        $current = $classLc;
        while (true) {
            if ($current === $ancestorLc) {
                return true;
            }
            if (!isset($ctx->classes[$current])) {
                return false;
            }
            $parentLc = $ctx->classes[$current]->parentLc;
            if (null === $parentLc) {
                return false;
            }
            $current = $parentLc;
        }
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
     * Enum pseudo-properties name/value via ReflectionProperty (php_reflection.c, #5680).
     */
    public static function isEnumReflectionPseudoProperty(ClassEntry $entry, string $property): bool
    {
        return $entry->isEnum && EnumCaseSupport::propertyExistsOnCase($entry, $property);
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
        self::attachReflectionPropertyState(
            $obj,
            $reflectedClassName,
            $prop->name,
            self::declaringClassDisplay($prop, $ctx)
        );

        return $obj;
    }

    /**
     * Declaring class display name for an instance/static property lookup (#9878).
     */
    public static function declaringClassNameForPropertyLookup(
        ClassEntry $class,
        string $property,
        Context $ctx
    ): string {
        $meta = self::findClassProperty($class, $property, $ctx);
        if (null !== $meta) {
            return self::declaringClassDisplay($meta, $ctx);
        }
        $lc = strtolower($property);
        $current = $class;
        while (true) {
            if (isset($current->staticProperties[$lc])) {
                $declLc = $current->staticPropertyDeclaringClassLc[$lc]
                    ?? strtolower(ltrim($current->name, '\\'));
                if (isset($ctx->classes[$declLc])) {
                    return $ctx->classes[$declLc]->name;
                }

                return $declLc;
            }
            if (null === $current->parentLc || !isset($ctx->classes[$current->parentLc])) {
                break;
            }
            $current = $ctx->classes[$current->parentLc];
        }
        if (self::isEnumReflectionPseudoProperty($class, $property)) {
            return $class->name;
        }

        return $class->name;
    }

    private static function attachReflectionPropertyState(
        \PHPCompiler\VM\ObjectEntry $obj,
        string $reflectedClassName,
        string $propertyName,
        string $declaringClassName
    ): void {
        $obj->getProperty(ReflectionSupport::PROP_CLASS_NAME)->string($reflectedClassName);
        $obj->getProperty(ReflectionSupport::PROP_PROPERTY_NAME)->string($propertyName);
        $obj->getProperty(ReflectionSupport::PROP_DECLARING_CLASS_NAME)->string($declaringClassName);
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
     * Read/write/get visibility flags for an instance or static property (#6977).
     *
     * @return array{visibility: int, setVisibility: int, getVisibility: int}|null
     */
    public static function propertyVisibilityMeta(ClassEntry $class, string $property, Context $ctx): ?array
    {
        $meta = self::findClassProperty($class, $property, $ctx);
        if (null !== $meta) {
            return [
                'visibility' => $meta->visibility,
                'setVisibility' => $meta->setVisibility,
                'getVisibility' => $meta->getVisibility,
            ];
        }

        $lc = strtolower($property);
        $current = $class;
        while (true) {
            if (isset($current->staticProperties[$lc])) {
                return [
                    'visibility' => $current->staticPropertyVisibility[$lc] ?? \PHPCfg\Func::FLAG_PUBLIC,
                    'setVisibility' => $current->staticPropertySetVisibility[$lc] ?? 0,
                    'getVisibility' => $current->staticPropertyGetVisibility[$lc] ?? 0,
                ];
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
        $decl = self::findClassConstantDecl($class, $constant, $ctx);

        return null !== $decl ? $decl['constLc'] : null;
    }

    /**
     * Declaring class + storage key for a class constant on $class or an ancestor (#6950).
     *
     * @return array{declaring: ClassEntry, constLc: string}|null
     */
    public static function findClassConstantDecl(ClassEntry $class, string $constant, Context $ctx): ?array
    {
        $lc = strtolower($constant);
        $current = $class;
        while (true) {
            if (isset($current->constants[$lc])) {
                return ['declaring' => $current, 'constLc' => $lc];
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
            ReflectionSupport::throwReflectionException(
                ReflectionSupport::functionNotFoundMessage($functionName)
            );
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

            return self::propertyExistsOnClass($class, $property, $ctx);
        }
        if (Variable::TYPE_OBJECT === $objectOrClass->type) {
            $object = $objectOrClass->toObject();
            if (EnumCaseSupport::isEnumCase($object)) {
                return EnumCaseSupport::propertyExistsOnCase($object->class, $property);
            }
            if (self::propertyExistsOnClass($object->class, $property, $ctx)) {
                return true;
            }
            // php-src zend_property_exists: dynamic instance properties (stdClass, etc.)
            return $object->hasProperty($property);
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
        if (Variable::TYPE_ENUM_CASE === $arg->type) {
            return EnumSupport::resolveRuntimeEnumClass($ctx, $arg->toEnumCase()->enumClass);
        }
        if (Variable::TYPE_OBJECT === $arg->type) {
            $object = $arg->toObject();
            if (EnumCaseSupport::isEnumCase($object)) {
                return EnumSupport::resolveRuntimeEnumClass($ctx, $object->class);
            }

            return $object->class;
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
     * trait alias => Trait::method (Zend ReflectionClass::getTraitAliases).
     *
     * @return array<string, string>
     */
    public static function traitAliasesMap(ClassEntry $class): array
    {
        return $class->traitAliases;
    }

    /**
     * ReflectionClass::getTraitNames() / ReflectionEnum::getTraitNames() result array (#9693).
     *
     * @return Variable list<string> indexed trait names in declaration order
     */
    public static function reflectionClassTraitNamesArray(ClassEntry $entry): Variable
    {
        $result = new Variable();
        $result->newArray();
        $ht = $result->toArray();
        foreach (array_values(self::traitUsesMap($entry)) as $traitName) {
            $slot = new Variable();
            $slot->string((string) $traitName);
            $ht->append($slot);
        }

        return $result;
    }

    /**
     * ReflectionClass::getTraitAliases() result array (#6661).
     */
    public static function reflectionClassTraitAliasesMap(ClassEntry $entry): Variable
    {
        $result = new Variable();
        $result->newArray();
        $ht = $result->toArray();
        foreach ($entry->traitAliases as $alias => $source) {
            $value = new Variable();
            $value->string((string) $source);
            $ht->add((string) $alias, $value);
        }

        return $result;
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
            // php-src: interface operands list parent interfaces only, not self (#7400).
            foreach ($entry->interfaces as $parentLc) {
                if (!isset($ctx->classes[$parentLc])) {
                    continue;
                }
                self::addInterfaceAndParents($ctx->classes[$parentLc], $ctx, $result);
            }

            return $result;
        }

        foreach ($entry->interfaces as $ifaceLc) {
            $builtin = self::builtinEnumInterfaceDisplayName($ifaceLc);
            if (null !== $builtin) {
                $result[$builtin] = $builtin;
                continue;
            }
            if (!isset($ctx->classes[$ifaceLc])) {
                continue;
            }
            self::addInterfaceAndParents($ctx->classes[$ifaceLc], $ctx, $result);
        }

        if (StringableSupport::entryHasImplicitStringable($entry, $ctx)) {
            $name = StringableSupport::INTERFACE_NAME;
            $result[$name] = $name;
        }

        return $result;
    }

    /**
     * Zend implicit enum interfaces — not registered as user ClassEntry (#5651, #5422).
     */
    public static function builtinEnumInterfaceDisplayName(string $ifaceLc): ?string
    {
        return match (strtolower(ltrim($ifaceLc, '\\'))) {
            'unitenum' => 'UnitEnum',
            'backedenum' => 'BackedEnum',
            default => null,
        };
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
            // php-src add_class_vars: public hooked props appear with declared defaults, not get-hook reads (#6603).
            if ($prop->propertyHookVirtual) {
                self::copyClassVarDefault($copy, $prop);
                $ht->add($prop->name, $copy);

                continue;
            }
            self::copyClassVarDefault($copy, $prop);
            $ht->add($prop->name, $copy);
        }
        self::addPublicStaticClassVars($entry, $ht);
        if ($entry->isEnum) {
            /** @var array<string, true> $seen */
            $seen = [];
            foreach ($ht->iterate(false) as $key => $_value) {
                $seen[(string) $key] = true;
            }
            // php-src add_class_vars: unit enums expose `name` only; backed enums add `value` (#5012).
            $enumProps = null !== $entry->backedType ? ['name', 'value'] : ['name'];
            foreach ($enumProps as $enumProp) {
                if (isset($seen[$enumProp])) {
                    continue;
                }
                $copy = new Variable();
                $copy->null();
                $ht->add($enumProp, $copy);
            }
        }

        return $result;
    }

    /** Declared default for get_class_vars() — never invoke property get hooks (#6603). */
    private static function copyClassVarDefault(Variable $copy, ClassProperty $prop): void
    {
        if (null !== $prop->default && !$prop->hasRuntimeDefaultInit()) {
            $copy->copyFrom($prop->default);

            return;
        }
        $src = $prop->getVariable();
        if ($src->isUndefined()) {
            $copy->null();
        } else {
            $copy->copyFrom($src);
        }
    }

    public static function propertyHasDefaultValue(ClassProperty $prop): bool
    {
        return null !== $prop->default || $prop->hasRuntimeDefaultInit();
    }

    public static function staticPropertyHasDefaultValue(Variable $storage): bool
    {
        return !$storage->resolveIndirect()->isUndefined();
    }

    public static function propertyDefaultValueIsAvailable(ClassProperty $prop): bool
    {
        return null !== $prop->default && !$prop->hasRuntimeDefaultInit();
    }

    public static function copyPropertyDefaultValue(Variable $dest, ClassProperty $prop, Context $ctx): bool
    {
        $value = $ctx->runtime->vm()->evaluatePropertyDefaultForReflection($prop);
        if (null === $value) {
            return false;
        }
        $dest->copyFrom($value);

        return true;
    }

    public static function copyStaticPropertyDefaultValue(Variable $dest, Variable $storage): bool
    {
        if (!self::staticPropertyHasDefaultValue($storage)) {
            return false;
        }
        $resolved = $storage->resolveIndirect();
        $dest->copyFrom($resolved);

        return true;
    }

    /**
     * ReflectionClass::getDefaultProperties() — declared defaults along inheritance chain (#11441).
     *
     * php-src: ext/reflection/php_reflection.c — reflection_class_get_default_properties()
     */
    public static function getDefaultPropertiesArray(ClassEntry $entry, Context $ctx): Variable
    {
        $result = new Variable();
        $result->newArray();
        $ht = $result->toArray();
        $chain = [];
        $current = $entry;
        while (true) {
            $chain[] = $current;
            if (null === $current->parentLc || !isset($ctx->classes[$current->parentLc])) {
                break;
            }
            $current = $ctx->classes[$current->parentLc];
        }
        foreach ($chain as $classEntry) {
            foreach ($classEntry->properties as $prop) {
                if (!self::propertyHasDefaultValue($prop)) {
                    continue;
                }
                $copy = new Variable();
                self::copyClassVarDefault($copy, $prop);
                $ht->add($prop->name, $copy);
            }
            foreach ($classEntry->staticProperties as $propLc => $storage) {
                if (!self::staticPropertyHasDefaultValue($storage)) {
                    continue;
                }
                $displayName = $storage->objectPropertyName ?? $propLc;
                $copy = new Variable();
                $resolved = $storage->resolveIndirect();
                if ($resolved->isUndefined()) {
                    $copy->null();
                } else {
                    $copy->copyFrom($resolved);
                }
                $ht->add($displayName, $copy);
            }
        }

        return $result;
    }

    /**
     * Public static properties declared on $entry (php-src add_class_vars, #7397).
     *
     * @param \PHPCompiler\VM\HashTable $ht
     */
    private static function addPublicStaticClassVars(ClassEntry $entry, $ht): void
    {
        /** @var array<string, true> $seen */
        $seen = [];
        foreach ($ht->iterate(false) as $key => $_value) {
            $seen[(string) $key] = true;
        }
        foreach (self::orderedPublicStaticPropertyKeys($entry) as $propLc) {
            $storage = $entry->staticProperties[$propLc];
            // php-src add_class_vars: public static props on $entry include trait-composed
            // and parent-inherited members already merged into staticProperties (#7420).
            if (!MethodVisibility::isPublic($entry->staticPropertyVisibility[$propLc] ?? \PHPCfg\Func::FLAG_PUBLIC)) {
                continue;
            }
            $displayName = $storage->objectPropertyName ?? $propLc;
            if (isset($seen[$displayName])) {
                continue;
            }
            $copy = new Variable();
            $resolved = $storage->resolveIndirect();
            if ($resolved->isUndefined()) {
                $copy->null();
            } else {
                $copy->copyFrom($resolved);
            }
            $ht->add($displayName, $copy);
            $seen[$displayName] = true;
        }
    }

    /**
     * php-src add_class_vars: class-declared statics before trait-composed (#7417).
     *
     * @return list<string> lowercase property keys
     */
    private static function orderedPublicStaticPropertyKeys(ClassEntry $entry): array
    {
        $ordered = [];
        $added = [];
        foreach (array_keys($entry->staticProperties) as $propLc) {
            if (isset($entry->traitStaticPropertyNames[$propLc])) {
                continue;
            }
            $ordered[] = $propLc;
            $added[$propLc] = true;
        }
        foreach (array_keys($entry->traitStaticPropertyNames) as $propLc) {
            if (!isset($added[$propLc]) && isset($entry->staticProperties[$propLc])) {
                $ordered[] = $propLc;
            }
        }

        return $ordered;
    }

    public static function resolveClassFromArg(Context $ctx, Variable $arg): ClassEntry
    {
        $arg = $arg->resolveIndirect();
        if (Variable::TYPE_STRING === $arg->type) {
            $entry = self::resolveClassEntry($ctx, $arg->toString());
            if (null === $entry) {
                ReflectionSupport::throwReflectionException(
                    ReflectionSupport::classNotFoundMessage($arg->toString())
                );
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
        $entryCandidate = null;
        for ($f = $frame->parent; null !== $f; $f = $f->parent) {
            if (null === $f->block || null === $f->block->func || $f->hasHandler()) {
                continue;
            }
            if ([] !== $f->calledArgs) {
                $args = $f->calledArgs;
                if (null !== $f->block->func->class) {
                    return array_slice($args, 1);
                }

                return $args;
            }
            if ($f->block->func instanceof Func\PHP && $f->block === $f->block->func->block) {
                $entryCandidate = $f;
            }
        }
        if (null !== $entryCandidate) {
            $args = $entryCandidate->calledArgs;
            if (null !== $entryCandidate->block->func->class) {
                return array_slice($args, 1);
            }

            return $args;
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
     * is_subclass_of() object operand — enum cases vs UnitEnum/BackedEnum (#5642, zend_is_a).
     *
     * Enum case vs its declaring enum is false (not a subclass); builtin enum interfaces match.
     */
    public static function isSubclassOfObject(Context $ctx, Variable $object, string $className): bool
    {
        $object = $object->resolveIndirect();
        if (EnumCaseSupport::isEnumCaseVariable($object)) {
            $entry = EnumCaseSupport::entryForInstanceOfCheck($object);
            if (null === $entry) {
                return false;
            }
            $entry = EnumCaseSupport::canonicalEnumClassEntryForInstanceOf($entry, $ctx);
            $parentLc = strtolower(ltrim($className, '\\'));
            if (strtolower($entry->name) === $parentLc) {
                return false;
            }

            return EnumCaseSupport::valueMatchesInstanceOfClassName($object, $className, $ctx) ?? false;
        }
        if (Variable::TYPE_OBJECT !== $object->type) {
            return false;
        }
        $entry = $object->toObject()->class;
        $parentLc = strtolower(ltrim($className, '\\'));
        if (strtolower($entry->name) === $parentLc) {
            return false;
        }

        return self::isInstanceOf($ctx, $entry, $className);
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
    /**
     * Zend get_object_vars / var_export property map keys — numeric property names become int keys (#12042).
     */
    private static function addObjectPropertyEntry(HashTable $ht, string|int $name, Variable $value): void
    {
        if (\is_int($name)) {
            $ht->addIndex($name, $value);

            return;
        }
        $intKey = HashTable::tryIntFromNumericString($name);
        if (null !== $intKey) {
            $ht->addIndex($intKey, $value);

            return;
        }
        $ht->add($name, $value);
    }

    public static function getObjectVars(Variable $object, Frame $frame): Variable
    {
        $object = $object->resolveIndirect();
        if (EnumCaseSupport::isEnumCaseVariable($object)) {
            $result = new Variable();
            $result->newArray();
            $ht = $result->toArray();
            foreach (EnumCaseSupport::objectVarsForCaseVariable($object) as $name => $value) {
                self::addObjectPropertyEntry($ht, $name, $value);
            }

            return $result;
        }
        if (Variable::TYPE_OBJECT !== $object->type) {
            throw new \TypeError(\sprintf(
                'get_object_vars(): Argument #1 ($object) must be of type object, %s given',
                EnumCaseSupport::typeNameForVariable($object)
            ));
        }
        $ctx = self::requireContext($frame);
        $result = new Variable();
        $result->newArray();
        $ht = $result->toArray();
        foreach ($ctx->runtime->vm()->collectObjectVarsForBuiltin($object->toObject(), $frame) as $name => $value) {
            self::addObjectPropertyEntry($ht, $name, $value);
        }

        return $result;
    }

    /**
     * Property snapshot for var_export() — all set properties regardless of scope (#3594).
     *
     * php-src: zend_get_properties_for(..., ZEND_PROP_PURPOSE_VAR_EXPORT)
     */
    public static function getVarExportObjectProperties(Variable $object, Frame $frame): Variable
    {
        $object = $object->resolveIndirect();
        if (EnumCaseSupport::isEnumCaseVariable($object)) {
            $result = new Variable();
            $result->newArray();
            $ht = $result->toArray();
            foreach (EnumCaseSupport::objectVarsForCaseVariable($object) as $name => $value) {
                $ht->add($name, $value);
            }

            return $result;
        }
        if (Variable::TYPE_OBJECT !== $object->type) {
            throw new \LogicException('var_export() object branch expects an object in this compiler build');
        }
        $ctx = self::requireContext($frame);
        $result = new Variable();
        $result->newArray();
        $ht = $result->toArray();
        foreach ($ctx->runtime->vm()->collectVarExportPropertiesForBuiltin($object->toObject(), $frame) as $name => $value) {
            self::addObjectPropertyEntry($ht, $name, $value);
        }

        return $result;
    }

    /**
     * get_mangled_object_vars() — all set instance properties with Zend-mangled keys (issue #3497).
     *
     * php-src: ext/standard/var.c — PHP_FUNCTION(get_mangled_object_vars)
     */
    public static function getMangledObjectVars(Variable $object, Frame $frame): Variable
    {
        $object = $object->resolveIndirect();
        if (EnumCaseSupport::isEnumCaseVariable($object)) {
            $result = new Variable();
            $result->newArray();
            $ht = $result->toArray();
            foreach (EnumCaseSupport::objectVarsForCaseVariable($object) as $name => $value) {
                $ht->add($name, $value);
            }

            return $result;
        }
        if (Variable::TYPE_OBJECT !== $object->type) {
            throw new \TypeError(\sprintf(
                'get_mangled_object_vars(): Argument #1 ($object) must be of type object, %s given',
                EnumCaseSupport::typeNameForVariable($object)
            ));
        }
        $ctx = self::requireContext($frame);
        $result = new Variable();
        $result->newArray();
        $ht = $result->toArray();
        foreach ($ctx->runtime->vm()->collectMangledObjectVarsForBuiltin($object->toObject(), $frame) as $name => $value) {
            $ht->add($name, $value);
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
        if (Variable::TYPE_ENUM_CASE === $arg->type) {
            return $arg->toEnumCase()->enumClass;
        }
        if (Variable::TYPE_STRING === $arg->type) {
            $className = $arg->toString();
            $lc = strtolower(ltrim($className, '\\'));
            if (!isset($ctx->classes[$lc])) {
                $ctx->autoloadClass($className);
            }

            return $ctx->classes[$lc] ?? null;
        }

        return null;
    }

    /**
     * @return list<string>
     */
    public static function classMethodsList(ClassEntry $entry, int $filter = 7, ?Context $ctx = null): array
    {
        $entries = [$entry];
        if ($entry->isInterface && null !== $ctx) {
            $entries = self::interfaceDeclarationChain($entry, $ctx);
        }
        $names = [];
        /** @var array<string, true> */
        $seenMethodLcs = [];
        foreach ($entries as $scan) {
            if ($scan->isEnum) {
                EnumSupport::ensureBuiltinCasesMethod($scan);
            }
            $methodLcs = array_keys($scan->methods);
            foreach (array_keys($scan->abstractMethods) as $abstractLc) {
                if (!in_array($abstractLc, $methodLcs, true)) {
                    $methodLcs[] = $abstractLc;
                }
            }
            foreach ($methodLcs as $methodLc) {
                if (isset($seenMethodLcs[$methodLc])) {
                    continue;
                }
                $vis = $scan->methodVisibility[$methodLc] ?? \PHPCfg\Func::FLAG_PUBLIC;
                if (0 !== ($filter & 7) && 0 === ($vis & $filter & 7)) {
                    continue;
                }
                $seenMethodLcs[$methodLc] = true;
                $handler = $scan->methods[$methodLc] ?? null;
                if ($handler instanceof \PHPCompiler\Func\Internal) {
                    $names[] = $handler->getName();
                } else {
                    $names[] = $scan->methodNames[$methodLc] ?? $methodLc;
                }
            }
            foreach (self::syntheticEnumMethodNames($scan, $filter) as $methodName) {
                if (!in_array($methodName, $names, true)) {
                    $names[] = $methodName;
                }
            }
        }

        return $names;
    }

    /**
     * Interface + parent interfaces for get_class_methods() (php-src basic_functions.c, #11689).
     *
     * @return list<ClassEntry>
     */
    private static function interfaceDeclarationChain(ClassEntry $entry, Context $ctx): array
    {
        $chain = [$entry];
        foreach ($entry->interfaces as $parentLc) {
            if (!isset($ctx->classes[$parentLc])) {
                continue;
            }
            foreach (self::interfaceDeclarationChain($ctx->classes[$parentLc], $ctx) as $parent) {
                if (!in_array($parent, $chain, true)) {
                    $chain[] = $parent;
                }
            }
        }

        return $chain;
    }

    /**
     * Zend zend_get_class_methods() synthetic enum methods (php-src basic_functions.c, #5614).
     *
     * @return list<string>
     */
    private static function syntheticEnumMethodNames(ClassEntry $entry, int $filter): array
    {
        if (!$entry->isEnum || null === $entry->backedType) {
            return [];
        }
        $vis = \PHPCfg\Func::FLAG_PUBLIC | \PHPCfg\Func::FLAG_STATIC;
        if (0 !== ($filter & 7) && 0 === ($vis & $filter & 7)) {
            return [];
        }

        return ['from', 'tryFrom'];
    }

    public static function classMethodsArray(ClassEntry $entry, int $filter = 7, ?Context $ctx = null): Variable
    {
        $result = new Variable();
        $result->newArray();
        $ht = $result->toArray();
        foreach (self::classMethodsList($entry, $filter, $ctx) as $methodName) {
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

    /**
     * Defining class for get_class() with no arguments (issue #4092).
     *
     * php-src / PHP 8.2: zero-arg get_class() matches __CLASS__ (method definition scope),
     * not get_class($this). Use get_called_class() for the call-site / LSB class name.
     */
    public static function zeroArgGetClassName(Frame $frame): string
    {
        $current = $frame->parent;
        if (null === $current) {
            throw new \Error('get_class() without arguments must be called from within a class');
        }
        if (null === $current->block || null === $current->block->func || null === $current->block->func->class) {
            throw new \Error('get_class() without arguments must be called from within a class');
        }

        return $current->block->func->class->value;
    }

    /** php-src ZEND_ACC_* filter bitmask for getProperties()/getMethods() (not getModifiers()). */
    public const REFLECTION_IS_PUBLIC = 256;

    public const REFLECTION_IS_PROTECTED = 512;

    public const REFLECTION_IS_PRIVATE = 1024;

    /** Register ReflectionAttribute::IS_INSTANCEOF (#11471, ext/reflection/php_reflection.c). */
    public static function registerReflectionAttributeClassConstants(ClassEntry $entry): void
    {
        $const = new Variable();
        $const->int(ReflectionSupport::REFLECTION_ATTRIBUTE_IS_INSTANCEOF);
        $entry->constants['is_instanceof'] = $const;
        $entry->constNames['is_instanceof'] = 'IS_INSTANCEOF';
    }

    /** Register ReflectionProperty::IS_* class constants (#5060). */
    public static function registerReflectionPropertyClassConstants(ClassEntry $entry): void
    {
        foreach (
            [
                'is_public' => self::REFLECTION_IS_PUBLIC,
                'is_protected' => self::REFLECTION_IS_PROTECTED,
                'is_private' => self::REFLECTION_IS_PRIVATE,
            ] as $name => $value
        ) {
            $const = new Variable();
            $const->int($value);
            $entry->constants[$name] = $const;
            $entry->constNames[$name] = strtoupper($name);
        }
    }

    /** php-src ReflectionMethod::IS_* values returned by getModifiers() (#7116). */
    public const REFLECTION_METHOD_IS_STATIC = 16;

    public const REFLECTION_METHOD_IS_PUBLIC = 1;

    public const REFLECTION_METHOD_IS_PROTECTED = 2;

    public const REFLECTION_METHOD_IS_PRIVATE = 4;

    public const REFLECTION_METHOD_IS_FINAL = 32;

    public const REFLECTION_METHOD_IS_ABSTRACT = 64;

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

    /** php-src zend_get_function_modifiers() for ReflectionMethod::getModifiers() (#7116). */
    public static function cfgMethodFlagsToReflectionModifiers(int $cfgFlags): int
    {
        if (($cfgFlags & \PHPCfg\Func::FLAG_PRIVATE) !== 0) {
            $modifiers = self::REFLECTION_METHOD_IS_PRIVATE;
        } elseif (($cfgFlags & \PHPCfg\Func::FLAG_PROTECTED) !== 0) {
            $modifiers = self::REFLECTION_METHOD_IS_PROTECTED;
        } else {
            $modifiers = self::REFLECTION_METHOD_IS_PUBLIC;
        }
        if (($cfgFlags & \PHPCfg\Func::FLAG_STATIC) !== 0) {
            $modifiers |= self::REFLECTION_METHOD_IS_STATIC;
        }
        if (($cfgFlags & \PHPCfg\Func::FLAG_FINAL) !== 0) {
            $modifiers |= self::REFLECTION_METHOD_IS_FINAL;
        }
        if (($cfgFlags & \PHPCfg\Func::FLAG_ABSTRACT) !== 0) {
            $modifiers |= self::REFLECTION_METHOD_IS_ABSTRACT;
        }

        return $modifiers;
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
        $chain = $entry->isInterface
            ? array_reverse(self::interfaceDeclarationChain($entry, $ctx))
            : array_reverse(self::classHierarchyChain($entry, $ctx));
        $byLc = [];
        foreach ($chain as $class) {
            $methodLcs = array_keys($class->methods);
            foreach (array_keys($class->abstractMethods) as $abstractLc) {
                if (!in_array($abstractLc, $methodLcs, true)) {
                    $methodLcs[] = $abstractLc;
                }
            }
            foreach ($methodLcs as $methodLc) {
                $vis = $class->methodVisibility[$methodLc] ?? \PHPCfg\Func::FLAG_PUBLIC;
                if (!self::matchesReflectionVisibilityFilter($vis, $filter)) {
                    continue;
                }
                // php-src add_reflection_method_sub: parent-private methods hidden on child (#7191).
                if (($vis & \PHPCfg\Func::FLAG_PRIVATE) !== 0 && $class !== $entry) {
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
     * ReflectionClass::hasMethod() — php-src ext/reflection/php_reflection.c (#6301).
     */
    public static function classHasMethodForReflection(
        ClassEntry $entry,
        Context $ctx,
        string $method,
        int $filter = 0
    ): bool {
        $methodLc = strtolower($method);
        foreach (self::collectClassMethodsForReflection($entry, $ctx, $filter) as $spec) {
            if ($spec['methodLc'] === $methodLc) {
                return true;
            }
        }
        if ($entry->isEnum && self::methodExistsOnClass($entry, $method)) {
            return true;
        }

        return false;
    }

    /**
     * ReflectionClass::hasProperty() — php-src ext/reflection/php_reflection.c (#6301).
     */
    public static function classHasPropertyForReflection(
        ClassEntry $entry,
        Context $ctx,
        string $property,
        int $filter = 0
    ): bool {
        $meta = self::propertyVisibilityMeta($entry, $property, $ctx);
        if (null === $meta) {
            return false;
        }

        return self::matchesReflectionVisibilityFilter($meta['visibility'], $filter);
    }

    /**
     * ReflectionClass::hasConstant() — php-src ext/reflection/php_reflection.c (#6301).
     */
    public static function classHasConstantForReflection(ClassEntry $entry, Context $ctx, string $constant): bool
    {
        return null !== self::findClassConstantDecl($entry, $constant, $ctx);
    }

    /**
     * Instance properties with readonly flag or declared on a readonly class (#7186).
     *
     * php-src: ext/reflection/php_reflection.c — zim_ReflectionClass_getReadOnlyProperties
     *
     * @return list<ClassProperty>
     */
    public static function collectReadOnlyClassPropertiesForReflection(ClassEntry $entry, Context $ctx): array
    {
        $byLc = [];
        foreach (array_reverse(self::classHierarchyChain($entry, $ctx)) as $class) {
            foreach ($class->properties as $prop) {
                if (!$prop->readonly && !$class->readonly) {
                    continue;
                }
                $byLc[strtolower($prop->name)] = $prop;
            }
        }

        return array_values($byLc);
    }

    /**
     * Instance properties eligible for lazy ghost initialization (#6606).
     *
     * php-src: ext/reflection/php_reflection.c — zim_ReflectionClass_getLazyPropertyNames
     *
     * @return list<string>
     */
    public static function collectLazyPropertyNamesForReflection(ClassEntry $entry, Context $ctx): array
    {
        if ($entry->isInterface || $entry->isTrait || $entry->isEnum) {
            return [];
        }
        if (!\PHPCompiler\VM\LazyGhostTraitSupport::classUsesLazyGhostTrait($entry, $ctx)) {
            return [];
        }
        $byLc = [];
        foreach (array_reverse(self::classHierarchyChain($entry, $ctx)) as $class) {
            foreach ($class->properties as $prop) {
                if ($prop->propertyHookVirtual) {
                    continue;
                }
                $byLc[strtolower($prop->name)] = $prop->name;
            }
        }

        return array_values($byLc);
    }

    /**
     * ReflectionClass::getLazyPropertyNames() result array (#6606).
     */
    public static function reflectionLazyPropertyNamesArray(Context $ctx, ClassEntry $entry): Variable
    {
        $result = new Variable();
        $result->newArray();
        $ht = $result->toArray();
        foreach (self::collectLazyPropertyNamesForReflection($entry, $ctx) as $name) {
            $slot = new Variable();
            $slot->string($name);
            $ht->append($slot);
        }

        return $result;
    }

    /**
     * ReflectionClass::getReadOnlyProperties() result array (#7186).
     */
    public static function reflectionReadOnlyPropertiesArray(
        Context $ctx,
        ClassEntry $entry,
        string $reflectedClassName
    ): Variable {
        $rpClass = $ctx->classes[\PHPCompiler\VM\ReflectionSupport::REFLECTION_PROPERTY] ?? null;
        if (null === $rpClass) {
            throw new \LogicException('ReflectionProperty is not registered in this compiler build');
        }
        $result = new Variable();
        $result->newArray();
        $ht = $result->toArray();
        foreach (self::collectReadOnlyClassPropertiesForReflection($entry, $ctx) as $prop) {
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
            self::attachReflectionPropertyState(
                $obj,
                $reflectedClassName,
                $prop->name,
                self::declaringClassDisplay($prop, $ctx)
            );
            $slot = new Variable(Variable::TYPE_OBJECT);
            $slot->object($obj);
            $ht->append($slot);
        }

        return $result;
    }

    /**
     * ReflectionClass::getStaticProperties() — static slot values (#6948).
     *
     * php-src: ext/reflection/php_reflection.c — zim_ReflectionClass_getStaticProperties
     */
    public static function staticPropertiesValuesArray(ClassEntry $entry): Variable
    {
        $result = new Variable();
        $result->newArray();
        $ht = $result->toArray();
        $entryLc = strtolower($entry->name);
        foreach ($entry->staticProperties as $propLc => $storage) {
            $vis = $entry->staticPropertyVisibility[$propLc] ?? CfgFunc::FLAG_PUBLIC;
            $declLc = $entry->staticPropertyDeclaringClassLc[$propLc] ?? $entryLc;
            if (($vis & CfgFunc::FLAG_PRIVATE) !== 0 && $declLc !== $entryLc) {
                continue;
            }
            if (TypedPropertyCheck::isUninitialized($storage)) {
                continue;
            }
            $displayName = $storage->objectPropertyName ?? $propLc;
            $copy = new Variable();
            $copy->copyFrom($storage->resolveIndirect());
            $ht->add($displayName, $copy);
        }

        return $result;
    }

    /**
     * ReflectionClass::getStaticPropertyValue($name, $default) (#6948).
     *
     * php-src: ext/reflection/php_reflection.c — zim_ReflectionClass_getStaticPropertyValue
     */
    public static function getStaticPropertyValueForReflection(
        ClassEntry $entry,
        Context $ctx,
        string $propertyName,
        ?Variable $default,
        Frame $frame
    ): Variable {
        $staticKey = self::findStaticPropertyKey($entry, $propertyName, $ctx);
        if (null === $staticKey) {
            ReflectionSupport::throwReflectionException(sprintf(
                'Property %s::$%s does not exist',
                $entry->name,
                $propertyName
            ));
        }
        $storage = $entry->staticProperties[$staticKey];
        $classLc = strtolower(ltrim($entry->name, '\\'));

        return $ctx->runtime->vm()->readStaticPropertyForReflection(
            $classLc,
            $propertyName,
            $storage,
            $default,
            $frame
        );
    }

    /** Outermost user frame for reflection hook dispatch (matches ReflectionProperty::getValue). */
    public static function reflectionCallerFrame(Frame $frame): Frame
    {
        $scopeFrame = $frame;
        while (null !== $scopeFrame && null !== $scopeFrame->handler) {
            $scopeFrame = $scopeFrame->parent;
        }

        return $scopeFrame ?? $frame;
    }

    /**
     * ReflectionClass::setStaticPropertyValue($name, $value) (#6948).
     *
     * php-src: ext/reflection/php_reflection.c — zim_ReflectionClass_setStaticPropertyValue
     */
    public static function setStaticPropertyValueForReflection(
        ClassEntry $entry,
        Context $ctx,
        string $propertyName,
        Variable $value
    ): void {
        $staticKey = self::findStaticPropertyKey($entry, $propertyName, $ctx);
        if (null === $staticKey) {
            ReflectionSupport::throwReflectionException(sprintf(
                'Class %s does not have a property named %s',
                $entry->name,
                $propertyName
            ));
        }
        $storage = $entry->staticProperties[$staticKey];
        $storage->copyFrom($value);
        \PHPCompiler\VM\TypeCheck::coercePropertyWrite($storage, false);
        $resolved = $storage->resolveIndirect();
        if (null !== $resolved->dnfArms) {
            \PHPCompiler\VM\DnfCheck::assertMatches(
                $value,
                $resolved->dnfArms,
                $ctx,
                'Property',
                $resolved
            );
        }
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
     * Build a constructed ReflectionEnumUnitCase or ReflectionEnumBackedCase (#4121, #5675).
     */
    public static function newReflectionEnumCase(
        Context $ctx,
        ClassEntry $enumEntry,
        string $caseName
    ): \PHPCompiler\VM\ObjectEntry {
        if (null !== $enumEntry->backedType) {
            return self::newReflectionEnumBackedCase($ctx, $enumEntry, $caseName);
        }

        return self::newReflectionEnumUnitCase($ctx, $enumEntry, $caseName);
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
            ReflectionSupport::throwReflectionException(
                ReflectionSupport::enumCaseNotFoundMessage($enumEntry->name, $caseName)
            );
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
     * Build a constructed ReflectionEnumBackedCase for a backed enum case (#5675).
     */
    public static function newReflectionEnumBackedCase(
        Context $ctx,
        ClassEntry $enumEntry,
        string $caseName
    ): \PHPCompiler\VM\ObjectEntry {
        $rebcClass = $ctx->classes[\PHPCompiler\VM\ReflectionSupport::REFLECTION_ENUM_BACKED_CASE] ?? null;
        if (null === $rebcClass) {
            throw new \LogicException('ReflectionEnumBackedCase is not registered in this compiler build');
        }
        if (null === $enumEntry->backedType) {
            throw new \LogicException('ReflectionEnumBackedCase expects a backed enum class');
        }
        $caseLc = strtolower($caseName);
        if (!isset($enumEntry->enumCaseCanonicalNames[$caseLc])) {
            ReflectionSupport::throwReflectionException(
                ReflectionSupport::enumCaseNotFoundMessage($enumEntry->name, $caseName)
            );
        }
        $obj = new \PHPCompiler\VM\ObjectEntry($rebcClass);
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
        if ([] !== ReflectionSupport::filterEntriesByName($ctx, $entry->attributeEntries, $attributeName)) {
            return true;
        }

        return [] !== ReflectionSupport::filterByName($ctx, $entry->attributeNames, $attributeName);
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
            $obj = self::newReflectionEnumCase($ctx, $enumEntry, $case['name']);
            $slot = new Variable(Variable::TYPE_OBJECT);
            $slot->object($obj);
            $ht->append($slot);
        }

        return $result;
    }

    /**
     * class_constants() — resolve class/interface/enum and reject traits (#7309).
     *
     * php-src: ext/standard/basic_functions.c — PHP_FUNCTION(class_constants)
     */
    public static function fetchClassEntryForClassConstants(Context $ctx, string $className): ClassEntry
    {
        $classLc = strtolower(ltrim($className, '\\'));
        if (!isset($ctx->classes[$classLc])) {
            $ctx->autoloadClass($className);
        }
        if (!isset($ctx->classes[$classLc])) {
            throw new \Error('Class "'.$className.'" not found');
        }
        $entry = $ctx->classes[$classLc];
        if ($entry->isTrait) {
            throw new \Error("Cannot fetch constants from trait {$entry->name}");
        }

        return $entry;
    }

    /**
     * Class constants visible on $entry (child overrides parent), php-src ReflectionClass::getConstants (#6950).
     *
     * @return list<array{name: string, declaring: ClassEntry, constLc: string}>
     */
    public static function collectClassConstantsForReflection(ClassEntry $entry, Context $ctx, int $filter): array
    {
        $byLc = [];
        foreach (array_reverse(self::classHierarchyChain($entry, $ctx)) as $class) {
            foreach ($class->constants as $constLc => $_stored) {
                $vis = $class->constVisibility[$constLc] ?? \PHPCfg\Func::FLAG_PUBLIC;
                if (!self::matchesReflectionVisibilityFilter($vis, $filter)) {
                    continue;
                }
                $displayName = $class->constNames[$constLc]
                    ?? $class->enumCaseCanonicalNames[$constLc]
                    ?? $constLc;
                $byLc[$constLc] = [
                    'name' => $displayName,
                    'declaring' => $class,
                    'constLc' => $constLc,
                ];
            }
        }

        return array_values($byLc);
    }

    /**
     * ReflectionClass::getConstants() result map — constant name => value (#6950).
     *
     * php-src: ext/reflection/php_reflection.c — reflection_class_get_constants
     */
    public static function reflectionClassConstantsMap(Context $ctx, ClassEntry $entry, int $filter): Variable
    {
        $result = new Variable();
        $result->newArray();
        $ht = $result->toArray();
        foreach (self::collectClassConstantsForReflection($entry, $ctx, $filter) as $spec) {
            $declaring = $spec['declaring'];
            $constLc = $spec['constLc'];
            $value = new Variable();
            if (EnumCaseSupport::tryMaterializeEnumCaseConstantFetch($declaring, $constLc, $value)) {
                $ht->add($spec['name'], $value);

                continue;
            }
            $copy = new Variable();
            $copy->copyFrom($declaring->constants[$constLc]);
            $ht->add($spec['name'], $copy);
        }

        return $result;
    }

    /**
     * Construct a ReflectionClassConstant stub for $declaringClassName::$constantName (#6662).
     */
    public static function newReflectionClassConstant(
        Context $ctx,
        string $declaringClassName,
        string $constantName
    ): \PHPCompiler\VM\ObjectEntry {
        $rcClass = $ctx->classes[ReflectionSupport::REFLECTION_CLASS_CONSTANT]
            ?? $ctx->classes[ReflectionSupport::REFLECTION_CONSTANT]
            ?? null;
        if (null === $rcClass) {
            throw new \LogicException('ReflectionClassConstant is not registered in this compiler build');
        }
        $rc = new \PHPCompiler\VM\ObjectEntry($rcClass);
        $rc->constructed = true;
        $rc->getProperty(ReflectionSupport::PROP_CLASS_NAME)->string($declaringClassName);
        $rc->getProperty(ReflectionSupport::PROP_CONSTANT_NAME)->string($constantName);

        return $rc;
    }

    /**
     * ReflectionClass::getReflectionConstants() — ReflectionClassConstant list (#6662).
     *
     * php-src: ext/reflection/php_reflection.c — reflection_class_get_reflection_constants
     */
    public static function reflectionClassReflectionConstantsMap(
        Context $ctx,
        ClassEntry $entry,
        int $filter
    ): Variable {
        $result = new Variable();
        $result->newArray();
        $ht = $result->toArray();
        foreach (self::collectClassConstantsForReflection($entry, $ctx, $filter) as $spec) {
            $obj = self::newReflectionClassConstant($ctx, $spec['declaring']->name, $spec['name']);
            $slot = new Variable(Variable::TYPE_OBJECT);
            $slot->object($obj);
            $ht->append($slot);
        }

        return $result;
    }

    /**
     * Resolve ReflectionClass::getConstants() visibility filter (#6950, filter flags in #4479).
     *
     * php-src: null filter returns all constants; IS_* bitmasks narrow the set.
     */
    public static function reflectionConstantsFilterArg(Frame $frame, int $argIndex): int
    {
        if (\count($frame->calledArgs) <= $argIndex) {
            return 0;
        }

        return self::optionalReflectionFilterArg($frame, $argIndex);
    }

    /**
     * class_constants() result map — constant name => value (#7309).
     *
     * php-src: Zend/zend_constants.c — class constant table iteration
     */
    public static function classConstantsArray(Context $ctx, ClassEntry $entry): Variable
    {
        $result = new Variable();
        $result->newArray();
        $ht = $result->toArray();
        if ($entry->isEnum && null !== $entry->backedType) {
            EnumSupport::ensureBackedEnumValuesUnique($entry);
        }
        foreach ($entry->constants as $constLc => $_stored) {
            $displayName = $entry->constNames[$constLc]
                ?? $entry->enumCaseCanonicalNames[$constLc]
                ?? $constLc;
            $value = new Variable();
            if (EnumCaseSupport::tryMaterializeEnumCaseConstantFetch($entry, $constLc, $value)) {
                $ht->add($displayName, $value);

                continue;
            }
            $copy = new Variable();
            $copy->copyFrom($entry->constants[$constLc]);
            $ht->add($displayName, $copy);
        }

        return $result;
    }

    /**
     * Build a ReflectionFiber wrapper around a Fiber object (#6793, ext/reflection/php_reflection.c).
     */
    public static function newReflectionFiber(Context $ctx, \PHPCompiler\VM\ObjectEntry $fiberObject): \PHPCompiler\VM\ObjectEntry
    {
        $rfClass = $ctx->classes[\PHPCompiler\VM\ReflectionSupport::REFLECTION_FIBER] ?? null;
        if (null === $rfClass) {
            throw new \LogicException('ReflectionFiber is not registered in this compiler build');
        }
        if (null === $fiberObject->fiberState) {
            throw new \TypeError('ReflectionFiber::__construct() expects a Fiber object');
        }
        $obj = new \PHPCompiler\VM\ObjectEntry($rfClass);
        $obj->constructed = true;
        $fiberVar = new Variable();
        $fiberVar->object($fiberObject);
        $obj->getProperty(\PHPCompiler\VM\ReflectionSupport::PROP_FIBER_TARGET)->copyFrom($fiberVar);

        return $obj;
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
