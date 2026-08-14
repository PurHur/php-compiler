<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\ext\simplexml\SimpleXmlJsonExport;
use PHPCompiler\ext\spl\SplArrayStorage;
use PHPCompiler\Block;
use PHPCompiler\CompilerVersion;
use PHPCompiler\Frame;
use PHPCompiler\Func;
use PHPCompiler\Func\Internal as FuncInternal;
use PHPCompiler\Compiler\AttributeClassRegistry;
use PHPCompiler\MethodVisibility;
use PHPCfg\Func as CfgFunc;
use PHPCompiler\VM\ClassEntry;
use PHPCompiler\VM\ClassProperty;
use PHPCompiler\VM\ClosureSupport;
use PHPCompiler\VM\Context;
use PHPCompiler\VM\EnumCaseSupport;
use PHPCompiler\VM\EnumSupport;
use PHPCompiler\VM\ErrorReporter;
use PHPCompiler\VM\HashTable;
use PHPCompiler\VM\IncompleteClassSupport;
use PHPCompiler\VM\InterfaceCheck;
use PHPCompiler\VM\LazyGhostTraitSupport;
use PHPCompiler\VM\Builtin\VmClassMethod;
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
     * Receiver-only ReflectionFunctionAbstract / ReflectionFunction::getClosure
     * display names — php-src ZEND_PARSE_PARAMETERS_NONE (#30924).
     *
     * @var array<string, string> method name => Class::method (no trailing "()")
     */
    public const FUNCTION_ABSTRACT_RECEIVER_ONLY = [
        'getNumberOfParameters' => 'ReflectionFunctionAbstract::getNumberOfParameters',
        'getNumberOfRequiredParameters' => 'ReflectionFunctionAbstract::getNumberOfRequiredParameters',
        'getFileName' => 'ReflectionFunctionAbstract::getFileName',
        'getStartLine' => 'ReflectionFunctionAbstract::getStartLine',
        'getEndLine' => 'ReflectionFunctionAbstract::getEndLine',
        'isClosure' => 'ReflectionFunctionAbstract::isClosure',
        'isInternal' => 'ReflectionFunctionAbstract::isInternal',
        'isUserDefined' => 'ReflectionFunctionAbstract::isUserDefined',
        'isVariadic' => 'ReflectionFunctionAbstract::isVariadic',
        'returnsReference' => 'ReflectionFunctionAbstract::returnsReference',
        'hasReturnType' => 'ReflectionFunctionAbstract::hasReturnType',
        'getStaticVariables' => 'ReflectionFunctionAbstract::getStaticVariables',
        'getClosure' => 'ReflectionFunction::getClosure',
    ];

    public static function functionAbstractReceiverOnlyDisplayName(string $method): string
    {
        return self::FUNCTION_ABSTRACT_RECEIVER_ONLY[$method]
            ?? ('ReflectionFunctionAbstract::'.$method);
    }

    /**
     * Excess user argc → Zend ArgumentCountError (user arity excludes $this).
     *
     * php-src: ext/reflection/php_reflection.c — zim_ReflectionFunctionAbstract_* /
     * zim_ReflectionFunction_getClosure
     */
    public static function requireFunctionAbstractReceiverOnlyArgc(Frame $frame, string $method): void
    {
        $given = max(0, \count($frame->calledArgs) - 1);
        if (0 !== $given) {
            throw new \ArgumentCountError(VmClassMethod::exactUserArgCountMessage(
                self::functionAbstractReceiverOnlyDisplayName($method),
                0,
                $given
            ));
        }
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
     * get_declared_* call arity — Zend arity 0; reject any argument (#27900 / #12403).
     *
     * php-src: Zend/zend_builtin_functions.stub.php — no $exclude_deprecated (feature never landed).
     * {@see CompilerVersion::supportsGetDeclaredExcludeDeprecated()} is permanently false.
     */
    public static function parseExcludeDeprecatedArg(Frame $frame, string $function): bool
    {
        return self::parseOptionalBoolBuiltinArg(
            $frame,
            $function,
            CompilerVersion::supportsGetDeclaredExcludeDeprecated(...)
        );
    }

    /**
     * Max positional arity for get_class() — always 1 (php-src stub, #28310).
     *
     * Prior PROFILE=8.4 path wrongly advertised optional $allow_string (#17395);
     * Zend never registered that parameter on get_class (only on is_a / is_subclass_of).
     */
    public static function getClassMaxArgCount(): int
    {
        return 1;
    }

    /**
     * Max positional arity for get_parent_class() — always 1 (php-src stub, #23948).
     *
     * Prior PROFILE=8.4 path wrongly shared {@see CompilerVersion::supportsGetClassAllowString()}
     * with get_class(); Zend never registered $allow_string on get_parent_class.
     */
    public static function getParentClassMaxArgCount(): int
    {
        return 1;
    }

    public static function enforceGetClassMaxArgs(int $argc, string $function = 'get_class'): void
    {
        $max = self::getClassMaxArgCount();
        if ($argc > $max) {
            $suffix = 1 === $max ? '' : 's';
            throw new \ArgumentCountError(
                "{$function}() expects at most {$max} argument{$suffix}, {$argc} given"
            );
        }
    }

    public static function enforceGetParentClassMaxArgs(int $argc): void
    {
        $max = self::getParentClassMaxArgCount();
        if ($argc > $max) {
            $suffix = 1 === $max ? '' : 's';
            throw new \ArgumentCountError(
                "get_parent_class() expects at most {$max} argument{$suffix}, {$argc} given"
            );
        }
    }

    /**
     * is_a() / is_subclass_of() $allow_string operand (php-src zend_builtin_functions).
     *
     * Not used by get_class() / get_parent_class() — php-src arity is 1 (#23948, #28310).
     */
    public static function parseAllowStringArg(Frame $frame, string $function, int $argIndex): bool
    {
        return VmMath::parseBoolBuiltinArg(
            $frame->calledArgs[$argIndex]->resolveIndirect(),
            $function,
            $argIndex + 1,
            'allow_string'
        );
    }

    /**
     * Resolve a class-name string operand when $allow_string is true (php-src zend_lookup_class_ex).
     */
    public static function resolveAllowStringClassName(
        Context $ctx,
        string $className,
        string $function,
        string $paramName = 'object'
    ): string {
        $classLc = strtolower(self::normalizeGlobalIntrospectionName($className));
        if (!isset($ctx->classes[$classLc])) {
            $ctx->autoloadClass($className);
        }
        if (!isset($ctx->classes[$classLc])) {
            throw new \ValueError(\sprintf(
                '%s(): Argument #1 ($%s) must be an object or a valid class name, "%s" given',
                $function,
                $paramName,
                $className
            ));
        }

        return $className;
    }

    /**
     * Optional $exclude_disabled for get_defined_functions() (PHP 8.4, #4942).
     *
     * php-src: ext/standard/basic_functions.c — Z_PARAM_OPTIONAL Z_PARAM_BOOL
     */
    public static function parseExcludeDisabledArg(Frame $frame, string $function): bool
    {
        return self::parseOptionalBoolBuiltinArg(
            $frame,
            $function,
            CompilerVersion::supportsGetDefinedFunctionsExcludeDisabled(...)
        );
    }

    /**
     * @param callable(): bool $supportsOptionalArg
     */
    private static function parseOptionalBoolBuiltinArg(Frame $frame, string $function, callable $supportsOptionalArg): bool
    {
        $argc = \count($frame->calledArgs);
        if (!$supportsOptionalArg()) {
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

        // Z_PARAM_BOOL — strict_types TypeError on null; else null→false + E_DEPRECATED (#30169).
        return VmMath::parseBoolBuiltinArgForFrame(
            $frame,
            0,
            $function,
            1,
            'exclude_disabled'
        );
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

    /**
     * zend_lookup_class — resolve a class name with autoload (#26406, #26407).
     *
     * php-src: Zend/zend_builtin_functions.c — is_a / is_subclass_of / method_exists / property_exists
     */
    public static function lookupClassEntryWithAutoload(Context $ctx, string $className): ?ClassEntry
    {
        self::maybeAutoloadClass($ctx, $className, true);

        return self::resolveClassEntry($ctx, $className);
    }

    /**
     * Second parameter for class_exists/interface_exists/trait_exists/enum_exists (php-src zif_* autoload flag).
     */
    public static function autoloadFlagFromFrame(Frame $frame, int $argIndex = 1, bool $default = true): bool
    {
        if (\count($frame->calledArgs) <= $argIndex) {
            return $default;
        }
        $arg = $frame->calledArgs[$argIndex]->resolveIndirect();
        if (Variable::TYPE_BOOLEAN !== $arg->type) {
            throw new \TypeError(
                \sprintf(
                    '%s(): Argument #%d ($autoload) must be of type bool, %s given',
                    $frame->func->getName(),
                    $argIndex + 1,
                    EnumCaseSupport::typeNameForVariable($arg)
                )
            );
        }

        return $arg->toBool();
    }

    private static function maybeAutoloadClass(Context $ctx, string $className, bool $autoload): void
    {
        if (!$autoload || null !== self::resolveClassEntry($ctx, $className)) {
            return;
        }
        $ctx->autoloadClass($className);
    }

    public static function classExists(Context $ctx, string $className, bool $autoload = true): bool
    {
        self::maybeAutoloadClass($ctx, $className, $autoload);
        $entry = self::resolveClassEntry($ctx, $className);

        return null !== $entry
            && !$entry->isInterface
            && !$entry->isTrait
            && !\PHPCompiler\VM\ResourceSupport::isHiddenPseudoClassEntry($entry)
            && !\PHPCompiler\ext\openssl\VmOpensslObjects::isHiddenClassEntry($entry)
            && !\PHPCompiler\ext\xsl\VmXsl::isHiddenClassEntry($entry);
    }

    public static function enumExists(Context $ctx, string $enumName, bool $autoload = true): bool
    {
        if ($autoload && null === self::resolveClassEntry($ctx, $enumName)) {
            $ctx->autoloadClass($enumName);
        }
        $entry = self::resolveClassEntry($ctx, $enumName);

        return null !== $entry && $entry->isEnum;
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

    public static function interfaceExists(Context $ctx, string $interfaceName, bool $autoload = true): bool
    {
        self::maybeAutoloadClass($ctx, $interfaceName, $autoload);
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
     * User #[Attribute] class names — helper for the phantom get_declared_attributes() (#6450 / #24222).
     *
     * php-src has no PHP_FUNCTION(get_declared_attributes); use ReflectionClass::getAttributes().
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

    /**
     * Registered ext Module internal function names (php-src internal bucket).
     *
     * @return list<string>
     */
    public static function internalFunctionNameList(bool $excludeDisabled = false): array
    {
        $names = [];
        $seen = [];
        foreach (ModuleRegistry::advertisedInternalFunctionNames() as $name) {
            $lc = strtolower($name);
            if (isset($seen[$lc]) || !self::isVisibleToFunctionExists($name)) {
                continue;
            }
            if (!BuiltinIntrospectionPolicy::functionIsAdvertised($lc)) {
                continue;
            }
            $seen[$lc] = true;
            $names[] = $name;
        }

        // php-src exclude_disabled omits ini-disabled functions only — deprecated builtins
        // such as utf8_encode remain listed (basic_functions.c, #16969, #16978).
        return self::orderInternalFunctionNamesForIntrospection($names);
    }

    /** Zend internal bucket starts with engine introspection builtins (php-src zend_builtin_functions). */
    private const INTERNAL_INTROSPECTION_HEAD = [
        'zend_version',
        'func_num_args',
        'func_get_args',
        'func_get_arg',
    ];

    /**
     * @param list<string> $names
     *
     * @return list<string>
     */
    private static function orderInternalFunctionNamesForIntrospection(array $names): array
    {
        $byLc = [];
        foreach ($names as $name) {
            $byLc[strtolower($name)] = $name;
        }
        $ordered = [];
        foreach (self::INTERNAL_INTROSPECTION_HEAD as $headLc) {
            if (isset($byLc[$headLc])) {
                $ordered[] = $byLc[$headLc];
                unset($byLc[$headLc]);
            }
        }
        foreach (CoreExtensionFunctions::FUNCTIONS as $coreLc) {
            if (isset($byLc[$coreLc])) {
                $ordered[] = $byLc[$coreLc];
                unset($byLc[$coreLc]);
            }
        }
        foreach ($names as $name) {
            $lc = strtolower($name);
            if (isset($byLc[$lc])) {
                $ordered[] = $name;
                unset($byLc[$lc]);
            }
        }

        return $ordered;
    }

    /**
     * get_defined_functions() — internal/user name lists (issue #3128).
     *
     * php-src: ext/standard/basic_functions.c — PHP_FUNCTION(get_defined_functions)
     */
    public static function definedFunctionsTable(Context $ctx, bool $excludeDisabled = false): \PHPCompiler\VM\HashTable
    {
        $result = new \PHPCompiler\VM\HashTable();

        $internalVar = new Variable();
        $internalVar->newArray();
        $internalHt = $internalVar->toArray();
        foreach (self::internalFunctionNameList($excludeDisabled) as $name) {
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

    public static function traitExists(Context $ctx, string $traitName, bool $autoload = true): bool
    {
        if (LazyGhostTraitSupport::isLazyGhostTrait($traitName)) {
            return false;
        }
        self::maybeAutoloadClass($ctx, $traitName, $autoload);
        $entry = self::resolveClassEntry($ctx, $traitName);

        return null !== $entry && $entry->isTrait;
    }

    /**
     * Always-hidden language constructs / compile-time-only symbols for function_exists()
     * (php-src zend_builtin_functions.c / basic_functions.c).
     *
     * `exit` / `die` are omitted here: on PHP 8.4+ they are real functions (#20575, re-#6975);
     * on the 8.2 reference profile they stay hidden (#14738).
     *
     * @var list<string> lowercase names
     */
    private const FUNCTION_EXISTS_EXCLUDED = [
        '__halt_compiler',
        'eval',
    ];

    /**
     * Symbols that are language constructs on PHP &lt; 8.4 and proper functions on 8.4+
     * (RFC exit-as-function; CompilerVersion::supportsExitFunctionForm).
     *
     * @var list<string> lowercase names
     */
    private const FUNCTION_EXISTS_EXIT_DIE = [
        'die',
        'exit',
    ];

    /** Whether function_exists() may report true — excludes constructs Zend omits from the function table. */
    public static function isVisibleToFunctionExists(string $functionName): bool
    {
        $lc = \strtolower($functionName);
        if (self::isCompilerAbiHelperName($lc)) {
            return false;
        }
        if (\in_array($lc, self::FUNCTION_EXISTS_EXCLUDED, true)) {
            return false;
        }
        // PHP 8.2: exit/die are constructs → false. PHP 8.4 profile: real functions → true (#20575).
        if (\in_array($lc, self::FUNCTION_EXISTS_EXIT_DIE, true)
            && !CompilerVersion::supportsExitFunctionForm()) {
            return false;
        }

        return true;
    }

    /** JIT/AOT self-host ABI helpers — linkable but hidden from user introspection (#15046). */
    public static function isCompilerAbiHelperName(string $functionName): bool
    {
        $lc = \strtolower($functionName);

        return str_starts_with($lc, '__compiler_')
            || str_starts_with($lc, 'phpc_')
            || str_starts_with($lc, 'web_');
    }

    /**
     * Compiler-only builtins absent from Zend reflection tables (#18357).
     *
     * @var list<string> lowercase names
     */
    private const REFLECTION_HIDDEN_COMPILER_BUILTINS = [
        'compiler_language_warning',
    ];

    /**
     * VM-only builtins absent from Zend 8.2 reflection tables (#18357).
     *
     * @var list<string> lowercase names
     */
    private const REFLECTION_REFERENCE_PROFILE_HIDDEN = [
        'frexp',
        'get_debug_backtrace',
        'get_declared_attributes',
        'get_declared_functions',
        'get_declared_variables',
        // memcmp/modf unregistered as userland (#25359); keep hidden if residual registration appears
        'vfscanf',
    ];

    /** Whether ReflectionExtension::getFunctions() may expose a builtin (php-src reflection_extension_get_functions). */
    public static function functionIsVisibleInReflection(string $functionName, string $extension = 'standard'): bool
    {
        $lc = \strtolower($functionName);
        if (!self::isVisibleToFunctionExists($lc)) {
            return false;
        }
        if (\in_array($lc, self::REFLECTION_HIDDEN_COMPILER_BUILTINS, true)) {
            return false;
        }
        if (\in_array($lc, self::REFLECTION_REFERENCE_PROFILE_HIDDEN, true)) {
            return false;
        }
        if (!BuiltinIntrospectionPolicy::functionIsAdvertised($lc)) {
            return false;
        }
        $extLc = \strtolower($extension);
        if ('core' === $extLc) {
            return 'core' === ModuleRegistry::reflectionOwningExtension($lc);
        }

        return $extLc === ModuleRegistry::reflectionOwningExtension($lc);
    }

    public static function functionExists(Context $ctx, string $functionName): bool
    {
        $normalized = \strtolower(self::normalizeGlobalIntrospectionName($functionName));
        if (!self::isVisibleToFunctionExists($normalized)) {
            return false;
        }

        if (!isset($ctx->functions[$normalized])) {
            return false;
        }

        return BuiltinIntrospectionPolicy::functionIsAdvertised($normalized);
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

        if (isset($class->methods[$methodLc]) || isset($class->abstractMethods[$methodLc])) {
            return true;
        }

        // Closure::__invoke is a trampoline (ZEND_ACC_CALL_VIA_TRAMPOLINE), not a table method (#19616).
        return self::isClosureInvokeMethod($class->name, $methodLc);
    }

    /**
     * Closure exposes __invoke via trampoline — php-src Zend/zend_closures.c (#19616).
     *
     * Not listed in get_class_methods(), but method_exists() / ReflectionClass::hasMethod() are true.
     */
    public static function isClosureInvokeMethod(string $className, string $method): bool
    {
        return 'closure' === strtolower(ltrim($className, '\\'))
            && '__invoke' === strtolower($method);
    }

    /**
     * method_exists() on a class name — inherited public/protected methods; parent-private excluded (#4360, #19178).
     */
    public static function methodExistsOnClassWithInheritance(Context $ctx, ClassEntry $entry, string $method): bool
    {
        return self::classHasMethodForReflection($entry, $ctx, $method, 0);
    }

    /**
     * method_exists() operand dispatch — php-src Zend/zend_builtin_functions.c (#4360, #19178, #26407).
     *
     * Class-name strings autoload via zend_lookup_class, then walk inheritance
     * (parent-private methods excluded). Object operands walk inheritance
     * (private parent methods included).
     */
    public static function methodExists(Context $ctx, Variable $objectOrClass, string $method): bool
    {
        $objectOrClass = $objectOrClass->resolveIndirect();
        if (Variable::TYPE_STRING === $objectOrClass->type) {
            $class = self::lookupClassEntryWithAutoload($ctx, $objectOrClass->toString());
            if (null === $class) {
                return false;
            }
            if (self::methodExistsOnClassWithInheritance($ctx, $class, $method)) {
                return true;
            }

            return self::isClosureInvokeMethod($class->name, $method);
        }
        if (Variable::TYPE_OBJECT === $objectOrClass->type) {
            $object = $objectOrClass->toObject();
            if (EnumCaseSupport::isEnumCase($object)) {
                $class = EnumSupport::resolveRuntimeEnumClass($ctx, $object->class);

                return self::methodExistsOnClass($class, $method);
            }
            if ($ctx->runtime->vm->hasInstanceMethod($object->class, $method)) {
                return true;
            }
            // Closure instances: __invoke via trampoline / closureState (#19616).
            if (null !== $object->closureState && '__invoke' === strtolower($method)) {
                return true;
            }

            return self::isClosureInvokeMethod($object->class->name, $method);
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
            EnumCaseSupport::typeNameForTypeErrorActual($objectOrClass)
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

        return self::methodExistsOnClass($entry, $method);
    }

    /**
     * Lexical class scope for is_callable() visibility (php-src zend_is_callable_at_frame, #9334).
     */
    public static function callerClassLcFromFrame(Frame $frame): ?string
    {
        $scopeFrame = self::reflectionCallerFrame($frame);
        if (
            null !== $scopeFrame->block
            && null !== $scopeFrame->block->func
            && (($scopeFrame->block->func->flags ?? 0) & CfgFunc::FLAG_CLOSURE) !== 0
            && null !== $scopeFrame->calledClass
            && '' !== $scopeFrame->calledClass
        ) {
            return strtolower($scopeFrame->calledClass);
        }
        if (null !== $scopeFrame->block && null !== $scopeFrame->block->func && null !== $scopeFrame->block->func->class) {
            return strtolower($scopeFrame->block->func->class->value);
        }
        if (null !== $scopeFrame->calledClass && '' !== $scopeFrame->calledClass) {
            return strtolower($scopeFrame->calledClass);
        }

        return null;
    }

    /**
     * Late-static called class for constant('static::…') / defined('static::…') (#29455).
     */
    public static function calledClassLcFromFrame(Frame $frame): ?string
    {
        $scopeFrame = self::reflectionCallerFrame($frame);
        if (null !== $scopeFrame->calledClass && '' !== $scopeFrame->calledClass) {
            return strtolower($scopeFrame->calledClass);
        }

        return self::callerClassLcFromFrame($frame);
    }

    /**
     * is_callable() visibility probe for a resolved method (#9334).
     */
    public static function isMethodCallableFromScope(
        Context $ctx,
        int $visibilityFlags,
        string $declaringClassLc,
        ?string $callerClassLc
    ): bool {
        return MethodVisibility::isCallable(
            $visibilityFlags,
            $callerClassLc,
            $declaringClassLc,
            false,
            fn (string $classLc, string $ancestorLc): bool => self::isSameOrSubclassOf($ctx, $classLc, $ancestorLc)
        );
    }

    /**
     * is_callable() class-string / "Class::method" probe (php-src zend_is_callable_ex).
     *
     * Static methods: normal visibility from the caller frame.
     * Instance methods: false from global / unrelated scopes (#12545); true when the
     * caller class is the named class or a subclass *and* the method is visible (#23996)
     * *and* the caller has an active `$this` — still false from a static method even
     * inside that class (#25873).
     *
     * php-src: Zend/zend_execute_API.c — zend_is_callable_ex / zend_is_callable_at_frame
     */
    public static function isStaticallyCallableMethod(
        Context $ctx,
        string $className,
        string $method,
        ?string $callerClassLc = null,
        ?Frame $scopeFrame = null
    ): bool
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
                // Parent-only PDO_*_Ext methods are not statically callable on subclasses (#21552).
                if ($walk !== $lcClass && isset($class->methodNotInherited[$methodLc])) {
                    if (null === $class->parentLc) {
                        break;
                    }
                    $walk = $class->parentLc;
                    continue;
                }
                $vis = $class->methodVisibility[$methodLc] ?? CfgFunc::FLAG_PUBLIC;
                if (($vis & CfgFunc::FLAG_STATIC) !== 0) {
                    if (self::isMethodCallableFromScope($ctx, $vis, $walk, $callerClassLc)) {
                        return true;
                    }

                    // Inaccessible static still callable via __callStatic (#25710).
                    return self::classHasStaticMagicCall($ctx, $lcClass);
                }
                $func = $class->methods[$methodLc];
                if ($func instanceof Func\PHP) {
                    $decl = $func->block->func;
                    if (null !== $decl && (($decl->flags ?? 0) & CfgFunc::FLAG_STATIC) !== 0) {
                        if (self::isMethodCallableFromScope($ctx, $vis, $walk, $callerClassLc)) {
                            return true;
                        }

                        return self::classHasStaticMagicCall($ctx, $lcClass);
                    }
                }

                // Instance method via class-string: only when caller has $this, is in the
                // named class hierarchy, and can see the method (Zend/zend_execute_API.c).
                return self::isInstanceMethodCallableViaClassString(
                    $ctx,
                    $vis,
                    $walk,
                    $lcClass,
                    $callerClassLc,
                    $scopeFrame
                );
            }
            if (null === $class->parentLc) {
                break;
            }
            $walk = $class->parentLc;
        }

        return self::classHasStaticMagicCall($ctx, $lcClass);
    }

    /**
     * Class-string / "Class::method" form for a non-static method (#23996 / #12545 / #25873).
     *
     * Zend requires an active `$this` (instance method or bound closure) plus the caller
     * frame's class to be the named class or a subclass; visibility alone is not enough
     * (static methods and unrelated scopes stay false even for public).
     */
    private static function isInstanceMethodCallableViaClassString(
        Context $ctx,
        int $visibilityFlags,
        string $declaringClassLc,
        string $namedClassLc,
        ?string $callerClassLc,
        ?Frame $scopeFrame = null
    ): bool {
        if (null === $callerClassLc) {
            return false;
        }
        // No $this → class-string cannot name an instance method (#25873).
        if (null === $scopeFrame || null === ClosureSupport::callerThis($scopeFrame)) {
            return false;
        }
        if (!self::isSameOrSubclassOf($ctx, $callerClassLc, $namedClassLc)) {
            return false;
        }

        return self::isMethodCallableFromScope($ctx, $visibilityFlags, $declaringClassLc, $callerClassLc);
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
     * property_exists() scope check — php-src zend_builtin_functions.c zif_property_exists.
     *
     * Property names are case-sensitive (unlike methods). Match declared casing exactly (#23532).
     */
    public static function propertyExistsOnClass(ClassEntry $class, string $property, Context $ctx): bool
    {
        $meta = self::findClassPropertyExact($class, $property, $ctx);
        if (null !== $meta) {
            // C-level reflection storage is not a PHP property (#22513, #22514).
            if ($meta->phpInvisible) {
                return false;
            }
            $declaringLc = '' !== $meta->declaringClassLc
                ? $meta->declaringClassLc
                : strtolower(ltrim($class->name, '\\'));

            return self::propertyVisibleFromScope($ctx, $class, $meta->visibility, $declaringLc);
        }

        $lc = strtolower($property);
        $current = $class;
        while (true) {
            if (isset($current->staticProperties[$lc])) {
                $storage = $current->staticProperties[$lc];
                $declared = $storage->objectPropertyName ?? $lc;
                if ($declared !== $property) {
                    return false;
                }
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

    /**
     * Instance property metadata with exact name match (zend_hash_find on property table, #23532).
     */
    public static function findClassPropertyExact(ClassEntry $class, string $property, Context $ctx): ?ClassProperty
    {
        $current = $class;
        while (true) {
            foreach ($current->properties as $prop) {
                if ($prop->name === $property) {
                    return $prop;
                }
            }
            if (null === $current->parentLc || !isset($ctx->classes[$current->parentLc])) {
                return null;
            }
            $current = $ctx->classes[$current->parentLc];
        }
    }

    /** True when a static property is declared under this exact casing (#23532). */
    private static function staticPropertyDeclaredExact(ClassEntry $class, string $property, Context $ctx): bool
    {
        $lc = strtolower($property);
        $current = $class;
        while (true) {
            if (isset($current->staticProperties[$lc])) {
                $storage = $current->staticProperties[$lc];
                $declared = $storage->objectPropertyName ?? $lc;

                return $declared === $property;
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
     * Runtime dynamic instance property on $object — not declared on CE (#15540, zend_property_exists).
     */
    public static function isRuntimeDynamicProperty(
        Variable $objectArg,
        string $property,
        ClassEntry $entry,
        Context $ctx
    ): bool {
        $objectArg = $objectArg->resolveIndirect();
        if (Variable::TYPE_OBJECT !== $objectArg->type) {
            return false;
        }
        $object = $objectArg->toObject();
        if (self::propertyExistsOnClass($entry, $property, $ctx)) {
            return false;
        }

        return $object->hasProperty($property);
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
        string $declaringClassName,
        bool $isDynamic = false
    ): void {
        // Zend public surface: $name = property, $class = declaring class (#22504).
        // Reflected class is not a public Zend property (same as ReflectionMethod).
        $obj->getProperty(ReflectionSupport::PROP_PROPERTY_NAME)->string($propertyName);
        $obj->getProperty(ReflectionSupport::PROP_DECLARING_CLASS_NAME)->string($declaringClassName);
        $obj->getProperty(ReflectionSupport::PROP_IS_DYNAMIC)->bool($isDynamic);
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
     * Declaring class + storage key for a class constant on $class or an ancestor (#6950, #22581).
     *
     * Inherited constants are merged onto the child ClassEntry; use constDeclaringClassLc
     * (same as collectClassConstantsForReflection) so Reflection reports the declaring ce.
     *
     * @return array{declaring: ClassEntry, constLc: string}|null
     */
    public static function findClassConstantDecl(ClassEntry $class, string $constant, Context $ctx): ?array
    {
        // Exact casing key (#25910 / #25929); wrong case is a miss (#25945 isEnumCase / getReflectionConstants).
        $lc = \PHPCompiler\ClassConstName::key($constant);
        $current = $class;
        while (true) {
            if (isset($current->constants[$lc])) {
                $declared = $current->constNames[$lc]
                    ?? $current->enumCaseCanonicalNames[$lc]
                    ?? null;
                // Case-sensitive: wrong casing is not a hit (#25910).
                if (null !== $declared && $declared !== $constant) {
                    return null;
                }
                $declLc = $current->constDeclaringClassLc[$lc]
                    ?? strtolower(ltrim($current->name, '\\'));
                $declaring = $ctx->classes[$declLc] ?? $current;

                return ['declaring' => $declaring, 'constLc' => $lc];
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

    /**
     * property_exists() — declared / dynamic / enum pseudo-props.
     *
     * php-src: Zend/zend_builtin_functions.c — PHP_FUNCTION(property_exists)
     * Incomplete objects: warn + false like isset/read (#26366, zend_object_handlers.c).
     */
    public static function propertyExists(
        Context $ctx,
        Variable $objectOrClass,
        string $property,
        ?Frame $frame = null
    ): bool {
        $objectOrClass = $objectOrClass->resolveIndirect();
        if (Variable::TYPE_STRING === $objectOrClass->type) {
            // zend_lookup_class — autoload string class name (#26407).
            $class = self::lookupClassEntryWithAutoload($ctx, $objectOrClass->toString());
            if (null === $class) {
                return false;
            }

            return self::propertyExistsOnClass($class, $property, $ctx);
        }
        if (Variable::TYPE_OBJECT === $objectOrClass->type) {
            $object = $objectOrClass->toObject();
            // __PHP_Incomplete_Class — Zend refuses property introspection (#26366 / #19632).
            // Warning prefix is always property_exists(): (internal execute_data), not the caller (#29025).
            if (IncompleteClassSupport::isIncomplete($object)) {
                IncompleteClassSupport::emitAccessWarning($object, $ctx, $frame, 'property_exists');

                return false;
            }
            if (EnumCaseSupport::isEnumCase($object)) {
                return EnumCaseSupport::propertyExistsOnCase($object->class, $property);
            }
            if (self::propertyExistsOnClass($object->class, $property, $ctx)) {
                return true;
            }
            // Declared instance/static property (exact name) that failed scope visibility is
            // not a dynamic — do not revive via instance slot (#4361, #23532).
            if (null !== self::findClassPropertyExact($object->class, $property, $ctx)
                || self::staticPropertyDeclaredExact($object->class, $property, $ctx)) {
                return false;
            }
            // php-src zend_property_exists: dynamic instance properties (stdClass, etc.)
            // Declared phpInvisible slots still allocate storage — do not treat as dynamics (#22513).
            if ($object->hasProperty($property)) {
                $meta = self::findClassProperty($object->class, $property, $ctx);
                if (null !== $meta && $meta->phpInvisible) {
                    return false;
                }

                return true;
            }
            // ArrayObject/ArrayIterator::ARRAY_AS_PROPS — backing keys as properties (spl_array.c; #31039).
            if (SplArrayStorage::hasArrayAsProps($object)) {
                $key = new Variable(Variable::TYPE_STRING);
                $key->string($property);
                if (SplArrayStorage::offsetExists($object, $key)) {
                    return true;
                }
            }

            return false;
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
     * php-src basic_functions.c / class.c — warn when class-string operand cannot be resolved (#14764).
     */
    public static function warnClassOperandNotFound(
        Frame $frame,
        string $function,
        string $className,
        bool $autoload
    ): void {
        if (null === $frame->vmContext) {
            return;
        }
        $message = $autoload
            ? \sprintf('%s(): Class %s does not exist and could not be loaded', $function, $className)
            : \sprintf('%s(): Class %s does not exist', $function, $className);
        $frame->vmContext->errors->triggerError(
            $message,
            ErrorReporter::E_WARNING,
            '' !== $frame->scriptPath ? $frame->scriptPath : null,
            $frame->vmContext,
            $frame
        );
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
     * ReflectionClass::getTraits() — trait name => ReflectionClass (#22108).
     *
     * php-src: ext/reflection/php_reflection.c — zim_ReflectionClass_getTraits
     *
     * @return Variable map<string, ReflectionClass>
     */
    public static function reflectionClassTraitsMap(Context $ctx, ClassEntry $entry): Variable
    {
        $result = new Variable();
        $result->newArray();
        $ht = $result->toArray();
        $rcClass = $ctx->classes[ReflectionSupport::REFLECTION_CLASS] ?? null;
        if (null === $rcClass) {
            return $result;
        }
        foreach (self::traitUsesMap($entry) as $traitName) {
            $name = (string) $traitName;
            $obj = new \PHPCompiler\VM\ObjectEntry($rcClass);
            $obj->constructed = true;
            $obj->getProperty(ReflectionSupport::PROP_CLASS_NAME)->string($name);
            $slot = new Variable();
            $slot->object($obj);
            $ht->add($name, $slot);
        }

        return $result;
    }

    /**
     * ReflectionClass::getInterfaces() — interface name => ReflectionClass (#22170).
     *
     * php-src: ext/reflection/php_reflection.c — zim_ReflectionClass_getInterfaces
     * Same order as getInterfaceNames() (ce->interfaces walk).
     *
     * @return Variable map<string, ReflectionClass>
     */
    public static function reflectionClassInterfacesMap(Context $ctx, ClassEntry $entry): Variable
    {
        $result = new Variable();
        $result->newArray();
        $ht = $result->toArray();
        $rcClass = $ctx->classes[ReflectionSupport::REFLECTION_CLASS] ?? null;
        if (null === $rcClass) {
            return $result;
        }
        foreach (self::reflectionClassInterfaceNamesList($entry, $ctx) as $name) {
            $obj = new \PHPCompiler\VM\ObjectEntry($rcClass);
            $obj->constructed = true;
            $obj->getProperty(ReflectionSupport::PROP_CLASS_NAME)->string($name);
            $slot = new Variable();
            $slot->object($obj);
            $ht->add($name, $slot);
        }

        return $result;
    }

    /**
     * ReflectionClass::getInterfaceNames() / ReflectionEnum::getInterfaceNames() (#9692).
     *
     * php-src: ext/reflection/php_reflection.c — zim_ReflectionClass_getInterfaceNames
     * walks ce->interfaces (direct implements + inherited parent interfaces).
     *
     * @return Variable list<string> indexed interface names in zend order
     */
    public static function reflectionClassInterfaceNamesArray(ClassEntry $entry, Context $ctx): Variable
    {
        $result = new Variable();
        $result->newArray();
        $ht = $result->toArray();
        foreach (self::reflectionClassInterfaceNamesList($entry, $ctx) as $ifaceName) {
            $slot = new Variable();
            $slot->string($ifaceName);
            $ht->append($slot);
        }

        return $result;
    }

    /**
     * @return list<string> interface display names in zend ce->interfaces order
     */
    public static function reflectionClassInterfaceNamesList(ClassEntry $entry, Context $ctx): array
    {
        $ordered = [];
        $seen = [];
        // Rematerialized tables already match Zend ce->interfaces — do not re-expand
        // parents (RecursiveIterator vs SeekableIterator disagree on Traversable/Iterator
        // placement; #25799).
        $rematerialized = self::interfacesListIsRematerialized($entry->interfaces);
        foreach ($entry->interfaces as $ifaceLc) {
            $ifaceLc = strtolower(ltrim($ifaceLc, '\\'));
            if ($rematerialized) {
                if (isset($seen[$ifaceLc])) {
                    continue;
                }
                $seen[$ifaceLc] = true;
                $ordered[] = $ifaceLc;
                continue;
            }
            self::appendReflectionInterfaceName($ifaceLc, $ctx, $ordered, $seen);
        }

        $names = [];
        foreach ($ordered as $lc) {
            $names[] = self::interfaceDisplayNameForReflection($lc, $ctx);
        }

        return $names;
    }

    private static function interfaceDisplayNameForReflection(string $ifaceLc, Context $ctx): string
    {
        $builtin = self::builtinEnumInterfaceDisplayName($ifaceLc);
        if (null !== $builtin) {
            return $builtin;
        }
        if (isset($ctx->classes[$ifaceLc])) {
            return $ctx->classes[$ifaceLc]->name;
        }

        return $ifaceLc;
    }

    /** @param list<string> $ordered */
    private static function appendReflectionInterfaceName(
        string $ifaceLc,
        Context $ctx,
        array &$ordered,
        array &$seen
    ): void {
        if (isset($seen[$ifaceLc])) {
            return;
        }
        $seen[$ifaceLc] = true;
        $ordered[] = $ifaceLc;

        if (!isset($ctx->classes[$ifaceLc])) {
            return;
        }

        $parents = $ctx->classes[$ifaceLc]->interfaces;
        for ($i = count($parents) - 1; $i >= 0; --$i) {
            $parentLc = $parents[$i];
            if (isset($seen[$parentLc])) {
                continue;
            }
            $seen[$parentLc] = true;
            $ordered[] = $parentLc;
            self::appendReflectionInterfaceParents($parentLc, $ctx, $ordered, $seen);
        }
    }

    /** @param list<string> $ordered */
    private static function appendReflectionInterfaceParents(
        string $ifaceLc,
        Context $ctx,
        array &$ordered,
        array &$seen
    ): void {
        if (!isset($ctx->classes[$ifaceLc])) {
            return;
        }

        $parents = $ctx->classes[$ifaceLc]->interfaces;
        for ($i = count($parents) - 1; $i >= 0; --$i) {
            $parentLc = $parents[$i];
            if (isset($seen[$parentLc])) {
                continue;
            }
            $seen[$parentLc] = true;
            $ordered[] = $parentLc;
            self::appendReflectionInterfaceParents($parentLc, $ctx, $ordered, $seen);
        }
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
                $parentLc = strtolower(ltrim($parentLc, '\\'));
                if (!isset($ctx->classes[$parentLc])) {
                    continue;
                }
                self::addInterfaceAndParents($ctx->classes[$parentLc], $ctx, $result);
            }

            return $result;
        }

        // Walk parent classes so inherited interface lists match Zend (#19784).
        // Interface names on ClassEntry may be mixed-case; registry keys are lowercase.
        $current = $entry;
        $visited = [];
        while (null !== $current) {
            $lc = strtolower($current->name);
            if (isset($visited[$lc])) {
                break;
            }
            $visited[$lc] = true;
            // php-src stores a flattened ce->interfaces table. When our ClassEntry already
            // lists both Iterator and Traversable as siblings, treat the list as that table
            // and do not re-expand parents (RecursiveIterator vs SeekableIterator parent
            // edges disagree on Traversable/Iterator order; #25799).
            $rematerialized = self::interfacesListIsRematerialized($current->interfaces);
            foreach ($current->interfaces as $ifaceLc) {
                $builtin = self::builtinEnumInterfaceDisplayName($ifaceLc);
                if (null !== $builtin) {
                    $result[$builtin] = $builtin;
                    continue;
                }
                $ifaceLc = strtolower(ltrim($ifaceLc, '\\'));
                if (!isset($ctx->classes[$ifaceLc])) {
                    continue;
                }
                if ($rematerialized) {
                    $name = $ctx->classes[$ifaceLc]->name;
                    if (!isset($result[$name])) {
                        $result[$name] = $name;
                    }
                    continue;
                }
                self::addInterfaceAndParents($ctx->classes[$ifaceLc], $ctx, $result);
            }
            if (null === $current->parentLc || !isset($ctx->classes[$current->parentLc])) {
                break;
            }
            $current = $ctx->classes[$current->parentLc];
        }

        if (StringableSupport::entryHasImplicitStringable($entry, $ctx)) {
            $name = StringableSupport::INTERFACE_NAME;
            $result[$name] = $name;
        }

        return $result;
    }

    /**
     * True when ClassEntry.interfaces already holds Zend's flattened ce->interfaces table
     * (both Iterator and Traversable appear as direct siblings).
     *
     * @param list<string> $interfaces
     */
    private static function interfacesListIsRematerialized(array $interfaces): bool
    {
        $hasIterator = false;
        $hasTraversable = false;
        foreach ($interfaces as $iface) {
            $lc = strtolower(ltrim((string) $iface, '\\'));
            if ('iterator' === $lc) {
                $hasIterator = true;
            } elseif ('traversable' === $lc) {
                $hasTraversable = true;
            }
            if ($hasIterator && $hasTraversable) {
                return true;
            }
        }

        return false;
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

    /**
     * Insert interface then parents in Zend ce->interfaces expansion order (#25790).
     *
     * Matches {@see appendReflectionInterfaceName}: parents are inserted in reverse
     * declaration order so SeekableIterator (Iterator, Traversable) expands to
     * SeekableIterator, Traversable, Iterator — the same order as php-src.
     *
     * @param array<string, string> $result
     */
    private static function addInterfaceAndParents(ClassEntry $iface, Context $ctx, array &$result): void
    {
        if (!$iface->isInterface) {
            return;
        }
        $name = $iface->name;
        if (isset($result[$name])) {
            return;
        }
        $result[$name] = $name;

        $parents = $iface->interfaces;
        for ($i = count($parents) - 1; $i >= 0; --$i) {
            $parentLc = strtolower(ltrim($parents[$i], '\\'));
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
     * get_class_vars() — default values for properties visible from the calling scope (#3159, #23531).
     *
     * php-src: Zend/zend_builtin_functions.c — add_class_vars / PHP_FUNCTION(get_class_vars)
     * Scope is zend_get_executed_scope(): outside a class only publics; inside a method,
     * protected/private defaults that zend_check_protected / declaring-CE equality allow.
     */
    public static function getClassVarsArray(ClassEntry $entry, ?Frame $frame = null): Variable
    {
        $ctx = null;
        $scopeClass = null;
        if (null !== $frame) {
            $ctx = self::requireContext($frame);
            $callerLc = self::callerClassLcFromFrame($frame);
            if (null !== $callerLc && isset($ctx->classes[$callerLc])) {
                $scopeClass = $ctx->classes[$callerLc];
            }
        }
        $result = new Variable();
        $result->newArray();
        $ht = $result->toArray();
        $entryLc = strtolower(ltrim($entry->name, '\\'));
        foreach ($entry->properties as $prop) {
            // php-src add_class_vars: skip ZEND_ACC_VIRTUAL — true virtual hooked props only
            // (no backing store). Short set => / same-name backed stay (#22493, #23881).
            if ($prop->propertyHookVirtual) {
                continue;
            }
            $declaringLc = '' !== $prop->declaringClassLc ? $prop->declaringClassLc : $entryLc;
            if (!self::classVarVisibleFromScope($ctx, $scopeClass, $prop->visibility, $declaringLc)) {
                continue;
            }
            $copy = new Variable();
            // php-src add_class_vars: backed hooked props use declared defaults, not get-hook reads (#6603).
            self::copyClassVarDefault($copy, $prop);
            $ht->add($prop->name, $copy);
        }
        self::addScopedStaticClassVars($entry, $ht, $ctx, $scopeClass);
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

    /**
     * php-src add_class_vars visibility: public always; else EG(scope) vs declaring CE (#23531).
     */
    private static function classVarVisibleFromScope(
        ?Context $ctx,
        ?ClassEntry $scopeClass,
        int $visibility,
        string $declaringClassLc
    ): bool {
        if (MethodVisibility::isPublic($visibility)) {
            return true;
        }
        if (null === $scopeClass || null === $ctx) {
            return false;
        }

        return self::propertyVisibleFromScope($ctx, $scopeClass, $visibility, $declaringClassLc);
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
        // Constructor-promoted properties: default lives on the parameter, not the
        // property AST (php-src zend_compile.c / zim_reflection_property_hasDefaultValue) (#22046).
        if ($prop->fromConstructorPromotion) {
            return false;
        }
        if (null !== $prop->default || $prop->hasRuntimeDefaultInit()) {
            return true;
        }
        // Untyped `public $b;` — Zend includes implicit null (#22047, php_reflection.c).
        return !$prop->hasDeclaredType();
    }

    public static function staticPropertyHasDefaultValue(Variable $storage): bool
    {
        return !$storage->resolveIndirect()->isUndefined();
    }

    public static function propertyDefaultValueIsAvailable(ClassProperty $prop): bool
    {
        if ($prop->fromConstructorPromotion) {
            return false;
        }
        if ($prop->hasRuntimeDefaultInit()) {
            return false;
        }
        if (null !== $prop->default) {
            return true;
        }
        // Implicit null default is readable without evaluating an init block (#22047).
        return !$prop->hasDeclaredType();
    }

    public static function copyPropertyDefaultValue(Variable $dest, ClassProperty $prop, Context $ctx): bool
    {
        if ($prop->fromConstructorPromotion) {
            return false;
        }
        $value = $ctx->runtime->vm()->evaluatePropertyDefaultForReflection($prop);
        if (null === $value) {
            return false;
        }
        $dest->copyFrom($value);

        return true;
    }

    public static function parameterDefaultValueIsAvailable(Block $block, int $paramIndex): bool
    {
        return ReflectionSupport::parameterDefaultValueIsAvailable($block, $paramIndex);
    }

    public static function copyParameterDefaultValue(
        Variable $dest,
        Block $block,
        int $paramIndex,
        Context $ctx,
    ): bool {
        $value = $ctx->runtime->vm()->evaluateParameterDefaultForReflection($block, $paramIndex);
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
     * Static properties declared on $entry visible from calling scope (php-src add_class_vars, #7397, #23531).
     *
     * @param \PHPCompiler\VM\HashTable $ht
     */
    private static function addScopedStaticClassVars(
        ClassEntry $entry,
        $ht,
        ?Context $ctx,
        ?ClassEntry $scopeClass
    ): void {
        /** @var array<string, true> $seen */
        $seen = [];
        foreach ($ht->iterate(false) as $key => $_value) {
            $seen[(string) $key] = true;
        }
        $entryLc = strtolower(ltrim($entry->name, '\\'));
        foreach (self::orderedStaticPropertyKeys($entry) as $propLc) {
            $storage = $entry->staticProperties[$propLc];
            // php-src add_class_vars: statics on $entry include trait-composed and parent-inherited (#7420).
            $visibility = $entry->staticPropertyVisibility[$propLc] ?? \PHPCfg\Func::FLAG_PUBLIC;
            $declaringLc = $entry->staticPropertyDeclaringClassLc[$propLc] ?? $entryLc;
            if (!self::classVarVisibleFromScope($ctx, $scopeClass, $visibility, $declaringLc)) {
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
    private static function orderedStaticPropertyKeys(ClassEntry $entry): array
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
        // php-src ext/reflection/php_reflection.c — null class/object name → "" (#21770).
        if (Variable::TYPE_NULL === $arg->type) {
            ReflectionSupport::throwReflectionException(
                ReflectionSupport::classNotFoundMessage('')
            );
        }
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
     * Values are the **current** parameter locals (php-src `zend_get_parameters_array_ex`),
     * not the call-time `calledArgs` snapshot (#21984 / #22025). Variadic extras stay on
     * the call-time argv (mutating `...$rest` does not rewrite those slots).
     *
     * Block::$func is a php-cfg {@see CfgFunc} (not {@see Func\PHP}); zero-arg calls leave
     * calledArgs empty, so detect user frames via {@see Block::isMainScript()} (#19617).
     * Always take the innermost such frame — do not walk past a zero-arg callee to an
     * outer caller with non-empty argv (#25896 / Zend execute_data).
     *
     * @return list<Variable>
     */
    public static function userCallArgs(Frame $frame): array
    {
        for ($f = $frame->parent; null !== $f; $f = $f->parent) {
            if (null === $f->block || null === $f->block->func || $f->hasHandler()) {
                continue;
            }
            // {main} is never a func_get_args() context (php-src basic_functions.c).
            if ($f->block->isMainScript()) {
                continue;
            }

            // Innermost enclosing user function / method / closure (incl. zero-arg).
            return self::liveUserCallArgs($f);
        }

        throw new \LogicException('Must be called from a function context');
    }

    /**
     * Current parameter values for {@see userCallArgs()} / debug_backtrace args (php-src-strict; #21984, #24948).
     *
     * Sparse named-arg maps keep holes for RECV defaults (#23388). Zend's func_* / backtrace argc is
     * {@code max(passed parameter index)+1}, with skipped leading optionals filled from live locals
     * (defaults already applied). Do not {@see array_values()} — that collapses `f(b:9)` to one slot.
     *
     * @return list<Variable>
     */
    public static function liveUserCallArgs(Frame $userFrame): array
    {
        $block = $userFrame->block;
        $cfgFunc = $block->func;
        $isInstance = null !== $cfgFunc->class
            && 0 === (($cfgFunc->flags ?? 0) & \PHPCfg\Func::FLAG_STATIC);
        $userCalled = [];
        foreach ($userFrame->calledArgs as $idx => $var) {
            $i = (int) $idx;
            if ($isInstance) {
                if ($i < 1) {
                    continue;
                }
                $userCalled[$i - 1] = $var;
            } else {
                $userCalled[$i] = $var;
            }
        }
        if ([] === $userCalled) {
            return [];
        }

        $maxIdx = (int) max(array_keys($userCalled));
        $variadicIdx = $block->variadicParamIndex;
        $out = [];
        for ($i = 0; $i <= $maxIdx; ++$i) {
            $useLive = null === $variadicIdx || $i < $variadicIdx;
            if ($useLive) {
                // Prefer the named CV — ARG_RECV slots can go stale after try/catch CFG splits (#24948).
                $name = $block->paramNames[$i] ?? null;
                if (null !== $name && '' !== $name) {
                    $byName = $block->findVariableByRuntimeName($name, $userFrame);
                    if (null !== $byName) {
                        $out[] = $byName;
                        continue;
                    }
                }
                $slot = $block->paramSlotForIndex($i);
                if (null !== $slot && isset($userFrame->scope[$slot])) {
                    $out[] = $userFrame->scope[$slot];
                    continue;
                }
            }
            if (array_key_exists($i, $userCalled)) {
                $out[] = $userCalled[$i];
                continue;
            }
            $missing = new Variable();
            $missing->null();
            $out[] = $missing;
        }

        return $out;
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
     *
     * Autoloads the subject like zend_lookup_class (#26406).
     */
    public static function isSubclassOf(Context $ctx, string $childName, string $parentName): bool
    {
        $child = self::lookupClassEntryWithAutoload($ctx, $childName);
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
     * is_a() class-string operand with allow_string — includes same class (#26406).
     *
     * php-src: Zend/zend_builtin_functions.c — PHP_FUNCTION(is_a) + zend_lookup_class
     */
    public static function isAString(Context $ctx, string $childName, string $className): bool
    {
        $child = self::lookupClassEntryWithAutoload($ctx, $childName);
        if (null === $child) {
            return false;
        }

        return self::isInstanceOf($ctx, $child, $className);
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
                EnumCaseSupport::typeNameForTypeErrorActual($object)
            ));
        }
        // SimpleXMLElement: same property map as (array) cast (php-src sxe.c; #21666).
        $obj = $object->toObject();
        if (SimpleXmlJsonExport::handles($obj)) {
            return SimpleXmlJsonExport::exportZendArrayCast($obj);
        }
        $ctx = self::requireContext($frame);
        $result = new Variable();
        $result->newArray();
        $ht = $result->toArray();
        foreach ($ctx->runtime->vm()->collectObjectVarsForBuiltin($obj, $frame) as $name => $value) {
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
        $obj = $object->toObject();
        // php-src spl_array_get_properties_for(ZEND_PROP_PURPOSE_VAR_EXPORT) (#24447).
        $storage = \PHPCompiler\ext\spl\SplArrayStorage::varExportStorageTable($obj);
        if (null !== $storage) {
            $result = new Variable();
            $result->array($storage);

            return $result;
        }
        // SimpleXMLElement: same property map as (array)/get_object_vars (php-src sxe.c; #25339).
        if (SimpleXmlJsonExport::handles($obj)) {
            return SimpleXmlJsonExport::exportZendArrayCast($obj);
        }
        $ctx = self::requireContext($frame);
        $result = new Variable();
        $result->newArray();
        $ht = $result->toArray();
        foreach ($ctx->runtime->vm()->collectVarExportPropertiesForBuiltin($obj, $frame) as $name => $value) {
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
                EnumCaseSupport::typeNameForTypeErrorActual($object)
            ));
        }
        // SimpleXMLElement: same public property map (no mangling; php-src sxe.c; #21666).
        $obj = $object->toObject();
        if (SimpleXmlJsonExport::handles($obj)) {
            return SimpleXmlJsonExport::exportZendArrayCast($obj);
        }
        $ctx = self::requireContext($frame);
        $result = new Variable();
        $result->newArray();
        $ht = $result->toArray();
        foreach ($ctx->runtime->vm()->collectMangledObjectVarsForBuiltin($obj, $frame) as $name => $value) {
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

    /** Default visibility filter: public only (php-src get_class_methods, basic_functions.c #4756). */
    public const METHOD_FILTER_DEFAULT = \PHPCfg\Func::FLAG_PUBLIC;

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
     * get_class_methods() — resolve operand or TypeError when class name is unknown (#18110).
     *
     * php-src: ext/standard/basic_functions.c — PHP_FUNCTION(get_class_methods)
     */
    public static function requireClassForGetClassMethods(Context $ctx, Variable $arg): ClassEntry
    {
        $entry = self::resolveClassForGetClassMethods($ctx, $arg);
        if (null !== $entry) {
            return $entry;
        }
        $arg = $arg->resolveIndirect();
        $given = Variable::TYPE_STRING === $arg->type
            ? 'string'
            : EnumCaseSupport::typeNameForTypeErrorActual($arg);
        throw new \TypeError(\sprintf(
            'get_class_methods(): Argument #1 ($object_or_class) must be an object or a valid class name, %s given',
            $given
        ));
    }

    /**
     * Public (etc.) method names including parents — php-src get_class_methods (#22789, #23530).
     *
     * Walks `parentLc` like {@see collectClassMethodsForReflection()} so builtin SPL
     * subclasses (SplFileObject → SplFileInfo, SplStack → SplDoublyLinkedList, …) list
     * inherited methods. Interfaces keep {@see interfaceDeclarationChain()}.
     *
     * When `$frame` is set, visibility follows zend_get_executed_scope() /
     * zend_check_method_accessible (outside a class: public only; inside: private of the
     * declaring CE + protected via zend_check_protected).
     *
     * @return list<string>
     */
    public static function classMethodsList(
        ClassEntry $entry,
        int $filter = 7,
        ?Context $ctx = null,
        ?Frame $frame = null
    ): array {
        $entries = [$entry];
        $scopeClass = null;
        if (null !== $frame) {
            if (null === $ctx) {
                $ctx = self::requireContext($frame);
            }
            $callerLc = self::callerClassLcFromFrame($frame);
            if (null !== $callerLc && isset($ctx->classes[$callerLc])) {
                $scopeClass = $ctx->classes[$callerLc];
            }
        }
        if (null !== $ctx) {
            $entries = $entry->isInterface
                ? self::interfaceDeclarationChain($entry, $ctx)
                : self::classHierarchyChain($entry, $ctx);
        }
        // Scope-aware listing ignores the bitmask and uses accessibility (#23530).
        $useScopeFilter = null !== $frame;
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
            $declaringLc = strtolower(ltrim($scan->name, '\\'));
            foreach ($methodLcs as $methodLc) {
                if (isset($seenMethodLcs[$methodLc])) {
                    continue;
                }
                $vis = $scan->methodVisibility[$methodLc] ?? \PHPCfg\Func::FLAG_PUBLIC;
                if ($useScopeFilter) {
                    if (null === $ctx
                        || !self::methodAccessibleFromExecutedScope($ctx, $scopeClass, $vis, $declaringLc)
                    ) {
                        continue;
                    }
                } else {
                    if (0 !== ($filter & 7) && 0 === ($vis & $filter & 7)) {
                        continue;
                    }
                    // Parent-private methods are not visible on the child (zend_get_class_methods).
                    if (($vis & \PHPCfg\Func::FLAG_PRIVATE) !== 0 && $scan !== $entry) {
                        continue;
                    }
                }
                // PDO_*_Ext / similar parent-only methods are not inherited (#21552).
                if ($scan !== $entry && isset($scan->methodNotInherited[$methodLc])) {
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
            $synthFilter = $useScopeFilter
                ? (\PHPCfg\Func::FLAG_PUBLIC | \PHPCfg\Func::FLAG_PROTECTED | \PHPCfg\Func::FLAG_PRIVATE)
                : $filter;
            foreach (self::syntheticEnumMethodNames($scan, $synthFilter) as $methodName) {
                if (!in_array($methodName, $names, true)) {
                    $names[] = $methodName;
                }
            }
        }

        return $names;
    }

    /**
     * php-src zend_check_method_accessible / get_class_methods visibility (#23530).
     *
     * Public always; private when scope === declaring CE; protected via zend_check_protected
     * (either CE is the other or an ancestor — Zend/zend_object_handlers.c).
     */
    private static function methodAccessibleFromExecutedScope(
        Context $ctx,
        ?ClassEntry $scopeClass,
        int $visibility,
        string $declaringClassLc
    ): bool {
        if (MethodVisibility::isPublic($visibility)) {
            return true;
        }
        if (null === $scopeClass) {
            return false;
        }
        $scopeLc = strtolower(ltrim($scopeClass->name, '\\'));
        if (($visibility & \PHPCfg\Func::FLAG_PRIVATE) !== 0) {
            return $scopeLc === $declaringClassLc;
        }
        if (($visibility & \PHPCfg\Func::FLAG_PROTECTED) !== 0) {
            return self::isSameOrSubclassOf($ctx, $scopeLc, $declaringClassLc)
                || self::isSameOrSubclassOf($ctx, $declaringClassLc, $scopeLc);
        }

        return true;
    }

    /**
     * Interface + parent interfaces for get_class_methods() (php-src basic_functions.c, #11689).
     *
     * @return list<ClassEntry>
     */
    public static function interfaceDeclarationChain(ClassEntry $entry, Context $ctx): array
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

    public static function classMethodsArray(
        ClassEntry $entry,
        int $filter = 7,
        ?Context $ctx = null,
        ?Frame $frame = null
    ): Variable {
        $result = new Variable();
        $result->newArray();
        $ht = $result->toArray();
        foreach (self::classMethodsList($entry, $filter, $ctx, $frame) as $methodName) {
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
     *
     * Callers that implement the user-visible get_class() builtin must emit the PHP 8.3+
     * parameterless E_DEPRECATED themselves ({@see CompilerVersion::supportsGetClassParentClassParameterlessDeprecation()})
     * — this helper is also used for self/parent scope resolution (#26369).
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

    /**
     * Parent class name for get_parent_class() with no arguments, or null → false (#26369).
     *
     * php-src: Zend/zend_builtin_functions.c — zend_get_executed_scope() then ce->parent.
     * Outside a class scope returns null (Zend false) after the caller emits E_DEPRECATED.
     */
    public static function zeroArgGetParentClassName(Frame $frame): ?string
    {
        $current = $frame->parent;
        if (null === $current) {
            return null;
        }
        if (null === $current->block || null === $current->block->func || null === $current->block->func->class) {
            return null;
        }
        $className = $current->block->func->class->value;
        $ctx = self::requireContext($frame);
        $entry = self::resolveClassEntry($ctx, $className);
        if (null === $entry || $entry->isInterface || $entry->isTrait || $entry->isEnum) {
            return null;
        }

        return self::parentClassName($entry, $ctx);
    }

    /** php-src ZEND_ACC_* filter bitmask for getProperties() (ReflectionProperty::IS_*). */
    public const REFLECTION_IS_PUBLIC = \PHPCfg\Func::FLAG_PUBLIC;

    public const REFLECTION_IS_PROTECTED = \PHPCfg\Func::FLAG_PROTECTED;

    public const REFLECTION_IS_PRIVATE = \PHPCfg\Func::FLAG_PRIVATE;

    public const REFLECTION_IS_STATIC = 16;

    /** php-src ZEND_ACC_READONLY — ReflectionProperty::IS_READONLY (#22128). */
    public const REFLECTION_IS_READONLY = 128;

    /**
     * php-src ZEND_ACC_FINAL — ReflectionProperty::IS_FINAL (PHP 8.4+ final properties, #22341).
     * Same bit as {@see REFLECTION_METHOD_IS_FINAL} / ReflectionMethod::IS_FINAL.
     */
    public const REFLECTION_IS_FINAL = 32;

    /** php-src ZEND_ACC_VIRTUAL — ReflectionProperty::IS_VIRTUAL (PHP 8.4+). */
    public const REFLECTION_IS_VIRTUAL = 512;

    /** php-src ZEND_ACC_PUBLIC_SET (PHP 8.4+ asymmetric visibility). */
    public const REFLECTION_IS_PUBLIC_SET = 1024;

    /** php-src ZEND_ACC_PROTECTED_SET (PHP 8.4+ asymmetric visibility). */
    public const REFLECTION_IS_PROTECTED_SET = 2048;

    /** php-src ZEND_ACC_PRIVATE_SET (PHP 8.4+ asymmetric visibility). */
    public const REFLECTION_IS_PRIVATE_SET = 4096;

    /** php-src ZEND_ACC_PPP_MASK — mutually exclusive visibility bits. */
    public const REFLECTION_PPP_MASK = self::REFLECTION_IS_PUBLIC
        | self::REFLECTION_IS_PROTECTED
        | self::REFLECTION_IS_PRIVATE;

    /** php-src ZEND_ACC_PPP_SET_MASK — mutually exclusive set-visibility bits. */
    public const REFLECTION_PPP_SET_MASK = self::REFLECTION_IS_PUBLIC_SET
        | self::REFLECTION_IS_PROTECTED_SET
        | self::REFLECTION_IS_PRIVATE_SET;

    /** php-src ZEND_ACC_DEPRECATED — ReflectionFunction::IS_DEPRECATED (#22128). */
    public const REFLECTION_FUNCTION_IS_DEPRECATED = 2048;

    /**
     * Reflection::getModifierNames($modifiers) — php-src zim_Reflection_getModifierNames (#22127).
     *
     * Order: abstract, final, virtual, public|protected|private, protected(set)|private(set),
     * static, readonly. Visibility uses exact PPP / PPP_SET match (mutually exclusive).
     * PPP_SET name tokens are PROFILE≥8.5 only (php-src #19691 / GH-19697; #29188) — 8.4 keeps
     * the bits / is*Set() accessors but omits the strings.
     *
     * @return Variable list<string>
     */
    public static function reflectionGetModifierNames(int $modifiers): Variable
    {
        $result = new Variable();
        $result->newArray();
        $ht = $result->toArray();

        $append = static function (string $name) use ($ht): void {
            $slot = new Variable();
            $slot->string($name);
            $ht->append($slot);
        };

        // ZEND_ACC_ABSTRACT | ZEND_ACC_EXPLICIT_ABSTRACT_CLASS (same bit = 64)
        if (($modifiers & self::REFLECTION_METHOD_IS_ABSTRACT) !== 0) {
            $append('abstract');
        }
        if (($modifiers & self::REFLECTION_METHOD_IS_FINAL) !== 0) {
            $append('final');
        }
        if (($modifiers & self::REFLECTION_IS_VIRTUAL) !== 0) {
            $append('virtual');
        }

        switch ($modifiers & self::REFLECTION_PPP_MASK) {
            case self::REFLECTION_IS_PUBLIC:
                $append('public');
                break;
            case self::REFLECTION_IS_PRIVATE:
                $append('private');
                break;
            case self::REFLECTION_IS_PROTECTED:
                $append('protected');
                break;
        }

        // php-src 8.5+ only — GH-19697; omit on PROFILE=8.4 / reference (#29188).
        if (CompilerVersion::supportsAsymmetricVisibilityModifierNames()) {
            switch ($modifiers & self::REFLECTION_PPP_SET_MASK) {
                case self::REFLECTION_IS_PROTECTED_SET:
                    $append('protected(set)');
                    break;
                case self::REFLECTION_IS_PRIVATE_SET:
                    $append('private(set)');
                    break;
            }
        }

        if (($modifiers & self::REFLECTION_IS_STATIC) !== 0) {
            $append('static');
        }

        if (($modifiers & self::REFLECTION_IS_READONLY) !== 0
            || ($modifiers & self::REFLECTION_CLASS_IS_READONLY) !== 0) {
            $append('readonly');
        }

        return $result;
    }

    /** Register ReflectionAttribute::IS_INSTANCEOF (#11471, ext/reflection/php_reflection.c). */
    public static function registerReflectionAttributeClassConstants(ClassEntry $entry): void
    {
        $const = new Variable();
        $const->int(ReflectionSupport::REFLECTION_ATTRIBUTE_IS_INSTANCEOF);
        // Case-sensitive class-const keys (#25910) — php-src name is IS_INSTANCEOF.
        $entry->constants['IS_INSTANCEOF'] = $const;
        $entry->constNames['IS_INSTANCEOF'] = 'IS_INSTANCEOF';
    }

    /**
     * Register int class constants on a Reflection builtin (#22128, php_reflection.stub.php).
     *
     * Storage keys must match php-src / ClassConstFetch casing (IS_*), not lower_snake.
     * After #25910, lowercase keys only resolved via host native fallback — which lacks
     * ReflectionProperty::IS_FINAL on the 8.2 reference profile (#26222, re-#22341).
     *
     * @param array<string, int> $constants lower_snake name => value (canonicalized to UPPER)
     */
    private static function registerIntClassConstants(ClassEntry $entry, array $constants): void
    {
        foreach ($constants as $name => $value) {
            $const = new Variable();
            $const->int($value);
            $canonical = strtoupper($name);
            $entry->constants[$canonical] = $const;
            $entry->constNames[$canonical] = $canonical;
        }
    }

    /** Register ReflectionProperty::IS_* class constants (#5060, #4470, #22128, #22341, #28137, #28248). */
    public static function registerReflectionPropertyClassConstants(ClassEntry $entry): void
    {
        $constants = [
            'is_public' => self::REFLECTION_IS_PUBLIC,
            'is_protected' => self::REFLECTION_IS_PROTECTED,
            'is_private' => self::REFLECTION_IS_PRIVATE,
            'is_static' => self::REFLECTION_IS_STATIC,
            'is_readonly' => self::REFLECTION_IS_READONLY,
        ];
        // php-src 8.4+ ReflectionProperty::IS_FINAL (ZEND_ACC_FINAL) — absent on 8.2 reference.
        if (CompilerVersion::supportsFinalProperties()) {
            $constants['is_final'] = self::REFLECTION_IS_FINAL;
        }
        // php-src 8.4+ asymmetric set-visibility constants (ZEND_ACC_*_SET) — #28137.
        if (CompilerVersion::supportsAsymmetricVisibility()) {
            $constants['is_public_set'] = self::REFLECTION_IS_PUBLIC_SET;
            $constants['is_protected_set'] = self::REFLECTION_IS_PROTECTED_SET;
            $constants['is_private_set'] = self::REFLECTION_IS_PRIVATE_SET;
        }
        // php-src 8.4+ property-hook flags (ZEND_ACC_VIRTUAL / ZEND_ACC_ABSTRACT) — #28248.
        if (CompilerVersion::supportsPropertyHooks()) {
            $constants['is_virtual'] = self::REFLECTION_IS_VIRTUAL;
            $constants['is_abstract'] = self::REFLECTION_METHOD_IS_ABSTRACT;
        }
        self::registerIntClassConstants($entry, $constants);
    }

    /** Register ReflectionMethod::IS_* class constants (#7116, #22128, php_reflection.stub.php). */
    public static function registerReflectionMethodClassConstants(ClassEntry $entry): void
    {
        self::registerIntClassConstants($entry, [
            'is_static' => self::REFLECTION_METHOD_IS_STATIC,
            'is_public' => self::REFLECTION_METHOD_IS_PUBLIC,
            'is_protected' => self::REFLECTION_METHOD_IS_PROTECTED,
            'is_private' => self::REFLECTION_METHOD_IS_PRIVATE,
            'is_abstract' => self::REFLECTION_METHOD_IS_ABSTRACT,
            'is_final' => self::REFLECTION_METHOD_IS_FINAL,
        ]);
    }

    /** Register ReflectionFunction::IS_DEPRECATED (#22128, php_reflection.stub.php). */
    public static function registerReflectionFunctionClassConstants(ClassEntry $entry): void
    {
        self::registerIntClassConstants($entry, [
            'is_deprecated' => self::REFLECTION_FUNCTION_IS_DEPRECATED,
        ]);
    }

    /**
     * Register ReflectionClass::IS_* (+ SKIP_* when lazy objects) (#18335, #21126, #22128).
     * php-src: ext/reflection/php_reflection.stub.php / register_reflection_constants.
     */
    public static function registerReflectionClassClassConstants(ClassEntry $entry): void
    {
        self::registerIntClassConstants($entry, [
            'is_implicit_abstract' => self::REFLECTION_CLASS_IS_IMPLICIT_ABSTRACT,
            'is_explicit_abstract' => self::REFLECTION_CLASS_IS_EXPLICIT_ABSTRACT,
            'is_final' => self::REFLECTION_CLASS_IS_FINAL,
            'is_readonly' => self::REFLECTION_CLASS_IS_READONLY,
        ]);
        if (!\PHPCompiler\CompilerVersion::supportsLazyObjectFactories()) {
            return;
        }
        self::registerIntClassConstants($entry, [
            'skip_initialization_on_serialize' => \PHPCompiler\VM\LazyObjectSupport::SKIP_INITIALIZATION_ON_SERIALIZE,
            'skip_destructor' => \PHPCompiler\VM\LazyObjectSupport::SKIP_DESTRUCTOR,
        ]);
    }

    /** Register ReflectionClassConstant::IS_* class constants (#17360, ext/reflection/php_reflection.c). */
    public static function registerReflectionClassConstantClassConstants(ClassEntry $entry): void
    {
        self::registerIntClassConstants($entry, [
            'is_public' => self::REFLECTION_METHOD_IS_PUBLIC,
            'is_protected' => self::REFLECTION_METHOD_IS_PROTECTED,
            'is_private' => self::REFLECTION_METHOD_IS_PRIVATE,
            'is_final' => self::REFLECTION_METHOD_IS_FINAL,
        ]);
    }

    /** php-src reflection_class_constant_get_modifiers() (#17360). */
    public static function cfgClassConstantFlagsToReflectionModifiers(int $cfgVisibility, bool $isFinal): int
    {
        if (($cfgVisibility & \PHPCfg\Func::FLAG_PRIVATE) !== 0) {
            $modifiers = self::REFLECTION_METHOD_IS_PRIVATE;
        } elseif (($cfgVisibility & \PHPCfg\Func::FLAG_PROTECTED) !== 0) {
            $modifiers = self::REFLECTION_METHOD_IS_PROTECTED;
        } else {
            $modifiers = self::REFLECTION_METHOD_IS_PUBLIC;
        }
        if ($isFinal) {
            $modifiers |= self::REFLECTION_METHOD_IS_FINAL;
        }

        return $modifiers;
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

    /**
     * php-src zim_ReflectionClass_isCloneable — ce->clone public flag or default clone_obj (#22109).
     */
    public static function reflectionClassIsCloneable(ClassEntry $entry, Context $ctx): bool
    {
        if ($entry->isInterface || $entry->isTrait || $entry->isEnum || $entry->isAbstract) {
            return false;
        }
        if ([] !== $entry->abstractMethods) {
            return false;
        }
        // clone_obj = NULL on this class or an ancestor (Exception/Error, WeakReference; #25870, #25962).
        foreach (self::classHierarchyChain($entry, $ctx) as $class) {
            if ($class->denyClone) {
                return false;
            }
        }
        $cloneLc = '__clone';
        foreach (self::classHierarchyChain($entry, $ctx) as $class) {
            if (isset($class->methods[$cloneLc])) {
                $vis = $class->methodVisibility[$cloneLc] ?? \PHPCfg\Func::FLAG_PUBLIC;

                return ($vis & \PHPCfg\Func::FLAG_PUBLIC) !== 0;
            }
        }

        return true;
    }

    public static function matchesReflectionVisibilityFilter(int $cfgVisibility, int $filter): bool
    {
        return self::propertyMatchesReflectionFilter($cfgVisibility, false, $filter);
    }

    /** ReflectionClass::getMethods() filter — include static/final/abstract from method flags (#4480). */
    public static function methodMatchesReflectionFilter(int $cfgFlags, int $filter): bool
    {
        if (0 === $filter) {
            return true;
        }
        $flags = self::cfgMethodFlagsToReflectionModifiers($cfgFlags);

        return ($flags & $filter) !== 0;
    }

    public static function propertyMatchesReflectionFilter(int $cfgVisibility, bool $isStatic, int $filter): bool
    {
        if (0 === $filter) {
            return true;
        }
        $flags = self::visibilityToReflectionBitmask($cfgVisibility);
        if ($isStatic) {
            $flags |= self::REFLECTION_IS_STATIC;
        }

        return ($flags & $filter) !== 0;
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
     * Map asymmetric set-visibility CFG flags to ReflectionProperty::IS_*_SET (#28137).
     * Symmetric properties store setVisibility=0 and contribute no SET bits (php-src prop->flags).
     */
    public static function setVisibilityToReflectionBitmask(int $setVisibility): int
    {
        if (0 === $setVisibility) {
            return 0;
        }
        if (($setVisibility & \PHPCfg\Func::FLAG_PRIVATE) !== 0) {
            return self::REFLECTION_IS_PRIVATE_SET;
        }
        if (($setVisibility & \PHPCfg\Func::FLAG_PROTECTED) !== 0) {
            return self::REFLECTION_IS_PROTECTED_SET;
        }

        return self::REFLECTION_IS_PUBLIC_SET;
    }

    /** True when $property is a static property on $class or an ancestor (#22143). */
    public static function propertyIsStatic(ClassEntry $class, string $property, Context $ctx): bool
    {
        return null !== self::findStaticPropertyKey($class, $property, $ctx);
    }

    /**
     * php-src zim_ReflectionProperty_getModifiers — IS_* bitmask (#22143, #22341, #28137, #28248).
     * Dynamic properties are public-only (ZEND_ACC_PUBLIC|ZEND_ACC_VIRTUAL).
     * keep_flags includes ZEND_ACC_FINAL, ZEND_ACC_*_SET, ZEND_ACC_VIRTUAL, ZEND_ACC_ABSTRACT (8.4+).
     */
    public static function propertyReflectionModifiers(
        ClassEntry $entry,
        string $property,
        Context $ctx,
        bool $isDynamic = false
    ): int {
        if ($isDynamic) {
            return self::REFLECTION_IS_PUBLIC;
        }
        if (self::isEnumReflectionPseudoProperty($entry, $property)) {
            return self::REFLECTION_IS_PUBLIC | self::REFLECTION_IS_READONLY;
        }
        $meta = self::propertyVisibilityMeta($entry, $property, $ctx);
        if (null === $meta) {
            return self::REFLECTION_IS_PUBLIC;
        }
        $modifiers = self::visibilityToReflectionBitmask($meta['visibility']);
        $modifiers |= self::setVisibilityToReflectionBitmask((int) $meta['setVisibility']);
        if (self::propertyIsStatic($entry, $property, $ctx)) {
            $modifiers |= self::REFLECTION_IS_STATIC;
        }
        $instance = self::findClassProperty($entry, $property, $ctx);
        if (null !== $instance && $instance->readonly) {
            $modifiers |= self::REFLECTION_IS_READONLY;
        }
        // php-src prop->flags & ZEND_ACC_FINAL → ReflectionProperty::IS_FINAL (#22341, #23683).
        // private(set) is implicitly final (zend_API.c, #23068). Plain final does not block writes.
        if (
            (null !== $instance && $instance->propertyFinal)
            || self::staticPropertyIsFinal($entry, $property, $ctx)
            || self::propertyIsFinalFromHookRegistry($entry, $property, $ctx)
            || \PHPCompiler\PropertyVisibility::isImplicitlyFinalFromPrivateSet($meta['setVisibility'])
        ) {
            $modifiers |= self::REFLECTION_IS_FINAL;
        }
        // php-src prop->flags & ZEND_ACC_VIRTUAL → ReflectionProperty::IS_VIRTUAL (#28248).
        if (\PHPCompiler\VM\ReflectionPropertyHookSupport::isVirtual($entry, $instance, $property, $ctx)) {
            $modifiers |= self::REFLECTION_IS_VIRTUAL;
        }
        // php-src prop->flags & ZEND_ACC_ABSTRACT → ReflectionProperty::IS_ABSTRACT (#28248).
        if (null !== $instance
            && \PHPCompiler\VM\AbstractPropertyHookCheck::isAbstractHookProperty($entry, $instance, $ctx)
        ) {
            $modifiers |= self::REFLECTION_METHOD_IS_ABSTRACT;
        }

        return $modifiers;
    }

    /**
     * True when a static property carries ZEND_ACC_FINAL (#23683, #23403).
     */
    private static function staticPropertyIsFinal(
        ClassEntry $entry,
        string $property,
        Context $ctx
    ): bool {
        $lc = strtolower($property);
        $current = $entry;
        while (true) {
            if (!empty($current->staticPropertyFinal[$lc])) {
                return true;
            }
            if (null === $current->parentLc || !isset($ctx->classes[$current->parentLc])) {
                return false;
            }
            $current = $ctx->classes[$current->parentLc];
        }
    }

    /**
     * Fallback when ClassProperty::$propertyFinal was not populated but the hook registry
     * recorded a final property (hooked finals, #20511 / #22341).
     */
    private static function propertyIsFinalFromHookRegistry(
        ClassEntry $entry,
        string $property,
        Context $ctx
    ): bool {
        $lcClass = strtolower($entry->name);
        $propLc = strtolower($property);
        $propMeta = $ctx->propertyHookRegistry[$lcClass][$property]
            ?? $ctx->propertyHookRegistry[$lcClass][$propLc]
            ?? null;

        return is_array($propMeta) && !empty($propMeta['finalProperty']);
    }

    /** php-src ReflectionClass::IS_* values returned by getModifiers() (#18335). */
    public const REFLECTION_CLASS_IS_IMPLICIT_ABSTRACT = 16;

    public const REFLECTION_CLASS_IS_EXPLICIT_ABSTRACT = 64;

    public const REFLECTION_CLASS_IS_FINAL = 32;

    public const REFLECTION_CLASS_IS_READONLY = 65536;

    /** php-src zim_ReflectionClass_get_modifiers — ce->ce_flags class bitmask (#18335). */
    public static function classEntryToReflectionModifiers(ClassEntry $entry): int
    {
        if ($entry->isInterface || $entry->isTrait) {
            return 0;
        }
        $modifiers = 0;
        if ($entry->isAbstract || [] !== $entry->abstractMethods) {
            $modifiers |= self::REFLECTION_CLASS_IS_EXPLICIT_ABSTRACT;
        }
        if ($entry->isFinal) {
            $modifiers |= self::REFLECTION_CLASS_IS_FINAL;
        }
        if ($entry->readonly) {
            $modifiers |= self::REFLECTION_CLASS_IS_READONLY;
        }

        return $modifiers;
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
     * Instance and static properties visible on $entry, php-src ReflectionClass::getProperties (#4470).
     *
     * @return list<ClassProperty>
     */
    public static function collectClassPropertiesForReflection(ClassEntry $entry, Context $ctx, int $filter = 0): array
    {
        $result = [];
        /** @var array<string, true> */
        $seenLc = [];
        foreach (self::classHierarchyChain($entry, $ctx) as $class) {
            foreach ($class->properties as $prop) {
                if ($prop->phpInvisible) {
                    // C-level / engine storage — not in Zend's PHP property table (#22513, #26155).
                    continue;
                }
                $declLc = '' !== $prop->declaringClassLc
                    ? $prop->declaringClassLc
                    : strtolower(ltrim($class->name, '\\'));
                if (
                    ($prop->visibility & \PHPCfg\Func::FLAG_PRIVATE) !== 0
                    && $declLc !== strtolower(ltrim($entry->name, '\\'))
                ) {
                    continue;
                }
                $lc = strtolower($prop->name);
                if (isset($seenLc[$lc])) {
                    continue;
                }
                if (!self::propertyMatchesReflectionFilter($prop->visibility, false, $filter)) {
                    continue;
                }
                $seenLc[$lc] = true;
                $result[] = $prop;
            }
            $classLc = strtolower(ltrim($class->name, '\\'));
            foreach ($class->staticProperties as $propLc => $storage) {
                if (isset($seenLc[$propLc])) {
                    continue;
                }
                $vis = $class->staticPropertyVisibility[$propLc] ?? CfgFunc::FLAG_PUBLIC;
                $declLc = $class->staticPropertyDeclaringClassLc[$propLc] ?? $classLc;
                if (($vis & CfgFunc::FLAG_PRIVATE) !== 0 && $declLc !== strtolower(ltrim($entry->name, '\\'))) {
                    continue;
                }
                if (!self::propertyMatchesReflectionFilter($vis, true, $filter)) {
                    continue;
                }
                $seenLc[$propLc] = true;
                $displayName = $storage->objectPropertyName ?? $propLc;
                $proto = new Variable();
                $proto->copyFrom($storage->resolveIndirect());
                $result[] = new ClassProperty(
                    $displayName,
                    null,
                    $proto,
                    false,
                    $vis,
                    $declLc,
                );
            }
        }

        // php-src add_reflection_property: enum virtual name/value props (#22030, zend_enum.c).
        if ($entry->isEnum) {
            $entryLc = strtolower(ltrim($entry->name, '\\'));
            $enumProps = null !== $entry->backedType ? ['name', 'value'] : ['name'];
            foreach ($enumProps as $enumProp) {
                $lc = strtolower($enumProp);
                if (isset($seenLc[$lc])) {
                    continue;
                }
                if (!self::propertyMatchesReflectionFilter(CfgFunc::FLAG_PUBLIC, false, $filter)) {
                    continue;
                }
                $proto = new Variable();
                $proto->null();
                $result[] = new ClassProperty(
                    $enumProp,
                    null,
                    $proto,
                    true,
                    CfgFunc::FLAG_PUBLIC,
                    $entryLc,
                );
            }
        }

        return $result;
    }

    /**
     * Declared Reflection method name casing (php-src ext/reflection; #21283).
     *
     * Prefer explicit {@see ClassEntry::$methodNames}, then Internal handler
     * {@see Func::getName()} (DOM/builtin registration keys are lowercase), else the lc key.
     */
    public static function canonicalMethodDisplayName(ClassEntry $class, string $methodLc): string
    {
        if (isset($class->methodNames[$methodLc])) {
            return $class->methodNames[$methodLc];
        }
        $handler = $class->methods[$methodLc] ?? null;
        if ($handler instanceof Func) {
            $name = $handler->getName();
            if (str_contains($name, '::')) {
                $name = substr($name, strrpos($name, '::') + 2);
            }

            return $name;
        }

        return $methodLc;
    }

    /**
     * Methods visible on $entry (child overrides parent), php-src ReflectionClass::getMethods.
     *
     * @return list<array{methodLc: string, display: string, declaring: ClassEntry}>
     */
    public static function collectClassMethodsForReflection(ClassEntry $entry, Context $ctx, int $filter = 0): array
    {
        if ($entry->isInterface) {
            return self::collectInterfaceMethodsForReflection($entry, $ctx, $filter);
        }

        $chain = array_reverse(self::classHierarchyChain($entry, $ctx));
        $byLc = [];
        foreach ($chain as $class) {
            self::mergeClassMethodsIntoReflectionMap($class, $entry, $byLc, $filter, true);
        }

        return array_values($byLc);
    }

    /**
     * Interface getMethods: own methods first, then parent-interface methods not yet seen (#25427).
     *
     * Matches SeekableIterator (seek then Iterator) and Throwable (__toString from Stringable last).
     *
     * @return list<array{methodLc: string, display: string, declaring: ClassEntry}>
     */
    private static function collectInterfaceMethodsForReflection(
        ClassEntry $entry,
        Context $ctx,
        int $filter
    ): array {
        $byLc = [];
        self::mergeClassMethodsIntoReflectionMap($entry, $entry, $byLc, $filter, true);
        foreach (self::interfaceDeclarationChain($entry, $ctx) as $iface) {
            if ($iface === $entry) {
                continue;
            }
            self::mergeClassMethodsIntoReflectionMap($iface, $entry, $byLc, $filter, false);
        }

        return array_values($byLc);
    }

    /**
     * @param array<string, array{methodLc: string, display: string, declaring: ClassEntry}> $byLc
     */
    private static function mergeClassMethodsIntoReflectionMap(
        ClassEntry $class,
        ClassEntry $reflected,
        array &$byLc,
        int $filter,
        bool $overwrite
    ): void {
        $methodLcs = array_keys($class->methods);
        foreach (array_keys($class->abstractMethods) as $abstractLc) {
            if (!in_array($abstractLc, $methodLcs, true)) {
                $methodLcs[] = $abstractLc;
            }
        }
        foreach ($methodLcs as $methodLc) {
            if (!$overwrite && isset($byLc[$methodLc])) {
                continue;
            }
            $flags = $class->methodVisibility[$methodLc] ?? \PHPCfg\Func::FLAG_PUBLIC;
            if (isset($class->abstractMethods[$methodLc])) {
                $flags |= \PHPCfg\Func::FLAG_ABSTRACT;
            }
            if (!self::methodMatchesReflectionFilter($flags, $filter)) {
                continue;
            }
            // php-src add_reflection_method_sub: parent-private methods hidden on child (#7191).
            if (($flags & \PHPCfg\Func::FLAG_PRIVATE) !== 0 && $class !== $reflected) {
                continue;
            }
            // PDO_*_Ext / similar parent-only methods are not visible on subclasses (#21552).
            if ($class !== $reflected && isset($class->methodNotInherited[$methodLc])) {
                continue;
            }
            $byLc[$methodLc] = [
                'methodLc' => $methodLc,
                'display' => self::canonicalMethodDisplayName($class, $methodLc),
                'declaring' => $class,
            ];
        }
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
        // php-src ReflectionClass::hasMethod finds parent-private __construct via ce->constructor
        // even though getMethods() omits it (#26059 / #7191).
        if (('__construct' === $methodLc || '__destruct' === $methodLc) && 0 === $filter) {
            foreach (self::classHierarchyChain($entry, $ctx) as $class) {
                if (isset($class->methods[$methodLc])) {
                    return true;
                }
            }
        }
        if ($entry->isEnum && self::methodExistsOnClass($entry, $method)) {
            return true;
        }
        // Closure::__invoke trampoline — Zend ReflectionClass::hasMethod true (#19616).
        if (self::isClosureInvokeMethod($entry->name, $method)) {
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
        $usesLazyGhostTrait = \PHPCompiler\VM\LazyGhostTraitSupport::classUsesLazyGhostTrait($entry, $ctx);
        $byLc = [];
        foreach (array_reverse(self::classHierarchyChain($entry, $ctx)) as $class) {
            foreach ($class->properties as $prop) {
                if ($prop->propertyHookVirtual) {
                    continue;
                }
                if ($usesLazyGhostTrait || $prop->lazy) {
                    $byLc[strtolower($prop->name)] = $prop->name;
                }
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
            // Zend public surface: $name / $class (#22504).
            $obj->getProperty(\PHPCompiler\VM\ReflectionSupport::PROP_PROPERTY_NAME)->string($prop->name);
            $declaring = self::declaringClassNameForPropertyLookup($entry, $prop->name, $ctx);
            $obj->getProperty(\PHPCompiler\VM\ReflectionSupport::PROP_DECLARING_CLASS_NAME)->string($declaring);
            $obj->getProperty(\PHPCompiler\VM\ReflectionSupport::PROP_IS_DYNAMIC)->bool(false);
            $slot = new Variable(Variable::TYPE_OBJECT);
            $slot->object($obj);
            $ht->append($slot);
        }

        return $result;
    }

    /**
     * ReflectionClass::getProperties() / ReflectionObject::getProperties() result array (#3815, #20098).
     *
     * When $instance is set (ReflectionObject), undeclared dynamic properties on that
     * instance are included — php-src zim_ReflectionClass_getProperties with intern->obj.
     */
    public static function reflectionPropertiesArray(
        Context $ctx,
        ClassEntry $entry,
        string $reflectedClassName,
        int $filter = 0,
        ?\PHPCompiler\VM\ObjectEntry $instance = null
    ): Variable {
        $rpClass = $ctx->classes[\PHPCompiler\VM\ReflectionSupport::REFLECTION_PROPERTY] ?? null;
        if (null === $rpClass) {
            throw new \LogicException('ReflectionProperty is not registered in this compiler build');
        }
        $result = new Variable();
        $result->newArray();
        $ht = $result->toArray();
        /** @var array<string, true> */
        $seenLc = [];
        foreach (self::collectClassPropertiesForReflection($entry, $ctx, $filter) as $prop) {
            $seenLc[strtolower($prop->name)] = true;
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
        if (null !== $instance
            && self::propertyMatchesReflectionFilter(\PHPCfg\Func::FLAG_PUBLIC, false, $filter)
        ) {
            foreach ($instance->propertiesWithNames() as $name => $_) {
                $lc = strtolower($name);
                if (isset($seenLc[$lc])) {
                    continue;
                }
                if (self::propertyExistsOnClass($entry, $name, $ctx)) {
                    continue;
                }
                $seenLc[$lc] = true;
                $obj = new \PHPCompiler\VM\ObjectEntry($rpClass);
                $obj->constructed = true;
                self::attachReflectionPropertyState(
                    $obj,
                    $reflectedClassName,
                    $name,
                    $reflectedClassName,
                    true
                );
                $slot = new Variable(Variable::TYPE_OBJECT);
                $slot->object($obj);
                $ht->append($slot);
            }
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
            // Zend ReflectionMethod::$class = declaring scope, not the reflected class (#22582).
            $declName = \PHPCompiler\VM\ReflectionSupport::declaringClassNameForMethod(
                $ctx,
                $entry,
                $spec['display']
            );
            $obj->getProperty(\PHPCompiler\VM\ReflectionSupport::PROP_REFLECTION_METHOD_CLASS)->string($declName);
            $obj->getProperty(\PHPCompiler\VM\ReflectionSupport::PROP_REFLECTION_METHOD_FUNC)->string($spec['display']);
            $slot = new Variable(Variable::TYPE_OBJECT);
            $slot->object($obj);
            $ht->append($slot);
        }

        return $result;
    }

    /**
     * ReflectionClass::* visibility filter — php-src `?int $filter = null` (#30897).
     *
     * Missing/null → 0 (all). Non-coercible types TypeError like Zend Z_PARAM_LONG_OR_NULL.
     */
    public static function optionalReflectionFilterArg(
        Frame $frame,
        int $argIndex,
        string $methodLabel = 'ReflectionClass::getProperties'
    ): int {
        if (\count($frame->calledArgs) <= $argIndex) {
            return 0;
        }
        $parsed = VmMath::parseNullableIntBuiltinArgForFrame(
            $frame,
            $argIndex,
            $methodLabel,
            $argIndex,
            'filter'
        );

        return null === $parsed ? 0 : $parsed;
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
        // Case-sensitive key (Zend enum cases / #25910 / #25929) — not strtolower (#25940).
        $caseKey = \PHPCompiler\ClassConstName::key($caseName);
        if (!isset($enumEntry->enumCaseCanonicalNames[$caseKey])) {
            ReflectionSupport::throwReflectionException(
                ReflectionSupport::enumCaseNotFoundMessage($enumEntry->name, $caseName)
            );
        }
        $obj = new \PHPCompiler\VM\ObjectEntry($reucClass);
        $obj->constructed = true;
        \PHPCompiler\VM\ReflectionSupport::initReflectionEnumCaseMetadata(
            $obj,
            $enumEntry->name,
            $enumEntry->enumCaseCanonicalNames[$caseKey]
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
        // Case-sensitive key (Zend enum cases / #25910 / #25929) — not strtolower (#25940).
        $caseKey = \PHPCompiler\ClassConstName::key($caseName);
        if (!isset($enumEntry->enumCaseCanonicalNames[$caseKey])) {
            ReflectionSupport::throwReflectionException(
                ReflectionSupport::enumCaseNotFoundMessage($enumEntry->name, $caseName)
            );
        }
        $obj = new \PHPCompiler\VM\ObjectEntry($rebcClass);
        $obj->constructed = true;
        \PHPCompiler\VM\ReflectionSupport::initReflectionEnumCaseMetadata(
            $obj,
            $enumEntry->name,
            $enumEntry->enumCaseCanonicalNames[$caseKey]
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
     * attribute_exists() operand dispatch — php-src attribute first, object|string second (#16844).
     */
    public static function attributeExistsForObjectOrClass(
        Context $ctx,
        Variable $objectOrClass,
        string $attributeName
    ): bool {
        $objectOrClass = $objectOrClass->resolveIndirect();
        if (Variable::TYPE_STRING === $objectOrClass->type) {
            return self::attributeExists($ctx, $objectOrClass->toString(), $attributeName);
        }
        if (Variable::TYPE_OBJECT === $objectOrClass->type) {
            $object = $objectOrClass->toObject();
            if (EnumCaseSupport::isEnumCase($object)) {
                $class = EnumSupport::resolveRuntimeEnumClass($ctx, $object->class);

                return self::attributeExistsOnClassEntry($ctx, $class, $attributeName);
            }

            return self::attributeExistsOnClassEntry($ctx, $object->class, $attributeName);
        }
        if (Variable::TYPE_ENUM_CASE === $objectOrClass->type) {
            $class = EnumSupport::resolveRuntimeEnumClass($ctx, $objectOrClass->toEnumCase()->enumClass);

            return self::attributeExistsOnClassEntry($ctx, $class, $attributeName);
        }
        throw new \TypeError(\sprintf(
            'attribute_exists(): Argument #2 ($object) must be of type object|string, %s given',
            VmClassHas::vmTypeName($objectOrClass->type)
        ));
    }

    private static function attributeExistsOnClassEntry(
        Context $ctx,
        ClassEntry $entry,
        string $attributeName
    ): bool {
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
     * get_class_vars() — resolve class/interface/trait/enum or reject invalid operand (#13271).
     *
     * php-src: ext/standard/class.c — PHP_FUNCTION(get_class_vars) / zend_fetch_class()
     */
    public static function fetchClassEntryForGetClassVars(Context $ctx, string $className): ClassEntry
    {
        $classLc = strtolower(self::normalizeGlobalIntrospectionName($className));
        if (!isset($ctx->classes[$classLc])) {
            $ctx->autoloadClass($className);
        }
        if (!isset($ctx->classes[$classLc])) {
            throw new \TypeError(\sprintf(
                'get_class_vars(): Argument #1 ($class) must be a valid class name, %s given',
                $className
            ));
        }

        return $ctx->classes[$classLc];
    }

    /**
     * Class constants visible on $entry (child overrides parent), php-src ReflectionClass::getConstants (#6950, #4479).
     *
     * Walk the parent chain child-first and keep the first occurrence of each name.
     * Interface-declared constants (e.g. DateTimeInterface::ATOM copied onto DateTime with
     * constDeclaringClassLc = datetimeinterface, #30229) are not in the parent chain, so they
     * must be taken from the merged table on the concrete class (#30887) — not filtered by
     * "declared on this ClassEntry name".
     *
     * @return list<array{name: string, declaring: ClassEntry, constLc: string}>
     */
    public static function collectClassConstantsForReflection(ClassEntry $entry, Context $ctx, int $filter): array
    {
        $entryLc = strtolower(ltrim($entry->name, '\\'));
        $byLc = [];
        foreach (self::classHierarchyChain($entry, $ctx) as $class) {
            foreach ($class->constants as $constLc => $_stored) {
                if (isset($byLc[$constLc])) {
                    continue;
                }
                $vis = $class->constVisibility[$constLc] ?? \PHPCfg\Func::FLAG_PUBLIC;
                $declLc = $class->constDeclaringClassLc[$constLc]
                    ?? strtolower(ltrim($class->name, '\\'));
                // Parent private constants are not inherited onto children; if we still see one
                // while walking ancestors, keep it only when reflecting that declaring class.
                if (($vis & \PHPCfg\Func::FLAG_PRIVATE) !== 0 && $declLc !== $entryLc) {
                    continue;
                }
                if (!self::matchesReflectionVisibilityFilter($vis, $filter)) {
                    continue;
                }
                $displayName = $class->constNames[$constLc]
                    ?? $class->enumCaseCanonicalNames[$constLc]
                    ?? $constLc;
                $declaring = $ctx->classes[$declLc] ?? $class;
                $byLc[$constLc] = [
                    'name' => $displayName,
                    'declaring' => $declaring,
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
        // Zend public surface: $class / $name (#22503); $class is declaring class (#22581).
        if (ReflectionSupport::REFLECTION_CLASS_CONSTANT === strtolower($rcClass->name)) {
            $rc->getProperty(ReflectionSupport::PROP_REFLECTION_CLASS_CONSTANT_CLASS)->string($declaringClassName);
            $rc->getProperty(ReflectionSupport::PROP_REFLECTION_CLASS_CONSTANT_NAME)->string($constantName);
        } else {
            $rc->getProperty(ReflectionSupport::PROP_CLASS_NAME)->string($declaringClassName);
            $rc->getProperty(ReflectionSupport::PROP_CONSTANT_NAME)->string($constantName);
        }

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
     * Resolve ReflectionClass::getConstants() visibility filter (#6950, filter flags in #4479, #30897).
     *
     * php-src: null filter returns all constants; IS_* bitmasks narrow the set.
     */
    public static function reflectionConstantsFilterArg(
        Frame $frame,
        int $argIndex,
        string $methodLabel = 'ReflectionClass::getConstants'
    ): int {
        return self::optionalReflectionFilterArg($frame, $argIndex, $methodLabel);
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

    /**
     * Build a ReflectionReference for an array bucket IS_REFERENCE cell (#22065).
     */
    public static function newReflectionReference(Context $ctx, Variable $bucketValue): \PHPCompiler\VM\ObjectEntry
    {
        $class = $ctx->classes[\PHPCompiler\VM\ReflectionSupport::REFLECTION_REFERENCE] ?? null;
        if (null === $class) {
            throw new \LogicException('ReflectionReference is not registered in this compiler build');
        }
        if (!\PHPCompiler\VM\ReflectionReferenceSupport::bucketValueIsReference($bucketValue)) {
            throw new \LogicException('ReflectionReference requires a reference bucket value');
        }
        $obj = new \PHPCompiler\VM\ObjectEntry($class);
        $obj->constructed = true;
        $idVar = new Variable();
        $idVar->string(\PHPCompiler\VM\ReflectionReferenceSupport::idForBucketValue($bucketValue));
        $obj->getProperty(\PHPCompiler\VM\ReflectionSupport::PROP_REFLECTION_REFERENCE_ID)->copyFrom($idVar);

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

    /**
     * php-src reflection_extension_get_classes — name => ReflectionClass (#18326).
     */
    public static function reflectionExtensionClassesTable(Context $ctx, string $extension): HashTable
    {
        $ht = new HashTable();
        $ext = strtolower($extension);
        $rcClass = $ctx->classes[ReflectionSupport::REFLECTION_CLASS] ?? null;
        if (null === $rcClass) {
            return $ht;
        }
        foreach ($ctx->classes as $entry) {
            if (!$entry->isInternal) {
                continue;
            }
            if (self::logicalExtensionForInternalClass($entry->name) !== $ext) {
                continue;
            }
            $name = $entry->name;
            $obj = new \PHPCompiler\VM\ObjectEntry($rcClass);
            $obj->constructed = true;
            $obj->getProperty(ReflectionSupport::PROP_CLASS_NAME)->string($name);
            $slot = new Variable();
            $slot->object($obj);
            $key = new Variable();
            $key->string($name);
            $ht->add($key->toString(), $slot);
        }

        return $ht;
    }

    public static function logicalExtensionForInternalClass(string $className): ?string
    {
        $lc = strtolower(ltrim($className, '\\'));
        if (\in_array($lc, [
            '__php_incomplete_class',
            'assertionerror',
            'php_user_filter',
            'directory',
        ], true)) {
            return 'standard';
        }
        if (str_starts_with($lc, 'dom') || 'domxpath' === $lc) {
            return 'dom';
        }
        if (
            str_starts_with($lc, 'spl')
            || str_starts_with($lc, 'recursive')
            || str_starts_with($lc, 'filter')
            || str_starts_with($lc, 'regex')
            || str_starts_with($lc, 'parent')
            || str_starts_with($lc, 'limit')
            || str_starts_with($lc, 'glob')
            || str_starts_with($lc, 'append')
            || str_starts_with($lc, 'caching')
            || str_starts_with($lc, 'empty')
            || str_starts_with($lc, 'norewind')
            || \in_array($lc, [
                'arrayiterator', 'arrayobject', 'directoryiterator', 'filesystemiterator',
                'logicexception', 'badfunctioncallexception', 'badmethodcallexception',
                'domainexception', 'invalidargumentexception', 'lengthexception',
                'outofrangeexception', 'outofboundsexception', 'overflowexception',
                'runtimeexception', 'underflowexception', 'unexpectedvalueexception',
            ], true)
        ) {
            return 'spl';
        }
        if (
            str_starts_with($lc, 'datetime')
            || \in_array($lc, ['dateinterval', 'dateperiod', 'datetimelocale', 'datetimezone'], true)
        ) {
            return 'date';
        }
        if (str_starts_with($lc, 'json')) {
            return 'json';
        }
        if ('closure' === $lc || 'generator' === $lc) {
            return 'core';
        }
        if (str_starts_with($lc, 'reflection')) {
            return 'core';
        }
        if (str_starts_with($lc, 'fiber')) {
            return 'core';
        }
        if (str_starts_with($lc, 'weak')) {
            return 'core';
        }
        if ('stdclass' === $lc || 'resource' === $lc) {
            return 'core';
        }

        return null;
    }

    /**
     * ReflectionClass/Method::getExtensionName() for internal classes (#22098, ext/reflection/php_reflection.c).
     */
    public static function extensionNameForInternalClass(string $className): string
    {
        $logical = self::logicalExtensionForInternalClass($className);
        if (null === $logical || 'core' === $logical) {
            return 'Core';
        }

        return $logical;
    }

    /** php-src reflection_extension_get_version — phpversion($extension) (#18326). */
    public static function reflectionExtensionVersion(string $extension): string
    {
        $version = VmInfo::phpversion($extension);
        if (false === $version) {
            return CompilerVersion::reportedPhpVersion();
        }

        return $version;
    }

    /**
     * php-src reflection_extension_get_functions — name => ReflectionFunction (#18326).
     */
    public static function reflectionExtensionFunctionsTable(Context $ctx, string $extension): HashTable
    {
        $ht = new HashTable();
        $funcs = ModuleRegistry::getExtensionFunctions($extension) ?? [];
        if ('standard' === strtolower($extension)) {
            foreach (ModuleRegistry::extensionFunctionMap() as $bucketFuncs) {
                foreach ($bucketFuncs as $name) {
                    $lc = strtolower($name);
                    if ('standard' !== ModuleRegistry::reflectionOwningExtension($lc)) {
                        continue;
                    }
                    if (ModuleRegistry::functionRegisteredInBucket($lc, 'standard')) {
                        continue;
                    }
                    if (!\in_array($name, $funcs, true)) {
                        $funcs[] = $name;
                    }
                }
            }
        }
        $rfClass = $ctx->classes[ReflectionSupport::REFLECTION_FUNCTION] ?? null;
        if (null === $rfClass) {
            return $ht;
        }
        foreach ($funcs as $name) {
            if (!self::functionIsVisibleInReflection($name, $extension)) {
                continue;
            }
            $lc = strtolower($name);
            $func = $ctx->functions[$lc] ?? null;
            $obj = new \PHPCompiler\VM\ObjectEntry($rfClass);
            $obj->constructed = true;
            $obj->reflectionIsInternalFunction = $func instanceof FuncInternal;
            $obj->getProperty(ReflectionSupport::PROP_REFLECTION_FUNCTION_NAME)->string($name);
            $slot = new Variable();
            $slot->object($obj);
            $key = new Variable();
            $key->string($name);
            $ht->add($key->toString(), $slot);
        }

        return $ht;
    }

    /**
     * php-src reflection_extension_get_constants — extension module constants (#18326).
     */
    public static function reflectionExtensionConstantsTable(Context $ctx, string $extension): HashTable
    {
        $ht = new HashTable();
        $ext = strtolower($extension);
        $groups = ExtensionConstantGroups::groups();
        $constants = $groups[$ext] ?? [];
        foreach ($constants as $name => $fallback) {
            $value = $ctx->constantFetchBuiltin($name);
            if (null === $value) {
                $value = new Variable();
                if (\is_int($fallback)) {
                    $value->int($fallback);
                } elseif (\is_float($fallback)) {
                    $value->float($fallback);
                } elseif (\is_bool($fallback)) {
                    $value->bool($fallback);
                } else {
                    $value->string((string) $fallback);
                }
            }
            $key = new Variable();
            $key->string($name);
            $ht->add($name, $value);
        }

        return $ht;
    }

    /**
     * ReflectionExtension::getClassNames() — indexed names matching getClasses() keys (#22247).
     *
     * php-src: ext/reflection/php_reflection.c — ZEND_METHOD(ReflectionExtension, getClassNames)
     */
    public static function reflectionExtensionClassNamesTable(Context $ctx, string $extension): HashTable
    {
        $ht = new HashTable();
        $index = 0;
        foreach (self::reflectionExtensionClassesTable($ctx, $extension)->iterateKeyed(false) as [$keyVar]) {
            $slot = new Variable();
            $slot->string($keyVar->toString());
            $ht->addIndex($index, $slot);
            ++$index;
        }

        return $ht;
    }

    /**
     * ReflectionExtension::getDependencies() — name => Required|Optional|Conflicts (#22247).
     *
     * php-src: ext/reflection/php_reflection.c — ZEND_METHOD(ReflectionExtension, getDependencies)
     * Module deps are not stored on our Module entries yet; mirror php-src zend_module_dep tables.
     *
     * @return HashTable<string, string>
     */
    public static function reflectionExtensionDependenciesTable(string $extension): HashTable
    {
        $ht = new HashTable();
        $deps = self::EXTENSION_DEPENDENCIES[strtolower($extension)] ?? [];
        foreach ($deps as $name => $relation) {
            $slot = new Variable();
            $slot->string($relation);
            $ht->add($name, $slot);
        }

        return $ht;
    }

    /**
     * php-src zend_module_dep tables for bundled extensions (subset used by ReflectionExtension).
     *
     * @var array<string, array<string, string>>
     */
    private const EXTENSION_DEPENDENCIES = [
        'standard' => ['session' => 'Optional'],
        'spl' => ['json' => 'Required'],
        'session' => ['hash' => 'Optional', 'spl' => 'Required'],
        'sodium' => ['standard' => 'Required'],
        'pdo' => ['spl' => 'Required'],
        'xml' => ['libxml' => 'Required'],
        'dom' => ['libxml' => 'Required', 'domxml' => 'Conflicts'],
        'mbstring' => ['pcre' => 'Required'],
        'simplexml' => ['libxml' => 'Required', 'spl' => 'Required'],
        'exif' => ['standard' => 'Required', 'mbstring' => 'Optional'],
        'phar' => [
            'apc' => 'Optional',
            'bz2' => 'Optional',
            'openssl' => 'Optional',
            'zlib' => 'Optional',
            'standard' => 'Optional',
            'hash' => 'Required',
            'spl' => 'Required',
        ],
    ];

    /** Bundled extensions are MODULE_PERSISTENT (no dl()); php-src module->type. */
    public static function reflectionExtensionIsPersistent(string $extension): bool
    {
        return ModuleRegistry::extensionLoaded($extension);
    }

    /** Temporary = MODULE_TEMPORARY (dl()); we never load via dl(). */
    public static function reflectionExtensionIsTemporary(string $extension): bool
    {
        return false;
    }

    /**
     * Approximate module_number for __toString header (1-based registration order).
     */
    public static function reflectionExtensionModuleNumber(string $extension): int
    {
        $ext = strtolower($extension);
        $n = 1;
        foreach (ModuleRegistry::getLoadedExtensions() as $name) {
            if (strtolower($name) === $ext) {
                return $n;
            }
            ++$n;
        }

        return 0;
    }

    /**
     * ReflectionExtension::info() — php_info_print_module text subset (#22247).
     *
     * Full per-module PHP_MINFO is not ported; emit name + support row like simple modules.
     */
    public static function reflectionExtensionInfoText(string $extension): string
    {
        $name = $extension;
        foreach (ModuleRegistry::getLoadedExtensions() as $loaded) {
            if (strtolower($loaded) === strtolower($extension)) {
                $name = $loaded;
                break;
            }
        }

        return "\n".$name."\n\n".$name." support => enabled\n\n";
    }

    /**
     * ReflectionExtension::__toString() — _extension_string shape (#22247).
     *
     * Omits full nested ReflectionFunction/Class dumps; lists names so cast is non-empty and structured.
     */
    public static function reflectionExtensionToString(Context $ctx, string $extension): string
    {
        $name = $extension;
        foreach (ModuleRegistry::getLoadedExtensions() as $loaded) {
            if (strtolower($loaded) === strtolower($extension)) {
                $name = $loaded;
                break;
            }
        }
        $version = self::reflectionExtensionVersion($name);
        $moduleNumber = self::reflectionExtensionModuleNumber($name);
        $persistent = self::reflectionExtensionIsPersistent($name);
        $temporary = self::reflectionExtensionIsTemporary($name);

        $out = 'Extension [ ';
        if ($persistent) {
            $out .= '<persistent> ';
        }
        if ($temporary) {
            $out .= '<temporary> ';
        }
        $out .= 'extension #'.$moduleNumber.' '.$name.' version '.$version." ] {\n";

        $deps = self::reflectionExtensionDependenciesTable($name);
        $depLines = '';
        foreach ($deps->iterateKeyed(false) as [$keyVar, $valVar]) {
            $depLines .= '    Dependency [ '.$keyVar->toString().' ('.$valVar->toString().") ]\n";
        }
        if ('' !== $depLines) {
            $out .= "\n  - Dependencies {\n".$depLines."  }\n";
        }

        $ini = VmIni::reflectionIniEntries($ctx, $name);
        $iniLines = '';
        foreach ($ini->iterateKeyed(false) as [$keyVar, $valVar]) {
            $iniLines .= '    Entry [ '.$keyVar->toString().' ]';
            if (Variable::TYPE_NULL === $valVar->type) {
                $iniLines .= " { }\n";
            } else {
                $iniLines .= " { Current = '".$valVar->toString()."' }\n";
            }
        }
        if ('' !== $iniLines) {
            $out .= "\n  - INI {\n".$iniLines."  }\n";
        }

        $constants = self::reflectionExtensionConstantsTable($ctx, $name);
        $constCount = 0;
        $constLines = '';
        foreach ($constants->iterateKeyed(false) as [$keyVar, $valVar]) {
            ++$constCount;
            $constLines .= '    Constant [ '.$keyVar->toString().' ] { '.$valVar->toString()." }\n";
        }
        if ($constCount > 0) {
            $out .= "\n  - Constants [".$constCount."] {\n".$constLines."  }\n";
        }

        $functions = self::reflectionExtensionFunctionsTable($ctx, $name);
        $fnLines = '';
        foreach ($functions->iterateKeyed(false) as [$keyVar]) {
            $fnLines .= '    Function [ <internal:'.strtolower($name).'> function '.$keyVar->toString()." ] {\n\n    }\n";
        }
        if ('' !== $fnLines) {
            $out .= "\n  - Functions {\n".$fnLines."  }\n";
        }

        $classes = self::reflectionExtensionClassNamesTable($ctx, $name);
        $classCount = 0;
        $classLines = '';
        foreach ($classes->iterate(false) as $nameVar) {
            ++$classCount;
            $classLines .= '    Class [ <internal:'.strtolower($name).'> class '.$nameVar->toString()." ] {\n\n    }\n";
        }
        if ($classCount > 0) {
            $out .= "\n  - Classes [".$classCount."] {\n".$classLines."  }\n";
        }

        $out .= "}\n";

        return $out;
    }
}
