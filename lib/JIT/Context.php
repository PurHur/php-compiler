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
use PHPCompiler\AOT\CompileTarget;
use PHPCompiler\CompilerVersion;
use PHPCompiler\Lint\UnsupportedFeature;
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
use PHPCompiler\Config;

require_once __DIR__.'/ContextEditScaffoldModuleRebind.php';
require_once __DIR__.'/ContextDefineBuiltinFunctionProxiesDateAndXml.php';
require_once __DIR__.'/ContextDefineBuiltinFunctionProxies.php';
require_once __DIR__.'/ContextDefineBuiltins.php';
require_once __DIR__.'/ContextCompileToFile.php';
require_once __DIR__.'/ContextVariableOperandBinding.php';
require_once __DIR__.'/ContextFreeDeadAndConstantFetch.php';
require_once __DIR__.'/ContextFunctionProxyAndNestedJitKernel.php';
require_once __DIR__.'/ContextTypeAndStructMap.php';
require_once __DIR__.'/ContextStandaloneBodies.php';
require_once __DIR__.'/ContextModuleCompileAndOptimize.php';
require_once __DIR__.'/ContextScriptGlobalsAndIncludeTracking.php';
require_once __DIR__.'/ContextLlvmConstantsAndRegistry.php';
require_once __DIR__.'/ContextScopeLifecycleAndInitEmit.php';

class Context {
    use ContextEditScaffoldModuleRebind;
    use ContextDefineBuiltinFunctionProxiesDateAndXml;
    use ContextDefineBuiltinFunctionProxies;
    use ContextDefineBuiltins;
    use ContextCompileToFile;
    use ContextVariableOperandBinding;
    use ContextFreeDeadAndConstantFetch;
    use ContextFunctionProxyAndNestedJitKernel;
    use ContextTypeAndStructMap;
    use ContextStandaloneBodies;
    use ContextModuleCompileAndOptimize;
    use ContextScriptGlobalsAndIncludeTracking;
    use ContextLlvmConstantsAndRegistry;
    use ContextScopeLifecycleAndInitEmit;

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

    /** Logical callee lc => LLVM symbol for chunk manifest export (#36155). */
    public array $functionLlvmSymbols = [];

    public array $functionProxies = [];

    /**
     * Extension-owned user-script AOT lowering (SimpleXML/DOM/XMLReader/XMLWriter) (#36204).
     *
     * Modules register from {@see Module::jitInit}; {@see \PHPCompiler\JIT} must not import those exts.
     */
    public ExtensionLoweringHooks $extensionLowering;

    /**
     * Optional VALUE⊙VALUE arith override — BcMath\Number do_operation (#36204 / #24683).
     *
     * Registered by {@see \PHPCompiler\ext\bcmath\Module::jitInit}; core must not import ext\bcmath.
     *
     * @var (callable(self, int, Variable, Variable): Variable)|null
     */
    public $arithBinaryValueValueHook = null;

    /**
     * Optional OBJECT⊙OBJECT arith override — BcMath\Number do_operation (#36204 / #24683).
     *
     * @var (callable(self, int, Variable, Variable): Variable)|null
     */
    public $arithBinaryObjectObjectHook = null;

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

    /** @var array<string, true> void Native callees elidable when discarded (#23483) */
    public array $discardedCallElisionVoidNatives = [];

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
    /**
     * lc user-function names whose CFG cannot throw and only call self / proven
     * no-throw user callees (#36386).
     *
     * @var array<string, bool>
     */
    public array $noThrowUserFunctions = [];

    /**
     * lc user-function names that are a single-param identity (ARG_RECV + RETURN)
     * and may be replaced by their argument at the call site (#36386).
     *
     * @var array<string, bool>
     */
    public array $trivialIdentityUserFunctions = [];

    /**
     * CFG entry blocks for {@see NoThrowCallElision::refineFixpoint} (#36386).
     *
     * @var array<string, \PHPCompiler\Block>
     */
    public array $noThrowAnalyzeBlocks = [];
    /** LLVM function owning the in-flight compileBlockInternal lowering (#31101). */
    public ?\PHPLLVM\Value\Function_ $loweringLlvmFunction = null;

    /**
     * Per-LLVM-function CFG Block → BB maps (#31101).
     * Entries are `[canonical Function_ wrapper, blockStorage, blockEntryStorage]` matched via
     * {@see TryCatchHelper::sameLlvmFunction} (PHPLLVM wrappers are not pointer-stable).
     *
     * @var list<array{0: \PHPLLVM\Value\Function_, 1: \SplObjectStorage, 2: \SplObjectStorage}>
     */
    public array $blockStorageByLlvmFunc = [];

    public array $functionScope = [];

    /** User function CFG block while compiling its body (func_get_args / func_num_args, #197). */
    public ?Block $jitEnclosingBlock = null;

    /** Operand for unserialize() options arg during FUNCCALL lowering (#3300). */
    public ?Operand $jitUnserializeOptionsOperand = null;

    /** Operand for json_encode() value arg during FUNCCALL lowering (#14040). */
    public ?Operand $jitJsonEncodeValueOperand = null;

    /** Operand for json_encode() flags arg — fold JSON_* | JSON_* (#35339). */
    public ?Operand $jitJsonEncodeFlagsOperand = null;

    /** Operand for json_decode() flags arg when ARG_SEND lost compileTimeLong (#10611 / #12009). */
    public ?Operand $jitJsonDecodeFlagsOperand = null;

    /** Compile-time json_encode() result for assignCallResultOperand (#24137). */
    public ?string $jitJsonEncodeFoldedString = null;

    /** Compile-time serialize() wire on the result CV so unserialize($s) can fold DateTime (#34576). */
    public ?string $jitSerializeFoldedString = null;

    /** Compile-time str_repeat() result for json_decode($s, …, JSON_THROW_ON_ERROR) depth fold (#10611). */
    public ?string $jitStrRepeatFoldedString = null;

    /** Operand for iterator_to_array() iterator arg — CFG userType for HT-backed SPL (#26825). */
    public ?Operand $jitIteratorToArrayIteratorOperand = null;

    /** Operand for compile-time xmlrpc_encode() array/scalar literals (#19048). */
    public ?Operand $jitXmlrpcEncodeValueOperand = null;

    /** Operand for call_user_func_array() $args during FUNCCALL lowering (#10359). */
    public ?Operand $jitCallUserFuncArrayParamsOperand = null;

    /** Operand for call_user_func() $callback — fold compile-time ['Class','method'] (#35090). */
    public ?Operand $jitCallUserFuncCallbackOperand = null;

    /**
     * New DateTime / DateTimeImmutable result — construct stamps unix instant (#29732 peer).
     */
    public ?Operand $lastDateTimeNewResultOp = null;

    public ?Variable $lastDateTimeNewResultVar = null;

    /**
     * Local name → unix instant + zone (+ micro) for DateTime / DateTimeImmutable (#32691, #33915).
     *
     * @var array<string, array{timestamp: int, timezone: ?string, microsecond?: int}>
     */
    public array $dateTimeLocalInstants = [];

    /**
     * Next typed DateTime property store after DateTime::__construct (#35752).
     *
     * @var array{timestamp: int, timezone: ?string, microsecond?: int}|null
     */
    public ?array $pendingDateTimePropertyInstant = null;

    /**
     * New DateInterval result — construct stamps parsed duration onto the local (#32699).
     */
    public ?Operand $lastDateIntervalNewResultOp = null;

    public ?Variable $lastDateIntervalNewResultVar = null;

    /**
     * New DatePeriod result — construct stamps foreach snapshot onto the local (#33744).
     */
    public ?Operand $lastDatePeriodNewResultOp = null;

    public ?Variable $lastDatePeriodNewResultVar = null;

    /**
     * Local name → parsed DateInterval state for format() (#32699).
     *
     * @var array<string, array<string, mixed>>
     */
    public array $dateIntervalLocalStates = [];

    /**
     * Compile-time DateTime::diff result state — published onto the result local so
     * format() can bake without mid-main FormatRuntime ensureLinked (#33912 / #32699).
     *
     * @var array{y: int, m: int, d: int, h: int, i: int, s: int, f: float, invert: int, days: int}|null
     */
    public ?array $lastDateIntervalDiffState = null;

    /**
     * Concrete / runtime unserialize() O: class hint for result locals (#34602 residual).
     * Sets classUserType without baking compileTimeDateInterval (wire values are runtime).
     */
    public ?string $lastUnserializeObjectClassUserType = null;

    /**
     * Folded DatePeriod unserialize — foreach snapshot for thin AOT (#34608 / peer #26772).
     *
     * @var list<int>|null
     */
    public ?array $lastDatePeriodUnserializeTimestamps = null;

    public ?string $lastDatePeriodUnserializeTimezone = null;

    /**
     * Folded DateTime / DateTimeImmutable unserialize — stamp for format()/getOffset() (#34614).
     *
     * Peer construct stamps (#33939) and DateInterval {@see $lastDateIntervalDiffState}.
     * Nested DatePeriod start/end materialize must clear this before return (#34608).
     *
     * @var array{timestamp: int, microsecond: int, timezone: string, className: string}|null
     */
    public ?array $lastDateTimeUnserializeInstant = null;

    /**
     * Named local last published by DateTime unserialize sync (#34614).
     * format()/getOffset() restore onto divergent method-$this Variables.
     */
    public ?string $lastDateTimeUnserializeLocalName = null;

    /**
     * New DateTimeZone result operand/var — construct stamps zone id onto the local (#29732).
     */
    public ?Operand $lastDateTimeZoneNewResultOp = null;

    public ?Variable $lastDateTimeZoneNewResultVar = null;

    /** Named local last assigned a DateTimeZone object — construct stamps zone id here (#29732). */
    public ?string $lastAssignedDateTimeZoneLocalName = null;

    /**
     * Most recent DateTimeZone::__construct literal id — `$z->getLocation()` receivers often
     * lack compileTimeTimezoneName after assign (#33727). Sequential construct+method is OK.
     */
    public ?string $lastDateTimeZoneConstructedId = null;

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

    /**
     * Named CV → index into generator state frame_slots (#35142).
     *
     * @var array<string, int>
     */
    public array $generatorFrameLocalIndex = [];

    /**
     * Named CV → LLVM pointer to heap __value__ slot (dominates all resume BBs) (#35142).
     *
     * @var array<string, \PHPLLVM\Value>
     */
    public array $generatorFrameLocalPtrs = [];

    /**
     * Yield opcode object id → resume-point index while compiling resume CFG (#35142).
     *
     * @var array<int, int>
     */
    public array $generatorYieldPointIndex = [];

    /**
     * Continuation LLVM BBs keyed by resume_ip after each yield (#35142).
     *
     * @var array<int, \PHPLLVM\BasicBlock>
     */
    public array $generatorResumeContinuations = [];

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
     * user func lc => ordered formal names for frame init at create (#35142).
     *
     * @var array<string, list<string>>
     */
    public array $generatorCreatorParamNames = [];

    /**
     * resume LLVM symbol lc => frame local name => index (#35142).
     *
     * @var array<string, array<string, int>>
     */
    public array $generatorCreatorFrameIndex = [];

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

    /**
     * Scoped name of the user function currently being lowered (set in JIT::compileBlock).
     * Used when Internal builtins cannot see {@see Block::$func} on jitCurrentBlock (#36382).
     */
    public ?string $jitLoweringScopedName = null;

    /** Most recent closure call proxy from TYPE_CLOSURE (register_shutdown_function, #3120). */
    public ?Call $lastClosureCallProxy = null;

    /**
     * TYPE_FROM_CALLABLE result slot => invoke proxy for FUNCCALL recovery (#24166).
     *
     * @var array<int, Call>
     */
    public array $fccClosureCallByResultSlot = [];

    /**
     * User function / method lcname => Closure invoke proxy for a compile-time
     * `return fn()…` / `return function()…` so the caller's EXEC_RETURN can reattach
     * Variable::closureCall (otherwise `$f = m(); $f()` loses the proxy and AOT
     * returns null / SIGSEGV — #34868).
     *
     * @var array<string, Call>
     */
    public array $functionReturnedClosureCall = [];

    /**
     * Array result operand slot => normalized element key => Closure invoke proxy.
     *
     * Populated at array literal build; consumed when foreach iter value assigns into a
     * local so `$fn()` keeps ClosureWithCaptures instead of RuntimeIndirect (#24106 peer).
     *
     * @var array<string, array<string, JIT\Call>>
     */
    public array $closureCallByArrayResultSlot = [];

    /**
     * Array literal operand registry key => ordered Closure invoke proxies (literal build order).
     *
     * @var array<string, list<JIT\Call>>
     */
    public array $closureCallOrderedByArrayResultSlot = [];

    /**
     * Foreach container operand registry key => key Variable from the latest TYPE_ITER_KEY.
     *
     * @var array<string, Variable>
     */
    public array $foreachPendingKeyByArraySlot = [];

    /** Call-site file strict_types while lowering FUNCCALL (issues #156, #1229). */
    public bool $callerStrictTypes = false;

    /** Call-site line for the pending FUNCCALL_EXEC (issue #4381). */
    public int $callSiteLine = 0;

    /** Outgoing user argc at the current call site (includes surplus args dropped from $args) (#31091). */
    public ?int $callSiteOutgoingUserArgCount = null;

    /** When true, pow() lowering returns a boxed {@see __value__*} (power operator **). */
    public bool $powReturnValueBox = false;

    /**
     * Overflowable native-long result from an Internal::call (e.g. typed abs with
     * PHP_INT_MIN → double). {@see Concern\CallResultOperandAssign} consumes this
     * so the call operand keeps promote metadata (#36386).
     */
    public ?Variable $overflowableInternalCallResult = null;

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
    /** @var array<string, PHPLLVM\Value> file-scope object constants (#35196) */
    private array $objectConstantMap = [];

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
    /** When set, {@see compileToFile()} persists `aot.o` + helper slugs into CompileCache (#36387). */
    public ?string $aotCompileCacheKey = null;

    public Helper $helper;

    public Scope $scope;

    /** @var list<Scope> */
    public array $scopeStack = [];

    public TryCatchState $tryCatch;

    /**
     * Module-wide try/finally goto-pending globals — must survive {@see TryCatchState::reset()}
     * when nested helper JIT runs mid user-function lowering (#25240 / HELPER_RUNTIME_O=0).
     */
    public ?PHPLLVM\Value $gotoPendingFlagGlobal = null;

    public ?PHPLLVM\Value $gotoResumeIdGlobal = null;

    public int $nextGotoResumeId = 0;

    /**
     * Handlers needing goto-resume dispatch at module seal — survives {@see TryCatchState::reset()}.
     *
     * @var list<TryCatchHandler>
     */
    public array $gotoResumeHandlers = [];

    /**
     * Deferred {@see Call\RuntimeIndirectClosureCall} bodies — filled at seal with the full
     * `{closure}_N` proxy set so IncludeHelper mid-graph invokes see entry-script closures (#36382).
     *
     * @var list<array{func: \PHPLLVM\Value\Function_, closureClassId: int, nargs: int, candidates: array<string, Call>}>
     */
    public array $pendingRuntimeIndirectClosureDispatches = [];

    /** True while {@see Call\RuntimeIndirectClosureCall::materializePending} fills deferred bodies. */
    public bool $materializingRuntimeIndirectClosureDispatch = false;

    /** ?? / ?-> result operands that must receive branch assigns even when php-cfg marks them dead (#99, #3219). */
    public \SplObjectStorage $coalesceAssignTargets;

    /**
     * PROPERTY_FETCH_WRITE feeding ??= : keep objectPropertySlot on force-merge copies (#33748).
     * ?-> / ?? reads leave this false so merge seats stay stack boxes (#32988 / #3219).
     */
    public bool $retainCoalesceInstancePropertyLvalue = false;

    /** Scope slot => ?? result operand for runtime reload at chained call-arg send (#17590). */
    public array $coalesceMergeSlotOperands = [];

    /**
     * CFG scope slot => {@see __object__**} entry alloca for ASSIGN result temps (#36245 loop_unset).
     *
     * @var array<int, \PHPLLVM\Value>
     */
    public array $scopeSlotObjectMirrorLlvmBySlot = [];

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
     * Nested eval() inline lowering depth — Exception/Error getLine() must unwrap wrapEvalCode (#31948).
     */
    public int $evalInlineDepth = 0;

    /**
     * Caller blocks for nested literal includes (layout → partial); used to resolve
     * inherited locals from the outer TU (#764, #784).
     *
     * @var list<Block>
     */
    public array $inlineIncludeCallerBlocks = [];

    /** Require/include expression result slots while inlining (issue #783). */
    public array $inlineIncludeReturnOperands = [];

    /** Value boxes holding inline eval/include return while inlining (#31912). */
    public array $inlineIncludeReturnHolders = [];

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

    /** Active {@see \PHPCompiler\JIT} during module lowering — avoids re-entrant loadJit() at call sites (#6652). */
    public ?\PHPCompiler\JIT $activeJitCompiler = null;

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
     * Keys in {@see $foreachDatePeriodSnapshotHts} that are SimpleXMLElement snapshots (#34543).
     * Those values must load as TYPE_OBJECT so (string) cast reads baked SXE slots.
     *
     * @var array<string, true>
     */
    public array $foreachSimpleXmlSnapshotKeys = [];

    /**
     * DOMNodeList / DOMNamedNodeMap foreach — snapshot to hashtable, then iterate.
     * Keyed by {@see foreachSlotMapKey()}, value is the snapshot HT JitVariable.
     *
     * @var array<string, Variable>
     */
    public array $foreachDomNodeListSlots = [];

    /**
     * IteratorAggregate foreach slots that unwrap getIterator() then walk `__spl_ht`
     * on the inner ArrayIterator (#26785). Keyed by {@see foreachSlotMapKey()}.
     *
     * @var array<string, true>
     */
    public array $foreachAggregateInnerHtSlots = [];

    /**
     * IteratorAggregate foreach whose getIterator() yields — resume name for the inner Generator (#34980).
     *
     * Keyed by {@see foreachSlotMapKey()}; value is the creator resume symbol (e.g. `a::getiterator__resume`).
     *
     * @var array<string, string>
     */
    public array $foreachAggregateGeneratorResume = [];

    /**
     * SplStack (and LIFO dllist) foreach — packed `__spl_ht` walked descending (#28705).
     *
     * @var array<string, true>
     */
    public array $foreachReverseHtSlots = [];

    /**
     * SplDoublyLinkedList foreach — runtime `__spl_flags` IT_MODE_LIFO alloca (i1) (#33987).
     *
     * @var array<string, \PHPLLVM\Value>
     */
    public array $foreachRuntimeReverseSlots = [];

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

    /** Implicit $this passed as the first LLVM arg for instance methods (#877). */
    public ?Variable $implicitThisArgument = null;

    public function __construct(Runtime $runtime, int $loadType) {
        $runtime->claimJitContextSlot($this);
        $this->runtime = $runtime;
        $this->extensionLowering = new ExtensionLoweringHooks();
        $this->scope = new Scope;
        $this->tryCatch = TryCatchState::create();
        $this->coalesceAssignTargets = new \SplObjectStorage();
        $this->listUnpackMergeLlvmBlocks = new \SplObjectStorage();
        $this->loadType = $loadType;
        $this->llvm = PHPLLVM\Chooser::choose();
        if (Builtin::LOAD_TYPE_STANDALONE === $this->loadType) {
            CompileTarget::current()->initializeLlvm($this->llvm);
        } else {
            $this->llvm->initializeNative();
        }
        $this->context = $this->llvm->contextCreate();
        $this->module = $this->context->moduleCreateWithName('main');
        if (Builtin::LOAD_TYPE_STANDALONE === $this->loadType) {
            CompileTarget::current()->applyToModule($this->module);
        }
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
        // Parse prior module.bc before namedStructType / Helper holds the live module (#36387).
        $this->tryBindEditScaffoldBitcodeBeforeBuiltins();

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
}
