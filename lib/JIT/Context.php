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
     * First-class callable targets (function/static names) => invoke proxy (#24166).
     *
     * @var array<string, Call>
     */
    public array $fccCallableProxies = [];

    /**
     * Lowercase logical callee => optional #[\NoDiscard] message (#5663).
     *
     * @var array<string, string|null>
     */
    public array $noDiscardCalleeMessages = [];

    /**
     * Lowercase logical callee => #[\Deprecated] metadata for AOT/JIT call sites (#27331).
     *
     * @var array<string, \PHPCompiler\Compiler\DeprecatedMetadata>
     */
    public array $deprecatedCalleeMeta = [];

    /** @var array<int, Call> lazy initializer proxies keyed by __object__.lazy_init_index (#4940, #5318) */
    public array $lazyInitProxies = [];

    /**
     * Original Closure JIT variables for getLazyInitializer identity (#29152).
     *
     * Parallel to {@see $lazyInitProxies} — same index as lazy_init_index on the object.
     *
     * @var array<int, Variable>
     */
    public array $lazyInitClosures = [];

    /**
     * Proxy class names parallel to {@see $lazyInitProxies} for TypeError messages (#29170).
     *
     * @var array<int, string>
     */
    public array $lazyInitProxyClassNames = [];
    /** @var array<string, true> JIT stubs registered for external Class::method (issue #579). */
    public array $externalMethodStubs = [];
    public array $functionReturnType = [];
    public string $activeFunction = '';
    public array $functionScope = [];

    /** User function CFG block while compiling its body (func_get_args / func_num_args, #197). */
    public ?Block $jitEnclosingBlock = null;

    /** Operand for unserialize() options arg during FUNCCALL lowering (#3300). */
    public ?Operand $jitUnserializeOptionsOperand = null;

    /** Operand for json_encode() value arg during FUNCCALL lowering (#14040). */
    public ?Operand $jitJsonEncodeValueOperand = null;

    /** Operand for iterator_to_array() iterator arg — CFG userType for HT-backed SPL (#26825). */
    public ?Operand $jitIteratorToArrayIteratorOperand = null;

    /** Operand for compile-time xmlrpc_encode() array/scalar literals (#19048). */
    public ?Operand $jitXmlrpcEncodeValueOperand = null;

    /** Operand for call_user_func_array() $args during FUNCCALL lowering (#10359). */
    public ?Operand $jitCallUserFuncArrayParamsOperand = null;

    /**
     * New DateTimeZone result operand/var — construct stamps zone id onto the local (#29732).
     */
    public ?Operand $lastDateTimeZoneNewResultOp = null;

    public ?Variable $lastDateTimeZoneNewResultVar = null;

    /** Named local last assigned a DateTimeZone object — construct stamps zone id here (#29732). */
    public ?string $lastAssignedDateTimeZoneLocalName = null;

    /**
     * Local name → IANA/offset id for DateTimeZone instances (method dispatch) (#29732).
     *
     * @var array<string, string>
     */
    public array $dateTimeZoneLocalNames = [];

    /** Operand for mb_encode/decode_numericentity() convmap during FUNCCALL lowering (#7237, #18035). */
    public ?Operand $jitMbNumericEntityConvmapOperand = null;

    /** CFG block for {@see self::$jitMbNumericEntityConvmapOperand} (#18035). */
    public ?Block $jitMbNumericEntityConvmapBlock = null;

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

    /**
     * TYPE_FROM_CALLABLE result slot => invoke proxy for FUNCCALL recovery (#24166).
     *
     * @var array<int, Call>
     */
    public array $fccClosureCallByResultSlot = [];

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

    /** Scope slot => ?? result operand for runtime reload at chained call-arg send (#17590). */
    public array $coalesceMergeSlotOperands = [];

    /** `return $c ? $a : $b` shared merge operand — emit direct returns per arm (#8555 AOT). */
    public ?Operand $ternarySharedReturnOperand = null;

    /** Scope slot for {@see $ternarySharedReturnOperand} on the merge RETURN (#8555). */
    public ?int $ternarySharedReturnSlot = null;

    /**
     * Most recent WeakReference::get() result operand — released at the next JUMPIF
     * so ternary-echo merges do not keep the referent across unset (#27118).
     */
    public ?Operand $pendingWeakReferenceGetResult = null;

    /**
     * ?: arm temp slot => phi dest operand when merge-block ECHO still references the arm temp (#18052).
     *
     * @var array<int, Operand>
     */
    public array $ternaryEchoPhiByAliasSlot = [];

    /** Entry alloca holding ?: condition for literal-arm merge ECHO (#18784). */
    public ?\PHPLLVM\Value $ternaryEchoLiteralConditionSlot = null;

    /** True-arm literal for {@see $ternaryEchoLiteralConditionSlot} redirect (#18784). */
    public ?string $ternaryEchoLiteralIf = null;

    /** False-arm literal for {@see $ternaryEchoLiteralConditionSlot} redirect (#18784). */
    public ?string $ternaryEchoLiteralElse = null;


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

    /**
     * Foreach index alloca slots keyed by {@see foreachSlotMapKey()} (activeFunction + Variable id).
     *
     * @var array<string, PHPLLVM\Value>
     */
    public array $foreachIndexSlots = [];

    /**
     * Foreach object-key walk slots keyed by {@see foreachSlotMapKey()}.
     *
     * @var array<string, PHPLLVM\Value>
     */
    public array $foreachObjNodeSlots = [];

    /**
     * Iterator protocol receiver (__object__*) per foreach container (#4011).
     *
     * @var array<string, PHPLLVM\Value>
     */
    public array $foreachIteratorReceiverSlots = [];

    /**
     * Iterator protocol advance flag (int1) per foreach container (#4011).
     *
     * @var array<string, PHPLLVM\Value>
     */
    public array $foreachIteratorAdvanceSlots = [];

    /**
     * DatePeriod compile-time foreach snapshot hashtables (#26772).
     *
     * @var array<string, Variable>
     */
    public array $foreachDatePeriodSnapshotHts = [];

    /**
     * IteratorAggregate foreach slots that unwrap getIterator() then walk `__spl_ht`
     * on the inner ArrayIterator (#26785). Keyed by {@see foreachSlotMapKey()}.
     *
     * @var array<string, true>
     */
    public array $foreachAggregateInnerHtSlots = [];

    /**
     * SplStack (and LIFO dllist) foreach — packed `__spl_ht` walked descending (#28705).
     *
     * @var array<string, true>
     */
    public array $foreachReverseHtSlots = [];

    /**
     * Map key for foreach alloca tables — include activeFunction so NestedJIT of a
     * multi-method helper cannot reuse a sibling method's entry alloca when
     * spl_object_id values collide after GC (#28053 / #27228).
     */
    public function foreachSlotMapKey(object $slotKey): string
    {
        return $this->activeFunction."\0".\spl_object_id($slotKey);
    }

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

    /** @var array<string, true> `global $name` imports in the active LLVM function (#16828). */
    public array $jitImportedGlobalNames = [];

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

    /**
     * Module-global NestedJIT helper TU dedupe (#27566).
     *
     * Unlike {@see $jitAotIncludedCompileDone} (keyed by activeFunction for user includes, #878),
     * helper statics (e.g. OutputRewriteVarsJitHelper::$tags) must be NestedJIT'd once per module.
     *
     * @var array<string, true> normalized helper path → compiled
     */
    public array $jitHelperTuCompiled = [];

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
        if ('' !== $normalized && !\PHPCompiler\VM\ScriptStack::isVirtualCompileUnit($normalized)) {
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
        if ($this->isThinStandaloneAotMain()) {
            return true;
        }
        $entry = $this->resolveJitAotEntryScriptPath();
        if ('' !== $entry && str_contains($entry, '/bootstrap-aot/')) {
            return true;
        }
        if ($this->isBootstrapNonSpineSelfhostEntry()) {
            return true;
        }
        $entry = $this->resolveJitAotEntryScriptPath();
        if ('' !== $entry && str_contains($entry, 'bootstrap-aot/')) {
            return true;
        }
        $bootstrapLink = getenv('PHP_COMPILER_BOOTSTRAP_AOT_LINK');
        if ('1' === $bootstrapLink || 'true' === strtolower((string) $bootstrapLink)) {
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

    /**
     * Module-global helper NestedJIT dedupe — statics must not split across activeFunction (#27566).
     */
    public function hasJitHelperTuCompiled(string $path): bool
    {
        $normalized = $this->normalizeJitHelperTuPath($path);

        return '' !== $normalized && isset($this->jitHelperTuCompiled[$normalized]);
    }

    public function markJitHelperTuCompiled(string $path): void
    {
        $normalized = $this->normalizeJitHelperTuPath($path);
        if ('' !== $normalized) {
            $this->jitHelperTuCompiled[$normalized] = true;
        }
    }

    private function normalizeJitHelperTuPath(string $path): string
    {
        $resolved = realpath($path);
        if (false === $resolved) {
            $resolved = $path;
        }

        return \PHPCompiler\VM\ScriptStack::normalize($resolved);
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
            // Instance-method FCC / fromCallable Closures must replace array-typed locals
            // (CFG types `$obj->m(...)` as array) so `$b()` sees the Closure object (#28613).
            if (
                null !== $var->closureCall
                && Variable::TYPE_OBJECT === $var->type
            ) {
                $this->namedVariableBindings[$resolved] = $var;
                foreach ($this->scope->variables as $scopeOp) {
                    if (!$scopeOp instanceof Operand) {
                        continue;
                    }
                    if ($resolved === OperandName::resolve($scopeOp)) {
                        $this->scope->variables[$scopeOp] = $var;
                    }
                }

                return;
            }
            // Closure use() snapshot reads must not rebind enclosing locals to MCJIT rvalues (#72).
            if (
                Variable::KIND_VARIABLE === $existing->kind
                && Variable::KIND_VALUE === $var->kind
                && null === $var->valueBoxAliasPtr
            ) {
                // FCC / Closure assigns still need invoke metadata on the stable lvalue (#24106, #24166).
                if (null !== $var->closureCall) {
                    $existing->closureCall = $var->closureCall;
                    $existing->closureIsStatic = $var->closureIsStatic;
                    $existing->closureIsMethodFake = $var->closureIsMethodFake;
                }

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
        $runtime->claimJitContextSlot($this);
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
        if ([] === $this->scopeStack) {
            return;
        }
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
        // Known helper-runtime / chunk-manifest symbols → real extern, not null (#24429).
        $bound = \PHPCompiler\AOT\ExternalMethodBind::tryBind($this, $proxyName);
        if (null !== $bound) {
            return $bound;
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
                $bound = \PHPCompiler\AOT\ExternalMethodBind::tryBind($this, $proxyName);
                if (null !== $bound && !($bound instanceof Call\ExternalMethod)) {
                    return $bound;
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
        if (DomInstanceMethodJit::isDomInstanceMethodProxy($lc)) {
            DomInstanceMethodJit::ensureProxy($this, $lc);
            if (isset($this->functionProxies[$lc])
                && !($this->functionProxies[$lc] instanceof Call\ExternalMethod)) {
                return $this->functionProxies[$lc];
            }
        }
        if (XmlReaderInstanceMethodJit::isXmlReaderInstanceMethodProxy($lc)
            && XmlReaderInstanceMethodJit::isUserScriptAot()
        ) {
            XmlReaderInstanceMethodJit::ensureProxy($this, $lc);
            if (isset($this->functionProxies[$lc])
                && !($this->functionProxies[$lc] instanceof Call\ExternalMethod)) {
                return $this->functionProxies[$lc];
            }
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

        // Pre-registerModule NestedJIT (#15417): Context->modules is still empty so most
        // builtins stay ExternalMethod stubs. Allow only known *JitHelper kernel leaves
        // from Runtime modules so always-helper user-script AOT emits libc (#20290).
        if ([] === $this->modules
            && NestedJitCompileScope::isActive()
            && self::isPreRegisterModuleNestedJitKernel($lc)
            && [] !== $this->runtime->modules
        ) {
            foreach ($this->runtime->modules as $module) {
                foreach ($module->getFunctions() as $func) {
                    if (!$func instanceof FuncInternal) {
                        continue;
                    }
                    if (strtolower($func->getName()) === $lc) {
                        return $func;
                    }
                }
            }
        }

        return null;
    }

    /**
     * Kernels safe to resolve from Runtime modules during NestedJIT before
     * {@see registerModule()} — must not open the full stdlib Internal surface (#15417).
     */
    private static function isPreRegisterModuleNestedJitKernel(string $lc): bool
    {
        return match ($lc) {
            // file_put_contents NestedJIT leaf (#30127) — whitelist file_put_contents →
            // file_put_contents::call → JitFilePutContentsLibc (kernel Internal removed;
            // peer file_get_contents #29833 / readfile #29915).
            'file_put_contents',
            // readfile NestedJIT leaf (#29915) — whitelist readfile →
            // readfile::call → JitReadfileLibc (kernel Internal removed;
            // peer file_get_contents #29833 / crypt #29545).
            'readfile',
            // file_get_contents NestedJIT leaf (#29833) — whitelist file_get_contents →
            // file_get_contents::call → JitFileGetContentsLibc (kernel Internal removed;
            // peer crypt #29545 / random_bytes #29531; former kernel #29510 / #26756).
            'file_get_contents',

            // rename(2) NestedJIT leaf (#29141) — whitelist rename → rename_::call →
            // StringRename::invokeNestedLeaf (module-local rename(2); kernel removed).
            'rename',
            // chdir(2) NestedJIT leaf (#29219) — whitelist chdir → chdir_::call →
            // StringChdir::invokeNestedLeaf (module-local chdir(2); kernel removed).
            'chdir',
            // chroot(2) NestedJIT leaf (#30558) — whitelist chroot → chroot_::call →
            // StringChroot::invokeNestedLeaf (module-local chroot(2); always-on Module decl removed).
            'chroot',
            // proc_nice NestedJIT leaf (#30615) — whitelist proc_nice → proc_nice::call →
            // StringProcNice::invokeNestedLeaf (module-local nice(3); always-on JitProcNice LLVM removed).
            'proc_nice',
            // getcwd(2) NestedJIT leaf (#29429) — whitelist getcwd → getcwd_::call →
            // GetcwdJit::invokeNestedLeaf (module-local getcwd(2); always-on realpath LLVM removed).
            'getcwd',
            // sys_get_temp_dir NestedJIT leaf (#29433) — whitelist sys_get_temp_dir →
            // SysGetTempDirRuntime::invokeNestedLeaf (thin getenv/realpath; always-on LLVM removed).
            'sys_get_temp_dir',
            // tempnam NestedJIT leaf (#29940) — whitelist tempnam → tempnam::call →
            // StringTempnam / JitTempnamKernel mkstemp leaf (thin-AOT always-on kernel removed;
            // peer sys_get_temp_dir #29433 / gethostname #29364).
            'tempnam',
            // glob()/scandir() NestedJIT leaf (#29986) — whitelist → glob_/scandir::call →
            // JitFsGlob collectList / JitFsGlobKernel libc vec (thin-AOT always-on fork removed;
            // peer tempnam #29940 / sys_get_temp_dir #29433).
            'glob',
            'scandir',

            // getenv(3) NestedJIT leaf (#29313) — whitelist getenv → getenv_::call →
            // JitEnv::getenvNestedLeaf / StringGetenv::invokeNestedLeaf (kernel removed).
            'getenv',
            'phpc_ob_write_stdout_kernel',
            'phpc_url_rewriter_apply_kernel',
            'phpc_rewrite_vars_set_tags_kernel',
            // random_bytes NestedJIT leaf (#29531) — whitelist random_bytes → random_bytes::call →
            // JitRandomBytes::generate / JitRandomBytesKernel /dev/urandom leaf (kernel Internal removed).
            'random_bytes',
            // crypt NestedJIT leaf (#29545) — whitelist crypt → crypt::call →
            // JitLibcryptKernel libc crypt(3) (kernel Internal removed; peer random_bytes #29531).
            'crypt',
            // Password NestedJIT leaves (#26773) — peer random_bytes (#21186 / #29531) / hash crypto (#21026).
            'phpc_libcrypt_verify',
            'phpc_argon2_hash',
            'phpc_argon2_verify',
            // gethostname NestedJIT leaf (#29364) — whitelist gethostname → gethostname::call →
            // StringGethostname / JitGethostnameKernel /proc leaf (kernel Internal removed).
            'gethostname',
            // microtime NestedJIT leaf (#29405) — whitelist microtime → microtime::call →
            // StringMicrotime::invokeFloat/invokeString thin gettimeofday leaf.
            'microtime',
            // time NestedJIT leaf (#30332) — whitelist time → time::call →
            // StringTime::invoke / JitTimeKernel thin libc time(2) leaf.
            'time',
            // getmypid NestedJIT leaf (#30623) — whitelist getmypid → getmypid::call →
            // ProcessIdentityJit::getmypid / JitGetmypidKernel thin libc getpid(2) leaf
            // (former always-on ProcessIdentityJit getpid LLVM; peer time #30332).
            'getmypid',
            // posix_getpid NestedJIT leaf (#30696) — whitelist posix_getpid → posix_getpid::call →
            // PosixGetpidJit::invoke / JitGetmypidKernel thin libc getpid(2) leaf
            // (former always-on JitPosix::getpid LLVM; peer getmypid #30623).
            'posix_getpid',
            // fnmatch(3) NestedJIT leaf (#30383) — whitelist fnmatch → fnmatch::call →
            // StringFnmatch::invokeNestedLeaf (module-local fnmatch(3); always-on Module decl removed).
            'fnmatch',
            // nl_langinfo(3) NestedJIT leaf (#30404) — whitelist nl_langinfo → nl_langinfo::call →
            // StringNlLanginfo / JitNlLanginfo libc leaf (always-on Module decl removed; peer fnmatch #30383).
            'nl_langinfo',
            // strxfrm(3) NestedJIT leaf (#30420) — whitelist strxfrm → strxfrm::call →
            // StringStrxfrm / JitStrxfrm libc leaf (always-on Module decl removed; peer nl_langinfo #30404).
            'strxfrm',
            // putenv(3) NestedJIT leaf (#29334) — whitelist putenv → putenv_::call →
            // JitEnv::putenvNestedLeaf / StringGetenv::invokePutenvNestedLeaf (kernel removed).
            'putenv',
            // Stat path always-helper NestedJIT leaves (#20742) — peer rename (#20603).
            'phpc_stat_mode_kernel',
            'phpc_access_kernel',
            // Hash crypto always-helper NestedJIT EVP leaves (#21026).
            'phpc_hash_crypto_hash',
            'phpc_hash_crypto_hmac',
            'phpc_hash_crypto_pbkdf2',
            'phpc_hash_crypto_hkdf' => true,
            default => false,
        };
    }

    public function recordExternalMethodStub(string $proxyName): void
    {
        $this->externalMethodStubs[strtolower($proxyName)] = true;
    }

    /**
     * Surface methods that lowered to a silent null because their class is not in this module (#579).
     *
     * {@see Call\ExternalMethod} turns such a call into `__value__writeNull` with no diagnostic, so a
     * module that is missing a class miscompiles quietly rather than failing to build. The record was
     * write-only until now; this makes it readable, which is what any split-module work needs in order
     * to tell "compiled into another unit" apart from "silently became null".
     *
     * PHP_COMPILER_REPORT_EXTERNAL_STUBS=1 logs them; PHP_COMPILER_FAIL_ON_EXTERNAL_STUBS=1 makes it
     * an error. Both are opt-in — some stubs are legitimate on bundles that intentionally exclude a
     * class, so this reports rather than assuming a defect.
     */
    public function reportExternalMethodStubs(): void
    {
        if ([] === $this->externalMethodStubs) {
            return;
        }
        $strict = '1' === getenv('PHP_COMPILER_FAIL_ON_EXTERNAL_STUBS');
        if (!$strict && '1' !== getenv('PHP_COMPILER_REPORT_EXTERNAL_STUBS')) {
            return;
        }

        $names = array_keys($this->externalMethodStubs);
        sort($names, SORT_STRING);
        $summary = sprintf(
            '%d method call(s) lowered to a silent null — class not in this module (#579): %s',
            count($names),
            implode(', ', array_slice($names, 0, 40)).(count($names) > 40 ? ', …' : '')
        );

        if ($strict) {
            throw new \RuntimeException('external method stubs: '.$summary);
        }
        if (\defined('STDERR') && \is_resource(STDERR)) {
            fwrite(STDERR, 'phpc: external method stubs — '.$summary."\n");
        }
    }

    /**
     * Whether a function name resolves to a builtin or user function in this compile unit (issue #1216).
     */
    public function functionIsRegistered(string $name): bool
    {
        $normalized = ltrim($name, '\\');
        $lc = strtolower($normalized);
        if (DomInstanceMethodJit::isDomInstanceMethodProxy($lc)) {
            DomInstanceMethodJit::ensureProxy($this, $lc);
        }
        if (SimpleXmlInstanceMethodJit::isSimpleXmlInstanceMethodProxy($lc)
            && UserScriptAotEnv::isActive()
        ) {
            SimpleXmlInstanceMethodJit::ensureProxy($this, $lc);
        }
        if (XmlReaderInstanceMethodJit::isXmlReaderInstanceMethodProxy($lc)
            && XmlReaderInstanceMethodJit::isUserScriptAot()
        ) {
            XmlReaderInstanceMethodJit::ensureProxy($this, $lc);
        }
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
        $this->registeredBuiltinLookup = null;
        $module->jitInit($this);
    }

    public function registerBuiltin(Builtin $builtin): void {
        $this->builtins[] = $builtin;
    }

    private function defineBuiltins(int $loadType): void {
        // Stale sg_* from a prior JITContext in the same PHP process breaks SessionDestroy::implement (#4415).
        SuperglobalInit::$globals = [];
        LibcExtern::register($this);
        LibcExtern::implementMcjitMemBodies($this);
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
            } elseif ($this->shouldUseBootstrapAotStandaloneBodies()) {
                $this->ensureBootstrapAotStandaloneBodies();
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
        // Iterator + getInfo/setInfo for thin AOT (#28707; php-src spl_observer.c).
        $this->functionProxies['splobjectstorage::rewind'] = new Call\SplObjectStorageMethod('rewind');
        $this->functionProxies['splobjectstorage::next'] = new Call\SplObjectStorageMethod('next');
        $this->functionProxies['splobjectstorage::valid'] = new Call\SplObjectStorageMethod('valid');
        $this->functionProxies['splobjectstorage::key'] = new Call\SplObjectStorageMethod('key');
        $this->functionProxies['splobjectstorage::current'] = new Call\SplObjectStorageMethod('current');
        $this->functionProxies['splobjectstorage::getinfo'] = new Call\SplObjectStorageMethod('getinfo');
        $this->functionProxies['splobjectstorage::setinfo'] = new Call\SplObjectStorageMethod('setinfo');
        // ArrayIterator / RecursiveArrayIterator — `__spl_ht` for thin AOT foreach (#26783, #26775).
        $this->functionProxies['arrayiterator::__construct'] = new Call\ArrayIteratorConstruct('ArrayIterator');
        $this->functionProxies['recursivearrayiterator::__construct'] = new Call\ArrayIteratorConstruct(
            'RecursiveArrayIterator'
        );
        // ArrayObject — same `__spl_ht` construct + count/ArrayAccess/getArrayCopy (#26823).
        $this->type->object->lookup('ArrayObject');
        $this->functionProxies['arrayobject::__construct'] = new Call\ArrayIteratorConstruct('ArrayObject');
        foreach (['count', 'append', 'getArrayCopy', 'offsetGet', 'offsetSet', 'offsetExists', 'offsetUnset', 'getIteratorClass', 'getIterator'] as $aoMethod) {
            $this->functionProxies['arrayobject::'.strtolower($aoMethod)] = new Call\ArrayObjectMethod($aoMethod);
        }
        // RecursiveIteratorIterator — flatten inner HT to LEAVES_ONLY `__spl_ht` (#26775).
        $this->functionProxies['recursiveiteratoriterator::__construct'] = new Call\RecursiveIteratorIteratorConstruct();
        // LimitIterator / AppendIterator / RegexIterator / CallbackFilterIterator — `__spl_ht` (#26825, #27259).
        $this->type->object->lookup('LimitIterator');
        $this->type->object->lookup('AppendIterator');
        $this->type->object->lookup('RegexIterator');
        $this->type->object->lookup('CallbackFilterIterator');
        $this->functionProxies['limititerator::__construct'] = new Call\LimitIteratorConstruct();
        $this->functionProxies['appenditerator::__construct'] = new Call\AppendIteratorMethod('__construct');
        $this->functionProxies['appenditerator::append'] = new Call\AppendIteratorMethod('append');
        $this->functionProxies['regexiterator::__construct'] = new Call\RegexIteratorConstruct();
        $this->functionProxies['callbackfilteriterator::__construct'] = new Call\CallbackFilterIteratorConstruct();
        $this->type->object->lookup('CachingIterator');
        $this->functionProxies['cachingiterator::__construct'] = new Call\CachingIteratorConstruct();
        $this->functionProxies['cachingiterator::getcache'] = new Call\CachingIteratorGetCache();
        // NoRewindIterator / InfiniteIterator — HT snapshot + Iterator protocol (#27583 / #27568).
        $this->type->object->lookup('NoRewindIterator');
        $this->type->object->lookup('InfiniteIterator');
        foreach (['__construct', 'rewind', 'valid', 'current', 'key', 'next'] as $nrMethod) {
            $this->functionProxies['norewinditerator::'.strtolower($nrMethod)] = new Call\SplHtPosIteratorMethod(
                $nrMethod,
                'NoRewindIterator',
                \PHPCompiler\VM\SplHtPosIteratorJitHelper::REWIND_NOOP,
                \PHPCompiler\VM\SplHtPosIteratorJitHelper::NEXT_STOP
            );
            $this->functionProxies['infiniteiterator::'.strtolower($nrMethod)] = new Call\SplHtPosIteratorMethod(
                $nrMethod,
                'InfiniteIterator',
                \PHPCompiler\VM\SplHtPosIteratorJitHelper::REWIND_RESET,
                \PHPCompiler\VM\SplHtPosIteratorJitHelper::NEXT_WRAP
            );
        }
        // EmptyIterator — always-invalid; current/key throw (#27582).
        $this->type->object->lookup('EmptyIterator');
        // Eager: get_class select-walk is frozen before method bodies compile (#27582).
        $this->type->object->lookup('BadMethodCallException');
        foreach (['__construct', 'rewind', 'valid', 'current', 'key', 'next'] as $eiMethod) {
            $this->functionProxies['emptyiterator::'.strtolower($eiMethod)] = new Call\EmptyIteratorMethod(
                $eiMethod
            );
        }
        // FilterIterator — HT snapshot + accept() fetch for user subclasses (#27565).
        $this->type->object->lookup('FilterIterator');
        foreach ([
            '__construct', 'rewind', 'valid', 'current', 'key', 'next', 'accept', 'getinneriterator',
        ] as $fiMethod) {
            $this->functionProxies['filteriterator::'.strtolower($fiMethod)] = new Call\FilterIteratorMethod(
                $fiMethod
            );
        }
        // ParentIterator / MultipleIterator / RecursiveTreeIterator — HT snapshot foreach (#27584).
        $this->type->object->lookup('ParentIterator');
        $this->type->object->lookup('MultipleIterator');
        $this->type->object->lookup('RecursiveTreeIterator');
        $this->functionProxies['parentiterator::__construct'] = new Call\ParentIteratorConstruct();
        $this->functionProxies['multipleiterator::__construct'] = new Call\MultipleIteratorMethod('__construct');
        $this->functionProxies['multipleiterator::attachiterator'] = new Call\MultipleIteratorMethod('attachIterator');
        $this->functionProxies['recursivetreeiterator::__construct'] = new Call\RecursiveTreeIteratorConstruct();
        // DirectoryIterator / FilesystemIterator / SplFileInfo — dir snapshot + Iterator (#27289).
        $this->type->object->lookup('SplFileInfo');
        $this->type->object->lookup('DirectoryIterator');
        $this->type->object->lookup('FilesystemIterator');
        foreach (['DirectoryIterator', 'FilesystemIterator'] as $diClass) {
            $diLc = strtolower($diClass);
            foreach ([
                '__construct', 'rewind', 'valid', 'current', 'key', 'next',
                'isDot', 'getFilename',
            ] as $diMethod) {
                $this->functionProxies[$diLc.'::'.strtolower($diMethod)] = new Call\DirectoryIteratorMethod(
                    $diMethod,
                    $diClass
                );
            }
        }
        $this->functionProxies['splfileinfo::getfilename'] = new Call\DirectoryIteratorMethod(
            'getFilename',
            'SplFileInfo'
        );
        // SplFileObject — line snapshot `__spl_ht` for foreach (#28709).
        $this->type->object->lookup('SplFileObject');
        $this->functionProxies['splfileobject::__construct'] = new Call\SplFileObjectMethod('__construct');
        // GlobIterator — glob snapshot + Iterator (#27422).
        $this->type->object->lookup('GlobIterator');
        foreach ([
            '__construct', 'rewind', 'valid', 'current', 'key', 'next',
            'getFilename', 'count',
        ] as $giMethod) {
            $this->functionProxies['globiterator::'.strtolower($giMethod)] = new Call\GlobIteratorMethod(
                $giMethod
            );
        }
        // SplHeap family — `__spl_heap` + Iterator protocol for thin AOT foreach (#26784).
        foreach ([
            'splmaxheap' => \PHPCompiler\ext\spl\SplHeapBuiltin::KIND_MAX,
            'splminheap' => \PHPCompiler\ext\spl\SplHeapBuiltin::KIND_MIN,
            'splheap' => \PHPCompiler\ext\spl\SplHeapBuiltin::KIND_USER,
        ] as $heapLc => $heapKind) {
            foreach ([
                '__construct', 'insert', 'extract', 'top', 'count',
                'rewind', 'valid', 'current', 'key', 'next',
            ] as $heapMethod) {
                $this->functionProxies[$heapLc.'::'.$heapMethod] = new Call\SplHeapMethod($heapMethod, $heapKind);
            }
        }
        // SplPriorityQueue — parallel data/priority HTs + Iterator foreach (#27277, #28708).
        $this->type->object->lookup('SplPriorityQueue');
        foreach ([
            '__construct', 'insert', 'extract', 'top', 'count',
            'rewind', 'valid', 'current', 'key', 'next',
        ] as $pqMethod) {
            $this->functionProxies['splpriorityqueue::'.$pqMethod] = new Call\SplPriorityQueueMethod($pqMethod);
        }
        // SplDoublyLinkedList / SplQueue / SplStack — `__spl_ht` deque (#26790, #27311, #28704).
        foreach ([
            'spldoublylinkedlist' => 'SplDoublyLinkedList',
            'splqueue' => 'SplQueue',
            'splstack' => 'SplStack',
        ] as $dllLc => $dllClass) {
            $dllMethods = ['__construct', 'push', 'pop', 'shift', 'unshift', 'top', 'bottom'];
            if ('splqueue' === $dllLc) {
                $dllMethods = array_merge($dllMethods, ['enqueue', 'dequeue']);
            }
            foreach ($dllMethods as $dllMethod) {
                $this->functionProxies[$dllLc.'::'.$dllMethod] = new Call\SplDllistMethod($dllMethod, $dllClass);
            }
        }
        // SplFixedArray — `__spl_ht` + fromArray / count / ArrayAccess / foreach (#26793, #28640).
        // Seed the class so count()/ArrayAccess candidates see Countable before first use.
        $this->type->object->lookup('SplFixedArray');
        foreach ([
            '__construct', 'fromArray', 'count', 'getSize',
            'offsetGet', 'offsetSet', 'offsetExists', 'offsetUnset',
        ] as $sfaMethod) {
            $this->functionProxies['splfixedarray::'.strtolower($sfaMethod)] = new Call\SplFixedArrayMethod($sfaMethod);
        }

        $this->functionProxies['weakreference::create'] = new Call\WeakReferenceCreate();
        $this->functionProxies['weakreference::get'] = new Call\WeakReferenceGet();
        $this->functionProxies['weakmap::offsetset'] = new Call\WeakMapMethod('offsetset');
        $this->functionProxies['weakmap::offsetget'] = new Call\WeakMapMethod('offsetget');
        $this->functionProxies['weakmap::offsetexists'] = new Call\WeakMapMethod('offsetexists');
        $this->functionProxies['weakmap::offsetunset'] = new Call\WeakMapMethod('offsetunset');
        $this->functionProxies['weakmap::count'] = new Call\WeakMapMethod('count');

        // PhpToken OOP API — user-script AOT (#27263 / #6794).
        $this->functionProxies['phptoken::__construct'] = new Call\PhpTokenConstruct();
        $this->functionProxies['phptoken::tokenize'] = new Call\PhpTokenTokenize();
        $this->functionProxies['phptoken::gettokenname'] = new Call\PhpTokenGetTokenName();

        if (CompilerVersion::supportsBcmath()) {
            $this->functionProxies['bcmath\number::__construct'] = new Call\BcMathNumberConstruct();
            $this->functionProxies['bcmath\number::__tostring'] = new Call\BcMathNumberToString();
            // User-script AOT: unbound methods were silent null (#579 / #26803).
            foreach (['add', 'mul', 'compare'] as $bcMethod) {
                $this->functionProxies['bcmath\number::'.$bcMethod] = new Call\BcMathNumberMethod($bcMethod);
            }
        }

        $this->functionProxies['reflectionclass::__construct'] = new Call\ReflectionClassConstruct();
        $this->functionProxies['reflectionobject::__construct'] = new Call\ReflectionObjectConstruct();
        $this->functionProxies['reflectionclass::getname'] = new Call\ReflectionClassGetName();
        $this->functionProxies['reflectionclass::getshortname'] = new Call\ReflectionClassGetShortName();
        $this->functionProxies['reflectionclass::getattributes'] = new Call\ReflectionClassGetAttributes();
        $this->functionProxies['reflectionclass::getmethod'] = new Call\ReflectionClassGetMethod();
        $this->functionProxies['reflectionclass::getreflectionconstant'] = new Call\ReflectionClassGetReflectionConstant();
        $this->functionProxies['reflectionclass::isfinal'] = new Call\ReflectionClassIsFinal();
        $this->functionProxies['reflectionclass::isiterateable'] = new Call\ReflectionClassIsIterateable();
        $this->functionProxies['reflectionclass::isiterable'] = new Call\ReflectionClassIsIterateable();
        if (CompilerVersion::supportsLazyObjectFactories()) {
            $this->functionProxies['reflectionclass::newlazyproxy'] = new Call\ReflectionClassNewLazyProxy();
            $this->functionProxies['reflectionclass::newlazyghost'] = new Call\ReflectionClassNewLazyGhost();
            // ReflectionClass::createLazyGhost/Proxy are phantoms vs php-src (#28516).
        }
        $this->functionProxies['reflectionproperty::__construct'] = new Call\ReflectionPropertyConstruct();
        $this->functionProxies['reflectionproperty::getattributes'] = new Call\ReflectionPropertyGetAttributes();
        $this->functionProxies['reflectionproperty::isfinal'] = new Call\ReflectionPropertyIsFinal();
        $this->functionProxies['reflectionproperty::isvirtual'] = new Call\ReflectionPropertyIsVirtual();
        $this->functionProxies['reflectionproperty::getrawvalue'] = new Call\ReflectionPropertyGetRawValue();
        $this->functionProxies['reflectionproperty::setrawvalue'] = new Call\ReflectionPropertySetRawValue();
        if (CompilerVersion::supportsReflectionPropertyGetMangledName()) {
            $this->functionProxies['reflectionproperty::getmangledname'] = new Call\ReflectionPropertyGetMangledName();
        }

        $this->functionProxies['reflectionconstant::__construct'] = new Call\ReflectionConstantConstruct();
        $this->functionProxies['reflectionconstant::getname'] = new Call\ReflectionConstantGetName();
        $this->functionProxies['reflectionconstant::getvalue'] = new Call\ReflectionConstantGetValue();
        // PHP 8.5+ only — withhold on ≤8.4 profiles (#28157).
        if (CompilerVersion::advertisesReflectionConstantGetAttributes()) {
            $this->functionProxies['reflectionconstant::getattributes'] = new Call\ReflectionConstantGetAttributes();
        }
        // ReflectionClassConstant::$class+$name layout — not ReflectionConstant::$name+$constant (#25963).
        $this->functionProxies['reflectionclassconstant::getattributes'] = new Call\ReflectionClassConstantGetAttributes();
        $this->functionProxies['reflectionmethod::getattributes'] = new Call\ReflectionMethodGetAttributes();
        $this->functionProxies['reflectionfunction::__construct'] = new Call\ReflectionFunctionConstruct();
        $this->functionProxies['reflectionfunction::getname'] = new Call\ReflectionFunctionGetName();
        $this->functionProxies['reflectionfunction::isvariadic'] = new Call\ReflectionFunctionIsVariadic();
        if (CompilerVersion::supportsReflectionParameterIsSensitiveParameter()) {
            $this->functionProxies['reflectionparameter::issensitiveparameter'] = new Call\ReflectionParameterIsSensitiveParameter();
        }
        if (CompilerVersion::supportsReflectionFunctionGetNamedArguments()) {
            $this->functionProxies['reflectionfunction::getnamedarguments'] = new Call\ReflectionFunctionGetNamedArguments();
            $this->functionProxies['reflectionmethod::getnamedarguments'] = new Call\ReflectionMethodGetNamedArguments();
        }
        $this->functionProxies['reflectionattribute::getname'] = new Call\ReflectionAttributeGetName();
        $this->functionProxies['reflectionattribute::gettarget'] = new Call\ReflectionAttributeGetTarget();
        $this->functionProxies['reflectionattribute::newinstance'] = new Call\ReflectionAttributeNewInstance();
        $this->functionProxies['reflectionenum::__construct'] = new Call\ReflectionEnumConstruct();
        $this->functionProxies['reflectionenum::getname'] = new Call\ReflectionEnumGetName();
        $this->functionProxies['reflectionenum::hascase'] = new Call\ReflectionEnumHasCase();
        $this->functionProxies['reflectionenum::getcase'] = new Call\ReflectionEnumGetCase();
        $this->functionProxies['reflectionenum::getcases'] = new Call\ReflectionEnumGetCases();
        $this->functionProxies['reflectionenum::isbacked'] = new Call\ReflectionEnumIsBacked();
        $this->functionProxies['reflectionenum::getbackingtype'] = new Call\ReflectionEnumGetBackingType();
        $this->functionProxies['reflectionenumunitcase::getname'] = new Call\ReflectionEnumUnitCaseGetName();
        $this->functionProxies['reflectionenumbackedcase::getname'] = new Call\ReflectionEnumUnitCaseGetName();
        $unitCaseGetValue = new Call\ReflectionEnumUnitCaseGetValue();
        $this->functionProxies['reflectionenumunitcase::getvalue'] = $unitCaseGetValue;
        $this->functionProxies['reflectionenumbackedcase::getvalue'] = $unitCaseGetValue;
        $this->functionProxies['reflectionnamedtype::getname'] = new Call\ReflectionNamedTypeGetName();
        $this->functionProxies['exception::getmessage'] = new Call\ExceptionGetMessage();
        $this->functionProxies['exception::getcode'] = new Call\ExceptionGetCode();
        $exceptionToString = new Call\ExceptionToString();
        $exceptionGetTrace = new Call\ExceptionGetTrace();
        $exceptionGetTraceAsString = new Call\ExceptionGetTraceAsString();
        $this->functionProxies['exception::__tostring'] = $exceptionToString;
        $this->functionProxies['exception::gettrace'] = $exceptionGetTrace;
        $this->functionProxies['exception::gettraceasstring'] = $exceptionGetTraceAsString;
        // catch (Throwable $e) resolves methods on the interface name (#27333).
        $this->functionProxies['throwable::__tostring'] = $exceptionToString;
        $this->functionProxies['throwable::gettrace'] = $exceptionGetTrace;
        $this->functionProxies['throwable::gettraceasstring'] = $exceptionGetTraceAsString;
        // Per-class ctor so TypeError wire text + $previous arg index match Zend (#28798).
        foreach (\PHPCompiler\ext\standard\ThrowableManifest::registrationOrder() as $throwableName) {
            if (!\PHPCompiler\ext\standard\ThrowableManifest::isAdvertised($throwableName)) {
                continue;
            }
            $lc = \PHPCompiler\ext\standard\ThrowableManifest::lcKey($throwableName);
            // ErrorException::__construct(..., $previous) is Argument #6; others #3.
            $prevArg = 'errorexception' === $lc ? 6 : 3;
            $this->functionProxies[$lc.'::__construct'] = new Call\ExceptionConstruct(
                $throwableName,
                $prevArg
            );
            // Throwable::__toString / getTrace / getTraceAsString — user-script AOT (#26796, #27333).
            $this->functionProxies[$lc.'::__tostring'] = $exceptionToString;
            $this->functionProxies[$lc.'::gettrace'] = $exceptionGetTrace;
            $this->functionProxies[$lc.'::gettraceasstring'] = $exceptionGetTraceAsString;
        }
        // Alias getMessage/getCode for Error family (same prop layout).
        $this->functionProxies['error::getmessage'] = $this->functionProxies['exception::getmessage'];
        $this->functionProxies['error::getcode'] = $this->functionProxies['exception::getcode'];
        $this->functionProxies['error::__tostring'] = $exceptionToString;
        $this->functionProxies['error::gettrace'] = $exceptionGetTrace;
        $this->functionProxies['error::gettraceasstring'] = $exceptionGetTraceAsString;

        FiberHelper::registerJitMethods($this);
        GeneratorHelper::registerJitMethods($this);
        ClosureBindHelper::registerJitMethods($this);
        // DateTime / DateInterval / DatePeriod ctors — thin user-script AOT (#26772).
        $this->functionProxies['datetime::__construct'] = new Call\DateTimeConstruct();
        $this->functionProxies['datetimeimmutable::__construct'] = new Call\DateTimeImmutableConstruct();
        $this->functionProxies['datetimezone::__construct'] = new Call\DateTimeZoneConstruct();
        $this->functionProxies['dateinterval::__construct'] = new Call\DateIntervalConstruct();
        $this->functionProxies['dateperiod::__construct'] = new Call\DatePeriodConstruct();
        if (CompilerVersion::supportsDatePeriodCreateFromISO8601String()) {
            $this->functionProxies['dateperiod::createfromiso8601string'] = new Call\DatePeriodCreateFromISO8601String();
            foreach (['rewind', 'valid', 'current', 'key', 'next'] as $dpIterMethod) {
                $this->functionProxies['dateperiod::'.$dpIterMethod] = new Call\DatePeriodIteratorMethod($dpIterMethod);
            }
        }
        // Accessors — always (not gated on ISO8601); avoid ExternalMethod null stubs (#27572).
        foreach (['getstartdate', 'getenddate', 'getdateinterval', 'getrecurrences'] as $dpAcc) {
            $this->functionProxies['dateperiod::'.$dpAcc] = new Call\DatePeriodAccessorMethod($dpAcc);
        }
        $this->functionProxies['datetime::format'] = new Call\DateTimeFormat();
        $this->functionProxies['datetimeimmutable::format'] = new Call\DateTimeFormat();
        // Wire class static factories to date_create*_from_format JIT (#26788 / #6172).
        $this->functionProxies['datetime::createfromformat'] = new Call\DateTimeCreateFromFormat(false);
        $this->functionProxies['datetimeimmutable::createfromformat'] = new Call\DateTimeCreateFromFormat(true);
        // PHP 8.4+ createFromTimestamp — avoid ExternalMethod null stub abort on thin AOT (#26936).
        if (CompilerVersion::supportsDateTimeCreateFromTimestamp()) {
            $this->functionProxies['datetime::createfromtimestamp'] = new Call\DateTimeCreateFromTimestamp(false);
            $this->functionProxies['datetimeimmutable::createfromtimestamp'] = new Call\DateTimeCreateFromTimestamp(true);
        }
        // PHP 8.4+ get/setMicrosecond — avoid ExternalMethod silent NULL on thin AOT (#26938).
        if (CompilerVersion::supportsDateTimeMicrosecond()) {
            $this->functionProxies['datetime::getmicrosecond'] = new Call\DateTimeGetMicrosecond(false);
            $this->functionProxies['datetimeimmutable::getmicrosecond'] = new Call\DateTimeGetMicrosecond(true);
            $this->functionProxies['datetime::setmicrosecond'] = new Call\DateTimeSetMicrosecond(false);
            $this->functionProxies['datetimeimmutable::setmicrosecond'] = new Call\DateTimeSetMicrosecond(true);
        }
        // php-src stub $datetime — InternalArgInfo still says time (#24589).
        $this->functionProxies['dateinterval::createfromdatestring'] = new Call\DateIntervalCreateFromDateString();
        // Mutable setTimezone — thin user-script AOT property write (#22824).
        $this->functionProxies['datetime::settimezone'] = new Call\DateTimeSetTimezone(false);
        // Immutable: allocate+copy (not cloneObject) for MCJIT. Thin user-script AOT still
        // hits "basic block has no parent" inside Object_::allocate / NestedJIT ensureLinked
        // (same as `new DateTimeImmutable` under HELPER_RUNTIME_O=0). VM Builtin covers
        // php bin/vm.php; register proxy for MCJIT / full-init only (#22824).
        if (!UserScriptAotEnv::isActive()) {
            $this->functionProxies['datetimeimmutable::settimezone'] = new Call\DateTimeSetTimezone(true);
        }
        // modify() — avoid ExternalMethod null stub segfault after chained format() (#26789).
        // Immutable allocate+copy is thin-AOT-safe after DatePeriod foreach (#26772).
        $this->functionProxies['datetime::modify'] = new Call\DateTimeModify(false);
        $this->functionProxies['datetimeimmutable::modify'] = new Call\DateTimeModify(true);
        // DateTime::diff — compile-time DateInterval materialize (#27309).
        $this->functionProxies['datetime::diff'] = new Call\DateTimeDiff();
        $this->functionProxies['datetimeimmutable::diff'] = new Call\DateTimeDiff();
        // DateTimeZone::getTransitions — compile-time materialize (peer timezone_transitions_get) (#26799).
        $this->functionProxies['datetimezone::gettransitions'] = new Call\DateTimeZoneGetTransitions();
        // DateTimeZone::getName — avoid ExternalMethod silent NULL on thin AOT (#27307).
        $this->functionProxies['datetimezone::getname'] = new Call\DateTimeZoneGetName();
        // DateTimeZone::getOffset — avoid ExternalMethod silent NULL on thin AOT (#27308).
        $this->functionProxies['datetimezone::getoffset'] = new Call\DateTimeZoneGetOffset();
        // DateTimeZone::listIdentifiers — avoid ExternalMethod silent NULL on thin AOT (#29735).
        $this->functionProxies['datetimezone::listidentifiers'] = new Call\DateTimeZoneListIdentifiers();
        // Locale::canonicalize — avoid ExternalMethod null stub on user-script AOT (#20760).
        $this->functionProxies['locale::canonicalize'] = new \PHPCompiler\ext\intl\LocaleCanonicalize();
        // Locale::acceptFromHttp — avoid ExternalMethod silent NULL on thin AOT (#28656).
        $this->functionProxies['locale::acceptfromhttp'] = new \PHPCompiler\ext\intl\LocaleAcceptFromHttp();
        // NumberFormatter::create / format — avoid ExternalMethod silent NULL on thin AOT (#27385, #28648).
        $this->functionProxies['numberformatter::create'] = new Call\NumberFormatterCreate();
        $this->functionProxies['numberformatter::format'] = new Call\NumberFormatterFormat();
        // IntlDateFormatter::create / format — avoid ExternalMethod silent NULL on thin AOT (#27361).
        $this->functionProxies['intldateformatter::create'] = new Call\IntlDateFormatterCreate();
        $this->functionProxies['intldateformatter::format'] = new Call\IntlDateFormatterFormat();
        // Collator::compare — avoid ExternalMethod silent NULL on thin AOT (#28649).
        $this->functionProxies['collator::compare'] = new Call\CollatorCompare();
        // Normalizer::normalize — avoid ExternalMethod silent NULL / segfault on thin AOT (#28654).
        $this->functionProxies['normalizer::normalize'] = new Call\NormalizerNormalize();
        // MessageFormatter::__construct / format — avoid ExternalMethod silent NULL on thin AOT (#28655).
        $this->functionProxies['messageformatter::__construct'] = new Call\MessageFormatterConstruct();
        $this->functionProxies['messageformatter::format'] = new Call\MessageFormatterFormat();
        // Transliterator::create / transliterate — avoid ExternalMethod silent NULL on thin AOT (#28657).
        $this->functionProxies['transliterator::create'] = new Call\TransliteratorCreate();
        $this->functionProxies['transliterator::transliterate'] = new Call\TransliteratorTransliterate();
        // finfo::__construct / finfo::file / finfo::buffer — thin AOT MIME sniff (#27196, #28660).
        $this->functionProxies['finfo::__construct'] = new Call\FinfoConstruct();
        $this->functionProxies['finfo::file'] = new Call\FinfoFile();
        $this->functionProxies['finfo::buffer'] = new Call\FinfoBuffer();
        // PDO — avoid ExternalMethod silent NULL / fake connect (#27619).
        $this->functionProxies['pdo::__construct'] = new Call\PdoConstruct();
        $this->functionProxies['pdo::getavailabledrivers'] = new Call\PdoGetAvailableDrivers();
        $this->functionProxies['pdo::quote'] = new Call\PdoQuote();
        // Dom\XMLDocument / Dom\HTMLDocument::createFromString — avoid ExternalMethod silent NULL (#27108, #27300).
        if (CompilerVersion::supportsDomLivingStandardNamespace()) {
            $this->functionProxies['dom\\xmldocument::createfromstring'] = new Call\DomXmlDocumentCreateFromString();
            $this->functionProxies['dom\\htmldocument::createfromstring'] = new Call\DomHtmlDocumentCreateFromString();
        }
        // XMLReader::XML / fromString / read — avoid ExternalMethod silent NULL on thin AOT (#27299, #28670).
        // XML() exists on all profiles; fromString is PROFILE≥8.4 only.
        XmlReaderInstanceMethodJit::ensureProxy($this, 'xmlreader::xml');
        XmlReaderInstanceMethodJit::ensureProxy($this, 'xmlreader::read');
        if (CompilerVersion::supportsXmlReaderFactories()) {
            XmlReaderInstanceMethodJit::ensureProxy($this, 'xmlreader::fromstring');
        }
        if (CompilerVersion::supportsDomTokenList()) {
            DomInstanceMethodJit::registerKnownProxies($this);
        }
    }

    /** User examples or bootstrap-aot-link: thin standalone main without session/header reset LLVM (#13571, #14459). */
    public function isThinStandaloneAotMain(): bool
    {
        return $this->isUserScriptAot() || $this->shouldUseBootstrapAotStandaloneBodies();
    }

    /**
     * Nested *JitHelper compile under user-script standalone: temporarily clear
     * PHP_COMPILER_AOT_USER_SCRIPT so helpers get full NestedJIT (#15407, #16734, #20246).
     *
     * Former {@see UserScriptAotDeferNestedJit::shouldDefer} — keep STANDALONE + user-script
     * only (do not widen to bootstrap-aot-link thin path).
     */
    public function shouldClearUserScriptEnvForNestedHelperCompile(): bool
    {
        return Builtin::LOAD_TYPE_STANDALONE === $this->loadType && $this->isUserScriptAot();
    }

    /**
     * After preg prelink on a temporary full-init Context, restore user-script standalone bodies (#16075).
     */
    public function retrofitUserScriptStandaloneAfterPregPrelink(): void
    {
        if (Builtin::LOAD_TYPE_STANDALONE !== $this->loadType || !$this->isUserScriptAot()) {
            return;
        }
        $this->ensureMinimalUserStandaloneBodies();
    }

    public function isUserScriptAot(): bool
    {
        return UserScriptAotEnv::isActive();
    }

    /** bootstrap-aot-link: thin LLVM during Context init — defer nested php-in-PHP JIT (#14459, #13245). */
    private function shouldUseBootstrapAotStandaloneBodies(): bool
    {
        $bootstrapLink = getenv('PHP_COMPILER_BOOTSTRAP_AOT_LINK');
        if ('1' === $bootstrapLink || 'true' === strtolower((string) $bootstrapLink)) {
            return true;
        }

        return false;
    }

    /** examples/000–009 user-script AOT: thin LLVM bridges only — no nested-JIT stdlib during init (#13571). */
    private function ensureMinimalUserStandaloneBodies(): void
    {
        Builtin\StringHtmlspecialchars::ensureStandaloneBodies($this);
        Builtin\HtmlEntitiesJit::ensureStandaloneBodies($this);
        Builtin\StringHtmlspecialcharsDecode::ensureStandaloneBodies($this);
        ExceptionBridge::ensureStandaloneBodies($this);
        ErrorBridge::ensureStandaloneBodies($this);
        Builtin\ErrorHandlerJitRuntime::ensureStandaloneBodies($this);
        Builtin\ExceptionHandlerJitRuntime::ensureStandaloneBodies($this);
        if (!$this->isUserScriptAot()) {
            // NestedJIT StreamLifecycle/StreamRead/StreamBucket helpers during thin init
            // (peer StreamIo #20943 / #20966 / #20982 / #20998).
            Builtin\StreamLifecycleRuntime::ensureLinked($this);
            Builtin\StreamReadRuntime::ensureLinked($this);
            Builtin\StreamBucket::ensureLinked($this);
        }
        Builtin\AssertFail::ensureStandaloneBodies($this);
        Builtin\JitReturnPending::ensureStandaloneBodies($this);
        Builtin\ObOutputRuntime::ensureLinked($this);
        Builtin\StringTriggerError::ensureStandaloneBodies($this);
        Builtin\StringRandomBytes::implement($this);
        Builtin\ProgressNoteRuntime::ensureStandaloneBodies($this);
        Builtin\GcCollectCyclesRuntime::ensureStandaloneBodies($this);
        Builtin\LastErrorRuntime::ensureStandaloneBodies($this);
        Builtin\StringUtf8Latin1::ensureStandaloneBodies($this);
        Builtin\RewriteVarsRuntime::ensureStandaloneBodies($this);
        Builtin\DefineRuntime::ensureStandaloneBodies($this);
        Builtin\StringStrContains::ensureStandaloneBodies($this);
        Builtin\StatPathRuntime::ensureStandaloneBodies($this);
        Builtin\StringFileGetContents::ensureStandaloneBodies($this);
        Builtin\StringHashCrypto::ensureStandaloneBodies($this);
        Builtin\MbNumericEntity::ensureStandaloneBodies($this);
        Builtin\StringReadfile::ensureStandaloneBodies($this);
        Builtin\StringBin2hex::ensureStandaloneBodies($this);
        Builtin\StringAddslashes::ensureStandaloneBodies($this);
        Builtin\StringStripslashes::ensureStandaloneBodies($this);
        Builtin\StringFilePutContents::ensureStandaloneBodies($this);
        Builtin\SuperglobalNameRuntime::ensureLinked($this);
        Builtin\EnvLocalRuntime::ensureLinked($this);
        // Thin AOT: NestedJIT IniJitHelper for Type::register __compiler_ini_* shells (#21200).
        Builtin\IniRuntime::ensureLinked($this);
        // CLI argv: NestedJIT CliArgvJitHelper during thin init (peer IncludePath #20877 / #20904)
        // — must precede {main} $argc/$argv lowering (compileToFile stubs are too late).
        Builtin\CliArgvRuntime::ensureStandaloneBodies($this);
        if (DomInstanceMethodJit::shouldDeferToVmClassMethodLowering()) {
            Builtin\DomStandaloneAotInitRuntime::ensureLinked($this);
        } elseif (CompilerVersion::supportsDomTokenList()) {
            Builtin\DomInstanceMethodRuntime::ensureLinked($this);
        }
    }

    /** bootstrap-aot-link fixtures: minimal init + CLI argv / superglobal refresh for standalone main (#14459). */
    private function ensureBootstrapAotStandaloneBodies(): void
    {
        $this->ensureMinimalUserStandaloneBodies();
        Builtin\EnvLocalRuntime::ensureBootstrapAotStubLinked($this);
        Builtin\CliArgvRuntime::ensureStandaloneBodies($this);
        Builtin\SuperglobalRefreshRuntime::ensureStandaloneBodies($this);
    }

    private function ensureFullStandaloneBodies(): void
    {
        Builtin\StreamIoRuntime::beginStandaloneInitPhase();
        try {
            ExceptionBridge::ensureStandaloneBodies($this);
            ErrorBridge::ensureStandaloneBodies($this);
            // NestedJIT StreamLifecycle/StreamRead helpers during full standalone init (#20966 / #20982).
            Builtin\StreamLifecycleRuntime::ensureLinked($this);
            Builtin\StreamReadRuntime::ensureLinked($this);
            Builtin\AssertFail::ensureStandaloneBodies($this);
            Builtin\AssertOptionsRuntime::ensureStandaloneBodies($this);
            Builtin\JitReturnPending::ensureStandaloneBodies($this);
            Builtin\ObOutputRuntime::ensureLinked($this);
            Builtin\ValueEchoRuntime::ensureLinked($this);
            Builtin\CliArgvRuntime::ensureStandaloneBodies($this);
            // Nested-JIT string helpers: lazy via ensureLinked during spine/thin init (#14472, #20571).
            // Gate on thin-standalone + init-phase only — not the broad StreamIo M3 defer bag (#20553).
            if (!$this->isThinStandaloneAotMain() && !Builtin\StreamIoRuntime::isStandaloneInitPhase()) {
                Builtin\StringSoundex::ensureStandaloneBodies($this);
                Builtin\StringQuotemeta::ensureStandaloneBodies($this);
                Builtin\StringPregQuote::ensureStandaloneBodies($this);
                Builtin\StringNl2br::ensureStandaloneBodies($this);
                Builtin\StringUcwords::ensureStandaloneBodies($this);
                Builtin\StringMetaphone::ensureStandaloneBodies($this);
                Builtin\StringWordwrap::ensureStandaloneBodies($this);
                Builtin\MbNumericEntity::ensureStandaloneBodies($this);
                Builtin\StringBin2hex::ensureStandaloneBodies($this);
                Builtin\StringBase64Encode::ensureStandaloneBodies($this);
                Builtin\StringBase64Decode::ensureStandaloneBodies($this);
                Builtin\StringStrrev::ensureStandaloneBodies($this);
                Builtin\StringStrRepeat::ensureStandaloneBodies($this);
                Builtin\StringStrPad::ensureStandaloneBodies($this);
                Builtin\StringStrRot13::ensureStandaloneBodies($this);
                Builtin\StringUniqid::ensureStandaloneBodies($this);
                Builtin\StringChunkSplit::ensureStandaloneBodies($this);
                Builtin\StringGraphemeStrSplit::ensureStandaloneBodies($this);
                Builtin\StringHex2bin::ensureStandaloneBodies($this);
                Builtin\StringLevenshtein::ensureStandaloneBodies($this);
                Builtin\StringSubstrCount::ensureStandaloneBodies($this);
                Builtin\StringCountChars::ensureStandaloneBodies($this);
                Builtin\StringNCompare::ensureStandaloneBodies($this);
                Builtin\StringStrWordCount::ensureStandaloneBodies($this);
                Builtin\StringStripTags::ensureStandaloneBodies($this);
                Builtin\StringStrtr::ensureStandaloneBodies($this);
                Builtin\StringParseStr::ensureStandaloneBodies($this);
            }
            Builtin\StringFormat::ensureStandaloneBodies($this);
            // Skip-bundle inventory compile_driver (#23970): bind phpc_str_replace before
            // NestedJIT includes; HelperRuntimeCache supplies the TU when enabled.
            if (\PHPCompiler\AOT\HelperRuntimeCache::enabled()) {
                Builtin\StringStrReplace::ensureStandaloneBodies($this);
            }
            Builtin\StringJsonEncode::ensureStandaloneBodies($this);
            Builtin\StringJsonDecode::ensureStandaloneBodies($this);
            Builtin\StringTriggerError::ensureStandaloneBodies($this);
            Builtin\StringRandomBytes::implement($this);
            Builtin\ScalarDimFetchRuntime::ensureStandaloneBodies($this);
            Builtin\StringOffsetRuntime::ensureStandaloneBodies($this);
            // UndefinedVariableRuntime: ensureLinked only — emitWarningForName uses __compiler_trigger_error
            // (StringTriggerError already linked above; avoid duplicate standalone bodies — #10524).
            // NestedJIT StreamFilterJitHelper during full standalone init (#21041 / peer #20998).
            Builtin\StreamFilter::ensureLinked($this);
            Builtin\GcToggleRuntime::ensureStandaloneBodies($this);
            Builtin\FunctionStaticRuntime::ensureStandaloneBodies($this);
            Builtin\GcCollectCyclesRuntime::ensureStandaloneBodies($this);
            Builtin\ProgressNoteRuntime::ensureStandaloneBodies($this);
            Builtin\LastErrorRuntime::ensureStandaloneBodies($this);
            Builtin\StringUtf8Latin1::ensureStandaloneBodies($this);
            Builtin\RewriteVarsRuntime::ensureStandaloneBodies($this);
            Builtin\DefineRuntime::ensureStandaloneBodies($this);
            Builtin\SuperglobalRefreshRuntime::ensureStandaloneBodies($this);
            Builtin\SuperglobalNameRuntime::ensureLinked($this);
            Builtin\StringStrspn::ensureStandaloneBodies($this);
            // BootstrapCompileSmokeM3Emit / inventory argv {main} calls __compiler_file_get_contents (#15604).
            Builtin\StringFileGetContents::ensureStandaloneBodies($this);
            Builtin\StringReadfile::ensureStandaloneBodies($this);
            Builtin\TokenGetAll::ensureStandaloneBodies($this);
            Builtin\Highlight::ensureStandaloneBodies($this);
            Builtin\Hebrev::ensureStandaloneBodies($this);
            Builtin\Hebrevc::ensureStandaloneBodies($this);
            \PHPCompiler\ext\standard\JitStreamBucketKernel::ensureStandaloneBodies($this);
        } finally {
            Builtin\StreamIoRuntime::endStandaloneInitPhase();
        }
        // After init-phase: NestedJIT GetenvJitHelper (always-helper, #20156) — skipped during
        // standaloneInitPhase so peer stream kernels keep thin inventory stubs (#20576 / #20553).
        Builtin\StringGetenv::ensureStandaloneBodies($this);
        Builtin\StringGetenvAll::ensureStandaloneBodies($this);
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

        // Silent-null method lowerings are invisible without this (#579); opt-in via env.
        $this->reportExternalMethodStubs();

        if (Builtin::LOAD_TYPE_STANDALONE === $this->loadType && $this->isThinStandaloneAotMain()) {
            Builtin\CliArgvRuntime::ensureStandaloneBodies($this);
            Builtin\IniRuntime::ensureLinked($this);
            Builtin\SuperglobalRefreshRuntime::ensureUserScriptRefreshEmit($this);
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
                // Thin user-script AOT still needs pending Error clear/abort for final/readonly
                // property writes (#23665, #3149). Session/header resets stay full-init only.
                if (!$this->isThinStandaloneAotMain()) {
                    $emitInStandaloneMain(fn () => Builtin\HttpResponseCode::emitResetForStandaloneMain($this));
                    $emitInStandaloneMain(fn () => Builtin\SessionId::emitResetForStandaloneMain($this));
                    $emitInStandaloneMain(fn () => Builtin\SessionName::emitResetForStandaloneMain($this));
                    $emitInStandaloneMain(fn () => Builtin\SessionModuleName::emitResetForStandaloneMain($this));
                    $emitInStandaloneMain(fn () => Builtin\PendingHeaders::emitResetForStandaloneMain($this));
                }
                $emitInStandaloneMain(fn () => $this->builder->call($this->lookupFunction('__superglobals__refresh')));
                if (!$this->isThinStandaloneAotMain()) {
                    $emitInStandaloneMain(fn () => Builtin\JitThrow::registerDeclarations($this));
                    $emitInStandaloneMain(fn () => $this->builder->call($this->lookupFunction('phpc_jit_clear_throw_pending')));
                    $emitInStandaloneMain(fn () => Builtin\JitReturnPending::registerDeclarations($this));
                    $emitInStandaloneMain(fn () => $this->builder->call($this->lookupFunction('phpc_jit_clear_return_pending')));
                } else {
                    // Thin path: still clear Error/Readonly pending buffers (#23665).
                    $emitInStandaloneMain(fn () => ErrorBridge::registerDeclarations($this));
                    $emitInStandaloneMain(fn () => ErrorBridge::ensureLinked($this));
                }
                $emitInStandaloneMain(fn () => ErrorBridge::emitClearForStandaloneMain($this));
                if (!$this->isThinStandaloneAotMain()) {
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
            if (Builtin::LOAD_TYPE_STANDALONE === $this->loadType) {
                // Always abort pending Errors after user script — thin AOT previously skipped this
                // and silently no-op'd final/readonly writes (#23665, readonly_property_write AOT).
                $emitInStandaloneMain(fn () => ErrorBridge::registerDeclarations($this));
                $emitInStandaloneMain(fn () => ErrorBridge::ensureLinked($this));
                $emitInStandaloneMain(fn () => ErrorBridge::emitAbortIfPendingForStandaloneMain($this));
                // Thin AOT: still flush OB when stack was linked (URL-Rewriter endAll, #27566).
                // emitEndAllForStandalone no-ops unless __phpc_ob_end_all has a body (#13571).
                $emitInStandaloneMain(fn () => Builtin\ObOutput::emitEndAllForStandalone($this));
                if (!$this->isThinStandaloneAotMain()) {
                    $emitInStandaloneMain(fn () => ExceptionBridge::emitAbortIfPendingForStandaloneMain($this));
                    $emitInStandaloneMain(fn () => Builtin\PendingHeaders::emitFlushForStandalone($this));
                }
            }
            if (!$this->isThinStandaloneAotMain()) {
                // User __destruct before __shutdown__ frees compile-time strings / sg_* (#4013).
                $emitInStandaloneMain(fn () => $this->type->object->emitShutdownDestructorsCall());
            }
            $emitInStandaloneMain(fn () => $this->builder->call($this->shutdownFunc));
            $emitInStandaloneMain(fn () => $this->builder->returnValue($i32->constInt(0, false)));
        }
        Progress::noteFunction('jit_context_compile_common_begin');
        $this->compileCommon();
        Progress::noteFunction('jit_context_compile_common_done');

        $this->runModuleOptimizationPasses();

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
            // Bind php_write before Result::__construct runs __init__ (#21124).
            McjitEmbedHostEcho::bindEngine($engine);
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
        McjitEmbedHostEcho::bindEngine($engine);
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
        // Split-compilation unit emission suffixes these per unit (env) so the
        // unit's init/shutdown survive the -z muldefs merge and the consuming
        // script's __init__ can call them explicitly — colliding symbols were
        // silently discarded and unit module state never initialized
        // (#15889 / #16075 step 4).
        $suffix = (string) getenv('PHP_COMPILER_INIT_SYMBOL_SUFFIX');
        $this->initFunc = $this->module->addFunction('__init__'.$suffix, $signature);
        $this->initBlock = $this->initFunc->appendBasicBlock('main');
        $this->initLinearBlock = $this->initBlock;

        $this->shutdownFunc = $this->module->addFunction('__shutdown__'.$suffix, $signature);
        $this->shutdownBlock = $this->shutdownFunc->appendBasicBlock('main');

        $this->headerPreFlushFunc = $this->module->addFunction('__header_pre_flush__'.$suffix, $signature);
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
        Builtin\ParamSensitiveLowering::implementLookupFunctions($this);
        Builtin\ReflectionNamedArgumentsLowering::implementLookupFunctions($this);
        Builtin\ReflectionFunctionVariadicLowering::implementLookupFunctions($this);
        VmActiveContextInitLlvm::emitPendingBeforeSeal($this);
        $this->sealInitFunction();
        $initSuffix = (string) getenv('PHP_COMPILER_INIT_SYMBOL_SUFFIX');
        if ('' !== $initSuffix) {
            \PHPCompiler\AOT\HelperUnitGlobalCtor::register($this, '__init__'.$initSuffix);
        }
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

    /**
     * Run the LLVM IR optimization pipeline before codegen (#23483).
     *
     * Nothing in the tree used PassManager, so the module went straight from lowering to
     * `emitToFile`, which runs backend codegen passes only. Every IR-level optimisation was
     * therefore absent: locals stayed in memory, the type-tag switch inlined from
     * `__value__readLong` was never folded away even where the tag is a compile-time constant, and
     * branches that cannot be taken (e.g. the `strtol` string path for a value known to be a long)
     * survived into the binary. That is the shape behind an untyped `++$a` loop running ~12x slower
     * than Zend.
     *
     * Opt-in while it is measured: PHP_COMPILER_OPT_LEVEL=0 (default) keeps the previous behaviour,
     * 1-3 selects the pipeline. Set PHP_COMPILER_OPT_SIZE_LEVEL to bias for size.
     */
    private function runModuleOptimizationPasses(): void
    {
        $level = getenv('PHP_COMPILER_OPT_LEVEL');
        $level = is_string($level) && ctype_digit($level) ? (int) $level : 0;
        if ($level <= 0) {
            return;
        }
        $level = min($level, 3);
        $sizeLevel = getenv('PHP_COMPILER_OPT_SIZE_LEVEL');
        $sizeLevel = is_string($sizeLevel) && ctype_digit($sizeLevel) ? min((int) $sizeLevel, 2) : 0;

        Progress::noteFunction('jit_context_opt_passes_begin');
        $builder = $this->llvm->createPassManagerBuilder();
        $builder->setOptLevel($level);
        $builder->setSizeLevel($sizeLevel);
        // The value accessors are alwaysinline and tiny; a real inliner is what lets the tag switch
        // fold once the caller knows the tag.
        $builder->useInlineWithThreshold($level >= 3 ? 275 : 225);

        // Module pipeline only: the LLVM 9 FFI header does not declare
        // LLVMCreatePassManagerForModule, so a function pass manager cannot be built here. The
        // module pipeline populated at O2/O3 already contains the function passes that matter
        // (mem2reg, instcombine, SCCP, GVN, loop passes), so nothing is lost.
        $modulePasses = $this->llvm->createPassManager();
        $builder->populateModulePassManager($modulePasses);
        $modulePasses->run($this->module);
        $modulePasses->dispose();
        $builder->dispose();
        Progress::noteFunction('jit_context_opt_passes_done');
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

                return \PHPCompiler\ext\standard\boolval::boxedTruthyScalar($this, $ptr);
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
            // Per-unit / main suffix — same collision class as __init__/__shutdown__ (#15889 /
            // #16075). Bare string_const_N merges across helper-runtime .o files; later unit
            // __init__ overwrites the main script's literals (SessionsWeb sid became
            // "/index.php"; session wire encode emptied — #26411).
            $global = $this->module->addGlobal(
                $this->type->string->pointer,
                $this->moduleLocalConstGlobalName('string_const_', count($this->stringConstantMap))
            );
            $global->setInitializer($this->type->string->pointer->constNull());
            $oldBuilder = $this->builder;
            // Capture before swapping builders — restore must not leave Runtime::parse
            // insert cleared/on __init__ (parentless jit_strcmp / seal unreachable — #26756).
            $savedInsert = BasicBlockHelper::tryGetInsertBlock($this);
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
            } else {
                BasicBlockHelper::restoreInsertBlock($this, $savedInsert);
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
            $global = $this->module->addGlobal(
                $ptrTy,
                $this->moduleLocalConstGlobalName('array_const_', \count($this->arrayConstantMap))
            );
            $global->setInitializer($ptrTy->constNull());
            $this->arrayConstantMap[$cacheKey] = $global;
            $this->emitConstantArrayInitInInitBlock($global, $table);
        }

        return $this->arrayConstantMap[$cacheKey];
    }

    /**
     * LLVM global name for module-local compile-time constants.
     *
     * Helper units set {@see PHP_COMPILER_INIT_SYMBOL_SUFFIX}; the main script uses
     * `_main` so bare string_const_N from stale prelinked helpers cannot clobber it.
     */
    private function moduleLocalConstGlobalName(string $prefix, int $index): string
    {
        $suffix = (string) getenv('PHP_COMPILER_INIT_SYMBOL_SUFFIX');
        if ('' === $suffix) {
            $suffix = '_main';
        }

        return $prefix.$index.$suffix;
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
        // Prefer the current block's folded constant even when a parent TYPE_TRY hoist
        // already allocated an empty placeholder for the same Temporary (#29751).
        if ($this->bindBlockConstantIfPresent($block, $op)) {
            return;
        }
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

    /**
     * Bind {@see Block::$constants} for $op when the slot is a folded literal (#29751).
     *
     * TYPE_TRY handler blocks hoist try-body Temporaries into scope before the try-body
     * block is lowered; those placeholders have no parent-block constant. Re-bind when the
     * body block's constant table has the UnaryMinus/Plus fold (e.g. {@code -1} for {@code <<}).
     */
    private function bindBlockConstantIfPresent(Block $block, Operand $op): bool
    {
        $slot = $block->slotForOperand($op);
        if (null === $slot || !isset($block->constants[$slot])) {
            return false;
        }
        $constVm = $block->constants[$slot];
        // #28038 stopped treating named CVs as embedded name-string literals. Call-arg
        // lowering can still leave TYPE_STRING placeholders on the CV's real slot while
        // the Operand's CFG type is int/float/bool — NestedJIT then binds NATIVE_LONG
        // formals into STRING slots (MbNumericEntity encode4 $m0…$m3, #28053). Prefer
        // the declared CFG type when it disagrees with the compile-time constant.
        if (!$this->slotConstantAgreesWithOperandType($constVm, $op)) {
            return false;
        }
        $this->scope->variables[$op] = VmConstantJit::toVariable($this, $constVm);

        return true;
    }

    /**
     * True when {@see Block::$constants} may drive {@see makeVariableFromOp} for $op (#28053).
     */
    private function slotConstantAgreesWithOperandType(\PHPCompiler\VM\Variable $constVm, Operand $op): bool
    {
        $declared = Variable::getTypeFromType($op->type ?? null);
        if (
            Variable::TYPE_VALUE === $declared
            || Variable::TYPE_NULL === $declared
        ) {
            return true;
        }
        $constJit = match ($constVm->type) {
            \PHPCompiler\VM\Variable::TYPE_INTEGER => Variable::TYPE_NATIVE_LONG,
            \PHPCompiler\VM\Variable::TYPE_STRING => Variable::TYPE_STRING,
            \PHPCompiler\VM\Variable::TYPE_FLOAT => Variable::TYPE_NATIVE_DOUBLE,
            \PHPCompiler\VM\Variable::TYPE_BOOLEAN => Variable::TYPE_NATIVE_BOOL,
            \PHPCompiler\VM\Variable::TYPE_NULL => Variable::TYPE_NULL,
            default => Variable::TYPE_VALUE,
        };
        if (Variable::TYPE_VALUE === $constJit) {
            return true;
        }

        return $declared === $constJit;
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
        // Prefer any Variable already bound for this slot in scope — ARG_SEND / json_encode
        // often use a distinct Temporary from ARRAY_SPREAD's dest (#28673). Searching only
        // scopedOperands() missed the spread rebind and allocated a fresh null value box.
        foreach ($this->scope->variables as $scopeOp) {
            if (!$scopeOp instanceof Operand) {
                continue;
            }
            if ($block->slotForOperand($scopeOp) !== $slot) {
                continue;
            }
            $this->scope->variables[$op] = $this->scope->variables[$scopeOp];

            return true;
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

    /**
     * Resolve a CFG return/phi operand against the arm block's scope slots (#8555, #23482).
     *
     * Arm-tail ?: returns pass the merge RETURN operand while {@see $jitCurrentBlock} may
     * already have moved on; use $cfgBlock for slot aliasing. Null means the caller should
     * fall back to {@see getVariableFromOp}.
     */
    public function functionScopeBindingVariable(Operand $op, Block $cfgBlock): ?Variable
    {
        if ($this->scope->variables->contains($op)) {
            return $this->scope->variables[$op];
        }
        if ($this->aliasVariableOpFromSlot($cfgBlock, $op)) {
            return $this->scope->variables[$op];
        }
        $name = OperandName::resolve($op);
        if (null !== $name && '' !== $name) {
            $resolved = $this->resolveRefAliasName($name);
            if (isset($this->namedVariableBindings[$resolved])) {
                $this->scope->variables[$op] = $this->namedVariableBindings[$resolved];

                return $this->namedVariableBindings[$resolved];
            }
        }

        return null;
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
        // Try-body folded constants (e.g. UnaryMinus → -1) must win over empty parent-hoist
        // placeholders already in scope (#29751 AOT `$a << -1` inside try).
        if ($op instanceof Operand\Temporary && null !== $this->jitCurrentBlock
            && $this->bindBlockConstantIfPresent($this->jitCurrentBlock, $op)) {
            return $this->scope->variables[$op];
        }
        if (!$this->scope->variables->contains($op)) {
            if ($op instanceof Operand\Literal) {
                $this->scope->variables[$op] = Variable::fromLiteral($this, $op);
            } elseif ($op instanceof Operand\BoundVariable
                && Operand\BoundVariable::SCOPE_OBJECT === $op->scope) {
                $thisVar = $this->findThisVariable();
                if (null === $thisVar) {
                    $thisVar = $this->seedImplicitThisFromActiveLlvmFunction();
                }
                if (null !== $thisVar) {
                    $this->scope->variables[$op] = $thisVar;

                    return $thisVar;
                }
                throw new \LogicException('BoundVariable SCOPE_OBJECT without $this in JIT scope');
            } elseif ($op instanceof Operand\BoundVariable && $op->name instanceof Operand) {
                if ($this->aliasVariableOpByName($op)) {
                    return $this->scope->variables[$op];
                }
                $inner = $this->getVariableFromOpInScopes($op->name);
                $this->scope->variables[$op] = $inner;

                return $inner;
            } elseif ($op instanceof Operand\BoundVariable) {
                throw new \LogicException(
                    'BoundVariable scope '.$op->scope
                    .' nameClass '.(is_object($op->name) ? get_class($op->name) : gettype($op->name))
                );
            } elseif ('this' === OperandName::resolve($op)) {
                $existing = $this->findThisVariable();
                if (null !== $existing) {
                    $this->scope->variables[$op] = $existing;
                } else {
                    throw new \LogicException("Unknown variable referenced: " . get_class($op));
                }
            } elseif ($op instanceof Operand\Temporary) {
                $block = $this->jitCurrentBlock;
                if (null !== $block) {
                    if ($this->aliasVariableOpFromSlot($block, $op)) {
                        return $this->scope->variables[$op];
                    }
                }
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
            } elseif ($op instanceof Operand\BoundVariable
                && Operand\BoundVariable::SCOPE_OBJECT === $op->scope) {
                $thisVar = $this->findThisVariable();
                if (null !== $thisVar) {
                    return $thisVar;
                }
                throw new \LogicException('BoundVariable SCOPE_OBJECT without $this in JIT scope');
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

        return $this->seedImplicitThisFromActiveLlvmFunction();
    }

    /**
     * Queued nested instance methods may omit argVars; LLVM param 0 is $this (#16075).
     */
    public function seedImplicitThisFromActiveLlvmFunction(): ?Variable
    {
        if (null !== $this->implicitThisArgument) {
            return $this->implicitThisArgument;
        }
        $active = strtolower($this->activeFunction ?? '');
        if ('' === $active || !str_contains($active, '::')) {
            return null;
        }
        $llvmFn = $this->functions[$active] ?? null;
        if (null === $llvmFn || $llvmFn->countParams() < 1) {
            return null;
        }
        $thisParam = $llvmFn->getParam(0);
        $thisTy = $this->getStringFromType($thisParam->typeOf());
        if ('__object__*' !== $thisTy) {
            return null;
        }
        $this->implicitThisArgument = new Variable(
            $this,
            Variable::TYPE_OBJECT,
            Variable::KIND_VALUE,
            $thisParam
        );

        return $this->implicitThisArgument;
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
        // Prefer by-ref / name rebinds over a stale same-object scope entry. php-cfg
        // uses distinct SSA Vars for `$n` (assign vs ARG_SEND vs echo); SEND_REF
        // updates namedVariableBindings while the echo operand may still hold the
        // pre-call constant (#24162).
        $name = OperandName::resolve($op);
        if (null !== $name && '' !== $name) {
            $resolved = $this->resolveRefAliasName($name);
            if (isset($this->namedVariableBindings[$resolved])) {
                $bound = $this->namedVariableBindings[$resolved];
                $this->scope->variables[$op] = $bound;

                return $bound;
            }
        }
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
        // Match/?: echo merge stack slots must survive trailing JUMPIF in the same
        // block (second `echo match` after the first merge) (#24143).
        foreach ($this->coalesceMergeSlotOperands as $mergeSlot => $mergeSlotOp) {
            $returnOperands[$mergeSlotOp] = true;
            $returnSlots[(int) $mergeSlot] = true;
            $resolved = $block->slotForOperand($mergeSlotOp);
            if (null !== $resolved) {
                $returnSlots[$resolved] = true;
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
                    $intVal = $phpVar->toInt();
                    $global->setInitializer($type->constInt($intVal, false));
                    // [type, global, compileTimeLong] — foldable after Instruction load (#26774).
                    $this->constants[$name] = [Variable::TYPE_NATIVE_LONG, $global, $intVal];
                    break;
                case VMVariable::TYPE_FLOAT:
                    $type = $this->getTypeFromString('double');
                    $global = $this->module->addGlobal($type, $name);
                    $floatVal = $phpVar->toFloat();
                    $global->setInitializer($type->constReal($floatVal));
                    // Keep host float for compile-time fold (round(M_PI, 5), #27249).
                    $this->constants[$name] = [Variable::TYPE_NATIVE_DOUBLE, $global, $floatVal];
                    break;
                case VMVariable::TYPE_BOOLEAN:
                    $type = $this->getTypeFromString('int1');
                    $global = $this->module->addGlobal($type, $name);
                    $boolAsLong = $phpVar->toBool() ? 1 : 0;
                    $global->setInitializer($type->constInt($boolAsLong, false));
                    $this->constants[$name] = [Variable::TYPE_NATIVE_BOOL, $global, $boolAsLong];
                    break;
                case VMVariable::TYPE_STRING:
                    $compileTimeStr = $phpVar->toString();
                    $global = $this->constantStringFromString($compileTimeStr);
                    $this->constants[$name] = [Variable::TYPE_STRING, $global, $compileTimeStr];
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
        // true/false (and int) CONST_FETCH loads are Instruction-backed; keep a foldable
        // scalar so user-script AOT (XMLWriter::outputMemory(true), flush(false), …) can
        // treat them as compile-time flags (#26774, peer #23427 ARG_SEND rematerialize).
        if ((Variable::TYPE_NATIVE_BOOL === $this->constants[$name][0]
                || Variable::TYPE_NATIVE_LONG === $this->constants[$name][0])
            && isset($this->constants[$name][2])
            && \is_int($this->constants[$name][2])
        ) {
            $var->compileTimeLong = $this->constants[$name][2];
        }
        if (Variable::TYPE_NATIVE_DOUBLE === $this->constants[$name][0]
            && isset($this->constants[$name][2])
            && \is_float($this->constants[$name][2])
        ) {
            $var->compileTimeFloat = $this->constants[$name][2];
        }
        if (Variable::TYPE_STRING === $this->constants[$name][0]
            && isset($this->constants[$name][2])
            && \is_string($this->constants[$name][2])
        ) {
            $var->compileTimeString = $this->constants[$name][2];
        }

        return $var;
    }

}
