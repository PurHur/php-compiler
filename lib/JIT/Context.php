<?php

/*
 * This file is part of PHP-Compiler, a PHP CFG Compiler for PHP code
 *
 * @copyright 2015 Anthony Ferrara. All rights reserved
 * @license MIT See LICENSE at the root of the project for more info
 */

namespace PHPCompiler\JIT;

use PHPCfg\Operand;
use PHPCompiler\AOT\Linker;
use PHPCompiler\CompilerVersion;
use PHPCompiler\Runtime;
use PHPCompiler\Block;
use PHPCompiler\Module;
use PHPCompiler\OpCode;
use PHPCompiler\VM\Variable as VMVariable;
use PHPTypes\Type;

use PHPLLVM;
use PHPCompiler\Func\Internal as FuncInternal;
use PHPCompiler\JIT\SuperglobalInit;
use PHPCompiler\Web\Superglobals;

class Context {

    public PHPLLVM\LLVM $llvm;
    public PHPLLVM\Context $context;
    public PHPLLVM\Module $module;
    public PHPLLVM\BasicBlock $initBlock;
    /** Open tail for linear __init__ emission after the first CFG split (#8559). */
    public ?PHPLLVM\BasicBlock $initLinearBlock = null;
    /** Nesting depth for {@see emitInInit()} / class-const hashtable linear emission (#8559). */
    private int $initLinearEmissionDepth = 0;
    /** True after {@see ensureInitShutdownBlocks()} creates __init__/__shutdown__ skeletons (#9223). */
    private bool $initShutdownBlocksReady = false;
    public PHPLLVM\BasicBlock $shutdownBlock;
    public PHPLLVM\BasicBlock $headerPreFlushBlock;
    public PHPLLVM\Builder $builder;
    public PHPLLVM\Intrinsic $intrinsic;
    public PHPLLVM\TargetData $targetData;

    public ?PHPLLVM\Value\Function_ $main = null;
    public ?PHPLLVM\Value\Function_ $initFunc = null;
    public ?PHPLLVM\Value\Function_ $shutdownFunc = null;
    public ?PHPLLVM\Value\Function_ $headerPreFlushFunc = null;

    public array $constants = [];
    public array $functions = [];
    public array $functionProxies = [];

    /**
     * Lowercase logical callee => optional #[\NoDiscard] message (#5663).
     *
     * @var array<string, string|null>
     */
    public array $noDiscardCalleeMessages = [];

    /** @var array<int, Call> lazy initializer proxies keyed by __object__.lazy_init_index (#4940, #5318) */
    public array $lazyInitProxies = [];
    /** @var array<string, true> JIT stubs registered for external Class::method (issue #579). */
    public array $externalMethodStubs = [];
    public array $functionReturnType = [];
    public string $activeFunction = '';
    public array $functionScope = [];

    /** User function CFG block while compiling its body (func_get_args / func_num_args, #197). */
    public ?Block $jitEnclosingBlock = null;

    /** Operand for unserialize() options arg during FUNCCALL lowering (#3300). */
    public ?Operand $jitUnserializeOptionsOperand = null;

    /** Operand for call_user_func_array() $args during FUNCCALL lowering (#10359). */
    public ?Operand $jitCallUserFuncArrayParamsOperand = null;

    /**
     * Backing property name for raw writes inside a lowering set-hook method (#4025).
     *
     * Mirrors VM {@see \PHPCompiler\Frame::$propertyHookRawProperty} at compile time.
     */
    public ?string $jitPropertyHookRawProperty = null;

    /** While lowering generator resume LLVM (issue #3074). */
    public bool $compilingGeneratorResume = false;

    public ?\PHPLLVM\Value $generatorStateParam = null;

    /** While lowering fiber resume LLVM (issue #4019). */
    public bool $compilingFiberResume = false;

    public ?\PHPLLVM\Value $fiberStateParam = null;

    /** @var array<int, string> spl_object_id(__object__*) => fiber resume LLVM symbol */
    public array $fiberResumeByObjectValueId = [];

    /** Last fiber callback resume symbol in script scope (phase 1 #4019). */
    public ?string $scriptFiberResumeName = null;

    /** @var array<string, string> user func lc => resume LLVM symbol */
    public array $generatorCreators = [];

    /**
     * Catch-body CFG block id => LLVM entry for generator try/catch dispatch (#4069).
     *
     * @var array<int, \PHPLLVM\BasicBlock>
     */
    public array $generatorCatchDispatchEntry = [];

    /** @var array<int, \PHPLLVM\BasicBlock> catch CFG block id => resume entry BB (#4624) */
    public array $fiberCatchDispatchEntry = [];

    /** CFG block currently being lowered (get_defined_vars snapshot, #3135). */
    public ?Block $jitCurrentBlock = null;

    /** Most recent closure call proxy from TYPE_CLOSURE (register_shutdown_function, #3120). */
    public ?Call $lastClosureCallProxy = null;

    /** Call-site file strict_types while lowering FUNCCALL (issues #156, #1229). */
    public bool $callerStrictTypes = false;

    /** Call-site line for the pending FUNCCALL_EXEC (issue #4381). */
    public int $callSiteLine = 0;

    /** When true, pow() lowering returns a boxed {@see __value__*} (power operator **). */
    public bool $powReturnValueBox = false;

    /** Link-time source bytes for runtime_trivial_echo.php (M3 emit-helper #2559). */
    public ?string $m3EmitTuTrivialEchoSource = null;

    /** Absolute path to runtime_trivial_echo.php cached at emit-helper link (#2559). */
    public ?string $m3EmitTuTrivialEchoPath = null;

    /** Compiled Block for runtime_trivial_echo.php (host compile at link time). */
    public ?\PHPCompiler\Block $m3EmitTuTrivialEchoCompiledBlock = null;

    /** Host-linked AOT bytes for runtime_trivial_echo.php (#2559). */
    public ?string $m3EmitTuTrivialEchoAotBytes = null;

    public ?string $m3EmitTuTrivialEchoSidecarPath = null;

    public ?\PHPLLVM\Value $m3EmitTuTrivialEchoSourceGlobal = null;

    public ?\PHPLLVM\Value $m3EmitTuTrivialEchoSidecarPathGlobal = null;

    /** @var list<array{sourceGlobal: \PHPLLVM\Value, sidecarGlobal: \PHPLLVM\Value, sentinelLc: string}> */
    public array $m3EmitTuLinktimeSidecarEntries = [];
    private array $typeMap = [];
    public array $structFieldMap = [];
    private array $intConstant = [];
    private array $stringConstant = [];
    private array $builtins;
    private array $stringConstantMap = [];

    /** @var array<string, PHPLLVM\Value> */
    private array $arrayConstantMap = [];

    /** @var array<string, \PHPCompiler\VM\HashTable> */

    private array $modules = [];

    /** @var array<string, true>|null */
    private ?array $registeredBuiltinLookup = null;

    private ?Result $result = null;
    public Builtin\MemoryManager $memory;
    public Builtin\Output $output;
    public Builtin\Type $type;
    public Builtin\Internal $internal;
    public Builtin\VarArg $vararg;
    public Builtin\Refcount $refcount;
    public Builtin\ErrorHandler $error;
    public int $loadType;
    private static int $stringConstantCounter = 0;
    private ?string $debugFile = null;
    private ?string $aotSourceFilename = null;

    public Helper $helper;

    public Scope $scope;

    /** @var list<Scope> */
    public array $scopeStack = [];

    public TryCatchState $tryCatch;

    /** ?? / ?-> result operands that must receive branch assigns even when php-cfg marks them dead (#99, #3219). */
    public \SplObjectStorage $coalesceAssignTargets;

    /** `return $c ? $a : $b` shared merge operand — emit direct returns per arm (#8555 AOT). */
    public ?Operand $ternarySharedReturnOperand = null;

    /** Scope slot for {@see $ternarySharedReturnOperand} on the merge RETURN (#8555). */
    public ?int $ternarySharedReturnSlot = null;

    /** Guarded list destruct: assign-path dim fetches compile as unreachable stubs (#4308). */
    public bool $listUnpackSkipAssignPath = false;

    /**
     * LLVM merge body blocks for guarded list destruct — not in {@see Scope::$blockStorage}
     * until TYPE_JUMP compiles the CFG merge (#4531).
     *
     * @var \SplObjectStorage<Block, \PHPLLVM\BasicBlock>
     */
    public \SplObjectStorage $listUnpackMergeLlvmBlocks;

    /**
     * List destruct targets to null-init at guarded-merge CFG block entry (#4531).
     *
     * @var array<int, list<Operand>>
     */
    public array $listUnpackMergeNullInitTargets = [];

    /** CFG block that assigned locals before a list-unpack merge include (#846). */
    public ?Block $listUnpackAssignCallerBlock = null;

    /** CFG block that began a guarded list-unpack assign region (#846). */
    public ?Block $listUnpackAssignRootBlock = null;

    /**
     * Stable lvalue slots written during guarded list-unpack assign (#846).
     *
     * @var array<string, Variable>
     */
    public array $listUnpackAssignSlots = [];

    /** Nested compile-time include inlining depth (issue #568). */
    public int $inlineIncludeDepth = 0;

    /**
     * Caller blocks for nested literal includes (layout → partial); used to resolve
     * inherited locals from the outer TU (#764, #784).
     *
     * @var list<Block>
     */
    public array $inlineIncludeCallerBlocks = [];

    /** Require/include expression result slots while inlining (issue #783). */
    public array $inlineIncludeReturnOperands = [];

    /** Last LLVM exit block from an inlined TU (if/elseif before nested include, #764). */
    public ?\PHPLLVM\BasicBlock $inlineIncludeExitBlock = null;

    /**
     * Stack of include callee bindings to re-store after ?? on superglobals (#866).
     *
     * Each frame entry: [Operand $calleeOp, Variable $prepared, Variable $calleeVar, ?string $compileTime].
     *
     * @var list<list<array{Operand, Variable, Variable}>>
     */
    public array $inlineIncludeBindingRefreshStack = [];

    private array $exports = [];
    public Runtime $runtime;

    public int $mode;
    public Analyzer $analyzer;

    public array $attributes;

    /** @var array<int, PHPLLVM\Value> foreach index alloca slots keyed by array Variable id */
    public array $foreachIndexSlots = [];

    /** @var array<int, PHPLLVM\Value> foreach object-key walk slots keyed by array Variable id */
    public array $foreachObjNodeSlots = [];

    /** @var array<int, PHPLLVM\Value> Iterator protocol receiver (__object__*) per foreach container (#4011) */
    public array $foreachIteratorReceiverSlots = [];

    /** @var array<int, PHPLLVM\Value> Iterator protocol advance flag (int1) per foreach container (#4011) */
    public array $foreachIteratorAdvanceSlots = [];

    /** @var array<string, Variable> */
    public array $jitGlobalVariables = [];

    /** @var array<string, Variable> function-local static storage (#2286) */
    public array $jitFunctionStaticVariables = [];

    /** @var array<string, true> logical function names that return by reference (#3778) */
    public array $functionReturnsRef = [];

    /** @var array<string, string> */
    public array $refAliasNames = [];

    /** Rebound foreach by-ref value variables keyed by source name (#1222). */
    /** @var array<string, Variable> */
    public array $namedVariableBindings = [];

    /** {main} foreach loop locals must not use script-global slots (#4364, #1492). */
    /** @var array<string, true> */
    public array $foreachByRefLocalNames = [];

    /** CFG entry block for the function currently being lowered (foreach local scan). */
    public ?Block $jitFunctionRootBlock = null;

    /**
     * Undeclared instance property writes collected before JIT lowering (#5111).
     *
     * @var array<string, list<string>>
     */
    public array $jitUndeclaredInstancePropertyWrites = [];

    /** @var list<string> compile-time included paths for get_included_files() (#3315) */
    public array $jitIncludedFiles = [];

    /** @var list<string> require_once dedupe while lowering literal includes (#8559) */
    public array $jitAotIncludedCompileDone = [];

    /** Normalized realpath of the outer {main} TU being JIT-compiled (#8559). */
    public string $jitAotEntryScriptPath = '';

    /** Clear per-script local name/ref bindings before lowering a new {main} TU (#4763). */
    public function resetScriptLocalBindings(): void
    {
        $this->namedVariableBindings = [];
        $this->foreachByRefLocalNames = [];
        $this->refAliasNames = [];
        $this->jitUndeclaredInstancePropertyWrites = [];
        $this->jitIncludedFiles = [];
        $this->jitAotIncludedCompileDone = [];
    }

    public function recordJitIncludedFile(string $path): void
    {
        $normalized = \PHPCompiler\VM\ScriptStack::normalize($path);
        if ('' !== $normalized) {
            $this->jitIncludedFiles[] = $normalized;
        }
    }

    /** Outer {main} TU path for bootstrap/AOT entry classification (#11005, #11642). */
    private function resolveJitAotEntryScriptPath(): string
    {
        if ('' !== $this->jitAotEntryScriptPath) {
            return str_replace('\\', '/', $this->jitAotEntryScriptPath);
        }
        $fromAot = $this->aotSourceFilename;
        if (is_string($fromAot) && '' !== $fromAot) {
            return str_replace('\\', '/', $fromAot);
        }

        return '';
    }

    public function isCompilerLibSpineSmokeEntry(): bool
    {
        $entry = $this->resolveJitAotEntryScriptPath();

        return str_ends_with($entry, '/test/selfhost/compiler_lib_spine_smoke/main.php');
    }

    /**
     * M3/bootstrap selfhost entries under test/selfhost/ (not spine smoke) segfault when the VM
     * env-probe LLVM gate is emitted (#11005). User AOT scripts (examples/, app code) must keep the gate.
     */
    public function isBootstrapNonSpineSelfhostEntry(): bool
    {
        $entry = $this->resolveJitAotEntryScriptPath();
        if ('' === $entry) {
            return false;
        }

        return str_contains($entry, '/test/selfhost/') && !$this->isCompilerLibSpineSmokeEntry();
    }

    /**
     * VM env-probe LLVM gate is only for compiler_lib_spine_smoke (#8719, #8693). Bootstrap
     * test/selfhost/* entries (compiler_minimal, helloworld, …) call PHP main() directly — emitting
     * the gate for them segfaults at c:main_before_php (#10938, #11005). M3 compile-driver rebuild also skips.
     */
    private function shouldSkipStandaloneMainEnvProbeGate(): bool
    {
        if ($this->isUserScriptAot()) {
            return true;
        }
        if ($this->isBootstrapNonSpineSelfhostEntry()) {
            return true;
        }
        $flag = getenv('PHP_COMPILER_M3_COMPILE_DRIVER_MAIN');

        return '1' === $flag || 'true' === strtolower((string) $flag);
    }

    public function hasJitIncludedFileCompiled(string $path): bool
    {
        $resolved = realpath($path);
        if (false === $resolved) {
            $resolved = $path;
        }
        $normalized = \PHPCompiler\VM\ScriptStack::normalize($resolved);
        if ('' === $normalized) {
            return false;
        }
        $key = $this->jitIncludeCompileScopeKey($normalized);

        return isset($this->jitAotIncludedCompileDone[$key]);
    }

    public function markJitIncludedFileCompiled(string $path): void
    {
        $resolved = realpath($path);
        if (false === $resolved) {
            $resolved = $path;
        }
        $normalized = \PHPCompiler\VM\ScriptStack::normalize($resolved);
        if ('' !== $normalized) {
            $this->jitAotIncludedCompileDone[$this->jitIncludeCompileScopeKey($normalized)] = true;
        }
    }

    /** Per-LLVM-function include dedupe (#878): same path in different methods must re-inline. */
    private function jitIncludeCompileScopeKey(string $normalizedPath): string
    {
        return $this->activeFunction."\0".$normalizedPath;
    }

    /**
     * LLVM module-global __value__ slot for a script-level variable (#3601, #5393).
     *
     * Shared by {main} locals and `global $name` imports in nested functions.
     */
    public function ensureScriptGlobal(string $name): Variable
    {
        $storageKey = $name;
        if ($this->inlineIncludeDepth > 0 && '' !== $this->activeFunction) {
            $storageKey = $this->activeFunction."\0".$name;
        }
        if (!isset($this->jitGlobalVariables[$storageKey])) {
            if ('argv' === $name && null !== CliArgvGlobalInit::$global) {
                $this->jitGlobalVariables[$storageKey] = CliArgvGlobalInit::load($this);
            } elseif ('argc' === $name && null !== CliArgvGlobalInit::$argcGlobal) {
                $this->jitGlobalVariables[$storageKey] = CliArgvGlobalInit::loadArgc($this);
            } else {
                $globalName = 'phpc_script_global_'.substr(hash('sha256', 'script:'.$storageKey), 0, 16);
                $ptrTy = $this->getTypeFromString('__value__*');
                $global = $this->module->addGlobal($ptrTy, $globalName);
                $global->setInitializer($ptrTy->constNull());
                $scriptVar = new Variable(
                    $this,
                    Variable::TYPE_VALUE,
                    Variable::KIND_VALUE,
                    $global
                );
                $scriptVar->functionStaticGlobal = true;
                $this->jitGlobalVariables[$storageKey] = $scriptVar;
                $this->initScriptGlobalHeapBox($global);
            }
        }

        return $this->jitGlobalVariables[$storageKey];
    }

    private function initScriptGlobalHeapBox(PHPLLVM\Value $global): void
    {
        $restore = $this->builder->getInsertBlock();
        $this->positionBuilderAtInitEmission();
        $valueType = $this->getTypeFromString('__value__');
        $heapVal = $this->memory->malloc($valueType);
        $heapPtr = $this->builder->pointerCast(
            $heapVal,
            $this->getTypeFromString('__value__*')
        );
        $this->builder->call(
            $this->lookupFunction('__value__writeNull'),
            $heapPtr
        );
        $this->builder->store($heapPtr, $global);
        if (null !== $restore) {
            BasicBlockHelper::restoreInsertBlock($this, $restore);
        }
    }

    public function bindVariableByName(string $name, Variable $var): void
    {
        $resolved = $this->resolveRefAliasName($name);
        if (isset($this->namedVariableBindings[$resolved])) {
            $existing = $this->namedVariableBindings[$resolved];
            // Closure use() snapshot reads must not rebind enclosing locals to MCJIT rvalues (#72).
            if (
                Variable::KIND_VARIABLE === $existing->kind
                && Variable::KIND_VALUE === $var->kind
                && null === $var->valueBoxAliasPtr
            ) {
                return;
            }
        }
        $this->namedVariableBindings[$resolved] = $var;
        foreach ($this->scope->variables as $scopeOp) {
            if (!$scopeOp instanceof Operand) {
                continue;
            }
            if ($resolved === OperandName::resolve($scopeOp)) {
                $this->scope->variables[$scopeOp] = $var;
            }
        }
    }

    public function __construct(Runtime $runtime, int $loadType) {
        $this->runtime = $runtime;
        $this->scope = new Scope;
        $this->tryCatch = TryCatchState::create();
        $this->coalesceAssignTargets = new \SplObjectStorage();
        $this->listUnpackMergeLlvmBlocks = new \SplObjectStorage();
        $this->loadType = $loadType;
        $this->llvm = PHPLLVM\Chooser::choose();
        $this->llvm->initializeNative();
        $this->context = $this->llvm->contextCreate();
        $this->module = $this->context->moduleCreateWithName('main');
        $this->targetData = $this->module->getModuleDataLayout();
        $this->builder = $this->context->builderCreate();
        $this->intrinsic = $this->module->intrinsic($this->builder);

        $this->attributes = [
            'alwaysinline' => $this->context->createEnumAttribute($this->context->getEnumAttributeKindForName('alwaysinline'), 0),
            'nocapture' => $this->context->createEnumAttribute($this->context->getEnumAttributeKindForName('nocapture'), 0),
            'readnone' => $this->context->createEnumAttribute($this->context->getEnumAttributeKindForName('readnone'), 0),
            'readonly' => $this->context->createEnumAttribute($this->context->getEnumAttributeKindForName('readonly'), 0),
            'writeonly' => $this->context->createEnumAttribute($this->context->getEnumAttributeKindForName('writeonly'), 0),
        ];

        $this->analyzer = new Analyzer;
        $this->helper = new Helper($this);
        
        $this->refcount = new Builtin\Refcount($this, $loadType);
        $this->memory = Builtin\MemoryManager::load($this, $loadType);
        $this->output = new Builtin\Output($this, $loadType);
        $this->type = new Builtin\Type($this, $loadType);
        $this->internal = new Builtin\Internal($this, $loadType);
        $this->vararg = new Builtin\VarArg($this, $loadType);
        $this->error = new Builtin\ErrorHandler($this, $loadType);

        $this->ensureInitShutdownBlocks();
        $this->defineBuiltins($loadType);
    }

    public function setMain(PHPLLVM\Value\Function_ $func): void {
        $this->main = $func;
    }

    public function addExport(string $name, string $signature, Block $block): void {
        $this->exports[] = [$name, $signature, $block];
        CompileCache::recordExport($name, $signature, $block);
    }

    /** Implicit $this passed as the first LLVM arg for instance methods (#877). */
    public ?Variable $implicitThisArgument = null;

    public function pushScope(): void {
        $this->scopeStack[] = $this->scope;
        $this->scope = new Scope;
    }

    public function popScope(): void {
        assert(!empty($this->scopeStack));
        $this->scope = array_pop($this->scopeStack);
    }

    /**
     * Resolve a JIT variable by PHP name across nested include scopes (#776).
     */
    public function variableForScopedName(string $name): ?Variable
    {
        foreach ($this->scope->variables as $op) {
            if (OperandName::resolve($op) === $name) {
                return $this->scope->variables[$op];
            }
        }
        for ($i = count($this->scopeStack) - 1; $i >= 0; --$i) {
            foreach ($this->scopeStack[$i]->variables as $op) {
                if (OperandName::resolve($op) === $name) {
                    return $this->scopeStack[$i]->variables[$op];
                }
            }
        }

        return null;
    }

    public function resolveFunctionProxy(string $proxyName): Call
    {
        $proxy = $this->lookupFunctionProxy($proxyName);
        if (null !== $proxy) {
            return $proxy;
        }
        if (LazyBuiltins::isEnabled($this->loadType) && $this->runtime->ensureJitBuiltinCompiled($proxyName)) {
            $proxy = $this->lookupFunctionProxy($proxyName);
            if (null !== $proxy) {
                return $proxy;
            }
        }
        $lc = strtolower($proxyName);
        $internal = $this->resolveRegisteredInternalBuiltin($lc);
        if (null !== $internal) {
            $this->functionProxies[$lc] = $internal;

            return $internal;
        }
        $this->functionProxies[$lc] = new Call\ExternalMethod($proxyName);

        return $this->functionProxies[$lc];
    }

    private function lookupFunctionProxy(string $proxyName): ?Call
    {
        $lc = strtolower($proxyName);
        if (isset($this->functionProxies[$lc])) {
            $existing = $this->functionProxies[$lc];
            if ($existing instanceof Call\ExternalMethod) {
                $internal = $this->resolveRegisteredInternalBuiltin($lc);
                if (null !== $internal) {
                    $this->functionProxies[$lc] = $internal;

                    return $internal;
                }
            }

            return $existing;
        }
        if (preg_match('/^(.+)\\\\([^\\\\]+)::(.+)$/', $lc, $matches)) {
            $shortKey = $matches[2].'::'.$matches[3];
            if (isset($this->functionProxies[$shortKey])) {
                return $this->functionProxies[$shortKey];
            }
        }
        // NsFuncCall lowers unqualified calls to the current namespace (e.g.
        // PHPCompiler\Web\dirname); fall back to the global builtin when no
        // namespaced function exists in the bundle.
        if (str_contains($lc, '\\') && !str_contains($lc, '::')) {
            $globalFn = substr($lc, strrpos($lc, '\\') + 1);
            if (isset($this->functionProxies[$globalFn])) {
                return $this->functionProxies[$globalFn];
            }
            $internal = $this->resolveRegisteredInternalBuiltin($globalFn);
            if (null !== $internal) {
                $this->functionProxies[$globalFn] = $internal;

                return $internal;
            }
        }
        $internal = $this->resolveRegisteredInternalBuiltin($lc);
        if (null !== $internal) {
            $this->functionProxies[$lc] = $internal;

            return $internal;
        }

        return null;
    }

    private function resolveRegisteredInternalBuiltin(string $lc): ?FuncInternal
    {
        foreach ($this->modules as $module) {
            foreach ($module->getFunctions() as $func) {
                if (!$func instanceof FuncInternal) {
                    continue;
                }
                if (strtolower($func->getName()) === $lc) {
                    return $func;
                }
            }
        }

        return null;
    }

    public function recordExternalMethodStub(string $proxyName): void
    {
        $this->externalMethodStubs[strtolower($proxyName)] = true;
    }

    /**
     * Whether a function name resolves to a builtin or user function in this compile unit (issue #1216).
     */
    public function functionIsRegistered(string $name): bool
    {
        $normalized = ltrim($name, '\\');
        $lc = strtolower($normalized);
        if ($this->functionProxyIsCallable($lc)) {
            return true;
        }
        $short = SelfHostBuiltinPolicy::normalizeName($name);
        if ($short !== $lc && $this->functionProxyIsCallable($short)) {
            return true;
        }
        if (isset($this->registeredBuiltinNames()[$lc]) || ($short !== $lc && isset($this->registeredBuiltinNames()[$short]))) {
            return true;
        }

        return isset($this->functions[$lc]) || ($short !== $lc && isset($this->functions[$short]));
    }

    /** @return array<string, true> */
    private function registeredBuiltinNames(): array
    {
        if (null !== $this->registeredBuiltinLookup) {
            return $this->registeredBuiltinLookup;
        }
        $this->registeredBuiltinLookup = [];
        foreach ($this->modules as $module) {
            foreach ($module->getFunctions() as $func) {
                $this->registeredBuiltinLookup[strtolower($func->getName())] = true;
            }
        }

        return $this->registeredBuiltinLookup;
    }

    /**
     * @return list<string> Lowercase user-defined function names compiled into this unit.
     */
    public function userFunctionNames(): array
    {
        $names = [];
        foreach ($this->functionProxies as $lc => $proxy) {
            if ($proxy instanceof Call\ExternalMethod || $proxy instanceof FuncInternal) {
                continue;
            }
            if ($proxy instanceof Call\Native || $proxy instanceof Call\Vararg) {
                $names[] = $lc;
            }
        }

        return array_values(array_unique($names));
    }

    private function functionProxyIsCallable(string $lc): bool
    {
        if (!isset($this->functionProxies[$lc])) {
            return false;
        }

        return !($this->functionProxies[$lc] instanceof Call\ExternalMethod);
    }

    public function registerModule(Module $module): void {
        $this->modules[] = $module;
        $module->jitInit($this);
    }

    public function registerBuiltin(Builtin $builtin): void {
        $this->builtins[] = $builtin;
    }

    private function defineBuiltins(int $loadType): void {
        // Stale sg_* from a prior JITContext in the same PHP process breaks SessionDestroy::implement (#4415).
        SuperglobalInit::$globals = [];
        LibcExtern::register($this);
        foreach ($this->builtins as $builtin) {
            // this is a separate loop, since implementation may
            // depend on global variables set during init()
            // so this way, cross-builtin dependencies are honored
            $builtin->register();
        }
        if ($loadType === Builtin::LOAD_TYPE_IMPORT) {
            return;
        }
        foreach ($this->builtins as $builtin) {
            // this is a separate loop, since initialize may
            // depend on functions defined during implement()
            // so this way, cross-builtin dependencies are honored
            $builtin->implement();
        }
        McjitEmbedRuntime::finalizeModule($this);
        $this->ensureInitShutdownBlocks();

        foreach ($this->builtins as $builtin) {
            $builtin->initialize();
        }

        SuperglobalInit::initialize($this);
        CliArgvGlobalInit::initialize($this);
        if (Builtin::LOAD_TYPE_STANDALONE === $this->loadType
            || Builtin::LOAD_TYPE_EMBED === $this->loadType) {
            SuperglobalInit::declareRefresh($this);
            SuperglobalInit::implementRefresh($this);
        }

        Builtin\ReflectionNative::registerDeclarations($this);
        Builtin\AttributeRegistry::registerDeclarations($this);
        if (Builtin::LOAD_TYPE_STANDALONE === $this->loadType) {
            if ($this->isUserScriptAot()) {
                $this->ensureMinimalUserStandaloneBodies();
            } else {
                $this->ensureFullStandaloneBodies();
            }
        }

        $this->functionProxies['is_null'] = new Builtin\IsNullFn();
        $this->functionProxies['phpcompiler\\is_null'] = new Builtin\IsNullFn();
        $this->functionProxies['splobjectstorage::attach'] = new Call\SplObjectStorageMethod('attach');
        $this->functionProxies['splobjectstorage::contains'] = new Call\SplObjectStorageMethod('contains');
        $this->functionProxies['splobjectstorage::count'] = new Call\SplObjectStorageMethod('count');
        $this->functionProxies['splobjectstorage::offsetexists'] = new Call\SplObjectStorageMethod('offsetexists');
        $this->functionProxies['splobjectstorage::offsetget'] = new Call\SplObjectStorageMethod('offsetget');
        $this->functionProxies['splobjectstorage::offsetset'] = new Call\SplObjectStorageMethod('offsetset');

        $this->functionProxies['weakreference::create'] = new Call\WeakReferenceCreate();
        $this->functionProxies['weakreference::get'] = new Call\WeakReferenceGet();
        $this->functionProxies['weakmap::offsetset'] = new Call\WeakMapMethod('offsetset');
        $this->functionProxies['weakmap::offsetget'] = new Call\WeakMapMethod('offsetget');
        $this->functionProxies['weakmap::offsetexists'] = new Call\WeakMapMethod('offsetexists');
        $this->functionProxies['weakmap::offsetunset'] = new Call\WeakMapMethod('offsetunset');
        $this->functionProxies['weakmap::count'] = new Call\WeakMapMethod('count');

        $this->functionProxies['reflectionclass::__construct'] = new Call\ReflectionClassConstruct();
        $this->functionProxies['reflectionclass::getname'] = new Call\ReflectionClassGetName();
        $this->functionProxies['reflectionclass::getattributes'] = new Call\ReflectionClassGetAttributes();
        $this->functionProxies['reflectionclass::getmethod'] = new Call\ReflectionClassGetMethod();
        $this->functionProxies['reflectionclass::getreflectionconstant'] = new Call\ReflectionClassGetReflectionConstant();
        if (CompilerVersion::supportsLazyObjectFactories()) {
            $this->functionProxies['reflectionclass::newlazyproxy'] = new Call\ReflectionClassNewLazyProxy();
            $this->functionProxies['reflectionclass::newlazyghost'] = new Call\ReflectionClassNewLazyGhost();
            $this->functionProxies['reflectionclass::createlazyghost'] = new Call\ReflectionClassCreateLazyGhost();
            $this->functionProxies['reflectionclass::createlazyproxy'] = new Call\ReflectionClassCreateLazyProxy();
        }
        $this->functionProxies['reflectionproperty::__construct'] = new Call\ReflectionPropertyConstruct();
        $this->functionProxies['reflectionproperty::getattributes'] = new Call\ReflectionPropertyGetAttributes();
        $this->functionProxies['reflectionconstant::__construct'] = new Call\ReflectionConstantConstruct();
        $this->functionProxies['reflectionconstant::getattributes'] = new Call\ReflectionConstantGetAttributes();
        $this->functionProxies['reflectionmethod::getattributes'] = new Call\ReflectionMethodGetAttributes();
        $this->functionProxies['reflectionattribute::getname'] = new Call\ReflectionAttributeGetName();
        $this->functionProxies['reflectionattribute::newinstance'] = new Call\ReflectionAttributeNewInstance();
        $this->functionProxies['reflectionenum::__construct'] = new Call\ReflectionEnumConstruct();
        $this->functionProxies['reflectionenum::getname'] = new Call\ReflectionEnumGetName();
        $this->functionProxies['reflectionenum::hascase'] = new Call\ReflectionEnumHasCase();
        $this->functionProxies['reflectionenum::getcase'] = new Call\ReflectionEnumGetCase();
        $this->functionProxies['reflectionenum::isbacked'] = new Call\ReflectionEnumIsBacked();
        $this->functionProxies['reflectionenumunitcase::getname'] = new Call\ReflectionEnumUnitCaseGetName();
        $this->functionProxies['reflectionenumbackedcase::getname'] = new Call\ReflectionEnumUnitCaseGetName();
        $this->functionProxies['exception::getmessage'] = new Call\ExceptionGetMessage();

        FiberHelper::registerJitMethods($this);
        GeneratorHelper::registerJitMethods($this);
        ClosureBindHelper::registerJitMethods($this);
    }

    private function isUserScriptAot(): bool
    {
        $userScript = getenv('PHP_COMPILER_AOT_USER_SCRIPT');
        if ('1' === $userScript || 'true' === strtolower((string) $userScript)) {
            return true;
        }

        return false;
    }

    /** examples/000–009 user-script AOT: thin LLVM bridges only — no nested-JIT stdlib during init (#13571). */
    private function ensureMinimalUserStandaloneBodies(): void
    {
        ExceptionBridge::ensureStandaloneBodies($this);
        ErrorBridge::ensureStandaloneBodies($this);
        Builtin\StreamLifecycleRuntime::ensureDeferredStubsForInventoryEmit($this);
        Builtin\StreamReadRuntime::ensureDeferredStubsForInventoryEmit($this);
        Builtin\AssertFail::ensureStandaloneBodies($this);
        Builtin\JitReturnPending::ensureStandaloneBodies($this);
        Builtin\ObOutputRuntime::ensureLinked($this);
        Builtin\StringTriggerError::ensureStandaloneBodies($this);
        Builtin\StringRandomBytes::implement($this);
        Builtin\ProgressNoteRuntime::ensureStandaloneBodies($this);
        Builtin\GcCollectCyclesRuntime::ensureStandaloneBodies($this);
        Builtin\LastErrorRuntime::ensureStandaloneBodies($this);
        Builtin\RewriteVarsRuntime::ensureStandaloneBodies($this);
        Builtin\DefineRuntime::ensureStandaloneBodies($this);
        Builtin\SuperglobalNameRuntime::ensureLinked($this);
    }

    private function ensureFullStandaloneBodies(): void
    {
        ExceptionBridge::ensureStandaloneBodies($this);
        ErrorBridge::ensureStandaloneBodies($this);
        Builtin\StreamLifecycleRuntime::ensureDeferredStubsForInventoryEmit($this);
        Builtin\StreamReadRuntime::ensureDeferredStubsForInventoryEmit($this);
        Builtin\AssertFail::ensureStandaloneBodies($this);
        Builtin\AssertOptionsRuntime::ensureStandaloneBodies($this);
        Builtin\JitReturnPending::ensureStandaloneBodies($this);
        Builtin\CliArgvRuntime::ensureStandaloneBodies($this);
        Builtin\StringSoundex::ensureStandaloneBodies($this);
        Builtin\StringMetaphone::ensureStandaloneBodies($this);
        Builtin\StringStripTags::ensureStandaloneBodies($this);
        Builtin\StringStrtr::ensureStandaloneBodies($this);
        Builtin\StringParseStr::ensureStandaloneBodies($this);
        Builtin\StringJsonEncode::ensureStandaloneBodies($this);
        Builtin\StringJsonDecode::ensureStandaloneBodies($this);
        Builtin\StringGetenv::ensureDeferredStubsForInventoryEmit($this);
        Builtin\StringGetenv::ensureStandaloneBodies($this);
        Builtin\StringGetenvAll::ensureStandaloneBodies($this);
        Builtin\StringTriggerError::ensureStandaloneBodies($this);
        Builtin\ScalarDimFetchRuntime::ensureStandaloneBodies($this);
        Builtin\StringOffsetRuntime::ensureStandaloneBodies($this);
        // UndefinedVariableRuntime: ensureLinked only — emitWarningForName uses __compiler_trigger_error
        // (StringTriggerError already linked above; avoid duplicate standalone bodies — #10524).
        Builtin\StringFormat::ensureDeferredStubsForInventoryEmit($this);
        Builtin\StringJsonEncode::ensureDeferredStubsForInventoryEmit($this);
        Builtin\StringJsonDecode::ensureDeferredStubsForInventoryEmit($this);
        Builtin\StreamFilterJit::ensureDeferredStubsForInventoryEmit($this);
        Builtin\GcToggleRuntime::ensureStandaloneBodies($this);
        Builtin\FunctionStaticRuntime::ensureStandaloneBodies($this);
        Builtin\GcCollectCyclesRuntime::ensureStandaloneBodies($this);
        Builtin\ProgressNoteRuntime::ensureStandaloneBodies($this);
        Builtin\LastErrorRuntime::ensureStandaloneBodies($this);
        Builtin\RewriteVarsRuntime::ensureStandaloneBodies($this);
        Builtin\DefineRuntime::ensureStandaloneBodies($this);
        Builtin\SuperglobalRefreshRuntime::ensureStandaloneBodies($this);
        Builtin\SuperglobalNameRuntime::ensureLinked($this);
        \PHPCompiler\ext\standard\JitStrspn::ensureStandaloneBodies($this);
        Builtin\TokenGetAll::ensureStandaloneBodies($this);
        Builtin\Highlight::ensureStandaloneBodies($this);
        Builtin\Hebrev::ensureStandaloneBodies($this);
        Builtin\StreamBucketRuntime::ensureStandaloneBodies($this);
    }

    public function compileToFile(string $file) {
        // `-o` is a file path, not a directory. When a directory slips through, LLVM/ld
        // errors are confusing and (in some environments) can be misinterpreted as success.
        if (is_dir($file)) {
            throw new \InvalidArgumentException(sprintf(
                'Output path is a directory: %s (expected file path)',
                $file
            ));
        }
        $outDir = dirname($file);
        if ('' !== $outDir && '.' !== $outDir && !is_dir($outDir)) {
            throw new \InvalidArgumentException(sprintf(
                'Output directory does not exist: %s (from -o %s)',
                $outDir,
                $file
            ));
        }
        if ('' !== $outDir && '.' !== $outDir && !is_writable($outDir)) {
            throw new \InvalidArgumentException(sprintf(
                'Output directory is not writable: %s (from -o %s)',
                $outDir,
                $file
            ));
        }

        if (Builtin::LOAD_TYPE_STANDALONE === $this->loadType && $this->isUserScriptAot()) {
            Builtin\CliArgvRuntime::ensureUserScriptMainStubs($this);
            Builtin\SuperglobalRefreshRuntime::ensureUserScriptRefresh($this);
        }

        // add main function
        if (!is_null($this->main)) {
            $i32 = $this->context->int32Type();
            $i8pp = $this->getTypeFromString('int8**');
            $signature = $this->context->functionType($i32, false, $i32, $i8pp);
            $main = $this->module->addFunction('main', $signature);
            $standaloneMainBlock = $main->appendBasicBlock('standalone_main');
            $emitInStandaloneMain = function (callable $emit) use ($standaloneMainBlock): void {
                $this->builder->positionAtEnd($standaloneMainBlock);
                $emit();
            };
            $emitInStandaloneMain(function () use ($main): void {
                $this->builder->call(
                    $this->lookupFunction('__phpc_cli_store_argv'),
                    $main->getParam(0),
                    $main->getParam(1)
                );
            });
            $emitInStandaloneMain(fn () => Progress::emitNativeNote($this, 'c:main_before_init'));
            $emitInStandaloneMain(fn () => $this->builder->call($this->initFunc));
            $emitInStandaloneMain(fn () => Progress::emitNativeNote($this, 'c:main_after_init'));
            if (Builtin::LOAD_TYPE_STANDALONE === $this->loadType) {
                if (!$this->isUserScriptAot()) {
                    $emitInStandaloneMain(fn () => Builtin\HttpResponseCode::emitResetForStandaloneMain($this));
                    $emitInStandaloneMain(fn () => Builtin\SessionId::emitResetForStandaloneMain($this));
                    $emitInStandaloneMain(fn () => Builtin\SessionName::emitResetForStandaloneMain($this));
                    $emitInStandaloneMain(fn () => Builtin\SessionModuleName::emitResetForStandaloneMain($this));
                    $emitInStandaloneMain(fn () => Builtin\PendingHeaders::emitResetForStandaloneMain($this));
                }
                $emitInStandaloneMain(fn () => $this->builder->call($this->lookupFunction('__superglobals__refresh')));
                if (!$this->isUserScriptAot()) {
                    $emitInStandaloneMain(fn () => Builtin\JitThrow::registerDeclarations($this));
                    $emitInStandaloneMain(fn () => $this->builder->call($this->lookupFunction('phpc_jit_clear_throw_pending')));
                    $emitInStandaloneMain(fn () => Builtin\JitReturnPending::registerDeclarations($this));
                    $emitInStandaloneMain(fn () => $this->builder->call($this->lookupFunction('phpc_jit_clear_return_pending')));
                    $emitInStandaloneMain(fn () => ErrorBridge::emitClearForStandaloneMain($this));
                    $emitInStandaloneMain(fn () => ExceptionBridge::emitClearForStandaloneMain($this));
                }
            }
            $emitInStandaloneMain(fn () => Progress::emitNativeNote($this, 'c:main_before_php'));
            if (Builtin::LOAD_TYPE_STANDALONE === $this->loadType
                && !$this->shouldSkipStandaloneMainEnvProbeGate()) {
                $emitInStandaloneMain(fn () => VmDriverExecuteNative::emitStandaloneMainEnvProbeGate($this, $this->main));
            } else {
                $emitInStandaloneMain(fn () => $this->builder->call($this->main));
            }
            $emitInStandaloneMain(fn () => Progress::emitNativeNote($this, 'c:main_after_php'));
            if (Builtin::LOAD_TYPE_STANDALONE === $this->loadType && !$this->isUserScriptAot()) {
                $emitInStandaloneMain(fn () => ErrorBridge::emitAbortIfPendingForStandaloneMain($this));
                $emitInStandaloneMain(fn () => ExceptionBridge::emitAbortIfPendingForStandaloneMain($this));
                $emitInStandaloneMain(fn () => Builtin\PendingHeaders::emitFlushForStandalone($this));
                $emitInStandaloneMain(fn () => Builtin\ObOutput::emitEndAllForStandalone($this));
            }
            if (!$this->isUserScriptAot()) {
                // User __destruct before __shutdown__ frees compile-time strings / sg_* (#4013).
                $emitInStandaloneMain(fn () => $this->type->object->emitShutdownDestructorsCall());
            }
            $emitInStandaloneMain(fn () => $this->builder->call($this->shutdownFunc));
            $emitInStandaloneMain(fn () => $this->builder->returnValue($i32->constInt(0, false)));
        }
        Progress::noteFunction('jit_context_compile_common_begin');
        $this->compileCommon();
        Progress::noteFunction('jit_context_compile_common_done');

        Progress::noteFunction('jit_context_create_execution_engine');
        $engine = $this->module->createExecutionEngine();
        $machine = $engine->getTargetMachine();
        if (!is_null($this->debugFile)) {
            $machine->emitToFile($this->module, $this->debugFile . '.s', $machine::CODEGEN_FILE_TYPE_ASM);
        }
        $keepObject = getenv('PHP_COMPILER_KEEP_OBJECT_FILE');
        $vendorPrelink = getenv('PHP_COMPILER_VENDOR_PRELINK');
        $selfhostAot = getenv('PHP_COMPILER_SELFHOST_AOT');
        $vendorObjectOnly = ('1' === $vendorPrelink || 'true' === strtolower((string) $vendorPrelink))
            && ('0' === $selfhostAot || 'false' === strtolower((string) $selfhostAot));
        $keepingObjectOnly = ('1' === $keepObject || 'true' === strtolower((string) $keepObject))
            || $vendorObjectOnly;
        // M5 vendor argv uses -o path ending in .o; do not append a second .o (#3054).
        $objectFile = $keepingObjectOnly && str_ends_with($file, '.o') ? $file : $file.'.o';
        Progress::noteFunction('jit_context_emit_object_begin');
        $machine->emitToFile($this->module, $objectFile, $machine::CODEGEN_FILE_TYPE_OBJECT);
        Progress::noteFunction('jit_context_emit_object_done');
        if ($keepingObjectOnly) {
            Linker::assertNonEmptyOutputFile($objectFile);

            return;
        }
        Progress::noteFunction('jit_context_link_begin');
        Linker::link($objectFile, $file);
        Progress::noteFunction('jit_context_link_done');
        Linker::assertNonEmptyOutputFile($file);
        unlink($objectFile);
    }

    public function jitResult(): ?Result
    {
        return $this->result;
    }

    public function refreshSuperglobals(): void
    {
        SuperglobalInit::refreshFromVm($this);
    }

    public function compileInPlace() {
        if (is_null($this->result)) {
            McjitEmbedRuntime::prepareModule($this);
            $this->compileCommon();
            $engine = $this->module->createJITCompiler(0);
            if (!is_null($this->debugFile)) {
                $machine = $engine->getTargetMachine();
                $machine->emitToFile($this->module, $this->debugFile . '.s', $machine::CODEGEN_FILE_TYPE_ASM);
            }
            $this->result = new Result(
                $engine,
                $this->loadType
            );
            ExceptionBridge::bindJitEngine($engine);
            ErrorBridge::bindJitEngine($engine);
            foreach ($this->exports as $export) {
                $export[2]->handler = $this->result->getHandler($export[0], $export[1]);
            }
        }
    }

    /** MCJIT from on-disk bitcode cache (#153). */
    public function compileInPlaceFromDiskCache(): void {
        if (!is_null($this->result)) {
            return;
        }
        McjitEmbedRuntime::prepareModule($this);
        $message = '';
        $this->module->verify($this->module::VERIFY_ACTION_THROW, $message);
        $engine = $this->module->createJITCompiler(0);
        $this->result = new Result(
            $engine,
            $this->loadType
        );
        ExceptionBridge::bindJitEngine($engine);
        ErrorBridge::bindJitEngine($engine);
        foreach ($this->exports as $export) {
            $export[2]->handler = $this->result->getHandler($export[0], $export[1]);
        }
    }

    public function replaceModuleFromBitcodeFile(string $path): void {
        $message = '';
        $buffer = $this->llvm->createMemoryBufferWithFile($path, $message);
        if ('' !== $message) {
            throw new \RuntimeException('Bitcode read failed: '.$message);
        }
        try {
            $this->module = $buffer->parseBitcode($this->context);
        } finally {
            $buffer->dispose();
        }
        $this->targetData = $this->module->getModuleDataLayout();
    }

    private function sealInitShutdownReturn(\PHPLLVM\BasicBlock $block): void
    {
        if (null !== $block->getTerminator()) {
            return;
        }
        $this->builder->positionAtEnd($block);
        $this->builder->returnVoid();
    }

    private function sealInitFunction(): void
    {
        $tail = $this->initLinearBlock ?? $this->initBlock;
        $this->sealInitShutdownReturn($tail);
        if ($tail !== $this->initBlock) {
            $this->sealInitShutdownReturn($this->initBlock);
        }
    }

    public function emitsInitLinearIR(): bool
    {
        return $this->initLinearEmissionDepth > 0;
    }

    private function ensureInitShutdownBlocks(): void
    {
        if ($this->initShutdownBlocksReady) {
            return;
        }
        $signature = $this->context->functionType(
            $this->context->voidType(),
            false
        );
        $this->initFunc = $this->module->addFunction('__init__', $signature);
        $this->initBlock = $this->initFunc->appendBasicBlock('main');
        $this->initLinearBlock = $this->initBlock;

        $this->shutdownFunc = $this->module->addFunction('__shutdown__', $signature);
        $this->shutdownBlock = $this->shutdownFunc->appendBasicBlock('main');

        $this->headerPreFlushFunc = $this->module->addFunction('__header_pre_flush__', $signature);
        $this->headerPreFlushBlock = $this->headerPreFlushFunc->appendBasicBlock('main');

        $this->initShutdownBlocksReady = true;
    }

    public function positionBuilderAtInitEmission(): void
    {
        $this->ensureInitShutdownBlocks();
        $initParent = $this->initBlock->getParent();
        if ($initParent instanceof \PHPLLVM\Value\Function_) {
            $this->initFunc = $initParent;
        }
        $block = $this->initLinearBlock ?? $this->initBlock;
        if (null !== $block->getTerminator()) {
            throw new \LogicException(
                '__init__ linear emission block is already sealed: '.$block->getName()
            );
        }
        $this->builder->positionAtEnd($block);
    }

    public function builderInsertsInInitFunction(): bool
    {
        return $this->emitsInitLinearIR();
    }

    public function splitInitLinearTo(\PHPLLVM\BasicBlock $target): void
    {
        $block = $this->initLinearBlock ?? $this->initBlock;
        if (null === $block->getTerminator()) {
            $this->builder->positionAtEnd($block);
            $this->builder->branch($target);
        }
    }

    public function advanceInitLinearTail(\PHPLLVM\BasicBlock $resume): void
    {
        if (null !== $resume->getTerminator()) {
            throw new \LogicException('__init__ resume block must be open');
        }
        $this->initLinearBlock = $resume;
        $this->builder->positionAtEnd($resume);
    }

    private function compileCommon() {
        Progress::noteFunction('jit_context_compile_common_phase_modules_shutdown');
        foreach ($this->modules as $module) {
            $module->jitShutdown($this);
        }
        Progress::noteFunction('jit_context_compile_common_phase_builtins_shutdown');
        foreach ($this->builtins as $builtin) {
            $builtin->shutdown();
        }
        Builtin\AttributeRegistryLowering::implementLookupFunctions($this);
        $this->sealInitFunction();
        $this->sealInitShutdownReturn($this->shutdownBlock);
        $this->sealInitShutdownReturn($this->headerPreFlushBlock);

        if (!is_null($this->debugFile)) {
            $this->module->printToFile($this->debugFile . '.bc');
        }
        $this->registerAotDebugSourceGlobal();
        Progress::noteFunction('jit_context_compile_common_phase_seal_functions');
        $function = $this->module->getFirstFunction();
        while (null !== $function) {
            if ($function instanceof PHPLLVM\Value\Function_) {
                BasicBlockHelper::sealFunction($this, $function);
            }
            $next = $function->getNext();
            if (null === $next) {
                break;
            }
            $function = $next;
        }
        Progress::noteFunction('jit_context_verify_begin');
        $this->debugScanForPostTerminatorInstructions();
        $dumpIr = getenv('PHP_COMPILER_DUMP_IR');
        if ('1' === $dumpIr || 'true' === strtolower((string) $dumpIr)) {
            $this->module->printToFile('/tmp/phpc-last.ll');
        }
        $this->module->verify($this->module::VERIFY_ACTION_THROW, $message);   
        Progress::noteFunction('jit_context_verify_done');
    }

    private function debugScanForPostTerminatorInstructions(): void
    {
        $flag = getenv('PHP_COMPILER_DEBUG_LLVM_BLOCKS');
        if ('1' !== $flag && 'true' !== strtolower((string) $flag)) {
            return;
        }
        $function = $this->module->getFirstFunction();
        while (null !== $function) {
            if ($function instanceof \PHPLLVM\Value\Function_) {
                $block = $function->getFirstBasicBlock();
                while (null !== $block) {
                    $terminator = $block->getTerminator();
                    if (null === $terminator) {
                        $block = $block->getNext();
                        continue;
                    }
                    $seenTerminator = false;
                    try {
                        $inst = $block->getFirstInstruction();
                    } catch (\Throwable) {
                        $block = $block->getNext();
                        continue;
                    }
                    while (null !== $inst) {
                        if ($seenTerminator) {
                            fwrite(
                                STDERR,
                                'llvm-block-debug: fn='.$function->getName()
                                .' bb='.$block->getName()
                                ." has instruction after terminator\n"
                            );
                            break;
                        }
                        if ($inst === $terminator) {
                            $seenTerminator = true;
                        } elseif ($inst instanceof \PHPLLVM\Value\Instruction) {
                            try {
                                if ($inst->isABranchInst() || $inst->isAReturnInst() || $inst->isAUnreachableInst()) {
                                    $seenTerminator = true;
                                }
                            } catch (\Throwable) {
                            }
                        }
                        $inst = $inst instanceof \PHPLLVM\Value\Instruction ? $inst->getNext() : null;
                    }
                    $block = $block->getNext();
                }
            }
            $function = $function->getNext();
        }
    }

    private function registerAotDebugSourceGlobal(): void
    {
        if (!AotDebugSymbols::isEnabled()) {
            return;
        }
        $path = $this->aotSourceFilename;
        if (!is_string($path) || '' === $path || '-' === $path) {
            return;
        }
        $normalized = str_replace('\\', '/', $path);
        $const = $this->context->constString($normalized, true);
        $global = $this->module->addGlobal($const->typeOf(), '__phpc_aot_source_file');
        $global->setInitializer($const);
    }

    public function setDebugFile(string $file): void {
        $this->debugFile = $file;
        $this->setDebug(true);
    }

    public function setAotSourceFilename(?string $filename): void
    {
        $this->aotSourceFilename = $filename;
        if (null !== $filename && '' !== $filename) {
            $this->jitAotEntryScriptPath = str_replace('\\', '/', $filename);
        }
    }

    public function setDebug(bool $value): void {
        // Todo
    }

    public function lookupFunction(string $name): PHPLLVM\Value\Function_ {
        if (isset($this->functionScope[$name])) {
            return $this->functionScope[$name];
        }
        throw new \LogicException('Unable to lookup non-existing function ' . $name);
    }

    public function registerFunction(string $name, PHPLLVM\Value\Function_ $func): void {
        $this->functionScope[$name] = $func;
    }

    public function registerType(string $name, PHPLLVM\Type $type): void {
        $this->typeMap[$name] = $type;
    }

    public function castToBool(PHPLLVM\Value $value): PHPLLVM\Value {
        $type = $value->typeOf();
        switch ($this->getStringFromType($type)) {
            case 'bool':
            case 'int1':
                return $value;
            case 'int8':
            case 'unsigned int':
            case 'long long':
            case 'int32':
            case 'int64':
            case 'size_t':
                return $this->builder->icmp($this->builder::INT_NE, $value, $type->constInt(0, false));
            case '__value__':
            case '__value__*':
                $ptr = $value;
                if ('__value__' === $this->getStringFromType($type)) {
                    $slot = BasicBlockHelper::entryAlloca($this, $type);
                    $this->builder->store($value, $slot);
                    $ptr = $slot;
                }

                return (new \PHPCompiler\ext\standard\boolval())->call(
                    $this,
                    new Variable($this, Variable::TYPE_VALUE, Variable::KIND_VALUE, $ptr)
                );
            case '__string__':
                $slot = BasicBlockHelper::entryAlloca($this, $type);
                $this->builder->store($value, $slot);

                return \PHPCompiler\ext\standard\boolval::stringTruthy($this, $slot);
            case '__string__*':
                return \PHPCompiler\ext\standard\boolval::stringTruthy($this, $value);
        }
        throw new \LogicException("Unknown bool cast from type: " . $this->getStringFromType($type));
    }

    public function unwrapNullableUnionType(Type $type): Type
    {
        if (Type::TYPE_UNION === $type->type && [] !== ($type->subTypes ?? [])) {
            $nonNull = [];
            foreach ($type->subTypes as $sub) {
                if (Type::TYPE_NULL !== $sub->type) {
                    $nonNull[] = $sub;
                }
            }
            if (1 === count($nonNull)) {
                return $this->unwrapNullableUnionType($nonNull[0]);
            }
        }
        return $type;
    }

    public function getTypeFromType(Type $type): PHPLLVM\Type {
        $type = $this->unwrapNullableUnionType($type);
        switch ($type->type) {
            case Type::TYPE_LONG:
                return $this->getTypeFromString('long long');
            case Type::TYPE_BOOLEAN:
                return $this->getTypeFromString('bool');
            case Type::TYPE_STRING:
                return $this->getTypeFromString('__string__*');
            case Type::TYPE_OBJECT:
                return $this->getTypeFromString('__object__*');
            case Type::TYPE_ARRAY:
                return $this->getTypeFromString('__hashtable__*');
            default:
                return $this->getTypeFromString('__value__');
        }
    }

    /**
     * Struct type name for structGep on an LLVM Value (pointer or by-value struct).
     */
    public function structNameForValue(PHPLLVM\Value $value): string
    {
        $ty = $value->typeOf();
        if (PHPLLVM\Type::KIND_POINTER === $ty->getKind()) {
            return $this->getStringFromType($ty->getElementType());
        }

        return $this->getStringFromType($ty);
    }

    public function getStringFromType(PHPLLVM\Type $type): string {
        // else, try to figure it out:
        switch ($type->getKind()) {
            case PHPLLVM\Type::KIND_DOUBLE:
                return 'double';
            case PHPLLVM\Type::KIND_INTEGER:
                return 'int' . $this->llvm->lib->LLVMGetIntTypeWidth($type->type);
            case PHPLLVM\Type::KIND_POINTER:
                return $this->getStringFromType($type->getElementType()) . '*';
        }
        foreach ($this->typeMap as $name => $ptr) {
            if ($type->toString() === $ptr->toString()) {
                return $name;
            }
        }
        var_dump($type->getKind());
        return 'unknown';
    }

    /** structFieldMap index for a struct value or pointer (issue #1880). */
    public function structFieldIndex(PHPLLVM\Value $structOrPtr, string $field): int
    {
        $ty = $structOrPtr->typeOf();
        $structTy = PHPLLVM\Type::KIND_POINTER === $ty->getKind()
            ? $ty->getElementType()
            : $ty;
        $structName = $this->resolveStructMapName($structTy);
        if (!isset($this->structFieldMap[$structName][$field])) {
            throw new \LogicException(
                "structFieldIndex: struct {$structName} has no field {$field} (llvm {$ty->toString()})"
            );
        }

        return $this->structFieldMap[$structName][$field];
    }

    /** Map an LLVM struct type to a structFieldMap key (issue #1880). */
    private function resolveStructMapName(PHPLLVM\Type $structTy): string
    {
        $name = $this->getStringFromType($structTy);
        if ('unknown' !== $name) {
            $base = rtrim($name, '*');
            if (isset($this->structFieldMap[$base])) {
                return $base;
            }
        }
        if (method_exists($structTy, 'getName')) {
            $llvmName = $structTy->getName();
            if (isset($this->structFieldMap[$llvmName])) {
                return $llvmName;
            }
        }
        $repr = $structTy->toString();
        foreach (array_keys($this->structFieldMap) as $candidate) {
            if (str_contains($repr, $candidate)) {
                return $candidate;
            }
        }

        return $name;
    }

    public function getTypeFromString(string $type): PHPLLVM\Type {
        if (!isset($this->typeMap[$type])) {
            $this->typeMap[$type] = $this->_getTypeFromString($type);
        }
        return $this->typeMap[$type];
    }

    public function _getTypeFromString(string $type): PHPLLVM\Type {
        switch ($type) {
            case 'void':
                return $this->context->voidType();
            case 'const char':
                return $this->context->int8Type();
            case 'char':
            case 'int8':
                return $this->context->int8Type();
            case 'int16':
            case 'short':
            case 'unsigned short':
                return $this->context->int16Type();
            case 'int32':
            case 'int':
            case 'unsigned int':
                return $this->context->int32Type();
            case 'int64':
            case 'long long':
            case 'unsigned long long':
            case 'size_t':
                return $this->context->int64Type();
                //return $this->module->getModuleDataLayout()->intPointerType();
            case 'int1':
            case 'bool':
                return $this->context->int1Type();
            case 'float':
                return $this->context->floatType();
            case 'double':
                return $this->context->doubleType();

        }
        if (substr($type, -1) === '*') {
            return $this->getTypeFromString(substr($type, 0, -1))->pointerType(0);
        }
        if (substr($type, -1) === ']') {
            // array type
            if (preg_match('(^(.*?)\\[(\d+)\\]$)', $type, $match)) {
                return $this->getTypeFromString($match[1])->arrayType((int) $match[2]);
            } else {
                throw new \LogicException("Could not parse type with array notation: $type");
            }
        }
        throw new \LogicException("Unsupported native type $type");
    }

    public function constantFromInteger(int $value, ?string $type = null): PHPLLVM\Value {
        return $this->getTypeFromString($type === null ? 'long long' : $type)->constInt($value, $value < 0);
    }

    public function constantFromFloat(float $value, ?string $type = null): PHPLLVM\Value {
        return $this->getTypeFromString($type === null ? 'double' : $type)->constReal($value);
    }

    public function constantFromString(string $string): PHPLLVM\Value {
        if (!isset($this->stringConstant[$string])) {
            $const = $this->context->constString($string, true);
            // Avoid LLVM symbol names that match POSIX/CGI env var names (e.g. SERVER_PROTOCOL)
            // which break getenv() linkage in the AOT binary (issue #306).
            $globalName = 'php_cstr_' . hash('sha256', $string);
            $global = $this->module->addGlobal($const->typeOf(), $globalName);
            $global->setInitializer($const);
            $this->stringConstant[$string] = $global;
        }
        return $this->stringConstant[$string];
    }

    /** NUL-terminated C string pointer for a module string global. */
    public function pointerFromStringConstant(string $string): PHPLLVM\Value
    {
        return $this->bytePtr($this->constantFromString($string));
    }

    /** C-style int success flag (non-zero => true) for branch/select lowering. */
    public function i32Success(PHPLLVM\Value $value): PHPLLVM\Value
    {
        return $this->builder->icmp(
            PHPLLVM\Builder::INT_NE,
            $value,
            $this->getTypeFromString('int32')->constInt(0, false)
        );
    }

    /** Bitcast any pointer to i8* for libc helpers declared with int8* parameters. */
    public function bytePtr(PHPLLVM\Value $value): PHPLLVM\Value
    {
        return $this->builder->pointerCast($value, $this->getTypeFromString('int8*'));
    }

    private array $boolValues = [];

    public function constantFromBool(bool $value): PHPLLVM\Value {
        $id = $value ? 1 : 0;
        if (!isset($this->boolValues[$id])) {
            $this->boolValues[$id] = $this->getTypeFromString('bool')->constInt($id, false);
        }
        return $this->boolValues[$id];
    }

    public function constantStringFromString(string $string): PHPLLVM\Value {
        if (!isset($this->stringConstantMap[$string])) {
            $global = $this->module->addGlobal($this->type->string->pointer, 'string_const_' . count($this->stringConstantMap));
            $global->setInitializer($this->type->string->pointer->constNull());
            $oldBuilder = $this->builder;
            $resumeInitEmission = $this->emitsInitLinearIR();
            $this->builder = $this->context->builderCreate();
            $this->positionBuilderAtInitEmission();
            $this->type->string->init(
                $global,
                $this->constantFromString($string),
                $this->constantFromInteger(strlen($string), 'size_t'),
                true
            );
            $this->builder->positionAtEnd($this->shutdownBlock);
            $this->memory->free($this->builder->load($global));
            $this->builder = $oldBuilder;
            if ($resumeInitEmission) {
                $this->positionBuilderAtInitEmission();
            }
            $this->stringConstantMap[$string] = $global;
        }
        return $this->stringConstantMap[$string];
    }

    /**
     * Module global for a compile-time constant array (eager __init__ — #4904, #4941).
     */
    public function constantArrayFromVmHashTable(string $cacheKey, \PHPCompiler\VM\HashTable $table): PHPLLVM\Value
    {
        if (!isset($this->arrayConstantMap[$cacheKey])) {
            $ptrTy = $this->getTypeFromString('__value__*');
            $global = $this->module->addGlobal($ptrTy, 'array_const_' . \count($this->arrayConstantMap));
            $global->setInitializer($ptrTy->constNull());
            $this->arrayConstantMap[$cacheKey] = $global;
            $this->emitConstantArrayInitInInitBlock($global, $table);
        }

        return $this->arrayConstantMap[$cacheKey];
    }

    /** @deprecated Inline lazy-init removed; arrays initialize in __init__ (#4941). */
    public function ensureConstantArrayLazyInit(string $cacheKey): void
    {
    }

    private function emitConstantArrayInitInInitBlock(PHPLLVM\Value $global, \PHPCompiler\VM\HashTable $table): void
    {
        $oldBuilder = $this->builder;
        $this->builder = $this->context->builderCreate();
        $this->positionBuilderAtInitEmission();
        $htVar = HashTableHelper::variableFromVmHashTable($this, $table);
        $ht = HashTableHelper::loadHashtablePointer($this, $htVar);
        $this->refcount->addref($ht);
        $valueType = $this->getTypeFromString('__value__');
        $heapVal = $this->memory->malloc($valueType);
        $heapPtr = $this->builder->pointerCast(
            $heapVal,
            $this->getTypeFromString('__value__*')
        );
        $this->builder->call(
            $this->lookupFunction('__value__writeHashtable'),
            $heapPtr,
            $ht
        );
        $this->builder->store($heapPtr, $global);
        $this->builder = $oldBuilder;
    }

    private function materializeVmHashTableForConstInit(\PHPCompiler\VM\HashTable $table): PHPLLVM\Value
    {
        $ht = HashTableHelper::alloc($this);
        $setLong = $this->lookupFunction('__hashtable__setLongAt');
        $i64 = $this->getTypeFromString('int64');
        foreach ($table->iterateKeyed(true) as [$keyVar, $valueVar]) {
            $resolved = $valueVar->resolveIndirect();
            if (VMVariable::TYPE_INTEGER !== $keyVar->type) {
                return $this->helper->loadValue(
                    HashTableHelper::variableFromVmHashTable($this, $table)
                );
            }
            $idx = $this->constantFromInteger($keyVar->toInt(), 'size_t');
            if (VMVariable::TYPE_INTEGER === $resolved->type) {
                $this->builder->call(
                    $setLong,
                    $ht,
                    $idx,
                    $i64->constInt($resolved->toInt(), false)
                );
            } elseif (VMVariable::TYPE_ARRAY === $resolved->type) {
                $nested = $this->materializeVmHashTableForConstInit($resolved->toArray());
                $this->refcount->addref($nested);
                $nestedVar = new Variable(
                    $this,
                    Variable::TYPE_HASHTABLE,
                    Variable::KIND_VALUE,
                    $nested
                );
                HashTableHelper::setAtIndex($this, $ht, $idx, $nestedVar);
            } else {
                return $this->helper->loadValue(
                    HashTableHelper::variableFromVmHashTable($this, $table)
                );
            }
        }

        return $ht;
    }

    /**
     * Temporarily position the builder at __init__ (for native registry calls).
     *
     * @param callable(self): void $emit
     */
    public function emitInInit(callable $emit): void
    {
        $oldBuilder = $this->builder;
        $this->builder = $this->context->builderCreate();
        ++$this->initLinearEmissionDepth;
        $this->positionBuilderAtInitEmission();
        try {
            $emit($this);
        } finally {
            if ($this->initLinearEmissionDepth > 0) {
                --$this->initLinearEmissionDepth;
            }
            $this->builder = $oldBuilder;
        }
    }

    /**
     * Temporarily position the builder at __shutdown__ (register_shutdown_function, issue #3120).
     *
     * @param callable(self): void $emit
     */
    public function emitInShutdown(callable $emit): void
    {
        $oldBuilder = $this->builder;
        $this->builder = $this->context->builderCreate();
        $this->builder->positionAtEnd($this->shutdownBlock);
        try {
            $emit($this);
        } finally {
            $this->builder = $oldBuilder;
        }
    }

    /**
     * Temporarily position the builder at __header_pre_flush__ (header_register_callback, #3759).
     *
     * @param callable(self): void $emit
     */
    public function emitInHeaderPreFlush(callable $emit): void
    {
        $oldBuilder = $this->builder;
        $this->builder = $this->context->builderCreate();
        $this->builder->positionAtEnd($this->headerPreFlushBlock);
        try {
            $emit($this);
        } finally {
            $this->builder = $oldBuilder;
        }
    }

    public function makeVariableFromOp(
        PHPLLVM\Value\Function_ $func,
        PHPLLVM\BasicBlock $basicBlock,
        Block $block,
        Operand $op
    ) {
        if ($this->scope->variables->contains($op)) {
            return;
        }
        $name = OperandName::resolve($op);
        if ('this' === $name) {
            foreach ($this->scope->variables as $existingOp) {
                if ('this' === OperandName::resolve($existingOp)) {
                    $this->scope->variables[$op] = $this->scope->variables[$existingOp];

                    return;
                }
            }
        }
        if (null !== $name && Superglobals::isSuperglobalName($name)) {
            $this->scope->variables[$op] = SuperglobalInit::load($this, $name);

            return;
        }
        if (null !== $name && $block->isMainScript()) {
            if (!$this->isForeachByRefLocalName($name, $block)) {
                $global = $this->ensureScriptGlobal($name);
                $this->scope->variables[$op] = $global;
                $this->bindVariableByName($name, $global);

                return;
            }
        }
        $this->scope->variables[$op] = Variable::fromOp($this, $func, $basicBlock, $block, $op);
        $this->scope->variables[$op]->initialize();
    }

    public function setVariableOp(Operand $op, Variable $var) {
        $this->scope->variables[$op] = $var;
    }

    /**
     * php-cfg may use distinct {@see Operand\Variable}/{@see Operand\Temporary} objects for one scope slot (#72, #12036).
     */
    private function aliasVariableOpByName(Operand $op): bool
    {
        $name = OperandName::resolve($op);
        if (null === $name || '' === $name) {
            return false;
        }
        $resolved = $this->resolveRefAliasName($name);
        if (isset($this->namedVariableBindings[$resolved])) {
            $this->scope->variables[$op] = $this->namedVariableBindings[$resolved];

            return true;
        }
        // CLI globals imported via `global $argv` / `global $argc` on inventory argv spine (#12036).
        if ('argv' === $name || 'argc' === $name) {
            $global = $this->ensureScriptGlobal($name);
            $alias = new Variable(
                $this,
                Variable::TYPE_VALUE,
                Variable::KIND_VARIABLE,
                JitValueBox::alloc($this)
            );
            $alias->valueBoxAliasPtr = JitValueBox::valuePtrFromVariable($this, $global);
            $alias->functionStaticGlobal = true;
            $this->bindVariableByName($name, $alias);
            $this->scope->variables[$op] = $alias;

            return true;
        }
        foreach ($this->scope->variables as $scopeOp) {
            if ($name === OperandName::resolve($scopeOp)) {
                $this->scope->variables[$op] = $this->scope->variables[$scopeOp];

                return true;
            }
        }
        foreach ($this->scopeStack as $scope) {
            foreach ($scope->variables as $scopeOp) {
                if ($name === OperandName::resolve($scopeOp)) {
                    $this->scope->variables[$op] = $scope->variables[$scopeOp];

                    return true;
                }
            }
        }
        $block = $this->jitCurrentBlock;
        if (null !== $block) {
            if ($block->declaresGlobalName($name)) {
                $global = $this->ensureScriptGlobal($name);
                $this->bindVariableByName($name, $global);
                $this->scope->variables[$op] = $global;

                return true;
            }
            $slot = $block->slotForOperand($op);
            if (null !== $slot) {
                foreach ($block->scopedOperands() as $scopeOp) {
                    if ($block->slotForOperand($scopeOp) !== $slot || !$this->scope->variables->contains($scopeOp)) {
                        continue;
                    }
                    $this->scope->variables[$op] = $this->scope->variables[$scopeOp];

                    return true;
                }
            }
        }

        return false;
    }

    /**
     * php-cfg may use distinct {@see Operand\Temporary} objects for one scope slot (#72).
     */
    public function aliasVariableOpFromSlot(Block $block, Operand $op): bool
    {
        if ($this->scope->variables->contains($op)) {
            return true;
        }
        if ($this->aliasVariableOpByName($op)) {
            return true;
        }
        $slot = $block->slotForOperand($op);
        if (null === $slot) {
            return false;
        }
        foreach ($block->scopedOperands() as $scopeOp) {
            if ($block->slotForOperand($scopeOp) !== $slot || !$this->scope->variables->contains($scopeOp)) {
                continue;
            }
            $this->scope->variables[$op] = $this->scope->variables[$scopeOp];

            return true;
        }

        return false;
    }

    public function hasVariableOp(Operand $op): bool {
        if ($this->scope->variables->contains($op)) {
            return true;
        }
        if ($op instanceof Operand\Literal) {
            return true;
        }
        return false;
    }

    public function resolveRefAliasName(string $name): string
    {
        while (isset($this->refAliasNames[$name])) {
            $name = $this->refAliasNames[$name];
        }

        return $name;
    }

    /** True when $name is the dest of foreach Iterator_Value → AssignRef in $block (#4364). */
    public function isForeachByRefLocalName(string $name, Block $block): bool
    {
        $resolved = $this->resolveRefAliasName($name);
        if (isset($this->foreachByRefLocalNames[$resolved])) {
            return true;
        }
        $root = $this->jitFunctionRootBlock ?? $block;
        $seen = [];
        $queue = [$root];
        while ([] !== $queue) {
            $scan = array_shift($queue);
            $id = spl_object_id($scan);
            if (isset($seen[$id])) {
                continue;
            }
            $seen[$id] = true;
            foreach ($scan->opCodes as $op) {
                if (OpCode::TYPE_ASSIGN_REF === $op->type) {
                    $destName = OperandName::resolve($scan->getOperand($op->arg1));
                    if (null !== $destName && $resolved === $this->resolveRefAliasName($destName)) {
                        $srcName = OperandName::resolve($scan->getOperand($op->arg2));
                        if (null === $srcName) {
                            $this->foreachByRefLocalNames[$resolved] = true;

                            return true;
                        }
                    }
                }
                if (OpCode::TYPE_ITER_VALUE === $op->type && $op->arg3) {
                    $destName = OperandName::resolve($scan->getOperand($op->arg1));
                    if (null !== $destName && $resolved === $this->resolveRefAliasName($destName)) {
                        $this->foreachByRefLocalNames[$resolved] = true;

                        return true;
                    }
                }
                foreach ([$op->block1 ?? null, $op->block2 ?? null, $op->block3 ?? null] as $target) {
                    if ($target instanceof Block && !isset($seen[spl_object_id($target)])) {
                        $queue[] = $target;
                    }
                }
            }
        }

        return false;
    }

    public function getVariableFromOp(Operand $op): Variable {
        $name = OperandName::resolve($op);
        if (null !== $name && '' !== $name) {
            $resolved = $this->resolveRefAliasName($name);
            if ($resolved !== $name) {
                foreach ($this->scope->variables as $scopeOp) {
                    if (!$scopeOp instanceof Operand) {
                        continue;
                    }
                    if ($resolved === OperandName::resolve($scopeOp)) {
                        return $this->scope->variables[$scopeOp];
                    }
                }
            }
            if (isset($this->namedVariableBindings[$resolved])) {
                $this->scope->variables[$op] = $this->namedVariableBindings[$resolved];

                return $this->namedVariableBindings[$resolved];
            }
        }
        if (!$this->scope->variables->contains($op)) {
            if ($op instanceof Operand\Literal) {
                $this->scope->variables[$op] = Variable::fromLiteral($this, $op);
            } elseif ('this' === OperandName::resolve($op)) {
                $existing = $this->findThisVariable();
                if (null !== $existing) {
                    $this->scope->variables[$op] = $existing;
                } else {
                    throw new \LogicException("Unknown variable referenced: " . get_class($op));
                }
            } elseif ($op instanceof Operand\Temporary) {
                // Temporaries can be introduced by CFG transforms after scope variable allocation.
                // Treat unknown temporaries as boxed __value__ slots to keep self-host emit paths alive.
                $slot = JitValueBox::alloc($this);
                $this->builder->call(
                    $this->lookupFunction('__value__writeNull'),
                    JitValueBox::pointer($this, $slot)
                );
                $this->scope->variables[$op] = new Variable(
                    $this,
                    Variable::TYPE_VALUE,
                    Variable::KIND_VARIABLE,
                    $slot
                );
            } elseif ($op instanceof Operand\Variable && $this->aliasVariableOpByName($op)) {
                // Distinct Variable operand for an already-allocated scope slot (#12036 inventory argv).
            } else {
                throw new \LogicException("Unknown variable referenced: " . get_class($op));
            }
        }

        return $this->scope->variables[$op];
    }

    public function findThisVariable(): ?Variable
    {
        foreach ($this->scope->variables as $existingOp) {
            if ('this' === OperandName::resolve($existingOp)) {
                return $this->scope->variables[$existingOp];
            }
        }
        if (null !== $this->implicitThisArgument) {
            return $this->implicitThisArgument;
        }

        return null;
    }

    public function hasVariableOpInScopes(Operand $op): bool
    {
        if ($this->scope->variables->contains($op)) {
            return true;
        }
        foreach ($this->scopeStack as $scope) {
            if ($scope->variables->contains($op)) {
                return true;
            }
        }

        return false;
    }

    public function getVariableFromOpInScopes(Operand $op): Variable
    {
        if ($this->scope->variables->contains($op)) {
            return $this->scope->variables[$op];
        }
        foreach ($this->scopeStack as $scope) {
            if ($scope->variables->contains($op)) {
                return $scope->variables[$op];
            }
        }

        return $this->getVariableFromOp($op);
    }

    public function makeVariableFromValueOp(
        PHPLLVM\Value $value,
        Operand $op
    ): Variable {
        $this->scope->variables[$op] = Variable::fromValueOp(
            $this, $value, $op
        );
        return $this->scope->variables[$op];
    }

    public function freeDeadVariables(
        PHPLLVM\Value\Function_ $func,
        PHPLLVM\BasicBlock $basicBlock,
        Block $block,
        ?Operand $skipOperand = null
    ): void {
        $coalesceResults = new \SplObjectStorage();
        foreach ($block->opCodes as $blockOp) {
            if (OpCode::TYPE_COALESCE === $blockOp->type && null !== $blockOp->block3) {
                $coalesceResults[$block->getOperand($blockOp->arg1)] = true;
            }
        }
        $returnOperands = new \SplObjectStorage();
        $returnSlots = [];
        foreach ($block->opCodes as $blockOp) {
            if (OpCode::TYPE_RETURN !== $blockOp->type || null === $blockOp->arg1) {
                continue;
            }
            $returnOp = $block->getOperand($blockOp->arg1);
            $returnOperands[$returnOp] = true;
            $returnSlots[(int) $blockOp->arg1] = true;
        }
        if (null !== $skipOperand) {
            $returnOperands[$skipOperand] = true;
            $skipSlot = $block->slotForOperand($skipOperand);
            if (null !== $skipSlot) {
                $returnSlots[$skipSlot] = true;
            }
        }
        foreach ($this->coalesceAssignTargets as $mergeOp) {
            $returnOperands[$mergeOp] = true;
            $mergeSlot = $block->slotForOperand($mergeOp);
            if (null !== $mergeSlot) {
                $returnSlots[$mergeSlot] = true;
            }
        }
        $returnVarNames = [];
        foreach ($returnOperands as $returnOp) {
            $name = OperandName::resolve($returnOp);
            if (null !== $name) {
                $returnVarNames[$name] = true;
            }
        }
        foreach ($block->orig->deadOperands as $op) {
            if ($returnOperands->contains($op)) {
                continue;
            }
            $deadSlot = $block->slotForOperand($op);
            if (null !== $deadSlot && isset($returnSlots[$deadSlot])) {
                continue;
            }
            $name = OperandName::resolve($op);
            if (null !== $name && isset($returnVarNames[$name])) {
                continue;
            }
            if ($coalesceResults->contains($op)) {
                continue;
            }
            if (!$this->scope->variables->contains($op)) {
                continue;
            }
            $var = $this->scope->variables[$op];
            $name = OperandName::resolve($op);
            if (
                null !== $var->superglobalName
                || (null !== $name && Superglobals::isSuperglobalName($name))
                || 'this' === $name
            ) {
                continue;
            }
            $var->free();
        }
    }

    /**
     * CLI stdio constants lower to integer fds for fwrite/standalone AOT (#90953, #10163).
     * VmStdStreamConstants registers stream objects on the VM; JIT must not see TYPE_OBJECT.
     */
    private function vmStdioFdVariable(string $name): ?VMVariable
    {
        return match ($name) {
            'STDIN' => $this->vmIntegerConstant(0),
            'STDOUT' => $this->vmIntegerConstant(1),
            'STDERR' => $this->vmIntegerConstant(2),
            default => null,
        };
    }

    private function vmIntegerConstant(int $value): VMVariable
    {
        $var = new VMVariable(VMVariable::TYPE_INTEGER);
        $var->int($value);

        return $var;
    }

    private function zendConstantVariable(string $name): ?VMVariable
    {
        if (!\is_string($name) || !\defined($name)) {
            return null;
        }
        $value = \constant($name);
        if (\is_int($value)) {
            return $this->vmIntegerConstant($value);
        }
        if (\is_float($value)) {
            $var = new VMVariable(VMVariable::TYPE_FLOAT);
            $var->float($value);

            return $var;
        }
        if (\is_bool($value)) {
            $var = new VMVariable(VMVariable::TYPE_BOOLEAN);
            $var->bool($value);

            return $var;
        }
        if (\is_string($value)) {
            $var = new VMVariable(VMVariable::TYPE_STRING);
            $var->string($value);

            return $var;
        }
        if (\is_resource($value)) {
            $stdio = $this->vmStdioFdVariable($name);
            if (null !== $stdio) {
                return $stdio;
            }
            // Other stream resources are unused in bundled bootstrap fixtures.
            $var = new VMVariable(VMVariable::TYPE_NULL);

            return $var;
        }

        return null;
    }

    public function constantFetch(Operand $op): ?Variable {
        if ($op instanceof Operand\Literal) {
            $name = $op->value;
        } else {
            throw new \LogicException("Variable constant fetch not supported yet");
        }
        if (!isset($this->constants[$name])) {
            $phpVar = $this->runtime->vmContext->constantFetch($name);
            if (is_null($phpVar)) {
                $phpVar = $this->zendConstantVariable($name);
            } elseif (VMVariable::TYPE_OBJECT === $phpVar->type) {
                $stdio = $this->vmStdioFdVariable($name);
                if (null !== $stdio) {
                    $phpVar = $stdio;
                }
            }
            if (is_null($phpVar)) {
                return null;
            }
            // convert to PHP variable
            switch ($phpVar->type) {
                case VMVariable::TYPE_NULL:
                    $nullVar = new Variable(
                        $this,
                        Variable::TYPE_NULL,
                        Variable::KIND_VALUE,
                        $this->getTypeFromString('__value__*')->constNull()
                    );
                    $nullVar->isNullConstant = true;

                    return $nullVar;
                case VMVariable::TYPE_INTEGER:
                    $type = $this->getTypeFromString('int64');
                    $global = $this->module->addGlobal($type, $name);
                    $global->setInitializer($type->constInt($phpVar->toInt(), false));
                    $this->constants[$name] = [Variable::TYPE_NATIVE_LONG, $global];
                    break;
                case VMVariable::TYPE_FLOAT:
                    $type = $this->getTypeFromString('double');
                    $global = $this->module->addGlobal($type, $name);
                    $global->setInitializer($type->constReal($phpVar->toFloat()));
                    $this->constants[$name] = [Variable::TYPE_NATIVE_DOUBLE, $global];
                    break;
                case VMVariable::TYPE_BOOLEAN:
                    $type = $this->getTypeFromString('int1');
                    $global = $this->module->addGlobal($type, $name);
                    $global->setInitializer($type->constInt($phpVar->toBool() ? 1 : 0, false));
                    $this->constants[$name] = [Variable::TYPE_NATIVE_BOOL, $global];
                    break;
                case VMVariable::TYPE_STRING:
                    $global = $this->constantStringFromString($phpVar->toString());
                    $this->constants[$name] = [Variable::TYPE_STRING, $global];
                    break;
                case VMVariable::TYPE_ARRAY:
                    $global = $this->constantArrayFromVmHashTable($name, $phpVar->toArray());
                    $this->constants[$name] = [Variable::TYPE_VALUE, $global];
                    break;
                default:
                    throw new \LogicException("Non-implemented constant fetch type: " . $phpVar->type);
            }       
        }
        $var = new Variable(
            $this,
            $this->constants[$name][0],
            Variable::KIND_VALUE,
            $this->builder->load($this->constants[$name][1])
        );
        $var->compileTimeConstantName = $name;

        return $var;
    }

}
