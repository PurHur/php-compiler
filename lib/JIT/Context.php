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

    /** Logical callee lc => LLVM symbol for chunk manifest export (#36155). */
    public array $functionLlvmSymbols = [];

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

    /** ?? / ?-> result operands that must receive branch assigns even when php-cfg marks them dead (#99, #3219). */
    public \SplObjectStorage $coalesceAssignTargets;

    /**
     * PROPERTY_FETCH_WRITE feeding ??= : keep objectPropertySlot on force-merge copies (#33748).
     * ?-> / ?? reads leave this false so merge seats stay stack boxes (#32988 / #3219).
     */
    public bool $retainCoalesceInstancePropertyLvalue = false;

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
        if ($this->isUserScriptAot() && str_contains($lc, '::')) {
            $vmOnly = self::findInternalClassMethodInVmRegistry($this, $lc);
            if (null !== $vmOnly && !self::internalBuiltinHasJitLowering($vmOnly)) {
                throw new \LogicException(
                    \sprintf('%s is registered in the VM but has no JIT lowering (#36202)', $proxyName)
                );
            }
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
        $this->ensureDomLivingDocumentFactoryProxies();
        if (isset($this->functionProxies[$lc])
            && !($this->functionProxies[$lc] instanceof Call\ExternalMethod)) {
            return $this->functionProxies[$lc];
        }

        return null;
    }

    /** Dom\HTMLDocument/XMLDocument factory Call proxies — thin user-script AOT (#27108, #27300, #35804). */
    private function ensureDomLivingDocumentFactoryProxies(): void
    {
        if (!CompilerVersion::supportsDomLivingStandardNamespaceJitLowering()) {
            return;
        }
        if (!isset($this->functionProxies['dom\\htmldocument::createfromstring'])) {
            $this->functionProxies['dom\\xmldocument::createfromstring'] = new Call\DomXmlDocumentCreateFromString();
            $this->functionProxies['dom\\htmldocument::createfromstring'] = new Call\DomHtmlDocumentCreateFromString();
            $this->functionProxies['dom\\xmldocument::createfromfile'] = new Call\DomXmlDocumentCreateFromFile();
            $this->functionProxies['dom\\htmldocument::createfromfile'] = new Call\DomHtmlDocumentCreateFromFile();
        }
    }

    private function resolveRegisteredInternalBuiltin(string $lc): ?FuncInternal
    {
        foreach ($this->modules as $module) {
            $found = self::findInternalBuiltinInModule($module, $lc);
            if (null !== $found) {
                return $found;
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
                $found = self::findInternalBuiltinInModule($module, $lc);
                if (null !== $found) {
                    return $found;
                }
            }
        }

        // Spine split-TU: chunk entry is bare require_once lines — registerModule() never
        // runs, so a few stdlib Internal leaves would ExternalMethod-null (#36147). Whitelist
        // only type-check/count kernels safe to emit without the full stdlib surface (#15417).
        if ([] === $this->modules
            && \PHPCompiler\AOT\ExternalMethodBind::spineChunkMode()
            && self::isSpineChunkRuntimeInternalKernel($lc)
            && [] !== $this->runtime->modules
        ) {
            foreach ($this->runtime->modules as $module) {
                $found = self::findInternalBuiltinInModule($module, $lc);
                if (null !== $found) {
                    return $found;
                }
            }
        }

        // Registered builtin class methods with JIT lowering — one table for VM and JIT (#36202).
        // VM-only handlers stay on ExternalMethod until call() is implemented (helper infra).
        if (!\PHPCompiler\AOT\ExternalMethodBind::spineChunkMode()) {
            $vmMethod = self::findInternalClassMethodInVmRegistry($this, $lc);
            if (null !== $vmMethod && self::internalBuiltinHasJitLowering($vmMethod)) {
                return $vmMethod;
            }
        }

        return null;
    }

    /**
     * VM registry lookup for `Class::method` — one table for VM and JIT (#36202).
     */
    private static function findInternalClassMethodInVmRegistry(self $context, string $lc): ?FuncInternal
    {
        if (!str_contains($lc, '::')) {
            return null;
        }
        [$classLc, $methodLc] = explode('::', $lc, 2);
        $classLc = strtolower(ltrim($classLc, '\\'));
        $methodLc = strtolower($methodLc);
        if ('' === $classLc || '' === $methodLc) {
            return null;
        }

        $vm = $context->runtime->vmContext;
        while (isset($vm->classAliases[$classLc])) {
            $classLc = $vm->classAliases[$classLc];
        }
        if (!isset($vm->classes[$classLc])) {
            return null;
        }

        $entry = $vm->classes[$classLc];
        if (!isset($entry->methods[$methodLc])) {
            return null;
        }

        $method = $entry->methods[$methodLc];
        if (!$method instanceof FuncInternal) {
            return null;
        }

        return $method;
    }

    /** True when an Internal / VmClassMethod overrides {@see VmClassMethod::call()} for JIT. */
    private static function internalBuiltinHasJitLowering(FuncInternal $method): bool
    {
        if (!$method instanceof \PHPCompiler\VM\Builtin\VmClassMethod) {
            return true;
        }
        $ref = new \ReflectionMethod($method, 'call');

        return \PHPCompiler\VM\Builtin\VmClassMethod::class !== $ref->getDeclaringClass()->getName();
    }

    /**
     * Internal builtins safe to resolve from Runtime modules during SPINE_CHUNK chunk emits.
     *
     * Opening the full stdlib surface pulls in helpers that fail module verify in isolation
     * (e.g. strncasecmp LLVM verify — Instruction referencing instruction not embedded in a basic
     * block; #36147). Same constraint as NestedJIT pre-register whitelist (#15417).
     */
    private static function isSpineChunkRuntimeInternalKernel(string $lc): bool
    {
        return match ($lc) {
            'count',
            'in_array',
            'intdiv',
            'intval',
            'is_float',
            'is_nan',
            'is_numeric',
            'sprintf',
            'strlen',
            'strpbrk',
            'strpos',
            'strtolower',
            'substr',
            'trim',
            => true,
            default => false,
        };
    }

    private static function findInternalBuiltinInModule(Module $module, string $lc): ?FuncInternal
    {
        foreach ($module->getFunctions() as $func) {
            if (!$func instanceof FuncInternal) {
                continue;
            }
            if (strtolower($func->getName()) === $lc) {
                return $func;
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
            // link(2) NestedJIT leaf (#33406) — whitelist link → link_::call →
            // StringLink::invokeNestedLeaf (module-local link(2); peer rename #29141).
            'link',
            // symlink(2) NestedJIT leaf (#33417) — whitelist symlink → symlink_::call →
            // StringSymlink::invokeNestedLeaf (module-local symlink(2); peer link #33406).
            'symlink',
            // chown(2)/chgrp NestedJIT leaf (#32466) — whitelist → chown_/chgrp_::call →
            // JitChown/JitChgrp::invokeNestedLeaf (module-local chown/fchownat; peer rename).
            'chown',
            'lchown',
            'chgrp',
            'lchgrp',
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
            // ftok NestedJIT leaf (#31478) — whitelist ftok → ftok::call →
            // FtokRuntime::invoke / JitFtokKernel thin libc ftok(3) leaf
            // (former always-on FtokRuntime stat+layout LLVM; peer posix_getpid #30696).
            'ftok',
            // posix_getppid NestedJIT leaf (#30728) — whitelist posix_getppid → posix_getppid::call →
            // PosixGetppidJit::invoke / JitPosixGetppidKernel thin libc getppid(2) leaf
            // (former always-on JitPosix::getppid LLVM; peer posix_getpid #30696).
            'posix_getppid',
            // posix_getuid NestedJIT leaf (#30744) — whitelist posix_getuid → posix_getuid::call →
            // PosixGetuidJit::invoke / JitPosixGetuidKernel thin libc getuid(2) leaf
            // (former always-on JitPosix::getuid LLVM; peer posix_getppid #30728).
            'posix_getuid',
            // posix_geteuid NestedJIT leaf (#30767) — whitelist posix_geteuid → posix_geteuid::call →
            // PosixGeteuidJit::invoke / JitPosixGeteuidKernel thin libc geteuid(2) leaf
            // (former always-on JitPosix::geteuid LLVM; peer posix_getuid #30744).
            'posix_geteuid',
            // posix_getgid NestedJIT leaf (#30803) — whitelist posix_getgid → posix_getgid::call →
            // PosixGetgidJit::invoke / JitPosixGetgidKernel thin libc getgid(2) leaf
            // (former always-on JitPosix::getgid LLVM; peer posix_geteuid #30767).
            'posix_getgid',
            // posix_getegid NestedJIT leaf (#30986) — whitelist posix_getegid → posix_getegid::call →
            // PosixGetegidJit::invoke / JitPosixGetegidKernel thin libc getegid(2) leaf
            // (former always-on JitPosix::getegid LLVM; peer posix_getgid #30803).
            'posix_getegid',
            // posix_setuid NestedJIT leaf (#31038) — whitelist posix_setuid → posix_setuid::call →
            // PosixSetuidJit::invoke / JitPosixSetuidKernel thin libc setuid(2) leaf
            // (former always-on JitPosix::setId LLVM; peer posix_getegid #30986 / proc_nice #30615).
            'posix_setuid',
            // posix_setgid NestedJIT leaf (#31066) — whitelist posix_setgid → posix_setgid::call →
            // PosixSetgidJit::invoke / JitPosixSetgidKernel thin libc setgid(2) leaf
            // (former always-on JitPosix::setId LLVM; peer posix_setuid #31038).
            'posix_setgid',
            // posix_seteuid NestedJIT leaf (#31066) — whitelist posix_seteuid → posix_seteuid::call →
            // PosixSeteuidJit::invoke / JitPosixSeteuidKernel thin libc seteuid(2) leaf
            // (former always-on JitPosix::setId LLVM; peer posix_setuid #31038).
            'posix_seteuid',
            // posix_setegid NestedJIT leaf (#31066) — whitelist posix_setegid → posix_setegid::call →
            // PosixSetegidJit::invoke / JitPosixSetegidKernel thin libc setegid(2) leaf
            // (former always-on JitPosix::setId LLVM; peer posix_setuid #31038).
            'posix_setegid',
            // posix_setsid NestedJIT leaf (#31235) — whitelist posix_setsid → posix_setsid::call →
            // PosixSetsidJit::invoke / JitPosixSetsidKernel thin libc setsid(2) leaf
            // (former always-on JitPosix::setsid LLVM; peer posix_getpid #30696 / posix_setuid #31038).
            'posix_setsid',
            // posix_setpgid NestedJIT leaf (#31235) — whitelist posix_setpgid → posix_setpgid::call →
            // PosixSetpgidJit::invoke / JitPosixSetpgidKernel thin libc setpgid(2) leaf
            // (former always-on JitPosix::setpgid LLVM; peer posix_setuid #31038 / posix_setgid #31066).
            'posix_setpgid',
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
        $jsonPath = getenv('PHP_COMPILER_EXTERNAL_STUBS_JSON');
        if (is_string($jsonPath) && '' !== $jsonPath) {
            $payload = [
                'stub_count' => count($names),
                'stubs' => $names,
                'generated_at' => gmdate('c'),
            ];
            $dir = dirname($jsonPath);
            if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
                throw new \RuntimeException('cannot create directory for external stubs JSON: '.$dir);
            }
            file_put_contents(
                $jsonPath,
                json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n"
            );
        }
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
     * Producer chunk: write logical→symbol manifest for cross-TU bind (#36155 Phase C).
     *
     * PHP_COMPILER_EXTERNAL_METHOD_MANIFEST_EXPORT=/path/to/manifest.json
     * PHP_COMPILER_EMIT_BITCODE=/path/to/chunk.bc  (optional; recorded as relative path)
     */
    private function exportChunkMethodManifestIfRequested(): void
    {
        $exportPath = getenv('PHP_COMPILER_EXTERNAL_METHOD_MANIFEST_EXPORT');
        if (!is_string($exportPath) || '' === $exportPath) {
            return;
        }
        $methods = [];
        foreach ($this->functionLlvmSymbols as $logical => $symbol) {
            if (!is_string($logical) || '' === $logical || !is_string($symbol) || '' === $symbol) {
                continue;
            }
            $methods[strtolower($logical)] = ['symbol' => $symbol];
        }
        ksort($methods, SORT_STRING);
        $bitcodeEnv = getenv('PHP_COMPILER_EMIT_BITCODE');
        $bitcodeRel = null;
        if (is_string($bitcodeEnv) && '' !== $bitcodeEnv) {
            $manifestDir = dirname($exportPath);
            $bitcodeAbs = $bitcodeEnv;
            if (!str_starts_with($bitcodeAbs, '/')) {
                $bitcodeAbs = getcwd().'/'.$bitcodeAbs;
            }
            if (str_starts_with($bitcodeAbs, $manifestDir.'/')) {
                $bitcodeRel = substr($bitcodeAbs, \strlen($manifestDir) + 1);
            } else {
                $bitcodeRel = basename($bitcodeAbs);
            }
        }
        $payload = [
            'bitcode' => $bitcodeRel,
            'method_count' => count($methods),
            'methods' => $methods,
            'generated_at' => gmdate('c'),
        ];
        $dir = dirname($exportPath);
        if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
            throw new \RuntimeException('cannot create directory for chunk method manifest: '.$dir);
        }
        file_put_contents(
            $exportPath,
            json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n"
        );
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
        if (XmlWriterInstanceMethodJit::isXmlWriterInstanceMethodProxy($lc)
            && XmlWriterInstanceMethodJit::isUserScriptAot()
        ) {
            XmlWriterInstanceMethodJit::ensureProxy($this, $lc);
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
        // detach / offsetUnset — thin AOT was a silent no-op (#33841; php-src spl_observer.c).
        $this->functionProxies['splobjectstorage::detach'] = new Call\SplObjectStorageMethod('detach');
        $this->functionProxies['splobjectstorage::offsetunset'] = new Call\SplObjectStorageMethod('offsetunset');
        // addAll / removeAll / removeAllExcept — thin AOT was a silent no-op (#33847).
        $this->functionProxies['splobjectstorage::addall'] = new Call\SplObjectStorageMethod('addall');
        $this->functionProxies['splobjectstorage::removeall'] = new Call\SplObjectStorageMethod('removeall');
        $this->functionProxies['splobjectstorage::removeallexcept'] = new Call\SplObjectStorageMethod('removeallexcept');
        // Iterator + getInfo/setInfo for thin AOT (#28707; php-src spl_observer.c).
        $this->functionProxies['splobjectstorage::rewind'] = new Call\SplObjectStorageMethod('rewind');
        $this->functionProxies['splobjectstorage::next'] = new Call\SplObjectStorageMethod('next');
        $this->functionProxies['splobjectstorage::valid'] = new Call\SplObjectStorageMethod('valid');
        $this->functionProxies['splobjectstorage::key'] = new Call\SplObjectStorageMethod('key');
        $this->functionProxies['splobjectstorage::current'] = new Call\SplObjectStorageMethod('current');
        $this->functionProxies['splobjectstorage::getinfo'] = new Call\SplObjectStorageMethod('getinfo');
        $this->functionProxies['splobjectstorage::setinfo'] = new Call\SplObjectStorageMethod('setinfo');
        // getHash — thin AOT returned empty; same wire as spl_object_hash (#33854 / #24292).
        $this->functionProxies['splobjectstorage::gethash'] = new Call\SplObjectStorageMethod('gethash');
        // serialize/unserialize — legacy x:/m: (#35117); without proxy → silent NULL (#579).
        $this->functionProxies['splobjectstorage::serialize'] = new Call\SplObjectStorageMethod('serialize');
        $this->functionProxies['splobjectstorage::unserialize'] = new Call\SplObjectStorageMethod('unserialize');
        // ArrayIterator / RecursiveArrayIterator — `__spl_ht` for thin AOT foreach (#26783, #26775).
        // Seed Countable + ArrayAccess so count()/offset* candidates resolve (#32910).
        $this->type->object->lookup('ArrayIterator');
        $this->type->object->lookup('RecursiveArrayIterator');
        $this->functionProxies['arrayiterator::__construct'] = new Call\ArrayIteratorConstruct('ArrayIterator');
        $this->functionProxies['recursivearrayiterator::__construct'] = new Call\ArrayIteratorConstruct(
            'RecursiveArrayIterator'
        );
        foreach ([
            'count',
            'append',
            // php-src zim_ArrayIterator_getArrayCopy — was missing → silent null (#34002).
            'getArrayCopy',
            'offsetGet',
            'offsetSet',
            'offsetExists',
            'offsetUnset',
            'asort',
            'ksort',
            'natsort',
            'natcasesort',
            // php-src spl_array_object_uasort/uksort — thin AOT was a silent no-op (#33613).
            'uasort',
            'uksort',
            // php-src zim_ArrayIterator_getFlags/setFlags — thin AOT was a silent no-op (#33616).
            'getFlags',
            'setFlags',
            // php-src zim_ArrayIterator_serialize/unserialize — silent-null (#579 / #35111)
            'serialize',
            'unserialize',
        ] as $aiMethod) {
            $this->functionProxies['arrayiterator::'.strtolower($aiMethod)] = new Call\ArrayIteratorMethod(
                $aiMethod,
                'ArrayIterator'
            );
            $this->functionProxies['recursivearrayiterator::'.strtolower($aiMethod)] = new Call\ArrayIteratorMethod(
                $aiMethod,
                'RecursiveArrayIterator'
            );
        }
        // ArrayObject — same `__spl_ht` construct + count/ArrayAccess/getArrayCopy (#26823).
        $this->type->object->lookup('ArrayObject');
        $this->functionProxies['arrayobject::__construct'] = new Call\ArrayIteratorConstruct('ArrayObject');
        foreach ([
            'count',
            'append',
            'getArrayCopy',
            'exchangeArray',
            'offsetGet',
            'offsetSet',
            'offsetExists',
            'offsetUnset',
            'getIteratorClass',
            'getIterator',
            // php-src spl_array_object_sort — thin AOT was a silent no-op (#33606).
            'asort',
            'ksort',
            'natsort',
            'natcasesort',
            // php-src spl_array_object_uasort/uksort — thin AOT was a silent no-op (#33613).
            'uasort',
            'uksort',
            // php-src zim_ArrayObject_getFlags/setFlags — thin AOT was a silent no-op (#33616).
            'getFlags',
            'setFlags',
            // php-src zim_ArrayObject_serialize/unserialize — silent-null (#579 / #35111)
            'serialize',
            'unserialize',
        ] as $aoMethod) {
            $this->functionProxies['arrayobject::'.strtolower($aoMethod)] = new Call\ArrayObjectMethod($aoMethod);
        }
        // RecursiveIteratorIterator — flatten inner HT to LEAVES_ONLY `__spl_ht` (#26775).
        $this->functionProxies['recursiveiteratoriterator::__construct'] = new Call\RecursiveIteratorIteratorConstruct();
        // php-src ZEND_PARSE_PARAMETERS_* — excess argc ArgumentCountError (#30956).
        $this->functionProxies['recursiveiteratoriterator::getdepth'] = new Call\RecursiveIteratorIteratorArgcMethod('getDepth', 0);
        $this->functionProxies['recursiveiteratoriterator::setmaxdepth'] = new Call\RecursiveIteratorIteratorArgcMethod('setMaxDepth', 1);
        $this->functionProxies['recursiveiteratoriterator::getsubiterator'] = new Call\RecursiveIteratorIteratorArgcMethod('getSubIterator', 1);
        // LimitIterator / AppendIterator / RegexIterator / CallbackFilterIterator — `__spl_ht` (#26825, #27259).
        $this->type->object->lookup('LimitIterator');
        $this->type->object->lookup('AppendIterator');
        $this->type->object->lookup('RegexIterator');
        $this->type->object->lookup('CallbackFilterIterator');
        $this->functionProxies['limititerator::__construct'] = new Call\LimitIteratorConstruct();
        $this->functionProxies['limititerator::rewind'] = new Call\LimitIteratorMethod('rewind');
        $this->functionProxies['limititerator::seek'] = new Call\LimitIteratorMethod('seek');
        $this->functionProxies['appenditerator::__construct'] = new Call\AppendIteratorMethod('__construct');
        $this->functionProxies['appenditerator::append'] = new Call\AppendIteratorMethod('append');
        $this->functionProxies['regexiterator::__construct'] = new Call\RegexIteratorConstruct();
        $this->functionProxies['callbackfilteriterator::__construct'] = new Call\CallbackFilterIteratorConstruct();
        $this->type->object->lookup('CachingIterator');
        $this->functionProxies['cachingiterator::__construct'] = new Call\CachingIteratorConstruct();
        $this->functionProxies['cachingiterator::getcache'] = new Call\CachingIteratorGetCache();
        foreach (['getFlags', 'setFlags'] as $citMethod) {
            $this->functionProxies['cachingiterator::'.strtolower($citMethod)] = new Call\CachingIteratorMethod(
                $citMethod
            );
        }
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
        // php-src ZEND_PARSE_PARAMETERS_NONE; hasChildren ACE cites RecursiveFilterIterator (#30956).
        $this->functionProxies['parentiterator::accept'] = new Call\ParentIteratorArgcMethod(
            'accept',
            'ParentIterator::accept'
        );
        $this->functionProxies['parentiterator::haschildren'] = new Call\ParentIteratorArgcMethod(
            'hasChildren',
            'RecursiveFilterIterator::hasChildren'
        );
        $this->functionProxies['multipleiterator::__construct'] = new Call\MultipleIteratorMethod('__construct');
        $this->functionProxies['multipleiterator::attachiterator'] = new Call\MultipleIteratorMethod('attachIterator');
        $this->functionProxies['recursivetreeiterator::__construct'] = new Call\RecursiveTreeIteratorConstruct();
        // Directory — dir() factory object + read/rewind/close (#30757).
        $this->type->object->lookup('Directory');
        foreach (['__construct', 'read', 'rewind', 'close'] as $dirMethod) {
            $this->functionProxies['directory::'.strtolower($dirMethod)] = new Call\DirectoryMethod(
                $dirMethod
            );
        }
        // DirectoryIterator / FilesystemIterator / RecursiveDirectoryIterator / SplFileInfo —
        // dir snapshot + Iterator (#27289 … #33298, #34624).
        $this->type->object->lookup('SplFileInfo');
        $this->type->object->lookup('DirectoryIterator');
        $this->type->object->lookup('FilesystemIterator');
        $this->type->object->lookup('RecursiveDirectoryIterator');
        foreach (['DirectoryIterator', 'FilesystemIterator', 'RecursiveDirectoryIterator'] as $diClass) {
            $diLc = strtolower($diClass);
            $diMethods = [
                '__construct', 'rewind', 'valid', 'current', 'key', 'next',
                'isDot', 'getFilename', 'getSize', 'getRealPath',
                'getMTime', 'getATime', 'getCTime', 'getPerms', 'getOwner', 'getGroup', 'getInode',
                'isFile', 'isDir',
                'isLink', 'getLinkTarget', 'isReadable', 'isWritable', 'isExecutable',
                'getPathname', 'getPath', 'getExtension', 'getBasename', 'getType', '__toString',
                'getFileInfo', 'getPathInfo', 'openFile',
            ];
            // FilesystemIterator / RDI — getFlags/setFlags (#34984 leftover of #30937).
            if ('DirectoryIterator' !== $diClass) {
                $diMethods[] = 'getFlags';
                $diMethods[] = 'setFlags';
            }
            foreach ($diMethods as $diMethod) {
                $this->functionProxies[$diLc.'::'.strtolower($diMethod)] = new Call\DirectoryIteratorMethod(
                    $diMethod,
                    $diClass
                );
            }
        }
        foreach ([
            '__construct',
            'getFilename', 'getSize', 'getRealPath',
            'getMTime', 'getATime', 'getCTime', 'getPerms', 'getOwner', 'getGroup', 'getInode',
            'isFile', 'isDir',
            'isLink', 'getLinkTarget', 'isReadable', 'isWritable', 'isExecutable',
            'getPathname', 'getPath', 'getExtension', 'getBasename', 'getType', '__toString',
            'getFileInfo', 'getPathInfo', 'openFile',
        ] as $sfiMethod) {
            $this->functionProxies['splfileinfo::'.strtolower($sfiMethod)] = new Call\DirectoryIteratorMethod(
                $sfiMethod,
                'SplFileInfo'
            );
        }
        // SplFileObject — `__spl_ht` + `__pathname` + `__spl_fd` (#33305/#33308/#33318) + iterator (#33319);
        // getCurrentLine → fgets (#33321); fread/fgetc (#33332); ftell/flock (#33336); fstat (#33359);
        // ftruncate (#33348); fflush (#33354); fpassthru (#33358); fputcsv (#33340); fgetcsv (#33346);
        // fseek (#33347); seek (#33364); setFlags/getFlags (#33368); setMaxLineLen/getMaxLineLen (#33377);
        // setCsvControl/getCsvControl (#33371); fscanf (#33382); hasChildren/getChildren (#33388);
        // inherited SplFileInfo stats (#33313).
        $this->type->object->lookup('SplFileObject');
        foreach ([
            '__construct', 'getFilename', 'getPathname', 'getPath', '__toString',
            'fgets', 'getCurrentLine', 'fread', 'fgetc', 'fwrite', 'fputcsv', 'fgetcsv', 'fscanf',
            'setCsvControl', 'getCsvControl', 'eof', 'hasChildren', 'getChildren',
            'ftell', 'fstat', 'flock', 'ftruncate', 'fflush', 'fpassthru', 'fseek', 'seek',
            'setFlags', 'getFlags', 'setMaxLineLen', 'getMaxLineLen',
            'rewind', 'valid', 'current', 'key', 'next',
        ] as $sfoMethod) {
            $this->functionProxies['splfileobject::'.strtolower($sfoMethod)] = new Call\SplFileObjectMethod(
                $sfoMethod
            );
        }
        foreach ([
            'getSize', 'getRealPath',
            'getMTime', 'getATime', 'getCTime', 'getPerms', 'getOwner', 'getGroup', 'getInode',
            'isFile', 'isDir',
            'isLink', 'getLinkTarget', 'isReadable', 'isWritable', 'isExecutable',
            'getExtension', 'getBasename', 'getType',
            'getFileInfo', 'getPathInfo', 'openFile',
        ] as $sfoStatMethod) {
            $this->functionProxies['splfileobject::'.strtolower($sfoStatMethod)] = new Call\DirectoryIteratorMethod(
                $sfoStatMethod,
                'SplFileObject'
            );
        }
        // SplTempFileObject — extends SplFileObject; php://temp construct (#33431).
        $this->type->object->lookup('SplTempFileObject');
        foreach ([
            '__construct', 'getFilename', 'getPathname', 'getPath', '__toString',
            'fgets', 'getCurrentLine', 'fread', 'fgetc', 'fwrite', 'fputcsv', 'fgetcsv', 'fscanf',
            'setCsvControl', 'getCsvControl', 'eof', 'hasChildren', 'getChildren',
            'ftell', 'fstat', 'flock', 'ftruncate', 'fflush', 'fpassthru', 'fseek', 'seek',
            'setFlags', 'getFlags', 'setMaxLineLen', 'getMaxLineLen',
            'rewind', 'valid', 'current', 'key', 'next',
        ] as $stfoMethod) {
            $this->functionProxies['spltempfileobject::'.strtolower($stfoMethod)] = new Call\SplFileObjectMethod(
                $stfoMethod,
                true
            );
        }
        foreach ([
            'getSize', 'getRealPath',
            'getMTime', 'getATime', 'getCTime', 'getPerms', 'getOwner', 'getGroup', 'getInode',
            'isFile', 'isDir',
            'isLink', 'getLinkTarget', 'isReadable', 'isWritable', 'isExecutable',
            'getExtension', 'getBasename', 'getType',
            'getFileInfo', 'getPathInfo', 'openFile',
        ] as $stfoStatMethod) {
            $this->functionProxies['spltempfileobject::'.strtolower($stfoStatMethod)] = new Call\DirectoryIteratorMethod(
                $stfoStatMethod,
                'SplFileObject'
            );
        }
        // GlobIterator — glob snapshot + Iterator (#27422); getFlags/setFlags (#34993).
        $this->type->object->lookup('GlobIterator');
        foreach ([
            '__construct', 'rewind', 'valid', 'current', 'key', 'next',
            'getFilename', 'count',
            'getFlags', 'setFlags',
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
                '__construct', 'insert', 'extract', 'top', 'count', 'isempty',
                'rewind', 'valid', 'current', 'key', 'next',
            ] as $heapMethod) {
                $this->functionProxies[$heapLc.'::'.$heapMethod] = new Call\SplHeapMethod($heapMethod, $heapKind);
            }
        }
        // SplPriorityQueue — parallel data/priority HTs + Iterator foreach (#27277, #28708).
        // setExtractFlags/getExtractFlags — thin AOT was a silent null stub (#33861).
        $this->type->object->lookup('SplPriorityQueue');
        foreach ([
            '__construct', 'insert', 'extract', 'top', 'count', 'isEmpty',
            'rewind', 'valid', 'current', 'key', 'next',
            'setExtractFlags', 'getExtractFlags',
        ] as $pqMethod) {
            $this->functionProxies['splpriorityqueue::'.strtolower($pqMethod)] = new Call\SplPriorityQueueMethod($pqMethod);
        }
        // SplDoublyLinkedList / SplQueue / SplStack — `__spl_ht` deque (#26790, #27311, #28704, #32910).
        foreach ([
            'spldoublylinkedlist' => 'SplDoublyLinkedList',
            'splqueue' => 'SplQueue',
            'splstack' => 'SplStack',
        ] as $dllLc => $dllClass) {
            $this->type->object->lookup($dllClass);
            // isEmpty: without proxy, thin AOT silent-nulls (#579) — always falsy (#33973).
            // offset* / setIteratorMode / getIteratorMode: same silent-null without proxy (#33987).
            $dllMethods = [
                '__construct', 'push', 'pop', 'shift', 'unshift', 'top', 'bottom', 'count', 'isempty',
                'offsetGet', 'offsetExists', 'offsetSet', 'offsetUnset',
                'setIteratorMode', 'getIteratorMode',
                // Iterator protocol — without proxy thin AOT silent-nulls (#579 / #34976)
                'rewind', 'valid', 'current', 'key', 'next',
                // Serializable::serialize/unserialize — silent-null (#579 / #35111)
                'serialize', 'unserialize',
            ];
            if ('splqueue' === $dllLc) {
                $dllMethods = array_merge($dllMethods, ['enqueue', 'dequeue']);
            }
            foreach ($dllMethods as $dllMethod) {
                // Lookup keys are lowercase (peer splfixedarray::strtolower); mixed-case
                // offsetGet/setIteratorMode keys missed the table → silent null (#33987).
                $this->functionProxies[$dllLc.'::'.strtolower($dllMethod)] = new Call\SplDllistMethod(
                    $dllMethod,
                    $dllClass
                );
            }
        }
        // SplFixedArray — `__spl_ht` + fromArray / count / setSize / toArray / ArrayAccess / foreach
        // (#26793, #28640, #33784).
        // Seed the class so count()/ArrayAccess candidates see Countable before first use.
        $this->type->object->lookup('SplFixedArray');
        foreach ([
            '__construct', 'fromArray', 'count', 'getSize', 'setSize', 'toArray',
            'offsetGet', 'offsetSet', 'offsetExists', 'offsetUnset', '__debugInfo',
        ] as $sfaMethod) {
            $this->functionProxies['splfixedarray::'.strtolower($sfaMethod)] = new Call\SplFixedArrayMethod($sfaMethod);
        }

        $this->functionProxies['weakreference::create'] = new Call\WeakReferenceCreate();
        $this->functionProxies['weakreference::get'] = new Call\WeakReferenceGet();
        $this->functionProxies['sensitiveparametervalue::__construct'] = new Call\SensitiveParameterValueConstruct();
        $this->functionProxies['sensitiveparametervalue::getvalue'] = new Call\SensitiveParameterValueGetValue();
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
        // Thin AOT: ReflectionObject::$name / getName empty without construct + TYPE_VALUE (#34001).
        $this->functionProxies['reflectionobject::getname'] = new Call\ReflectionObjectGetName();
        $this->functionProxies['reflectionclass::getshortname'] = new Call\ReflectionClassGetShortName();
        $this->functionProxies['reflectionclass::getnamespacename'] = new Call\ReflectionClassGetNamespaceName();
        $this->functionProxies['reflectionclass::innamespace'] = new Call\ReflectionClassInNamespace();
        $this->functionProxies['reflectionclass::getattributes'] = new Call\ReflectionClassGetAttributes();

        $this->functionProxies['reflectionclass::getmethod'] = new Call\ReflectionClassGetMethod();
        // Thin AOT: unbound getConstructor → unseeded ReflectionMethod → SIGSEGV (#34073).
        $this->functionProxies['reflectionclass::getconstructor'] = new Call\ReflectionClassGetConstructor();
        $this->functionProxies['reflectionclass::getproperty'] = new Call\ReflectionClassGetProperty();
        $this->functionProxies['reflectionclass::getreflectionconstant'] = new Call\ReflectionClassGetReflectionConstant();
        // Thin AOT: unbound hasMethod/hasProperty/hasConstant → NULL (#34072); VM #6301.
        $this->functionProxies['reflectionclass::hasmethod'] = new Call\ReflectionClassHasMember('hasMethod');
        $this->functionProxies['reflectionclass::hasproperty'] = new Call\ReflectionClassHasMember('hasProperty');
        $this->functionProxies['reflectionclass::hasconstant'] = new Call\ReflectionClassHasMember('hasConstant');
        // Thin AOT: unbound getConstant → NULL (#34093); VM ReflectionClassGetConstant (#6950).
        $this->functionProxies['reflectionclass::getconstant'] = new Call\ReflectionClassGetConstant();
        // Thin AOT: unbound getConstants → NULL (#34109); VM ReflectionClassGetConstants (#6950).
        $this->functionProxies['reflectionclass::getconstants'] = new Call\ReflectionClassGetConstants();
        // Thin AOT: unbound getReflectionConstants → NULL (#34119); VM #6662.
        $this->functionProxies['reflectionclass::getreflectionconstants'] = new Call\ReflectionClassGetReflectionConstants();
        // Thin AOT: unbound getFileName → NULL/SIGSEGV (#34096); VM ReflectionClassGetFileName (#7358).
        $this->functionProxies['reflectionclass::getfilename'] = new Call\ReflectionClassGetFileName();
        // Thin AOT: unbound getStartLine/getEndLine/getDocComment → NULL (#34106);
        // once-per-module helpers — inlined emit SIGSEGV under typed show() thrice (#34186).
        $this->functionProxies['reflectionclass::getstartline'] = new Call\ReflectionClassSourceLocationQuery('getStartLine');
        $this->functionProxies['reflectionclass::getendline'] = new Call\ReflectionClassSourceLocationQuery('getEndLine');
        $this->functionProxies['reflectionclass::getdoccomment'] = new Call\ReflectionClassSourceLocationQuery('getDocComment');
        // Thin AOT: unbound getInterfaceNames/getTraitNames → NULL (#34110); Object_ interface/trait tables.
        $this->functionProxies['reflectionclass::getinterfacenames'] = new Call\ReflectionClassNameListQuery('interfacenames');
        $this->functionProxies['reflectionclass::gettraitnames'] = new Call\ReflectionClassNameListQuery('traitnames');
        // Thin AOT: unbound getInterfaces/getTraits → NULL (#34121); VM #22170 / #22108.
        $this->functionProxies['reflectionclass::getinterfaces'] = new Call\ReflectionClassClassMapQuery('interfaces');
        $this->functionProxies['reflectionclass::gettraits'] = new Call\ReflectionClassClassMapQuery('traits');
        // Thin AOT: unbound getTraitAliases → NULL (#34129); VM #6661.
        $this->functionProxies['reflectionclass::gettraitaliases'] = new Call\ReflectionClassGetTraitAliases();
        // Thin AOT: unbound __toString → convert-to-string fatal (#34135); VM #22379.
        $this->functionProxies['reflectionclass::__tostring'] = new Call\ReflectionClassToString();
        // Thin AOT: unbound implementsInterface/isSubclassOf → NULL → false (#34080); VM #6302.
        $this->functionProxies['reflectionclass::implementsinterface'] = new Call\ReflectionClassRelationQuery('implementsInterface');
        $this->functionProxies['reflectionclass::issubclassof'] = new Call\ReflectionClassRelationQuery('isSubclassOf');
        // Thin AOT: isFinal used broken strcasecmp → always true (#34043); memcmp+fold table.
        $this->functionProxies['reflectionclass::isfinal'] = new Call\ReflectionClassIsFinal();
        // Thin AOT: unbound isInstantiable → NULL (#34027); VM has ReflectionClassIsInstantiable.
        $this->functionProxies['reflectionclass::isinstantiable'] = new Call\ReflectionClassIsInstantiable();
        // Thin AOT: unbound isInstance → NULL (#34098); VM #6302 / peer instanceof tables.
        $this->functionProxies['reflectionclass::isinstance'] = new Call\ReflectionClassIsInstance();
        // Thin AOT: unbound isCloneable → NULL (#34040); VM has ReflectionClassIsCloneable (#22109).
        $this->functionProxies['reflectionclass::iscloneable'] = new Call\ReflectionClassIsCloneable();
        // Thin AOT: unbound isAnonymous → NULL (#34057); VM has ReflectionClassIsAnonymous (#5105).
        $this->functionProxies['reflectionclass::isanonymous'] = new Call\ReflectionClassIsAnonymous();
        // Thin AOT: unbound kind queries → NULL (#34032); NestedJIT emitKindQuery fails verify.
        $this->functionProxies['reflectionclass::isinterface'] = new Call\ReflectionClassKindQuery('isInterface');
        $this->functionProxies['reflectionclass::isabstract'] = new Call\ReflectionClassKindQuery('isAbstract');
        $this->functionProxies['reflectionclass::istrait'] = new Call\ReflectionClassKindQuery('isTrait');
        $this->functionProxies['reflectionclass::isenum'] = new Call\ReflectionClassKindQuery('isEnum');
        // Thin AOT: unbound isInternal/isUserDefined/isReadOnly → NULL (#34067); peer #34032 tables.
        $this->functionProxies['reflectionclass::isinternal'] = new Call\ReflectionClassKindQuery('isInternal');
        $this->functionProxies['reflectionclass::isuserdefined'] = new Call\ReflectionClassKindQuery('isUserDefined');
        $this->functionProxies['reflectionclass::isreadonly'] = new Call\ReflectionClassKindQuery('isReadOnly');
        // Thin AOT: isIterable looked up unlinked NestedJIT ABI → compile abort (#34062).
        $this->functionProxies['reflectionclass::isiterateable'] = new Call\ReflectionClassIsIterateable();
        $this->functionProxies['reflectionclass::isiterable'] = new Call\ReflectionClassIsIterateable();
        // Thin AOT: getParentClass without proxy → SIGSEGV on result use (#34069).
        $this->functionProxies['reflectionclass::getparentclass'] = new Call\ReflectionClassGetParentClass();
        // Thin AOT: unbound getModifiers → NULL (#34077); VM has ReflectionClassGetModifiers (#18335).
        $this->functionProxies['reflectionclass::getmodifiers'] = new Call\ReflectionClassGetModifiers();
        // Thin AOT: unbound newInstanceWithoutConstructor → abort rc=134 (#34078); VM #5443.
        $this->functionProxies['reflectionclass::newinstancewithoutconstructor'] = new Call\ReflectionClassNewInstanceWithoutConstructor();
        // Thin AOT: unbound newInstance → abort rc=134 (#34083); VM #22086.
        $this->functionProxies['reflectionclass::newinstance'] = new Call\ReflectionClassNewInstance();
        // Thin AOT: unbound newInstanceArgs → NULL (#34090); VM #22086.
        $this->functionProxies['reflectionclass::newinstanceargs'] = new Call\ReflectionClassNewInstanceArgs();
        // Thin AOT: unbound getDefaultProperties → NULL (#34091); VM #11441 / peer get_class_vars #27229.
        $this->functionProxies['reflectionclass::getdefaultproperties'] = new Call\ReflectionClassGetDefaultProperties();
        // Thin AOT: unbound getMethods → NULL (#34107); VM #3815.
        $this->functionProxies['reflectionclass::getmethods'] = new Call\ReflectionClassGetMethods();
        // Thin AOT: unbound getProperties → NULL (#34113); VM #3815.
        $this->functionProxies['reflectionclass::getproperties'] = new Call\ReflectionClassGetProperties();
        // Thin AOT: unbound getStaticProperties → NULL (#34118); VM #6948.
        $this->functionProxies['reflectionclass::getstaticproperties'] = new Call\ReflectionClassGetStaticProperties();
        // Thin AOT: unbound getStaticPropertyValue → NULL (#34125); VM #6948 / peer getConstant #34093.
        $this->functionProxies['reflectionclass::getstaticpropertyvalue'] = new Call\ReflectionClassGetStaticPropertyValue();
        // Thin AOT: unbound setStaticPropertyValue → silent no-op (#34130); VM #6948.
        $this->functionProxies['reflectionclass::setstaticpropertyvalue'] = new Call\ReflectionClassSetStaticPropertyValue();
        // Thin AOT: unbound getExtensionName → NULL (#34139); VM #7358 / peer getFileName #34096.
        $this->functionProxies['reflectionclass::getextensionname'] = new Call\ReflectionClassGetExtensionName();
        // Thin AOT: unbound getExtension → NULL (#34145); VM #11462 / peer #34139.
        $this->functionProxies['reflectionclass::getextension'] = new Call\ReflectionClassGetExtension();
        if (CompilerVersion::supportsLazyObjectFactories()) {
            $this->functionProxies['reflectionclass::newlazyproxy'] = new Call\ReflectionClassNewLazyProxy();
            $this->functionProxies['reflectionclass::newlazyghost'] = new Call\ReflectionClassNewLazyGhost();
            // ReflectionClass::createLazyGhost/Proxy are phantoms vs php-src (#28516).
        }
        $this->functionProxies['reflectionproperty::__construct'] = new Call\ReflectionPropertyConstruct();
        $this->functionProxies['reflectionproperty::getname'] = new Call\ReflectionPropertyGetName();
        $this->functionProxies['reflectionparameter::__construct'] = new Call\ReflectionParameterConstruct();
        $this->functionProxies['reflectionparameter::getname'] = new Call\ReflectionParameterGetName();
        $this->functionProxies['reflectionparameter::gettype'] = new Call\ReflectionParameterGetType();
        $this->functionProxies['reflectionparameter::hastype'] = new Call\ReflectionParameterHasType();
        $this->functionProxies['reflectionparameter::allowsnull'] = new Call\ReflectionParameterAllowsNull();
        $this->functionProxies['reflectionparameter::isdefaultvalueavailable'] = new Call\ReflectionParameterIsDefaultValueAvailable();
        $this->functionProxies['reflectionparameter::getdefaultvalue'] = new Call\ReflectionParameterGetDefaultValue();
        $this->functionProxies['reflectionproperty::getattributes'] = new Call\ReflectionPropertyGetAttributes();
        // Thin AOT: isFinal used broken strcasecmp → true for every prop when table non-empty (#34047).
        $this->functionProxies['reflectionproperty::isfinal'] = new Call\ReflectionPropertyIsFinal();
        $this->functionProxies['reflectionproperty::isvirtual'] = new Call\ReflectionPropertyIsVirtual();
        $this->functionProxies['reflectionproperty::getrawvalue'] = new Call\ReflectionPropertyGetRawValue();
        $this->functionProxies['reflectionproperty::setrawvalue'] = new Call\ReflectionPropertySetRawValue();
        // Thin AOT: avoid undefined setaccessible / null invoke (#30910).
        $this->functionProxies['reflectionproperty::setaccessible'] = new Call\ReflectionSetAccessible('ReflectionProperty');
        $this->functionProxies['reflectionproperty::getvalue'] = new Call\ReflectionPropertyGetValue();
        $this->functionProxies['reflectionproperty::setvalue'] = new Call\ReflectionPropertySetValue();
        // Thin AOT: getDeclaringClass without proxy → ReflectionClass $name unset → SIGSEGV (#34020).
        $this->functionProxies['reflectionproperty::getdeclaringclass'] = new Call\ReflectionGetDeclaringClass(
            'ReflectionProperty',
            \PHPCompiler\VM\ReflectionSupport::PROP_DECLARING_CLASS_NAME,
            'ReflectionProperty::getDeclaringClass'
        );
        // Thin AOT: unset $class/$name → SIGSEGV on property read / getAttributes (#33990).
        $this->functionProxies['reflectionmethod::__construct'] = new Call\ReflectionMethodConstruct();
        // getName was still unbound after #33994 — silent empty string (#33990 done-when).
        $this->functionProxies['reflectionmethod::getname'] = new Call\ReflectionMethodGetName();
        // Thin AOT: getDeclaringClass without proxy → ReflectionClass $name unset → SIGSEGV (#34020).
        $this->functionProxies['reflectionmethod::getdeclaringclass'] = new Call\ReflectionGetDeclaringClass(
            'ReflectionMethod',
            \PHPCompiler\VM\ReflectionSupport::PROP_REFLECTION_METHOD_CLASS,
            'ReflectionMethod::getDeclaringClass'
        );
        $this->functionProxies['reflectionmethod::setaccessible'] = new Call\ReflectionSetAccessible('ReflectionMethod');
        $this->functionProxies['reflectionmethod::invoke'] = new Call\ReflectionMethodInvoke();
        // Thin AOT: unbound isPublic/isStatic/param counts → NULL (#34216).
        $this->functionProxies['reflectionmethod::ispublic'] = new Call\ReflectionMethodIsPublic();
        $this->functionProxies['reflectionmethod::isstatic'] = new Call\ReflectionMethodIsStatic();
        $this->functionProxies['reflectionmethod::getnumberofparameters'] = new Call\ReflectionMethodGetNumberOfParameters();
        $this->functionProxies['reflectionmethod::getnumberofrequiredparameters'] = new Call\ReflectionMethodGetNumberOfRequiredParameters();
        $this->functionProxies['reflectionclassconstant::__construct'] = new Call\ReflectionClassConstantConstruct();
        $this->functionProxies['reflectionclassconstant::getname'] = new Call\ReflectionClassConstantGetName();
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
        // Thin AOT: unset extension name → empty getName() (#34003).
        $this->functionProxies['reflectionextension::__construct'] = new Call\ReflectionExtensionConstruct();
        $this->functionProxies['reflectionextension::getname'] = new Call\ReflectionExtensionGetName();
        // Thin AOT: unbound getVersion → NULL (#34016); VM uses VmReflection::reflectionExtensionVersion.
        $this->functionProxies['reflectionextension::getversion'] = new Call\ReflectionExtensionGetVersion();
        // Thin AOT: unbound getClassNames → NULL (#34150); VM #22247 / peer name-list #34110.
        $this->functionProxies['reflectionextension::getclassnames'] = new Call\ReflectionExtensionGetClassNames();
        // Thin AOT: unbound getClasses → NULL (#34169); VM #18326 / peer getClassNames #34150.
        $this->functionProxies['reflectionextension::getclasses'] = new Call\ReflectionExtensionGetClasses();
        // Thin AOT: unbound getFunctions → NULL (#34177); VM #18326 / peer getClasses #34169.
        $this->functionProxies['reflectionextension::getfunctions'] = new Call\ReflectionExtensionGetFunctions();
        // Thin AOT: unbound isPersistent/isTemporary → NULL (#34154); VM #22247.
        $this->functionProxies['reflectionextension::ispersistent'] = new Call\ReflectionExtensionIsPersistent();
        $this->functionProxies['reflectionextension::istemporary'] = new Call\ReflectionExtensionIsTemporary();
        // Thin AOT: unbound getDependencies → NULL (#34155); VM #22247 / peer getClassNames #34150.
        $this->functionProxies['reflectionextension::getdependencies'] = new Call\ReflectionExtensionGetDependencies();
        // Thin AOT: unbound getConstants → NULL (#34162); VM #18326 / peer getDependencies #34155.
        $this->functionProxies['reflectionextension::getconstants'] = new Call\ReflectionExtensionGetConstants();
        // Thin AOT: unbound getINIEntries → NULL (#34165); VM #22247 / peer getConstants #34162.
        $this->functionProxies['reflectionextension::getinientries'] = new Call\ReflectionExtensionGetINIEntries();
        // Thin AOT: unbound __toString/info → cast fatal / empty info (#34181); VM #22247.
        $this->functionProxies['reflectionextension::__tostring'] = new Call\ReflectionExtensionToString();
        $this->functionProxies['reflectionextension::info'] = new Call\ReflectionExtensionInfo();

        $this->functionProxies['reflectionfunction::isvariadic'] = new Call\ReflectionFunctionIsVariadic();
        // Thin AOT: unbound getNumberOfParameters / isUserDefined / isInternal → NULL (#34218).
        $this->functionProxies['reflectionfunction::getnumberofparameters'] = new Call\ReflectionFunctionGetNumberOfParameters();
        $this->functionProxies['reflectionfunction::getnumberofrequiredparameters'] = new Call\ReflectionFunctionGetNumberOfRequiredParameters();
        $this->functionProxies['reflectionfunction::getparameters'] = new Call\ReflectionFunctionGetParameters();
        $this->functionProxies['reflectionfunction::getreturntype'] = new Call\ReflectionFunctionGetReturnType();
        $this->functionProxies['reflectionfunction::hasreturntype'] = new Call\ReflectionFunctionHasReturnType();
        $this->functionProxies['reflectionfunction::isuserdefined'] = new Call\ReflectionFunctionIsUserDefined();
        $this->functionProxies['reflectionfunction::isinternal'] = new Call\ReflectionFunctionIsInternal();
        if (CompilerVersion::supportsReflectionParameterIsSensitiveParameter()) {
            $this->functionProxies['reflectionparameter::issensitiveparameter'] = new Call\ReflectionParameterIsSensitiveParameter();
        }
        $this->functionProxies['reflectionparameter::isvariadic'] = new Call\ReflectionParameterIsVariadic();
        $this->functionProxies['reflectionparameter::isoptional'] = new Call\ReflectionParameterIsOptional();
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
        $this->functionProxies['reflectionnamedtype::__tostring'] = new Call\ReflectionNamedTypeToString();
        $this->functionProxies['reflectionuniontype::__tostring'] = new Call\ReflectionUnionTypeToString();
        $this->functionProxies['exception::getmessage'] = new Call\ExceptionGetMessage('Exception');
        $this->functionProxies['exception::getcode'] = new Call\ExceptionGetCode('Exception');
        $exceptionToString = new Call\ExceptionToString();
        $exceptionGetTrace = new Call\ExceptionGetTrace('Exception');
        $exceptionGetTraceAsString = new Call\ExceptionGetTraceAsString('Exception');
        $exceptionGetFile = new Call\ExceptionGetFile('Exception');
        $exceptionGetLine = new Call\ExceptionGetLine('Exception');
        $exceptionGetPrevious = new Call\ExceptionGetPrevious('Exception');
        $this->functionProxies['exception::__tostring'] = $exceptionToString;
        $this->functionProxies['exception::gettrace'] = $exceptionGetTrace;
        $this->functionProxies['exception::gettraceasstring'] = $exceptionGetTraceAsString;
        $this->functionProxies['exception::getfile'] = $exceptionGetFile;
        $this->functionProxies['exception::getline'] = $exceptionGetLine;
        $this->functionProxies['exception::getprevious'] = $exceptionGetPrevious;
        // catch (Throwable $e) resolves methods on the interface name (#27333).
        $this->functionProxies['throwable::__tostring'] = $exceptionToString;
        $this->functionProxies['throwable::gettrace'] = $exceptionGetTrace;
        $this->functionProxies['throwable::gettraceasstring'] = $exceptionGetTraceAsString;
        $this->functionProxies['throwable::getmessage'] = $this->functionProxies['exception::getmessage'];
        $this->functionProxies['throwable::getcode'] = $this->functionProxies['exception::getcode'];
        $this->functionProxies['throwable::getfile'] = $exceptionGetFile;
        $this->functionProxies['throwable::getline'] = $exceptionGetLine;
        $this->functionProxies['throwable::getprevious'] = $exceptionGetPrevious;
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
            $isErrorFamily = \PHPCompiler\ext\standard\ThrowableManifest::LC_ERROR === $lc
                || \PHPCompiler\ext\standard\ThrowableManifest::isDescendantOf(
                    $lc,
                    \PHPCompiler\ext\standard\ThrowableManifest::LC_ERROR
                );
            // Throwable::__toString / getTrace / get* — user-script AOT (#26796, #27333, #30895).
            $this->functionProxies[$lc.'::__tostring'] = $exceptionToString;
            $this->functionProxies[$lc.'::gettrace'] = $isErrorFamily
                ? new Call\ExceptionGetTrace('Error')
                : $exceptionGetTrace;
            $this->functionProxies[$lc.'::gettraceasstring'] = $isErrorFamily
                ? new Call\ExceptionGetTraceAsString('Error')
                : $exceptionGetTraceAsString;
            $this->functionProxies[$lc.'::getmessage'] = $isErrorFamily
                ? new Call\ExceptionGetMessage('Error')
                : $this->functionProxies['exception::getmessage'];
            $this->functionProxies[$lc.'::getcode'] = $isErrorFamily
                ? new Call\ExceptionGetCode('Error')
                : $this->functionProxies['exception::getcode'];
            $this->functionProxies[$lc.'::getfile'] = $isErrorFamily
                ? new Call\ExceptionGetFile('Error')
                : $exceptionGetFile;
            $this->functionProxies[$lc.'::getline'] = $isErrorFamily
                ? new Call\ExceptionGetLine('Error')
                : $exceptionGetLine;
            $this->functionProxies[$lc.'::getprevious'] = $isErrorFamily
                ? new Call\ExceptionGetPrevious('Error')
                : $exceptionGetPrevious;
        }
        // Alias get* for Error family roots (same prop layout; Error ACE label #30895).
        $this->functionProxies['error::getmessage'] = new Call\ExceptionGetMessage('Error');
        $this->functionProxies['error::getcode'] = new Call\ExceptionGetCode('Error');
        $this->functionProxies['error::__tostring'] = $exceptionToString;
        $this->functionProxies['error::gettrace'] = new Call\ExceptionGetTrace('Error');
        $this->functionProxies['error::gettraceasstring'] = new Call\ExceptionGetTraceAsString('Error');
        $this->functionProxies['error::getfile'] = new Call\ExceptionGetFile('Error');
        $this->functionProxies['error::getline'] = new Call\ExceptionGetLine('Error');
        $this->functionProxies['error::getprevious'] = new Call\ExceptionGetPrevious('Error');

        FiberHelper::registerJitMethods($this);
        GeneratorHelper::registerJitMethods($this);
        ClosureBindHelper::registerJitMethods($this);
        // DateTime / DateInterval / DatePeriod ctors — thin user-script AOT (#26772).
        $this->functionProxies['datetime::__construct'] = new Call\DateTimeConstruct();
        $this->functionProxies['datetimeimmutable::__construct'] = new Call\DateTimeImmutableConstruct();
        // DOMDocument::__construct — seed nodeType for thin AOT (#33607).
        $this->functionProxies['domdocument::__construct'] = new Call\DomDocumentConstruct();
        // ZipArchive::__construct — seed stub props for thin AOT (#35002 leftover of #20584).
        $this->functionProxies['ziparchive::__construct'] = new Call\ZipArchiveConstruct();
        // ZipArchive methods — NestedJIT helper (peer HashContext #3357; #35424 / #35437 / #35440 / #35449 / #35450 / #35455 / #35465 / #35466 / #35467 / #35472 / #35476 / #35486 / #35489 / #35491 / #35496 / #35498 / #35500 / #35504 / #35506 / #35508 leftover of #6414).
        foreach ([
            'open',
            'addFromString',
            'addEmptyDir',
            'addFile',
            'close',
            'getFromName',
            'locateName',
            'getFromIndex',
            'getNameIndex',
            'renameName',
            'renameIndex',
            'deleteName',
            'deleteIndex',
            'extractTo',
            'getStatusString',
            'count',
            'isWritable',
            'setReadOnly',
            'setArchiveComment',
            'getArchiveComment',
            'setCommentName',
            'getCommentName',
            'setCommentIndex',
            'getCommentIndex',
            'unchangeAll',
            'unchangeArchive',
            'unchangeIndex',
            'unchangeName',
            'replaceFile',
            'isCompressionMethodSupported',
            'isEncryptionMethodSupported',
            'setPassword',
            'setCompressionName',
            'setCompressionIndex',
            'setEncryptionName',
            'setEncryptionIndex',
            'setExternalAttributesName',
            'setExternalAttributesIndex',
            'getExternalAttributesName',
            'getExternalAttributesIndex',
            'statName',
            'statIndex',
            'setMtimeName',
            'setMtimeIndex',
            'setArchiveFlag',
            'getArchiveFlag',
            'clearError',
            'getStream',
            'getStreamIndex',
            'getStreamName',
            'addGlob',
            'addPattern',
            'registerProgressCallback',
            'registerCancelCallback',
        ] as $zipMethod) {
            $this->functionProxies['ziparchive::'.strtolower($zipMethod)] = new Call\ZipArchiveMethod(
                $zipMethod
            );
        }
        // SQLite3 construct/exec/querySingle/lastInsertRowID/changes/lastError*/busyTimeout/enableExceptions/escapeString/version/open/prepare/query — NestedJIT leftover of advertise-only AOT (#35931 / #35966 / #35972 / #35975 / #35977 / #35991 / #36001 / #36010 / #20565).
        if (CompilerVersion::supportsSqlite3()) {
            $this->type->object->lookup('SQLite3');
            foreach (['__construct', 'exec', 'querySingle', 'close', 'lastInsertRowID', 'changes', 'lastErrorCode', 'lastErrorMsg', 'busyTimeout', 'enableExceptions', 'escapeString', 'version', 'open', 'prepare', 'query'] as $sqliteMethod) {
                $this->functionProxies['sqlite3::'.strtolower($sqliteMethod)] = new Call\Sqlite3Method(
                    $sqliteMethod
                );
            }
            $this->type->object->lookup('SQLite3Stmt');
            foreach (['getSQL', 'paramCount', 'bindValue', 'bindParam', 'execute', 'readOnly'] as $stmtMethod) {
                $this->functionProxies['sqlite3stmt::'.strtolower($stmtMethod)] = new Call\Sqlite3StmtMethod(
                    $stmtMethod
                );
            }
            $this->type->object->lookup('SQLite3Result');
            foreach (['fetchArray', 'columnType'] as $resultMethod) {
                $this->functionProxies['sqlite3result::'.strtolower($resultMethod)] = new Call\Sqlite3ResultMethod(
                    $resultMethod
                );
            }
        }
        $this->functionProxies['datetimezone::__construct'] = new Call\DateTimeZoneConstruct();
        $this->functionProxies['dateinterval::__construct'] = new Call\DateIntervalConstruct();
        $this->functionProxies['dateinterval::format'] = new Call\DateIntervalFormat();
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
        // Conversion factories — avoid ExternalMethod null / segfault on thin AOT (#30762).
        $this->functionProxies['datetime::createfrominterface'] = new Call\DateTimeCreateFromInterface(false);
        $this->functionProxies['datetimeimmutable::createfrominterface'] = new Call\DateTimeCreateFromInterface(true);
        $this->functionProxies['datetime::createfromimmutable'] = new Call\DateTimeCreateFromImmutable();
        $this->functionProxies['datetimeimmutable::createfrommutable'] = new Call\DateTimeImmutableCreateFromMutable();
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
        $this->functionProxies['datetime::gettimezone'] = new Call\DateTimeGetTimezone();
        $this->functionProxies['datetimeimmutable::gettimezone'] = new Call\DateTimeGetTimezone();
        // getOffset lives in DateTimeSetTimezone.php (always loaded above).
        // Avoid ExternalMethod null stub / invokeJitCall TypeError on thin AOT (#30761).
        $this->functionProxies['datetime::getoffset'] = new Call\DateTimeGetOffset();
        $this->functionProxies['datetimeimmutable::getoffset'] = new Call\DateTimeGetOffset();
        // Immutable: allocate+copy (not cloneObject) for MCJIT. Thin user-script AOT still
        // hits "basic block has no parent" inside Object_::allocate / NestedJIT ensureLinked
        // (same as `new DateTimeImmutable` under HELPER_RUNTIME_O=0). VM Builtin covers
        // php bin/vm.php; register proxy for MCJIT / full-init only (#22824).
        if (!UserScriptAotEnv::isActive()) {
            $this->functionProxies['datetimeimmutable::settimezone'] = new Call\DateTimeSetTimezone(true);
        }
        // getTimestamp / setTimestamp live in DateTimeSetTimezone.php (always loaded above).
        // Avoid ExternalMethod null stub on thin AOT (#30745).
        $this->functionProxies['datetime::gettimestamp'] = new Call\DateTimeGetTimestamp(false);
        $this->functionProxies['datetimeimmutable::gettimestamp'] = new Call\DateTimeGetTimestamp(true);
        $this->functionProxies['datetime::settimestamp'] = new Call\DateTimeSetTimestamp(false);
        // Immutable allocate+copy is thin-AOT-safe after DatePeriod foreach (#26772 / modify).
        $this->functionProxies['datetimeimmutable::settimestamp'] = new Call\DateTimeSetTimestamp(true);
        // setDate / setTime live in DateTimeSetTimezone.php (always loaded above).
        // Avoid ExternalMethod null stub on thin AOT (#30747).
        $this->functionProxies['datetime::setdate'] = new Call\DateTimeSetDate(false);
        $this->functionProxies['datetimeimmutable::setdate'] = new Call\DateTimeSetDate(true);
        $this->functionProxies['datetime::settime'] = new Call\DateTimeSetTime(false);
        $this->functionProxies['datetimeimmutable::settime'] = new Call\DateTimeSetTime(true);
        $this->functionProxies['datetime::setisodate'] = new Call\DateTimeSetISODate(false);
        $this->functionProxies['datetimeimmutable::setisodate'] = new Call\DateTimeSetISODate(true);
        // getLastErrors() — compile-time last-errors bag (peer date_get_last_errors) (#30749).
        $this->functionProxies['datetime::getlasterrors'] = new Call\DateTimeGetLastErrors(false);
        $this->functionProxies['datetimeimmutable::getlasterrors'] = new Call\DateTimeGetLastErrors(true);
        // modify() — avoid ExternalMethod null stub segfault after chained format() (#26789).
        // Immutable allocate+copy is thin-AOT-safe after DatePeriod foreach (#26772).
        $this->functionProxies['datetime::modify'] = new Call\DateTimeModify(false);
        $this->functionProxies['datetimeimmutable::modify'] = new Call\DateTimeModify(true);
        // add()/sub() — avoid ExternalMethod null stub segfault / silent no-op (#30760).
        // DateTimeAdd/DateTimeSub live in DateTimeModify.php (always loaded above).
        $this->functionProxies['datetime::add'] = new Call\DateTimeAdd(false);
        $this->functionProxies['datetimeimmutable::add'] = new Call\DateTimeAdd(true);
        $this->functionProxies['datetime::sub'] = new Call\DateTimeSub(false);
        $this->functionProxies['datetimeimmutable::sub'] = new Call\DateTimeSub(true);
        // Procedural date_add/date_sub — Call proxy avoids Internal FUNCCALL prep SIGSEGV (#33781).
        $this->functionProxies['date_add'] = new Call\ProceduralDateAdd();
        $this->functionProxies['date_sub'] = new Call\ProceduralDateSub();
        // DateTime::diff — compile-time DateInterval materialize (#27309).
        $this->functionProxies['datetime::diff'] = new Call\DateTimeDiff();
        $this->functionProxies['datetimeimmutable::diff'] = new Call\DateTimeDiff();
        // DateTimeZone::getTransitions — compile-time materialize (peer timezone_transitions_get) (#26799).
        $this->functionProxies['datetimezone::gettransitions'] = new Call\DateTimeZoneGetTransitions();
        // DateTimeZone::getName — avoid ExternalMethod silent NULL on thin AOT (#27307).
        $this->functionProxies['datetimezone::getname'] = new Call\DateTimeZoneGetName();
        // php-src zim_DateTimeZone_getLocation — thin AOT was silent NULL (#33727).
        $this->functionProxies['datetimezone::getlocation'] = new Call\DateTimeZoneGetLocation();
        // DateTimeZone::getOffset — avoid ExternalMethod silent NULL on thin AOT (#27308).
        $this->functionProxies['datetimezone::getoffset'] = new Call\DateTimeZoneGetOffset();
        // DateTimeZone::listIdentifiers — avoid ExternalMethod silent NULL on thin AOT (#29735).
        $this->functionProxies['datetimezone::listidentifiers'] = new Call\DateTimeZoneListIdentifiers();
        // DateTimeZone::listAbbreviations — avoid ExternalMethod silent NULL on thin AOT (#30780).
        $this->functionProxies['datetimezone::listabbreviations'] = new Call\DateTimeZoneListAbbreviations();
        // Locale::canonicalize — avoid ExternalMethod null stub on user-script AOT (#20760).
        $this->functionProxies['locale::canonicalize'] = new \PHPCompiler\ext\intl\LocaleCanonicalize();
        // Locale::acceptFromHttp — avoid ExternalMethod silent NULL on thin AOT (#28656).
        $this->functionProxies['locale::acceptfromhttp'] = new \PHPCompiler\ext\intl\LocaleAcceptFromHttp();
        // Locale::lookup — RFC 4647 via JitLocaleLookup / VmLocale (#32118).
        $this->functionProxies['locale::lookup'] = new \PHPCompiler\ext\intl\LocaleLookup();
        // Locale::filterMatches — prefix filter via JitLocaleFilterMatches / VmLocale (#32119).
        $this->functionProxies['locale::filtermatches'] = new \PHPCompiler\ext\intl\LocaleFilterMatches();
        // Locale::getDisplayName — ICU display name via JitLocaleGetDisplayName / VmLocale (#32120).
        $this->functionProxies['locale::getdisplayname'] = new \PHPCompiler\ext\intl\LocaleGetDisplayName();
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
        // finfo::__construct / finfo::file / finfo::buffer / finfo::set_flags — thin AOT (#27196, #28660, #34688).
        $this->functionProxies['finfo::__construct'] = new Call\FinfoConstruct();
        $this->functionProxies['finfo::file'] = new Call\FinfoFile();
        $this->functionProxies['finfo::buffer'] = new Call\FinfoBuffer();
        $this->functionProxies['finfo::set_flags'] = new Call\FinfoSetFlags();
        // PDO — avoid ExternalMethod silent NULL / fake connect (#27619).
        $this->functionProxies['pdo::__construct'] = new Call\PdoConstruct();
        $this->functionProxies['pdo::getavailabledrivers'] = new Call\PdoGetAvailableDrivers();
        $this->functionProxies['pdo::quote'] = new Call\PdoQuote();
        // Dom\XMLDocument / Dom\HTMLDocument::createFromString / createFromFile — avoid ExternalMethod silent NULL (#27108, #27300).
        $this->ensureDomLivingDocumentFactoryProxies();
        // XMLReader::XML / fromString / read — avoid ExternalMethod silent NULL on thin AOT (#27299, #28670).
        // XML() exists on all profiles; fromString is PROFILE≥8.4 only.
        XmlReaderInstanceMethodJit::ensureProxy($this, 'xmlreader::xml');
        XmlReaderInstanceMethodJit::ensureProxy($this, 'xmlreader::open');
        XmlReaderInstanceMethodJit::ensureProxy($this, 'xmlreader::read');
        // leftover of fromString read (#35908 / #27299) — php-src readInnerXml / readOuterXml
        XmlReaderInstanceMethodJit::ensureProxy($this, 'xmlreader::readinnerxml');
        XmlReaderInstanceMethodJit::ensureProxy($this, 'xmlreader::readouterxml');
        // leftover of fromString/readInnerXml (#35917 / #27299)
        XmlReaderInstanceMethodJit::ensureProxy($this, 'xmlreader::readstring');
        // leftover of fromString/open (#35911 / #27299)
        XmlReaderInstanceMethodJit::ensureProxy($this, 'xmlreader::expand');
        // leftover of fromString/read (#35926 / #27299)
        XmlReaderInstanceMethodJit::ensureProxy($this, 'xmlreader::next');
        // leftover of fromString/read (#35959 / #27299)
        XmlReaderInstanceMethodJit::ensureProxy($this, 'xmlreader::isvalid');
        // leftover of fromString/read (#35965 / #27299)
        XmlReaderInstanceMethodJit::ensureProxy($this, 'xmlreader::setparserproperty');
        // leftover of fromString (#35971 / #27299)
        XmlReaderInstanceMethodJit::ensureProxy($this, 'xmlreader::setschema');
        XmlReaderInstanceMethodJit::ensureProxy($this, 'xmlreader::setrelaxngschema');
        XmlReaderInstanceMethodJit::ensureProxy($this, 'xmlreader::setrelaxngschemasource');
        // leftover of fromString (#35935 / #27299)
        XmlReaderInstanceMethodJit::ensureProxy($this, 'xmlreader::close');
        // leftover of getAttribute (#35941 / #35918 / #27299)
        XmlReaderInstanceMethodJit::ensureProxy($this, 'xmlreader::movetoattribute');
        // leftover of moveToAttribute (#35946 / #35941 / #27299)
        XmlReaderInstanceMethodJit::ensureProxy($this, 'xmlreader::movetoattributeno');
        // leftover of moveToAttribute (#35948 / #35941 / #27299)
        XmlReaderInstanceMethodJit::ensureProxy($this, 'xmlreader::movetofirstattribute');
        // leftover of moveToAttribute (#35951 / #35941 / #27299)
        XmlReaderInstanceMethodJit::ensureProxy($this, 'xmlreader::movetoattributens');
        // leftover of moveToAttribute (#35940 / #27299)
        XmlReaderInstanceMethodJit::ensureProxy($this, 'xmlreader::movetoelement');
        // leftover of moveToAttribute (#35952 / #35941 / #27299)
        XmlReaderInstanceMethodJit::ensureProxy($this, 'xmlreader::movetonextattribute');
        // leftover of fromString/read (#35962 / #27299)
        XmlReaderInstanceMethodJit::ensureProxy($this, 'xmlreader::getparserproperty');
        if (CompilerVersion::supportsXmlReaderFactories()) {
            XmlReaderInstanceMethodJit::ensureProxy($this, 'xmlreader::fromstring');
            // leftover of fromString (#35900 / #27299)
            XmlReaderInstanceMethodJit::ensureProxy($this, 'xmlreader::fromuri');
            XmlReaderInstanceMethodJit::ensureProxy($this, 'xmlreader::fromstream');
        }
        // XMLWriter::toMemory / toUri / toStream — leftover of openMemory/openUri (#19606 / #35872 / #35895).
        if (CompilerVersion::supportsXmlWriterFactories()) {
            XmlWriterInstanceMethodJit::ensureProxy($this, 'xmlwriter::tomemory');
            XmlWriterInstanceMethodJit::ensureProxy($this, 'xmlwriter::touri');
            XmlWriterInstanceMethodJit::ensureProxy($this, 'xmlwriter::tostream');
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

    public function compileToFile(string $file) {
        Progress::noteFunction('jit_context_compile_to_file_begin');
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

        if (Builtin::LOAD_TYPE_STANDALONE === $this->loadType) {
            // Every standalone main emits __phpc_cli_store_argv — link before that call.
            // Was ensureFull-only for non-thin (#35133); thin already linked here (#34822).
            Builtin\CliArgvRuntime::ensureStandaloneBodies($this);
            // Every standalone main calls __superglobals__refresh — link before that call.
            // Was ensureFull-only for non-thin (#35137); thin already emitRefresh here.
            if ($this->isThinStandaloneAotMain()) {
                // IniRuntime always-on removed (#34848): JitIni / IniGet / IniSet / ErrorReporting /
                // ZendDoubleStringRuntime / ExceptionThrowToStringSeed already ensureLinked before
                // lookup (peer #34578 / #34822). Thin hello-world must not NestedJIT ini ABI during
                // compileToFile pre-main — leftover Context NestedJIT vs Runtime ABI drift mints
                // ini_get.1 / phpc_ini_*.1 (#31894 / #32122).
                Builtin\SuperglobalRefreshRuntime::ensureUserScriptRefreshEmit($this);
            } else {
                Builtin\SuperglobalRefreshRuntime::ensureStandaloneBodies($this);
            }
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
                // php-src zend_reset_lc_ctype_locale — idle nl_langinfo(CODESET) → UTF-8 (#30789).
                $emitInStandaloneMain(fn () => Builtin\LocaleStartupRuntime::emitResetLcCtypeForStandaloneMain($this));
                // Thin user-script AOT still needs pending Error clear/abort for final/readonly
                // property writes (#23665, #3149). Session/header resets stay full-init only.
                if (!$this->isThinStandaloneAotMain()) {
                    // HttpResponseRuntime always-on ensure removed (#35803 / peer #35443):
                    // HttpResponseCode::emitResetForStandaloneMain already ensureLinked
                    // (implement restores insert block — #33965). Full standalone must not
                    // NestedJIT http_response_code bridges during compileToFile prologue when
                    // the script never calls http_response_code() — leftover Context NestedJIT
                    // vs Runtime ABI drift mints http_response_code_apply.1 (#31894 / #32122).
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
                    // ensureLinked fills return-pending bodies after ensureFull drop (#35073).
                    $emitInStandaloneMain(fn () => Builtin\JitReturnPending::ensureLinked($this));
                    $emitInStandaloneMain(fn () => $this->builder->call($this->lookupFunction('phpc_jit_clear_return_pending')));
                }
                // ErrorBridge always-on ensure removed (#35443 / peer #35099): emitClear /
                // emitAbort already ErrorRaise / ReadonlyRaise::ensureLinked (insert restore)
                // before lookup. Thin hello-world must not NestedJIT AssertionErrorRaise during
                // {main} prologue — leftover Context NestedJIT vs Runtime ABI drift mints *.1
                // (#31894 / #32122). Thin still clears/aborts pending Error for final/readonly
                // writes (#23665, #3149).
                $emitInStandaloneMain(fn () => ErrorBridge::emitClearForStandaloneMain($this));
                if (!$this->isThinStandaloneAotMain()) {
                    $emitInStandaloneMain(fn () => ExceptionBridge::emitClearForStandaloneMain($this));
                }
            }
            $emitInStandaloneMain(fn () => Progress::emitNativeNote($this, 'c:main_before_php'));
            if (Builtin::LOAD_TYPE_STANDALONE === $this->loadType) {
                $emitInStandaloneMain(fn () => Builtin\ObjectHandleRuntime::emitSnapBaselineForStandaloneMain($this));
            }
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
                // emitAbort self-ensures (#35443); do not NestedJIT ErrorBridge here.
                $emitInStandaloneMain(fn () => ErrorBridge::emitAbortIfPendingForStandaloneMain($this));
                // Thin AOT: still flush OB when stack was linked (URL-Rewriter endAll, #27566).
                // emitEndAllForStandalone no-ops unless __phpc_ob_end_all has a body (#13571).
                $emitInStandaloneMain(fn () => Builtin\ObOutput::emitEndAllForStandalone($this));
                if (!$this->isThinStandaloneAotMain()) {
                    $emitInStandaloneMain(fn () => ExceptionBridge::emitAbortIfPendingForStandaloneMain($this));
                }
                // Thin AOT skipped this flush, so header() without exit produced no CGI Status
                // (header_redirect.phpt / #1974). emitFlushForStandalone no-ops unless the
                // PendingHeaders helper was NestedJIT-linked (header()/exit).
                $emitInStandaloneMain(fn () => Builtin\PendingHeaders::emitFlushForStandalone($this));
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

        $bitcodePath = getenv('PHP_COMPILER_EMIT_BITCODE');
        if (is_string($bitcodePath) && '' !== $bitcodePath) {
            $bcDir = dirname($bitcodePath);
            if (!is_dir($bcDir) && !mkdir($bcDir, 0775, true) && !is_dir($bcDir)) {
                throw new \RuntimeException('cannot create directory for chunk bitcode: '.$bcDir);
            }
            $this->module->writeBitcodeToFile($bitcodePath);
        }
        $this->exportChunkMethodManifestIfRequested();

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
        AotGcSections::applyFunctionSections($this->llvm, $this->module);
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
        $dumpIr = getenv('PHP_COMPILER_DUMP_IR');
        if ('1' === $dumpIr || 'true' === strtolower((string) $dumpIr)) {
            $this->module->printToFile('/tmp/phpc-last.ll');
        }
        $this->verifyModuleOrThrow();
        Progress::noteFunction('jit_context_verify_done');
    }

    /**
     * LLVM verify failures on large modules repeat the same line thousands of times (#36253).
     * Surface the first distinct lines and a best-effort function name instead of megabytes of IR.
     */
    private function verifyModuleOrThrow(): void
    {
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
        $head = implode("\n", $uniqueLines);
        $totalLines = substr_count($message, "\n") + ('' !== $message && !str_ends_with($message, "\n") ? 1 : 0);
        $suffix = $totalLines > \count($uniqueLines)
            ? "\n… (".($totalLines - \count($uniqueLines)).' more lines truncated)'
            : '';
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
        if (isset($this->functionScope[$name])) {
            return $this->functionScope[$name];
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
            case '__object__*':
                // zend_is_true / zend_std_cast_object_to_type(_IS_BOOL) → true (#32471 leftover of #32463).
                return $this->constantFromBool(true);
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
            // Inlined eval/include {main}: additional $this operands (hoisted vs scoped)
            // must alias the include-entry bind / LLVM param 0 (#31902 / #31903).
            if ($this->inlineIncludeDepth > 0) {
                $inheritedThis = $this->findThisVariable();
                if (null !== $inheritedThis && Variable::TYPE_OBJECT === $inheritedThis->type) {
                    $this->scope->variables[$op] = $inheritedThis;

                    return;
                }
                // Static/file-scope eval must not materialize $this as script global/alloca (#31902 AOT).
                return;
            }
        }
        if (null !== $name && Superglobals::isSuperglobalName($name)) {
            $this->scope->variables[$op] = SuperglobalInit::load($this, $name);

            return;
        }
        if (null !== $name && $block->isMainScript()) {
            // Inlined eval {main} without a bound caller $this must not become a script global (#31902 AOT).
            if ('this' !== $name && !$this->isForeachByRefLocalName($name, $block)) {
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
        // A Temporary rebound onto a FuncCall name-literal slot (ternary ?: phi sharing the
        // INIT name's index) must not rematerialize that name string (#34814).
        if ($op instanceof Operand\Temporary) {
            foreach ($block->scopedOperands() as $scopedOp) {
                if (
                    $block->slotForOperand($scopedOp) === $slot
                    && $scopedOp instanceof Operand\Literal
                    && $scopedOp !== $op
                ) {
                    return false;
                }
            }
        }
        // Function formals carry their default in ARG_RECV / call-site filling.
        // Rematerializing that constant as the CV makes `f($x = 1); f(7)` and
        // `__construct(public $x = 1); new C(7)` ignore the argument (#32349).
        if (null !== $block->func) {
            $opName = OperandName::resolve($op);
            foreach ($block->func->params as $param) {
                if ($param->result === $op) {
                    return false;
                }
                $paramName = OperandName::resolve($param->result);
                if (null !== $opName && null !== $paramName && $opName === $paramName) {
                    return false;
                }
            }
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
        // Enum/object compile-time slots must not be rematerialized during hoisted
        // makeVariableFromOp: that runs before DECLARE_ENUM/DECLARE_CLASS (#31967).
        // After the enum is registered, folded `C::K` / `E::X` slots (the script-level
        // CLASS_CONST_FETCH is often eliminated) must rematerialize the singleton.
        if (\PHPCompiler\VM\Variable::TYPE_ENUM_CASE === $constVm->type) {
            try {
                $case = $constVm->toEnumCase();
            } catch (\Throwable) {
                return false;
            }
            $enumLc = strtolower(ltrim($case->enumClass->name, '\\'));
            if (
                !$this->type->object->hasDeclaredClass($enumLc)
                || !$this->type->object->isRegisteredEnumLc($enumLc)
            ) {
                return false;
            }

            return true;
        }
        if (\PHPCompiler\VM\Variable::TYPE_OBJECT === $constVm->type) {
            return false;
        }
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
            // ?: merge Temporary and FUNCCALL name Literal share a numeric slot after
            // bindScopeSlot (#34818). Aliasing the phi onto LITERAL('strlen') makes
            // `true ? strlen($s) : "bad"` echo the function name.
            if ($op instanceof Operand\Temporary && $scopeOp instanceof Operand\Literal) {
                continue;
            }
            $this->scope->variables[$op] = $this->scope->variables[$scopeOp];

            return true;
        }
        foreach ($block->scopedOperands() as $scopeOp) {
            if ($block->slotForOperand($scopeOp) !== $slot || !$this->scope->variables->contains($scopeOp)) {
                continue;
            }
            if ($op instanceof Operand\Temporary && $scopeOp instanceof Operand\Literal) {
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
                    $slot = $block->slotForOperand($op);
                    if (null !== $slot && null !== $block->func) {
                        foreach ($block->func->params as $param) {
                            $pname = OperandName::resolve($param->result);
                            if (null === $pname || '' === $pname) {
                                continue;
                            }
                            if ($block->slotForOperand($param->result) !== $slot) {
                                continue;
                            }
                            $resolved = $this->resolveRefAliasName($pname);
                            if (isset($this->namedVariableBindings[$resolved])) {
                                $bound = $this->namedVariableBindings[$resolved];
                                $this->scope->variables[$op] = $bound;

                                return $bound;
                            }
                        }
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

        if ($this->scope->variables->contains($op)) {
            $bound = $this->resolveNamedBindingBySlot($op);
            if (null !== $bound) {
                $this->scope->variables[$op] = $bound;

                return $bound;
            }
            $block = $this->jitCurrentBlock;
            if (null !== $block && null !== $block->func) {
                $slot = $block->slotForOperand($op);
                if (null !== $slot) {
                    foreach ($block->func->params as $param) {
                        $pname = OperandName::resolve($param->result);
                        if (null === $pname || '' === $pname) {
                            continue;
                        }
                        if ($block->slotForOperand($param->result) !== $slot) {
                            continue;
                        }
                        $resolved = $this->resolveRefAliasName($pname);
                        if (isset($this->namedVariableBindings[$resolved])) {
                            $bound = $this->namedVariableBindings[$resolved];
                            $this->scope->variables[$op] = $bound;

                            return $bound;
                        }
                    }
                }
            }
        }

        $bound = $this->resolveNamedBindingBySlot($op);
        if (null !== $bound) {
            $this->scope->variables[$op] = $bound;

            return $bound;
        }

        return $this->scope->variables[$op];
    }

    /**
     * Loop headers reuse CFG slot numbers — prefer live named bindings over stale
     * compare temps so `$i < $len` reads the post-increment alloca (#36018 / #32605).
     */
    private function resolveNamedBindingBySlot(Operand $op): ?Variable
    {
        $block = $this->jitCurrentBlock ?? $this->jitEnclosingBlock;
        if (null === $block || null === $block->func) {
            return null;
        }
        $slot = $block->slotForOperand($op);
        if (null === $slot) {
            return null;
        }
        foreach ($block->scopedOperands() as $scopeOp) {
            $pname = OperandName::resolve($scopeOp);
            if (null === $pname || '' === $pname) {
                continue;
            }
            if ($block->slotForOperand($scopeOp) !== $slot) {
                continue;
            }
            $resolved = $this->resolveRefAliasName($pname);
            if (isset($this->namedVariableBindings[$resolved])) {
                return $this->namedVariableBindings[$resolved];
            }
        }

        return null;
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
            // TYPE_THROW must keep its operand alive the same way RETURN does: uncaught
            // emitThrow calls freeDeadVariables before instanceof Throwable, and freeing the
            // Exception object made `return throw new …` / `fn()=>throw new …` look like a
            // non-Throwable (or SIGSEGV) under AOT (#34868, peer #34859).
            if (
                (OpCode::TYPE_RETURN !== $blockOp->type && OpCode::TYPE_THROW !== $blockOp->type)
                || null === $blockOp->arg1
            ) {
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

    /**
     * File-scope {@code const X = E::A} / define() holding an enum case (#34783).
     *
     * Class-const path rematerializes via {@see VmConstantJit} (#31967); global CONST_FETCH
     * previously only lowered scalars and threw on VM TYPE_OBJECT / TYPE_ENUM_CASE.
     *
     * php-src: Zend/zend_constants.c + Zend/zend_enum.c — file consts store case singletons.
     */
    private function constantFetchEnumCaseVariable(string $name, VMVariable $phpVar): Variable
    {
        if (VMVariable::TYPE_ENUM_CASE === $phpVar->type) {
            $var = VmConstantJit::toVariable($this, $phpVar);
            $var->compileTimeConstantName = $name;

            return $var;
        }
        $enumClass = \PHPCompiler\VM\EnumCaseSupport::enumClassForCaseVariable($phpVar);
        $caseName = \PHPCompiler\VM\EnumCaseSupport::enumCaseNameForVariable($phpVar);
        if (null === $enumClass || '' === $caseName) {
            throw new \LogicException('Enum case constant missing class/name: '.$name);
        }
        // Declared spelling for display name (#35332); lookup keys remain lowercase.
        $classId = $this->type->object->lookup(ltrim($enumClass->name, '\\'));
        $caseKey = \PHPCompiler\ClassConstName::key($caseName);
        $var = $this->type->object->jitEnumCaseFromBacking($classId, $caseKey);
        $var->compileTimeConstantName = $name;

        return $var;
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
            // File-scope const / define() with enum case singleton (#34783, peer #31967).
            if (\PHPCompiler\VM\EnumCaseSupport::isEnumCaseVariable($phpVar)) {
                return $this->constantFetchEnumCaseVariable($name, $phpVar);
            }
            // File-scope const holding a user/builtin object (#35196, new-in-initializers).
            if (VMVariable::TYPE_OBJECT === $phpVar->type) {
                $global = $this->constantObjectFromVm($name, $phpVar);
                $var = new Variable(
                    $this,
                    Variable::TYPE_OBJECT,
                    Variable::KIND_VALUE,
                    $this->builder->load($global)
                );
                $var->compileTimeConstantName = $name;

                return $var;
            }
            // convert to PHP variable
            switch ($phpVar->type) {
                case VMVariable::TYPE_NULL:
                    // Match Variable::fromLiteral TYPE_NULL — a real __value__ box, not
                    // nullptr. Catch-body / assign paths load through the pointer (#34659).
                    $slot = JitValueBox::alloc($this);
                    $this->builder->call(
                        $this->lookupFunction('__value__writeNull'),
                        JitValueBox::pointer($this, $slot)
                    );
                    $nullVar = new Variable(
                        $this,
                        Variable::TYPE_VALUE,
                        Variable::KIND_VARIABLE,
                        $slot
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
                    $global->setInitializer($this->constantFromFloat($floatVal));
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
