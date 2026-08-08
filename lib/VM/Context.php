<?php

/*
 * This file is part of PHP-Compiler, a PHP CFG Compiler for PHP code
 *
 * @copyright 2015 Anthony Ferrara. All rights reserved
 * @license MIT See LICENSE at the root of the project for more info
 */

namespace PHPCompiler\VM;

use PHPCompiler\Block;
use PHPCompiler\Frame;
use PHPCompiler\Func;
use PHPCompiler\Runtime;
use PHPCompiler\Web\Superglobals;

class Context {
    public array $functions = [];
    public array $classes = [];
    /**
     * User class aliases: lowercase alias => lowercase canonical class key (#3095).
     *
     * @var array<string, string>
     */
    public array $classAliases = [];
    /** @var array<string, true> lowercase enum name => registered (#1373, #1356) */
    public array $enums = [];
    /** @var list<callable(string): bool> */
    public array $classAutoloaders = [];
    /** @var list<\PHPCompiler\ext\standard\SplAutoloadRunner> spl_autoload_register() stack (#1369) */
    public array $splAutoloadCallbacks = [];
    /** @var array<string, true> */
    private array $loadedCompileUnits = [];

    /** @var list<string> absolute paths from main script + include/require (issue #3315). */
    private array $includedFiles = [];
    private ?RunStackEntry $runStack = null;
    public array $constants = [];

    /** @var array<string, \PHPCompiler\Compiler\DeprecatedMetadata> */
    public array $globalConstDeprecated = [];

    /**
     * File/namespace constant attribute entries (PHP 8.5+ TARGET_CONSTANT, #23882).
     *
     * @var array<string, list<\PHPCompiler\Compiler\AttributeEntry>> lowercase const => entries
     */
    public array $globalConstAttributeEntries = [];

    /**
     * Declaring filename for user constants (ReflectionConstant::getFileName, PHP 8.5+, #21551).
     *
     * @var array<string, string>
     */
    public array $globalConstantFilenames = [];

    /** @var array<string, Variable> */
    private array $superglobalVars = [];

    /**
     * filter_input() / filter_has_var() request-input snapshots (php-src IF_G arrays, #19640).
     * Keyed by superglobal name (_GET, _POST, …). Captured when CGI/web populates request
     * tables — not updated by later $_GET[...] = writes.
     *
     * @var array<string, HashTable>
     */
    private array $filterInputSnapshots = [];

    /** @var array<string, Variable> */
    private array $globalVars = [];

    /** @var array<string, true> script globals that received a value (#6800, zend_variables.c) */
    private array $globalEverAssigned = [];

    /** Lazily built $GLOBALS superglobal table (issue #3413). */
    private ?Variable $globalsSuperglobal = null;

    /** @var array<string, Variable> function-local static storage keyed by compile-time key (#2286) */
    private array $functionStaticVars = [];

    /** @var array<string, true> */
    private array $functionStaticInitialized = [];

    public Runtime $runtime;

    /** Active declare(ticks=N) interval; 0 disables tick dispatch (#3343). */
    public int $tickInterval = 0;

    /** Countdown to next tick callback invocation (#3343). */
    public int $tickCounter = 0;

    /** @var list<int> Saved tick intervals for nested declare blocks (#3343). */
    public array $tickIntervalStack = [];

    /**
     * Property-hook virtual/backing metadata from {@see \PHPCompiler\SourcePreprocessor\PropertyHooks} (#4687).
     *
     * @var array<string, array<string, array<string, mixed>>>
     */
    public array $propertyHookRegistry = [];

    /**
     * Reserved map for runtime property-hook overrides (never populated: ReflectionProperty::setHook
     * is a phantom vs php-src — removed in #22494 / re-#22116).
     *
     * @var array<string, array<string, array<string, ClosureState>>>
     */
    public array $reflectionPropertyRuntimeHooks = [];

    /** Pending thrown value while dispatching catch handlers (issue #1362). */
    public ?Variable $pendingException = null;

    /** Set when a property set hook throws (even if caught); suppresses outer assign (#3145). */
    public bool $propertyHookSetAborted = false;

    /** Catch frame for throw/TypeError during nested property-hook invoke; bubble to caller (#7301, #9503). */
    public ?Frame $propertyHookExternalCatchFrame = null;

    /** True while {@see VM::invokeUserDestructor} runs on an isolated run stack (#12070). */
    public bool $isolatedDestructorInvoke = false;

    /** True while user __clone() runs on an isolated run stack (#12068, zend_object_handlers.c). */
    public bool $invokingCloneMagic = false;

    /** True while serialize/unserialize magic hooks run on an isolated stack (#12069). */
    public bool $isolatedPhpFunctionInvoke = false;

    /** Active serialize() var_hash for nested builtin calls during Serializable/__serialize (#18428). */
    public ?\PHPCompiler\ext\standard\VmSerializeRefState $activeSerializeRefState = null;

    /** Object whose legacy Serializable::serialize() is running — guards nested serialize($this) (#18428). */
    public ?ObjectEntry $legacySerializableBeingInvoked = null;

    /** Object whose __serialize() is running — root defers var_hash until nested self (#18428, #11903). */
    public ?ObjectEntry $magicSerializeBeingInvoked = null;

    /**
     * When true, bubble uncaught user throwables as native \Throwable to the embedding host (PHPUnit,
     * library API) instead of emitting a Zend-style fatal block and terminating the VM with ScriptExit.
     *
     * CLI entrypoints should leave this false and handle ScriptExit status codes.
     */
    public bool $bubbleUncaughtToNative = false;

    /**
     * Isolated closure invoke from stdlib callbacks — defer user catch to outer runFrames (#14104).
     */
    public bool $deferBuiltinCallbackCatchToOuterRunFrames = false;

    /**
     * When set, only activeTryHandlerFrames entries with index < this depth redirect to the outer
     * runFrames via {@see BuiltinCallbackCatchRedirect} (#25816).
     *
     * Nested eval() pushes a child frame without swapping the run stack; try handlers entered
     * inside the eval sit at index ≥ this depth and must run in the nested loop so trailing
     * eval opcodes still execute. Handlers already active before eval (outer try) must not —
     * otherwise catch/merge runs inside executeEvalBlock and TYPE_EVAL continues the try body.
     */
    public ?int $deferCatchBelowTryHandlerDepth = null;

    /** Catch frame for throw during nested __clone(); bubble to clone opcode caller (#12068). */
    public ?Frame $cloneMagicExternalCatchFrame = null;

    /**
     * Frame that executed the `clone` opcode while {@see $invokingCloneMagic} is true (#23527).
     * Used to tell outer try/catch handlers from try/catch inside __clone itself.
     */
    public ?Frame $cloneMagicCallerFrame = null;

    /** Active object-to-string coercion via __toString (issue #4284). */
    public bool $coercingObjectToString = false;

    /** count() on Countable — php-src zval_get_long, skip interface return check (#12867). */
    public int $suppressReturnTypeCheckDepth = 0;

    /** Ghost object currently running its lazy initializer (#6531, Zend/zend_lazy_objects.c). */
    public ?ObjectEntry $lazyGhostInitializing = null;

    /** Lazy ghost/proxy running ensureInitialized() — capture init Throwable (#6514). */
    public ?ObjectEntry $lazyInitializingObject = null;

    /**
     * SAPI argv snapshot for getopt() (php-src SG(request_info).argv; issue #3251).
     *
     * @var list<string>
     */
    public array $cliRequestArgv = [];

    /** User catch ran during coercion; caller must not use a coerced result (#4284). */
    public bool $magicMethodThrowHandled = false;

    /** Handler frame whose catch chain resumes after a throw-path finally (issue #2114). */
    public ?Frame $pendingCatchResumeHandler = null;

    /**
     * Throw-site frame when entering throw-path finally — used to release same-function CVs
     * (including try-body locals) after finally before outer catch (#22541).
     */
    public ?Frame $pendingFinallyUnwindThrowFrame = null;

    /** Try handler for the innermost catch body exiting to merge (issue #195). */
    public ?Frame $activeCatchHandlerFrame = null;

    /** Merge block to enter after catch-path finally completes (#195, Zend zend_exceptions.c). */
    public ?Block $pendingMergeAfterFinally = null;

    /** Label/merge target after goto exits a try with pending finally (#4491). */
    public ?Block $pendingGotoAfterFinally = null;

    /** @var array<int, true> handler frame object id => finally already ran for current unwind */
    public array $completedFinallyHandlers = [];

    /** Return from try/catch deferred until pending finally handlers run (#3082). */
    public bool $pendingReturnActive = false;

    public bool $pendingReturnIsVoid = true;

    public ?Variable $pendingReturnValue = null;

    public ?Frame $pendingReturnResumeFrame = null;

    /** Set when a TYPE_JUMP finishes the finally chain for a deferred return (#3082). */
    public bool $pendingReturnDispatch = false;

    public ErrorReporter $errors;

    public ExceptionHandlerStack $exceptionHandlers;

    public ScriptStack $scriptStack;

    /** Max execution time + ignore_user_abort (ext/standard VmExecutionLimits; #3242). */
    public \PHPCompiler\ext\standard\VmExecutionLimits $executionLimits;

    /** @var array<int, Variable> foreach iterator container cache (issue #167, #1885). */
    public array $foreachIterators = [];

    /** @var array<int, ObjectPropertyIterator> foreach object property walk (#3661). */
    public array $objectPropertyIterators = [];

    /** @var array<int, WeakMapIterator> foreach WeakMap entry walk (#4434). */
    public array $weakMapIterators = [];

    /**
     * Handler frames for active try regions (block with TYPE_TRY/CATCH), innermost last (#3521).
     *
     * @var list<Frame>
     */
    public array $activeTryHandlerFrames = [];

    /** @var array<int, true> merge block object id => pop one try handler on entry */
    public array $tryMergeBlockIds = [];

    /**
     * Object-foreach slots: when true, call next() before the next valid() check (#3234).
     *
     * @var array<int, bool>
     */
    public array $foreachObjectAdvance = [];

    /** @var array<int, true> foreach warned on non-traversable operand; loop body skipped (#4879). */
    public array $foreachInvalidSlots = [];

    /**
     * Trait `use` bindings deferred until a forward-referenced trait is declared (#9395).
     *
     * @var list<array{entry: ClassEntry, traitNames: list<string>, adaptations: list<array<string, mixed>>, ownMethods: array<string, true>}>
     */
    public array $deferredTraitUses = [];

    /**
     * Class constants deferred until a forward-referenced enum/class is declared (#9664).
     *
     * @var list<array{
     *     entry: ClassEntry,
     *     block: Block,
     *     frame: Frame,
     *     classBodyOps: list<OpCode>,
     *     segments: array<string, array{initIndices: list<int>, declareIndex: int}>
     * }>
     */
    public array $deferredClassConstants = [];

    /**
     * Parent inheritance deferred until a forward-referenced parent class is declared (#9721).
     *
     * @var list<array{childLc: string, parentName: string}>
     */
    public array $deferredParentInheritance = [];

    /** Fiber executing on this VM stack (issue #3130). */
    public ?FiberState $currentFiber = null;

    public function __construct(Runtime $runtime) {
        $this->runtime = $runtime;
        $this->errors = new ErrorReporter();
        $this->exceptionHandlers = new ExceptionHandlerStack();
        $this->scriptStack = new ScriptStack();
        $this->executionLimits = new \PHPCompiler\ext\standard\VmExecutionLimits();
        BuiltinClasses::register($this);
    }

    public function constantFetch(string $name): ?Variable {
        switch (strtolower($name)) {
            case 'null':
                return new Variable(Variable::TYPE_NULL);
            case 'false':
                $var = new Variable(Variable::TYPE_BOOLEAN);
                $var->bool(false);
                return $var;
            case 'true':
                $var = new Variable(Variable::TYPE_BOOLEAN);
                $var->bool(true);
                return $var;
        }
        $engine = $this->constantFetchEngineConstant($name);
        if (null !== $engine) {
            return $engine;
        }
        if ($name !== strtoupper($name)) {
            $engineAlias = $this->constantFetchEngineConstant(strtoupper($name));
            if (null !== $engineAlias) {
                return $engineAlias;
            }
        }
        // Module/user defineConstant values win over host-backed core fetch so PROFILE-gated
        // constants (e.g. IMAGETYPE_COUNT 21 vs host 20) are not shadowed (#22787).
        if (isset($this->constants[$name])) {
            return $this->constants[$name];
        }
        $phpCore = \PHPCompiler\ext\standard\VmPhpCoreConstants::fetch($name);
        if (null !== $phpCore) {
            return $phpCore;
        }
        $errorInt = self::errorReportingConstant($name);
        if (null !== $errorInt) {
            $var = new Variable(Variable::TYPE_INTEGER);
            $var->int($errorInt);
            return $var;
        }

        return null;
    }

    /**
     * defined()/constant() lookup — internal constant names are case-sensitive (#10635, basic_functions.c).
     *
     * true/false/null stay case-insensitive; user constants use exact keys; engine/stdlib names must
     * match canonical UPPER_SNAKE spelling (PHP_VERSION yes, php_version no).
     */
    public function constantFetchBuiltin(string $name): ?Variable
    {
        switch (strtolower($name)) {
            case 'null':
                return new Variable(Variable::TYPE_NULL);
            case 'false':
                $var = new Variable(Variable::TYPE_BOOLEAN);
                $var->bool(false);

                return $var;
            case 'true':
                $var = new Variable(Variable::TYPE_BOOLEAN);
                $var->bool(true);

                return $var;
        }
        if (isset($this->constants[$name])) {
            return $this->constants[$name];
        }
        if ($name !== strtoupper($name)) {
            return null;
        }

        return $this->constantFetchEngineConstant($name);
    }

    public function constantDefinedBuiltin(string $name): bool
    {
        return null !== $this->constantFetchBuiltin($name);
    }

    /**
     * Engine/stdlib constants for constantFetch() and constantFetchBuiltin() after case rules apply.
     */
    private function constantFetchEngineConstant(string $name): ?Variable
    {
        switch (strtolower($name)) {
            case 'inf':
                $var = new Variable(Variable::TYPE_FLOAT);
                $var->float(INF);

                return $var;
            case 'nan':
                $var = new Variable(Variable::TYPE_FLOAT);
                $var->float(NAN);

                return $var;
            case 'password_bcrypt':
                $var = new Variable(Variable::TYPE_STRING);
                $var->string(\PHPCompiler\ext\standard\StdlibConstants::PASSWORD_BCRYPT);

                return $var;
            case 'password_default':
                $var = new Variable(Variable::TYPE_STRING);
                $var->string(\PHPCompiler\ext\standard\StdlibConstants::PASSWORD_DEFAULT);

                return $var;
            case 'password_argon2i':
                if (!\PHPCompiler\ext\standard\VmPasswordNative::argon2Available()) {
                    return null;
                }
                // User-visible algo id is the string name (php-src password.c / #11615);
                // VmPassword keeps int 2/3 only as internal hash lowering ids (#25818).
                $var = new Variable(Variable::TYPE_STRING);
                $var->string(\PHPCompiler\ext\standard\StdlibConstants::PASSWORD_ARGON2I);

                return $var;
            case 'password_argon2id':
                if (!\PHPCompiler\ext\standard\VmPasswordNative::argon2Available()) {
                    return null;
                }
                $var = new Variable(Variable::TYPE_STRING);
                $var->string(\PHPCompiler\ext\standard\StdlibConstants::PASSWORD_ARGON2ID);

                return $var;
            case 'crypt_std_des':
                $var = new Variable(Variable::TYPE_INTEGER);
                $var->int(\PHPCompiler\ext\standard\VmPassword::CRYPT_STD_DES);

                return $var;
            case 'crypt_ext_des':
                $var = new Variable(Variable::TYPE_INTEGER);
                $var->int(\PHPCompiler\ext\standard\VmPassword::CRYPT_EXT_DES);

                return $var;
            case 'crypt_md5':
                $var = new Variable(Variable::TYPE_INTEGER);
                $var->int(\PHPCompiler\ext\standard\VmPassword::CRYPT_MD5);

                return $var;
            case 'crypt_blowfish':
                $var = new Variable(Variable::TYPE_INTEGER);
                $var->int(\PHPCompiler\ext\standard\VmPassword::CRYPT_BLOWFISH);

                return $var;
            case 'crypt_sha256':
                $var = new Variable(Variable::TYPE_INTEGER);
                $var->int(\PHPCompiler\ext\standard\VmPassword::CRYPT_SHA256);

                return $var;
            case 'crypt_sha512':
                $var = new Variable(Variable::TYPE_INTEGER);
                $var->int(\PHPCompiler\ext\standard\VmPassword::CRYPT_SHA512);

                return $var;
        }
        $filterVar = \PHPCompiler\ext\filter\FilterConstants::variableForName($name);
        if (null !== $filterVar) {
            return $filterVar;
        }
        $stdlibInt = \PHPCompiler\ext\standard\StdlibConstants::coreIntByName(strtolower($name));
        if (null !== $stdlibInt) {
            $var = new Variable(Variable::TYPE_INTEGER);
            $var->int($stdlibInt);

            return $var;
        }
        $stdlibFloat = \PHPCompiler\ext\standard\StdlibConstants::CORE_FLOAT_BY_NAME[strtolower($name)] ?? null;
        if (null !== $stdlibFloat) {
            $var = new Variable(Variable::TYPE_FLOAT);
            $var->float($stdlibFloat);

            return $var;
        }
        $dateStr = \PHPCompiler\ext\standard\DateConstants::CORE_STRING_BY_NAME[strtolower($name)] ?? null;
        if (null !== $dateStr) {
            $var = new Variable(Variable::TYPE_STRING);
            $var->string($dateStr);

            return $var;
        }
        $phpCore = \PHPCompiler\ext\standard\VmPhpCoreConstants::fetchExact($name);
        if (null !== $phpCore) {
            return $phpCore;
        }
        $errorInt = self::errorReportingConstantExact($name);
        if (null !== $errorInt) {
            $var = new Variable(Variable::TYPE_INTEGER);
            $var->int($errorInt);

            return $var;
        }

        return null;
    }

    /** @var list<string> */
    private const ERROR_REPORTING_CONSTANT_NAMES = [
        'e_error',
        'e_warning',
        'e_parse',
        'e_notice',
        'e_core_error',
        'e_core_warning',
        'e_compile_error',
        'e_compile_warning',
        'e_user_error',
        'e_user_warning',
        'e_user_notice',
        'e_strict',
        'e_recoverable_error',
        'e_deprecated',
        'e_user_deprecated',
        'e_all',
    ];

    /**
     * @return list<string> lowercase names registered for constantFetch()
     */
    public static function errorReportingConstantFetchNames(): array
    {
        return self::ERROR_REPORTING_CONSTANT_NAMES;
    }

    public static function errorReportingConstant(string $name): ?int
    {
        return match (strtolower($name)) {
            'e_error' => 1,
            'e_warning' => ErrorReporter::E_WARNING,
            'e_parse' => 4,
            'e_notice' => 8,
            'e_core_error' => 16,
            'e_core_warning' => 32,
            'e_compile_error' => 64,
            'e_compile_warning' => 128,
            'e_user_error' => ErrorReporter::E_USER_ERROR,
            'e_user_warning' => ErrorReporter::E_USER_WARNING,
            'e_user_notice' => ErrorReporter::E_USER_NOTICE,
            'e_strict' => ErrorReporter::E_STRICT,
            'e_recoverable_error' => 4096,
            'e_deprecated' => ErrorReporter::E_DEPRECATED,
            'e_user_deprecated' => ErrorReporter::E_USER_DEPRECATED,
            'e_all' => ErrorReporter::eAll(),
            default => null,
        };
    }

    public static function errorReportingConstantExact(string $name): ?int
    {
        return match ($name) {
            'E_ERROR' => 1,
            'E_WARNING' => ErrorReporter::E_WARNING,
            'E_PARSE' => 4,
            'E_NOTICE' => 8,
            'E_CORE_ERROR' => 16,
            'E_CORE_WARNING' => 32,
            'E_COMPILE_ERROR' => 64,
            'E_COMPILE_WARNING' => 128,
            'E_USER_ERROR' => ErrorReporter::E_USER_ERROR,
            'E_USER_WARNING' => ErrorReporter::E_USER_WARNING,
            'E_USER_NOTICE' => ErrorReporter::E_USER_NOTICE,
            'E_STRICT' => ErrorReporter::E_STRICT,
            'E_RECOVERABLE_ERROR' => 4096,
            'E_DEPRECATED' => ErrorReporter::E_DEPRECATED,
            'E_USER_DEPRECATED' => ErrorReporter::E_USER_DEPRECATED,
            'E_ALL' => ErrorReporter::eAll(),
            default => null,
        };
    }

    public function isUserConstantDefined(string $name): bool
    {
        return isset($this->constants[$name]);
    }

    /**
     * Register a user constant (const / define). Returns false if already defined.
     *
     * PHP 8+ ignores case-insensitive constants; $caseInsensitive is accepted for call-site BC only.
     * Optional $filename feeds ReflectionConstant::getFileName() (php-src zend_constant.filename, #21551).
     */
    public function defineConstant(string $name, Variable $value, bool $caseInsensitive = false, ?string $filename = null): bool
    {
        if (isset($this->constants[$name])) {
            return false;
        }
        foreach ($this->constants as $existingName => $_) {
            if (0 === strcasecmp($existingName, $name)) {
                return false;
            }
        }
        $this->constants[$name] = EnumCaseSupport::materializeConstantValue($this, $value);
        $file = (null !== $filename && '' !== $filename) ? $filename : 'Command line code';
        // php-src CLI uses "Command line code" for -r / stdin; compliance harness uses "-" (#21551).
        if ('-' === $file) {
            $file = 'Command line code';
        }
        $this->globalConstantFilenames[$name] = $file;

        return true;
    }

    /** True when a live user constant still holds the given object id (#17676). */
    public function userConstantReferencesObjectId(int $objectId): bool
    {
        foreach ($this->constants as $constVar) {
            $resolved = $constVar->resolveIndirect();
            if (Variable::TYPE_OBJECT !== $resolved->type) {
                continue;
            }
            try {
                if ($resolved->toObject()->id === $objectId) {
                    return true;
                }
            } catch (\LogicException) {
            }
        }

        return false;
    }

    public function declareFunction(Func $func): void {
        $lcname = strtolower($func->getName());
        $this->functions[$lcname] = $func;
    }

    /**
     * Resolve a function call target; namespaced unqualified calls fall back to global builtins (#10534).
     *
     * Honors function_exists visibility for exit/die on the 8.2 reference profile (#22796), but still
     * resolves compiler ABI helpers (phpc_, __compiler_, web_ prefixes) used by match/clone-with
     * lowering (#22820).
     */
    public function resolveFunctionCallLc(string $name): ?string
    {
        $lcname = strtolower($name);
        if (isset($this->functions[$lcname]) && self::isResolvableRegisteredFunction($lcname)) {
            return $lcname;
        }
        if (str_contains($name, '\\') && !str_contains($name, '::')) {
            $globalFn = substr($name, strrpos($name, '\\') + 1);
            $globalLc = strtolower($globalFn);
            if (isset($this->functions[$globalLc]) && self::isResolvableRegisteredFunction($globalLc)) {
                return $globalLc;
            }
        }

        return null;
    }

    /**
     * Registered builtins may be hidden from function_exists but still callable when the compiler
     * lowers to them (#22820). exit/die stay unresolvable on the 8.2 reference profile (#22796).
     */
    private static function isResolvableRegisteredFunction(string $lcname): bool
    {
        if (\PHPCompiler\ext\standard\VmReflection::isCompilerAbiHelperName($lcname)) {
            return true;
        }

        return \PHPCompiler\ext\standard\VmReflection::isVisibleToFunctionExists($lcname);
    }

    /**
     * Register an alternate name for a class (ext/standard class_alias, #3095).
     *
     * php-src: zend_register_class_alias_ex — internal originals allowed on PHP 8.3+ (#29084);
     * PROFILE≤8.2 throws ValueError (#29150). Alias-of-alias resolves to canonical (#11639).
     * Duplicate alias names warn + false (#29084 / re-#18290).
     */
    public function registerClassAlias(string $original, string $alias, bool $autoload = true, ?\PHPCompiler\Frame $frame = null): bool
    {
        $aliasLc = strtolower($alias);
        $originalLc = strtolower($original);

        if (!isset($this->classes[$originalLc])) {
            if (!$autoload || !$this->autoloadClass($original)) {
                $this->errors->triggerError(
                    \sprintf('Class "%s" not found', $original),
                    ErrorReporter::E_WARNING,
                    null,
                    $this,
                    $frame
                );

                return false;
            }
        }
        if (!isset($this->classes[$originalLc])) {
            $this->errors->triggerError(
                \sprintf('Class "%s" not found', $original),
                ErrorReporter::E_WARNING,
                null,
                $this,
                $frame
            );

            return false;
        }

        $canonicalOriginalLc = $originalLc;
        while (isset($this->classAliases[$canonicalOriginalLc])) {
            $canonicalOriginalLc = $this->classAliases[$canonicalOriginalLc];
        }

        $entry = $this->classes[$canonicalOriginalLc];
        if ($entry->isInternal && !\PHPCompiler\CompilerVersion::allowsClassAliasOfInternalClass()) {
            throw new \ValueError(
                'class_alias(): Argument #1 ($class) must be a user-defined class name, internal class name given'
            );
        }

        if (isset($this->classes[$aliasLc]) || isset($this->classAliases[$aliasLc]) || isset($this->enums[$aliasLc])) {
            $this->errors->triggerError(
                \sprintf('Cannot declare class %s, because the name is already in use', $alias),
                ErrorReporter::E_WARNING,
                null,
                $this,
                $frame
            );

            return false;
        }

        $this->classes[$aliasLc] = $entry;
        $this->classAliases[$aliasLc] = $canonicalOriginalLc;
        if ($entry->isEnum) {
            $this->enums[$aliasLc] = true;
        }

        return true;
    }

    public function isCompileUnitLoaded(string $path): bool
    {
        $normalized = ScriptStack::normalize($path);

        return '' !== $normalized && isset($this->loadedCompileUnits[$normalized]);
    }

    public function markCompileUnitLoaded(string $path): void
    {
        $normalized = ScriptStack::normalize($path);
        if ('' !== $normalized) {
            $this->loadedCompileUnits[$normalized] = true;
        }
    }

    /**
     * Record a successfully loaded compile unit for get_included_files() (#3315).
     *
     * php-src: zend_execute_scripts / zend_execute — included_files list
     */
    public function recordIncludedFile(string $path): void
    {
        $normalized = ScriptStack::normalize($path);
        if ('' !== $normalized && !ScriptStack::isVirtualCompileUnit($normalized)) {
            $this->includedFiles[] = $normalized;
        }
    }

    /**
     * @return list<string> absolute paths in load order
     */
    public function includedFiles(): array
    {
        return $this->includedFiles;
    }

  /** Try spl_autoload_register() callbacks, then PSR-4 project autoloaders (#155, #1369). */
    public function autoloadClass(string $className): bool
    {
        if (\PHPCompiler\ext\standard\VmSplAutoload::runStack($this, $className)) {
            return true;
        }
        foreach ($this->classAutoloaders as $loader) {
            if ($loader($className)) {
                return true;
            }
        }

        return false;
    }

    public function ensureSuperglobal(string $name): Variable
    {
        if ('GLOBALS' === $name) {
            return $this->ensureGlobalsTable();
        }
        if (!Superglobals::isSuperglobalName($name)) {
            throw new \InvalidArgumentException("Unknown superglobal: {$name}");
        }
        if (!isset($this->superglobalVars[$name])) {
            $var = new Variable(Variable::TYPE_ARRAY);
            $var->array(new HashTable());
            $this->superglobalVars[$name] = $var;
        }

        return $this->superglobalVars[$name];
    }

    public function getSuperglobal(string $name): ?Variable
    {
        return $this->superglobalVars[$name] ?? null;
    }

    /**
     * Capture a deep copy of a request-input table for filter_input() (#19640).
     */
    public function captureFilterInputSnapshot(string $sgName, HashTable $table): void
    {
        $this->filterInputSnapshots[$sgName] = $table->duplicate();
    }

    public function getFilterInputSnapshot(string $sgName): ?HashTable
    {
        return $this->filterInputSnapshots[$sgName] ?? null;
    }

    public function ensureGlobal(string $name): Variable
    {
        if (!isset($this->globalVars[$name])) {
            $this->globalVars[$name] = new Variable(Variable::TYPE_NULL);
        }
        $this->ensureGlobalsTable();
        $this->syncGlobalEntryInGlobalsTable($name, $this->globalVars[$name]);

        return $this->globalVars[$name];
    }

    /** @param callable(Variable): void $visitVar */
    public function visitGlobalVariables(callable $visitVar): void
    {
        foreach ($this->globalVars as $global) {
            $visitVar($global);
        }
    }

    /** True when $var is the canonical storage cell for a script global (#5089). */
    public function isGlobalStorage(Variable $var): bool
    {
        foreach ($this->globalVars as $global) {
            if ($global === $var) {
                return true;
            }
        }

        return false;
    }

    public function markGlobalEverAssigned(string $name): void
    {
        $this->globalEverAssigned[$name] = true;
    }

    public function isGlobalEverAssigned(string $name): bool
    {
        return isset($this->globalEverAssigned[$name]);
    }

    public function clearGlobalEverAssigned(string $name): void
    {
        unset($this->globalEverAssigned[$name]);
    }

    public function globalNameForStorage(Variable $var): ?string
    {
        $resolved = $var->resolveIndirect();
        foreach ($this->globalVars as $name => $global) {
            if ($global === $var || $global === $resolved) {
                return $name;
            }
        }

        return null;
    }

    public function functionStaticKeyForStorage(Variable $var): ?string
    {
        $resolved = $var->resolveIndirect();
        foreach ($this->functionStaticVars as $key => $storage) {
            if ($storage === $var || $storage === $resolved) {
                return $key;
            }
        }

        return null;
    }

    /** Reset a script-global symbol (unset($name) on {main}, #5089). */
    public function clearGlobalByName(string $name): void
    {
        if (!isset($this->globalVars[$name])) {
            return;
        }
        $global = $this->globalVars[$name];
        // Variable::reset() clears WeakMap/WeakReference only when this was the last
        // strong ref (refCount <= 1). Unconditional clearForObject here dropped map
        // entries while foreach $k (or any other live zval) still held the key (#24784).
        $global->reset();
        $global->type = Variable::TYPE_UNDEFINED;
        $this->clearGlobalEverAssigned($name);
        $this->syncGlobalEntryInGlobalsTable($name, $global);
    }

    /**
     * unset($GLOBALS['name']) — drop symbol table entry and $GLOBALS offset (#5868, zend_hash.c).
     */
    public function unsetGlobalsTableKey(string $name): void
    {
        if (isset($this->globalVars[$name])) {
            $global = $this->globalVars[$name];
            // Same last-strong-ref rule as clearGlobalByName / Variable::reset (#24784).
            $global->reset();
            unset($this->globalVars[$name]);
        }
        if (null === $this->globalsSuperglobal) {
            return;
        }
        $table = $this->globalsSuperglobal->toArray();
        $key = new Variable(Variable::TYPE_STRING);
        $key->string($name);
        $table->offsetUnset($key);
        $slot = $table->find($name);
        if (null !== $slot) {
            $slot->type = Variable::TYPE_UNDEFINED;
        }
    }

    public function ensureGlobalsTable(): Variable
    {
        if (null === $this->globalsSuperglobal) {
            $this->globalsSuperglobal = new Variable(Variable::TYPE_ARRAY);
            $this->globalsSuperglobal->array(new HashTable());
            foreach ($this->globalVars as $name => $global) {
                $this->syncGlobalEntryInGlobalsTable($name, $global);
            }
        }

        return $this->globalsSuperglobal;
    }

    public function isGlobalsTable(Variable $container): bool
    {
        if (null === $this->globalsSuperglobal) {
            return false;
        }

        return $this->globalsSuperglobal === $container->resolveIndirect();
    }

    /**
     * $GLOBALS['name'] read/write shares storage with `global $name` (Zend symbol table).
     */
    public function globalsTableOffsetFetch(Variable $index, bool $forWrite): Variable
    {
        if (Variable::TYPE_STRING !== $index->type) {
            return $this->ensureGlobalsTable()->toArray()->findVariable($index, $forWrite, $this, null);
        }
        $name = $index->toString();
        $global = $this->ensureGlobal($name);
        $table = $this->ensureGlobalsTable()->toArray();
        $slot = $table->find($name);
        if (null === $slot) {
            $ref = new Variable(Variable::TYPE_NULL);
            $ref->indirect($global);
            $table->add($name, $ref);

            return $ref;
        }
        if (Variable::TYPE_INDIRECT !== $slot->type) {
            $ref = new Variable(Variable::TYPE_NULL);
            $ref->indirect($global);
            $table->updateIndirect($name, $ref);

            return $ref;
        }

        return $slot;
    }

    /**
     * isset($GLOBALS['name']) — symbol table probe (php-src zend_hash_global_lookup).
     */
    public function globalsTableOffsetIsSet(Variable $index): bool
    {
        if (Variable::TYPE_STRING !== $index->type) {
            return $this->ensureGlobalsTable()->toArray()->offsetIsSet($index);
        }
        $name = $index->toString();
        if (!isset($this->globalVars[$name])) {
            return false;
        }
        $global = $this->globalVars[$name]->resolveIndirect();

        return !$global->isUndefined() && Variable::TYPE_NULL !== $global->type;
    }

    /**
     * empty($GLOBALS['name']) — symbol probe then value truthiness (#14798).
     */
    public function globalsTableOffsetIsEmpty(Variable $index): bool
    {
        if (Variable::TYPE_STRING !== $index->type) {
            $table = $this->ensureGlobalsTable()->toArray();
            if (!$table->keyExists($index)) {
                return true;
            }
            $stored = $table->findVariable($index, false);

            return !\PHPCompiler\ext\standard\boolval::isTruthy($stored->resolveIndirect());
        }
        $name = $index->toString();
        if (!isset($this->globalVars[$name])) {
            return true;
        }
        $global = $this->globalVars[$name]->resolveIndirect();

        return !\PHPCompiler\ext\standard\boolval::isTruthy($global);
    }

    private function syncGlobalEntryInGlobalsTable(string $name, Variable $global): void
    {
        if (null === $this->globalsSuperglobal) {
            return;
        }
        $table = $this->globalsSuperglobal->toArray();
        $existing = $table->find($name);
        if (null === $existing) {
            $ref = new Variable(Variable::TYPE_NULL);
            $ref->indirect($global);
            $table->add($name, $ref);

            return;
        }
        if (Variable::TYPE_INDIRECT !== $existing->type) {
            $ref = new Variable(Variable::TYPE_NULL);
            $ref->indirect($global);
            $table->updateIndirect($name, $ref);
        }
    }

    public function ensureFunctionStatic(string $storageKey): Variable
    {
        if (!isset($this->functionStaticVars[$storageKey])) {
            $this->functionStaticVars[$storageKey] = new Variable(Variable::TYPE_NULL);
        }
        // Persist across calls: frame teardown must not releaseRef through CV aliases (#28039).
        $this->functionStaticVars[$storageKey]->functionStaticStorage = true;

        return $this->functionStaticVars[$storageKey];
    }

    public function peekFunctionStatic(string $storageKey): ?Variable
    {
        return $this->functionStaticVars[$storageKey] ?? null;
    }

    public function isFunctionStaticInitialized(string $storageKey): bool
    {
        return VmFunctionStatic::isInitialized($storageKey, $this->functionStaticInitialized);
    }

    public function markFunctionStaticInitialized(string $storageKey): void
    {
        VmFunctionStatic::markInitialized($storageKey, $this->functionStaticInitialized);
    }

    public function save(Frame $frame): RunStackEntry {
        $this->push($frame);
        $return = $this->runStack;
        $this->runStack = null;
        return $return;
    }

    public function restore(RunStackEntry $runStack): Frame {
        assert(is_null($this->runStack));
        $this->runStack = $runStack->prev;
        return $runStack->frame;
    }

    public function push(Frame $frame): void {
        $entry = new RunStackEntry($frame);
        $entry->prev = $this->runStack;
        $this->runStack = $entry;
    }

    public function pop(): ?Frame {
        $return = $this->runStack;
        if (!is_null($this->runStack)) {
            $this->runStack = $this->runStack->prev;
            return $return->frame;
        }
        return null;;
    }

    /** Drop suspended call-site frames when catch takes over from a nested throw (#5331). */
    public function clearRunStack(): void
    {
        $this->runStack = null;
    }

    /**
     * Drop suspended try-body call sites when catch takes over from a nested throw (#5331, #5896).
     *
     * The run stack holds suspended *callers* only (see TYPE_FUNC_CALL push). Remove entries for
     * $handler and callees invoked from its try body, but keep $handler's callers so catch/return
     * can resume (maintainer probe() parity scripts).
     */
    public function truncateRunStackForCatch(Frame $handler): void
    {
        while (null !== $this->runStack) {
            $suspended = $this->runStack->frame;
            if ($suspended === $handler || $this->frameIsDescendantOf($suspended, $handler)) {
                $this->runStack = $this->runStack->prev;
                continue;
            }
            break;
        }
    }

    private function frameIsDescendantOf(Frame $frame, Frame $ancestor): bool
    {
        for ($f = $frame; null !== $f; $f = $f->parent) {
            if ($f === $ancestor) {
                return true;
            }
        }

        return false;
    }

    /**
     * Active user frames, innermost first (matches debug_backtrace() order, #1378, #3626).
     *
     * @return list<Frame>
     */
    public function runStackFrames(): array
    {
        $frames = [];
        $stack = $this->runStack;
        while (null !== $stack) {
            $frames[] = $stack->frame;
            $stack = $stack->prev;
        }

        return $frames;
    }

  /** Swap the run stack (nested user-function calls from VM builtins). */
    public function swapRunStack(?RunStackEntry $stack): ?RunStackEntry
    {
        $prev = $this->runStack;
        $this->runStack = $stack;

        return $prev;
    }

    public function hasRunStack(): bool
    {
        return null !== $this->runStack;
    }

    /**
     * Visit variables that act as GC roots (globals, stack, statics, etc.).
     *
     * @param callable(Variable): void $visitVar
     */
    public function visitGcRoots(callable $visitVar): void
    {
        foreach ($this->constants as $constant) {
            $visitVar($constant);
        }
        foreach ($this->globalVars as $global) {
            $visitVar($global);
        }
        foreach ($this->superglobalVars as $superglobal) {
            $visitVar($superglobal);
        }
        foreach ($this->functionStaticVars as $static) {
            $visitVar($static);
        }
        foreach ($this->foreachIterators as $iterator) {
            $visitVar($iterator);
        }
        if (null !== $this->pendingException) {
            $visitVar($this->pendingException);
        }
        if (null !== $this->pendingReturnValue) {
            $visitVar($this->pendingReturnValue);
        }
        foreach ($this->classes as $class) {
            foreach ($class->staticProperties as $storage) {
                $visitVar($storage);
            }
            foreach ($class->constants as $constant) {
                $visitVar($constant);
            }
        }
        $stack = $this->runStack;
        while (null !== $stack) {
            CycleCollector::markFrameRoots($stack->frame, $visitVar);
            $stack = $stack->prev;
        }
        $this->exceptionHandlers->visitGcRoots($visitVar);
        $this->errors->visitGcRoots($visitVar);
    }
}

class RunStackEntry {
    public ?RunStackEntry $prev = null; 
    public Frame $frame;

    public function __construct(Frame $frame) {
        $this->frame = $frame;
    }
}
