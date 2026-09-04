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
require_once __DIR__.'/Compiler/Concern/ExpressionPreludeDimFetchAndHoistedConstCallArgSlots.php';
require_once __DIR__.'/Compiler/Concern/CfgProducerIndexAndRematerialize.php';
require_once __DIR__.'/Compiler/Concern/InlineCallArgClosureFeedsAndReturnProducers.php';
require_once __DIR__.'/Compiler/Concern/NestedArrayOutermostAndByRefCallArgHelpers.php';
require_once __DIR__.'/Compiler/Concern/OperandAccessAndDeferredArrayCompile.php';
require_once __DIR__.'/Compiler/Concern/TryFinallyCatchAndOperandLookup.php';
require_once __DIR__.'/Compiler/Concern/EchoCompileOperandTerminalAndGlobalConst.php';
require_once __DIR__.'/Compiler/Concern/CompileOpsTicksAndCfgSplit.php';

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
use PHPCompiler\Compiler\Concern\ExactHoistedAndInlineNewCallArgProducers;
use PHPCompiler\Compiler\Concern\ExpressionPreludeDimFetchAndHoistedConstCallArgSlots;
use PHPCompiler\Compiler\Concern\CfgProducerIndexAndRematerialize;
use PHPCompiler\Compiler\Concern\InlineCallArgClosureFeedsAndReturnProducers;
use PHPCompiler\Compiler\Concern\NestedArrayOutermostAndByRefCallArgHelpers;
use PHPCompiler\Compiler\Concern\OperandAccessAndDeferredArrayCompile;
use PHPCompiler\Compiler\Concern\TryFinallyCatchAndOperandLookup;
use PHPCompiler\Compiler\Concern\EchoCompileOperandTerminalAndGlobalConst;
use PHPCompiler\Compiler\Concern\CompileOpsTicksAndCfgSplit;
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
    use ExpressionPreludeDimFetchAndHoistedConstCallArgSlots;
    use CfgProducerIndexAndRematerialize;
    use InlineCallArgClosureFeedsAndReturnProducers;
    use NestedArrayOutermostAndByRefCallArgHelpers;
    use OperandAccessAndDeferredArrayCompile;
    use TryFinallyCatchAndOperandLookup;
    use EchoCompileOperandTerminalAndGlobalConst;
    use CompileOpsTicksAndCfgSplit;

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
}
