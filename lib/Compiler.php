<?php

/*
 * This file is part of PHP-Compiler, a PHP CFG Compiler for PHP code
 *
 * @copyright 2015 Anthony Ferrara. All rights reserved
 * @license MIT See LICENSE at the root of the project for more info
 */

namespace PHPCompiler;

require_once __DIR__.'/OpCodeNames.php';
require_once __DIR__.'/Compiler/Concern/CompileTimeFold.php';
require_once __DIR__.'/Compiler/Concern/ParameterAsserts.php';
require_once __DIR__.'/Compiler/Concern/CoalesceAndNullsafe.php';
require_once __DIR__.'/Compiler/Concern/ClassLikeAndStmtCompile.php';
require_once __DIR__.'/Compiler/Concern/ErrorSuppressAndPropertyFetch.php';
require_once __DIR__.'/Compiler/Concern/WriteContextRejects.php';
require_once __DIR__.'/Compiler/Concern/TernaryAndLogicalShortCircuit.php';
require_once __DIR__.'/Compiler/Concern/CompileCallArgSends.php';
require_once __DIR__.'/Compiler/Concern/CompileInlineSpecializedCallArgSends.php';
require_once __DIR__.'/Compiler/Concern/InlineCallArgProducerMatch.php';
require_once __DIR__.'/Compiler/Concern/FindInlineCallArgProducerSlot.php';
require_once __DIR__.'/Compiler/Concern/PrecedingInlineCallArgProducers.php';
require_once __DIR__.'/Compiler/Concern/SiblingInlineFuncCallProducers.php';
require_once __DIR__.'/Compiler/Concern/SiblingInlineCallArgProducerSlots.php';
require_once __DIR__.'/Compiler/Concern/SlotForCallArgResolvers.php';
require_once __DIR__.'/Compiler/Concern/InlineCallArgSlotResolvers.php';
require_once __DIR__.'/Compiler/Concern/RewireInlineCallArgSendSlots.php';
require_once __DIR__.'/Compiler/Concern/AdjacentNestedCallArgSlots.php';
require_once __DIR__.'/Compiler/Concern/ListDestructAndForeach.php';
require_once __DIR__.'/Compiler/Concern/DimAndPropertyWriteContext.php';
require_once __DIR__.'/Compiler/Concern/IssetEmptyUnsetAndDimFetchCompile.php';
require_once __DIR__.'/Compiler/Concern/InlineCallArgCompileTimeFold.php';
require_once __DIR__.'/Compiler/Concern/EchoCoalesceCallArgCompile.php';
require_once __DIR__.'/Compiler/Concern/FirstClassCallableAndClosure.php';
require_once __DIR__.'/Compiler/Concern/ErrorSuppressCallArgProducers.php';
require_once __DIR__.'/Compiler/Concern/FinalizeArrayFamilyCallArgSlots.php';
require_once __DIR__.'/Compiler/Concern/FunctionStaticAndCompileTimeLiterals.php';
require_once __DIR__.'/Compiler/Concern/IssetEmptyCallArgAndMultiCompile.php';
require_once __DIR__.'/Compiler/Concern/CoalesceLeftAndEchoConcatPreludes.php';
require_once __DIR__.'/Compiler/Concern/InstanceOfInAndClassConstCompile.php';
require_once __DIR__.'/Compiler/Concern/OuterSiblingAndBuiltinWireCallArgSlots.php';
require_once __DIR__.'/Compiler/Concern/CallAndArrayLiteralCompile.php';
require_once __DIR__.'/Compiler/Concern/SiblingInlineFuncCallAndDeadArrayProducers.php';
require_once __DIR__.'/Compiler/Concern/NestedArrayAndLeadingConstCallArgProducers.php';
require_once __DIR__.'/Compiler/Concern/DeferredSiblingAndArrayMapNullCallArgProducers.php';
require_once __DIR__.'/Compiler/Concern/HoistedEnumAndChainedInlineCallArgProducers.php';
require_once __DIR__.'/Compiler/Concern/ExactHoistedAndInlineNewCallArgProducers.php';

use SplObjectStorage;
use PHPCfg\Func as CfgFunc;
use PHPCfg\Op;
use PHPCfg\Block as CfgBlock;
use PHPCfg\ErrorSuppressBlock;
use PHPCfg\Operand;
use PHPCfg\Operand\BoundVariable;
use PHPCfg\Operand\Literal;
use PHPCfg\Operand\NullOperand;
use PHPCfg\Operand\Temporary;
use PHPCfg\Operand\Variable as CfgVariable;
use PHPCfg\Script;
use PHPTypes\Type;
use PHPCompiler\VM\AttributeSupport;
use PHPCompiler\VM\ClassConstExpr;
use PHPCompiler\VM\ClassConstMaterializer;
use PHPCompiler\VM\ClassEntry;
use PHPCompiler\VM\Context as VMContext;
use PHPCompiler\VM\ObjectEntry;
use PHPCompiler\VM\EnumCaseSupport;
use PHPCompiler\VM\EnumSupport;
use PHPCompiler\VM\DateTimeInterfaceSupport;
use PHPCompiler\VM\HashTable;
use PHPCompiler\VM\ReferencableCheck;
use PHPCompiler\VM\TypeCheck;
use PHPCompiler\VM\Variable;
use PHPCompiler\VM\VariableFunctionCall;
use PHPCompiler\VM\ClassReadonly;
use PHPCompiler\VM\ClassFinal;
use PHPCompiler\VM\ClosureRichDisplayName;
use PHPCompiler\JIT\OperandName;
use PHPCompiler\Ast\AsymmetricVisibilityRewriter;
use PHPCompiler\Ast\FinalPromotedPropertyRewriter;
use PHPCompiler\Ast\LazyPropertyRewriter;
use PHPCompiler\Ast\GeneratorYieldSourceMarker;
use PHPCompiler\Cfg\OpSubBlockAccess;
use PHPCompiler\Compiler\AbstractMethodBodyCheck;
use PHPCompiler\Compiler\AbstractMethodVisibilityCheck;
use PHPCompiler\Compiler\AbstractPromotedPropertyCompileCheck;
use PHPCompiler\Compiler\InterfaceConstAmbiguityCheck;
use PHPCompiler\Compiler\InterfaceConstVisibilityCheck;
use PHPCompiler\Compiler\InterfaceMethodBodyCheck;
use PHPCompiler\Compiler\InterfaceMethodFinalCheck;
use PHPCompiler\Compiler\InterfaceMethodVisibilityCheck;
use PHPCompiler\Compiler\EnumAbstractMethodCompileCheck;
use PHPCompiler\Compiler\EnumBuiltinMethodRedeclareCheck;
use PHPCompiler\Compiler\ClassConstDuplicateCheck;
use PHPCompiler\Compiler\ClosureUseDuplicateCompileCheck;
use PHPCompiler\Compiler\EnumBackedCaseCheck;
use PHPCompiler\Compiler\EnumMagicMethodCheck;
use PHPCompiler\Compiler\EnumParentCompileCheck;
use PHPCompiler\Compiler\MagicMethodArityCheck;
use PHPCompiler\Compiler\MagicMethodParamTypeCheck;
use PHPCompiler\Compiler\MagicMethodReturnTypeCheck;
use PHPCompiler\Compiler\MagicMethodStaticCheck;
use PHPCompiler\Compiler\PseudoClassTypeHintCompileCheck;
use PHPCompiler\Compiler\DuplicateUnionMemberCompileCheck;
use PHPCompiler\Compiler\RedundantDnfArmCompileCheck;
use PHPCompiler\Compiler\RedundantDnfArmSubsetCompileCheck;
use PHPCompiler\Compiler\RedundantObjectClassUnionCompileCheck;
use PHPCompiler\Compiler\IntersectionTypeMemberCompileCheck;
use PHPCompiler\Compiler\FunctionStaticAnonymousClassCompileCheck;
use PHPCompiler\Compiler\NewWithoutParensCompileCheck;
use PHPCompiler\Compiler\NonAbstractMethodBodyCheck;
use PHPCompiler\Compiler\NonEnumBuiltinInterfaceCompileCheck;
use PHPCompiler\Compiler\ThrowInClassConstCompileCheck;
use PHPCompiler\Compiler\AsymmetricVisibilityCompileCheck;
use PHPCompiler\Compiler\CompileFatal;
use PHPCompiler\Compiler\AttributeClassRegistry;
use PHPCompiler\Compiler\AttributeConstantEvaluator;
use PHPCompiler\Compiler\AttributeEntry;
use PHPCompiler\Compiler\AttributeMetadata;
use PHPCompiler\Compiler\AttributeNames;
use PHPCompiler\Compiler\AttributeTargetValidator;
use PHPCompiler\Compiler\DeprecatedMetadata;
use PHPCompiler\Compiler\NoDiscardMetadata;
use PHPCompiler\Compiler\FinalClassConstCheck;
use PHPCompiler\Compiler\TraitClassConstConflictCheck;
use PHPCompiler\Compiler\FinalClassExtensionCheck;
use PHPCompiler\Compiler\ImplementsHierarchyCompileCheck;
use PHPCompiler\VM\ImplementsHierarchyRuntimeCheck;
use PHPCompiler\Compiler\FinalMethodOverrideCheck;
use PHPCompiler\Compiler\FinalPropertyOverrideCheck;
use PHPCompiler\Compiler\InterfaceImplementationCheck;
use PHPCompiler\Compiler\ParameterMetadata;
use PHPCompiler\VM\ReflectionTypeSupport;
use PHPCompiler\Compiler\GeneratorNeverReturnCompileCheck;
use PHPCompiler\Compiler\GeneratorStaticMethodCompileCheck;
use PHPCompiler\Compiler\ReadonlyClassCompileCheck;
use PHPCompiler\Compiler\SourceLocation;
use PHPCompiler\Compiler\TraitCollisionCheck;
use PHPCompiler\Compiler\ClassConstVisibilityInheritCheck;
use PHPCompiler\Compiler\PropertyVisibilityInheritCheck;
use PHPCompiler\Compiler\TypedClassConstInheritCheck;
use PHPCompiler\Compiler\TypedPropertyInheritCheck;
use PHPCompiler\Compiler\VariadicPromotedPropertyCompileCheck;
use PHPCompiler\Compiler\ClassCompileRegistry;
use PHPCompiler\Compiler\Concern\CompileTimeFold;
use PHPCompiler\Compiler\Concern\ParameterAsserts;
use PHPCompiler\Compiler\Concern\CoalesceAndNullsafe;
use PHPCompiler\Compiler\Concern\ClassLikeAndStmtCompile;
use PHPCompiler\Compiler\Concern\ErrorSuppressAndPropertyFetch;
use PHPCompiler\Compiler\Concern\TernaryAndLogicalShortCircuit;
use PHPCompiler\Compiler\Concern\CompileCallArgSends;
use PHPCompiler\Compiler\Concern\CompileInlineSpecializedCallArgSends;
use PHPCompiler\Compiler\Concern\InlineCallArgProducerMatch;
use PHPCompiler\Compiler\Concern\FindInlineCallArgProducerSlot;
use PHPCompiler\Compiler\Concern\PrecedingInlineCallArgProducers;
use PHPCompiler\Compiler\Concern\SiblingInlineFuncCallProducers;
use PHPCompiler\Compiler\Concern\SiblingInlineCallArgProducerSlots;
use PHPCompiler\Compiler\Concern\SlotForCallArgResolvers;
use PHPCompiler\Compiler\Concern\InlineCallArgSlotResolvers;
use PHPCompiler\Compiler\Concern\RewireInlineCallArgSendSlots;
use PHPCompiler\Compiler\Concern\AdjacentNestedCallArgSlots;
use PHPCompiler\Compiler\Concern\ListDestructAndForeach;
use PHPCompiler\Compiler\Concern\DimAndPropertyWriteContext;
use PHPCompiler\Compiler\Concern\IssetEmptyUnsetAndDimFetchCompile;
use PHPCompiler\Compiler\Concern\InlineCallArgCompileTimeFold;
use PHPCompiler\Compiler\Concern\EchoCoalesceCallArgCompile;
use PHPCompiler\Compiler\Concern\FirstClassCallableAndClosure;
use PHPCompiler\Compiler\Concern\ErrorSuppressCallArgProducers;
use PHPCompiler\Compiler\Concern\FinalizeArrayFamilyCallArgSlots;
use PHPCompiler\Compiler\Concern\FunctionStaticAndCompileTimeLiterals;
use PHPCompiler\Compiler\Concern\IssetEmptyCallArgAndMultiCompile;
use PHPCompiler\Compiler\Concern\CoalesceLeftAndEchoConcatPreludes;
use PHPCompiler\Compiler\Concern\InstanceOfInAndClassConstCompile;
use PHPCompiler\Compiler\Concern\OuterSiblingAndBuiltinWireCallArgSlots;
use PHPCompiler\Compiler\Concern\CallAndArrayLiteralCompile;
use PHPCompiler\Compiler\Concern\SiblingInlineFuncCallAndDeadArrayProducers;
use PHPCompiler\Compiler\Concern\NestedArrayAndLeadingConstCallArgProducers;
use PHPCompiler\Compiler\Concern\DeferredSiblingAndArrayMapNullCallArgProducers;
use PHPCompiler\Compiler\Concern\HoistedEnumAndChainedInlineCallArgProducers;
use PHPCompiler\Compiler\OverrideValidator;
use PHPCompiler\Web\ConstStringFolder;
use PHPCompiler\Web\IncludePathResolver;
use PHPCompiler\Web\Superglobals;

class Compiler {

    use CompileTimeFold;
    use ParameterAsserts;
    use CoalesceAndNullsafe;
    use ClassLikeAndStmtCompile;
    use ErrorSuppressAndPropertyFetch;
    use WriteContextRejects;
    use TernaryAndLogicalShortCircuit;
    use CompileCallArgSends;
    use CompileInlineSpecializedCallArgSends;
    use InlineCallArgProducerMatch;
    use FindInlineCallArgProducerSlot;
    use PrecedingInlineCallArgProducers;
    use SiblingInlineFuncCallProducers;
    use SiblingInlineCallArgProducerSlots;
    use SlotForCallArgResolvers;
    use InlineCallArgSlotResolvers;
    use RewireInlineCallArgSendSlots;
    use AdjacentNestedCallArgSlots;
    use ListDestructAndForeach;
    use DimAndPropertyWriteContext;
    use IssetEmptyUnsetAndDimFetchCompile;
    use InlineCallArgCompileTimeFold;
    use EchoCoalesceCallArgCompile;
    use FirstClassCallableAndClosure;
    use ErrorSuppressCallArgProducers;
    use FinalizeArrayFamilyCallArgSlots;
    use FunctionStaticAndCompileTimeLiterals;
    use IssetEmptyCallArgAndMultiCompile;
    use CoalesceLeftAndEchoConcatPreludes;
    use InstanceOfInAndClassConstCompile;
    use OuterSiblingAndBuiltinWireCallArgSlots;
    use CallAndArrayLiteralCompile;
    use SiblingInlineFuncCallAndDeadArrayProducers;
    use NestedArrayAndLeadingConstCallArgProducers;
    use DeferredSiblingAndArrayMapNullCallArgProducers;
    use HoistedEnumAndChainedInlineCallArgProducers;
    use ExactHoistedAndInlineNewCallArgProducers;

    protected ?SplObjectStorage $seen = null;
    protected ?SplObjectStorage $funcs = null;

    /** @var ?SplObjectStorage<Operand, Op\Expr> producer-expr index over $seen block trees (#16077) */
    private ?SplObjectStorage $cfgProducerExprIndex = null;

    /** @var ?SplObjectStorage<CfgBlock, int> indexed blocks -> children count at index time (#16077) */
    private ?SplObjectStorage $cfgProducerIndexedBlocks = null;

    /** @var array<int, true> spl_object_id of cfg var roots that have >=1 indexed producer candidate */
    private array $cfgProducerRootsWithCandidates = [];

    /** Identity of the $seen storage the index was built against; rebuilt when $seen is replaced. */
    private ?SplObjectStorage $cfgProducerIndexSeenSource = null;

    /** Fingerprint of $seen block count + child counts when producer index last synced (#36224). */
    private int $cfgProducerIndexLastSyncFingerprint = -1;

    /** spl_object_id(callOp) => preceding inline producers (#36224). */
    private array $precedingInlineCallArgProducersCache = [];

    /** spl_object_id(callOp) => index in owning cfg block children (#36224). */
    private array $cfgCallOpIndexCache = [];

    /** spl_object_id(CfgBlock)|string => children count when op-index map was last built (#36224). */
    private array $cfgChildrenOpIndexBuiltCount = [];

    /** @var SplObjectStorage<CfgBlock, SplObjectStorage<CfgVariable, int>> ?: merge var slots (#3790) */
    private SplObjectStorage $ternaryMergeVarSlots;

    /** @var SplObjectStorage<CfgBlock, int> ?: assign-phi RHS slot from first lowered arm (#9159) */
    private SplObjectStorage $ternaryMergePhiRhsSlots;

    /** @var SplObjectStorage<Op\Stmt\JumpIf, true> ?: return `null !== $p ? $p : null` rewritten (#8563) */
    private SplObjectStorage $rewrittenNeNullReturnJumpIf;

    /**
     * File-level ({main}) function decls early-bound at entry — skip at original CFG site (#24807).
     * Set-like map of early-bound Function_ ops; no generics in @var (php-types / #24877).
     *
     * @var SplObjectStorage
     */
    private SplObjectStorage $earlyBoundFunctionOps;

    private ?string $debugLastPhaseInputFile = null;
    /** Source text for the current compile() call — `new Foo()` paren detection (#9116). */
    private ?string $compileSourceCode = null;
    private int $debugLastPhaseCounter = 0;
    private ?string $debugLastPhaseKey = null;

    /** Set from the first compile-time abort (#2642, self-host diagnostics). */
    private ?string $compileAbortDetail = null;

    /** CFG op being lowered — fallback file/line for throwCompileError/Logic (#36227). */
    private ?Op $compileErrorContextOp = null;

    /** While compiling an arrow function CFG for implicit outer captures (#10304). */
    private bool $compilingArrowAutoCapture = false;

    /** @var array<string, array<string, array<string, mixed>>> from PropertyHooks preprocessor (#6770). */
    private array $propertyHookRegistry = [];
    /** 1-based source lines lowered from bare `throw;` (#3508). */
    private array $bareRethrowLines = [];
    /** spl_object_id(Coalesce expr) => scope slot for ?? result (stmt ?? before call args, #9479). */
    private array $coalesceResultSlots = [];
    /** spl_object_id(Coalesce) => CFG merge block for chained ?? call-arg lowering (#17590). */
    private array $coalesceMergeBlocks = [];
    /** spl_object_id(NullsafePropertyFetch|NullsafeMethodCall) => scope slot for ?-> result (#18455). */
    private array $nullsafeResultSlots = [];
    /** spl_object_id(NullsafePropertyFetch|NullsafeMethodCall) => CFG merge block (#18455). */
    private array $nullsafeMergeBlocks = [];
    /** cfgVarRoot / call-arg oid => slot wired by syncCoalesceResultToDistinctFuncCallArg (#15915). */
    private array $syncedCoalesceFuncCallArgSlots = [];
    /** spl_object_id(Coalesce) => ??= lvalue operand when result temp differs (#5337, #17458). */
    private array $coalesceAssignLvalues = [];
    /** Trailing source bytes after __halt_compiler(); (issue #3479). */
    private ?string $haltCompilerRemaining = null;
    /** {@see OpCode::ASSIGN_REF_FOREACH_PROPERTY_HOOK} for the next AssignRef compile (#6435). */
    private int $assignRefBindRefFlags = 0;
    /** Force PROPERTY_FETCH_WRITE for array-literal by-ref element lowering (#6426, #17353). */
    private int $forcePropertyFetchForWrite = 0;

    /**
     * Compile-time declare(ticks=N) interval for emitting TYPE_TICKS after statements (#22840).
     * Mirrors CG(declarables).ticks — braced scopes push/pop via activeTickIntervalStack.
     */
    private int $activeTickInterval = 0;

    /** @var list<int> */
    private array $activeTickIntervalStack = [];

    /** Byte offset where halt trailing data starts; null when no __halt_compiler() (#5455). */
    private ?int $haltCompilerOffset = null;

    /** Lowercase class name while compiling a class body (#3803). */
    private ?string $compilingClassLc = null;

    /**
     * Include/require target units may use self/parent/static at file scope — Zend resolves at
     * runtime from the caller's called_scope (#31913, zend_execute.c ZEND_INCLUDE_OR_EVAL).
     */
    private int $includeTargetCompileDepth = 0;

    /** Parent lc while compiling a class body — registerClass() runs after body (#13533). */
    private ?string $compilingClassParentLc = null;

    /** Parent display name while compiling a class body (#26252). */
    private ?string $compilingClassParentName = null;

    /** Display class name while compiling a class body (#4286). */
    private ?string $compilingClassDisplayName = null;

    /**
     * Declaring class (self) while compiling eval()'d code from a method (#31912).
     *
     * Distinct from {@see $compilingClassLc} (class *body* being lowered). Zend
     * `zend_eval_string` compiles with the caller's `func->common.scope`.
     */
    private ?string $evalClassScopeLc = null;

    /** Display FQCN for {@see $evalClassScopeLc} (#31912). */
    private ?string $evalClassScopeDisplay = null;

    /**
     * Saved eval class-scope pairs for reentrant parseAndCompile (#31912).
     *
     * @var list<array{0:?string,1:?string}>
     */
    private array $evalClassScopeStack = [];

    /**
     * PHP 8.4+ rich closure names keyed by CFG Func — set before body compile so nested
     * closures can nest parent names (zend_compile.c, #30076).
     *
     * @var ?SplObjectStorage<\PHPCfg\Func, string>
     */
    private ?SplObjectStorage $closureRichNameByFunc = null;

    /** True while compiling a `readonly class` body (#29186). */
    private bool $compilingClassIsReadonly = false;

    /** @var array<string, true> instance property names declared in the current class body (#4286) */
    private array $compilingClassInstancePropertyNames = [];

    /** @var array<string, true> lowercase method names declared in the current class/interface/enum body (#5218) */
    private array $compilingClassMethodNames = [];

    /** @var array<string, array<string, Variable>> compile-time class constants by lc name */
    private array $compileTimeClassConsts = [];

    /** @var array<string, array<string, true>> class-body const declarations already emitted (#17173, #5953). */
    private array $compileTimeClassConstEmitted = [];

    /** @var array<string, array<string, int>> compile-time class constant visibility flags by lc name (#6784) */
    private array $compileTimeClassConstVisibility = [];

    /** @var array<string, array<string, DeprecatedMetadata>> deprecated class constants by lc name (#6962) */
    private array $compileTimeClassConstDeprecated = [];

    /** @var array<string, array<string, string>> compile-time class constant declared casing by lc name (#25910) */
    private array $compileTimeClassConstNames = [];

    /** @var array<string, Variable> lowercase global constant name => compile-time value (#3803, #6542) */
    private array $compileTimeGlobalConsts = [];

    /** @var array<string, ?string> lowercase enum name => backing type (`int`/`string`) while compiling enum body */
    private array $compileTimeEnumBackedTypes = [];

    /** @var array<string, array<string, true>> lowercase enum => lowercase `case` names (#5054) */
    private array $compileTimeEnumCaseConstNames = [];

    /** @var array<string, array<string, Variable>> runtime builtin enum constants by lowercase enum/const */
    private array $runtimeEnumCaseConsts = [];

    /** @var array<string, array<string, true>> lowercase class => declared static property names (#3814). */
    private array $compiledClassStaticProperties = [];

    /** Class being compiled while lowering static property declarations (#3814). */
    private ?string $currentClassStaticPropertyCompile = null;

    /**
     * Operand slots for deferred inline array literals within the current CFG block compile (#14134).
     *
     * @var array<int, true>
     */
    private array $deferredArrayLiteralKeepSlots = [];

    /** CFG block owning a rematerialized Expr producer — inline call-arg lookup (#14134, #15848). */
    private ?CfgBlock $rematerializeInlineProducerCfgBlock = null;

    /** @var array<string, true> lowercase user function names declared `: never` (#4117). */
    private array $neverFunctionNames = [];

    /** @var array<string, array<int, true>> lowercase user function => by-ref param indices (#25301). */
    private array $userFunctionParamByRef = [];

    /** True while lowering switch to JUMPIF/EQUAL — skip ?: merge slot bridging (#878). */
    private bool $compilingSwitchJumpIfChain = false;

    /** Force FUNCCALL_EXEC_RETURN while lowering hoisted sibling call-arg producers (#10981). */
    private bool $forceDeferredSiblingCallReturnSlot = false;

    /** Keep nested inline producer FUNCCALL_INIT in argSends return, not prepended on $block (#17862). */
    private bool $inlineNestedProducerOpsInArgSends = false;

    /** Reentrancy guard — statementLevel() ↔ firstSibling() mutual recursion (#9321). */
    private bool $firstSiblingInlineFuncCallProducerIndexActive = false;

    /**
     * Memoize firstSibling scans per consumer op (nested call-arg compile was O(n²) from
     * hundreds of identical lookups per statement — #36387 / #36224).
     *
     * @var array<string, int> cache key => index, or -1 for null
     */
    private array $firstSiblingInlineFuncCallProducerCache = [];

    /**
     * Memoize isSiblingMultiArgFuncCallProducer(producer, consumer) (#36387).
     *
     * @var array<string, bool>
     */
    private array $isSiblingMultiArgFuncCallProducerCache = [];

    /** @var array<string, true> reentrancy set while computing a cache entry (#36387). */
    private array $isSiblingMultiArgFuncCallProducerComputing = [];

    /**
     * Memoize deferredSiblingInlineCallArgConsumerIndex per producer (#36387).
     *
     * @var array<int, int> spl_object_id(producer) => consumer index, or -1 for null
     */
    private array $deferredSiblingInlineCallArgConsumerIndexCache = [];

    /**
     * Memoize callArgIsDeadInlineTemporary per operand (#36387).
     *
     * @var array<int, bool>
     */
    private array $callArgIsDeadInlineTemporaryCache = [];

    /**
     * Memoize whether a CFG block's children contain any BinaryOp\Coalesce (#36387).
     * Without this, findCoalesceStmtForCallArg rescans every FuncCall arg in the block
     * on every call — O(n²) on nested stmt blocks that have no ?? at all.
     *
     * @var array<int, bool> spl_object_id(CfgBlock) => has coalesce stmt
     */
    private array $cfgBlockHasCoalesceStmtCache = [];

    /**
     * Memoize resolveCfgFuncCallName per call op (#36387).
     *
     * @var array<int, string|null>
     */
    private array $resolveCfgFuncCallNameCache = [];

    /**
     * Memoize deadInlineTemporaryArgCount per consumer op (#36387).
     *
     * @var array<int, int>
     */
    private array $deadInlineTemporaryArgCountCache = [];

    /**
     * Memoize callResultFeedsInlineCallArg per result operand (#36387).
     *
     * @var array<int, bool>
     */
    private array $callResultFeedsInlineCallArgCache = [];

    /** Catch variable name (lc) => scope slot while lowering catch bodies (#9887). */
    private array $activeCatchVarSlotsByName = [];

    /** Catch variable cfg roots while lowering catch bodies (#9887). */
    private array $activeCatchVarRoots = [];

    /** Script declares DNF-typed instance properties — MCJIT needs a try region (#4111). */
    private bool $scriptHasDnfTypedProperties = false;

    private ClassCompileRegistry $classCompileRegistry;

    private AttributeClassRegistry $attributeClassRegistry;

    public function setBareRethrowLines(array $lines): void
    {
        $this->bareRethrowLines = $lines;
    }

    /**
     * Bind eval() compile to the caller's declaring class (self/parent) (#31912).
     *
     * php-src: Zend/zend_execute_API.c zend_eval_string — CG(active_class_entry) /
     * execute_data->func->common.scope so `self::class` is not a global-scope fatal.
     */
    public function pushEvalClassScope(?string $className): void
    {
        $this->evalClassScopeStack[] = [$this->evalClassScopeLc, $this->evalClassScopeDisplay];
        if (null === $className || '' === $className) {
            $this->evalClassScopeLc = null;
            $this->evalClassScopeDisplay = null;

            return;
        }
        $display = ltrim($className, '\\');
        $this->evalClassScopeDisplay = $display;
        $this->evalClassScopeLc = strtolower($display);
    }

    public function popEvalClassScope(): void
    {
        $prev = array_pop($this->evalClassScopeStack);
        if (null === $prev) {
            $this->evalClassScopeLc = null;
            $this->evalClassScopeDisplay = null;

            return;
        }
        [$this->evalClassScopeLc, $this->evalClassScopeDisplay] = $prev;
    }

    public function setDebugLastPhaseInputFile(?string $filename): void
    {
        $this->debugLastPhaseInputFile = $filename;
    }

    public function getDebugLastPhaseInputFile(): ?string
    {
        return $this->debugLastPhaseInputFile;
    }

    public function setCompileSourceCode(?string $code): void
    {
        $this->compileSourceCode = $code;
    }

    private function debugLastPhaseIsEnabled(): bool
    {
        if (\defined('PHP_COMPILER_DEBUG_LAST_PHASE') && PHP_COMPILER_DEBUG_LAST_PHASE) {
            return true;
        }
        $v = $_SERVER['PHP_COMPILER_DEBUG_LAST_PHASE'] ?? $_ENV['PHP_COMPILER_DEBUG_LAST_PHASE'] ?? Config::getenv('PHP_COMPILER_DEBUG_LAST_PHASE');
        if (false === $v || null === $v || '' === $v) {
            return false;
        }
        $v = strtolower((string) $v);

        return '1' === $v || 'true' === $v || 'yes' === $v;
    }

    private function debugLastPhaseFile(): ?string
    {
        if (\defined('PHP_COMPILER_DEBUG_LAST_PHASE_FILE') && is_string(PHP_COMPILER_DEBUG_LAST_PHASE_FILE) && '' !== PHP_COMPILER_DEBUG_LAST_PHASE_FILE) {
            return PHP_COMPILER_DEBUG_LAST_PHASE_FILE;
        }
        $explicit = $_SERVER['PHP_COMPILER_DEBUG_LAST_PHASE_FILE'] ?? $_ENV['PHP_COMPILER_DEBUG_LAST_PHASE_FILE'] ?? Config::getenv('PHP_COMPILER_DEBUG_LAST_PHASE_FILE');
        if (is_string($explicit) && '' !== $explicit) {
            return $explicit;
        }
        if (is_dir('build')) {
            return 'build/last_lowering_phase.json';
        }

        return null;
    }

    private function debugWriteLastPhase(string $label, ?Block $block = null, mixed $node = null): void
    {
        if (!$this->debugLastPhaseIsEnabled()) {
            return;
        }
        if ('Compiler::compileOps op' === $label) {
            ++$this->debugLastPhaseCounter;
            // Keep stderr/file noise low: sample op breadcrumbs (still frequent enough to localize crash).
            if (0 !== ($this->debugLastPhaseCounter % 200)) {
                return;
            }
        }
        $file = $this->debugLastPhaseFile();

        $funcName = null;
        if (null !== $block && null !== $block->func) {
            $funcName = $block->func->name ?? null;
            if (null !== $block->func->class && isset($block->func->class->name)) {
                $funcName = $block->func->class->name.'::'.((string) $funcName);
            }
        }

        $nodeType = null;
        if (null !== $node) {
            $nodeType = \is_object($node) ? \get_class($node) : \gettype($node);
        }

        $key = ($this->debugLastPhaseInputFile ?? '').'|'.($funcName ?? '').'|'.$label.'|'.($nodeType ?? '');
        if ($key === $this->debugLastPhaseKey) {
            return;
        }
        $this->debugLastPhaseKey = $key;

        $input = $this->debugLastPhaseInputFile;
        if (\defined('PHP_COMPILER_DEBUG_LAST_PHASE_INPUT_FILE') && is_string(PHP_COMPILER_DEBUG_LAST_PHASE_INPUT_FILE) && '' !== PHP_COMPILER_DEBUG_LAST_PHASE_INPUT_FILE) {
            $input = PHP_COMPILER_DEBUG_LAST_PHASE_INPUT_FILE;
        }
        if (
            (null === $input || '' === $input || (str_contains(str_replace('\\', '/', (string) $input), '/test/selfhost/') && str_ends_with(str_replace('\\', '/', (string) $input), '/compile_driver.php')))
            && \function_exists('getenv')
        ) {
            $fromSource = Config::getenv('PHP_COMPILER_M3_SOURCE');
            if (is_string($fromSource) && '' !== $fromSource) {
                $input = $fromSource;
            }
        }
        if (
            (null === $input || '' === $input || (str_contains(str_replace('\\', '/', (string) $input), '/test/selfhost/') && str_ends_with(str_replace('\\', '/', (string) $input), '/compile_driver.php')))
            && isset($_SERVER['argv'])
            && \is_array($_SERVER['argv'])
            && [] !== $_SERVER['argv']
        ) {
            $last = $_SERVER['argv'][\count($_SERVER['argv']) - 1] ?? null;
            if (is_string($last) && '' !== $last && str_ends_with(strtolower($last), '.php')) {
                $input = $last;
            }
        }

        $payload = [
            'ts' => \microtime(true),
            'input' => $input,
            'func' => $funcName,
            'label' => $label,
            'node' => $nodeType,
        ];

        $line = \json_encode($payload, JSON_UNESCAPED_SLASHES)."\n";
        if (null !== $file && '' !== $file) {
            @\file_put_contents($file, $line, LOCK_EX);
        }
        $stderr = (\defined('PHP_COMPILER_DEBUG_LAST_PHASE_STDERR') && PHP_COMPILER_DEBUG_LAST_PHASE_STDERR)
            ? '1'
            : ($_SERVER['PHP_COMPILER_DEBUG_LAST_PHASE_STDERR'] ?? $_ENV['PHP_COMPILER_DEBUG_LAST_PHASE_STDERR'] ?? Config::getenv('PHP_COMPILER_DEBUG_LAST_PHASE_STDERR'));
        if (false !== $stderr && null !== $stderr && '' !== $stderr && '0' !== $stderr) {
            @\fwrite(STDERR, "last_phase: {$line}");
        }
    }

    public function resetCompileAbortDetail(): void
    {
        $this->compileAbortDetail = null;
    }

    public function getCompileAbortDetail(): ?string
    {
        return $this->compileAbortDetail;
    }

    /**
     * Best-effort set of the first compile abort detail without throwing.
     * Used to surface self-host null-return failure modes (#2666).
     */
    public function setCompileAbortDetailIfEmpty(string $detail): void
    {
        if (null === $this->compileAbortDetail || '' === $this->compileAbortDetail) {
            $this->compileAbortDetail = $detail;
        }
    }

    /**
     * @param array<string, array<string, array<string, mixed>>> $registry
     */
    public function setPropertyHookRegistry(array $registry): void
    {
        $this->propertyHookRegistry = $registry;
    }

    /** @var array<string, array{display: string, readonly: bool, extends: ?string}> */
    private array $knownClassReadonly = [];

    /**
     * @param array<string, array{display: string, readonly: bool, extends: ?string}> $knownClasses
     */
    public function setKnownClassReadonly(array $knownClasses): void
    {
        $this->knownClassReadonly = $knownClasses;
    }

    /**
     * @param array<string, array<string, Variable>> $runtimeEnumCaseConsts
     */
    public function setRuntimeEnumCaseConsts(array $runtimeEnumCaseConsts): void
    {
        $this->runtimeEnumCaseConsts = $runtimeEnumCaseConsts;
    }

    /** @var ?VMContext Compile-time error context when no VM is running (#22987). */
    private ?VMContext $vmContext = null;

    /**
     * VM context for compile-time diagnostics when {@see VM::running()} is unset (#22987).
     *
     * File-level {@see Runtime::parseAndCompile} runs before {@see Runtime::run}, so
     * Zend-matching E_DEPRECATED (implicit nullable params, …) must use this context.
     */
    public function setVmContext(?VMContext $vmContext): void
    {
        $this->vmContext = $vmContext;
    }

    /** Bytes after the first __halt_compiler(); in the compiled script, if any (#3479). */
    public function getHaltCompilerRemaining(): ?string
    {
        return $this->haltCompilerRemaining;
    }

    /** Byte offset of halt trailing data; null when the unit has no __halt_compiler() (#5455). */
    public function getHaltCompilerOffset(): ?int
    {
        return $this->haltCompilerOffset;
    }

    /**
     * Recompute halt offset from user script bytes when parse input was transformed (#4378).
     *
     * MCJIT embed prepends bootstrap classes (bin/jit.php); trailing payload is unchanged.
     */
    public function reconcileHaltCompilerOffsetFromSource(string $userSource): void
    {
        if (null === $this->haltCompilerRemaining) {
            return;
        }
        $this->haltCompilerOffset = strlen($userSource) - strlen($this->haltCompilerRemaining);
    }

    /**
     * Marks the CFG construct that halted compilation before throwing LogicException (#2642).
     *
     * @return never
     */
    protected function throwCompileLogic(string $detail): void
    {
        if (null === $this->compileAbortDetail) {
            $this->compileAbortDetail = $detail;
        }

        [$sourceFile, $sourceLine] = $this->resolveCompileErrorLocation(null, null);
        if (null !== $sourceFile) {
            throw new CompileFatal($sourceFile, max(1, $sourceLine ?? 1), $detail);
        }

        throw new \LogicException($detail);
    }

    /**
     * Like {@see throwCompileLogic} but enriches abort detail with CFG file/line (#2988).
     */
    protected function throwCompileLogicForOp(Op $op, string $detail): void
    {
        if (
            str_contains($detail, 'Unknown ')
            || str_contains($detail, 'Unsupported ')
        ) {
            $detail = Lint\Issue::fromOp($op, $detail)->formatHuman();
        }

        $this->throwCompileLogic($detail);
    }

    /**
     * Like throwCompileLogic for CompileError paths (#2642).
     *
     * @return never
     */
    protected function throwCompileError(string $detail, ?string $sourceFile = null, ?int $sourceLine = null): void
    {
        if (null === $this->compileAbortDetail) {
            $this->compileAbortDetail = $detail;
        }

        [$sourceFile, $sourceLine] = $this->resolveCompileErrorLocation($sourceFile, $sourceLine);
        if (null !== $sourceFile) {
            throw new CompileFatal($sourceFile, max(1, $sourceLine ?? 1), $detail);
        }

        throw new \CompileError($detail);
    }

    /**
     * @return array{0: ?string, 1: ?int}
     */
    private function resolveCompileErrorLocation(?string $sourceFile, ?int $sourceLine): array
    {
        if (null !== $sourceFile && '' !== $sourceFile) {
            return [$sourceFile, $sourceLine];
        }
        $op = $this->compileErrorContextOp;
        if (null === $op) {
            return [null, null];
        }
        $file = $op->getFile();
        if ('' === $file) {
            return [null, null];
        }

        return [$file, $op->getLine()];
    }

    public function compile(Script $script): ?Block {
        $this->resetCompileAbortDetail();
        $this->compileErrorContextOp = null;
        $this->coalesceResultSlots = [];
        $this->coalesceMergeBlocks = [];
        $this->nullsafeResultSlots = [];
        $this->nullsafeMergeBlocks = [];
        $this->syncedCoalesceFuncCallArgSlots = [];
        $this->compileTimeEnumBackedTypes = [];
        $this->compileTimeEnumCaseConstNames = [];
        $this->compileTimeGlobalConsts = [];
        $this->haltCompilerRemaining = null;
        $this->haltCompilerOffset = null;
        $this->compiledClassStaticProperties = [];
        $this->currentClassStaticPropertyCompile = null;
        $this->neverFunctionNames = [];
        $this->userFunctionParamByRef = [];
        $this->scriptHasDnfTypedProperties = false;
        $this->classCompileRegistry = new ClassCompileRegistry();
        $this->attributeClassRegistry = new AttributeClassRegistry();
        $this->seen = new SplObjectStorage;
        $this->ternaryMergeVarSlots = new SplObjectStorage;
        $this->ternaryMergePhiRhsSlots = new SplObjectStorage;
        $this->rewrittenNeNullReturnJumpIf = new SplObjectStorage;
        $this->earlyBoundFunctionOps = new SplObjectStorage;
        $this->closureRichNameByFunc = new SplObjectStorage;
        $this->precedingInlineCallArgProducersCache = [];
        $this->cfgCallOpIndexCache = [];
        $this->cfgChildrenOpIndexBuiltCount = [];
        $this->firstSiblingInlineFuncCallProducerCache = [];
        $this->isSiblingMultiArgFuncCallProducerCache = [];
        $this->isSiblingMultiArgFuncCallProducerComputing = [];
        $this->deferredSiblingInlineCallArgConsumerIndexCache = [];
        $this->callArgIsDeadInlineTemporaryCache = [];
        $this->cfgBlockHasCoalesceStmtCache = [];
        $this->resolveCfgFuncCallNameCache = [];
        $this->deadInlineTemporaryArgCountCache = [];
        $this->callResultFeedsInlineCallArgCache = [];
        $this->cfgProducerIndexLastSyncFingerprint = -1;
        $this->debugWriteLastPhase('Compiler::compile enter');

        Compiler\InheritanceVariance::validateScript(
            $script,
            function (string $detail): void {
                $this->throwCompileError($detail);
            }
        );

        // Const-expr context checks before compileCfgBlock / PHPTypes folding (#10106, #6549, #6580).
        ThrowInClassConstCompileCheck::validate($script);
        NewWithoutParensCompileCheck::validate($script, $this->compileSourceCode);
        FunctionStaticAnonymousClassCompileCheck::validate($script);
        NonAbstractMethodBodyCheck::validate($script);

        /** @var mixed $main */
        $main = $this->compileCfgBlock($script->main->cfg, $script->main->params, $script->main);
        if (!$main instanceof Block) {
            // Self-host AOT can surface unexpected stub returns as null; capture a stable diagnostic.
            if (null === $this->compileAbortDetail) {
                $this->compileAbortDetail = 'Compiler::compile: compileCfgBlock returned non-Block';
            }
            $this->seen = null;

            return null;
        }

        $this->seen = null;

        if ($this->scriptHasDnfTypedProperties) {
            $this->appendMcjitDnfPropertyTryEpilogue($main);
        }

        NonEnumBuiltinInterfaceCompileCheck::validate($script);
        InterfaceImplementationCheck::validate($script, $this->propertyHookRegistry);
        TraitCollisionCheck::validate($script, $this->propertyHookRegistry);
        FinalClassExtensionCheck::validate($script);
        ImplementsHierarchyCompileCheck::validate($script);
        FinalMethodOverrideCheck::validate($script);
        FinalPropertyOverrideCheck::validate($script, $this->propertyHookRegistry);
        TypedPropertyInheritCheck::validate($script);
        OverrideValidator::validateScript($script);
        FinalClassConstCheck::validate($script);
        TraitClassConstConflictCheck::validate($script);
        TypedClassConstInheritCheck::validate($script);
        ClassConstVisibilityInheritCheck::validate($script);
        PropertyVisibilityInheritCheck::validate($script);
        InterfaceConstVisibilityCheck::validate($script);
        InterfaceConstAmbiguityCheck::validate($script, $this->vmContext);
        InterfaceMethodVisibilityCheck::validate($script);
        InterfaceMethodFinalCheck::validate($script);
        InterfaceMethodBodyCheck::validate($script);
        AbstractMethodBodyCheck::validate($script);
        AbstractMethodVisibilityCheck::validate($script);
        MagicMethodArityCheck::validate($script);
        MagicMethodParamTypeCheck::validate($script);
        MagicMethodReturnTypeCheck::validate($script);
        MagicMethodStaticCheck::validate($script);
        EnumMagicMethodCheck::validate($script);
        EnumBuiltinMethodRedeclareCheck::validate($script);
        EnumAbstractMethodCompileCheck::validate($script);
        EnumParentCompileCheck::validate($script);
        EnumBackedCaseCheck::validate($script);
        ClassConstDuplicateCheck::validate($script);
        ReadonlyClassCompileCheck::validate($script, $this->knownClassReadonly, $this->propertyHookRegistry);
        AsymmetricVisibilityCompileCheck::validate($script);
        VariadicPromotedPropertyCompileCheck::validate($script);
        AbstractPromotedPropertyCompileCheck::validate($script);
        GeneratorStaticMethodCompileCheck::validate($script);
        GeneratorNeverReturnCompileCheck::validate($script);

        if (null !== $this->haltCompilerOffset) {
            $main->haltCompilerOffset = $this->haltCompilerOffset;
        }


        return $main;
    }

    /** M3 emit TU: trivial single-block sources without full seen-map compile (#1937). */
    public function compileEmitSmoke(Script $script): ?Block
    {
        $this->resetCompileAbortDetail();
        $this->coalesceResultSlots = [];
        $this->coalesceMergeBlocks = [];
        $this->nullsafeResultSlots = [];
        $this->nullsafeMergeBlocks = [];
        $this->syncedCoalesceFuncCallArgSlots = [];
        $this->classCompileRegistry = new ClassCompileRegistry();
        $this->attributeClassRegistry = new AttributeClassRegistry();
        // Inventory-scale sources declare user functions and/or class-like units; emit-smoke only needs {main}
        // — same as compile() without a compile() callee in the M3 emit TU (#2633, #2666).
        if ([] !== $script->functions || $this->emitSmokeScriptHasClassLike($script)) {
            $this->seen = new SplObjectStorage;
        }
        $this->ternaryMergeVarSlots = new SplObjectStorage;
        $this->ternaryMergePhiRhsSlots = new SplObjectStorage;
        $this->rewrittenNeNullReturnJumpIf = new SplObjectStorage;
        $this->earlyBoundFunctionOps = new SplObjectStorage;
        $this->closureRichNameByFunc = new SplObjectStorage;
        $block = $this->compileCfgBlock($script->main->cfg, $script->main->params, $script->main);
        $this->seen = null;
        if (null === $block && null !== $this->compileAbortDetail && '' !== $this->compileAbortDetail) {
            echo 'Compiler::compileEmitSmoke: '.$this->compileAbortDetail."\n";
        }

        return $block;
    }

    /**
     * Emit-smoke is intended for small scripts; class-like constructs are a strong signal that
     * we should run the full compile path (self-host M5, #2666).
     */
    private function emitSmokeScriptHasClassLike(Script $script): bool
    {
        foreach ($script->main->cfg->children as $child) {
            if (
                $child instanceof Op\Stmt\Class_
                || $child instanceof Op\Stmt\Interface_
                || $child instanceof Op\Stmt\Trait_
                || $child instanceof Op\Stmt\Enum_
            ) {
                return true;
            }
        }

        return false;
    }

    public function compileFunc(string $name, CfgFunc $func): Func {
        $this->resetCompileAbortDetail();
        $this->classCompileRegistry = new ClassCompileRegistry();
        $this->attributeClassRegistry = new AttributeClassRegistry();
        $this->seen = new SplObjectStorage;

        $funcBlock = $this->compileCfgBlock($func->cfg, $func->params, $func);
        $this->seen = null;
        return new Func\PHP($name, $funcBlock);
    }

    protected function applyReturnTypeFromFunc(Block $block, CfgFunc $func): void
    {
        // php-cfg marks file-level {main} as void; only enforce on user functions/methods (#205).
        if ('{main}' === $func->name && null === $func->class) {
            return;
        }
        $returnType = $func->returnType;
        // php-cfg represents “no return type” as null or Mixed_; Zend auto-declares `: string`
        // for untyped `__toString` (zend_compile.c / #26402).
        if (null === $returnType || $returnType instanceof Op\Type\Mixed_) {
            if ($this->applyImplicitToStringStringReturn($block, $func)) {
                return;
            }
            if (null === $returnType) {
                return;
            }
            // Untyped (Mixed_) non-__toString: no scalar constraint.
            $block->returnDeclaredType = $returnType;

            return;
        }
        $this->rejectPseudoClassTypeHintOutsideClassScope($returnType, $block, $func);
        $this->rejectParentTypeHintWithoutParent($returnType);
        $this->assertIntersectionTypeMembers($returnType);
        // void before never — Zend prefers "Void can only…" when both appear in a union (#26517).
        $this->assertFunctionSignatureVoidType($returnType);
        $this->assertFunctionSignatureNeverType($returnType);
        $this->assertMixedTypeRules($returnType);
        $block->returnDeclaredType = $returnType;
        if ($returnType instanceof Op\Type\Void_) {
            $block->returnTypeVoid = true;

            return;
        }
        if ($returnType instanceof Op\Type\Never_) {
            $block->returnTypeNever = true;

            return;
        }
        if ($returnType instanceof Op\Type\Reference) {
            $refName = $this->staticNameFromOperand($returnType->declaration);
            if ('static' === strtolower((string) $refName)) {
                $block->returnTypeStatic = true;

                return;
            }
            if (null !== $refName && '' !== $refName) {
                $resolved = $this->resolveTypeHintClassName($refName, $block);
                if (null !== $resolved && '' !== $resolved) {
                    $block->returnTypeConstraint = Variable::TYPE_OBJECT;
                    $block->returnClassConstraint = $resolved;
                    // Zend TypeError prints the resolved class for self/parent (zend_execute_API.c);
                    // keep unresolved trait `parent` as the keyword (#29911, #29912).
                    $block->returnDeclaredTypeLabel = ltrim($resolved, '\\');
                }

                return;
            }
        }
        if ($this->cfgTypeUsesDnfShape($returnType)) {
            $dnfArms = DnfType::armsFromCfgType(
                $returnType,
                fn (Op\Type\Intersection $t) => $this->intersectionNamesFromCfgType($t, $block),
                fn (Op\Type\Intersection $t) => $this->intersectionDisplayFromCfgType($t, $block),
                fn (Op\Type\Reference $t) => $this->resolvedDnfReferenceNameFromCfgType($t, $block)
            );
            if (DnfType::hasConstraints($dnfArms)) {
                $block->returnDnfConstraints = $dnfArms;

                return;
            }
        }
        if ($returnType instanceof Op\Type\Literal) {
            if ('void' === $returnType->name) {
                $block->returnTypeVoid = true;

                return;
            }
            if ('never' === $returnType->name) {
                $block->returnTypeNever = true;

                return;
            }
            if ('static' === $returnType->name) {
                $block->returnTypeStatic = true;

                return;
            }
            if ('mixed' === strtolower($returnType->name)) {
                // Explicit `: mixed` is not untyped — fall-off / bare `return;` must error (#26485).
                $block->returnTypeMixed = true;
                $block->returnDeclaredTypeLabel = 'mixed';

                return;
            }
            $returnLc = strtolower($returnType->name);
            if ('true' === $returnLc || 'false' === $returnLc) {
                $block->returnTypeConstraint = Variable::TYPE_BOOLEAN;
                $block->returnLiteralBoolType = $returnLc;

                return;
            }
            // Bare `: callable` — Variable::mapFromType has no TYPE_CALLABLE mapping, so
            // without DNF arms zend_verify_return_type is skipped (#29887). Reuse the same
            // literal-arm path as ?callable / callable|… unions (DnfCheck / DnfParamCheck).
            if ('callable' === $returnLc) {
                $block->returnDnfConstraints = [['kind' => 'literal', 'name' => 'callable']];

                return;
            }
            // Bare `: iterable` — mapFromType treats it as a class name, so returns reject
            // arrays and TypeError says "iterable". Use the iterable DNF arm (array|Traversable
            // match) with Zend TypeError display Traversable|array (#29888 / #4829 sibling).
            if ('iterable' === $returnLc) {
                $block->returnDnfConstraints = [
                    ['kind' => 'literal', 'name' => 'iterable', 'display' => 'Traversable|array'],
                ];
                $block->returnDeclaredTypeLabel = 'iterable';

                return;
            }
            $declType = Type::fromDecl($returnType->name);
            $mapped = Variable::mapFromType($declType);
            if (Variable::TYPE_OBJECT === $mapped) {
                $className = '' !== (string) $declType->userType ? $declType->userType : $returnType->name;
                $block->returnTypeConstraint = Variable::TYPE_OBJECT;
                $block->returnClassConstraint = $className;
                $block->returnDeclaredTypeLabel = ltrim($className, '\\');

                return;
            }
            if (Variable::TYPE_UNDEFINED !== $mapped) {
                $block->returnTypeConstraint = $mapped;
            }
        }
    }

    /**
     * php-src: Zend/zend_compile.c — untyped `__toString` is compiled as returning string.
     * Enables zend_verify_return_type under strict_types (#26402); Reflection sees `: string`.
     *
     * @return bool true when the implicit string return was applied
     */
    private function applyImplicitToStringStringReturn(Block $block, CfgFunc $func): bool
    {
        $stringType = $this->implicitToStringReturnType($func);
        if (null === $stringType) {
            return false;
        }
        $block->returnDeclaredType = $stringType;
        $block->returnTypeConstraint = Variable::TYPE_STRING;

        return true;
    }

    /**
     * Zend auto-declares `: string` when `__toString` has no user-written return type.
     * php-cfg represents that absence as {@see Op\Type\Mixed_}.
     */
    private function implicitToStringReturnType(CfgFunc $func): ?Op\Type\Literal
    {
        if ('__tostring' !== strtolower($func->name)) {
            return null;
        }
        $returnType = $func->returnType;
        if (null !== $returnType && !($returnType instanceof Op\Type\Mixed_)) {
            return null;
        }

        return new Op\Type\Literal('string');
    }

    /**
     * php-cfg appends a null Terminal_Return after exit(); skip it for :never (issue #1358).
     */
    protected function neverFunctionHasAbnormalExitBeforeReturn(CfgBlock $block, Op\Terminal\Return_ $return): bool
    {
        foreach ($block->children as $child) {
            if ($child === $return) {
                return false;
            }
            if ($child instanceof Op\Expr\Exit_) {
                return true;
            }
        }

        return false;
    }

    /**
     * Zend allows implicit fall-off on :never (runtime TypeError); explicit `return;` is compile fatal (#4206).
     * php-cfg synthetic trailing returns have no source attributes; user `return;` carries startLine.
     */
    protected function neverFunctionReturnIsImplicitFalloff(Op\Terminal\Return_ $return): bool
    {
        $attrs = $return->getAttributes();

        return [] === $attrs || !isset($attrs['startLine']);
    }

    /**
     * Arrow `fn(): never => expr` is not Zend's explicit-`return` compile fatal — expression bodies
     * TypeError at call time with "must not implicitly return" (zend_compile.c / #30020).
     */
    protected function neverFunctionIsArrowExpressionBody(Block $block): bool
    {
        $func = $block->func;
        if (null === $func) {
            return false;
        }

        return ($func->callableOp ?? null) instanceof Op\Expr\ArrowFunction;
    }

    /**
     * @param list<Operand\BoundVariable> $closureUseVars
     */
    protected function registerClosureUseCapturesOnBlock(Block $funcBlock, array $closureUseVars): void
    {
        foreach ($closureUseVars as $useVar) {
            $name = $this->boundVariableName($useVar);
            $slot = $funcBlock->getVarSlot($useVar, false);
            $funcBlock->closureCaptureSlots[$slot] = true;
            $funcBlock->closureCaptureSlotNames[$slot] = $name;
            if ($useVar->byRef) {
                $funcBlock->closureCaptureByRef[$slot] = true;
            }
        }
    }

    /**
     * @param list<Operand\BoundVariable> $closureUseVars
     */
    protected function compileCfgBlock(
        CfgBlock $block,
        array $params = [],
        ?CfgFunc $func = null,
        array $closureUseVars = []
    ): Block {
        if (null === $this->seen) {
            $this->seen = new SplObjectStorage;
        }
        if (!$this->seen->contains($block)) {
            $savedDeferredArrayLiteralKeepSlots = $this->deferredArrayLiteralKeepSlots;
            $this->deferredArrayLiteralKeepSlots = [];
            $this->seen[$block] = $new = new Block($block);
            if ($this->compilingArrowAutoCapture) {
                $new->arrowAutoCapture = true;
            }
            if (null !== $func) {
                $new->func = $func;
                $new->strictTypes = isset($func->strictTypes) ? (bool) $func->strictTypes : false;
                $this->applyReturnTypeFromFunc($new, $func);
                $new->functionNamedCvSlots = new \ArrayObject();
            }
            if ([] !== $params) {
                $this->assertNoDuplicateParameterNames($params);
                $this->assertNoThisAsParameter($params);
                $this->assertNoDuplicateParameterAttributes($params, $func);
                $this->assertReadonlyParamOnlyInConstructor($params, $func);
                $this->assertVariadicParamIsLast($params);
            }
            $paramIdx = 0;
            foreach ($params as $param) {
                $new->addOpCode($this->compileParam($param, $new, $paramIdx++));
            }
            $this->maybeEmitOptionalBeforeRequiredParamDeprecations($params, $new);
            if (null !== $func && '__construct' === $func->name && null !== $func->class) {
                $this->compileCtorPromotionAssignments($new, $params);
            }
            if ([] !== $closureUseVars) {
                $this->registerClosureUseCapturesOnBlock($new, $closureUseVars);
            }
            // Zend early-binds top-level function decls in {main} for the whole compile unit
            // (not nested in if/try/switch/loop). php-cfg places those Stmt_Function in later
            // merge blocks after try/if, so per-block hoist alone misses call sites inside the
            // control-flow body that appear textually before the declaration (#24807).
            if (
                null !== $func
                && '{main}' === $func->name
                && null === $func->class
                && $block === $func->cfg
            ) {
                // Attribute args on early-bound FUNCDEFs fold userland consts; those consts are
                // otherwise prescanned only inside compileOps, which runs after this hoist (#26628).
                $this->prescanCompileTimeGlobalConsts($block->children, $new);
                $this->emitEarlyBoundFunctionDefs($block, $new);
            }
            $this->compileBlock($new);
            foreach ($this->deferredArrayLiteralKeepSlots as $slot => $_) {
                $new->deferredArrayLiteralKeepSlots[$slot] = true;
            }
            $this->deferredArrayLiteralKeepSlots = $savedDeferredArrayLiteralKeepSlots;
        }
        /** @var mixed $out */
        $out = $this->seen[$block] ?? null;
        if (!$out instanceof Block) {
            if (null === $this->compileAbortDetail) {
                $this->compileAbortDetail = 'Compiler::compileCfgBlock: seen map returned non-Block';
            }
            // Best effort: keep going with a fresh Block so callers can surface a meaningful abort later.
            $out = new Block($block);
            $this->seen[$block] = $out;
        }

        return $out;
    }

    /**
     * CFG branch target within the current function: inherit parent locals ($this, params).
     */
    protected function compileCfgBranch(CfgBlock $block, Block $parent): Block {
        if (!$this->seen->contains($block)) {
            $this->seen[$block] = $new = new Block($block);
            $new->inheritScopeFrom($parent);
            if (!$this->compilingSwitchJumpIfChain) {
                $this->inheritCfgVarSlotsFromSiblingCfgBranches($block, $new);
                $this->applyTernaryMergeVarSlots($block, $new);
            }
            if ($this->isErrorSuppressEndBlock($block)) {
                $this->inheritErrorSuppressExpressionSlots($parent, $new);
            }
            $this->inheritFuncFromParent($new, $parent);
            // Match/ternary branch blocks reuse unnamed temporaries (subject slot) from the parent (#4274).
            $new->inheritUndefinedLocals = true;
            if ($block instanceof ErrorSuppressBlock) {
                $new->addOpCode(new OpCode(OpCode::TYPE_BEGIN_SILENCE));
            }
            $this->compileBlock($new);
            if (!$this->compilingSwitchJumpIfChain) {
                $this->recordTernaryMergeVarSlots($block, $new);
            }
        } else {
            $child = $this->seen[$block];
            // Merge blocks already mapped on first branch; sibling inheritScopeFrom
            // adds duplicate slot indices and breaks ?: echo (#3790).
            // Try/catch end often has only one CFG parent (the catch), so the parents>=2
            // guard does not apply — re-inheriting from the catch aliases method-name /
            // "caught:" temps onto the merge echo slot and AFTER prints the wrong string
            // (#23930, #23641 AFTER regression). Skip once the merge already has opcodes.
            if (\count($block->parents) < 2 && 0 === $child->nOpCodes) {
                $child->inheritScopeFrom($parent);
                if ($this->isErrorSuppressEndBlock($block)) {
                    $this->inheritErrorSuppressExpressionSlots($parent, $child);
                }
            }
            $this->inheritFuncFromParent($child, $parent);
        }
        $child = $this->seen[$block];
        $child->parents[] = $parent;

        return $child;
    }

    /** Switch/if/loop targets need enclosing Func for JIT visibility (#210, #588). */
    private function inheritFuncFromParent(Block $child, Block $parent): void
    {
        if (null !== $parent->func) {
            $child->func = $parent->func;
            $child->strictTypes = $parent->strictTypes;
            // Merge blocks skip inheritScopeFrom when parents>=2 (#3790); still need
            // return-type flags so :never epilogue checks run on implicit fall-off (#9240).
            $child->returnTypeConstraint = $parent->returnTypeConstraint;
            $child->returnClassConstraint = $parent->returnClassConstraint;
            $child->returnDeclaredTypeLabel = $parent->returnDeclaredTypeLabel;
            $child->returnDnfConstraints = $parent->returnDnfConstraints;
            $child->returnTypeVoid = $parent->returnTypeVoid;
            $child->returnTypeNever = $parent->returnTypeNever;
            $child->returnTypeStatic = $parent->returnTypeStatic;
            $child->returnTypeMixed = $parent->returnTypeMixed;
            $child->returnDeclaredType = $parent->returnDeclaredType;
            $child->returnLiteralBoolType = $parent->returnLiteralBoolType;
        }
        // Share function-wide CV map across CFG arms (Parsedown `$text` if/elseif, #36380).
        if (null !== $parent->functionNamedCvSlots) {
            $child->functionNamedCvSlots = $parent->functionNamedCvSlots;
        } elseif (null !== $child->functionNamedCvSlots) {
            $parent->functionNamedCvSlots = $child->functionNamedCvSlots;
        } else {
            $shared = new \ArrayObject();
            $child->functionNamedCvSlots = $shared;
            $parent->functionNamedCvSlots = $shared;
        }
    }

    /**
     * ?: / if branches must assign the merge temporary in one scope slot (#3790, #137).
     */
    private function inheritCfgVarSlotsFromSiblingCfgBranches(CfgBlock $cfgBlock, Block $compiled): void
    {
        foreach ($cfgBlock->children as $child) {
            if (!$child instanceof Op\Stmt\Jump) {
                continue;
            }
            $merge = $child->target;
            if (\count($merge->parents) < 2) {
                continue;
            }
            foreach ($merge->parents as $siblingCfg) {
                if ($siblingCfg === $cfgBlock || !$this->seen->contains($siblingCfg)) {
                    continue;
                }
                $sibling = $this->seen[$siblingCfg];
                $compiled->inheritCfgVarSlotsFrom($sibling);
                // Same-name CVs (`$text` in if/elseif) must share one slot (#36380).
                $compiled->inheritNamedAssignDestsFrom($sibling);
            }
        }
    }


    protected function compileBlock(Block $block) {
        if (null !== $block->orig && $this->isErrorSuppressEndBlock($block->orig)) {
            $block->addOpCode(new OpCode(OpCode::TYPE_END_SILENCE));
        }
        $this->compileOps($block->orig->children, $block);
        // Do not auto-LEAVE at CFG block edges: file-level declare(ticks=N) and braced
        // bodies that span loops/jumps must keep the interval across successor blocks.
        // Braced scopes emit LeaveTickInterval explicitly from php-cfg (#22840, #23486).
    }

    private function emitTicksBeforeStatementIfNeeded(Op $op, Block $block, array $ops, int $index): void
    {
        if ($this->activeTickInterval <= 0) {
            return;
        }
        if ($op instanceof Op\Terminal\SetTickInterval || $op instanceof Op\Terminal\LeaveTickInterval) {
            return;
        }
        // for ($i=0; $i<n; $i++) init/increment exprs are not Zend statement boundaries (#23486, #25621).
        if ($op->hasAttribute('for_loop_increment') && $op->getAttribute('for_loop_increment')) {
            return;
        }
        if ($op->hasAttribute('for_loop_init') && $op->getAttribute('for_loop_init')) {
            return;
        }
        if (
            $op instanceof Op\Stmt\Function_
            || $op instanceof Op\Stmt\Class_
            || $op instanceof Op\Stmt\Interface_
            || $op instanceof Op\Stmt\Trait_
            || $op instanceof Op\Stmt\Enum_
            || $op instanceof Op\Stmt\Jump
            || $op instanceof Op\Stmt\JumpIf
            || $op instanceof Op\Terminal\Const_
            || $op instanceof Op\Terminal\Return_
        ) {
            return;
        }
        // php-cfg lowers `$x += 1` to BinaryOp + Assign — only the Assign is tickable (#22840).
        if ($op instanceof Op\Expr\BinaryOp) {
            return;
        }
        // echo "a$b" → ConcatList + Echo. Zend ticks at the statement start (before
        // interpolation). Tick before ConcatList; skip the following Echo (#23486).
        if ($op instanceof Op\Expr\ConcatList) {
            $next = $ops[$index + 1] ?? null;
            if ($next instanceof Op\Terminal && 'Terminal_Echo' === $next->getType()) {
                $block->addOpCode(new OpCode(OpCode::TYPE_TICKS));
            }

            return;
        }
        if ($op instanceof Op\Expr\Closure) {
            return;
        }
        // php-src places ZEND_TICKS before each ECHO opcode. `echo $a, $b` and
        // `echo "a"; echo "b"` both lower to consecutive Terminal_Echo — tick each
        // fragment. Skip only the Echo that follows ConcatList (already ticked) (#30010).
        if ($op instanceof Op\Terminal && 'Terminal_Echo' === $op->getType()) {
            $prev = $ops[$index - 1] ?? null;
            if ($prev instanceof Op\Expr\ConcatList) {
                return;
            }
        }
        // Arg evaluation feeding a following Echo (FuncCall/BinaryOp/…) is not a
        // separate Zend statement boundary — ZEND_TICKS sits on the ECHO (#30010).
        if ($this->isEchoArgEvaluationPrelude($op, $ops, $index)) {
            return;
        }
        $block->addOpCode(new OpCode(OpCode::TYPE_TICKS));
    }

    /**
     * True when $op only evaluates an argument of a following Terminal_Echo.
     *
     * Mirrors php-src: ZEND_TICKS is emitted with each ECHO, not with the arg-setup
     * ops php-cfg materializes ahead of it (`echo strtoupper("a"), "b"` / `echo foo(bar())`).
     *
     * Call-arg site clones often break Temporary identity (#8560), so this uses a
     * same-startLine window: producers sharing the echo statement's line are preludes,
     * except `foo(); echo …` where the call result is unused by the following Echo.
     *
     * @param Op[] $ops
     */
    private function isEchoArgEvaluationPrelude(Op $op, array $ops, int $index): bool
    {
        if (!($op instanceof Op\Expr) || !property_exists($op, 'result') || null === $op->result) {
            return false;
        }
        // Statement-level assign (`$a = 1; echo $a`) must tick; only `echo ($a = …)`
        // feeds the Echo expr directly.
        if (
            $op instanceof Op\Expr\Assign
            || $op instanceof Op\Expr\AssignRef
            || $op instanceof Op\Expr\AssignOp
        ) {
            $next = $ops[$index + 1] ?? null;
            if (!($next instanceof Op\Terminal) || 'Terminal_Echo' !== $next->getType()) {
                return false;
            }

            return $this->operandsChainEqual($next->expr, $op->result)
                || $this->operandsReferToSameVariable($next->expr, $op->result);
        }
        if (!$this->isInlineExprCallArgProducer($op)) {
            return false;
        }

        $line = $op->hasAttribute('startLine')
            ? (int) $op->getAttribute('startLine')
            : $op->getLine();
        if ($line <= 0) {
            return false;
        }

        $produced = $op->result;
        $sawEcho = false;
        $resultFeedsEcho = false;
        $immediate = $ops[$index + 1] ?? null;
        $n = \count($ops);
        for ($j = $index + 1; $j < $n; ++$j) {
            $next = $ops[$j];
            $nextLine = $next->hasAttribute('startLine')
                ? (int) $next->getAttribute('startLine')
                : $next->getLine();
            if ($nextLine !== $line) {
                break;
            }
            if ($next instanceof Op\Terminal && 'Terminal_Echo' === $next->getType()) {
                $sawEcho = true;
                if (
                    $this->operandsChainEqual($next->expr, $produced)
                    || $this->operandsReferToSameVariable($next->expr, $produced)
                ) {
                    $resultFeedsEcho = true;
                }
                continue;
            }
            if (
                !($next instanceof Op\Expr)
                || !property_exists($next, 'result')
                || null === $next->result
                || !$this->isInlineExprCallArgProducer($next)
            ) {
                break;
            }
        }
        if (!$sawEcho) {
            return false;
        }
        if ($resultFeedsEcho) {
            return true;
        }
        // Nested call arg: `echo foo(bar())` — bar sits before foo on the echo line.
        if (
            $immediate instanceof Op\Expr
            && $this->isInlineExprCallArgProducer($immediate)
            && !($immediate instanceof Op\Expr\Assign)
            && !($immediate instanceof Op\Expr\AssignRef)
            && !($immediate instanceof Op\Expr\AssignOp)
        ) {
            return true;
        }
        // `foo(); echo "a"` on one line — call result unused by Echo; still tickable.
        if ($immediate instanceof Op\Terminal && 'Terminal_Echo' === $immediate->getType()) {
            return false;
        }

        return true;
    }

    protected function compileOps(array $ops, Block $block): void {
        // Enum cases before global const / hoisted class bodies so E::A folds in
        // class const initializers when enum appears later in source (#15737, #5738).
        $this->prescanCompileTimeEnumCases($ops);
        // Register file-level `const` / literal define() before class bodies and
        // FUNCDEF defaults so zend_compile_default_value can fold ConstFetch (#6542).
        $this->prescanCompileTimeGlobalConsts($ops, $block);
        $this->rejectListDestructDefaultValueSlotsInOps($ops);

        // Hoist class-like definitions before functions so JIT/AOT see member
        // constants when compiling FUNCDEF bodies (issue #2215, MiniWebApp Router::CONST).
        // Interfaces before classes so same-file `class C implements I` / later `interface I`
        // resolves at DECLARE_CLASS like Zend early-binding (#25624).
        // Enums stay in source order so enum_exists() before declaration matches Zend (#5013).
        // Serializable / forbidden-implements / trait-use stay in source order for DECLARE
        // side effects (#18781, #25109, #25912). Subclasses of those classes stay in source
        // order too — hoisting them ahead leaves deferred parent inheritance pending across
        // preceding runtime opcodes, which finalize as Class "Parent" not found (#29552, #29566).
        $sourceOrderClassLcs = $this->sourceOrderClassRegistrationLcs($ops);
        foreach ($ops as $child) {
            if ($child instanceof Op\Stmt\Interface_) {
                $block->addOpCode($this->compileInterface($child, $block));
            }
        }
        foreach ($ops as $child) {
            if ($child instanceof Op\Stmt\Trait_) {
                $block->addOpCode($this->compileTrait($child, $block));
            }
        }
        foreach ($ops as $child) {
            if (!$child instanceof Op\Stmt\Class_) {
                continue;
            }
            if ($this->classIsSourceOrderRegistration($child, $sourceOrderClassLcs)) {
                continue;
            }
            $block->addOpCode($this->compileClassLike($child, $block));
        }
        foreach ($ops as $child) {
            switch (get_class($child)) {
                case Op\Stmt\Function_::class:
                    // Already emitted at {main} entry for Zend early-binding (#24807).
                    if ($this->earlyBoundFunctionOps->contains($child)) {
                        break;
                    }
                    $block->addOpCode($this->compileFunction($child, $block));
                    break;
                case Op\Terminal\Const_::class:
                    $block->addOpCode($this->compileGlobalConst($child, $block));
                    break;
            }
        }

        // php-cfg may linearize nullsafe-call arguments into eager temporaries:
        //
        //   $t = sideEffect();
        //   $c?->f($t);
        //
        // For PHP semantics, those argument temporaries must only be evaluated on the
        // non-null receiver branch (Zend `?->` short-circuit). We detect a small
        // producer slice that is used exclusively to feed a nullsafe method-call
        // argument and compile that slice into the nullsafe fetch block instead (#4394).
        $deferredNullsafePreludeOps = new SplObjectStorage();
        $deferredOpIndexes = [];
        $opCount = count($ops);
        for ($i = 0; $i < $opCount; ++$i) {
            $child = $ops[$i];
            if (!$child instanceof Op\Expr\NullsafeMethodCall) {
                continue;
            }

            $needed = [];
            foreach ($child->args as $arg) {
                if ($arg instanceof \PHPCfg\Operand\Temporary) {
                    $needed[spl_object_id($arg)] = $arg;
                }
            }
            if (empty($needed)) {
                continue;
            }

            $slice = [];
            for ($j = $i - 1; $j >= 0 && !empty($needed); --$j) {
                $candidate = $ops[$j] ?? null;
                if (!$candidate instanceof Op\Expr) {
                    break;
                }
                if ($candidate instanceof Op\Expr\Assign) {
                    break;
                }
                if (!property_exists($candidate, 'result') || !$candidate->result instanceof \PHPCfg\Operand\Temporary) {
                    break;
                }
                $resultVar = $candidate->result;
                // php-cfg parseArg() clones producer temps for call sites (#8560); match the
                // clone via shared ops, not only identical operand objects (#22660).
                $matchedArgId = null;
                if (isset($needed[spl_object_id($resultVar)])) {
                    $matchedArgId = spl_object_id($resultVar);
                } else {
                    foreach ($needed as $argId => $argTemp) {
                        if ($this->nullsafeCallArgTempFedByProducer($argTemp, $candidate)) {
                            $matchedArgId = $argId;
                            break;
                        }
                    }
                }
                if (null === $matchedArgId) {
                    continue;
                }

                $slice[] = $candidate;
                unset($needed[$matchedArgId]);
                $deferredOpIndexes[$j] = true;

                foreach ($this->nullsafePreludeOperandVars($candidate) as $dep) {
                    if ($dep instanceof \PHPCfg\Operand\Temporary) {
                        $needed[spl_object_id($dep)] = $dep;
                    }
                }
            }

            if (!empty($slice)) {
                $deferredNullsafePreludeOps[$child] = array_reverse($slice);
            } elseif ($i > 0) {
                // php-cfg may use a distinct arg temporary vs the immediately preceding
                // inline producer (IIFE FuncCall) — defer that prelude slice (#17186, #4394).
                $head = $ops[$i - 1] ?? null;
                if (
                    $head instanceof Op\Expr
                    && !$head instanceof Op\Expr\Assign
                    && property_exists($head, 'result')
                    && $this->isNullsafeMethodCallArgPreludeProducer($head)
                ) {
                    $adjacentSlice = [$head];
                    $deferredOpIndexes[$i - 1] = true;
                    $pendingDeps = [];
                    foreach ($this->nullsafePreludeOperandVars($head) as $dep) {
                        if ($dep instanceof \PHPCfg\Operand\Temporary) {
                            $pendingDeps[spl_object_id($dep)] = $dep;
                        }
                    }
                    for ($j = $i - 2; $j >= 0 && [] !== $pendingDeps; --$j) {
                        $candidate = $ops[$j] ?? null;
                        if (
                            !$candidate instanceof Op\Expr
                            || $candidate instanceof Op\Expr\Assign
                            || !property_exists($candidate, 'result')
                            || !$candidate->result instanceof \PHPCfg\Operand\Temporary
                        ) {
                            break;
                        }
                        $resultVar = $candidate->result;
                        $matchedDep = null;
                        foreach ($pendingDeps as $depId => $dep) {
                            if ($resultVar === $dep || $this->operandsReferToSameVariable($resultVar, $dep)) {
                                $matchedDep = $depId;
                                break;
                            }
                        }
                        if (null === $matchedDep) {
                            break;
                        }
                        unset($pendingDeps[$matchedDep]);
                        array_unshift($adjacentSlice, $candidate);
                        $deferredOpIndexes[$j] = true;
                        foreach ($this->nullsafePreludeOperandVars($candidate) as $dep) {
                            if ($dep instanceof \PHPCfg\Operand\Temporary) {
                                $pendingDeps[spl_object_id($dep)] = $dep;
                            }
                        }
                    }
                    if ([] !== $adjacentSlice) {
                        $deferredNullsafePreludeOps[$child] = $adjacentSlice;
                    }
                }
            }
        }
        for ($i = 0; $i < $opCount; ++$i) {
            if (isset($deferredOpIndexes[$i])) {
                continue;
            }
            $child = $ops[$i];
            $prevCompileErrorContextOp = $this->compileErrorContextOp;
            $this->compileErrorContextOp = $child;
            if ($child instanceof Op\Expr\ArrayDimFetch) {
                $this->rejectArrayEmptyOffsetRead($child, $block);
            }
            $this->debugWriteLastPhase('Compiler::compileOps op', $block, $child);
            switch (get_class($child)) {
                case Op\Stmt\Function_::class:
                case Op\Terminal\Const_::class:
                case Op\Stmt\Interface_::class:
                case Op\Stmt\Trait_::class:
                    break;
                case Op\Stmt\Class_::class:
                    if ($this->classIsSourceOrderRegistration($child, $sourceOrderClassLcs)) {
                        $block->addOpCode($this->compileClassLike($child, $block));
                    }
                    break;
                case Op\Stmt\Enum_::class:
                    $block->addOpCode($this->compileEnum($child, $block));
                    break;
                default:
                    if ($child instanceof Op\Expr\Isset_ && count($child->vars) > 1) {
                        $block = $this->compileIssetMulti($child, $block);
                    } elseif (
                        $child instanceof Op\Expr\Isset_
                        && 1 === count($child->vars)
                        && [] !== ($nullsafeChain = $this->collectNullsafePropertyFetchChain($child->vars[0], $block))
                    ) {
                        $block = $this->compileIssetNullsafePropertyFetchChain($nullsafeChain, $child, $block);
                    } elseif (
                        $child instanceof Op\Expr\Empty_
                        && [] !== ($nullsafeChain = $this->collectNullsafePropertyFetchChainForEmpty($child, $block))
                    ) {
                        $block = $this->compileEmptyNullsafePropertyFetchChain($nullsafeChain, $child, $block);
                    } elseif ($child instanceof Op\Expr\BinaryOp\Coalesce) {
                        if ($this->isCoalesceChainInnerStmt($child, $ops, $i)) {
                            break;
                        }
                        if ($this->isCoalesceLoweredByFollowingEchoConcat($ops, $i)) {
                            break;
                        }
                        // php-cfg emits Coalesce before Throw when source is `throw … ?? …`; lower once inside compileThrowExpression (#15315).
                        if ($this->isCoalesceLoweredByFollowingThrow($ops, $i)) {
                            break;
                        }
                        $resultOverride = null;
                        if (
                            $i + 1 < $opCount
                            && $ops[$i + 1] instanceof Op\Expr\Assign
                            && $this->isCoalesceAssignTail($ops[$i + 1], $child)
                        ) {
                            /** @var Op\Expr\Assign $tailAssign */
                            $tailAssign = $ops[$i + 1];
                            $resultOverride = $tailAssign->var;
                        }
                        $block = null !== $resultOverride
                            ? $this->compileCoalesceForAssign($child, $block, $resultOverride)
                            : $this->compileCoalesce($child, $block);
                        if (null !== $resultOverride) {
                            ++$i;
                        }
                    } elseif (
                        $child instanceof Op\Expr\NullsafePropertyFetch
                        && (
                            $this->shouldSkipNullsafePropertyFetchForIssetOrEmpty($child, $ops, $i, $block)
                            || $this->shouldSkipNullsafePropertyFetchForCoalesce($child, $ops, $i, $block)
                        )
                    ) {
                        // Lowered by compileIssetNullsafePropertyFetchChain / compileEmptyNullsafePropertyFetchChain (#4980)
                        // or compileCoalesce nullsafe chain eval (#13747).
                        break;
                    } elseif ($child instanceof Op\Expr\NullsafePropertyFetch) {
                        if ($this->isNullsafePropertyFetchInWriteContext($ops, $i)) {
                            $this->throwCompileError("Can't use nullsafe operator in write context");
                        }
                        // Zend: &$a?->x / &$a?->x->y — AssignRef RHS, not write-context LHS (#26638).
                        if ($this->isNullsafeOperandUsedAsAssignRefRhs($ops, $i + 1, $child->result)) {
                            $this->throwCompileError('Cannot take reference of a nullsafe chain');
                        }
                        $block = $this->compileNullsafePropertyFetch($child, $block);
                        $this->syncNullsafePropertyFetchResultToFollowingFuncCallArg($child, $block);
                    } elseif (
                        $child instanceof Op\Expr\NullsafeMethodCall
                        && $this->shouldSkipNullsafeMethodCallForCoalesce($child, $ops, $i, $block)
                    ) {
                        // Lowered inside compileCoalesce nullsafe method eval (#19591).
                        break;
                    } elseif ($child instanceof Op\Expr\NullsafeMethodCall) {
                        // Zend: &$obj?->m() — AssignRef of nullsafe method result (#26638).
                        if ($this->isNullsafeOperandUsedAsAssignRefRhs($ops, $i + 1, $child->result)) {
                            $this->throwCompileError('Cannot take reference of a nullsafe chain');
                        }
                        $block = $this->compileNullsafeMethodCall(
                            $child,
                            $block,
                            $deferredNullsafePreludeOps->contains($child) ? $deferredNullsafePreludeOps[$child] : []
                        );
                    } elseif ($this->isNullsafeChainArrayDimFetch($ops, $i)) {
                        /** @var Op\Expr\ArrayDimFetch $child */
                        $block = $this->compileNullsafeArrayDimFetch($child, $block);
                    } elseif (
                        $child instanceof Op\Expr\PropertyFetch
                        && $i + 1 < $opCount
                        && ($ops[$i + 1] instanceof Op\Expr\FuncCall || $ops[$i + 1] instanceof Op\Expr\NsFuncCall)
                        && $this->isPropertyFetchOnlyCoalesceFuncCallArg($child, $ops[$i + 1], $block)
                    ) {
                        break;
                    } elseif (
                        $child instanceof Op\Expr\PropertyFetch
                        && $this->isPropertyFetchNullsafeReceiver($child, $ops, $i)
                    ) {
                        // Lowered inside compileNullsafePropertyFetch / coalesce chain eval (#16637).
                        break;
                    } elseif (
                        $child instanceof Op\Expr\PropertyFetch
                        && null !== ($coalesceMatch = $this->findCoalesceUsingPropertyFetchLeft($child, $ops, $i))
                    ) {
                        /** @var Op\Expr\BinaryOp\Coalesce $coalesce */
                        [$coalesce, $coalesceIndex] = $coalesceMatch;
                        // Nested ??= stmts may sit between hoisted fetch and outer ?? (#33760).
                        if ($coalesceIndex !== $i + 1) {
                            break;
                        }
                        $resultOverride = null;
                        if (
                            $coalesceIndex + 1 < $opCount
                            && $ops[$coalesceIndex + 1] instanceof Op\Expr\Assign
                            && $this->isCoalesceAssignTail($ops[$coalesceIndex + 1], $coalesce)
                            && $this->operandsChainEqual($ops[$coalesceIndex + 1]->var, $child->result)
                        ) {
                            /** @var Op\Expr\Assign $tailAssign */
                            $tailAssign = $ops[$coalesceIndex + 1];
                            $resultOverride = $tailAssign->var;
                        }
                        if ($this->isCoalesceLoweredByFollowingEchoConcat($ops, $coalesceIndex)) {
                            $i = $coalesceIndex;
                            break;
                        }
                        $block = null !== $resultOverride
                            ? $this->compileCoalesceForAssign($coalesce, $block, $resultOverride)
                            : $this->compileCoalesce($coalesce, $block);
                        $i = $coalesceIndex;
                        if (null !== $resultOverride) {
                            ++$i;
                        }
                        break;
                    } elseif (
                        $child instanceof Op\Expr\StaticPropertyFetch
                        && null !== ($coalesceMatch = $this->findCoalesceUsingStaticPropertyFetchLeft($child, $ops, $i))
                    ) {
                        // php-cfg hoists StaticPropertyFetch before ?? / ??=; skip the R-mode
                        // fetch so uninitialized typed statics stay BP_VAR_IS (#31146).
                        /** @var Op\Expr\BinaryOp\Coalesce $coalesce */
                        [$coalesce, $coalesceIndex] = $coalesceMatch;
                        if ($coalesceIndex !== $i + 1) {
                            break;
                        }
                        $resultOverride = null;
                        if (
                            $coalesceIndex + 1 < $opCount
                            && $ops[$coalesceIndex + 1] instanceof Op\Expr\Assign
                            && $this->isCoalesceAssignTail($ops[$coalesceIndex + 1], $coalesce)
                            && $this->operandsChainEqual($ops[$coalesceIndex + 1]->var, $child->result)
                        ) {
                            /** @var Op\Expr\Assign $tailAssign */
                            $tailAssign = $ops[$coalesceIndex + 1];
                            $resultOverride = $tailAssign->var;
                        }
                        if ($this->isCoalesceLoweredByFollowingEchoConcat($ops, $coalesceIndex)) {
                            $i = $coalesceIndex;
                            break;
                        }
                        $block = null !== $resultOverride
                            ? $this->compileCoalesceForAssign($coalesce, $block, $resultOverride)
                            : $this->compileCoalesce($coalesce, $block);
                        $i = $coalesceIndex;
                        if (null !== $resultOverride) {
                            ++$i;
                        }
                        break;
                    } elseif (
                        $child instanceof Op\Expr\ArrayDimFetch
                        && $i + 1 < $opCount
                        && ($ops[$i + 1] instanceof Op\Expr\FuncCall || $ops[$i + 1] instanceof Op\Expr\NsFuncCall)
                        && $this->isArrayDimFetchOnlyCoalesceFuncCallArg($child, $ops[$i + 1], $block)
                    ) {
                        break;
                    } elseif (
                        $child instanceof Op\Expr\ArrayDimFetch
                        && $this->isArrayDimFetchSkippedForCoalesce($child, $ops, $i, $block)
                    ) {
                        // Nested `$a['x']['y'] ??…` / `??=` — intermediates lowered inside compileCoalesce (#28954).
                        break;
                    } elseif (
                        $child instanceof Op\Expr\ArrayDimFetch
                        && null !== ($coalesceMatch = $this->findCoalesceUsingArrayDimFetchLeft($child, $ops, $i))
                    ) {
                        /** @var Op\Expr\BinaryOp\Coalesce $coalesce */
                        [$coalesce, $coalesceIndex] = $coalesceMatch;
                        $resultOverride = null;
                        if (
                            $coalesceIndex + 1 < $opCount
                            && $ops[$coalesceIndex + 1] instanceof Op\Expr\Assign
                            && $this->isRedundantCoalesceTailAssign(
                                $ops[$coalesceIndex + 1],
                                $child,
                                $coalesce
                            )
                        ) {
                            /** @var Op\Expr\Assign $tailAssign */
                            $tailAssign = $ops[$coalesceIndex + 1];
                            $resultOverride = $tailAssign->var;
                        }
                        if ($this->isCoalesceLoweredByFollowingEchoConcat($ops, $coalesceIndex)) {
                            $i = $coalesceIndex;
                            break;
                        }
                        $block = $this->compileCoalesceForAssign($coalesce, $block, $resultOverride);
                        $i = $coalesceIndex;
                        if (null !== $resultOverride) {
                            ++$i;
                        }
                        break;
                    } elseif (
                        $child instanceof Op\Expr\ArrayDimFetch
                        && $this->isArrayDimFetchSkippedForIssetEmptyOrUnset($child, $ops, $i, $block)
                    ) {
                        // Lowered by compileIsset / Empty_ / Unset — including nested dim chains (#99, #21991).
                        break;
                    } elseif (
                        $child instanceof Op\Expr\PropertyFetch
                        && $i + 1 < $opCount
                        && $this->isPropertyFetchOnlyIssetVar($child, $ops[$i + 1])
                    ) {
                        // Lowered by compileIsset via TYPE_ISSET(container, name) (#3298).
                        break;
                    } elseif (
                        $child instanceof Op\Expr\StaticPropertyFetch
                        && $i + 1 < $opCount
                        && $this->isStaticPropertyFetchOnlyIssetVar($child, $ops[$i + 1])
                    ) {
                        // Lowered by compileIsset via TYPE_ISSET(class, name) (#15112).
                        break;
                    } elseif ($child instanceof Op\Terminal\StaticVar) {
                        [$staticOps, $nextBlock] = $this->compileFunctionStaticVar($child, $block);
                        foreach ($staticOps as $staticOp) {
                            $block->addOpCode($staticOp);
                        }
                        $block = $nextBlock;
                    } elseif (
                        $child instanceof Op\Expr\PropertyFetch
                        && $i + 1 < $opCount
                        && $this->isPropertyFetchOnlyUnsetVar($child, $ops[$i + 1])
                    ) {
                        break;
                    } elseif (
                        $child instanceof Op\Expr\PropertyFetch
                        && $i + 1 < $opCount
                        && $this->isPropertyFetchOnlyAssignVar($child, $ops[$i + 1])
                    ) {
                        // Lowered by compileExpr Assign via TYPE_PROPERTY_FETCH + TYPE_ASSIGN (#6834).
                        break;
                    } elseif (
                        $child instanceof Op\Expr\PropertyFetch
                        && $i + 1 < $opCount
                        && $this->isPropertyFetchLoweredByFollowingArrayLiteralByRefElement(
                            $child,
                            $ops[$i + 1]
                        )
                    ) {
                        // Lowered by compileArrayLiteral PROPERTY_FETCH_WRITE + ASSIGN_REF (#6426, #17353).
                        break;
                    } elseif ($this->isLoweredByFollowingCoalesce($child, $ops, $i)) {
                        break;
                    } elseif ($this->isLoweredByFollowingThrow($child, $ops, $i)) {
                        break;
                    } elseif (
                        $child instanceof Op\Expr\Throw_
                        && $this->throwResultFeedsFollowingIsset($child, $ops, $i)
                    ) {
                        // php-cfg emits Throw_ before Isset_ for isset(throw …); do not run the throw (#29086).
                        $this->throwCompileError(self::ISSET_EXPRESSION_COMPILE_ERROR);
                    } elseif ($this->isUnreachableAfterThrow($child, $ops, $i)) {
                        break;
                    } elseif ($this->isUnreachableAfterNeverCall($child, $ops, $i)) {
                        break;
                    } elseif (
                        $child instanceof Op\Expr\ClassConstFetch
                        && $this->isHoistedEnumCaseFetchOnlyForCaseClassPseudoConst($child, $ops, $i, $block)
                    ) {
                        // Lowered via following `Case::class` fold / call-arg compile-time value (#9426, #9518).
                        break;
                    } elseif ($this->isDeferredSiblingInlineCallArgProducer($child, $ops, $i)) {
                        // Hoisted sibling call-arg producers compile at the consumer via
                        // resolveSiblingInlineCallArgProducerSlot (#9463, #10981, #12421, #13788).
                        break;
                    } elseif ($this->isFuncCallLoweredByFollowingEchoConcat($child, $ops, $i)) {
                        break;
                    } elseif (
                        $child instanceof Op\Expr\ConstFetch
                        && $this->isDeferredLeadingConstFetchBeforeSiblingFuncCallConsumer($child, $ops, $i)
                    ) {
                        // explode(PATH_SEPARATOR, get_include_path()) — ConstFetch + sibling FuncCall (#15833).
                        break;
                    } elseif (
                        ($child instanceof Op\Expr\ConstFetch || $child instanceof Op\Expr\ClassConstFetch)
                        && $this->isConstFetchLoweredByFollowingEchoConcatFuncCall($ops, $i)
                    ) {
                        // echo var_export($arr['k'] ?? $d, true) . "\n" — hoisted true before deferred call (#18315).
                        break;
                    } elseif (
                        ($child instanceof Op\Expr\ConstFetch || $child instanceof Op\Expr\ClassConstFetch)
                        && $i + 1 < $opCount
                        && ($ops[$i + 1] instanceof Op\Expr\FuncCall || $ops[$i + 1] instanceof Op\Expr\NsFuncCall)
                        && $this->isDeferredHoistedConstFetchCallArgPrelude($child, $ops[$i + 1], $ops, $i)
                        && !(
                            $child instanceof Op\Expr\ConstFetch
                            && $this->isVarExportReturnFlagAfterPropertyFetchPrelude($child, $ops, $i)
                        )
                    ) {
                        // stream_supports($fp, STREAM_SUPPORT_READ) — FUNCCALL_INIT before const (#17697).
                        break;
                    } elseif ($this->isDeferredTrailingComparatorFirstClassCallable($child, $ops, $i)) {
                        // strcmp(...) trailing FCC with deferred sibling array_keys — emit at consumer (#15475).
                        break;
                    } elseif ($this->isForeachLoopVarAssignRefFusion($ops, $i)) {
                        /** @var Op\Iterator\Value $iter */
                        $iter = $ops[$i];
                        /** @var Op\Expr\AssignRef $assign */
                        $assign = $ops[$i + 1];
                        // Fusion skips AssignRef, which is where rejectThisReassignment normally fires.
                        // Zend zend_compile_foreach: foreach (... as &$this) is Cannot re-assign $this (#32205).
                        $this->rejectThisReassignment($assign->var);
                        // Fusion also skips zend_ensure_writable_variable: foreach (... as &$GLOBALS) (#32229).
                        $this->rejectGlobalsWrite($assign->var, $assign, $block);
                        $destSlot = $this->compileOperand($assign->var, $block, false);
                        $this->registerForeachByRefLoopVarBindings($block, $assign, $iter, $destSlot);
                        $block->addOpCode(new OpCode(
                            OpCode::TYPE_ITER_VALUE,
                            $destSlot,
                            $this->compileOperand($iter->var, $block, true),
                            1
                        ));
                        ++$i;
                        break;
                    } elseif ($this->isForeachListDestructRefFusion($ops, $i, $block)) {
                        /** @var Op\Iterator\Value $iter */
                        $iter = $ops[$i];
                        // Live haystack element for by-ref destructuring slots (#16213, Zend FE_FETCH_R).
                        $block->addOpCode(new OpCode(
                            OpCode::TYPE_ITER_VALUE,
                            $this->compileOperand($iter->result, $block, false),
                            $this->compileOperand($iter->var, $block, true),
                            1
                        ));
                        ++$i;
                        [$block, $i] = $this->compileListDestructGroup($ops, $i, $block);
                        break;
                    } elseif (
                        $child instanceof Op\Expr\PropertyFetch
                        && $i + 1 < $opCount
                        && $this->isPropertyFetchOnlyEmptyVar($child, $ops[$i + 1], $block)
                    ) {
                        // Lowered by compileExpr Empty_ via TYPE_EMPTY_OBJECT_PROPERTY (#4912).
                        break;
                    } elseif (
                        $child instanceof Op\Expr\StaticPropertyFetch
                        && $i + 1 < $opCount
                        && $this->isStaticPropertyFetchOnlyEmptyVar($child, $ops[$i + 1], $block)
                    ) {
                        // Lowered by compileExpr Empty_ via TYPE_EMPTY_STATIC_PROPERTY (#15112, #23983).
                        break;
                    } elseif (
                        (
                            $child instanceof Op\Expr\ArrayDimFetch
                            || $this->isListSpreadAssignOp($child)
                        )
                        && $this->isListDestructGroupStart($ops, $i, $block)
                    ) {
                        [$block, $i] = $this->compileListDestructGroup($ops, $i, $block);
                    } else {
                        if ($this->needsCfgSplitBeforeStringDimFetch($child, $block, $ops, $i)) {
                            $block = $this->splitCfgBlockAfterStringKeyedArray($block);
                        }
                        $echoBlock = $this->compileEchoWithEmbeddedCoalesce($child, $block, $ops, $i);
                        if (null !== $echoBlock) {
                            $block = $echoBlock;
                            break;
                        }
                        if (
                            ($ops[$i + 1] ?? null) instanceof Op\Stmt\JumpIf
                            && null !== ($paramOp = $this->nullableParamFromReturnTernaryArms($ops[$i + 1], $block))
                            && (
                                $child instanceof Op\Expr\BinaryOp\NotIdentical
                                || $child instanceof Op\Expr\BinaryOp\Identical
                            )
                        ) {
                            $this->emitImplicitNullableParamCoalesceReturn($paramOp, $block);
                            break;
                        }
                        if (
                            $child instanceof Op\Expr\BinaryOp\Concat
                            && $this->isConcatLoweredByFollowingEcho($child, $ops, $i)
                        ) {
                            break;
                        }
                        $savedAssignRefFlags = $this->assignRefBindRefFlags;
                        if (
                            $child instanceof Op\Expr\AssignRef
                            && $this->isForeachPropertyHookAssignRefPair($ops, $i)
                        ) {
                            $this->assignRefBindRefFlags = OpCode::ASSIGN_REF_FOREACH_PROPERTY_HOOK;
                        }
                        $this->emitTicksBeforeStatementIfNeeded($child, $block, $ops, $i);
                        $this->compileOp($child, $block);
                        $this->assignRefBindRefFlags = $savedAssignRefFlags;
                    }
            }
            $this->compileErrorContextOp = $prevCompileErrorContextOp;
        }
    }

    /**
     * String-key array writes and immediate dim fetch in one CFG block break AOT (#764, #783).
     * Keyed list destructuring (`["a" => $x] = …`) is excluded (#1234).
     *
     * @param Op[] $ops
     */
    private function needsCfgSplitBeforeStringDimFetch(Op $op, Block $block, array $ops, int $index): bool
    {
        if (!$op instanceof Op\Expr\ArrayDimFetch) {
            return false;
        }
        if (!$op->dim instanceof Literal || !is_string($op->dim->value)) {
            return false;
        }
        if ($this->isKeyedListDestructDimFetch($ops, $index, $block)) {
            return false;
        }
        // Zend materializes inline array literals before dim-fetch; splitting drops dead temps (#16462).
        if (
            null !== $op->var
            && (
                ($ops[$index - 1] ?? null) instanceof Op\Expr\Array_
                && $this->operandsReferToSameVariable($op->var, $ops[$index - 1]->result)
            )
            || null !== $this->unwrapArrayLiteralExpr($op->var)
            || null !== $this->findArrayExprForResult($op->var, $block)
        ) {
            return false;
        }
        // Same class of Temporary loss: StaticPropertyFetch emitted just before this dim
        // must stay in the same CFG block. TYPE_JUMP after INIT_ARRAY drops the fetch
        // Temporary and AOT dim-reads empty (#33936 / peer #23354).
        $prev = $ops[$index - 1] ?? null;
        if (
            $prev instanceof Op\Expr\StaticPropertyFetch
            && null !== $op->var
            && $this->operandsReferToSameVariable($op->var, $prev->result)
        ) {
            return false;
        }
        foreach ($block->opCodes as $prevOp) {
            if (OpCode::TYPE_INIT_ARRAY === $prevOp->type && null !== $prevOp->arg3) {
                return true;
            }
            if (OpCode::TYPE_INCLUDE === $prevOp->type && null !== $prevOp->arg2) {
                return true;
            }
        }

        return false;
    }


    private function splitCfgBlockAfterStringKeyedArray(Block $block): Block
    {
        $cont = new Block($block->orig);
        $cont->inheritScopeFrom($block);
        // Temporaries have no name, so findVariableInParentFrames() cannot carry them across the
        // jump; without slot inheritance every value computed before the split reads back empty
        // in the continuation (#23354).
        $cont->inheritUndefinedLocals = true;
        $this->inheritFuncFromParent($cont, $block);
        $jumpToCont = new OpCode(OpCode::TYPE_JUMP);
        $jumpToCont->block1 = $cont;
        $block->addOpCode($jumpToCont);
        $cont->parents[] = $block;

        return $cont;
    }


    /**
     * Scope slot for the local alias created by `global $name` (#3413).
     *
     * php-cfg may pass writeVariable(Literal('x')) rather than a Variable operand.
     */
    protected function compileGlobalImportSlot(Operand $var, string $globalName, Block $block): int
    {
        if ($var instanceof Operand\Variable) {
            return $block->getVarSlot($var, false);
        }
        $nameLiteral = new Operand\Literal($globalName);
        $nameLiteral->type = Type::string();
        $local = new Operand\Variable($nameLiteral);

        return $block->getVarSlot($local, false);
    }

    protected function resolveSimpleVariableName(Operand $var): string
    {
        while ($var instanceof Temporary) {
            if (null === $var->original) {
                break;
            }
            $var = $var->original;
        }
        if ($var instanceof Operand\Literal && is_string($var->value)) {
            return $var->value;
        }
        if (!$var instanceof Operand\Variable) {
            $this->throwCompileLogic('Expected a simple variable operand');
        }
        $name = $var->name;
        while ($name instanceof Temporary) {
            if (null === $name->original) {
                break;
            }
            $name = $name->original;
        }
        if ($name instanceof Operand\Literal && is_string($name->value)) {
            return $name->value;
        }

        $this->throwCompileLogic('Expected a simple variable name');
    }

    /**
     * Normal try/catch completion must run finally before merge; php-cfg jumps straight to end (#2114, #195).
     * Also rewrite nested blocks and JUMPIF→merge leave edges so AOT matches VM (#25240 / #35547).
     * Non-merge leaves (break → loop exit) still need an AOT leave trampoline; VM unwinds those.
     */
    private function rewriteMergeJumpsToFinally(Block $source, Block $merge, Block $finally): void
    {
        $seen = [];
        $this->rewriteMergeJumpsToFinallyRecursive($source, $merge, $finally, $seen);
    }

    /**
     * @param array<int, true> $seen
     */
    private function rewriteMergeJumpsToFinallyRecursive(
        Block $source,
        Block $merge,
        Block $finally,
        array &$seen
    ): void {
        $id = spl_object_id($source);
        if (isset($seen[$id]) || $source === $merge || $source === $finally) {
            return;
        }
        $seen[$id] = true;
        for ($i = 0; $i < $source->nOpCodes; ++$i) {
            $op = $source->opCodes[$i];
            if (OpCode::TYPE_JUMP === $op->type && $op->block1 === $merge) {
                $op->block1 = $finally;
            } elseif (OpCode::TYPE_JUMPIF === $op->type) {
                // continue / fallthrough leave: JumpIf arm targets merge (#25240 / #35547).
                if ($op->block1 === $merge) {
                    $op->block1 = $finally;
                }
                if ($op->block2 === $merge) {
                    $op->block2 = $finally;
                }
            }
            if (null !== $op->block1) {
                $this->rewriteMergeJumpsToFinallyRecursive($op->block1, $merge, $finally, $seen);
            }
            if (null !== $op->block2) {
                $this->rewriteMergeJumpsToFinallyRecursive($op->block2, $merge, $finally, $seen);
            }
        }
    }

    /**
     * Try/catch merge blocks from php-cfg may include later sibling try/catch in the same end
     * block. JIT pre-lowers merge at beginTry via compileIncludedAtEntry; nested TYPE_TRY in
     * that merge corrupts LLVM EH basic blocks (#4041). Split so merge is prefix-only + JUMP.
     *
     * When the nested TYPE_TRY is at index 0 (two sequential try/catch, nothing between), still
     * split into an empty merge prefix that JUMP's to the nested try — otherwise the first
     * catch falls into the second try's EH and the second catch sees the first exception (#23930).
     */
    private function splitMergeBeforeNestedTry(Block $merge): Block
    {
        $splitAt = null;
        for ($i = 0; $i < $merge->nOpCodes; ++$i) {
            $type = $merge->opCodes[$i]->type;
            if (
                OpCode::TYPE_TRY === $type
                || OpCode::TYPE_CATCH === $type
                || OpCode::TYPE_FINALLY === $type
            ) {
                $splitAt = $i;
                break;
            }
        }
        if (null === $splitAt) {
            return $merge;
        }
        $tailOps = \array_slice($merge->opCodes, $splitAt);
        $merge->opCodes = \array_slice($merge->opCodes, 0, $splitAt);
        $merge->nOpCodes = \count($merge->opCodes);
        $merge->invalidateOpcodeDerivedIndexes();
        $tail = $merge->fragmentForOpcodes($tailOps);
        $tail->orig = $merge->orig;
        $tail->inheritUndefinedLocals = $merge->inheritUndefinedLocals;
        $jump = new OpCode(OpCode::TYPE_JUMP);
        $jump->block1 = $tail;
        $merge->addOpCode($jump);

        return $merge;
    }

    /**
     * php-cfg TryCatch emits a Stmt_Jump into the try body; TYPE_TRY already enters it (#2084).
     */
    private function isRedundantTryEntryJump(Block $block, Block $target): bool
    {
        for ($i = $block->nOpCodes - 1; $i >= 0; --$i) {
            $op = $block->opCodes[$i];
            if (OpCode::TYPE_CATCH === $op->type || OpCode::TYPE_FINALLY === $op->type) {
                continue;
            }
            if (OpCode::TYPE_TRY === $op->type) {
                return $op->block1 === $target;
            }

            break;
        }

        return false;
    }

    protected function encodeCatchTypeList(array $types): string
    {
        $encoded = [];
        foreach ($types as $name) {
            // Intersection arms arrive as a single `A&B` member from php-cfg (#28205).
            if (str_contains($name, '&')) {
                $parts = [];
                foreach (explode('&', $name) as $part) {
                    $parts[] = strtolower(ltrim($part, '\\'));
                }
                $encoded[] = implode('&', $parts);
            } else {
                $encoded[] = strtolower(ltrim($name, '\\'));
            }
        }

        return implode('|', $encoded);
    }

    /**
     * php-cfg catch vars are registered on the handler block; the catch body may use
     * a distinct operand for the same name (#195, #2084, #3445).
     */
    protected function resolveCatchVarSlot(Block $compiledCatch, ?Operand $catchVar): ?int
    {
        if (null === $catchVar) {
            return null;
        }
        $slot = $compiledCatch->slotForOperand($catchVar);
        if (null !== $slot) {
            return $slot;
        }
        if (null !== $this->resolveCatchVariableName($catchVar)) {
            // Catch body may reference $e only from nested try blocks (#195, #2084).
            return $compiledCatch->getVarSlot($catchVar, false);
        }

        return null;
    }

    protected function resolveCatchVariableName(?Operand $catchVar): ?string
    {
        while ($catchVar instanceof Operand\Temporary && null !== $catchVar->original) {
            $catchVar = $catchVar->original;
        }
        if (!$catchVar instanceof Operand\Variable) {
            return null;
        }
        $nameOp = $catchVar->name;
        while ($nameOp instanceof Operand\Temporary && null !== $nameOp->original) {
            $nameOp = $nameOp->original;
        }
        if ($nameOp instanceof Literal && is_string($nameOp->value)) {
            return $nameOp->value;
        }

        return null;
    }

    private function slotForActiveCatchVariable(?Operand $operand): ?int
    {
        if ([] === $this->activeCatchVarSlotsByName || null === $operand) {
            return null;
        }
        $name = $this->resolveCatchVariableName($operand);
        if (null !== $name) {
            $slot = $this->activeCatchVarSlotsByName[strtolower($name)] ?? null;
            if (null !== $slot) {
                return $slot;
            }
        }
        $root = Block::cfgVarRoot($operand);
        if (null === $root) {
            return null;
        }
        foreach ($this->activeCatchVarRoots as $catchRoot) {
            if ($catchRoot === $root) {
                $catchName = $this->resolveCatchVariableName($catchRoot);
                if (null === $catchName) {
                    return null;
                }

                return $this->activeCatchVarSlotsByName[strtolower($catchName)] ?? null;
            }
        }

        return null;
    }

    /**
     * @param Op\Expr|Operand $expr
     *
     * @return array{0: int, 1: ?int}
     */
    protected function resolveIssetTarget($expr, Block $block): array
    {
        if ($expr instanceof Op\Expr\ArrayDimFetch) {
            return $this->resolveIssetTargetFromArrayDimFetch($expr, $block);
        }
        if ($expr instanceof Op\Expr\PropertyFetch) {
            return [
                $this->compileOperand($expr->var, $block, true),
                $this->compileOperand($expr->name, $block, true),
            ];
        }
        if ($expr instanceof Operand) {
            $fetch = $this->unwrapArrayDimFetch($expr);
            if (null !== $fetch) {
                return [
                    $this->compileOperand($fetch->var, $block, true),
                    $this->compileOperand($fetch->dim, $block, true),
                ];
            }
            foreach ($block->orig->children as $child) {
                if ($child instanceof Op\Expr\PropertyFetch && $child->result === $expr) {
                    return [
                        $this->compileOperand($child->var, $block, true),
                        $this->compileOperand($child->name, $block, true),
                    ];
                }
            }
            $canonical = $this->unwrapVariableOperand($expr);

            return [$this->compileOperand(null !== $canonical ? $canonical : $expr, $block, true), null];
        }

        $this->throwCompileLogic('Unsupported isset target: ' . (is_object($expr) ? $expr->getType() : gettype($expr)));
    }

    /**
     * Reject `$arr[]` in read context — Zend compile fatal (#12303, zend_language_parser.y).
     */
    protected function rejectArrayEmptyOffsetRead(Op\Expr\ArrayDimFetch $fetch, Block $block): void
    {
        if (!$this->isArrayAppendDim($fetch->dim)) {
            return;
        }
        if ($this->isArrayDimFetchForWrite($fetch, $block)) {
            return;
        }
        $this->throwCompileError(self::ARRAY_EMPTY_OFFSET_READ_COMPILE_ERROR);
    }

    /** True for `$arr[]` append syntax — php-cfg uses {@see NullOperand}, not PHP null (#12303). */
    protected function isArrayAppendDim(?Operand $dim): bool
    {
        // Plain null dim means php-cfg lost the index operand (comma-for `$a[$i]` in for-init, #1492).
        return $dim instanceof NullOperand;
    }

    /**
     * php-cfg Expr::result temporaries omit ->original; match list-destruct fetch by result (#3799).
     */
    protected function findArrayDimFetchForResult(Operand $result, Block $block): ?Op\Expr\ArrayDimFetch
    {
        if (null === $block->orig) {
            return null;
        }
        foreach ($block->orig->children as $child) {
            if ($child instanceof Op\Expr\ArrayDimFetch && $child->result === $result) {
                return $child;
            }
        }
        // php-cfg may allocate a distinct FuncCall arg temp whose sole writer is the dim
        // fetch (`f([1,2][0])` — arg !== ArrayDimFetch->result) (#29522).
        $writer = $result->ops[0] ?? null;
        if ($writer instanceof Op\Expr\ArrayDimFetch) {
            return $writer;
        }

        return null;
    }

    /**
     * php-cfg Expr::result temporaries omit ->original; match inline array literal RHS (#3799).
     */
    protected function findArrayExprForResult(Operand $result, Block $block): ?Op\Expr\Array_
    {
        foreach ($block->orig->children as $child) {
            if ($child instanceof Op\Expr\Array_ && $child->result === $result) {
                return $child;
            }
        }

        return null;
    }

    private function cfgExprUsesOperand(Op\Expr $expr, Operand $operand): bool
    {
        if ($expr instanceof Op\Expr\Array_) {
            foreach ($expr->values as $value) {
                if (null === $value) {
                    continue;
                }
                if ($value === $operand || $this->operandsReferToSameVariable($value, $operand)) {
                    return true;
                }
            }
            foreach ($expr->keys as $key) {
                if (null === $key) {
                    continue;
                }
                if ($key === $operand || $this->operandsReferToSameVariable($key, $operand)) {
                    return true;
                }
            }

            return false;
        }
        if ($expr instanceof Op\Expr\BinaryOp) {
            return $expr->left === $operand
                || $expr->right === $operand
                || $this->operandsReferToSameVariable($expr->left, $operand)
                || $this->operandsReferToSameVariable($expr->right, $operand);
        }
        if ($expr instanceof Op\Expr\InstanceOf_) {
            return $expr->expr === $operand
                || $this->operandsReferToSameVariable($expr->expr, $operand);
        }
        if ($expr instanceof Op\Expr\UnaryMinus || $expr instanceof Op\Expr\UnaryPlus) {
            return $expr->expr === $operand
                || $this->operandsReferToSameVariable($expr->expr, $operand);
        }
        if ($expr instanceof Op\Expr\PropertyFetch) {
            return $expr->var === $operand
                || $this->operandsReferToSameVariable($expr->var, $operand);
        }
        if ($expr instanceof Op\Expr\NullsafePropertyFetch) {
            return $expr->var === $operand
                || $this->operandsReferToSameVariable($expr->var, $operand);
        }
        if ($expr instanceof Op\Expr\NullsafeMethodCall) {
            return $expr->var === $operand
                || $this->operandsReferToSameVariable($expr->var, $operand);
        }
        if ($expr instanceof Op\Expr\StaticPropertyFetch) {
            return $expr->class === $operand
                || $this->operandsReferToSameVariable($expr->class, $operand);
        }
        if ($expr instanceof Op\Expr\ArrayDimFetch) {
            return $expr->var === $operand
                || $this->operandsReferToSameVariable($expr->var, $operand);
        }
        if ($expr instanceof Op\Expr\Cast) {
            return $expr->expr === $operand
                || $this->operandsReferToSameVariable($expr->expr, $operand);
        }
        if ($expr instanceof Op\Expr\ClassConstFetch) {
            return $expr->class === $operand
                || $this->operandsReferToSameVariable($expr->class, $operand);
        }
        // new Outer(new Inner(..., Class::CONST), …) — ClassConstFetch feeds inner New_ args (#19439).
        // php-cfg may rewrite fetch->result into a distinct Temporary on the New_ arg list; link via $arg->ops.
        if (
            $expr instanceof Op\Expr\New_
            || $expr instanceof Op\Expr\FuncCall
            || $expr instanceof Op\Expr\NsFuncCall
            || $expr instanceof Op\Expr\MethodCall
            || $expr instanceof Op\Expr\NullsafeMethodCall
            || $expr instanceof Op\Expr\StaticCall
        ) {
            if ($expr instanceof Op\Expr\MethodCall || $expr instanceof Op\Expr\NullsafeMethodCall) {
                if (
                    isset($expr->var)
                    && $expr->var instanceof Operand
                    && (
                        $expr->var === $operand
                        || $this->operandsReferToSameVariable($expr->var, $operand)
                    )
                ) {
                    return true;
                }
            }
            if (!property_exists($expr, 'args') || !\is_array($expr->args)) {
                return false;
            }
            foreach ($expr->args as $arg) {
                if (!($arg instanceof Operand)) {
                    continue;
                }
                if ($arg === $operand || $this->operandsReferToSameVariable($arg, $operand)) {
                    return true;
                }
                // Distinct dead temps: arg was written by the same ClassConstFetch/ConstFetch as $operand (#19439).
                if (
                    isset($arg->ops)
                    && \is_array($arg->ops)
                    && isset($operand->ops)
                    && \is_array($operand->ops)
                ) {
                    foreach ($arg->ops as $argWriteOp) {
                        if (
                            ($argWriteOp instanceof Op\Expr\ClassConstFetch
                                || $argWriteOp instanceof Op\Expr\ConstFetch)
                            && \in_array($argWriteOp, $operand->ops, true)
                        ) {
                            return true;
                        }
                    }
                }
                if (
                    isset($arg->ops)
                    && \is_array($arg->ops)
                    && (
                        ($operandWriter = $this->soleWriteExprForOperand($operand)) instanceof Op\Expr
                    )
                    && \in_array($operandWriter, $arg->ops, true)
                ) {
                    return true;
                }
            }

            return false;
        }

        return false;
    }


    private function inlineCallArgProducerFeedsCallArgOp(Op\Expr $producer, Op $consumer, Operand $callArg): bool
    {
        if (!property_exists($producer, 'result') || !property_exists($consumer, 'args') || !is_array($consumer->args)) {
            return false;
        }
        $producerRoot = Block::cfgVarRoot($producer->result);
        if ($callArg === $producer->result) {
            return true;
        }
        if ($this->operandsReferToSameVariable($callArg, $producer->result)) {
            return true;
        }
        if (null !== $producerRoot && Block::cfgVarRoot($callArg) === $producerRoot) {
            return true;
        }

        return false;
    }

    /**
     * @param ?Operand $argRoot from Block::cfgVarRoot($arg)
     */
    private function inlineExprCallArgUsesOperand(Op $consumer, Operand $arg, ?Operand $argRoot): bool
    {
        if (!property_exists($consumer, 'args') || !is_array($consumer->args)) {
            return false;
        }
        foreach ($consumer->args as $callArg) {
            if ($callArg === $arg) {
                return true;
            }
            if (null !== $argRoot && Block::cfgVarRoot($callArg) === $argRoot) {
                return true;
            }
        }

        return false;
    }

    protected function findPropertyFetchForResult(Operand $result, Block $block): ?Op\Expr\PropertyFetch
    {
        if (null === $block->orig) {
            return null;
        }
        foreach ($block->orig->children as $child) {
            if ($child instanceof Op\Expr\PropertyFetch && $child->result === $result) {
                return $child;
            }
        }
        // php-cfg may allocate a distinct FuncCall arg temp whose sole writer is the prop
        // fetch (`f((new stdClass)->x)` — arg !== PropertyFetch->result) (#29522).
        $writer = $result->ops[0] ?? null;
        if ($writer instanceof Op\Expr\PropertyFetch) {
            return $writer;
        }

        return null;
    }

    /**
     * Property fetch for `[&$obj->prop]` array-literal refs — operand may be the fetch expr (#17353).
     */
    private function resolvePropertyFetchForArrayLiteralRef(Operand $valueExpr, Block $block): ?Op\Expr\PropertyFetch
    {
        if ($valueExpr instanceof Op\Expr\PropertyFetch) {
            return $valueExpr;
        }
        $unwrapped = $this->unwrapOperandChain($valueExpr);
        if ($unwrapped instanceof Op\Expr\PropertyFetch) {
            return $unwrapped;
        }
        $producer = $this->findCfgProducerExprForOperand($valueExpr);
        if ($producer instanceof Op\Expr\PropertyFetch) {
            return $producer;
        }

        return $this->findPropertyFetchForResult($valueExpr, $block);
    }

    /**
     * php-cfg lowers short list `[$a, $b] = …` and `[$a, $b]` RHS via Op\Expr\Array_ (#1222).
     */
    protected function unwrapArrayLiteralExpr(Operand $operand): ?Op\Expr\Array_
    {
        while ($operand instanceof Temporary) {
            if ($operand->original instanceof Op\Expr\Array_) {
                return $operand->original;
            }
            if (null === $operand->original) {
                return null;
            }
            $operand = $operand->original;
        }
        if ($operand instanceof Op\Expr\Array_) {
            return $operand;
        }

        return null;
    }

    private function unsetTerminalUsesOperand(Op\Terminal\Unset_ $unset, Operand $operand): bool
    {
        foreach ($unset->exprs as $expr) {
            if ($expr === $operand) {
                return true;
            }
        }

        return false;
    }

    protected function unwrapArrayDimFetch(Operand $operand): ?Op\Expr\ArrayDimFetch
    {
        while ($operand instanceof Temporary) {
            if ($operand->original instanceof Op\Expr\ArrayDimFetch) {
                return $operand->original;
            }
            if (null === $operand->original) {
                return null;
            }
            $operand = $operand->original;
        }
        if ($operand instanceof Op\Expr\ArrayDimFetch) {
            return $operand;
        }

        return null;
    }

    protected function unwrapPropertyFetch(Operand $operand): ?Op\Expr\PropertyFetch
    {
        while ($operand instanceof Temporary) {
            if ($operand->original instanceof Op\Expr\PropertyFetch) {
                return $operand->original;
            }
            if (null === $operand->original) {
                return null;
            }
            $operand = $operand->original;
        }
        if ($operand instanceof Op\Expr\PropertyFetch) {
            return $operand;
        }

        return null;
    }

    protected function unwrapStaticPropertyFetch(Operand $operand): ?Op\Expr\StaticPropertyFetch
    {
        while ($operand instanceof Temporary) {
            if ($operand->original instanceof Op\Expr\StaticPropertyFetch) {
                return $operand->original;
            }
            if (null === $operand->original) {
                return null;
            }
            $operand = $operand->original;
        }
        if ($operand instanceof Op\Expr\StaticPropertyFetch) {
            return $operand;
        }

        return null;
    }

    /**
     * php-cfg may emit StaticPropertyFetch + Terminal_Unset on the fetch result temp (#2256).
     */
    protected function findStaticPropertyFetchForUnset(Operand $expr, Block $block): ?Op\Expr\StaticPropertyFetch
    {
        return $this->findStaticPropertyFetchForLvalue($expr, $block);
    }

    /**
     * php-cfg may split StaticPropertyFetch and Assign across statements (#6769).
     */
    protected function findStaticPropertyFetchForAssign(Operand $expr, Block $block): ?Op\Expr\StaticPropertyFetch
    {
        return $this->findStaticPropertyFetchForLvalue($expr, $block);
    }

    /**
     * @return Op\Expr\StaticPropertyFetch|null
     */
    protected function findStaticPropertyFetchForLvalue(Operand $expr, Block $block): ?Op\Expr\StaticPropertyFetch
    {
        $direct = $this->unwrapStaticPropertyFetch($expr);
        if (null !== $direct) {
            return $direct;
        }
        $candidates = [$expr];
        if ($expr instanceof Operand\Variable) {
            $candidates[] = $expr->name;
        }
        $target = $expr;
        while ($target instanceof Temporary) {
            $candidates[] = $target;
            if (null === $target->original) {
                break;
            }
            $target = $target->original;
        }
        foreach ($block->orig->children as $child) {
            if (!$child instanceof Op\Expr\StaticPropertyFetch) {
                continue;
            }
            foreach ($candidates as $candidate) {
                if ($child->result === $candidate) {
                    return $child;
                }
            }
        }

        return null;
    }

    protected function requireOperandSlot(?int $slot, string $context): int
    {
        if (null === $slot) {
            $this->throwCompileLogic('Missing operand slot for '.$context);
        }

        return $slot;
    }

    /**
     * Compile a class-name operand, rewriting eval-donor `self` to the enclosing FQCN (#31912).
     *
     * php-src zend_eval_string compiles with the caller's scope so php-cfg MagicStringResolver
     * would have rewritten `self` during a method compile. Eval is a separate translation unit.
     */
    protected function compileClassNameOperand(Operand $class, Block $block): int
    {
        return $this->compileOperand($this->rewriteEvalDonorClassOperand($class), $block, true);
    }

    private function rewriteEvalDonorClassOperand(Operand $class): Operand
    {
        if (null === $this->evalClassScopeDisplay || '' === $this->evalClassScopeDisplay) {
            return $class;
        }
        $name = $this->staticNameFromOperand($class);
        if (null === $name) {
            return $class;
        }
        if ('self' === strtolower($name)) {
            return new Literal($this->evalClassScopeDisplay);
        }

        return $class;
    }

    /**
     * Static property name operand (#23606, zend_compile.c / zend_object_handlers.c).
     *
     * php-cfg already distinguishes forms:
     * - `Class::$prop` → Literal name (VarLikeIdentifier) — always the property name string
     * - `Class::$$var` / `Class::${expr}` → Variable / expression — runtime name
     *
     * Do not rewrite undeclared Literals into local-variable lookups: that truncated
     * Error messages to `Class::$` and made undeclared access look like an empty name.
     */
    protected function compileStaticPropertyNameSlot(Operand $name, Operand $class, Block $block): int
    {
        return $this->compileOperand($name, $block, true);
    }

    /**
     * ?: echo merge phi must not share a slot with method-name literals (#3790, #5506).
     *
     * Clone the PHPCfg Literal before forceFreshVarSlot: AssignOp lowers two
     * PROPERTY_FETCHes that share one Literal object. SplObjectStorage would
     * relocate the first fetch's name slot, leaving try-body arg3 vacant and
     * AOT TypeErroring in getVariableFromOp (#34426, zend_vm_def.h ASSIGN_OBJ_OP).
     */
    private function freshLiteralConstantSlot(Operand $operand, Block $block): int
    {
        if (!$operand instanceof Operand\Literal) {
            return $block->forceFreshVarSlot($operand);
        }
        $fresh = new Operand\Literal($operand->value);
        $fresh->type = $operand->type;
        $mappedType = null !== $fresh->type
            ? Variable::mapFromType($fresh->type)
            : Variable::TYPE_UNDEFINED;
        if ($mappedType === Variable::TYPE_UNDEFINED) {
            if (is_int($fresh->value)) {
                $mappedType = Variable::TYPE_INTEGER;
            } elseif (is_float($fresh->value)) {
                $mappedType = Variable::TYPE_FLOAT;
            } elseif (is_string($fresh->value)) {
                $mappedType = Variable::TYPE_STRING;
            } elseif (is_bool($fresh->value)) {
                $mappedType = Variable::TYPE_BOOLEAN;
            } elseif (null === $fresh->value) {
                $mappedType = Variable::TYPE_NULL;
            }
        }
        $const = new Variable($mappedType);
        switch ($mappedType) {
            case Variable::TYPE_STRING:
                $const->string($fresh->value, true);
                break;
            case Variable::TYPE_INTEGER:
                $const->int($fresh->value);
                break;
            case Variable::TYPE_FLOAT:
                $const->float($fresh->value);
                break;
            case Variable::TYPE_BOOLEAN:
                $const->bool($fresh->value);
                break;
            case Variable::TYPE_NULL:
                break;
            default:
                $this->throwCompileLogic('Unknown Literal Operand Type: ' . ($fresh->type ?? 'untyped'));
        }
        $slot = $block->forceFreshVarSlot($fresh);
        // Same guard as {@see Block::registerConstant}: never alias a named CV (#36380).
        if (
            $block->isNamedAssignDestSlot($slot)
            || $block->isNamedVariableSlot($slot)
            || (null !== $block->functionNamedCvSlots && $this->blockFunctionNamedCvOccupies($block, $slot))
        ) {
            $slot = $block->forceFreshVarSlot($fresh, $slot);
        }
        $block->constants[$slot] = $const;

        return $slot;
    }

    /** @param \ArrayObject<string, int> $_unused */
    private function blockFunctionNamedCvOccupies(Block $block, int $slot): bool
    {
        if (null === $block->functionNamedCvSlots) {
            return false;
        }
        foreach ($block->functionNamedCvSlots as $cvSlot) {
            if ((int) $cvSlot === $slot) {
                return true;
            }
        }

        return false;
    }

    /** @var int Synthetic echo-materialize locals for {main} FuncCall→echo (#23472). */
    private static int $echoFuncCallMaterializeSeq = 0;

    /**
     * Lower `echo f()` in {main} like `$__phpcEchoN = f(); echo $__phpcEchoN` — mirrors named
     * ASSIGN so JIT materializes a stable CV instead of echoing a bare call temp (#23472).
     *
     * Always materialize in {main}: guarding on a later top-level call fixed ~74% of intermittent
     * SIGSEGV but left ~4/100 on consecutive `echo Ack()`; the last echoed call still aliases native
     * call state through teardown.
     */
    private function materializeCallResultSlotBeforeEcho(Block $block, Operand $expr, ?int $slot): ?int
    {
        if (
            null === $slot
            || 0 === $block->nOpCodes
            || !$block->isMainScript()
        ) {
            return $slot;
        }
        $last = $block->opCodes[$block->nOpCodes - 1];
        if (
            OpCode::TYPE_FUNCCALL_EXEC_RETURN !== $last->type
            || (int) $last->arg1 !== $slot
            || !$block->callResultFeedsEcho($expr)
        ) {
            return $slot;
        }
        $name = '__phpcEchoMat' . (++self::$echoFuncCallMaterializeSeq);
        $echoVar = new Operand\Variable(new Operand\Literal($name));
        $srcOp = $block->getOperand($slot);
        if (null !== $srcOp?->type) {
            $echoVar->type = $srcOp->type;
        }
        $destSlot = $block->forceFreshVarSlot($echoVar);
        $resultTemp = new Operand\Temporary();
        if (null !== $srcOp?->type) {
            $resultTemp->type = $srcOp->type;
        }
        $resultSlot = $block->forceFreshVarSlot($resultTemp, $destSlot);
        $block->registerNamedAssignDest($echoVar, $destSlot);
        $block->registerAssignResultLvalue($resultSlot, $destSlot);
        $block->addOpCode(new OpCode(OpCode::TYPE_ASSIGN, $resultSlot, $destSlot, $slot));

        return $destSlot;
    }

    /**
     * Echo must read the live CV slot after ++/-- or assign-op, not a stale literal (#23842).
     */
    private function resolveEchoEmitSlot(Operand $expr, Block $block, ?int $slot): ?int
    {
        if (null === $slot) {
            return null;
        }
        $root = Block::cfgVarRoot($expr);
        if (!$root instanceof Operand\Variable) {
            return $slot;
        }
        $name = Block::resolveVariableName($root);
        if (null === $name || '' === $name) {
            return $slot;
        }
        if (!$block->isMainScript() && !$block->hasLocallyWrittenVariableName($name)) {
            return $slot;
        }
        $live = $block->slotIndexForVariableName($name);
        if (null === $live) {
            return $slot;
        }
        $block->invalidateCompileTimeSlot($live);

        return $live;
    }

    private function attachEchoScriptGlobalName(OpCode $opcode, Operand $expr, Block $block): void
    {
        if (!$block->isMainScript()) {
            return;
        }
        $root = Block::cfgVarRoot($expr);
        if (!$root instanceof Operand\Variable) {
            return;
        }
        $name = Block::resolveVariableName($root);
        if (
            null === $name
            || '' === $name
            || \PHPCompiler\Web\Superglobals::isSuperglobalName($name)
        ) {
            return;
        }
        $opcode->echoScriptGlobalName = $name;
    }

    protected function compileOperand(?Operand $operand, Block $block, bool $isRead): ?int {
        if (null === $operand) {
            return null;
        }
        if ($isRead) {
            $catchSlot = $this->slotForActiveCatchVariable($operand);
            if (null !== $catchSlot) {
                return $catchSlot;
            }
        }
        if ($operand instanceof Operand\NullOperand) {
            return null;
        } elseif ($operand instanceof Operand\Literal) {
            $mappedType = null !== $operand->type
                ? Variable::mapFromType($operand->type)
                : Variable::TYPE_UNDEFINED;
            if ($mappedType === Variable::TYPE_UNDEFINED) {
                if (is_int($operand->value)) {
                    $mappedType = Variable::TYPE_INTEGER;
                } elseif (is_float($operand->value)) {
                    $mappedType = Variable::TYPE_FLOAT;
                } elseif (is_string($operand->value)) {
                    $mappedType = Variable::TYPE_STRING;
                } elseif (is_bool($operand->value)) {
                    $mappedType = Variable::TYPE_BOOLEAN;
                } elseif (null === $operand->value) {
                    $mappedType = Variable::TYPE_NULL;
                }
            }
            $return = new Variable($mappedType);
            switch ($mappedType) {
                case Variable::TYPE_STRING:
                    $return->string($operand->value, true);
                    break;
                case Variable::TYPE_INTEGER:
                    $return->int($operand->value);
                    break;
                case Variable::TYPE_FLOAT:
                    $return->float($operand->value);
                    break;
                case Variable::TYPE_BOOLEAN:
                    $return->bool($operand->value);
                    break;
                case Variable::TYPE_NULL:
                    break;
                default:
                    $this->throwCompileLogic('Unknown Literal Operand Type: ' . ($operand->type ?? 'untyped'));
            }
            // CFG branch blocks inherit parent constant slots; literals must not alias (#15902).
            if ($block->inheritUndefinedLocals) {
                return $this->freshLiteralConstantSlot($operand, $block);
            }

            return $block->registerConstant($operand, $return);
        } elseif ($operand instanceof Operand\Variable) {
            if ($this->isDynamicVariableOperand($operand)) {
                $slot = $block->getVarSlot($operand, $isRead);
                $nameSlot = $this->compileOperand($operand->name, $block, true);
                $block->addOpCode(new OpCode(
                    OpCode::TYPE_VAR_FETCH,
                    $slot,
                    $nameSlot
                ));

                return $slot;
            }

            return $this->finalizeOperandSlotForAccess(
                $block,
                $block->getVarSlot($operand, $isRead),
                $isRead
            );
        } elseif ($operand instanceof Operand\Temporary) {
            return $this->finalizeOperandSlotForAccess(
                $block,
                $block->getVarSlot($operand, $isRead),
                $isRead
            );
        }
        $this->throwCompileLogic("Unknown Operand Type: " . $operand->getType());
    }

    /**
     * Assign.result temps diverge from the CV after by-ref builtins; reads must use the lvalue (#12712, #12714).
     */
    private function finalizeOperandSlotForAccess(Block $block, int $slot, bool $isRead): int
    {
        if (!$isRead) {
            return $slot;
        }
        $lvalue = $this->slotForAssignLvalueFromResultSlot($block, $slot);
        if (null !== $lvalue) {
            return $lvalue;
        }

        return $slot;
    }

    private function isDynamicVariableOperand(Operand\Variable $operand): bool
    {
        return !$operand->name instanceof Operand\Literal;
    }

    /**
     * php-cfg may leave call result usages empty when the next op is `return $tmp` (#1885).
     */
    private function callNeedsReturnSlot(Operand $result, Block $block, ?Op $cfgCallOp = null): bool
    {
        if (
            !empty($result->usages)
            || $block->callResultFeedsReturn($result)
            || $block->callResultFeedsEcho($result)
            || $block->callResultFeedsErrorSuppressExit($result)
            || (null !== $block->orig && $block->orig instanceof ErrorSuppressBlock)
            || $this->isVarExportReturnTrueCall($cfgCallOp, $block)
            || 'iterator_to_array' === $this->resolveCfgFuncCallName($cfgCallOp)
        ) {
            return true;
        }

        return $this->callResultFeedsInlineCallArg($result, $block);
    }

    /** `var_export($x, true)` returns a string instead of echoing (#10704). */
    private function isVarExportReturnTrueCall(?Op $cfgCallOp, Block $block): bool
    {
        if (
            !$cfgCallOp instanceof Op\Expr\FuncCall
            && !$cfgCallOp instanceof Op\Expr\NsFuncCall
        ) {
            return false;
        }
        $name = $this->resolveCfgFuncCallName($cfgCallOp);
        if ('var_export' !== $name) {
            return false;
        }
        if (!property_exists($cfgCallOp, 'args') || !is_array($cfgCallOp->args)) {
            return false;
        }

        return $this->cfgOperandIsTrue($cfgCallOp->args[1] ?? null, $block);
    }

    /**
     * var_export($arr['k']->prop, true) — hoisted true after PropertyFetch must compile eagerly;
     * deferral to compileCallArgSends loses the return-flag slot under AOT (#31938).
     *
     * @param Op[] $ops
     */
    private function isVarExportReturnFlagAfterPropertyFetchPrelude(
        Op\Expr $fetch,
        array $ops,
        int $fetchIndex
    ): bool {
        if (!$fetch instanceof Op\Expr\ConstFetch) {
            return false;
        }
        $flagName = strtolower($this->staticNameFromOperand($fetch->name) ?? '');
        if (!\in_array($flagName, ['true', 'false'], true)) {
            return false;
        }
        $consumer = $ops[$fetchIndex + 1] ?? null;
        if (!$consumer instanceof Op\Expr\FuncCall && !$consumer instanceof Op\Expr\NsFuncCall) {
            return false;
        }
        if ('var_export' !== strtolower($this->resolveCfgFuncCallName($consumer) ?? '')) {
            return false;
        }
        $prev = $ops[$fetchIndex - 1] ?? null;

        return $prev instanceof Op\Expr\PropertyFetch
            || $prev instanceof Op\Expr\NullsafePropertyFetch
            || $prev instanceof Op\Expr\StaticPropertyFetch;
    }

    private function cfgOperandIsTrue(?Operand $operand, Block $block): bool
    {
        if ($operand instanceof Operand\Literal) {
            return true === $operand->value;
        }
        if (null === $operand || null === $block->orig) {
            return false;
        }
        foreach ($block->orig->children as $child) {
            if (!$child instanceof Op\Expr) {
                continue;
            }
            if ($child->result !== $operand) {
                continue;
            }
            if ($child instanceof Op\Expr\ConstFetch && $child->name instanceof Operand\Literal) {
                return 'true' === strtolower((string) $child->name->value);
            }
        }
        $root = $operand;
        while ($root instanceof Temporary && null !== $root->original) {
            $root = $root->original;
        }
        if ($root instanceof Operand\Literal) {
            return true === $root->value;
        }

        return false;
    }

    private function resolveCfgFuncCallName(?Op $call): ?string
    {
        if (!$call instanceof Op\Expr) {
            return null;
        }
        $cacheKey = spl_object_id($call);
        if (\array_key_exists($cacheKey, $this->resolveCfgFuncCallNameCache)) {
            return $this->resolveCfgFuncCallNameCache[$cacheKey];
        }
        $result = null;
        if ($call instanceof Op\Expr\FuncCall && $call->name instanceof Operand\Literal) {
            $result = strtolower((string) $call->name->value);
        } elseif ($call instanceof Op\Expr\NsFuncCall && $call->name instanceof Operand\Literal) {
            $result = strtolower((string) $call->name->value);
        } elseif ($call instanceof Op\Expr\MethodCall && $call->name instanceof Operand\Literal) {
            $result = strtolower((string) $call->name->value);
        }
        $this->resolveCfgFuncCallNameCache[$cacheKey] = $result;

        return $result;
    }

    /** Folded callee hint for variable calls ($fn = 'array_all'; $fn(...), #12766). */
    private function resolveInlineCallArgFuncName(?Op $call, ?string $calleeName = null): ?string
    {
        $resolved = $this->resolveCfgFuncCallName($call);
        if (null !== $resolved) {
            return $resolved;
        }
        if (null === $calleeName || '' === $calleeName) {
            return null;
        }

        return strtolower($calleeName);
    }

    /**
     * Zend handler builtins whose sole argument may be an inline Closure/ArrowFunction (#17846, #17845).
     *
     * touch(); ob_start(fn(...)) must not treat touch as a hoisted sibling arg producer.
     */
    private function builtinAcceptsSingleInlineClosureCallback(?string $funcName, int $argCount = 1): bool
    {
        if (null === $funcName || '' === $funcName || 1 !== $argCount) {
            return false;
        }

        return \in_array(strtolower($funcName), [
            'ob_start',
            'set_error_handler',
            'set_exception_handler',
            'register_shutdown_function',
            'register_tick_function',
            'unregister_tick_function',
            'header_register_callback',
            'spl_autoload_register',
        ], true);
    }

    private function cfgCallAcceptsSingleInlineClosureCallback(Op $callOp): bool
    {
        if (!$callOp instanceof Op\Expr\FuncCall && !$callOp instanceof Op\Expr\NsFuncCall) {
            return false;
        }
        if (!\is_array($callOp->args ?? null)) {
            return false;
        }

        return $this->builtinAcceptsSingleInlineClosureCallback(
            $this->resolveCfgFuncCallName($callOp),
            \count($callOp->args)
        );
    }

    /**
     * array_reduce([...], fn(...), [...]) — two+ inline Array_ producers before the call (#5626).
     */
    private function arrayReduceCfgCallHasMultipleInlineArrayProducers(Block $block, Op $cfgCallOp): bool
    {
        $cfgChildren = $this->inlineCallArgProducerCfgChildren($block);
        if ([] === $cfgChildren && null !== $block->orig) {
            $cfgChildren = $block->orig->children;
        }
        if ([] === $cfgChildren) {
            return false;
        }
        $callIndex = $this->cfgCallOpIndexInChildren($cfgChildren, $cfgCallOp);
        if (null === $callIndex) {
            return false;
        }
        $arrayCount = 0;
        for ($i = 0; $i < $callIndex; ++$i) {
            if (($cfgChildren[$i] ?? null) instanceof Op\Expr\Array_) {
                ++$arrayCount;
                if ($arrayCount >= 2) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * array_reduce([...], fn(...), [...]) — wire input Array_ chain, closure, initial Array_ (#5626).
     *
     * Skips ClassConstFetch/ConstFetch preludes (enum case elements) before the first Array_.
     *
     * @param list<Op\Expr> $producers
     */
    private function matchArrayReduceInlineArrayClosureInitialProducer(
        array $producers,
        int $argIndex,
        int $closureProducerIndex
    ): ?Op\Expr {
        $arrayCount = 0;
        foreach ($producers as $producer) {
            if ($producer instanceof Op\Expr\Array_) {
                ++$arrayCount;
            }
        }
        if ($arrayCount < 2) {
            return null;
        }
        $firstArrayPi = null;
        foreach ($producers as $pi => $producer) {
            if ($producer instanceof Op\Expr\Array_) {
                $firstArrayPi = $pi;
                break;
            }
        }
        if (null === $firstArrayPi) {
            return null;
        }
        $fromFirstArray = \array_slice($producers, $firstArrayPi);
        $leading = $this->splitLeadingNestedArrayLiteralChainWithRemainingProducers($fromFirstArray);
        if (null === $leading) {
            return null;
        }
        [$chain, $remaining] = $leading;
        if ([] === $chain) {
            return null;
        }
        $inputArray = $chain[\count($chain) - 1];
        if (!$inputArray instanceof Op\Expr\Array_) {
            return null;
        }
        $initialArray = null;
        foreach ($remaining as $producer) {
            if ($producer instanceof Op\Expr\Array_) {
                $initialArray = $producer;
            }
        }
        if (null === $initialArray || $initialArray === $inputArray) {
            return null;
        }
        if (0 === $argIndex) {
            return $inputArray;
        }
        if (1 === $argIndex) {
            return $producers[$closureProducerIndex];
        }
        if (2 === $argIndex) {
            return $initialArray;
        }

        return null;
    }

    /** Callback arg index for closure + inline Array_ hoists (array_map vs array_reduce, #10775). */
    private function inlineClosureArrayPairCallbackArgIndex(?string $funcName): int
    {
        if (null === $funcName || '' === $funcName) {
            return -1;
        }
        if (in_array($funcName, [
            'array_all',
            'array_any',
            'array_find',
            'array_find_key',
            'array_reduce',
            'array_walk',
            'array_walk_recursive',
            'array_filter',
            'iterator_apply',
        ], true)) {
            return 1;
        }
        if (in_array($funcName, [
            'array_map',
            'register_shutdown_function',
            'ob_start',
            'set_error_handler',
            'set_exception_handler',
            'register_tick_function',
            'unregister_tick_function',
            'header_register_callback',
            'spl_autoload_register',
        ], true)) {
            return 0;
        }

        return -1;
    }

    /** php-cfg dead temps: inline FuncCall/New_/Array_ producer before a call (#8561, #4633). */
    private function callResultFeedsInlineCallArg(Operand $result, Block $block): bool
    {
        $cacheKey = spl_object_id($result);
        if (\array_key_exists($cacheKey, $this->callResultFeedsInlineCallArgCache)) {
            return $this->callResultFeedsInlineCallArgCache[$cacheKey];
        }
        $answer = $this->computeCallResultFeedsInlineCallArg($result, $block);
        $this->callResultFeedsInlineCallArgCache[$cacheKey] = $answer;

        return $answer;
    }

    /**
     * Whether $result is consumed as a hoisted inline call arg (empty php-cfg usages).
     *
     * Scan only a near window after the producer: walking every later FuncCall in the
     * block made nested call stmts O(n²) via matchInlineCallArgProducer (#36387).
     */
    private function computeCallResultFeedsInlineCallArg(Operand $result, Block $block): bool
    {
        if (null === $block->orig) {
            return false;
        }
        $children = $block->orig->children;
        $startIndex = 0;
        $producer = $this->findCfgProducerExprForOperand($result);
        if ($producer instanceof Op) {
            $producerIndex = $this->cfgCallOpIndexInChildren($children, $producer, $block->orig);
            if (null !== $producerIndex) {
                $startIndex = $producerIndex + 1;
            }
        }
        $n = \count($children);
        // Multi-arg nests with ConstFetch/Array_ preludes stay within a small span; 32
        // matches deferredSiblingInlineCallArgConsumerIndex's hard cap (#36387).
        $scanEnd = null !== $producer && $startIndex > 0
            ? min($n, $startIndex + 32)
            : $n;
        for ($i = $startIndex; $i < $scanEnd; ++$i) {
            $child = $children[$i];
            if (!$this->isInlineExprCallArgConsumer($child)) {
                continue;
            }
            $producers = $this->precedingInlineCallArgProducersBeforeCfgOp($children, $child);
            foreach ($producers as $producerCand) {
                if ($producerCand->result === $result || $this->operandsReferToSameVariable($producerCand->result, $result)) {
                    if ($this->inlineCallArgProducerPassesByRefGuards($producerCand, $child, $children)) {
                        return true;
                    }
                }
            }
            // php-cfg distinct result/arg temps for multi-arg consumers (#9351).
            if (!property_exists($child, 'args') || !is_array($child->args)) {
                continue;
            }
            foreach ($child->args as $argIndex => $callArg) {
                $matched = $this->matchInlineCallArgProducer($producers, $child->args, (int) $argIndex, $child);
                if (!$matched instanceof Op\Expr) {
                    continue;
                }
                if (
                    $matched->result !== $result
                    && !$this->operandsReferToSameVariable($matched->result, $result)
                ) {
                    continue;
                }
                if ($this->inlineCallArgProducerPassesByRefGuards($matched, $child, $children)) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * @param list<Op>|null $cfgChildren
     */
    private function inlineCallArgProducerPassesByRefGuards(Op\Expr $producer, Op $consumer, ?array $cfgChildren = null): bool
    {
        if (
            !($producer instanceof Op\Expr\FuncCall || $producer instanceof Op\Expr\NsFuncCall)
            || !property_exists($consumer, 'args')
            || !is_array($consumer->args)
        ) {
            return true;
        }
        $feedsConsumerArg = false;
        foreach ($consumer->args as $consumerArg) {
            if (!$this->inlineCallArgProducerFeedsCallArgOp($producer, $consumer, $consumerArg)) {
                continue;
            }
            $feedsConsumerArg = true;
            if ($this->funcCallExprByRefArgMatchesOperand($producer, $consumerArg)) {
                return false;
            }
            if (!$this->namedCallArgMayUseFuncCallProducerResult($producer, $consumerArg)) {
                return false;
            }
        }
        if (!$feedsConsumerArg && null !== $cfgChildren) {
            $producerIndex = $this->cfgCallOpIndexInChildren($cfgChildren, $producer);
            $consumerIndex = array_search($consumer, $cfgChildren, true);
            if (is_int($producerIndex) && is_int($consumerIndex)) {
                $feedsConsumerArg = $this->isNestedCallArgProducerSeparatedByConsumerLiteralPreludes(
                    $producer,
                    $consumer,
                    $producerIndex,
                    $consumerIndex,
                    $cfgChildren
                )
                    || $this->isAdjacentNestedFuncCallProducer($producer, $consumer, $producerIndex, $consumerIndex)
                    || $this->isSiblingMultiArgFuncCallProducer(
                        $producer,
                        $consumer,
                        $producerIndex,
                        $consumerIndex,
                        $cfgChildren
                    );
            }
        }

        // Producer may feed a dead temp via position matching when operand identity
        // does not link result→arg (#11313, #11409); unrelated named locals are skipped above.
        return $feedsConsumerArg;
    }

    /**
     * `return foo()` lowers call opcodes then return; reuse FUNCCALL_EXEC_RETURN slot (#1885).
     */
    private function funcCallExecReturnSlotForReturn(Block $block, Operand $returnExpr): ?int
    {
        $n = $block->nOpCodes;
        if (0 === $n) {
            return null;
        }
        $last = $block->opCodes[$n - 1];
        if (OpCode::TYPE_FUNCCALL_EXEC_RETURN !== $last->type) {
            return null;
        }
        if (!$block->callResultFeedsReturn($returnExpr)) {
            return null;
        }

        return $last->arg1;
    }

    /**
     * php-cfg lowers `return null` to ConstFetch + Temporary; trailing include/call
     * may appear as Terminal_Return with a non-literal operand (#5367, #739).
     */
    private function voidFunctionReturnIsPhpCfgArtifact(Op\Terminal\Return_ $terminal, Block $block): bool
    {
        $expr = $terminal->expr;
        if (null === $expr) {
            return true;
        }
        if (null !== $this->funcCallExecReturnSlotForReturn($block, $expr)) {
            return true;
        }
        if ($expr instanceof Operand\Literal || $expr instanceof Operand\Variable) {
            return false;
        }
        if ($expr instanceof Operand\Temporary) {
            $producer = $this->findCfgProducerForReturnOperand($block->orig, $expr);

            return $producer instanceof Op\Expr\Include_;
        }

        return true;
    }

    private function voidFunctionReturnValueErrorMessage(?Operand $expr, Block $block): string
    {
        $base = 'A void function must not return a value';
        if (null === $expr) {
            return $base;
        }
        if ($expr instanceof Operand\Literal && $this->isNullLiteralOperand($expr)) {
            return $base.' (did you mean "return;" instead of "return null;"?)';
        }
        if (
            ($expr instanceof Operand\Temporary || $expr instanceof Operand\Variable)
            && $this->isNullConstFetchReturnTemporary($block->orig, $expr)
        ) {
            return $base.' (did you mean "return;" instead of "return null;"?)';
        }

        return $base;
    }

    private function isNullLiteralOperand(Operand\Literal $literal): bool
    {
        if (null !== $literal->type && Type::TYPE_NULL === $literal->type->type) {
            return true;
        }

        return 'null' === strtolower((string) ($literal->value ?? ''));
    }

    private function isNullConstFetchReturnTemporary(CfgBlock $cfgBlock, Operand $returnExpr): bool
    {
        $producer = $this->findCfgProducerForReturnOperand($cfgBlock, $returnExpr);
        if (!$producer instanceof Op\Expr\ConstFetch) {
            return false;
        }
        $name = $this->staticNameFromOperand($producer->name);

        return 'null' === strtolower((string) $name);
    }

    private function findCfgProducerForReturnOperand(CfgBlock $cfgBlock, Operand $returnExpr): ?Op
    {
        $returnRoot = Block::cfgVarRoot($returnExpr);
        foreach ($cfgBlock->children as $child) {
            if (!($child instanceof Op\Expr)) {
                continue;
            }
            $result = $child->result;
            if (!$result instanceof Operand) {
                continue;
            }
            if ($result === $returnExpr) {
                return $child;
            }
            if (null !== $returnRoot && Block::cfgVarRoot($result) === $returnRoot) {
                return $child;
            }
        }

        return null;
    }

    /**
     * Result slot from freshly emitted INIT_ARRAY lowering — not a stale operand map (#15848).
     */
    private function slotFromInitArrayLiteralOps(array $arrayOps): ?string
    {
        $slot = null;
        foreach ($arrayOps as $op) {
            if ($op instanceof OpCode && OpCode::TYPE_INIT_ARRAY === $op->type && null !== $op->arg1) {
                $slot = (string) $op->arg1;
            }
        }

        return $slot;
    }

    /** First INIT_ARRAY slot in $block — outer haystack for array_slice (#13684). */
    private function firstInitArraySlotInBlock(Block $block): ?string
    {
        foreach ($block->opCodes as $op) {
            if (OpCode::TYPE_INIT_ARRAY === $op->type && null !== $op->arg1) {
                return (string) $op->arg1;
            }
        }

        return null;
    }

    /**
     * Map hoisted inline producer slot to cfg child index — skips Expr_Param and other non-producers (#17259).
     *
     * @param list<Op> $cfgChildren
     */
    private function inlineCallArgProducerCfgChildIndex(
        int $callIndex,
        int $producerSlotIndex,
        int $producerCount,
        array $cfgChildren
    ): ?int {
        if ($producerSlotIndex < 0 || $producerSlotIndex >= $producerCount || $callIndex < 1) {
            return null;
        }
        $inlineIndices = [];
        for ($i = $callIndex - 1; $i >= 0; --$i) {
            $child = $cfgChildren[$i] ?? null;
            if (!$child instanceof Op\Expr || !$this->isInlineExprCallArgProducer($child)) {
                if ([] !== $inlineIndices) {
                    break;
                }
                continue;
            }
            $inlineIndices[] = $i;
            if (\count($inlineIndices) >= $producerCount) {
                break;
            }
        }
        if (\count($inlineIndices) < $producerCount) {
            $fallback = $callIndex - $producerCount + $producerSlotIndex;
            if ($fallback >= 0 && $fallback < $callIndex) {
                return $fallback;
            }

            return null;
        }
        $chronological = array_reverse(\array_slice($inlineIndices, 0, $producerCount));

        return $chronological[$producerSlotIndex] ?? null;
    }

    /**
     * Compile a php-cfg defaultBlock (param/static/property `new`/array inits) so inline
     * Array_ producers are visible to TYPE_ARG_SEND wiring (#22390, #8561, #15848).
     */
    private function compileDefaultBlockChildrenWithProducerCfg(CfgBlock $defaultBlock, Block $block): void
    {
        $savedProducerCfg = $this->rematerializeInlineProducerCfgBlock;
        $this->rematerializeInlineProducerCfgBlock = $defaultBlock;
        try {
            $this->compileOps($defaultBlock->children, $block);
        } finally {
            $this->rematerializeInlineProducerCfgBlock = $savedProducerCfg;
        }
    }

    /**
     * CFG stmt children for hoisted inline call-arg producer lookup during rematerialization (#15848).
     *
     * @return list<Op>
     */
    private function inlineCallArgProducerCfgChildren(Block $block): array
    {
        $cfg = $this->rematerializeInlineProducerCfgBlock ?? $block->orig;
        if (null === $cfg) {
            return [];
        }

        return $cfg->children;
    }

    private function findCfgBlockContainingExpr(Op $expr): ?CfgBlock
    {
        if (null === $this->seen) {
            return null;
        }
        foreach ($this->seen as $cfgBlock) {
            if (!$cfgBlock instanceof CfgBlock) {
                continue;
            }
            $seen = [];
            $found = $this->findCfgBlockContainingExprInTree($cfgBlock, $expr, $seen);
            if (null !== $found) {
                return $found;
            }
        }

        return null;
    }

    private function findCfgBlockContainingExprInTree(
        CfgBlock $cfgBlock,
        Op $expr,
        array &$seen = []
    ): ?CfgBlock {
        $id = spl_object_id($cfgBlock);
        if (isset($seen[$id])) {
            return null;
        }
        $seen[$id] = true;
        foreach ($cfgBlock->children as $child) {
            if ($child === $expr) {
                return $cfgBlock;
            }
        }
        foreach ($cfgBlock->children as $child) {
            if ($child instanceof CfgBlock) {
                $found = $this->findCfgBlockContainingExprInTree($child, $expr, $seen);
                if (null !== $found) {
                    return $found;
                }
            }
            if ($child instanceof Op\Stmt\JumpIf) {
                foreach ([$child->if ?? null, $child->else ?? null] as $branch) {
                    if ($branch instanceof CfgBlock) {
                        $found = $this->findCfgBlockContainingExprInTree($branch, $expr, $seen);
                        if (null !== $found) {
                            return $found;
                        }
                    }
                }
            }
        }

        return null;
    }

    private function findCfgProducerExprForOperand(Operand $operand): ?Op\Expr
    {
        if (null === $this->seen) {
            return null;
        }
        if ('1' !== Config::getenv('PHP_COMPILER_PRODUCER_INDEX_LEGACY')) {
            // O(1) exact lookup instead of re-walking every seen block tree per
            // call — the walk was the top lint/compile hotspot on 30k-line files
            // (#16077). Index misses (root-match producers, blocks mutated after
            // indexing) fall through to the legacy scan below, whose non-exact
            // arm still matches exact producers first.
            $this->syncCfgProducerExprIndex();
            if ($this->cfgProducerExprIndex->contains($operand)) {
                return $this->cfgProducerExprIndex[$operand];
            }
            // No exact producer indexed. The root-match arm below can only hit
            // when some indexed expr shares the operand's cfg var root; when no
            // such root exists anywhere (the overwhelmingly common case), the
            // legacy scan is a guaranteed null — skip it.
            $missRoot = Block::cfgVarRoot($operand);
            if (null === $missRoot
                || !isset($this->cfgProducerRootsWithCandidates[spl_object_id($missRoot)])) {
                return null;
            }
        }
        $returnRoot = Block::cfgVarRoot($operand);
        $rootMatch = null;
        foreach ($this->seen as $cfgBlock) {
            if (!$cfgBlock instanceof CfgBlock) {
                continue;
            }
            $seen = [];
            $producer = $this->findCfgProducerInBlockTree($cfgBlock, $operand, $returnRoot, $seen, true);
            if (null !== $producer) {
                return $producer;
            }
            $seen = [];
            $candidate = $this->findCfgProducerInBlockTree($cfgBlock, $operand, $returnRoot, $seen, false);
            if (null !== $candidate) {
                $rootMatch = $candidate;
            }
        }

        return $rootMatch;
    }

    /**
     * Bring the producer index up to date with $this->seen: index newly seen
     * blocks, re-index blocks whose child list grew or shrank since indexing.
     */
    private function cfgProducerIndexFingerprint(): int
    {
        if (null === $this->seen) {
            return 0;
        }
        $fp = $this->seen->count();
        foreach ($this->seen as $cfgBlock) {
            if ($cfgBlock instanceof CfgBlock) {
                $fp = (int) (($fp * 31) + \count($cfgBlock->children));
            }
        }

        return $fp;
    }

    private function syncCfgProducerExprIndex(): void
    {
        if ($this->cfgProducerIndexSeenSource !== $this->seen || null === $this->cfgProducerExprIndex) {
            $this->cfgProducerExprIndex = new SplObjectStorage();
            $this->cfgProducerIndexedBlocks = new SplObjectStorage();
            $this->cfgProducerRootsWithCandidates = [];
            $this->cfgProducerIndexSeenSource = $this->seen;
            $this->cfgProducerIndexLastSyncFingerprint = -1;
        }
        $fp = $this->cfgProducerIndexFingerprint();
        if ($fp === $this->cfgProducerIndexLastSyncFingerprint) {
            return;
        }
        $this->cfgProducerIndexLastSyncFingerprint = $fp;
        foreach ($this->seen as $cfgBlock) {
            if ($cfgBlock instanceof CfgBlock) {
                $this->indexCfgProducerBlockTree($cfgBlock);
            }
        }
    }

    private function indexCfgProducerBlockTree(CfgBlock $cfgBlock): void
    {
        $childCount = \count($cfgBlock->children);
        $prevCount = $this->cfgProducerIndexedBlocks->contains($cfgBlock)
            ? $this->cfgProducerIndexedBlocks[$cfgBlock]
            : null;
        if ($prevCount === $childCount) {
            return;
        }
        if (null !== $prevCount && $prevCount > $childCount) {
            // Block shrunk — rebuild this subtree in the index (#36224).
            $this->reindexCfgProducerBlockTreeFromScratch($cfgBlock);

            return;
        }
        $startIndex = null !== $prevCount ? $prevCount : 0;
        $this->cfgProducerIndexedBlocks[$cfgBlock] = $childCount;
        for ($i = $startIndex; $i < $childCount; ++$i) {
            $this->indexCfgProducerTreeNode($cfgBlock->children[$i]);
        }
    }

    /**
     * @param Op|CfgBlock $node
     */
    private function indexCfgProducerTreeNode($node): void
    {
        if ($node instanceof Op\Expr) {
            $result = $node->result;
            if ($result instanceof Operand) {
                if (!$this->cfgProducerExprIndex->contains($result)) {
                    $this->cfgProducerExprIndex[$result] = $node;
                }
                $resultRoot = Block::cfgVarRoot($result);
                if (null !== $resultRoot) {
                    $this->cfgProducerRootsWithCandidates[spl_object_id($resultRoot)] = true;
                }
            }

            return;
        }
        if ($node instanceof CfgBlock) {
            $this->indexCfgProducerBlockTree($node);

            return;
        }
        if ($node instanceof Op\Stmt\JumpIf) {
            foreach ([$node->if ?? null, $node->else ?? null] as $branch) {
                if ($branch instanceof CfgBlock) {
                    $this->indexCfgProducerBlockTree($branch);
                }
            }
        }
    }

    private function reindexCfgProducerBlockTreeFromScratch(CfgBlock $cfgBlock): void
    {
        $this->cfgProducerIndexedBlocks[$cfgBlock] = \count($cfgBlock->children);
        foreach ($cfgBlock->children as $child) {
            $this->indexCfgProducerTreeNode($child);
        }
    }

    private function findCfgProducerInBlockTree(
        CfgBlock $cfgBlock,
        Operand $operand,
        ?Operand $returnRoot,
        array &$seen = [],
        bool $exactOnly = false
    ): ?Op\Expr {
        $id = spl_object_id($cfgBlock);
        if (isset($seen[$id])) {
            return null;
        }
        $seen[$id] = true;
        foreach ($cfgBlock->children as $child) {
            if ($child instanceof Op\Expr) {
                $result = $child->result;
                if (!$result instanceof Operand) {
                    continue;
                }
                if ($result === $operand) {
                    return $child;
                }
                if (!$exactOnly && null !== $returnRoot && Block::cfgVarRoot($result) === $returnRoot) {
                    return $child;
                }
            }
            if ($child instanceof CfgBlock) {
                $found = $this->findCfgProducerInBlockTree($child, $operand, $returnRoot, $seen, $exactOnly);
                if (null !== $found) {
                    return $found;
                }
            }
            if ($child instanceof Op\Stmt\JumpIf) {
                foreach ([$child->if ?? null, $child->else ?? null] as $branch) {
                    if ($branch instanceof CfgBlock) {
                        $found = $this->findCfgProducerInBlockTree($branch, $operand, $returnRoot, $seen, $exactOnly);
                        if (null !== $found) {
                            return $found;
                        }
                    }
                }
            }
        }

        return null;
    }

    private function cfgBlockContainsOp(?CfgBlock $cfgBlock, Op $needle): bool
    {
        if (null === $cfgBlock) {
            return false;
        }
        foreach ($cfgBlock->children as $child) {
            if ($child === $needle) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return list<OpCode>
     */
    private function rematerializeCfgProducerExprOps(Op\Expr $producer, Block $block): array
    {
        if ($this->cfgBlockContainsOp($block->orig, $producer)) {
            return [];
        }
        if ($producer instanceof Op\Expr\FuncCall || $producer instanceof Op\Expr\NsFuncCall) {
            $savedProducerCfg = $this->rematerializeInlineProducerCfgBlock;
            $this->rematerializeInlineProducerCfgBlock = $this->findCfgBlockContainingExpr($producer);
            try {
                return $this->compileFuncCall(
                    $this->compileOperand($producer->name, $block, true),
                    $producer->args ?? [],
                    $producer->result,
                    $block,
                    max(0, $producer->getLine()),
                    $producer
                );
            } finally {
                $this->rematerializeInlineProducerCfgBlock = $savedProducerCfg;
            }
        }
        if ($producer instanceof Op\Expr\BinaryOp) {
            $ops = [];
            foreach (['left', 'right'] as $side) {
                $operand = $producer->$side;
                if (!$operand instanceof Operand) {
                    continue;
                }
                $nested = $this->findCfgProducerExprForOperand($operand);
                if ($nested instanceof Op\Expr) {
                    $ops = array_merge($ops, $this->rematerializeCfgProducerExprOps($nested, $block));
                }
            }

            return array_merge($ops, $this->compileExpr($producer, $block));
        }
        if ($producer instanceof Op\Expr\Cast) {
            $nested = $this->findCfgProducerExprForOperand($producer->expr);
            $ops = [];
            if ($nested instanceof Op\Expr) {
                $ops = $this->rematerializeCfgProducerExprOps($nested, $block);
            }

            return array_merge($ops, $this->compileExpr($producer, $block));
        }

        return $this->compileExpr($producer, $block);
    }

    /**
     * php-cfg evaluates inline array elements before ternary JUMPIFs; rematerialize at INIT_ARRAY (#14134).
     *
     * @return array{0: list<OpCode>, 1: int}
     */
    private function compileDeferredArrayLiteralElementValue(
        Operand $valueOperand,
        Block $block,
        Op\Expr\Array_ $arrayExpr,
        int $elementIndex,
        bool $forRefBinding = false,
    ): array {
        $prefetchOps = $this->compileRuntimeEnumCaseFetchOpsForArrayElement(
            $valueOperand,
            $block,
            $arrayExpr,
            $elementIndex
        );
        if ([] !== $prefetchOps) {
            return [$prefetchOps, $prefetchOps[0]->arg1];
        }

        $folded = $this->tryFoldArrayElementCompileTimeValue($valueOperand, $block, $arrayExpr, $elementIndex);
        if (null !== $folded) {
            return [[], $folded];
        }

        $valueOperand = $arrayExpr->values[$elementIndex] ?? $valueOperand;
        $producer = $this->findCfgProducerExprForOperand($valueOperand);
        if ($producer instanceof Op\Expr) {
            $ops = $this->rematerializeCfgProducerExprOps($producer, $block);
            if ([] !== $ops) {
                $valueSlot = $this->compileArrayLiteralElementExpressionSlot(
                    $valueOperand,
                    $block,
                    $forRefBinding
                );
                $snapshotOperand = new Operand\Temporary();
                $snapshotSlot = $block->getVarSlot($snapshotOperand, false);
                $ops[] = new OpCode(OpCode::TYPE_ASSIGN, $snapshotSlot, $snapshotSlot, $valueSlot);

                return [$ops, $snapshotSlot];
            }
        }

        return [[], $this->compileArrayLiteralElementExpressionSlot(
            $valueOperand,
            $block,
            $forRefBinding
        )];
    }

    /**
     * Resolve a non-ref array-literal element to its expression-result slot.
     *
     * Zend evaluates elements left-to-right and packs the expression value. Do not rewrite
     * assign.result → lvalue via {@see finalizeOperandSlotForAccess()}: dim/property write
     * slots stay live aliases, so later elements (e.g. array_shift) would mutate the packed
     * value (#23979). By-ref elements still need the live lvalue.
     */
    private function compileArrayLiteralElementExpressionSlot(
        Operand $valueOperand,
        Block $block,
        bool $forRefBinding = false,
    ): int {
        if ($forRefBinding) {
            return (int) $this->compileOperand($valueOperand, $block, false);
        }
        if ($valueOperand instanceof Operand\Temporary || $valueOperand instanceof Operand\Variable) {
            $catchSlot = $this->slotForActiveCatchVariable($valueOperand);
            if (null !== $catchSlot) {
                return $catchSlot;
            }

            return $block->getVarSlot($valueOperand, true);
        }

        return (int) $this->compileOperand($valueOperand, $block, true);
    }

    /**
     * @return list<OpCode>
     */
    protected function compileTerminal(Op\Terminal $terminal, Block $block): array {
        switch ($terminal->getType()) {
            case 'Terminal_Echo':
                $concat = $this->unwrapConcatListExpr($terminal->expr)
                    ?? $this->flattenBinaryConcatToConcatList($terminal->expr);
                if (null !== $concat) {
                    $concat = $this->materializeConcatListCoalesceParts($concat, $block);
                    $this->compileOp($concat, $block);
                    $var = $this->compileOperand($concat->result, $block, true);
                } else {
                    $this->compileEmbeddedExprForOperand($terminal->expr, $block);
                    $var = $this->compileOperand($terminal->expr, $block, true);
                    $var = $this->materializeCallResultSlotBeforeEcho($block, $terminal->expr, $var);
                    $var = $this->resolveEchoEmitSlot($terminal->expr, $block, $var);
                }

                $line = $terminal->getLine();

                $echoOpcode = new OpCode(
                    OpCode::TYPE_ECHO,
                    $var,
                    $line > 0 ? $line : null
                );
                $this->attachEchoScriptGlobalName($echoOpcode, $terminal->expr, $block);

                return [$echoOpcode];
            case 'Terminal_Return':
                $returnLine = $terminal->getLine();
                $returnLineArg = $returnLine > 0 ? $returnLine : null;
                if ($block->returnTypeNever) {
                    $neverFile = $terminal->getFile() ?: 'unknown';
                    $neverLine = $returnLine > 0 ? $returnLine : 1;
                    if ($this->neverFunctionHasAbnormalExitBeforeReturn($block->orig, $terminal)) {
                        return [];
                    }
                    // Arrow expression body → runtime TypeError, not compile Fatal (#30020).
                    if ($this->neverFunctionIsArrowExpressionBody($block)) {
                        return [new OpCode(
                            OpCode::TYPE_RETURN_VOID,
                            $returnLineArg
                        )];
                    }
                    if (!is_null($terminal->expr)) {
                        $this->throwCompileError('A never-returning function must not return', $neverFile, $neverLine);
                    }
                    if ($this->neverFunctionReturnIsImplicitFalloff($terminal)) {
                        return [new OpCode(
                            OpCode::TYPE_RETURN_VOID,
                            $returnLineArg
                        )];
                    }
                    $this->throwCompileError('A never-returning function must not return', $neverFile, $neverLine);
                }
                if (is_null($terminal->expr)) {
                    return [new OpCode(
                        OpCode::TYPE_RETURN_VOID,
                        $returnLineArg
                    )];
                }
                if ($block->returnTypeVoid) {
                    if ($this->voidFunctionReturnIsPhpCfgArtifact($terminal, $block)) {
                        return [new OpCode(
                            OpCode::TYPE_RETURN_VOID,
                            $returnLineArg
                        )];
                    }
                    $this->throwCompileError(
                        $this->voidFunctionReturnValueErrorMessage($terminal->expr, $block)
                    );
                }

                $callResultSlot = $this->funcCallExecReturnSlotForReturn($block, $terminal->expr);
                if (null !== $callResultSlot) {
                    return [new OpCode(OpCode::TYPE_RETURN, $callResultSlot, $returnLineArg)];
                }

                $returnExpr = $terminal->expr;
                while ($returnExpr instanceof Temporary && null !== $returnExpr->original) {
                    $returnExpr = $returnExpr->original;
                }
                if (
                    $returnExpr instanceof CfgVariable
                    && $this->funcReturnTypeIsNullableScalar($block)
                    && $this->operandIsImplicitNullableParam($returnExpr, $block)
                ) {
                    $this->emitImplicitNullableParamCoalesceReturn($returnExpr, $block);

                    return [];
                }

                return [new OpCode(
                    OpCode::TYPE_RETURN,
                    $this->compileOperand($terminal->expr, $block, true),
                    $returnLineArg
                )];
            case 'Iterator_Reset':
                // Stamp foreach site so FE_RESET E_WARNING cites the foreach line (#27953).
                $iterReset = new OpCode(
                    OpCode::TYPE_ITER_RESET,
                    $this->compileOperand($terminal->var, $block, true)
                );
                $this->assignSourceMetadata($iterReset, $terminal);

                return [$iterReset];
            case 'Terminal_Throw':
                if ($this->isBareRethrowThrow($terminal, $block)) {
                    return [new OpCode(OpCode::TYPE_RETHROW)];
                }

                $line = $terminal->getLine();

                return [new OpCode(
                    OpCode::TYPE_THROW,
                    $this->compileOperand($terminal->expr, $block, true),
                    $line > 0 ? $line : null
                )];
            case 'Terminal_Unset':
                $ops = [];
                foreach ($terminal->exprs as $unsetExpr) {
                    $this->rejectThisUnset($unsetExpr);
                    if ($unsetExpr instanceof Operand) {
                        $this->rejectGlobalsWrite($unsetExpr, $terminal, $block);
                        $this->rejectNewExprInWriteContext($unsetExpr, $block, null, null, $terminal);
                        $this->rejectArrayLiteralInWriteContext($unsetExpr, $block, $terminal);
                        $this->rejectGlobalConstInWriteContext($unsetExpr, $block, $terminal);
                        $this->rejectCallReturnInWriteContext($unsetExpr, $block, $terminal);
                    }
                    $staticPropertyFetch = $unsetExpr instanceof Op\Expr\StaticPropertyFetch
                        ? $unsetExpr
                        : ($unsetExpr instanceof Operand ? $this->findStaticPropertyFetchForUnset($unsetExpr, $block) : null);
                    if (null !== $staticPropertyFetch) {
                        $staticUnsetOp = new OpCode(
                            OpCode::TYPE_STATIC_PROPERTY_UNSET,
                            null,
                            $this->compileClassNameOperand($staticPropertyFetch->class, $block),
                            $this->compileStaticPropertyNameSlot(
                                $staticPropertyFetch->name,
                                $staticPropertyFetch->class,
                                $block
                            )
                        );
                        $this->assignSourceMetadata($staticUnsetOp, $terminal);
                        $ops[] = $staticUnsetOp;
                        continue;
                    }
                    [$containerSlot, $dimSlot, $unsetOnProperty] = $this->resolveUnsetTarget($unsetExpr, $block);
                    $unsetOp = new OpCode(
                        OpCode::TYPE_UNSET,
                        null,
                        $containerSlot,
                        $dimSlot
                    );
                    $unsetOp->unsetOnProperty = $unsetOnProperty;
                    // Stamp user site so readonly/unset Errors cite unset() not prior opcodes (#25556).
                    $this->assignSourceMetadata($unsetOp, $terminal);
                    $ops[] = $unsetOp;
                }

                return $ops;
            case 'Terminal_GlobalVar':
                $globalName = $this->resolveSimpleVariableName($terminal->var);
                $this->assertNoThisAsGlobalVariable($globalName, $terminal);
                $nameVar = new Variable(Variable::TYPE_STRING);
                $nameVar->string($globalName);
                $nameOperand = new Operand\Literal($globalName);
                $nameOperand->type = Type::string();
                $nameSlot = $block->registerConstant($nameOperand, $nameVar);
                return [new OpCode(
                    OpCode::TYPE_DECLARE_GLOBAL,
                    $this->compileGlobalImportSlot($terminal->var, $globalName, $block),
                    $nameSlot
                )];
            case 'Terminal_StaticVar':
                throw new \LogicException('StaticVar must be compiled via compileOps (#4352)');
            case 'Terminal_SetTickInterval':
                return $this->compileSetTickInterval($terminal, $block);
            case 'Terminal_LeaveTickInterval':
                return $this->compileLeaveTickInterval($terminal, $block);
            default:
                $this->throwCompileLogic("Unknown Terminal Type: " . $terminal->getType());
        }
    }

    /**
     * @return list<OpCode>
     */
    protected function compileSetTickInterval(Op\Terminal $terminal, Block $block): array
    {
        if (!$terminal instanceof Op\Terminal\SetTickInterval) {
            $this->throwCompileLogic('Expected SetTickInterval terminal');
        }
        $interval = max(0, $terminal->interval);
        $scoped = !empty($terminal->scoped);
        // Braced declare(ticks=N){…} pushes so LeaveTickInterval can restore (#22840).
        // File-level declare uses SET and must persist across CFG jumps (#23486) — never
        // mark tickScopeOpened / auto-LEAVE at block edges (that killed for-loop ticks).
        if ($scoped) {
            $this->activeTickIntervalStack[] = $this->activeTickInterval;
            $this->activeTickInterval = $interval;

            return [new OpCode(OpCode::TYPE_TICK_SCOPE_ENTER, $interval)];
        }
        $this->activeTickInterval = $interval;

        return [new OpCode(OpCode::TYPE_TICK_SCOPE_SET, $interval)];
    }

    /**
     * @return list<OpCode>
     */
    protected function compileLeaveTickInterval(Op\Terminal $terminal, Block $block): array
    {
        if (!$terminal instanceof Op\Terminal\LeaveTickInterval) {
            $this->throwCompileLogic('Expected LeaveTickInterval terminal');
        }
        if ([] !== $this->activeTickIntervalStack) {
            $this->activeTickInterval = array_pop($this->activeTickIntervalStack);
        } else {
            $this->activeTickInterval = 0;
        }

        return [new OpCode(OpCode::TYPE_TICK_SCOPE_LEAVE)];
    }

    /**
     * Zend places ZEND_TICKS on the fallthrough after while/for/do-while (#25621).
     * Insert a synthetic block so the exit path ticks once before successor stmts.
     */
    private function wrapBlockWithLoopExitTick(Block $exit): Block
    {
        $wrapper = new Block(null);
        $wrapper->syntheticCfgBranch = true;
        $wrapper->strictTypes = $exit->strictTypes;
        $wrapper->addOpCode(new OpCode(OpCode::TYPE_TICKS));
        $jump = new OpCode(OpCode::TYPE_JUMP);
        $jump->block1 = $exit;
        $wrapper->addOpCode($jump);

        return $wrapper;
    }

    private function isBareRethrowThrow(Op\Terminal\Throw_ $terminal, Block $block): bool
    {
        if (!$this->isBareRethrowLine($terminal->getLine())) {
            return false;
        }

        return $this->throwOperandIsBareRethrowSentinel($terminal->expr, $block);
    }

    private function isBareRethrowExpression(Op\Expr\Throw_ $expr, Block $block, Block ...$extraSearchBlocks): bool
    {
        if (!$this->isBareRethrowLine($expr->getLine())) {
            return false;
        }

        return $this->throwOperandIsBareRethrowSentinel($expr->expr, $block, ...$extraSearchBlocks);
    }

    private function isBareRethrowLine(int $line): bool
    {
        return $line >= 1 && isset($this->bareRethrowLines[$line]);
    }

    /**
     * SourceBareThrowRewriter lowers bare `throw;` to `throw null`; only that sentinel is a rethrow (#3508, #10016).
     */
    private function throwOperandIsBareRethrowSentinel(?Operand $expr, Block $block, Block ...$extraSearchBlocks): bool
    {
        if (!$expr instanceof Operand) {
            return false;
        }
        $innerOp = $this->findOrigExprOpForOperand($expr, $block);
        if (null === $innerOp) {
            foreach ($extraSearchBlocks as $searchBlock) {
                $innerOp = $this->findOrigExprOpForOperand($expr, $searchBlock);
                if (null !== $innerOp) {
                    break;
                }
            }
        }
        if (!$innerOp instanceof Op\Expr\ConstFetch) {
            return false;
        }
        $name = $this->staticNameFromOperand($innerOp->name);

        return 'null' === strtolower((string) $name);
    }

    /**
     * @return OpCode[]
     */
    protected function compileGlobalConst(Op\Terminal\Const_ $const, Block $block): OpCode
    {
        $this->rejectReservedGlobalConstName($const);
        $this->rejectFinalGlobalTypedConstantIfUnsupported($const);
        $valueSlot = $this->tryFoldGlobalConstValueSlot($const, $block);
        if (null === $valueSlot) {
            $this->compileOps($const->valueBlock->children, $block);
            $valueSlot = $this->compileOperand($const->value, $block, true);
        }
        $constName = $this->staticNameFromOperand($const->name);
        $typeSlot = null;
        if (property_exists($const, 'declaredType') && null !== $const->declaredType) {
            if (!$this->cfgDeclaredTypeIsMixed($const->declaredType)) {
                $declared = $this->typeFromClassConstDecl($const);
                $typeSlot = $this->compileTypeConstrainedVariable($block, $declared, $const->declaredType);
                if (isset($block->constants[$valueSlot])) {
                    $this->verifyGlobalConstCompileTimeType(
                        $const->name,
                        $block->constants[$valueSlot],
                        $typeSlot,
                        $block
                    );
                }
            }
        }
        if (null !== $constName && isset($block->constants[$valueSlot])) {
            $this->storeCompileTimeGlobalConst($constName, $block->constants[$valueSlot]);
        }

        $opcode = new OpCode(
            OpCode::TYPE_DECLARE_GLOBAL_CONST,
            $this->compileOperand($const->name, $block, true),
            $valueSlot
        );
        $opcode->globalConstStartLine = max(0, $const->getLine());
        $opcode->deprecatedMetadata = DeprecatedMetadata::fromOp($const);
        $this->assignAttributeMetadata($opcode, $const);
        AttributeNames::assertAttributeMetaClassTargetOnly($opcode->attributeNames, 'constant', $opcode->attributeEntries);
        AttributeNames::assertCompileTimeConstTargetOnly($opcode->attributeNames, 'constant', $opcode->attributeEntries);
        AttributeNames::assertSensitiveParameterParamTargetOnly($opcode->attributeNames, 'constant', $opcode->attributeEntries);
        AttributeNames::assertReturnTypeWillChangeMethodTargetOnly($opcode->attributeNames, 'constant', $opcode->attributeEntries);
        AttributeNames::assertDeprecatedTargetAllowed($opcode->attributeNames, 'constant', $opcode->attributeEntries);
        // PHP 8.5+ user attributes on file/namespace constants (#23882).
        if (CompilerVersion::supportsAttributeTargetConstant() && [] !== $opcode->attributeEntries) {
            AttributeTargetValidator::assertEntriesForTarget(
                $opcode->attributeEntries,
                \PHPCompiler\VM\AttributeSupport::TARGET_CONSTANT,
                'constant',
                $this->attributeClassRegistry,
                true
            );
        }

        return $opcode;
    }

    protected function tryFoldGlobalConstValueSlot(Op\Terminal\Const_ $terminal, Block $block): ?int
    {
        if (null !== $terminal->valueBlock && [] !== $terminal->valueBlock->children) {
            $children = $terminal->valueBlock->children;
            if (1 === \count($children) && $children[0] instanceof Op\Expr\Array_) {
                $vm = $this->tryBuildCompileTimeArrayFromExpr($children[0], $block, $children, true);
                if (null !== $vm) {
                    return $block->registerConstant(new Operand\Temporary(), $vm);
                }
            }
            if (1 === \count($children) && $children[0] instanceof Op\Expr\ClassConstFetch) {
                $vm = $this->tryFoldClassConstFetchDefault($children[0], $block, true);
                if (null !== $vm) {
                    return $block->registerConstant(new Operand\Temporary(), $vm);
                }
            }
            if (1 === \count($children) && $children[0] instanceof Op\Expr\ConstFetch) {
                $vm = $this->tryFoldGlobalConstFetch($children[0]);
                if (null !== $vm) {
                    return $block->registerConstant(new Operand\Temporary(), $vm);
                }
            }
            if (1 === \count($children) && $children[0] instanceof Op\Expr) {
                $vm = $this->tryFoldCompileTimeExprDefault($children[0], $block, $children, true);
                if (null !== $vm) {
                    return $block->registerConstant(new Operand\Temporary(), $vm);
                }
            }
            // Multi-op const-expr (e.g. E::A->value / ->name): php-cfg lowers ClassConstFetch
            // then PropertyFetch; fold like param/property defaults (#19567, zend_compile.c).
            // Global consts emit before DECLARE_ENUM, so runtime CLASS_CONST_FETCH would miss E.
            foreach ($children as $child) {
                if (!$child instanceof Op\Expr) {
                    continue;
                }
                if (!property_exists($child, 'result')
                    || !$this->operandsReferToSameVariable($child->result, $terminal->value)
                ) {
                    continue;
                }
                $vm = $this->tryFoldCompileTimeExprDefault($child, $block, $children, true);
                if (null !== $vm) {
                    return $block->registerConstant(new Operand\Temporary(), $vm);
                }
            }
        }
        $vm = $this->vmVariableFromCfgLiteralOperand($terminal->value);
        if (null === $vm) {
            return null;
        }

        return $block->registerConstant(new Operand\Temporary(), $vm);
    }

    protected function operandsReferToSameVariable(Operand $a, Operand $b): bool
    {
        if ($this->unwrapOperandChain($a) === $this->unwrapOperandChain($b)) {
            return true;
        }
        $rootA = Block::cfgVarRoot($a);
        $rootB = Block::cfgVarRoot($b);
        if (null !== $rootA && null !== $rootB && $rootA === $rootB) {
            return true;
        }
        $nameA = Block::resolveVariableName($a);
        $nameB = Block::resolveVariableName($b);

        return null !== $nameA && '' !== $nameA && $nameA === $nameB;
    }

    protected function unwrapOperandChain(Operand $operand): Operand
    {
        while ($operand instanceof Operand\Temporary && null !== $operand->original) {
            $operand = $operand->original;
        }

        return $operand;
    }

    /**
     * var_export($text->data) / var_export($expr instanceof T) — immediate PropertyFetch/compare prelude (#17540).
     */
    private function resolvePrecedingExpressionPreludeCallArgSlot(
        Operand $arg,
        Block $block,
        ?Op $cfgCallOp,
        int $argIndex
    ): ?string {
        if (null === $block->orig || null === $cfgCallOp || 0 !== $argIndex) {
            return null;
        }
        // The prelude read below is children[$callIndex - 1] — the TRAILING argument's producer.
        // Handing it to arg #0 of a multi-argument call is exactly backwards: f($x + 1, $r['k'])
        // printed "K|K" (#23354). Only valid when arg #0 IS the trailing non-embedded argument.
        if (0 !== $this->trailingNonEmbeddedCallArgIndex($cfgCallOp)) {
            return null;
        }
        $callArg = $cfgCallOp->args[$argIndex] ?? $arg;
        if (!$this->callArgIsDeadInlineTemporary($callArg)) {
            return null;
        }
        $callIndex = $this->cfgCallOpIndexInChildren($block->orig->children, $cfgCallOp, $block->orig);
        if (null === $callIndex || $callIndex < 1) {
            return null;
        }
        $prelude = $block->orig->children[$callIndex - 1] ?? null;
        if (
            !$prelude instanceof Op\Expr
            || !$this->isImmediateVarExportExpressionPrelude($prelude)
            || null === $prelude->result
        ) {
            return null;
        }
        // Multi-arg ctor/call with trailing scalar/flag prelude — do not bind to arg #0 (#19735, #19738).
        // Covers BitwiseOr, Plus/Mul/shifts, UnaryMinus, Cast, etc. (isTrailingInlineNewCtorOptionPrelude).
        if (
            $this->isTrailingInlineNewCtorOptionPrelude($prelude)
            && 0 !== $this->trailingNonEmbeddedCallArgIndex($cfgCallOp)
        ) {
            return null;
        }
        $opcodeSlot = $this->compiledExpressionPreludeResultSlotBeforePendingFuncCall($block, $prelude);
        if (null === $opcodeSlot) {
            foreach ($this->compileExpr($prelude, $block) as $op) {
                $block->addOpCode($op);
            }
            $opcodeSlot = $this->compiledExpressionPreludeResultSlotBeforePendingFuncCall($block, $prelude);
        }
        if (null !== $opcodeSlot) {
            return (string) $opcodeSlot;
        }
        $slot = $block->slotForOperand($prelude->result);
        if (null !== $slot) {
            $opcodeSlot = $this->compiledExpressionPreludeResultSlotBeforePendingFuncCall($block, $prelude);
            if (null !== $opcodeSlot && $opcodeSlot !== $slot) {
                return (string) $opcodeSlot;
            }

            return (string) $slot;
        }

        return null;
    }

    /**
     * Operand slot map can lag TYPE_PROPERTY_FETCH / TYPE_INSTANCEOF when php-cfg reuses dead temps (#17540).
     */
    private function compiledExpressionPreludeResultSlotBeforePendingFuncCall(
        Block $block,
        Op\Expr $prelude
    ): ?int {
        $expectedTypes = match (true) {
            $prelude instanceof Op\Expr\PropertyFetch => [OpCode::TYPE_PROPERTY_FETCH],
            $prelude instanceof Op\Expr\NullsafePropertyFetch => [OpCode::TYPE_NULLSAFE],
            $prelude instanceof Op\Expr\StaticPropertyFetch => [OpCode::TYPE_STATIC_PROPERTY_FETCH],
            $prelude instanceof Op\Expr\ArrayDimFetch => [OpCode::TYPE_ARRAY_DIM_FETCH, OpCode::TYPE_ARRAY_DIM_FETCH_WRITE],
            $prelude instanceof Op\Expr\InstanceOf_ => [OpCode::TYPE_INSTANCEOF],
            $prelude instanceof Op\Expr\Cast => [
                OpCode::TYPE_CAST_ARRAY,
                OpCode::TYPE_CAST_BOOL,
                OpCode::TYPE_CAST_FLOAT,
                OpCode::TYPE_CAST_INT,
                OpCode::TYPE_CAST_OBJECT,
                OpCode::TYPE_CAST_STRING,
                OpCode::TYPE_CAST_UNSET,
                OpCode::TYPE_CAST_VOID,
            ],
            $prelude instanceof Op\Expr\BooleanNot => [OpCode::TYPE_BOOLEAN_NOT],
            $prelude instanceof Op\Expr\BitwiseNot => [OpCode::TYPE_BITWISE_NOT],
            $prelude instanceof Op\Expr\UnaryMinus => [OpCode::TYPE_UNARY_MINUS],
            $prelude instanceof Op\Expr\UnaryPlus => [OpCode::TYPE_UNARY_PLUS],
            // Typed property ++/-- inline call-arg (#26491 / re-#10123, zend_execute.c).
            $prelude instanceof Op\Expr\PostInc => [OpCode::TYPE_POST_INC],
            $prelude instanceof Op\Expr\PreInc => [OpCode::TYPE_PRE_INC],
            $prelude instanceof Op\Expr\PostDec => [OpCode::TYPE_POST_DEC],
            $prelude instanceof Op\Expr\PreDec => [OpCode::TYPE_PRE_DEC],
            $this->isComparisonInlineCallArgProducer($prelude) => [OpCode::TYPE_IDENTICAL, OpCode::TYPE_NOT_IDENTICAL, OpCode::TYPE_EQUAL, OpCode::TYPE_NOT_EQUAL, OpCode::TYPE_SPACESHIP, OpCode::TYPE_SMALLER, OpCode::TYPE_GREATER, OpCode::TYPE_SMALLER_OR_EQUAL, OpCode::TYPE_GREATER_OR_EQUAL, OpCode::TYPE_INSTANCEOF, OpCode::TYPE_IN],
            $this->isArithmeticInlineCallArgProducer($prelude) => [
                OpCode::TYPE_BITWISE_AND,
                OpCode::TYPE_BITWISE_OR,
                OpCode::TYPE_BITWISE_XOR,
                OpCode::TYPE_PLUS,
                OpCode::TYPE_MINUS,
                OpCode::TYPE_MUL,
                OpCode::TYPE_DIV,
                OpCode::TYPE_MODULO,
                OpCode::TYPE_POW,
                OpCode::TYPE_SHIFT_LEFT,
                OpCode::TYPE_SHIFT_RIGHT,
            ],
            default => [],
        };
        if ([] === $expectedTypes) {
            return null;
        }
        for ($i = \count($block->opCodes) - 1; $i >= 0; --$i) {
            $op = $block->opCodes[$i];
            if (\in_array($op->type, $expectedTypes, true)) {
                return $op->arg1;
            }
            if (OpCode::TYPE_FUNCCALL_EXEC_RETURN === $op->type) {
                break;
            }
            // Pending callee INIT/ARG_SEND during compileCallArgSends — skip to hoisted prelude (#14467, #17540).
            if (OpCode::TYPE_FUNCCALL_INIT === $op->type || OpCode::TYPE_ARG_SEND === $op->type) {
                continue;
            }
        }

        return null;
    }

    /**
     * var_dump((['a'=>1])['a']) — php-cfg dead arg temp; Array_ + ArrayDimFetch immediately precede call (#16462).
     */
    private function resolveInlineArrayLiteralDimFetchCallArgSlot(
        Block $block,
        Op $cfgCallOp,
        int $argIndex
    ): ?string {
        if (null === $block->orig || $argIndex < 0) {
            return null;
        }
        $callArg = property_exists($cfgCallOp, 'args') && is_array($cfgCallOp->args)
            ? ($cfgCallOp->args[$argIndex] ?? null)
            : null;
        // Embedded literals (e.g. call_user_func_array('fn', [&$x])) are not dim-fetch producers (#18015).
        if ($this->isEmbeddedCallLiteralArg($callArg)) {
            return null;
        }
        $callIndex = $this->cfgCallOpIndexInChildren($block->orig->children, $cfgCallOp, $block->orig);
        if (null === $callIndex || $callIndex < 1) {
            return null;
        }
        // children[$callIndex - 1] is the TRAILING argument's fetch; handing it to every index made
        // f($x + 1, $r['k']) print "K|K" (#23354).
        if ($argIndex !== $this->trailingNonEmbeddedCallArgIndex($cfgCallOp)) {
            return null;
        }
        $fetch = $block->orig->children[$callIndex - 1] ?? null;
        if (!$fetch instanceof Op\Expr\ArrayDimFetch) {
            return null;
        }
        // Array-literal by-ref element setup (FETCH_DIM_W + ASSIGN_REF) is not a dim-read call arg (#18015).
        if ($this->isArrayDimFetchForWrite($fetch, $block)) {
            return null;
        }
        $array = $callIndex >= 2 ? ($block->orig->children[$callIndex - 2] ?? null) : null;
        if (
            !$array instanceof Op\Expr\Array_
            || !$this->operandsReferToSameVariable($fetch->var, $array->result)
        ) {
            return null;
        }
        if (null === $block->slotForOperand($fetch->result)) {
            foreach ($this->compileExpr($fetch, $block) as $op) {
                $block->addOpCode($op);
            }
        }
        $slot = $block->slotForOperand($fetch->result);

        return null !== $slot ? (string) $slot : null;
    }

    /**
     * Hoisted dim-fetch on a method-call receiver must not bind to call args (#9703).
     */
    private function arrayDimFetchFeedsMethodCallReceiver(
        Op\Expr\ArrayDimFetch $fetch,
        ?Operand $receiver
    ): bool {
        if (null === $receiver) {
            return false;
        }
        if (
            null !== $fetch->result
            && (
                $fetch->result === $receiver
                || $this->operandsReferToSameVariable($fetch->result, $receiver)
            )
        ) {
            return true;
        }
        $root = $this->unwrapOperandChain($receiver);
        if (!$root instanceof Op\Expr\ArrayDimFetch) {
            return false;
        }
        $current = $root;
        while ($current instanceof Op\Expr\ArrayDimFetch) {
            if (
                $current === $fetch
                || (
                    null !== $fetch->result
                    && null !== $current->result
                    && $this->operandsReferToSameVariable($fetch->result, $current->result)
                )
            ) {
                return true;
            }
            $current = $this->unwrapOperandChain($current->var);
        }

        return false;
    }

    /**
     * var_export($a[1][0], true) — chained hoisted dim-fetch tail feeds arg #0 only (#15762, #15945).
     *
     * @param list<Op\Expr> $producers
     */
    private function matchChainedArrayDimFetchInlineCallArgProducer(array $producers, int $argIndex): ?Op\Expr
    {
        // Nested dim chain before isset()/empty() is a quiet prelude, not the call arg (#21991).
        foreach ($producers as $producer) {
            if ($producer instanceof Op\Expr\Isset_ || $producer instanceof Op\Expr\Empty_) {
                return null;
            }
        }
        $dimFetches = array_values(array_filter(
            $producers,
            static fn (Op\Expr $producer): bool => $producer instanceof Op\Expr\ArrayDimFetch
        ));
        if (
            \count($dimFetches) < 2
            || !$this->arrayDimFetchesFormProducerChain($dimFetches)
        ) {
            return null;
        }
        if (0 === $argIndex) {
            return $dimFetches[\count($dimFetches) - 1];
        }
        $nonDimProducers = array_values(array_filter(
            $producers,
            static fn (Op\Expr $producer): bool => !$producer instanceof Op\Expr\ArrayDimFetch
        ));

        return $nonDimProducers[$argIndex - 1] ?? null;
    }

    /**
     * Consecutive hoisted dim-fetch preludes before one call arg — $a[0]['k'] (#14555).
     *
     * @param list<Op\Expr\ArrayDimFetch> $dimFetches
     */
    private function arrayDimFetchesFormProducerChain(array $dimFetches): bool
    {
        if (\count($dimFetches) < 2) {
            return false;
        }
        for ($i = 1; $i < \count($dimFetches); ++$i) {
            $inner = $dimFetches[$i];
            $outer = $dimFetches[$i - 1];
            if (
                null === $inner->var
                || null === $outer->result
                || !$this->operandsReferToSameVariable($inner->var, $outer->result)
            ) {
                return false;
            }
        }

        return true;
    }

    /**
     * Operand slot map can lag TYPE_ARRAY_DIM_FETCH when php-cfg reuses result temps (#10401).
     *
     * @param list<OpCode> $opcodes
     *
     * @return int|null VM slot from the Nth dim-fetch opcode before the pending FUNCCALL_INIT
     */
    private function compiledArrayDimFetchResultSlotBeforePendingFuncCallFromOpcodes(array $opcodes, int $dimIndex = 0): ?int
    {
        $dimFetchOpcodes = [];
        for ($i = \count($opcodes) - 1; $i >= 0; --$i) {
            $op = $opcodes[$i];
            if (OpCode::TYPE_FUNCCALL_INIT === $op->type || OpCode::TYPE_FUNCCALL_EXEC_RETURN === $op->type) {
                break;
            }
            // Write lvalues (TYPE_ARRAYACCESS_OFFSET) are not dim-fetch read results (#10639).
            if (OpCode::TYPE_ARRAY_DIM_FETCH !== $op->type) {
                if ([] !== $dimFetchOpcodes) {
                    break;
                }
                continue;
            }
            array_unshift($dimFetchOpcodes, $op);
        }
        if (!isset($dimFetchOpcodes[$dimIndex])) {
            return null;
        }

        return $dimFetchOpcodes[$dimIndex]->arg1;
    }

    /**
     * @return int|null VM slot from the Nth dim-fetch opcode before the pending FUNCCALL_INIT
     */
    private function compiledArrayDimFetchResultSlotBeforePendingFuncCall(Block $block, int $dimIndex = 0): ?int
    {
        return $this->compiledArrayDimFetchResultSlotBeforePendingFuncCallFromOpcodes($block->opCodes, $dimIndex);
    }

    /**
     * Pending call-arg opcodes may hold the haystack dim-fetch before FUNCCALL_INIT lands on the block (#17000).
     *
     * @param list<OpCode> $pendingOps
     */
    private function pendingCallArgArrayDimFetchSlot(Block $block, array $pendingOps, int $dimIndex = 0): ?int
    {
        if ([] === $pendingOps) {
            return $this->compiledArrayDimFetchResultSlotBeforePendingFuncCall($block, $dimIndex);
        }

        return $this->compiledArrayDimFetchResultSlotBeforePendingFuncCallFromOpcodes(
            array_merge($block->opCodes, $pendingOps),
            $dimIndex
        );
    }

    /**
     * Last ARRAY_DIM_FETCH (read) before pending FUNCCALL_INIT — var_export($meta['k'], …) after earlier dim assigns (#18005).
     * Exclude TYPE_ARRAY_DIM_FETCH_WRITE: write lvalues must not feed call args (#10639).
     *
     * @param list<OpCode> $pendingOps
     */
    private function lastPendingCallArgArrayDimFetchSlot(Block $block, array $pendingOps): ?int
    {
        $dimFetchOpcodes = [];
        $merged = array_merge($block->opCodes, $pendingOps);
        for ($i = \count($merged) - 1; $i >= 0; --$i) {
            $op = $merged[$i];
            if (OpCode::TYPE_FUNCCALL_INIT === $op->type || OpCode::TYPE_FUNCCALL_EXEC_RETURN === $op->type) {
                break;
            }
            if (OpCode::TYPE_ARRAY_DIM_FETCH === $op->type) {
                array_unshift($dimFetchOpcodes, $op);
            }
        }
        if ([] === $dimFetchOpcodes) {
            return null;
        }
        $last = $dimFetchOpcodes[\count($dimFetchOpcodes) - 1];

        return null !== $last->arg1 ? (int) $last->arg1 : null;
    }

    /**
     * Nested inline consumer — last FUNCCALL_EXEC_RETURN before trailing FUNCCALL_INIT (#14555).
     */
    private function slotForLastEmittedInlineCallResultBeforePendingFuncCall(Block $block): ?int
    {
        return $block->lastFunccallExecReturnSlot();
    }

    /**
     * Pending call-arg opcodes — nested FUNCCALL_EXEC_RETURN not yet on the block (#9292).
     *
     * @param list<OpCode> $opcodes
     */
    private function slotForLastPendingInlineCallResultBeforeFuncCallInit(array $opcodes): ?int
    {
        for ($i = \count($opcodes) - 1; $i >= 0; --$i) {
            if (OpCode::TYPE_FUNCCALL_EXEC_RETURN === $opcodes[$i]->type) {
                return (int) $opcodes[$i]->arg1;
            }
            if (OpCode::TYPE_FUNCCALL_INIT === $opcodes[$i]->type) {
                break;
            }
        }

        return null;
    }

    /**
     * Last FUNCCALL_EXEC_RETURN on block plus pending call-arg opcodes (#10474, is_array(file(..., FLAGS))).
     *
     * @param list<OpCode> $pendingOps
     */
    private function slotForLastInlineFuncCallExecReturn(Block $block, array $pendingOps = []): ?int
    {
        $last = $block->lastFunccallExecReturnSlot();
        foreach ($pendingOps as $op) {
            if (OpCode::TYPE_FUNCCALL_EXEC_RETURN === $op->type && null !== $op->arg1) {
                $last = (int) $op->arg1;
            }
        }

        return $last;
    }

    /**
     * php-cfg dead call-arg temp for inline eval() — TYPE_EVAL producer slot (#10661, zif_eval).
     */
    private function resolvePrecedingEvalCallArgSlot(
        Operand $arg,
        Block $block,
        ?Op $cfgCallOp,
        int $argIndex
    ): ?string {
        if (null === $block->orig || null === $cfgCallOp) {
            return null;
        }
        $callSite = $this->findCfgCallSiteForArg($block->orig->children, $arg, $cfgCallOp);
        if (null === $callSite) {
            return null;
        }
        [$callOp, $matchedIndex] = $callSite;
        if ($matchedIndex !== $argIndex) {
            return null;
        }
        $producers = $this->precedingInlineCallArgProducersBeforeCfgOp($block->orig->children, $callOp);
        $matched = $this->matchInlineCallArgProducer($producers, $callOp->args ?? [], $argIndex, $callOp);
        if (!$matched instanceof Op\Expr\Eval_) {
            return null;
        }
        for ($i = \count($block->opCodes) - 1; $i >= 0; --$i) {
            $op = $block->opCodes[$i];
            if (OpCode::TYPE_FUNCCALL_INIT === $op->type) {
                break;
            }
            if (OpCode::TYPE_EVAL === $op->type) {
                return (string) $op->arg1;
            }
        }
        if (null === $block->slotForOperand($matched->result)) {
            foreach ($this->compileExpr($matched, $block) as $op) {
                $block->addOpCode($op);
            }
        }
        $slot = $block->slotForOperand($matched->result);

        return null !== $slot ? (string) $slot : null;
    }

    protected function operandHasObjectType(Operand $operand): bool
    {
        $operand = $this->unwrapOperandChain($operand);

        return null !== $operand->type && Type::TYPE_OBJECT === $operand->type->type;
    }

    /**
     * php-cfg may linearize `E::A; E::B; foo($a, $b)` into dead ClassConstFetch stmts
     * plus distinct call-arg temporaries with no dataflow edge (#5933, #5858).
     *
     * @param list<Op> $cfgChildren
     *
     * @return list<Op\Expr\ClassConstFetch>
     */
    private function precedingClassConstFetchesBeforeCfgOp(array $cfgChildren, Op $callOp): array
    {
        $callIndex = $this->cfgCallOpIndexInChildren($cfgChildren, $callOp);
        if (null === $callIndex) {
            return [];
        }
        $fetches = [];
        for ($i = $callIndex - 1; $i >= 0; --$i) {
            $child = $cfgChildren[$i];
            if ($child instanceof Op\Expr\ClassConstFetch) {
                array_unshift($fetches, $child);

                continue;
            }
            if ($child instanceof Op\Expr\FuncCall || $child instanceof Op\Expr\NsFuncCall) {
                break;
            }
            if ($child instanceof Op\Expr && $this->isInlineExprCallArgProducer($child)) {
                continue;
            }
            break;
        }

        return $fetches;
    }

    /**
     * Call-arg slot mapping must skip enum case fetches that only feed `Case::class` (#9426).
     *
     * @param list<Op\Expr\ClassConstFetch> $fetches
     * @param list<Op> $cfgChildren
     *
     * @return list<Op\Expr\ClassConstFetch>
     */
    private function dropEnumCaseFetchesConsumedByCaseClassPseudoConst(
        array $fetches,
        array $cfgChildren,
        Op $beforeOp,
        Block $block
    ): array {
        if ([] === $fetches) {
            return $fetches;
        }
        $stopIndex = null;
        foreach ($cfgChildren as $i => $child) {
            if ($child === $beforeOp) {
                $stopIndex = $i;
                break;
            }
        }
        if (null === $stopIndex) {
            return $fetches;
        }
        $filtered = [];
        foreach ($fetches as $fetch) {
            if (!$this->isCompileTimeEnumCaseClassConstFetch($fetch, $block)) {
                $filtered[] = $fetch;
                continue;
            }
            $consumed = false;
            for ($i = 0; $i < $stopIndex; ++$i) {
                $child = $cfgChildren[$i];
                if (!$child instanceof Op\Expr\ClassConstFetch) {
                    continue;
                }
                $pseudoName = $this->staticNameFromOperand($child->name);
                if (null === $pseudoName || 'class' !== strtolower($pseudoName)) {
                    continue;
                }
                if ($this->operandsReferToSameVariable($child->class, $fetch->result)) {
                    $consumed = true;
                    break;
                }
            }
            if (!$consumed) {
                $filtered[] = $fetch;
            }
        }

        return $filtered;
    }

    /**
     * @return list<Op\Expr\ClassConstFetch>
     */
    private function precedingCallArgClassConstFetchesBeforeCfgOp(
        array $cfgChildren,
        Op $callOp,
        Block $block
    ): array {
        $fetches = $this->precedingClassConstFetchesBeforeCfgOp($cfgChildren, $callOp);

        return $this->dropEnumCaseFetchesConsumedByCaseClassPseudoConst($fetches, $cfgChildren, $callOp, $block);
    }

    /**
     * php-cfg may hoist `E::A; E::B; f(E::A); g(E::B)` to dead ClassConstFetch stmts before the
     * first call; later calls then lack a preceding fetch (#4260, #5933, ext/standard/type.c).
     */
    private function classConstFetchForHoistedDeadPrelude(
        Op $callOp,
        int $argIndex,
        Block $block
    ): ?Op\Expr\ClassConstFetch {
        if (null === $block->orig) {
            return null;
        }
        $children = $block->orig->children;
        $callIndex = null;
        foreach ($children as $i => $child) {
            if ($child === $callOp) {
                $callIndex = $i;
                break;
            }
        }
        if (null === $callIndex) {
            return null;
        }
        $firstCallIndex = null;
        foreach ($children as $i => $child) {
            if ($child instanceof Op\Expr\FuncCall || $child instanceof Op\Expr\NsFuncCall) {
                $firstCallIndex = $i;
                break;
            }
        }
        if (null === $firstCallIndex || $callIndex <= $firstCallIndex) {
            return null;
        }
        /** @var list<Op\Expr\ClassConstFetch> $hoistedFetches */
        $hoistedFetches = [];
        for ($i = 0; $i < $firstCallIndex; ++$i) {
            $child = $children[$i];
            if ($child instanceof Op\Expr\ClassConstFetch
                && !$this->hoistedEnumCaseFetchConsumedInCfg($child, $block)
            ) {
                $hoistedFetches[] = $child;
            }
        }
        if ([] === $hoistedFetches) {
            return null;
        }
        $callsBefore = 0;
        for ($i = 0; $i < $callIndex; ++$i) {
            $child = $children[$i];
            if ($child instanceof Op\Expr\FuncCall || $child instanceof Op\Expr\NsFuncCall) {
                ++$callsBefore;
            }
        }
        $slotOrdinal = $this->hoistedEnumPreludeSlotOrdinalForCallArg($callOp, $argIndex);
        if (null === $slotOrdinal) {
            return null;
        }
        $fetchIndex = $callsBefore + $slotOrdinal;

        return $hoistedFetches[$fetchIndex] ?? null;
    }

    /**
     * Map call ordinal + arg index to a ClassConstFetch when php-cfg linearizes fetches (#4260).
     */
    private function enumConstFetchForCallOrdinal(Block $block, int $callOrdinal, int $argIndex): ?Op\Expr\ClassConstFetch
    {
        if (null === $block->orig) {
            return null;
        }
        $children = $block->orig->children;
        $targetCall = null;
        $ordinal = 0;
        foreach ($children as $child) {
            if ($child instanceof Op\Expr\FuncCall || $child instanceof Op\Expr\NsFuncCall) {
                if ($ordinal === $callOrdinal) {
                    $targetCall = $child;
                    break;
                }
                ++$ordinal;
            }
        }
        if (null === $targetCall) {
            return null;
        }
        $fetches = $this->precedingCallArgClassConstFetchesBeforeCfgOp($children, $targetCall, $block);

        return $this->precedingClassConstFetchForCallArgIndex($targetCall, $argIndex, $fetches);
    }

    /**
     * @return array{0: Op, 1: int}|null
     */
    private function findCfgCallSiteForArg(array $cfgChildren, Operand $arg, ?Op $knownCallOp = null): ?array
    {
        $argRoot = Block::cfgVarRoot($arg);
        $argChain = $this->unwrapOperandChain($arg);
        if (
            null !== $knownCallOp
            && property_exists($knownCallOp, 'args')
            && is_array($knownCallOp->args)
        ) {
            foreach ($knownCallOp->args as $argIndex => $callArg) {
                if ($this->cfgCallArgOperandsMatch($callArg, $arg, $argChain, $argRoot)) {
                    return [$knownCallOp, $argIndex];
                }
            }
        }
        foreach ($cfgChildren as $child) {
            if (!property_exists($child, 'args') || !is_array($child->args)) {
                continue;
            }
            foreach ($child->args as $argIndex => $callArg) {
                if ($this->cfgCallArgOperandsMatch($callArg, $arg, $argChain, $argRoot)) {
                    return [$child, $argIndex];
                }
            }
        }

        return null;
    }

    private function cfgCallArgOperandsMatch(
        Operand $callArg,
        Operand $arg,
        Operand $argChain,
        ?Operand $argRoot
    ): bool {
        if ($callArg === $arg) {
            return true;
        }
        if ($this->unwrapOperandChain($callArg) === $argChain) {
            return true;
        }

        return null !== $argRoot && Block::cfgVarRoot($callArg) === $argRoot;
    }

    /**
     * php-cfg hoists null/false/true ConstFetch before FuncCall with dead arg temps (#9140, #15931, #16065).
     *
     * @param list<Op\Expr> $producers
     */
    private function matchHoistedScalarConstFetchInlineCallArgProducer(array $producers, ?Operand $callArg): ?Op\Expr\ConstFetch
    {
        if (null === $callArg || !$this->callArgIsDeadInlineTemporary($callArg)) {
            return null;
        }
        foreach ($producers as $producer) {
            if (!$producer instanceof Op\Expr\ConstFetch || null === $producer->result) {
                continue;
            }
            if (!$this->operandsReferToSameVariable($producer->result, $callArg)) {
                continue;
            }
            $name = $this->staticNameFromOperand($producer->name);
            if (null === $name || !\in_array(strtolower($name), ['true', 'false', 'null'], true)) {
                continue;
            }

            return $producer;
        }

        return null;
    }

    /** Stmt immediately before FuncCall is hoisted true/false/null for a trailing call arg (#11407). */
    private function isHoistedScalarConstFetchImmediatelyBeforeCall(?Op $expr): bool
    {
        if (!$expr instanceof Op\Expr\ConstFetch) {
            return false;
        }
        $name = $this->staticNameFromOperand($expr->name);

        return null !== $name && \in_array(strtolower($name), ['true', 'false', 'null'], true);
    }

    /**
     * php-cfg hoists ConstFetch/ClassConstFetch immediately before FuncCall for dead inline arg temps.
     * Defer eager compileOps so FUNCCALL_INIT runs first (php-src undefined-function before undefined-const, #17697).
     *
     * @param Op[] $ops
     */
    private function isDeferredHoistedConstFetchCallArgPrelude(
        Op\Expr $fetch,
        Op\Expr\FuncCall|Op\Expr\NsFuncCall $consumer,
        array $ops,
        int $fetchIndex
    ): bool {
        if (
            !$fetch instanceof Op\Expr\ConstFetch
            && !$fetch instanceof Op\Expr\ClassConstFetch
        ) {
            return false;
        }
        // Sibling comparison operands (false !== ini_get(...)) are not call args — compile eagerly (#17756, #17757).
        if ($this->hoistedConstFetchFeedsSiblingComparisonAfterCall($fetch, $consumer, $ops, $fetchIndex)) {
            return false;
        }
        if (!isset($fetch->result)) {
            return false;
        }
        if (!property_exists($consumer, 'args') || !\is_array($consumer->args)) {
            return false;
        }
        // php-cfg hoists call-arg ConstFetch as the stmt immediately before the consumer (#17697).
        foreach ($consumer->args as $arg) {
            if (null === $arg) {
                continue;
            }
            if ($arg === $fetch->result || $this->operandsReferToSameVariable($arg, $fetch->result)) {
                return true;
            }
        }
        // array_chunk(range(...), 2, true) — php-cfg dead temps may not share cfg roots (#11767).
        if (
            $fetch instanceof Op\Expr\ConstFetch
            && ($ops[$fetchIndex + 1] ?? null) === $consumer
        ) {
            $name = $this->staticNameFromOperand($fetch->name);
            if (null !== $name && \in_array(strtolower($name), ['true', 'false', 'null'], true)) {
                foreach ($consumer->args as $arg) {
                    if ($this->callArgIsDeadInlineTemporary($arg)) {
                        return true;
                    }
                }
            }
        }

        return false;
    }

    /**
     * True when a hoisted fetch supplies a comparison operand after the adjacent FuncCall, not a call arg.
     *
     * @param Op[] $ops
     */
    private function hoistedConstFetchFeedsSiblingComparisonAfterCall(
        Op\Expr $fetch,
        Op\Expr\FuncCall|Op\Expr\NsFuncCall $consumer,
        array $ops,
        int $fetchIndex
    ): bool {
        if (null === $fetch->result || ($ops[$fetchIndex + 1] ?? null) !== $consumer) {
            return false;
        }
        for ($j = $fetchIndex + 2, $n = \count($ops); $j < $n; ++$j) {
            $stmt = $ops[$j];
            if (!$this->isComparisonInlineCallArgProducer($stmt) || !$stmt instanceof Op\Expr\BinaryOp) {
                break;
            }
            if (
                $this->operandsReferToSameVariable($stmt->left, $fetch->result)
                || $this->operandsReferToSameVariable($stmt->right, $fetch->result)
            ) {
                return true;
            }
        }

        return false;
    }

    private function slotForInlineArrayExpr(Block $block, ?Op\Expr\Array_ $arrayExpr): ?string
    {
        if (!$arrayExpr instanceof Op\Expr\Array_) {
            return null;
        }
        if (null === $block->slotForOperand($arrayExpr->result)) {
            foreach ($this->compileArrayLiteral($arrayExpr, $block) as $op) {
                $block->addOpCode($op);
            }
        }
        $slot = $block->slotForOperand($arrayExpr->result);

        return null !== $slot ? (string) $slot : null;
    }

    /** Outermost hoisted Array_ stmt immediately before a cfg FuncCall (#11485). */
    private function resolveInlineArrayProducerSlotBeforeCfgCall(Op $callOp, Block $block): ?string
    {
        $arrayExpr = $this->inlineArrayProducerImmediatelyBeforeCfgCall($callOp, $block);
        if (!$arrayExpr instanceof Op\Expr\Array_) {
            return null;
        }
        if (null === $block->slotForOperand($arrayExpr->result)) {
            foreach ($this->compileExpr($arrayExpr, $block) as $op) {
                $block->addOpCode($op);
            }
        }
        $slot = $block->slotForOperand($arrayExpr->result);

        return null !== $slot ? (string) $slot : null;
    }

    /**
     * Outermost inline Array_ producer for nested literal call args (descriptor_spec, etc.) (#11485).
     *
     * @param list<Op\Expr> $producers
     * @param list<Op\Expr\Array_> $arrayProducers
     */
    private function matchOutermostNestedInlineArrayProducerForCallArg(
        array $producers,
        array $arrayProducers,
        int $argIndex,
        int $argCount
    ): ?Op\Expr\Array_ {
        if ([] === $arrayProducers) {
            return null;
        }
        $nestedTrailing = $this->splitNestedArrayLiteralChainWithTrailingProducers($producers);
        if (null !== $nestedTrailing) {
            [$arrayChain, $trailing] = $nestedTrailing;
            if (1 + \count($trailing) === $argCount && $argIndex < \count($arrayChain)) {
                $outer = $arrayChain[\count($arrayChain) - 1] ?? null;

                return $outer instanceof Op\Expr\Array_ ? $outer : null;
            }
        }
        if (
            \count($arrayProducers) >= 2
            && $this->producersAreNestedArrayLiteralChain($arrayProducers)
            && $this->arrayProducersFormNestedChain($arrayProducers)
        ) {
            $outer = $arrayProducers[\count($arrayProducers) - 1];

            return $outer instanceof Op\Expr\Array_ ? $outer : null;
        }

        return $arrayProducers[\count($arrayProducers) - 1];
    }

    /**
     * By-ref named locals are real CV operands, not hoisted inline FuncCall producers (#15476, #13714).
     */
    private function isByRefNamedCallArgExcludedFromSiblingProducerWiring(
        Op $consumer,
        int $argIndex,
        Operand $arg
    ): bool {
        if (!$this->isNamedVariableOperand($arg)) {
            return false;
        }
        if (!$consumer instanceof Op\Expr\FuncCall && !$consumer instanceof Op\Expr\NsFuncCall) {
            return false;
        }
        $calleeName = $this->funcCallExprCalleeName($consumer);
        if (null === $calleeName) {
            return false;
        }

        return $this->callArgRequiresByRef($calleeName, $argIndex, $arg);
    }

    private function callArgRequiresByRef(string $calleeName, int $argIndex, ?Operand $arg = null, ?Block $block = null): bool
    {
        if ('array_multisort' === strtolower($calleeName)) {
            if (null !== $arg && null !== $block && $this->isArrayMultisortSortFlagOperand($arg, $block)) {
                return false;
            }

            return true;
        }
        $lc = strtolower($calleeName);
        if (isset($this->userFunctionParamByRef[$lc][$argIndex])) {
            return true;
        }
        if (\in_array($argIndex, BuiltinByRefParams::forFunction($calleeName), true)) {
            return true;
        }
        $variadicFrom = BuiltinByRefParams::variadicByRefFromIndex($calleeName);

        return null !== $variadicFrom && $argIndex >= $variadicFrom;
    }

    /**
     * array_multisort() SORT_* / Sorting enum operands are by-value (#9481, ext/standard/array.c).
     */
    private function isArrayMultisortSortFlagOperand(Operand $arg, Block $block): bool
    {
        if ($this->operandLooksLikeArrayMultisortSortFlag($arg)) {
            return true;
        }
        $slot = $this->tryFoldCallArgCompileTimeValue($arg, $block, 'array_multisort', null);
        if (null === $slot || !isset($block->constants[$slot])) {
            return false;
        }
        $const = $block->constants[$slot];
        if (Variable::TYPE_INTEGER !== $const->type) {
            return false;
        }
        $val = $const->toInt();
        $masked = $val & ~\PHPCompiler\ext\standard\StdlibConstants::SORT_FLAG_CASE;

        return \in_array($masked, [
            \PHPCompiler\ext\standard\StdlibConstants::SORT_ASC,
            \PHPCompiler\ext\standard\StdlibConstants::SORT_DESC,
            \PHPCompiler\ext\standard\StdlibConstants::SORT_REGULAR,
            \PHPCompiler\ext\standard\StdlibConstants::SORT_NUMERIC,
            \PHPCompiler\ext\standard\StdlibConstants::SORT_STRING,
            \PHPCompiler\ext\standard\StdlibConstants::SORT_NATURAL,
            \PHPCompiler\ext\standard\StdlibConstants::SORT_LOCALE_STRING,
        ], true) || 0 !== ($val & \PHPCompiler\ext\standard\StdlibConstants::SORT_FLAG_CASE);
    }

    /** SORT_* / Sorting enum operands in array_multisort() are by-value (#9481). */
    private function operandLooksLikeArrayMultisortSortFlag(Operand $arg): bool
    {
        if ($arg instanceof Op\Expr\ConstFetch) {
            $name = $this->staticNameFromOperand($arg->name);
            if (null !== $name && str_starts_with(strtoupper($name), 'SORT_')) {
                return true;
            }
        }
        if ($arg instanceof Op\Expr\ClassConstFetch) {
            $class = $this->staticNameFromOperand($arg->class);
            if (null !== $class && 0 === strcasecmp(ltrim($class, '\\'), 'Sorting')) {
                return true;
            }
        }

        return false;
    }

    /**
     * Resolve a compile-time global function name from a php-cfg FuncCall/NsFuncCall expr.
     */
    private function funcCallExprCalleeName(Op\Expr $call): ?string
    {
        if ($call instanceof Op\Expr\FuncCall || $call instanceof Op\Expr\NsFuncCall) {
            return $this->staticNameFromOperand($call->name);
        }

        return null;
    }

    /**
     * Statement-level VM builtins that take by-ref args must compile eagerly — deferring as
     * sibling inline producers drops mutations (natcasesort + array_values + implode, #12732).
     */
    private function funcCallExprHasByRefMutatingSideEffects(Op $op): bool
    {
        if (!$op instanceof Op\Expr\FuncCall && !$op instanceof Op\Expr\NsFuncCall) {
            return false;
        }
        $calleeName = $this->funcCallExprCalleeName($op);
        if (null === $calleeName) {
            return false;
        }
        if ([] !== BuiltinByRefParams::forFunction($calleeName)) {
            return true;
        }

        $variadicFrom = BuiltinByRefParams::variadicByRefFromIndex($calleeName);
        if (null !== $variadicFrom) {
            if (
                property_exists($op, 'args')
                && \is_array($op->args)
                && \count($op->args) <= $variadicFrom
            ) {
                return false;
            }

            return true;
        }

        return false;
    }

    /**
     * True when $arg is passed by reference to a VM builtin in $call (issue #9074).
     */
    private function funcCallExprByRefArgMatchesOperand(Op\Expr $call, Operand $arg): bool
    {
        if (
            !($call instanceof Op\Expr\FuncCall || $call instanceof Op\Expr\NsFuncCall)
            || !property_exists($call, 'args')
            || !is_array($call->args)
        ) {
            return false;
        }
        $calleeName = $this->funcCallExprCalleeName($call);
        if (null === $calleeName) {
            return false;
        }
        foreach (BuiltinByRefParams::forFunction($calleeName) as $idx) {
            if (!isset($call->args[$idx])) {
                continue;
            }
            if ($this->operandsReferToSameVariable($call->args[$idx], $arg)) {
                return true;
            }
        }
        $variadicFrom = BuiltinByRefParams::variadicByRefFromIndex($calleeName);
        if (null === $variadicFrom) {
            return false;
        }
        $n = \count($call->args);
        for ($i = $variadicFrom; $i < $n; ++$i) {
            if (!isset($call->args[$i])) {
                continue;
            }
            if (
                'array_multisort' === strtolower($calleeName)
                && $this->operandLooksLikeArrayMultisortSortFlag($call->args[$i])
            ) {
                continue;
            }
            if ($this->operandsReferToSameVariable($call->args[$i], $arg)) {
                return true;
            }
        }

        return false;
    }

    private function callArgUnpack(Operand $arg): bool
    {
        return property_exists($arg, 'callArgUnpack') && true === $arg->callArgUnpack;
    }

    /**
     * Zend zend_compile.c: positional-after-named, unpack ordering (#4299, #4663).
     * Duplicate named params are deferred to runtime (zend_execute.c, #16652).
     *
     * @param list<Operand> $args
     */
    private function validateCallArgOrder(array $args): void
    {
        $hadNamed = false;
        $hadUnpack = false;
        foreach ($args as $arg) {
            $argName = $this->callArgName($arg);
            $isNamed = null !== $argName;
            $isUnpack = $this->callArgUnpack($arg);
            if ($isUnpack && $hadNamed) {
                $this->throwCompileError('Cannot use argument unpacking after named arguments');
            }
            if (!$isNamed && !$isUnpack && $hadNamed) {
                $this->throwCompileError('Cannot use positional argument after named argument');
            }
            if (!$isNamed && !$isUnpack && $hadUnpack) {
                $this->throwCompileError('Cannot use positional argument after argument unpacking');
            }
            if ($isNamed) {
                $hadNamed = true;
            }
            if ($isUnpack) {
                $hadUnpack = true;
            }
        }
    }

    private function callArgName(Operand $arg): ?string
    {
        if (property_exists($arg, 'callArgName') && null !== $arg->callArgName) {
            $name = $arg->callArgName;

            return is_string($name) && '' !== $name ? $name : null;
        }

        return null;
    }

    /**
     * CFG call argument expression for hoisted producer wiring (#16057, #18410).
     */
    private function cfgCallArgOperand(?Op $cfgCallOp, int $argIndex, $loopArg): ?Operand
    {
        if (null !== $cfgCallOp && property_exists($cfgCallOp, 'args') && is_array($cfgCallOp->args)) {
            $cfgArg = $cfgCallOp->args[$argIndex] ?? null;
            if ($cfgArg instanceof Operand) {
                return $cfgArg;
            }
        }
        if ($loopArg instanceof Operand) {
            return $loopArg;
        }

        return null;
    }

    /**
     * Constant slot for TYPE_ARG_SEND named-parameter label (#11052, #12018).
     */
    private function callArgNameSlot(Operand $arg, Block $block): ?string
    {
        $argName = $this->callArgName($arg);
        if (null === $argName) {
            return null;
        }
        $nameOp = new Operand\Literal($argName);
        $nameOp->type = Type::string();
        $nameVar = new Variable(Variable::TYPE_STRING);
        $nameVar->string($argName);

        return $block->registerConstant($nameOp, $nameVar);
    }

    /**
     * True when any call operand carries a php-cfg named-parameter label (#11052, #11105).
     */
    private function callIncludesNamedParameter(?Op $cfgCallOp): bool
    {
        if (null === $cfgCallOp || !property_exists($cfgCallOp, 'args') || !is_array($cfgCallOp->args)) {
            return false;
        }
        foreach ($cfgCallOp->args as $arg) {
            if ($arg instanceof Operand && null !== $this->callArgName($arg)) {
                return true;
            }
        }

        return false;
    }


    /**
     * True when a Plus/Minus(read, 1) + Assign(write) pair is lowered ++/-- (#3469).
     *
     * php-cfg uses dedicated PostInc/PreInc/PostDec/PreDec ops (#3552), not Plus+Assign.
     * AssignOp ($x += 1 / $x -= 1) shares the Plus(var,1)+Assign shape and must not set
     * {@see OpCode::$isIncDec} — bool compound assign promotes to int, ++/-- does not (#7340).
     */
    private function isIncDecBinaryOp(Op\Expr\BinaryOp $expr): bool
    {
        return false;
    }

    private function operandsSameBaseVariable(?Operand $left, ?Operand $right): bool
    {
        $leftName = $this->baseVariableName($left);
        $rightName = $this->baseVariableName($right);
        if (null === $leftName || null === $rightName) {
            return false;
        }

        return $leftName === $rightName;
    }

    private function baseVariableName(?Operand $operand): ?string
    {
        while ($operand instanceof Temporary && $operand->original instanceof Operand) {
            $operand = $operand->original;
        }
        if ($operand instanceof BoundVariable && $operand->name instanceof Literal && is_string($operand->name->value)) {
            return $operand->name->value;
        }
        if ($operand instanceof CfgVariable && $operand->name instanceof Literal && is_string($operand->name->value)) {
            return $operand->name->value;
        }

        return null;
    }

}
