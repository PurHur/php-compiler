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
    private ?RunStackEntry $runStack = null;
    public array $constants = [];

    /**
     * Case-insensitive user constants: lowercase name => canonical key in $constants (#3711).
     *
     * @var array<string, string>
     */
    private array $caseInsensitiveConstantNames = [];

    /** @var array<string, Variable> */
    private array $superglobalVars = [];

    /** @var array<string, Variable> */
    private array $globalVars = [];

    /** Lazily built $GLOBALS superglobal table (issue #3413). */
    private ?Variable $globalsSuperglobal = null;

    /** @var array<string, Variable> function-local static storage keyed by compile-time key (#2286) */
    private array $functionStaticVars = [];

    /** @var array<string, true> */
    private array $functionStaticInitialized = [];

    public Runtime $runtime;

    /** Pending thrown value while dispatching catch handlers (issue #1362). */
    public ?Variable $pendingException = null;

    /** Set when a property set hook throws (even if caught); suppresses outer assign (#3145). */
    public bool $propertyHookSetAborted = false;

    /** Handler frame whose catch chain resumes after a throw-path finally (issue #2114). */
    public ?Frame $pendingCatchResumeHandler = null;

    /** Try handler for the innermost catch body exiting to merge (issue #195). */
    public ?Frame $activeCatchHandlerFrame = null;

    /** Merge block to enter after catch-path finally completes (#195, Zend zend_exceptions.c). */
    public ?Block $pendingMergeAfterFinally = null;

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

    /** @var array<int, Variable> foreach iterator container cache (issue #167, #1885). */
    public array $foreachIterators = [];

    /** @var array<int, ObjectPropertyIterator> foreach object property walk (#3661). */
    public array $objectPropertyIterators = [];

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

    /** Fiber executing on this VM stack (issue #3130). */
    public ?FiberState $currentFiber = null;

    public function __construct(Runtime $runtime) {
        $this->runtime = $runtime;
        $this->errors = new ErrorReporter();
        $this->exceptionHandlers = new ExceptionHandlerStack();
        $this->scriptStack = new ScriptStack();
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
            case 'inf':
                $var = new Variable(Variable::TYPE_FLOAT);
                $var->float(INF);
                return $var;
            case 'nan':
                $var = new Variable(Variable::TYPE_FLOAT);
                $var->float(NAN);
                return $var;
            case 'password_bcrypt':
            case 'password_default':
                $var = new Variable(Variable::TYPE_INTEGER);
                $var->int(\PHPCompiler\ext\standard\StdlibConstants::PASSWORD_DEFAULT);
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
            case 'filter_validate_int':
                $var = new Variable(Variable::TYPE_INTEGER);
                $var->int(\PHPCompiler\ext\standard\VmFilter::FILTER_VALIDATE_INT);
                return $var;
            case 'filter_validate_email':
                $var = new Variable(Variable::TYPE_INTEGER);
                $var->int(\PHPCompiler\ext\standard\VmFilter::FILTER_VALIDATE_EMAIL);
                return $var;
            case 'filter_null_on_failure':
                $var = new Variable(Variable::TYPE_INTEGER);
                $var->int(\PHPCompiler\ext\standard\VmFilter::FILTER_NULL_ON_FAILURE);
                return $var;
            case 'input_get':
                $var = new Variable(Variable::TYPE_INTEGER);
                $var->int(\PHPCompiler\ext\standard\VmFilter::INPUT_GET);
                return $var;
            case 'input_post':
                $var = new Variable(Variable::TYPE_INTEGER);
                $var->int(\PHPCompiler\ext\standard\VmFilter::INPUT_POST);
                return $var;
        }
        $stdlibInt = \PHPCompiler\ext\standard\StdlibConstants::CORE_INT_BY_NAME[strtolower($name)] ?? null;
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
        if (isset($this->constants[$name])) {
            return $this->constants[$name];
        }
        $lc = strtolower($name);
        if (isset($this->caseInsensitiveConstantNames[$lc])) {
            return $this->constants[$this->caseInsensitiveConstantNames[$lc]];
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
            'e_strict' => 2048,
            'e_recoverable_error' => 4096,
            'e_deprecated' => ErrorReporter::E_DEPRECATED,
            'e_user_deprecated' => ErrorReporter::E_USER_DEPRECATED,
            'e_all' => E_ALL,
            default => null,
        };
    }

    public function isUserConstantDefined(string $name): bool
    {
        if (isset($this->constants[$name])) {
            return true;
        }

        return isset($this->caseInsensitiveConstantNames[strtolower($name)]);
    }

    /**
     * Register a user constant (const / define). Returns false if already defined.
     */
    public function defineConstant(string $name, Variable $value, bool $caseInsensitive = false): bool
    {
        if (isset($this->constants[$name])) {
            return false;
        }
        $lc = strtolower($name);
        if (isset($this->caseInsensitiveConstantNames[$lc])) {
            return false;
        }
        if (!$caseInsensitive) {
            foreach ($this->constants as $existingName => $_) {
                if (0 === strcasecmp($existingName, $name)) {
                    return false;
                }
            }
            $this->constants[$name] = clone $value;

            return true;
        }
        foreach ($this->constants as $existingName => $_) {
            if (0 === strcasecmp($existingName, $name)) {
                return false;
            }
        }
        $this->constants[$name] = clone $value;
        $this->caseInsensitiveConstantNames[$lc] = $name;

        return true;
    }

    public function declareFunction(Func $func): void {
        $lcname = strtolower($func->getName());
        $this->functions[$lcname] = $func;
    }

    /**
     * Register an alternate name for a user-defined class (ext/standard class_alias, #3095).
     *
     * php-src: zend_register_class_alias_ex — v1: user classes only, no alias chains.
     */
    public function registerClassAlias(string $original, string $alias, bool $autoload = true): bool
    {
        $aliasLc = strtolower($alias);
        $originalLc = strtolower($original);

        if (isset($this->classes[$aliasLc]) || isset($this->classAliases[$aliasLc]) || isset($this->enums[$aliasLc])) {
            return false;
        }

        if (!isset($this->classes[$originalLc])) {
            if (!$autoload || !$this->autoloadClass($original)) {
                return false;
            }
        }
        if (!isset($this->classes[$originalLc])) {
            return false;
        }

        if (isset($this->classAliases[$originalLc])) {
            return false;
        }

        $entry = $this->classes[$originalLc];
        if ($entry->isEnum || $entry->isInterface || $entry->isTrait) {
            return false;
        }

        $this->classes[$aliasLc] = $entry;
        $this->classAliases[$aliasLc] = $originalLc;

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

    public function ensureGlobal(string $name): Variable
    {
        if (!isset($this->globalVars[$name])) {
            $this->globalVars[$name] = new Variable(Variable::TYPE_NULL);
        }
        $this->syncGlobalEntryInGlobalsTable($name, $this->globalVars[$name]);

        return $this->globalVars[$name];
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
            return $this->ensureGlobalsTable()->toArray()->findVariable($index, $forWrite);
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

        return $this->functionStaticVars[$storageKey];
    }

    public function isFunctionStaticInitialized(string $storageKey): bool
    {
        return isset($this->functionStaticInitialized[$storageKey]);
    }

    public function markFunctionStaticInitialized(string $storageKey): void
    {
        $this->functionStaticInitialized[$storageKey] = true;
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
    }
}

class RunStackEntry {
    public ?RunStackEntry $prev = null; 
    public Frame $frame;

    public function __construct(Frame $frame) {
        $this->frame = $frame;
    }
}
