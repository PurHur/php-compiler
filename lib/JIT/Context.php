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
use PHPCompiler\JIT\SuperglobalInit;
use PHPCompiler\Web\Superglobals;
use PHPCompiler\Config;

require_once __DIR__.'/ContextEditScaffoldModuleRebind.php';
require_once __DIR__.'/ContextDefineBuiltins.php';
require_once __DIR__.'/ContextCompileToFile.php';
require_once __DIR__.'/ContextVariableOperandBinding.php';
require_once __DIR__.'/ContextFunctionProxyResolve.php';

class Context {
    use ContextEditScaffoldModuleRebind;
    use ContextDefineBuiltins;
    use ContextCompileToFile;
    use ContextVariableOperandBinding;
    use ContextFunctionProxyResolve;

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
        $bootstrapLink = Config::getenv('PHP_COMPILER_BOOTSTRAP_AOT_LINK');
        if ('1' === $bootstrapLink || 'true' === strtolower((string) $bootstrapLink)) {
            return true;
        }
        $flag = Config::getenv('PHP_COMPILER_M3_COMPILE_DRIVER_MAIN');

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
        $restore = BasicBlockHelper::tryGetInsertBlock($this);
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
        } else {
            // NestedJIT helper compile can leave insert cleared; reopen so the
            // subsequent load of this global is parented (#32445).
            BasicBlockHelper::ensureOpenInsertBlock($this, 'script_global_after_init');
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
            // `$r = &Class::$prop` must rebind onto the static global lvalue (#32036).
            // `$o->p =& $v` / `$a[] =& $v` must rebind onto property/dim KIND_VALUE lvalues (#34649).
            if (
                Variable::KIND_VARIABLE === $existing->kind
                && Variable::KIND_VALUE === $var->kind
                && null === $var->valueBoxAliasPtr
                && null === $var->staticPropertyGlobal
                && null === $var->objectPropertySlot
                && null === $var->writableHt
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


    public function setMain(PHPLLVM\Value\Function_ $func): void {
        $this->main = $func;
    }

    /** Keep intrinsic memcpy/memset on the live builder after save/restore swaps (#36144). */
    public function syncIntrinsicBuilder(): void
    {
        $this->intrinsic->builder = $this->builder;
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
        $bootstrapLink = Config::getenv('PHP_COMPILER_BOOTSTRAP_AOT_LINK');
        if ('1' === $bootstrapLink || 'true' === strtolower((string) $bootstrapLink)) {
            return true;
        }

        return false;
    }

    /** examples/000–009 user-script AOT: thin LLVM bridges only — no nested-JIT stdlib during init (#13571). */
    private function ensureMinimalUserStandaloneBodies(): void
    {
        // StringHtmlspecialchars always-on removed (#34642): htmlspecialchars.php already
        // ensureLinked before lookup (peer #34612 HtmlEntities/Decode). Leftover Context
        // NestedJIT vs Runtime ABI drift mints *.1 (#31894 / #32122). Thin hello-world must
        // not NestedJIT htmlspecialchars ABI during init.
        // HtmlEntities / HtmlspecialcharsDecode always-on removed (#34612): htmlentities.php /
        // JitHtmlspecialcharsDecode already ensureLinked before lookup (peer #34605). Leftover
        // Context NestedJIT vs Runtime ABI drift mints *.1 (#31894 / #32122).
        // ExceptionBridge always-on removed (#34732): TypeErrorRaise::ensureLinked /
        // ExceptionBridge::emitTypeError* / emitClear+emitAbort already implement standalone
        // bodies before lookup (peer #34695). Thin hello-world must not NestedJIT TypeErrorRaise
        // / JitThrow during init — thin {main} skips ExceptionBridge clear/abort anyway.
        // Leftover Context NestedJIT vs Runtime ABI drift mints *.1 (#31894 / #32122).
        // ErrorBridge always-on removed (#34769): ErrorRaise / AssertionErrorRaise /
        // ReadonlyRaise ensureLinked + emitClear/emitAbort/emitRaise already implement
        // standalone bodies before lookup (peer #34732). Thin hello-world must not NestedJIT
        // pending-Error ABI during init — thin {main} skips ErrorBridge clear/abort when unused.
        // Leftover Context NestedJIT vs Runtime ABI drift mints *.1 (#31894 / #32122).
        // Full standalone drop is #35099 (peer #35089 / #35086).
        // ErrorHandler / ExceptionHandler always-on removed (#34612): JitErrorHandler /
        // JitTriggerErrorKernel / JitExceptionHandler / TryCatchHelper already ensureLinked
        // before lookup (peer #34605). implement() paths restore builder insert mid-{main}.
        // StreamLifecycle / StreamRead / StreamBucket always-on removed (#34836): call-site
        // StreamLifecycleRuntime::ensureLinked(ForUserScriptLowering) / StreamReadRuntime::
        // ensureLinked / StreamBucket::ensureLinked already run before lookup (JitFclose /
        // JitFeof / JitFflush / JitFgetc / JitFgets / JitStreamBucket / JitIsResource /
        // StringVarDump / StringPrintR / SilenceRuntime — peer Type::initialize #34439 /
        // #20966 / #20982 / #20998). ensureMinimal is reached for user-script AOT and via
        // bootstrap-aot ensureBootstrapAotStandaloneBodies; the old `!$isUserScriptAot`
        // guard only NestedJIT Stream* on the bootstrap path and still risked feof.1 /
        // stream_bucket_*.1 (#31894 / #32122). Full standalone drop is #35086 (peer #35073).
        // StringTriggerError always-on removed (#34641): trigger_error_.php / JitBuiltinWarning /
        // JitIncDec / HashTableResourceKeyLlvm / JitTriggerErrorKernel already ensureLinked before
        // lookup (peer #34631 / #33234). JitTriggerErrorKernel restores builder insert mid-{main}.
        // Leftover Context NestedJIT vs Runtime ABI drift mints *.1 (#31894 / #32122).
        // AssertFail always-on removed (#34605): JitAssert already ensureLinked before lookup
        // (peer #34578). Full standalone still ensureStandaloneBodies below.
        // JitReturnPending always-on removed (#34621): TryCatchHelper / emitPendingReturnResume
        // already ensureLinked before lookup (peer #34612). JitHelperAbiBridge restores insert
        // mid-{main}. Leftover Context NestedJIT vs Runtime ABI drift mints *.1 (#31894 / #32122).
        // ObOutput always-on removed (#34695): ValueEchoHelper / ValueEchoRuntime /
        // StringVarDump / ObOutput / StreamReadRuntime already ensureLinked before
        // __phpc_ob_echo_* lookup (peer #34642). Leftover Context NestedJIT vs Runtime ABI
        // drift mints ob_*.1 (#31894 / #32122). Thin hello-world must not NestedJIT ob during init.
        // StringRandomBytes / Utf8Latin1 / RewriteVars / Define / StrContains / StatPath /
        // FileGetContents / MetaTags / HashCrypto / MbNumericEntity / Readfile / Bin2hex /
        // Addslashes / Stripslashes / FilePutContents / IniRuntime always-on removed (#34578):
        // call-site ensureLinked / ensureStandaloneBodies / emit* / invoke* already run before
        // lookup (peer #34566 SessionStorageGlobals). Thin AOT hello-world must not NestedJIT
        // those ABIs. Leftover Context NestedJIT vs Runtime ABI drift mints *.1 (#31894 / #32122).
        // Type::register __compiler_ini_* shells are gone (#34474); do not re-add IniRuntime here.
        // ProgressNote / GcCollectCycles always-on removed (#34605): tryResolveProgressStaticCall /
        // JitGcCollectCycles / Object_ / GcStatusRuntime already ensureLinked before lookup
        // (peer #34578). Full standalone still ensureStandaloneBodies below.
        // LastError always-on removed (#34631): JitErrorGetLast / JitTriggerErrorKernel already
        // ensureLinked before lookup (peer #34621). LastErrorRuntime restores builder insert
        // mid-{main}. Leftover Context NestedJIT vs Runtime ABI drift mints *.1 (#31894 / #32122).
        // Full standalone still ensureStandaloneBodies below (StringTriggerError then LastError).
        // EnvLocal always-on removed (#34807): getenv()/putenv() lower via StringGetenv /
        // PutenvJitHelper / GetenvLookupJitHelper (#32665 / #23414 / #29313). No call site looks
        // up __compiler_env_local_* — NestedJIT of EnvLocalJitHelper during thin init only
        // risked env_local_lookup.1 (#31894 / #32122). bootstrap-aot still
        // ensureBootstrapAotStubLinked below. EnvLocalRuntime::ensureLinked stays callable.
        // SuperglobalName always-on removed (#34812): JitSuperglobalName / JIT.php
        // compileSuperglobalNameNative already StringSuperglobalName::ensureLinked before lookup
        // (peer #34807 / #33235). Compile-time paths use Web\Superglobals::isSuperglobalName —
        // not the LLVM ABI. Thin hello-world must not NestedJIT SuperglobalNameJitHelper during
        // init. Leftover Context NestedJIT vs Runtime ABI drift mints is_superglobal_name.1
        // (#31894 / #32122). Full standalone still ensureLinked below.
        // CliArgv always-on removed (#34822 / #35133): compileToFile (all standalone) +
        // CliArgvGlobalInit / JitGetopt already ensureLinked / ensureStandaloneBodies before
        // lookup (peer #34812 / #34463). Thin hello-world must not link CLI argv ABI during
        // ensureMinimal init — main() still gets __phpc_cli_store_argv from compileToFile.
        // Mid-{main} $argc/$argv restores insert block after ABI emit (#27317). Leftover Context
        // NestedJIT vs Runtime ABI drift mints cli_*.1 (#31894 / #32122). Full standalone also
        // deferred to compileToFile (#35133); bootstrap-aot still ensureStandaloneBodies in
        // ensureBootstrapAotStandaloneBodies.
        // DomStandaloneAotInit / DomInstanceMethod always-on removed (#34605):
        // VmActiveContextInitLlvm::emitPendingBeforeSeal ensureLinked DomStandaloneAotInit when
        // thin init is requested; DomInstanceMethodRuntime::invoke ensureBridge per arity
        // (peer #34578). Leftover Context NestedJIT vs Runtime ABI drift mints
        // dom_standalone_aot_init.1 / dom_instance_method_*.1 (#31894 / #32122).
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
            // ExceptionBridge / ErrorBridge always-on removed (#35099): TypeErrorRaise /
            // JitThrow / ErrorRaise / AssertionErrorRaise / ReadonlyRaise ensureLinked +
            // emitClear/emitAbort/emitRaise already implement standalone bodies before lookup
            // (peer ensureMinimal #34732 / #34769). compileToFile clear/abort also drop
            // eager ErrorBridge::ensureLinked (#35443) — emit* self-ensure. Full
            // standalone must not NestedJIT type_error_* / error_* during init — leftover
            // Context NestedJIT vs Runtime ABI drift mints *.1 (#31894 / #32122).
            // StreamLifecycle / StreamRead always-on removed (#35086): JitFclose / JitFeof /
            // JitFflush / JitFgetc / JitFgets / StringVarDump / StringPrintR / SilenceRuntime /
            // StreamReadJit / StringFgetcsvJit already ensureLinked(ForUserScriptLowering)
            // before lookup (peer ensureMinimal #34836 / #20966 / #20982). Full standalone
            // must not NestedJIT feof/fclose/fflush/fgets during init — leftover Context
            // NestedJIT vs Runtime ABI drift mints feof.1 / fflush.1 (#31894 / #32122).
            // StringTriggerError / AssertFail / AssertOptions / JitReturnPending always-on
            // removed (#35073): JitAssert / JitAssertOptions / TryCatchHelper /
            // AssertFail::ensureLinked (→ StringTriggerError) already ensure before lookup
            // (peer ensureMinimal #34605 / #34621 / #34641). Full standalone must not
            // NestedJIT assert_fail* / assert_options / return_pending / trigger_error
            // during init — leftover Context NestedJIT vs Runtime ABI drift mints *.1
            // (#31894 / #32122).
            // ObOutput always-on removed (#34695): ValueEchoHelper / ValueEchoRuntime::emitValue
            // ensureLinked ObOutput before __phpc_ob_echo_* lookup (peer #34642).
            // ValueEcho always-on removed (#35143): emitValue / StringVarDump / StringPrintR /
            // StringVarExport already ValueEchoRuntime::ensureLinked before type-bridge use
            // (peer #35137 SuperglobalRefresh / #35133 CliArgv). Full standalone must not
            // NestedJIT value_echo_* during init — leftover Context NestedJIT vs Runtime ABI
            // drift mints value_echo_*.1 (#31894 / #32122).
            // CliArgv always-on removed (#35133 / peer ensureMinimal #34822): compileToFile
            // ensures CliArgvRuntime for every LOAD_TYPE_STANDALONE before main emits
            // __phpc_cli_store_argv; CliArgvGlobalInit / JitGetopt ensureLinked before lookup.
            // Full standalone must not NestedJIT cli_* during init — leftover Context NestedJIT
            // vs Runtime ABI drift mints cli_*.1 (#31894 / #32122).
            // Soundex/Quotemeta/PregQuote/Nl2br/Ucwords/Metaphone/Wordwrap/MbNumericEntity/
            // Bin2hex/Base64*/Strrev/StrRepeat/StrPad/StrRot13/Uniqid/ChunkSplit/
            // GraphemeStrSplit/Hex2bin/Levenshtein/SubstrCount/CountChars/NCompare/
            // StrWordCount/StripTags/Strtr/ParseStr always-on removed (#35099): the old
            // `!isStandaloneInitPhase()` gate never ran — ensureFull always
            // beginStandaloneInitPhase() first (#14472 / #20571). Call sites already
            // ensureLinked before lookup (peer ensureMinimal #34578). Do not NestedJIT
            // those ABIs during full init (#31894 / #32122).
            // StringFormat always-on removed (#35130): JitSprintf / JitPrintf /
            // JitNumberFormat / JitFprintf / JitVfprintf / JitVsprintf / vprintf_ /
            // vfprintf_ already implementIfDeclared / ensureLinked before lookup
            // (peer #35127 / Type #32921 / #15642). Full standalone must not NestedJIT
            // __compiler_sprintf / __compiler_printf / __compiler_number_format during
            // init — leftover Context NestedJIT vs Runtime ABI drift mints sprintf.1 /
            // printf.1 / number_format.1 (#31894 / #32122).
            // StringStrReplace always-on removed (#35160): JitStrReplace / StringStrReplace::invoke
            // already ensureLinked before lookup. With HelperRuntimeCache enabled, ensureLinked
            // still implements under NestedJitCompileScope (the #23970 no-op is cache-off only);
            // bin/compile.php already forces PHP_COMPILER_HELPER_RUNTIME_O=1 for skip-bundle
            // compile_driver. Full standalone must not NestedJIT phpc_str_replace during init —
            // leftover Context NestedJIT vs Runtime ABI drift mints phpc_str_replace.1
            // (#31894 / #32122).
            // StringJsonEncode / StringJsonDecode always-on removed (#35065): JitJsonEncode /
            // JitJsonDecode / JsonEncodeArrayLlvm / JitJsonValidate / … already ensureLinked /
            // ensureJitHelperCompiled before lookup (peer #35035). Full standalone must not
            // NestedJIT json_* during init — leftover Context NestedJIT vs Runtime ABI drift
            // mints json_encode_*.1 / json_decode*.1 (#31894 / #32122).
            // StringRandomBytes always-on removed (#35113): JitRandomBytes / ArrayRandLlvm /
            // SessionCreateIdRuntime already StringRandomBytes::ensureLinked before lookup
            // (peer ensureMinimal #34578 / Type #33160 / #34332). Full standalone must not
            // NestedJIT __compiler_random_bytes during init — leftover Context NestedJIT vs
            // Runtime ABI drift mints random_bytes.1 (#31894 / #32122).
            // ScalarDimFetchRuntime / StringOffsetRuntime always-on removed (#35065):
            // emitWarning / dimFetch / readDimAsString / … already ensureLinked before ABI use
            // (peer #35035). Do not NestedJIT offset / scalar-dim helpers during full init.
            // UndefinedVariableRuntime: ensureLinked only — emitWarningForName uses __compiler_trigger_error
            // (call sites / AssertFail ensure StringTriggerError; avoid duplicate bodies — #10524 / #35073).
            // StreamFilter / StreamBucket always-on removed (#35086): StreamIoJit /
            // StreamReadJit / StreamReadRuntime / JitStreamBucket / JitIsResource already
            // StreamFilter::ensureLinked / StreamBucket::ensureLinked before lookup
            // (peer ensureMinimal #34836 / #21041 / #20998). Full standalone must not
            // NestedJIT stream_filter_* / stream_bucket_* during init — leftover Context
            // NestedJIT vs Runtime ABI drift mints stream_bucket_*.1 (#31894 / #32122).
            // GcToggle / GcCollect / ProgressNote / LastError always-on removed (#35073):
            // JitGcToggle / JitGcCollectCycles / ProgressNoteRuntime / JitErrorGetLast /
            // JitTriggerErrorKernel / Object_ delref already ensureLinked before lookup
            // (peer ensureMinimal #34605 / #34631). Full standalone must not NestedJIT
            // gc_* / progress_note / last_error during init (#31894 / #32122).
            // FunctionStatic always-on removed (#35086): FunctionStaticHelper::ensureRuntime
            // already FunctionStaticRuntime::ensureLinked before phpc_fn_static_* lookup
            // (#10173). Full standalone must not emit fn-static table ABI during init —
            // leftover Context NestedJIT vs Runtime ABI drift mints phpc_fn_static_*.1
            // (#31894 / #32122).
            // StringUtf8Latin1 / RewriteVars / Define / Strspn / FileGetContents / Readfile
            // always-on removed (#35089): JitUtf8Latin1 / JitDefine / SpnJitLowering /
            // JitParseStrUserScriptCstrKernel / JitFileGetContents / readfile.php /
            // RewriteVarsRuntime::emit* / BootstrapCompileSmokeM3Emit already ensureLinked
            // before lookup (peer ensureMinimal #34578 / Type #34474 / #34423). Full
            // standalone must not NestedJIT those ABIs during init — leftover Context
            // NestedJIT vs Runtime ABI drift mints utf8_*.1 / define.1 /
            // file_get_contents.1 / readfile.1 / strspn.1 (#31894 / #32122).
            // SuperglobalRefresh always-on removed (#35137 / peer #35133 CliArgv):
            // compileToFile ensures for every LOAD_TYPE_STANDALONE before main emits
            // __superglobals__refresh; thin keeps ensureUserScriptRefreshEmit. Full
            // standalone must not NestedJIT __superglobals__refresh during init —
            // leftover Context NestedJIT vs Runtime ABI drift mints
            // __superglobals__refresh.1 (#31894 / #32122).
            // SuperglobalName always-on removed (#35035): JitSuperglobalName / JIT.php
            // StringSuperglobalName::ensureLinked before lookup (peer ensureMinimal #34812 /
            // #33235). Full standalone must not NestedJIT is_superglobal_name during init —
            // leftover Context NestedJIT vs Runtime ABI drift mints is_superglobal_name.1
            // (#31894 / #32122).
            // TokenGetAll / Highlight / Hebrev / Hebrevc always-on removed (#35035): each
            // ensureStandaloneBodies is a no-op — helper LLVM compiles on first lowering
            // (TokenGetAll::helperFunction / JitHighlight / JitHebrev). Do not re-add eager
            // NestedJIT here (#31894 / #32122 .1 mint class).
            // JitStreamBucketKernel always-on removed (#35086): StreamBucket::ensureLinked →
            // JitStreamBucketKernel::ensureLinked (JitStreamBucket / JitIsResource) already
            // implement before lookup (peer #34836). Do not NestedJIT stream_bucket_* here.
            // StringGetenv / StringGetenvAll always-on removed (#35127): JitEnv::getenv /
            // getenvAll already StringGetenv::ensureLinked / StringGetenvAll::ensureLinked
            // before lookup (peer ensureMinimal Type #32665 / #34807). Full standalone must
            // not NestedJIT __compiler_getenv / __compiler_getenv_all during init — leftover
            // Context NestedJIT vs Runtime ABI drift mints getenv.1 / getenv_all.1
            // (#31894 / #32122). Post-init always-helper (#20156) was the prior reason these
            // sat after endStandaloneInitPhase; call-site ensureLinked is enough now.
        } finally {
            Builtin\StreamIoRuntime::endStandaloneInitPhase();
        }
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
            $this->runModuleOptimizationPasses();
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
        $this->verifyModuleOrThrow();
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
            // Caller must parse into a context without colliding named structs (thin boot
            // loads bitcode before register(); post-register replace uniqueifies names).
            $this->module = $buffer->parseBitcode($this->context);
        } finally {
            $buffer->dispose();
        }
        $this->targetData = $this->module->getModuleDataLayout();
        $this->builder = $this->context->builderCreate();
        $this->refreshIntrinsicAfterModuleReplace();
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
        $suffix = (string) Config::getenv('PHP_COMPILER_INIT_SYMBOL_SUFFIX');
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
        Builtin\ParamVariadicLowering::implementLookupFunctions($this);
        Builtin\ReflectionNamedArgumentsLowering::implementLookupFunctions($this);
        Builtin\ReflectionFunctionVariadicLowering::implementLookupFunctions($this);
        // Internal literal ReflectionFunction names first — param-count bridge must include
        // their arity before ReflectionFunctionParamCountLowering's early-return (#28780).
        Builtin\ReflectionInternalFunctionLowering::implementLookupFunctions($this);
        Builtin\ReflectionFunctionParamCountLowering::implementLookupFunctions($this);
        Builtin\ReflectionMethodQueryLowering::implementLookupFunctions($this);
        VmActiveContextInitLlvm::emitPendingBeforeSeal($this);
        $this->sealInitFunction();
        $initSuffix = (string) Config::getenv('PHP_COMPILER_INIT_SYMBOL_SUFFIX');
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
        Call\RuntimeIndirectClosureCall::materializePending($this);
        TryCatchHelper::materializeAllPendingGotoResumeHandlers($this);
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
        $dumpIr = Config::getenv('PHP_COMPILER_DUMP_IR');
        if ('1' === $dumpIr || 'true' === strtolower((string) $dumpIr)) {
            $this->module->printToFile('/tmp/phpc-last.ll');
        }
        \PHPCompiler\AOT\BuildTiming::mark('verify');
        $this->verifyModuleOrThrow();
        \PHPCompiler\AOT\BuildTiming::end('verify');
        Progress::noteFunction('jit_context_verify_done');
    }

    /**
     * LLVM verify failures on large modules repeat the same line thousands of times (#36253).
     * Surface the first distinct lines and a best-effort function name instead of megabytes of IR.
     */
    private function verifyModuleOrThrow(): void
    {
        // User-script AOT: skip module verify unless asserts are on — ~110–140ms on MiniWebApp
        // and redundant with aot-smoke / differential gates for the cold-compile target (#36387).
        if ($this->isUserScriptAot()) {
            $assert = Config::getenv('PHP_COMPILER_LLVM_ASSERT');
            if ('1' !== $assert && 'true' !== strtolower((string) $assert)) {
                return;
            }
        }
        $message = '';
        if ($this->module->verify($this->module::VERIFY_ACTION_RETURN, $message)) {
            return;
        }
        $funcName = 'unknown';
        if (preg_match('/@([A-Za-z0-9_.$]+)/', $message, $match)) {
            $funcName = $match[1];
        }
        $uniqueLines = [];
        $seen = [];
        foreach (explode("\n", $message) as $line) {
            if ('' === $line || isset($seen[$line])) {
                continue;
            }
            $seen[$line] = true;
            $uniqueLines[] = $line;
            if (\count($uniqueLines) >= 20) {
                break;
            }
        }
        $dumpPath = Config::getenv('PHP_COMPILER_LLVM_VERIFY_DUMP');
        $failingFns = [];
        if (\is_string($dumpPath) && '' !== $dumpPath) {
            // Module verify's first @name is often a *callee* (e.g. valueDelref), not the
            // broken function. Per-fn LLVMVerifyFunction names the actual offenders (#36382).
            $fn = $this->module->getFirstFunction();
            while (null !== $fn) {
                if ($fn instanceof PHPLLVM\Value\Function_) {
                    $name = '?';
                    try {
                        // Value::getName() calls mistyped LLVMValueGetName; use LLVMGetValueName.
                        $raw = $fn->llvm->lib->LLVMGetValueName($fn->value);
                        $name = \is_object($raw) && method_exists($raw, 'toString')
                            ? (string) $raw->toString()
                            : (string) $raw;
                        if ('' === $name) {
                            $name = '?';
                        }
                    } catch (\Throwable) {
                    }
                    $ok = true;
                    try {
                        // LLVMVerifyFunction: 0 = ok; fromBool inverts LLVMBool.
                        // LLVMReturnStatusAction = 2.
                        $ok = $fn->llvm->fromBool(
                            $fn->llvm->lib->LLVMVerifyFunction($fn->value, 2)
                        );
                    } catch (\Throwable) {
                        $ok = false;
                    }
                    if (!$ok) {
                        $failingFns[] = $name;
                        if (\count($failingFns) <= 2 && '?' !== $name && '' !== $name) {
                            try {
                                $printed = $fn->llvm->lib->LLVMPrintValueToString($fn->value);
                                $ir = \is_object($printed) && method_exists($printed, 'toString')
                                    ? (string) $printed->toString()
                                    : (string) $printed;
                                @file_put_contents($dumpPath.'.'.$name.'.ll', $ir);
                            } catch (\Throwable) {
                            }
                        }
                    }
                }
                $next = $fn->getNext();
                if (null === $next) {
                    break;
                }
                $fn = $next;
            }
            $dump = $message;
            if ([] !== $failingFns) {
                $dump = "Failing functions (".\count($failingFns)."):\n"
                    .implode("\n", $failingFns)
                    ."\n\n".$message;
                $funcName = $failingFns[0];
            }
            @file_put_contents($dumpPath, $dump);
        }
        $head = implode("\n", $uniqueLines);
        $totalLines = substr_count($message, "\n") + ('' !== $message && !str_ends_with($message, "\n") ? 1 : 0);
        $suffix = $totalLines > \count($uniqueLines)
            ? "\n… (".($totalLines - \count($uniqueLines)).' more lines truncated)'
            : '';
        if ([] !== $failingFns) {
            $suffix .= "\nFailing functions: ".implode(', ', \array_slice($failingFns, 0, 12))
                .(\count($failingFns) > 12 ? ' …(+'.(\count($failingFns) - 12).')' : '');
        }
        throw new \RuntimeException(
            "Module verification failed in function {$funcName}:\n{$head}{$suffix}"
        );
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
     * Default (unset or 0): cheap function-scoped passes (SROA, EarlyCSE, InstCombine, CFG simplify,
     * AlwaysInliner) over user-emitted functions so `alwaysinline` helpers fold without the 13–25×
     * whole-module O2 cost. PHP_COMPILER_OPT_LEVEL=1–3 selects the full PassManagerBuilder pipeline;
     * `none`/`off` disables IR optimisation entirely. Set PHP_COMPILER_OPT_SIZE_LEVEL to bias for size.
     *
     * User-script AOT (`PHP_COMPILER_AOT_USER_SCRIPT=1`) defaults to skipping light passes when
     * OPT_LEVEL is unset — cold MiniWebApp compile wall drops ~1.2s (#36387). Explicit `0`/`1`/`2`/`3`
     * still enables the corresponding pipeline.
     */
    private function runModuleOptimizationPasses(): void
    {
        $raw = Config::getenv('PHP_COMPILER_OPT_LEVEL');
        if (is_string($raw)) {
            $normalized = strtolower(trim($raw));
            if (in_array($normalized, ['none', 'off', 'false'], true)) {
                return;
            }
        }
        // User-script AOT: skip default light IR passes unless OPT_LEVEL is explicit.
        // Measured cold MiniWebApp (bench-gate --include path): link ~4.1s → ~2.7s,
        // total ~12.0s → ~10.4s. Helpers are prelinked; IR is already interpreter-shaped
        // so SROA/EarlyCSE buys little compile-time wall (#36387, same rationale as
        // TargetMachine OptNone in createAotTargetMachine). Set PHP_COMPILER_OPT_LEVEL=0
        // (light) or 1–3 (heavy) to re-enable.
        if (!is_string($raw) || '' === trim($raw)) {
            $userAot = Config::getenv('PHP_COMPILER_AOT_USER_SCRIPT');
            if ('1' === $userAot || 'true' === strtolower((string) $userAot)) {
                return;
            }
        }
        $level = is_string($raw) && ctype_digit($raw) ? (int) $raw : 0;
        if ($level >= 1) {
            $this->runHeavyModuleOptimizationPasses(min($level, 3));

            return;
        }
        $this->runLightModuleOptimizationPasses();
    }

    /**
     * Cheap IR cleanup at the default opt level (#36213): SROA + EarlyCSE + InstCombine +
     * CFGSimplify over user-emitted functions only. Legacy LLVM 9 function pass managers
     * segfault if AlwaysInliner/FunctionInlining passes are registered (module passes on an FPM).
     */
    private function runLightModuleOptimizationPasses(): void
    {
        $targets = $this->lightOptimizationFunctionTargets();
        if ([] === $targets) {
            return;
        }

        Progress::noteFunction('jit_context_opt_passes_begin');
        $functionPasses = $this->module->createFunctionPassManager();
        if (!$functionPasses instanceof PHPLLVM\LLVMAbstract\PassManager) {
            throw new \RuntimeException('expected LLVMAbstract PassManager for light opt passes');
        }
        $pm = $functionPasses->passManager;
        $lib = $this->llvm->lib;
        $lib->LLVMAddScalarReplAggregatesPassSSA($pm);
        $lib->LLVMAddEarlyCSEPass($pm);
        $lib->LLVMAddInstructionCombiningPass($pm);
        $lib->LLVMAddCFGSimplificationPass($pm);
        $functionPasses->initializeFunctionPassManager();
        foreach ($targets as $function) {
            $functionPasses->runFunctionPassManager($function);
        }
        $functionPasses->finalizeFunctionPassManager();
        $functionPasses->dispose();
        Progress::noteFunction('jit_context_opt_passes_done');
    }

    /**
     * LLVM functions lowered in this compile (not the prelinked helper corpus).
     *
     * @return list<PHPLLVM\Value\Function_>
     */
    private function lightOptimizationFunctionTargets(): array
    {
        $seen = [];
        $targets = [];
        $add = function (?PHPLLVM\Value $function) use (&$seen, &$targets): void {
            if (!$function instanceof PHPLLVM\Value\Function_) {
                return;
            }
            $key = spl_object_id($function);
            if (isset($seen[$key])) {
                return;
            }
            $seen[$key] = true;
            $targets[] = $function;
        };

        $add($this->main);
        $add($this->initFunc);
        $add($this->shutdownFunc);
        $add($this->headerPreFlushFunc);

        foreach ($this->functionLlvmSymbols as $symbol) {
            if (!is_string($symbol) || '' === $symbol) {
                continue;
            }
            $named = $this->module->getNamedFunction($symbol);
            $add($named);
        }

        return $targets;
    }

    private function runHeavyModuleOptimizationPasses(int $level): void
    {
        $sizeLevel = Config::getenv('PHP_COMPILER_OPT_SIZE_LEVEL');
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
        $flag = Config::getenv('PHP_COMPILER_DEBUG_LLVM_BLOCKS');
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
        // Lazy __value__writeDouble — Value::implement no longer eager-implements (#36141 /
        // peer #36135 writeLong). Thin hello-world must not emit double-box LLVM during init.
        if ('__value__writeDouble' === $name) {
            Builtin\ValueBoxWriteDoubleJit::ensureLinked($this);
        }
        // Lazy __value__writeLong — Value::implement no longer eager-implements (#36135 /
        // peer #36124 writeNull). Thin hello-world must not emit long-box LLVM during init.
        if ('__value__writeLong' === $name) {
            Builtin\ValueBoxWriteLongJit::ensureLinked($this);
        }
        // Lazy __value__writeNull — Value::implement no longer eager-implements (#36124 /
        // peer #36108 writeBool). Thin hello-world must not emit null-box LLVM during init.
        if ('__value__writeNull' === $name) {
            Builtin\ValueBoxWriteNullJit::ensureLinked($this);
        }
        // Lazy __value__writeBool — Value::implement no longer eager-implements (#36108 /
        // peer #36100 malloc). Thin hello-world must not emit bool-box LLVM during init.
        if ('__value__writeBool' === $name) {
            Builtin\ValueBoxWriteBoolJit::ensureLinked($this);
        }
        // Lazy __value__copy — one outlined type-switch per module (#36193).
        if ('__value__copy' === $name) {
            Builtin\ValueBoxCopyJit::ensureLinked($this);
        }
        if (isset($this->functionScope[$name])) {
            return $this->functionScope[$name];
        }
        // After edit-scaffold / bitcode restore, prefer live module over stale scope (#36387).
        $fromModule = $this->module->getNamedFunction($name);
        if ($fromModule instanceof PHPLLVM\Value\Function_) {
            try {
                // php-llvm may wrap a null LLVMValueRef; probe before trusting it.
                $fromModule->countParams();
                $this->functionScope[$name] = $fromModule;

                return $fromModule;
            } catch (\Throwable $e) {
                // fall through to lazy ensure / throw
            }
        }
        // Lazy libc exit(3)/abort(3) — Type::register no longer always-on ensures (#35428 /
        // leftover #33267 / peer #35392). ~292 call sites lookup without a nearby ensure.
        // Lazy setlocale(3) — LocaleStartupRuntime ensures before use (#36074 / #30789).
        // Lazy malloc/realloc/free — MemoryManager\Native::implement + NestedJIT leaves
        // (#36100 / peer #32273); register() no longer eager-ensures the family.
        if ('exit' === $name || 'abort' === $name) {
            LibcExtern::ensureExitAbort($this);
            if (isset($this->functionScope[$name])) {
                return $this->functionScope[$name];
            }
        }
        if ('setlocale' === $name) {
            LibcExtern::ensureSetlocaleDecl($this);
            if (isset($this->functionScope[$name])) {
                return $this->functionScope[$name];
            }
        }
        if ('malloc' === $name || 'realloc' === $name || 'free' === $name) {
            LibcExtern::ensureMallocFamily($this);
            if (isset($this->functionScope[$name])) {
                return $this->functionScope[$name];
            }
        }
        // Lazy stream I/O ABI — Type::register no longer always-on ensures (#33055).
        // SPINE_CHUNK standard hub (VmFs slice) lowers fread before StreamIo::ensureLinked
        // (#36155 Phase B/C); JitFread::invoke lookupFunction must self-ensure.
        if (Builtin\StreamIoRuntime::isLazyLookupRuntimeFunction($name)) {
            Builtin\StreamIoRuntime::ensureLinkedForUserScriptLowering($this);
            if (isset($this->functionScope[$name])) {
                return $this->functionScope[$name];
            }
        }
        throw new \LogicException('Unable to lookup non-existing function ' . $name);
    }

    /** Scope probe for LibcExtern ensure* without re-entering lookupFunction (#35428). */
    public function tryGetRegisteredFunction(string $name): ?PHPLLVM\Value\Function_ {
        return $this->functionScope[$name] ?? null;
    }

    public function registerFunction(string $name, PHPLLVM\Value\Function_ $func): void {
        $this->functionScope[$name] = $func;
    }

    public function registerType(string $name, PHPLLVM\Type $type): void {
        $this->typeMap[$name] = $type;
    }

    /**
     * Prefer an existing module named struct (edit-scaffold bitcode) over CreateNamed (#36387).
     *
     * CreateNamed after parseBitcode uniqueifies (__string__.11) and breaks GEPs.
     */
    public function namedStructType(string $name): PHPLLVM\Type
    {
        if (CompileCache::isEditScaffoldActive() || null !== CompileCache::pendingEditScaffoldKey()) {
            try {
                $existing = $this->module->getTypeByName($name);
                if ($existing instanceof PHPLLVM\Type\Struct) {
                    return $existing;
                }
                if ($existing instanceof PHPLLVM\Type) {
                    try {
                        $existing->getKind();

                        return $existing;
                    } catch (\Throwable $e) {
                        // null wrapper — fall through
                    }
                }
            } catch (\Throwable $e) {
                // fall through to CreateNamed
            }
        }

        return $this->context->namedStructType($name);
    }

    /**
     * setBody only when the named struct is still opaque (bitcode already defined it) (#36387).
     */
    public function setNamedStructBody(PHPLLVM\Type $struct, bool $packed, PHPLLVM\Type ...$elements): void
    {
        if ($struct instanceof PHPLLVM\Type\Struct) {
            try {
                if (!$struct->isOpaque()) {
                    return;
                }
            } catch (\Throwable $e) {
                // try setBody anyway
            }
        }
        if (!method_exists($struct, 'setBody')) {
            return;
        }
        $struct->setBody($packed, ...$elements);
    }

    public function castToBool(PHPLLVM\Value $value): PHPLLVM\Value {
        $type = $value->typeOf();
        $typeName = $this->getStringFromType($type);
        // CreateNamed uniquify (`__object__.2*`) — same as Call\Native (#36382).
        if (str_starts_with($typeName, '__object__') && str_ends_with($typeName, '*')) {
            $typeName = '__object__*';
        } elseif (str_starts_with($typeName, '__hashtable__') && str_ends_with($typeName, '*')) {
            $typeName = '__hashtable__*';
        } elseif (str_starts_with($typeName, '__string__') && str_ends_with($typeName, '*')) {
            $typeName = '__string__*';
        } elseif (str_starts_with($typeName, '__value__') && str_ends_with($typeName, '*')) {
            $typeName = '__value__*';
        }
        switch ($typeName) {
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
            case 'double':
            case 'float':
                // zend_is_true(IS_DOUBLE): != 0.0 including NaN (#35220 JUMPIF on native float).
                return $this->builder->fcmp(
                    $this->builder::REAL_UNE,
                    $value,
                    $type->constReal(0.0)
                );
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
            case '__object__':
                // zend_is_true / zend_std_cast_object_to_type(_IS_BOOL) → true (#32471 leftover of #32463).
                return $this->constantFromBool(true);
            case '__object__*':
                // Typed `?T $p` / omitted null args are `__object__*` null pointers, not
                // objects — zend_is_true(IS_NULL) is false. Always-true here made `??`
                // never fall through (Slim RouteCollectorProxy / AppFactory::create, #36382).
                // php-src: Zend/zend_operators.c zend_is_true / IS_NULL vs IS_OBJECT.
                return $this->builder->icmp(
                    $this->builder::INT_NE,
                    $value,
                    $type->constNull()
                );
            case '__hashtable__':
            case '__hashtable__*':
                // zend_is_true(IS_ARRAY): zend_hash_num_elements ? true : false (#32455 / #32471).
                $ht = $value;
                if ('__hashtable__' === $this->getStringFromType($type)) {
                    $slot = BasicBlockHelper::entryAlloca($this, $type);
                    $this->builder->store($value, $slot);
                    $ht = $slot;
                }
                $n = ArrayBuiltinHelper::getNumElements($this, $ht);

                return $this->builder->icmp(
                    $this->builder::INT_NE,
                    $n,
                    $n->typeOf()->constInt(0, false)
                );
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
                // PHPTypes Type::fromDecl('mixed') mis-parses as object userType mixed (#12348 / #32728).
                if ('mixed' === strtolower((string) ($type->userType ?? ''))) {
                    return $this->getTypeFromString('__value__');
                }

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
        // CreateNamed after helper/bitcode merge uniqueifies (`__value__.2`); map back to
        // the seeded core name so Slim AOT guards see `__value__*` not `unknown*` (#36382).
        if (method_exists($type, 'getName')) {
            $llvmName = (string) $type->getName();
            if ('' !== $llvmName) {
                $stripped = self::stripLlvmUniquifySuffix($llvmName);
                if (isset($this->typeMap[$stripped])) {
                    return $stripped;
                }
                if (isset($this->typeMap[$llvmName])) {
                    return $llvmName;
                }
                if (isset($this->structFieldMap[$stripped])) {
                    return $stripped;
                }
            }
        }
        $repr = $type->toString();
        foreach (array_keys($this->typeMap) as $name) {
            if (str_contains($name, '*')) {
                continue;
            }
            if ('' !== $name && str_contains($repr, $name)) {
                return $name;
            }
        }

        return 'unknown';
    }

    /** structFieldMap index for a struct value or pointer (issue #1880). */
    public function structFieldIndex(PHPLLVM\Value $structOrPtr, string $field): int
    {
        $map = $this->structFieldsFor($structOrPtr);
        if (!isset($map[$field])) {
            $structName = $this->resolveStructMapName(
                PHPLLVM\Type::KIND_POINTER === $structOrPtr->typeOf()->getKind()
                    ? $structOrPtr->typeOf()->getElementType()
                    : $structOrPtr->typeOf()
            );
            throw new \LogicException(
                "structFieldIndex: struct {$structName} has no field {$field} (llvm {$structOrPtr->typeOf()->toString()})"
            );
        }

        return $map[$field];
    }

    /**
     * Field map for a struct value/pointer, resolving LLVM CreateNamed suffixes (#36387).
     *
     * @return array<string, int>
     */
    public function structFieldsFor(PHPLLVM\Value $structOrPtr): array
    {
        $ty = $structOrPtr->typeOf();
        $structTy = PHPLLVM\Type::KIND_POINTER === $ty->getKind()
            ? $ty->getElementType()
            : $ty;

        return $this->structFieldsForType($structTy);
    }

    /**
     * Field map for an LLVM struct type name, resolving `__string__.2` → `__string__` (#36387).
     *
     * @return array<string, int>
     */
    public function structFieldsForTypeName(string $llvmName): array
    {
        if (isset($this->structFieldMap[$llvmName]) && is_array($this->structFieldMap[$llvmName])) {
            return $this->structFieldMap[$llvmName];
        }
        $stripped = self::stripLlvmUniquifySuffix($llvmName);
        if (
            $stripped !== $llvmName
            && isset($this->structFieldMap[$stripped])
            && is_array($this->structFieldMap[$stripped])
        ) {
            // Lazy-alias so subsequent raw map lookups in older call sites succeed.
            $this->structFieldMap[$llvmName] = $this->structFieldMap[$stripped];

            return $this->structFieldMap[$llvmName];
        }

        throw new \LogicException("structFieldsForTypeName: no field map for {$llvmName}");
    }

    /**
     * @return array<string, int>
     */
    public function structFieldsForType(PHPLLVM\Type $structTy): array
    {
        $name = $this->resolveStructMapName($structTy);
        if (!isset($this->structFieldMap[$name]) || !is_array($this->structFieldMap[$name])) {
            throw new \LogicException(
                'structFieldsForType: no field map for '.$name.' (llvm '.$structTy->toString().')'
            );
        }
        // If LLVM name is uniquified, alias it for raw call sites (#36387).
        if (method_exists($structTy, 'getName')) {
            $llvmName = (string) $structTy->getName();
            if ($llvmName !== $name && $llvmName !== '') {
                $this->structFieldMap[$llvmName] = $this->structFieldMap[$name];
            }
        }

        return $this->structFieldMap[$name];
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
            $stripped = self::stripLlvmUniquifySuffix($base);
            if ($stripped !== $base && isset($this->structFieldMap[$stripped])) {
                return $stripped;
            }
        }
        if (method_exists($structTy, 'getName')) {
            $llvmName = (string) $structTy->getName();
            if (isset($this->structFieldMap[$llvmName])) {
                return $llvmName;
            }
            // CreateNamed after bitcode uniqueifies (__string__.2); map to the seeded core (#36387).
            $stripped = self::stripLlvmUniquifySuffix($llvmName);
            if ($stripped !== $llvmName && isset($this->structFieldMap[$stripped])) {
                return $stripped;
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

    /** LLVM CreateNamed uniquify suffix: `__string__.2` → `__string__` (#36387). */
    public static function stripLlvmUniquifySuffix(string $name): string
    {
        if (preg_match('/^(.+)\.(\d+)$/', $name, $m)) {
            return $m[1];
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
            // LLVM forbids pointers-to-void in bitcode (Invalid type on LLVMParseBitcode).
            // Map void*/void** to i8*/i8** so full-module module.bc round-trips (#36387).
            case 'void*':
            case 'const void*':
                return $this->context->int8Type()->pointerType(0);
            case 'void**':
            case 'const void**':
                return $this->context->int8Type()->pointerType(0)->pointerType(0);
            case 'const char':
                return $this->context->int8Type();
            case 'char':
            case 'unsigned char':
            case 'int8':
            case 'uint8_t':
            case 'int8_t':
                return $this->context->int8Type();
            case 'int16':
            case 'short':
            case 'unsigned short':
            case 'uint16_t':
            case 'int16_t':
                return $this->context->int16Type();
            case 'int32':
            case 'int':
            case 'unsigned':
            case 'unsigned int':
            case 'uint32_t':
            case 'int32_t':
            case 'uint':
                return $this->context->int32Type();
            case 'int64':
            // NestedJIT / FFI may emit plain "long"; LP64 maps to i64 (#35424).
            case 'long':
            case 'unsigned long':
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
            $base = substr($type, 0, -1);
            // void***… and any peel that would form pointer-to-void (#36387).
            if ('void' === $base || 'const void' === $base) {
                return $this->context->int8Type()->pointerType(0);
            }

            return $this->getTypeFromString($base)->pointerType(0);
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
        $llvmType = $this->getTypeFromString($type === null ? 'double' : $type);
        // PHP FFI LLVMConstReal(double) drops IEEE specials: -INF becomes 2^63 (#32317).
        // Positive inf/nan via LLVMConstRealOfString; -INF via LLVMConstFNeg(+inf)
        // (llvm-c Core.h — zend_operators.c zendi_negate_function analog).
        if (\is_nan($value) || \is_infinite($value)) {
            $text = \is_nan($value) ? 'nan' : 'inf';
            $raw = $this->llvm->lib->LLVMConstRealOfString($llvmType->type, $text);
            if (null === $raw) {
                throw new \LogicException('LLVMConstRealOfString failed for '.$text.' (#32317)');
            }
            if (\is_infinite($value) && $value < 0.0) {
                $neg = $this->llvm->lib->LLVMConstFNeg($raw);
                if (null === $neg) {
                    throw new \LogicException('LLVMConstFNeg failed for -inf (#32317)');
                }
                $raw = $neg;
            }

            return $this->llvm->factory->value($this->context, $raw);
        }

        return $llvmType->constReal($value);
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
            $globalName = $this->moduleLocalConstGlobalName('string_const_', \count($this->stringConstantMap));
            $this->stringConstantMap[$string] = StaticImmortalStringLlvm::definePtrGlobal(
                $this,
                $string,
                $globalName
            );
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
     * Immortal module global for file-scope {@code const C = new …} (#35196).
     *
     * Peer of class-const object globals ({@see Builtin\Type\Object_::defineClassConst}) and
     * file-scope enum rematerialization (#34783). Copies declared property values from the
     * VM ObjectEntry so constructor args survive AOT CONST_FETCH.
     *
     * php-src: Zend/zend_constants.c — file consts hold persistent object zvals.
     */
    public function constantObjectFromVm(string $cacheKey, VMVariable $phpVar): PHPLLVM\Value
    {
        if (isset($this->objectConstantMap[$cacheKey])) {
            return $this->objectConstantMap[$cacheKey];
        }
        if (isset($this->constants[$cacheKey][1])) {
            return $this->constants[$cacheKey][1];
        }
        $phpVar = $phpVar->resolveIndirect();
        if (VMVariable::TYPE_OBJECT !== $phpVar->type) {
            throw new \LogicException('constantObjectFromVm requires TYPE_OBJECT');
        }
        $object = $phpVar->toObject();
        $className = $object->class->name;
        $classLc = strtolower(ltrim($className, '\\'));
        $classId = $this->type->object->lookup($classLc);
        $objPtrType = $this->getTypeFromString('__object__*');
        $global = $this->module->addGlobal(
            $objPtrType,
            $this->moduleLocalConstGlobalName('object_const_', \count($this->objectConstantMap))
        );
        $global->setInitializer($objPtrType->constNull());
        $this->objectConstantMap[$cacheKey] = $global;
        /** @var array<string, VMVariable> $propSnapshot */
        $propSnapshot = [];
        foreach ($object->propertiesWithNames() as $propName => $propVar) {
            if (!\is_string($propName) || '' === $propName) {
                continue;
            }
            $propSnapshot[$propName] = \PHPCompiler\VM\ClassConstMaterializer::detachConstantValue($propVar);
        }
        $this->emitInInit(function (Context $ctx) use ($classId, $global, $propSnapshot): void {
            $alloc = $ctx->type->object->allocateClassConstantObject($classId);
            foreach ($propSnapshot as $propName => $propVm) {
                $nameId = $ctx->type->object->propNameIdFor($propName);
                $propset = null !== $nameId
                    ? $ctx->type->object->resolvePropertySetForNameId($classId, $nameId)
                    : null;
                if (null === $propset) {
                    $jitType = Variable::fromVMVariable($propVm->type);
                    $ctx->type->object->defineProperty($classId, $propName, $jitType);
                    $nameId = $ctx->type->object->propNameIdAfterDefine($propName);
                    if (null === $nameId) {
                        continue;
                    }
                    $propset = $ctx->type->object->resolvePropertySetForNameId($classId, $nameId);
                }
                if (null === $propset) {
                    continue;
                }
                try {
                    $jitVal = VmConstantJit::toVariable($ctx, $propVm);
                } catch (\Throwable) {
                    continue;
                }
                $slot = $ctx->type->object->propertySlotPtr($alloc, $propset[3]);
                $ctx->type->object->propertyStore($slot, $jitVal, $propset[2]);
            }
            $ctx->builder->store($alloc, $global);
        });
        $this->constants[$cacheKey] = [Variable::TYPE_OBJECT, $global];

        return $global;
    }

    /**
     * LLVM global name for module-local compile-time constants.
     *
     * Helper units set {@see PHP_COMPILER_INIT_SYMBOL_SUFFIX}; the main script uses
     * `_main` so bare string_const_N from stale prelinked helpers cannot clobber it.
     */
    private function moduleLocalConstGlobalName(string $prefix, int $index): string
    {
        $suffix = (string) Config::getenv('PHP_COMPILER_INIT_SYMBOL_SUFFIX');
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
            // propertyStore TYPE_VALUE may leave insert in prop_store_box_ready (#34649);
            // keep initLinearBlock on the open tail so the next emitInInit does not see a
            // sealed "main" (#34662).
            $insert = BasicBlockHelper::tryGetInsertBlock($this);
            if (null !== $insert && null === $insert->getTerminator()) {
                $this->initLinearBlock = $insert;
            }
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

}
