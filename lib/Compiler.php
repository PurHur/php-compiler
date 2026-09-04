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
     * php-cfg emits ArrayDimFetch as its own stmt before Coalesce; skip duplicate lowering.
     */
    private function isArrayDimFetchOnlyCoalesceLeft(
        Op\Expr\ArrayDimFetch $fetch,
        Op $next
    ): bool {
        if (!$next instanceof Op\Expr\BinaryOp\Coalesce) {
            return false;
        }
        $left = $next->left;
        while ($left instanceof Temporary) {
            if ($left === $fetch->result) {
                return true;
            }
            if (null === $left->original) {
                break;
            }
            $left = $left->original;
        }

        return $left === $fetch->result;
    }

    private function isPropertyFetchOnlyCoalesceLeft(
        Op\Expr\PropertyFetch $fetch,
        Op $next
    ): bool {
        if (!$next instanceof Op\Expr\BinaryOp\Coalesce) {
            return false;
        }
        $left = $next->left;
        while ($left instanceof Temporary) {
            if ($left === $fetch->result) {
                return true;
            }
            if (null === $left->original) {
                break;
            }
            $left = $left->original;
        }

        return $left === $fetch->result;
    }

    /**
     * php-cfg emits StaticPropertyFetch as its own stmt before ?? / ??= (#31146).
     */
    private function isStaticPropertyFetchOnlyCoalesceLeft(
        Op\Expr\StaticPropertyFetch $fetch,
        Op $next
    ): bool {
        if (!$next instanceof Op\Expr\BinaryOp\Coalesce) {
            return false;
        }
        $left = $next->left;
        while ($left instanceof Temporary) {
            if ($left === $fetch->result) {
                return true;
            }
            if (null === $left->original) {
                break;
            }
            $left = $left->original;
        }

        return $left === $fetch->result;
    }

    /**
     * php-cfg emits StaticPropertyFetch as its own stmt before ?? / ??= (#31146).
     *
     * @param Op[] $ops
     *
     * @return ?array{0: Op\Expr\BinaryOp\Coalesce, 1: int}
     */
    private function findCoalesceUsingStaticPropertyFetchLeft(
        Op\Expr\StaticPropertyFetch $fetch,
        array $ops,
        int $index
    ): ?array {
        $count = count($ops);
        for ($j = $index + 1; $j < $count; ++$j) {
            $next = $ops[$j];
            if ($next instanceof Op\Expr\BinaryOp\Coalesce) {
                if ($this->isStaticPropertyFetchOnlyCoalesceLeft($fetch, $next)) {
                    return [$next, $j];
                }
                continue;
            }
            if ($this->isLoweredByFollowingCoalesce($next, $ops, $j)) {
                continue;
            }
            continue;
        }

        return null;
    }

    /**
     * php-cfg may emit RHS expr stmts between PropertyFetch and Coalesce (#8902).
     *
     * @param Op[] $ops
     *
     * @return ?array{0: Op\Expr\BinaryOp\Coalesce, 1: int}
     */
    private function findCoalesceUsingPropertyFetchLeft(
        Op\Expr\PropertyFetch $fetch,
        array $ops,
        int $index
    ): ?array {
        $count = count($ops);
        for ($j = $index + 1; $j < $count; ++$j) {
            $next = $ops[$j];
            if ($next instanceof Op\Expr\BinaryOp\Coalesce) {
                if ($this->isPropertyFetchOnlyCoalesceLeft($fetch, $next)) {
                    return [$next, $j];
                }
                // Nested ??= before outer ??= (e.g. $a->p ??= $b->q ??= 9) — keep scanning (#33760).
                continue;
            }
            if ($this->isLoweredByFollowingCoalesce($next, $ops, $j)) {
                continue;
            }
            // php-cfg hoists inner PropertyFetch / ??= stmts between outer fetch and ?? (#33760).
            continue;
        }

        return null;
    }

    private function isPropertyFetchOnlyCoalesceFuncCallArg(
        Op\Expr\PropertyFetch $fetch,
        Op $call,
        Block $block
    ): bool {
        if (!$call instanceof Op\Expr\FuncCall && !$call instanceof Op\Expr\NsFuncCall) {
            return false;
        }
        if (!property_exists($call, 'args') || !is_array($call->args)) {
            return false;
        }
        foreach ($call->args as $arg) {
            $coalesce = $this->findCoalesceStmtForCallArg($arg, $block);
            if (null !== $coalesce && $this->findCoalescePropertyFetch($coalesce->left, $block) === $fetch) {
                return true;
            }
        }

        return false;
    }

    private function isArrayDimFetchOnlyCoalesceFuncCallArg(
        Op\Expr\ArrayDimFetch $fetch,
        Op $call,
        Block $block
    ): bool {
        if (!$call instanceof Op\Expr\FuncCall && !$call instanceof Op\Expr\NsFuncCall) {
            return false;
        }
        if (!property_exists($call, 'args') || !is_array($call->args)) {
            return false;
        }
        foreach ($call->args as $arg) {
            $coalesce = $this->findCoalesceStmtForCallArg($arg, $block);
            if (null !== $coalesce && $this->findCoalesceArrayDimFetch($coalesce->left, $block) === $fetch) {
                return true;
            }
        }

        return false;
    }

    /**
     * php-cfg may emit RHS expr stmts (FuncCall, …) between ArrayDimFetch and Coalesce (#4416).
     *
     * @param Op[] $ops
     *
     * @return ?array{0: Op\Expr\BinaryOp\Coalesce, 1: int}
     */
    private function findCoalesceUsingArrayDimFetchLeft(
        Op\Expr\ArrayDimFetch $fetch,
        array $ops,
        int $index
    ): ?array {
        $count = count($ops);
        for ($j = $index + 1; $j < $count; ++$j) {
            $next = $ops[$j];
            if ($next instanceof Op\Expr\BinaryOp\Coalesce) {
                if (!$this->isArrayDimFetchOnlyCoalesceLeft($fetch, $next)) {
                    return null;
                }

                return [$next, $j];
            }
            if ($this->isLoweredByFollowingCoalesce($next, $ops, $j)) {
                continue;
            }

            return null;
        }

        return null;
    }

    /**
     * php-cfg: ArrayDimFetch; Coalesce; Assign $dst = fetch-temp after ?? already stored in $dst.
     */
    private function isRedundantCoalesceTailAssign(
        Op\Expr\Assign $assign,
        Op\Expr\ArrayDimFetch $fetch,
        Op\Expr\BinaryOp\Coalesce $coalesce
    ): bool {
        return $this->isCoalesceAssignTail($assign, $coalesce);
    }

    /**
     * php-cfg: Coalesce; Assign $dst = coalesce-result for ??= (issue #1235).
     */
    private function isCoalesceAssignTail(
        Op\Expr\Assign $assign,
        Op\Expr\BinaryOp\Coalesce $coalesce
    ): bool {
        return $this->operandsChainEqual($assign->expr, $coalesce->result);
    }

    /**
     * php-cfg emits inner ?? before outer for chains ($a ?? $b ?? $c); only lower the outer stmt (#3798).
     *
     * @param Op[] $ops
     */
    private function isCoalesceChainInnerStmt(
        Op\Expr\BinaryOp\Coalesce $inner,
        array $ops,
        int $index
    ): bool {
        if ($index + 1 >= count($ops)) {
            return false;
        }
        $next = $ops[$index + 1];
        if (!$next instanceof Op\Expr\BinaryOp\Coalesce) {
            return false;
        }

        return $this->operandsChainEqual($next->right, $inner->result);
    }

    /**
     * @return ?Op\Expr\ConcatList
     */
    private function unwrapConcatListExpr(Operand $operand): ?Op\Expr\ConcatList
    {
        while ($operand instanceof Temporary) {
            if ($operand->original instanceof Op\Expr\ConcatList) {
                return $operand->original;
            }
            if (null === $operand->original) {
                return null;
            }
            $operand = $operand->original;
        }
        if ($operand instanceof Op\Expr\ConcatList) {
            return $operand;
        }

        return null;
    }

    private function unwrapBinaryConcatExpr(Operand $operand): ?Op\Expr\BinaryOp\Concat
    {
        while ($operand instanceof Temporary) {
            if ($operand->original instanceof Op\Expr\BinaryOp\Concat) {
                return $operand->original;
            }
            if (null === $operand->original) {
                return null;
            }
            $operand = $operand->original;
        }
        if ($operand instanceof Op\Expr\BinaryOp\Concat) {
            return $operand;
        }

        return null;
    }

    /**
     * @param Op[] $ops
     */
    private function resolveBinaryConcatForOperand(Operand $operand, array $ops): ?Op\Expr\BinaryOp\Concat
    {
        $concat = $this->unwrapBinaryConcatExpr($operand);
        if (null !== $concat) {
            return $concat;
        }
        foreach ($ops as $op) {
            if ($op instanceof Op\Expr\BinaryOp\Concat && $this->operandsChainEqual($op->result, $operand)) {
                return $op;
            }
        }

        return null;
    }

    /**
     * Flatten nested BinaryOp\Concat trees to one ConcatList so ?? branches do not split temps (#10430).
     *
     * @param Op[] $ops
     *
     * @return ?Op\Expr\ConcatList
     */
    private function flattenBinaryConcatFromBlockOps(array $ops, int $echoIndex, Operand $echoExpr): ?Op\Expr\ConcatList
    {
        $outer = null;
        for ($j = $echoIndex - 1; $j >= 0; --$j) {
            $candidate = $ops[$j] ?? null;
            if (
                $candidate instanceof Op\Expr\BinaryOp\Concat
                && $this->operandsChainEqual($candidate->result, $echoExpr)
            ) {
                $outer = $candidate;
                break;
            }
        }
        if (null === $outer) {
            return $this->flattenBinaryConcatToConcatList($echoExpr);
        }
        $parts = [];
        $current = $outer;
        while ($current instanceof Op\Expr\BinaryOp\Concat) {
            $parts[] = $current->right;
            $inner = $this->resolveBinaryConcatForOperand($current->left, $ops);
            if ($inner instanceof Op\Expr\BinaryOp\Concat) {
                $current = $inner;
                continue;
            }
            $parts[] = $current->left;
            break;
        }
        if (\count($parts) < 2) {
            return null;
        }
        $parts = array_reverse($parts);
        $list = new Op\Expr\ConcatList($parts, $outer->getAttributes());
        $list->result = $outer->result;

        return $list;
    }

    /**
     * @return ?Op\Expr\ConcatList
     */
    private function flattenBinaryConcatToConcatList(?Operand $operand): ?Op\Expr\ConcatList
    {
        if (null === $operand) {
            return null;
        }
        $parts = [];
        $current = $operand;
        $topConcat = null;
        while (null !== $current) {
            $concat = $this->unwrapBinaryConcatExpr($current);
            if (null === $concat) {
                $parts[] = $current;
                break;
            }
            if (null === $topConcat) {
                $topConcat = $concat;
            }
            $parts[] = $concat->right;
            $current = $concat->left;
        }
        if (\count($parts) < 2 || null === $topConcat) {
            return null;
        }
        $parts = array_reverse($parts);
        $list = new Op\Expr\ConcatList($parts, $topConcat->getAttributes());
        $list->result = $topConcat->result;

        return $list;
    }

    /**
     * @param Op[] $ops
     *
     * @return list<Op\Expr\BinaryOp\Coalesce>
     */
    private function findBlockCoalescesBeforeIndex(array $ops, int $endIndex): array
    {
        $found = [];
        for ($j = 0; $j < $endIndex; ++$j) {
            if ($ops[$j] instanceof Op\Expr\BinaryOp\Coalesce) {
                $found[] = $ops[$j];
            }
        }

        return $found;
    }

    /**
     * Defer BinaryOp\Concat only when a following echo will lower pending ?? into the
     * concat (compileEchoWithEmbeddedCoalesce). Already-merged coalesces must not count —
     * otherwise a later `echo "x".$obj->prop` after `$a?->b ?? …` skips CONCAT and echoes
     * an empty temp (#25525 / re-#18455).
     *
     * @param Op[] $ops
     */
    private function isConcatLoweredByFollowingEcho(Op\Expr\BinaryOp\Concat $concat, array $ops, int $index): bool
    {
        $count = \count($ops);
        for ($j = $index + 1; $j < $count; ++$j) {
            $next = $ops[$j];
            if ($next instanceof Op\Terminal\Echo_) {
                $coalesces = $this->findEmbeddedCoalesces($next->expr);
                if ([] === $coalesces) {
                    // Match compileEchoWithEmbeddedCoalesce: only pending ?? (#25525).
                    foreach ($this->findBlockCoalescesBeforeIndex($ops, $j) as $candidate) {
                        if (!isset($this->coalesceMergeBlocks[spl_object_id($candidate)])) {
                            $coalesces[] = $candidate;
                        }
                    }
                }
                if ([] === $coalesces) {
                    return false;
                }

                return null !== $this->flattenBinaryConcatFromBlockOps($ops, $j, $next->expr)
                    || null !== $this->unwrapConcatListExpr($next->expr)
                    || null !== $this->flattenBinaryConcatToConcatList($next->expr);
            }
            if ($next instanceof Op\Terminal\Return || $next instanceof Op\Expr\Assign) {
                return false;
            }
        }

        return false;
    }

    /**
     * @param Op[] $ops
     */
    private function isCoalesceLoweredByFollowingEchoConcat(array $ops, int $index): bool
    {
        // ??= (coalesce + tail assign) must compile with ISSET/COALESCE branches — the echo reads
        // the array element via a separate dim fetch, not the coalesce result (#30435).
        if (
            $ops[$index] instanceof Op\Expr\BinaryOp\Coalesce
            && $index + 1 < \count($ops)
            && $ops[$index + 1] instanceof Op\Expr\Assign
            && $this->isCoalesceAssignTail($ops[$index + 1], $ops[$index])
        ) {
            return false;
        }
        for ($j = $index + 1; $j < \count($ops); ++$j) {
            if ($ops[$j] instanceof Op\Terminal\Echo_) {
                if (null !== $this->flattenBinaryConcatToConcatList($ops[$j]->expr)) {
                    return true;
                }
                if (null !== $this->flattenBinaryConcatFromBlockOps($ops, $j, $ops[$j]->expr)) {
                    return true;
                }

                return false;
            }
            if ($ops[$j] instanceof Op\Terminal\Return) {
                return false;
            }
        }

        return false;
    }

    /**
     * echo var_export($arr['k'] ?? $d, true) . "\n" — defer call until ?? merge + concat echo (#18315).
     * Also `"prefix" . var_export($o->x ?? $d, true)` where the call is Concat.right (#31769).
     *
     * @param Op[] $ops
     */
    private function isFuncCallLoweredByFollowingEchoConcat(Op $call, array $ops, int $index): bool
    {
        if (
            !($call instanceof Op\Expr\FuncCall || $call instanceof Op\Expr\NsFuncCall)
            || !property_exists($call, 'result')
            || null === $call->result
        ) {
            return false;
        }
        $concat = $ops[$index + 1] ?? null;
        $concatAtNext = $concat instanceof Op\Expr\BinaryOp\Concat
            && (
                $this->operandsChainEqual($concat->left, $call->result)
                || $this->operandsReferToSameVariable($concat->left, $call->result)
                || $this->operandsChainEqual($concat->right, $call->result)
                || $this->operandsReferToSameVariable($concat->right, $call->result)
            );
        if (!$concatAtNext) {
            // set_error_handler() may sit between hoisted soft-null producer and echo (#21223).
            $name = strtolower($this->resolveCfgFuncCallName($call) ?? '');
            if (!$this->funcCallNameMaySoftNullDeprecateOnProfile84($name)) {
                return false;
            }

            return $this->hoistedSoftNullProducerHasFollowingEcho($ops, $index + 1);
        }
        for ($j = $index + 2; $j < \count($ops); ++$j) {
            $next = $ops[$j];
            if ($next instanceof Op\Terminal\Echo_) {
                $flattened = $this->flattenBinaryConcatFromBlockOps($ops, $j, $next->expr)
                    ?? $this->flattenBinaryConcatToConcatList($next->expr);
                if (null === $flattened) {
                    return false;
                }
                $feedsEchoConcat = false;
                foreach ($flattened->list as $part) {
                    if (
                        null !== $part
                        && (
                            $this->operandsChainEqual($part, $call->result)
                            || $this->operandsReferToSameVariable($part, $call->result)
                        )
                    ) {
                        $feedsEchoConcat = true;
                        break;
                    }
                }
                if (!$feedsEchoConcat) {
                    return false;
                }
                for ($k = $index - 1; $k >= 0; --$k) {
                    $prev = $ops[$k];
                    if ($prev instanceof Op\Expr\BinaryOp\Coalesce) {
                        return $this->isCoalesceLoweredByFollowingEchoConcat($ops, $k);
                    }
                    if (!$prev instanceof Op\Expr || !$this->isInlineExprCallArgProducer($prev)) {
                        break;
                    }
                }

                return false;
            }
            if ($next instanceof Op\Terminal\Return) {
                return false;
            }
        }

        return false;
    }

    /**
     * PROFILE≥8.4 soft-null hoisted producer — later echo must run set_error_handler first (#21223).
     *
     * @param Op[] $ops
     */
    private function hoistedSoftNullProducerHasFollowingEcho(array $ops, int $startIndex): bool
    {
        for ($j = $startIndex, $opCount = \count($ops); $j < $opCount; ++$j) {
            $next = $ops[$j];
            if ($next instanceof Op\Terminal\Echo_) {
                return true;
            }
            if ($next instanceof Op\Terminal\Return) {
                return false;
            }
        }

        return false;
    }

    /** set_error_handler()/restore_error_handler() between hoisted producers and echo (#21223). */
    private function isErrorHandlerRegistrationStmt(Op $op): bool
    {
        if (!$op instanceof Op\Expr\FuncCall && !$op instanceof Op\Expr\NsFuncCall) {
            return false;
        }
        $name = strtolower($this->resolveCfgFuncCallName($op) ?? '');

        return \in_array($name, ['set_error_handler', 'restore_error_handler'], true);
    }

    /**
     * @param Op[] $ops
     */
    private function isConstFetchLoweredByFollowingEchoConcatFuncCall(array $ops, int $index): bool
    {
        $next = $ops[$index + 1] ?? null;
        if (!$next instanceof Op\Expr\FuncCall && !$next instanceof Op\Expr\NsFuncCall) {
            return false;
        }

        return $this->isFuncCallLoweredByFollowingEchoConcat($next, $ops, $index + 1);
    }

    /**
     * Stmt-level ?? consumed by a FuncCall before echo-concat lowering runs (#18315, re-#11601).
     *
     * @param Op[] $ops
     */
    private function stmtCoalesceFeedsFuncCallBeforeEcho(
        Op\Expr\BinaryOp\Coalesce $coalesce,
        Op $callOp,
        array $ops,
        int $coalesceIndex,
        int $callIndex
    ): bool {
        if (!property_exists($callOp, 'args') || !is_array($callOp->args)) {
            return false;
        }
        $resultOverride = $this->coalesceAssignLvalueOperand($coalesce);
        foreach ($callOp->args as $callArg) {
            if (
                null !== $callArg
                && !$this->isCallArgUnrelatedToPriorStmtCoalesce($callArg)
                && $this->callArgMatchesCoalesceExpressionValue($callArg, $coalesce, $resultOverride)
            ) {
                return true;
            }
        }
        $firstArg = $callOp->args[0] ?? null;
        if (
            null !== $firstArg
            && !$this->isCallArgUnrelatedToPriorStmtCoalesce($firstArg)
            && $this->onlyInlineCallArgProducersBetweenIndices($ops, $coalesceIndex, $callIndex)
            && (
                $this->callArgMatchesCoalesceExpressionValue($firstArg, $coalesce, $resultOverride)
                || $this->callArgIsDeadInlineTemporary($firstArg)
            )
        ) {
            return true;
        }

        return false;
    }

    /**
     * Copy ?? branch results into merge-block temps so concat reads live CVs (#10430, #9973).
     */
    private function materializeConcatListCoalesceParts(Op\Expr\ConcatList $concat, Block $block): Op\Expr\ConcatList
    {
        $parts = [];
        foreach ($concat->list as $part) {
            if ($part instanceof Operand\Literal) {
                $parts[] = $part;
                continue;
            }
            $readSlot = $this->compileOperand($part, $block, true);
            $fresh = new Operand\Temporary();
            $writeSlot = $block->forceFreshVarSlot($fresh);
            $assignOp = new OpCode(
                OpCode::TYPE_ASSIGN,
                $writeSlot,
                $writeSlot,
                $readSlot
            );
            $this->assignConcatListSourceMetadata($assignOp, $concat);
            $block->addOpCode($assignOp);
            $parts[] = $fresh;
        }
        $materialized = new Op\Expr\ConcatList($parts, $concat->getAttributes());
        $materialized->result = $concat->result;

        return $materialized;
    }

    /**
     * php-cfg may embed Expr (e.g. spaceship) only under ConcatList / echo without a separate block op (#3671).
     */
    private function isExprLoweredInBlock(Op\Expr $expr, Block $block): bool
    {
        if (null === $block->orig) {
            return false;
        }
        foreach ($block->orig->ops as $op) {
            if ($op === $expr) {
                return true;
            }
        }

        return false;
    }

    /**
     * Lower embedded expressions before reading operand slots (echo / concat paths).
     */
    private function compileEmbeddedExprForOperand(?Operand $operand, Block $block): void
    {
        if (null === $operand) {
            return;
        }
        if (!$operand instanceof Operand\Temporary || null === $operand->original) {
            return;
        }
        $original = $operand->original;
        if ($original instanceof Op\Expr && $this->isExprLoweredInBlock($original, $block)) {
            return;
        }
        if ($original instanceof Op\Expr\ConcatList) {
            $this->compileOp($original, $block);

            return;
        }
        if ($original instanceof Op\Expr) {
            $this->compileDeferredCoalesceBranchExpr($original, $block);
        }
    }

    private function compileConcatListPart(Operand $part, Block $block): int
    {
        $this->compileEmbeddedExprForOperand($part, $block);

        return $this->compileOperand($part, $block, true);
    }

    /**
     * CONCAT/CAST_STRING from encapsed ConcatList must carry the user site so
     * Undefined variable warnings do not inherit the prior statement's opline (#32034).
     *
     * php-src: Zend/zend_compile.c zend_compile_encapsed_string — FETCH_R lineno is the
     * interpolated expression, not the previous statement.
     */
    private function addConcatListOpCode(Block $block, OpCode $opcode, Op\Expr\ConcatList $concat): void
    {
        $this->assignConcatListSourceMetadata($opcode, $concat);
        $block->addOpCode($opcode);
    }

    private function assignConcatListSourceMetadata(OpCode $opcode, Op\Expr\ConcatList $concat): void
    {
        $this->assignSourceMetadata($opcode, $concat);
        $line = $this->concatListWarningLine($concat);
        if ($line <= 0) {
            return;
        }
        $loc = $opcode->sourceLocation;
        if (null !== $loc && $loc->startLine === $line) {
            return;
        }
        $opcode->sourceLocation = new SourceLocation(
            $loc?->docComment,
            $line,
            $loc?->endLine ?? max(0, (int) $concat->getAttribute('endLine', 0)),
            $loc?->filename ?? (string) $concat->getAttribute('filename', '')
        );
    }

    /**
     * Heredoc ConcatList startLine is the `<<<LABEL` opener; Zend FETCH_R cites the body
     * (php-parser String_::KIND_HEREDOC === 3, #32034).
     */
    private function concatListWarningLine(Op\Expr\ConcatList $concat): int
    {
        $start = max(0, $concat->getLine());
        $end = max(0, (int) $concat->getAttribute('endLine', 0));
        $kind = (int) $concat->getAttribute('kind', 0);
        if (3 === $kind && $end > $start && $start > 0) {
            return $start + 1;
        }

        return $start;
    }

    /** Concat destination must not alias an active catch variable slot (#17384). */
    private function concatResultSlotAliasesCatchVar(int $slot): bool
    {
        if ([] === $this->activeCatchVarSlotsByName) {
            return false;
        }

        return \in_array($slot, $this->activeCatchVarSlotsByName, true);
    }

    private function freshConcatResultSlotIfCatchAlias(int $slot, Block $block, Operand $result): int
    {
        if (!$this->concatResultSlotAliasesCatchVar($slot)) {
            return $slot;
        }

        return $block->forceFreshVarSlot($result);
    }

    /**
     * @return ?Op\Expr\BinaryOp\Coalesce
     */
    private function unwrapCoalesceExpr(Operand $operand): ?Op\Expr\BinaryOp\Coalesce
    {
        while ($operand instanceof Temporary) {
            if ($operand->original instanceof Op\Expr\BinaryOp\Coalesce) {
                return $operand->original;
            }
            if (null === $operand->original) {
                return null;
            }
            $operand = $operand->original;
        }
        if ($operand instanceof Op\Expr\BinaryOp\Coalesce) {
            return $operand;
        }

        return null;
    }

    private function operandsChainEqual(Operand $a, Operand $b): bool
    {
        while ($a instanceof Temporary) {
            if ($a === $b) {
                return true;
            }
            if (null === $a->original) {
                break;
            }
            $a = $a->original;
        }
        while ($b instanceof Temporary) {
            if ($b === $a) {
                return true;
            }
            if (null === $b->original) {
                break;
            }
            $b = $b->original;
        }

        return $a === $b;
    }

    private function findFuncCallFirstArgOperand(CfgFunc $func, string $name): ?Operand
    {
        $found = null;
        $walk = function ($node) use (&$walk, $name, &$found): void {
            if (null !== $found) {
                return;
            }
            if ($node instanceof Op\Expr\FuncCall) {
                $fn = $node->name;
                if ($fn instanceof Literal && $name === $fn->value && isset($node->args[0])) {
                    $found = $node->args[0];

                    return;
                }
            }
            if ($node instanceof CfgBlock) {
                foreach ($node->children as $child) {
                    $walk($child);
                }
            }
            if ($node instanceof Op\Stmt\JumpIf) {
                $walk($node->if);
                $walk($node->else);
            }
            if ($node instanceof Op\Stmt\Loop) {
                $walk($node->loop);
            }
            if ($node instanceof Op\Stmt\Foreach_) {
                $walk($node->loop);
            }
        };
        $walk($func->cfg);

        return $found;
    }

    protected function functionStaticStorageKey(\PHPCfg\Func $func, string $varName): string
    {
        if (((int) ($func->flags ?? 0)) & \PHPCfg\Func::FLAG_CLOSURE) {
            return $varName;
        }

        return $this->resolveFuncDisplayName($func)."\0".$varName;
    }

    protected function resolveFuncDisplayName(\PHPCfg\Func $func): string
    {
        $name = $func->name;
        if ($name instanceof Operand\Literal && is_string($name->value)) {
            $name = $name->value;
        }
        if (!is_string($name)) {
            $this->throwCompileLogic('Function name must be a string literal for static storage key (#2286)');
        }
        $class = $func->class;
        if ($class instanceof Operand\Literal && is_string($class->value)) {
            $class = $class->value;
        }
        if (null !== $class && !is_string($class)) {
            $this->throwCompileLogic('Function class must be a string literal for static storage key (#2286)');
        }

        return null !== $class ? $class.'::'.$name : $name;
    }

    /**
     * @param Op\Terminal\StaticVar $terminal
     *
     * @return array{0: list<OpCode>, 1: Block}
     */
    protected function compileFunctionStaticVar(Op\Terminal $terminal, Block $block): array
    {
        if (null === $block->func) {
            $this->throwCompileLogic('Function-local static requires a function context');
        }
        $varName = $this->resolveSimpleVariableName($terminal->var);
        $this->assertNoThisAsStaticVariable($varName, $terminal);
        $storageKey = $this->functionStaticStorageKey($block->func, $varName);
        $keyVar = new Variable(Variable::TYPE_STRING);
        $keyVar->string($storageKey);
        $keyOperand = new Operand\Literal($storageKey);
        $keyOperand->type = Type::string();
        $keySlot = $block->registerConstant($keyOperand, $keyVar);
        $localSlot = $this->compileOperand($terminal->var, $block, false);
        $declaredType = $this->staticVarDeclaredType($terminal);
        $typeSlot = null;
        if (null !== $declaredType) {
            $declType = $this->typeFromStaticVarDecl($terminal, $declaredType);
            $typeSlot = $this->compileTypeConstrainedVariable($block, $declType, $declaredType);
        }

        if (null === $terminal->defaultVar) {
            return [[$this->makeDeclareFunctionStaticOp(
                $localSlot,
                $keySlot,
                null,
                $typeSlot,
                $varName
            )], $block];
        }

        $defaultSlot = $this->tryFoldFunctionStaticDefaultSlot($terminal, $block);
        if (null !== $defaultSlot) {
            $defaultVm = $block->constants[$defaultSlot];
            if (!$this->isAllowedFunctionStaticDefaultType($defaultVm->type)) {
                $this->throwCompileLogic(
                    'Function-local static initializer must be a compile-time literal in v1 (#2286)'
                );
            }
            if (null !== $declaredType) {
                $this->assertCompileTimeDefaultMatchesDeclaredType(
                    $defaultVm,
                    $declaredType,
                    'static variable',
                    '$'.$varName,
                    $block,
                    $defaultSlot
                );
            }

            return [[$this->makeDeclareFunctionStaticOp(
                $localSlot,
                $keySlot,
                $defaultSlot,
                $typeSlot,
                $varName
            )], $block];
        }

        $this->assertFunctionStaticRuntimeInitAllowed($terminal);

        $continueBlock = new Block($block->orig);
        $continueBlock->func = $block->func;
        $continueBlock->inheritScopeFrom($block);

        // JUMPIF must precede New_/Array_ rematerialization. Compiling the defaultBlock first
        // left TYPE_NEW ahead of the initialized check, so every call allocated a discarded object
        // (#28040 companion — wastes work; frame-teardown refcount fix is in VM.php).
        $skipOp = new OpCode(
            OpCode::TYPE_JUMPIF_FUNCTION_STATIC_INITIALIZED,
            null,
            $keySlot
        );
        $skipOp->block1 = $continueBlock;
        $block->addOpCode($skipOp);

        if (null !== $terminal->defaultBlock) {
            // php-cfg places Array_/New_ in defaultBlock, not the function body. Rematerialize
            // so TYPE_ARG_SEND wires the INIT_ARRAY slot (not a dead mixed temp) (#22390, #8561).
            $this->compileDefaultBlockChildrenWithProducerCfg($terminal->defaultBlock, $block);
        }
        $initSlot = $this->compileOperand($terminal->defaultVar, $block, true);

        $storeOp = new OpCode(
            OpCode::TYPE_FUNCTION_STATIC_INIT_STORE,
            null,
            $keySlot,
            $initSlot
        );
        $storeOp->functionStaticTypeSlot = $typeSlot;
        $storeOp->functionStaticVarName = $varName;
        $jumpOp = new OpCode(OpCode::TYPE_JUMP);
        $jumpOp->block1 = $continueBlock;

        $continueBlock->addOpCode($this->makeDeclareFunctionStaticOp(
            $localSlot,
            $keySlot,
            null,
            $typeSlot,
            $varName
        ));
        $continueBlock->parents[] = $block;

        return [[$storeOp, $jumpOp], $continueBlock];
    }

    protected function staticVarDeclaredType(Op\Terminal\StaticVar $terminal): ?Op\Type
    {
        if (!property_exists($terminal, 'declaredType')) {
            return null;
        }

        return $terminal->declaredType;
    }

    protected function typeFromStaticVarDecl(Op\Terminal\StaticVar $terminal, ?Op\Type $declaredType = null): Type
    {
        $declaredType ??= $this->staticVarDeclaredType($terminal);
        if (null === $declaredType) {
            return Type::mixed();
        }
        if ($declaredType instanceof Op\Type\Literal) {
            return Type::fromDecl($declaredType->name);
        }

        return Type::fromTypeDecl($declaredType);
    }

    protected function makeDeclareFunctionStaticOp(
        int $localSlot,
        int $keySlot,
        ?int $defaultSlot,
        ?int $typeSlot,
        string $varName
    ): OpCode {
        $op = new OpCode(
            OpCode::TYPE_DECLARE_FUNCTION_STATIC,
            $localSlot,
            $keySlot,
            $defaultSlot
        );
        $op->functionStaticTypeSlot = $typeSlot;
        $op->functionStaticVarName = $varName;

        return $op;
    }

    /**
     * Reject non-constant function-static initializers on PHP &lt; 8.3 (#22923, #4352, #5478, #31168).
     *
     * php-cfg often places a bare `$param` on {@see Op\Terminal\StaticVar::$defaultVar} with an
     * empty {@see $defaultBlock}; walking children alone missed that shape and accepted it as a
     * runtime init (undefined-constant → string) on the 8.2 reference profile.
     *
     * First-class callables (`strlen(...)`) are not constant expressions on ≤8.2
     * ({@see Op\Expr\FirstClassCallable}); on 8.3+ they are legal arbitrary static initializers
     * (php-src `zend_compile_static_var` → `zend_compile_expr`, verified 8.3/8.4/8.5).
     *
     * @param Op\Terminal\StaticVar $terminal
     */
    protected function assertFunctionStaticRuntimeInitAllowed(Op\Terminal $terminal): void
    {
        // PHP 8.3+ RFC: arbitrary static variable initializers (Zend/zend_compile.c).
        // FCC / closures / runtime exprs are allowed here — not gated on closures-in-const-expr
        // (that RFC is for const/attr/param/property defaults, not function-static on 8.3+).
        if (CompilerVersion::supportsArbitraryStaticVariableInitializers()) {
            return;
        }
        if (
            null !== $terminal->defaultVar
            && $this->functionStaticInitOperandReferencesLocal($terminal->defaultVar)
        ) {
            $this->throwCompileLogic(
                'Constant expression contains invalid operations'
            );
        }
        if (null === $terminal->defaultBlock) {
            return;
        }
        foreach ($terminal->defaultBlock->children as $child) {
            if ($this->functionStaticInitReferencesLocal($child)) {
                $this->throwCompileLogic(
                    'Constant expression contains invalid operations'
                );
            }
        }
    }

    protected function functionStaticInitReferencesLocal(Op $op): bool
    {
        if ($op instanceof Op\Expr\Closure || $op instanceof Op\Expr\ArrowFunction) {
            return true;
        }
        // FCC is ZEND_AST_CALLABLE_CONVERT — not a const expr on ≤8.2 (#31168 / zend_compile.c).
        if ($op instanceof Op\Expr\FirstClassCallable) {
            return true;
        }
        if ($op instanceof Op\Expr\FuncCall || $op instanceof Op\Expr\MethodCall) {
            return true;
        }
        if ($op instanceof Op\Expr\Variable) {
            return true;
        }
        if ($op instanceof Op\Expr\ArrayDimFetch) {
            return $this->functionStaticInitExprOrOperandReferencesLocal($op->var)
                || (null !== $op->dim && $this->functionStaticInitOperandReferencesLocal($op->dim));
        }
        if ($op instanceof Op\Expr\PropertyFetch) {
            return $this->functionStaticInitExprOrOperandReferencesLocal($op->var)
                || $this->functionStaticInitOperandReferencesLocal($op->name);
        }
        if ($op instanceof Op\Expr\BinaryOp) {
            return $this->functionStaticInitOperandReferencesLocal($op->left)
                || $this->functionStaticInitOperandReferencesLocal($op->right);
        }
        if ($op instanceof Op\Expr\UnaryMinus || $op instanceof Op\Expr\UnaryPlus || $op instanceof Op\Expr\UnaryOp\BitwiseNot) {
            return $this->functionStaticInitOperandReferencesLocal($op->expr);
        }
        if ($op instanceof Op\Expr\New_) {
            foreach ($op->args as $arg) {
                if ($this->functionStaticInitOperandReferencesLocal($arg)) {
                    return true;
                }
            }

            return false;
        }
        if ($op instanceof Op\Expr\Array_) {
            $n = \count($op->values);
            for ($i = 0; $i < $n; ++$i) {
                if ($this->functionStaticInitOperandReferencesLocal($op->values[$i])) {
                    return true;
                }
                $key = $op->keys[$i] ?? null;
                if (null !== $key && $this->functionStaticInitOperandReferencesLocal($key)) {
                    return true;
                }
            }

            return false;
        }
        if ($op instanceof Op\Expr\ConstFetch || $op instanceof Op\Expr\ClassConstFetch) {
            return false;
        }

        return false;
    }

    protected function functionStaticInitExprOrOperandReferencesLocal(Op|Operand $node): bool
    {
        if ($node instanceof Op) {
            return $this->functionStaticInitReferencesLocal($node);
        }

        return $this->functionStaticInitOperandReferencesLocal($node);
    }

    protected function functionStaticInitOperandReferencesLocal(Operand $operand): bool
    {
        if ($operand instanceof Operand\Variable) {
            return true;
        }
        if ($operand instanceof Operand\Literal || $operand instanceof Operand\NullOperand) {
            return false;
        }
        if ($operand instanceof Operand\Temporary) {
            return false;
        }

        return false;
    }

    private function isAllowedFunctionStaticDefaultType(int $type): bool
    {
        return \in_array(
            $type,
            [
                Variable::TYPE_INTEGER,
                Variable::TYPE_STRING,
                Variable::TYPE_ARRAY,
                Variable::TYPE_BOOLEAN,
                Variable::TYPE_FLOAT,
                Variable::TYPE_NULL,
                Variable::TYPE_ENUM_CASE,
                Variable::TYPE_OBJECT,
            ],
            true
        );
    }

    /**
     * @param Op\Terminal\StaticVar $terminal
     */
    protected function tryFoldFunctionStaticDefaultSlot(Op\Terminal $terminal, Block $block): ?int
    {
        if (null === $terminal->defaultVar) {
            return null;
        }
        // Operand\Variable must not fold via unwrapCfgLiteralOperand (name → string "x") —
        // that mis-accepted `static $a = $param` as compile-time string on 8.2 (#22923).
        if ($this->functionStaticInitOperandReferencesLocal($terminal->defaultVar)) {
            return null;
        }
        // Share param-default folding (scalar/array literals, const fetch, unary, …) — Zend
        // zend_compile_static_variables() binds literals at compile time (#2286, #9351).
        $pseudo = new Op\Expr\Param(
            new Operand\Literal(''),
            new Op\Type\Mixed_(),
            false,
            false,
            $terminal->defaultVar,
            $terminal->defaultBlock
        );

        return $this->tryFoldParamDefaultSlot($pseudo, $block);
    }

    protected function tryBuildCompileTimeArrayFromExpr(
        Op\Expr\Array_ $expr,
        ?Block $block = null,
        array $defaultBlockChildren = [],
        bool $materializeEnumCase = false
    ): ?Variable
    {
        $unpackFlags = property_exists($expr, 'unpack') ? $expr->unpack : [];
        $byRefFlags = property_exists($expr, 'byRef') ? $expr->byRef : [];
        foreach ($byRefFlags as $refFlag) {
            if (!empty($refFlag)) {
                return null;
            }
        }
        $ht = new HashTable();
        $n = \count($expr->values);
        for ($i = 0; $i < $n; ++$i) {
            if (!empty($unpackFlags[$i])) {
                $spreadVm = $this->compileTimeVariableFromCfgArrayElement(
                    $expr->values[$i],
                    $block,
                    $defaultBlockChildren,
                    $materializeEnumCase
                );
                if (null === $spreadVm || !$spreadVm->is(Variable::TYPE_ARRAY)) {
                    return null;
                }
                $ht->spreadFrom($spreadVm->toArray());

                continue;
            }
            $valueVm = $this->compileTimeVariableFromCfgArrayElement(
                $expr->values[$i],
                $block,
                $defaultBlockChildren,
                $materializeEnumCase
            );
            if (null === $valueVm) {
                return null;
            }
            $keyOp = $expr->keys[$i] ?? null;
            if (null === $keyOp) {
                $ht->append($valueVm);
                continue;
            }
            if ($keyOp instanceof Operand\NullOperand) {
                $ht->append($valueVm);
                continue;
            }
            if ($keyOp instanceof Operand\Literal && null === $keyOp->value) {
                $ht->update('', $valueVm);
                continue;
            }
            $keyVm = $this->vmVariableFromCfgLiteralOperand($keyOp);
            if (null === $keyVm && null !== $block && [] !== $defaultBlockChildren) {
                $keyVm = $this->tryFoldCompileTimeOperandDefault(
                    $keyOp,
                    $block,
                    $defaultBlockChildren,
                    $materializeEnumCase
                );
            }
            if (null === $keyVm) {
                return null;
            }
            if ($keyVm->is(Variable::TYPE_INTEGER) || $keyVm->is(Variable::TYPE_FLOAT)) {
                $ht->updateIndex($keyVm->toInt(), $valueVm);
            } elseif ($keyVm->is(Variable::TYPE_STRING)) {
                $ht->update($keyVm->toString(), $valueVm);
            } elseif ($keyVm->is(Variable::TYPE_BOOLEAN)) {
                $ht->updateIndex($keyVm->toBool() ? 1 : 0, $valueVm);
            } elseif ($keyVm->is(Variable::TYPE_NULL)) {
                $ht->update('', $valueVm);
            } else {
                return null;
            }
        }
        $vmArray = new Variable(Variable::TYPE_ARRAY);
        $vmArray->array($ht);

        return $vmArray;
    }

    protected function compileTimeVariableFromCfgArrayElement(
        Operand $operand,
        ?Block $block = null,
        array $defaultBlockChildren = [],
        bool $materializeEnumCase = false
    ): ?Variable {
        $vm = $this->vmVariableFromCfgLiteralOperand($operand);
        if (null !== $vm) {
            return $vm;
        }
        if (null !== $block && [] !== $defaultBlockChildren) {
            $vm = $this->tryFoldCompileTimeOperandDefault(
                $operand,
                $block,
                $defaultBlockChildren,
                $materializeEnumCase
            );
            if (null !== $vm) {
                return $vm;
            }
        }
        $nested = $this->unwrapCfgArrayExprOperand($operand);
        if (null !== $nested) {
            return $this->tryBuildCompileTimeArrayFromExpr(
                $nested,
                $block,
                $defaultBlockChildren,
                $materializeEnumCase
            );
        }

        return null;
    }

    protected function unwrapCfgArrayExprOperand(Operand $operand): ?Op\Expr\Array_
    {
        while ($operand instanceof Operand\Temporary && null !== $operand->original) {
            $operand = $operand->original;
        }

        return $operand instanceof Op\Expr\Array_ ? $operand : null;
    }

    protected function vmVariableFromCfgLiteralOperand(Operand $operand): ?Variable
    {
        // Named CVs / BoundVariable($this) unwrap to Literal(name) via unwrapCfgLiteralOperand —
        // that is the variable *name*, not a compile-time value. Folding it registers string
        // constants on the CV slot (e.g. const "this" on $this) so call-arg reads see a string
        // instead of the object (#28049, #28038, #22923).
        if (null !== Block::resolveVariableName($operand)) {
            return null;
        }
        $literal = $this->unwrapCfgLiteralOperand($operand);
        if (null === $literal) {
            return null;
        }
        $mappedType = Variable::mapFromType($literal->type ?? Type::mixed());
        if (Variable::TYPE_UNDEFINED === $mappedType) {
            if (\is_int($literal->value)) {
                $mappedType = Variable::TYPE_INTEGER;
            } elseif (\is_float($literal->value)) {
                $mappedType = Variable::TYPE_FLOAT;
            } elseif (\is_string($literal->value)) {
                $mappedType = Variable::TYPE_STRING;
            } elseif (\is_bool($literal->value)) {
                $mappedType = Variable::TYPE_BOOLEAN;
            } elseif (null === $literal->value) {
                $mappedType = Variable::TYPE_NULL;
            }
        }
        $return = new Variable($mappedType);
        switch ($mappedType) {
            case Variable::TYPE_STRING:
                $return->string($literal->value, true);
                break;
            case Variable::TYPE_INTEGER:
                $return->int($literal->value);
                break;
            case Variable::TYPE_FLOAT:
                $return->float($literal->value);
                break;
            case Variable::TYPE_BOOLEAN:
                $return->bool($literal->value);
                break;
            case Variable::TYPE_NULL:
                break;
            default:
                return null;
        }

        return $return;
    }

    protected function unwrapCfgLiteralOperand(Operand $operand): ?Operand\Literal
    {
        while ($operand instanceof Operand\Temporary && null !== $operand->original) {
            $operand = $operand->original;
        }
        while ($operand instanceof Operand\Variable) {
            $operand = $operand->name;
            while ($operand instanceof Operand\Temporary && null !== $operand->original) {
                $operand = $operand->original;
            }
        }

        return $operand instanceof Operand\Literal ? $operand : null;
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
     * @return ?array{0: int, 1: ?int}
     */
    protected function resolveCoalesceIssetTarget(Operand $operand, Block $block): ?array
    {
        $fetch = $this->findCoalesceArrayDimFetch($operand, $block);
        if (null !== $fetch) {
            return $this->resolveIssetTargetFromArrayDimFetch($fetch, $block);
        }
        $propFetch = $this->findCoalescePropertyFetch($operand, $block);
        if (null !== $propFetch) {
            return $this->resolveIssetTargetFromPropertyFetch($propFetch, $block);
        }
        $staticPropFetch = $this->findCoalesceStaticPropertyFetch($operand, $block);
        if (null !== $staticPropFetch) {
            return $this->resolveIssetTargetFromStaticPropertyFetch($staticPropFetch, $block);
        }
        if (null !== $this->unwrapVariableOperand($operand)) {
            return $this->resolveIssetTarget($operand, $block);
        }

        return null;
    }

    /**
     * @return ?Op\Expr\ArrayDimFetch
     */
    /**
     * php-cfg emits PropertyFetch before Empty_; recover operand when Empty_.expr is cleared (#4701, #6829).
     */
    private function recoverEmptyExprOperand(Op\Expr\Empty_ $expr, Block $block): ?Operand
    {
        if (null !== $expr->expr) {
            return $expr->expr;
        }
        foreach ($block->orig->children as $child) {
            if ($child instanceof Op\Expr\PropertyFetch && $this->isPropertyFetchOnlyEmptyVar($child, $expr, $block)) {
                return $child->result;
            }
            if ($child instanceof Op\Expr\StaticPropertyFetch && $this->isStaticPropertyFetchOnlyEmptyVar($child, $expr, $block)) {
                return $child->result;
            }
            if ($child instanceof Op\Expr\ArrayDimFetch && $this->isArrayDimFetchOnlyEmptyVar($child, $expr, $block)) {
                return $child->result;
            }
        }
        $funcCallFetch = $this->recoverEmptyPropertyFetchForFuncCallArg($expr, $block);
        if (null !== $funcCallFetch) {
            return $funcCallFetch;
        }

        return null;
    }

    /**
     * PropertyFetch hoisted before FuncCall(empty($obj->prop)) when php-cfg omits Empty_ stmt (#8901).
     */
    private function recoverEmptyPropertyFetchForFuncCallArg(Op\Expr\Empty_ $expr, Block $block): ?Operand
    {
        if (null === $block->orig) {
            return null;
        }
        $children = $block->orig->children;
        foreach ($children as $i => $child) {
            if (!$this->isInlineExprCallArgConsumer($child) || !$this->funcCallArgReferencesEmpty($child, $expr)) {
                continue;
            }
            for ($j = $i - 1; $j >= 0; --$j) {
                $prev = $children[$j];
                if ($prev instanceof Op\Expr\PropertyFetch && $this->emptyExprDependsOnOperand($expr, $prev->result, $block)) {
                    return $prev->result;
                }
                if ($prev === $expr) {
                    continue;
                }
                if ($prev instanceof Op\Expr && $this->isInlineExprCallArgProducer($prev)) {
                    continue;
                }
                break;
            }
        }

        return null;
    }

    private function funcCallArgReferencesEmpty(Op $call, Op\Expr\Empty_ $empty): bool
    {
        if (!property_exists($call, 'args') || !is_array($call->args)) {
            return false;
        }
        foreach ($call->args as $arg) {
            if ($arg instanceof Operand\Temporary && $arg->original === $empty) {
                return true;
            }
            if ($this->operandsReferToSameVariable($arg, $empty->result)) {
                return true;
            }
        }

        return false;
    }

    private function emptyExprDependsOnOperand(Op\Expr\Empty_ $expr, Operand $operand, Block $block): bool
    {
        $target = $this->unaryExprOperandForRead($expr, $block) ?? $expr->expr;
        if (null === $target) {
            return false;
        }
        if ($target === $operand) {
            return true;
        }

        return $this->operandsReferToSameVariable($target, $operand);
    }

    /**
     * @return ?Op\Expr\Empty_
     */
    private function findEmptyExprForCallArg(Operand $arg, Block $block): ?Op\Expr\Empty_
    {
        $empty = $this->unwrapEmptyExpr($arg);
        if (null !== $empty) {
            return $empty;
        }
        if (null === $block->orig) {
            return null;
        }
        foreach ($block->orig->children as $child) {
            if ($child instanceof Op\Expr\Empty_ && $this->operandsReferToSameVariable($child->result, $arg)) {
                return $child;
            }
        }
        $callSite = $this->findCfgCallSiteForArg($block->orig->children, $arg);
        if (null === $callSite) {
            return null;
        }
        [$callOp, $argIndex] = $callSite;
        if (!property_exists($callOp, 'args') || !is_array($callOp->args)) {
            return null;
        }
        $callArg = $callOp->args[$argIndex] ?? null;
        if (null === $callArg) {
            return null;
        }

        return $this->unwrapEmptyExpr($callArg);
    }

    /**
     * @return ?Op\Expr\Empty_
     */
    private function unwrapEmptyExpr(Operand $operand): ?Op\Expr\Empty_
    {
        if ($operand instanceof Op\Expr\Empty_) {
            return $operand;
        }
        if ($operand instanceof Operand\Temporary) {
            if ($operand->original instanceof Op\Expr\Empty_) {
                return $operand->original;
            }
            if (null !== $operand->original) {
                return $this->unwrapEmptyExpr($operand->original);
            }
        }

        return null;
    }

    /**
     * FuncCall(empty($obj->prop)) — compile hoisted Empty_ when php-cfg left the arg slot dead (#8901).
     */
    private function compileHoistedEmptyCallArg(Operand $arg, Block $block): ?int
    {
        $empty = $this->findEmptyExprForCallArg($arg, $block);
        if (null === $empty) {
            return null;
        }
        if (!$this->emptyExprLoweringEmitted($block, $empty)) {
            foreach ($this->compileExpr($empty, $block) as $op) {
                $block->addOpCode($op);
            }
        }

        return $this->compileOperand($empty->result, $block, true);
    }

    /**
     * php-cfg dead call-arg temps for hoisted isset()/empty() — map to producer result slot (#11498).
     *
     * Sibling isset()/empty() before another expression in the same array literal must not steal
     * that call's literal/CV args (#25188).
     */
    private function resolveHoistedIssetOrEmptyCallArgSlot(
        Operand $arg,
        Block $block,
        ?Op $cfgCallOp,
        int $argIndex
    ): ?int {
        if (null === $cfgCallOp || null === $block->orig) {
            return null;
        }
        $producer = $this->findHoistedIssetOrEmptyProducerForCallArg($block, $cfgCallOp, $argIndex);
        if (null === $producer) {
            return null;
        }
        $callArg = $cfgCallOp->args[$argIndex] ?? $arg;
        $argIsProducerResult = $this->operandsReferToSameVariable($producer->result, $callArg);
        $argIsDeadTemp = $this->callArgIsDeadInlineTemporary($callArg);
        if (!$argIsDeadTemp && !$argIsProducerResult) {
            // [isset($a['x']), array_key_exists('x', $a)] — preceding Isset_ is a sibling array
            // element, not this call's arg; keep LITERAL/CV wiring (#25188).
            return null;
        }
        if (
            $argIsDeadTemp
            && !$argIsProducerResult
            && !$this->issetOrEmptyProducerIsImmediateCallPrelude($producer, $cfgCallOp, $block)
        ) {
            // isset() && … as call arg — php-cfg dead temp is && merge, not hoisted Isset_ (#10704).
            return null;
        }
        if ($producer instanceof Op\Expr\Isset_ && 1 === count($producer->vars)) {
            $nullsafeChain = $this->collectNullsafePropertyFetchChain($producer->vars[0], $block);
            if ([] !== $nullsafeChain) {
                $existingSlot = $block->slotForOperand($producer->result);
                if (null !== $existingSlot) {
                    return $existingSlot;
                }
            }
        }
        if ($producer instanceof Op\Expr\Empty_) {
            $nullsafeChain = $this->collectNullsafePropertyFetchChainForEmpty($producer, $block);
            if ([] !== $nullsafeChain) {
                $existingSlot = $block->slotForOperand($producer->result);
                if (null !== $existingSlot) {
                    return $existingSlot;
                }
            }
        }
        if ($producer instanceof Op\Expr\Isset_ && !$this->issetExprLoweringEmitted($block, $producer)) {
            foreach ($this->compileExpr($producer, $block) as $op) {
                $block->addOpCode($op);
            }
        }
        if ($producer instanceof Op\Expr\Empty_ && !$this->emptyExprLoweringEmitted($block, $producer)) {
            foreach ($this->compileExpr($producer, $block) as $op) {
                $block->addOpCode($op);
            }
        }

        return $this->slotForEmittedIssetOrEmptyProducer($block, $producer)
            ?? $this->compileOperand($producer->result, $block, true);
    }

    /**
     * @return Op\Expr\Isset_|Op\Expr\Empty_|null
     */
    private function findHoistedIssetOrEmptyProducerForCallArg(
        Block $block,
        Op $cfgCallOp,
        int $argIndex
    ): ?Op\Expr {
        $callIndex = $this->cfgCallOpIndexInChildren($block->orig->children, $cfgCallOp, $block->orig);
        if (null === $callIndex) {
            return null;
        }
        $producers = $this->precedingInlineCallArgProducersBeforeCfgOp($block->orig->children, $cfgCallOp);
        $callArgs = property_exists($cfgCallOp, 'args') && is_array($cfgCallOp->args) ? $cfgCallOp->args : [];
        if (\count($producers) === \count($callArgs) && isset($producers[$argIndex])) {
            $candidate = $producers[$argIndex];
            if ($candidate instanceof Op\Expr\Isset_ || $candidate instanceof Op\Expr\Empty_) {
                return $candidate;
            }
        }
        $matched = $this->matchInlineCallArgProducer($producers, $callArgs, $argIndex, $cfgCallOp);
        if ($matched instanceof Op\Expr\Isset_ || $matched instanceof Op\Expr\Empty_) {
            return $matched;
        }
        // var_dump(property_exists(...), isset(...)) — producers align 1:1; arg #0 is FuncCall,
        // not the trailing Isset_. Do not map hoisted[$argIndex] onto isset for earlier args (#15646).
        if (\count($producers) === \count($callArgs)) {
            return null;
        }
        $hoisted = [];
        for ($i = $callIndex - 1; $i >= 0; --$i) {
            $child = $block->orig->children[$i];
            if ($child instanceof Op\Expr\Isset_ || $child instanceof Op\Expr\Empty_) {
                array_unshift($hoisted, $child);
                continue;
            }
            if ($child instanceof Op\Expr\ConstFetch) {
                continue;
            }
            break;
        }
        $producer = $hoisted[$argIndex] ?? null;

        return ($producer instanceof Op\Expr\Isset_ || $producer instanceof Op\Expr\Empty_) ? $producer : null;
    }

    /**
     * var_dump(isset(['a'=>1]['a'])) — php-cfg dead arg temp ≠ Isset_.result (#16462).
     */
    private function issetOrEmptyProducerIsImmediateCallPrelude(
        Op\Expr $producer,
        Op $cfgCallOp,
        Block $block
    ): bool {
        if (null === $block->orig) {
            return false;
        }
        $callIndex = $this->cfgCallOpIndexInChildren($block->orig->children, $cfgCallOp, $block->orig);
        if (null === $callIndex || $callIndex < 1) {
            return false;
        }
        for ($i = $callIndex - 1; $i >= 0; --$i) {
            $child = $block->orig->children[$i];
            if ($child instanceof Op\Expr\Isset_ || $child instanceof Op\Expr\Empty_) {
                return $child === $producer;
            }
            if ($child instanceof Op\Expr\ArrayDimFetch) {
                continue;
            }
            if ($child instanceof Op\Expr\ConstFetch || $child instanceof Op\Expr\ClassConstFetch) {
                continue;
            }
            break;
        }

        return false;
    }

    /**
     * Recover lowered isset()/empty() result slots when php-cfg dead arg temps omit dataflow (#11498).
     */
    private function slotForEmittedIssetOrEmptyProducer(Block $block, Op\Expr $producer): ?int
    {
        $slot = $block->slotForOperand($producer->result);
        if (null !== $slot) {
            return $slot;
        }
        if ($producer instanceof Op\Expr\Isset_) {
            for ($i = \count($block->opCodes) - 1; $i >= 0; --$i) {
                $op = $block->opCodes[$i];
                if (OpCode::TYPE_ISSET === $op->type) {
                    return $op->arg1;
                }
            }
        }
        if ($producer instanceof Op\Expr\Empty_) {
            for ($i = \count($block->opCodes) - 1; $i >= 0; --$i) {
                $op = $block->opCodes[$i];
                if (OpCode::TYPE_EMPTY === $op->type
                    || OpCode::TYPE_EMPTY_OBJECT_PROPERTY === $op->type
                    || OpCode::TYPE_EMPTY_STATIC_PROPERTY === $op->type
                    || OpCode::TYPE_EMPTY_DIMENSION === $op->type) {
                    return $op->arg1;
                }
            }
        }

        return null;
    }

    private function emptyExprLoweringEmitted(Block $block, Op\Expr\Empty_ $empty): bool
    {
        $slot = $block->slotForOperand($empty->result);
        if (null === $slot) {
            return false;
        }
        foreach ($block->opCodes as $op) {
            if ($op->arg1 !== $slot) {
                continue;
            }
            if (OpCode::TYPE_EMPTY === $op->type
                || OpCode::TYPE_EMPTY_OBJECT_PROPERTY === $op->type
                || OpCode::TYPE_EMPTY_STATIC_PROPERTY === $op->type
                || OpCode::TYPE_EMPTY_DIMENSION === $op->type) {
                return true;
            }
        }

        return false;
    }

    private function issetExprLoweringEmitted(Block $block, Op\Expr\Isset_ $expr): bool
    {
        $slot = $block->slotForOperand($expr->result);
        if (null === $slot) {
            return false;
        }
        foreach ($block->opCodes as $op) {
            if ($op->arg1 !== $slot) {
                continue;
            }
            if (OpCode::TYPE_ISSET === $op->type) {
                return true;
            }
        }

        return false;
    }

    /**
     * php-cfg may clear Empty_/BooleanNot->expr after SSA phi replaceWith; recover read operand (#6829).
     */
    private function unaryExprOperandForRead(Op\Expr $expr, Block $block): ?Operand
    {
        if (null !== $expr->expr) {
            return $expr->expr;
        }
        if ($expr instanceof Op\Expr\Empty_) {
            return $this->recoverEmptyExprOperand($expr, $block);
        }
        if ($expr instanceof Op\Expr\BooleanNot) {
            return $this->recoverBooleanNotExprOperand($expr, $block);
        }

        return null;
    }

    private function compileUnaryExprReadOperand(Op\Expr $expr, Block $block): ?int
    {
        $operand = $this->unaryExprOperandForRead($expr, $block);

        return null !== $operand ? $this->compileOperand($operand, $block, true) : null;
    }

    /**
     * BooleanNot.expr cleared while JumpIf still uses result — find negated operand (#6829).
     */
    private function recoverBooleanNotExprOperand(Op\Expr\BooleanNot $expr, Block $block): ?Operand
    {
        $func = $block->func;
        if (null === $func?->cfg) {
            return null;
        }
        $line = $expr->getLine();
        $nearest = null;
        $nearestLine = -1;
        $walk = function ($node) use (&$walk, $line, &$nearest, &$nearestLine): void {
            if ($node instanceof Op\Expr\Assign && $node->getLine() <= $line && $node->getLine() > $nearestLine) {
                $nearestLine = $node->getLine();
                $nearest = $node->var;
            }
            if ($node instanceof CfgBlock) {
                foreach ($node->children as $child) {
                    $walk($child);
                }
            }
            if ($node instanceof Op\Stmt\JumpIf) {
                $walk($node->if);
                $walk($node->else);
            }
        };
        $walk($func->cfg);

        return $nearest;
    }

    protected function findCoalesceArrayDimFetch(?Operand $operand, Block $block): ?Op\Expr\ArrayDimFetch
    {
        if (null === $operand) {
            return null;
        }
        $direct = $this->unwrapArrayDimFetch($operand);
        if (null !== $direct) {
            return $direct;
        }
        foreach ($block->orig->children as $child) {
            if ($child instanceof Op\Expr\ArrayDimFetch && $child->result === $operand) {
                return $child;
            }
        }

        return null;
    }

    /**
     * @return ?Op\Expr\PropertyFetch
     */
    protected function findCoalescePropertyFetch(?Operand $operand, Block $block): ?Op\Expr\PropertyFetch
    {
        if (null === $operand) {
            return null;
        }
        $direct = $this->unwrapPropertyFetch($operand);
        if (null !== $direct) {
            return $direct;
        }
        $candidates = [$operand];
        $seen = [];
        while ([] !== $candidates) {
            $current = array_shift($candidates);
            if (isset($seen[spl_object_id($current)])) {
                continue;
            }
            $seen[spl_object_id($current)] = true;
            foreach ($block->orig->children as $child) {
                if ($child instanceof Op\Expr\PropertyFetch && $child->result === $current) {
                    return $child;
                }
            }
            if ($current instanceof Temporary && null !== $current->original) {
                $candidates[] = $current->original;
            }
        }

        return null;
    }

    /**
     * @return ?Op\Expr\StaticPropertyFetch
     */
    protected function findCoalesceStaticPropertyFetch(?Operand $operand, Block $block): ?Op\Expr\StaticPropertyFetch
    {
        if (null === $operand) {
            return null;
        }
        $direct = $this->unwrapStaticPropertyFetch($operand);
        if (null !== $direct) {
            return $direct;
        }
        $candidates = [$operand];
        $seen = [];
        while ([] !== $candidates) {
            $current = array_shift($candidates);
            if (isset($seen[spl_object_id($current)])) {
                continue;
            }
            $seen[spl_object_id($current)] = true;
            foreach ($block->orig->children as $child) {
                if ($child instanceof Op\Expr\StaticPropertyFetch && $child->result === $current) {
                    return $child;
                }
            }
            if ($current instanceof Temporary && null !== $current->original) {
                $candidates[] = $current->original;
            }
        }

        return null;
    }

    /**
     * @return array{0: int, 1: ?int}
     */
    protected function resolveIssetTargetFromPropertyFetch(Op\Expr\PropertyFetch $fetch, Block $block): array
    {
        return [
            $this->compileOperand($fetch->var, $block, true),
            $this->compileOperand($fetch->name, $block, true),
        ];
    }

    /**
     * @return array{0: int, 1: ?int}
     */
    protected function resolveIssetTargetFromStaticPropertyFetch(
        Op\Expr\StaticPropertyFetch $fetch,
        Block $block
    ): array {
        return [
            $this->compileClassNameOperand($fetch->class, $block),
            $this->compileStaticPropertyNameSlot($fetch->name, $fetch->class, $block),
        ];
    }

    /**
     * @return array{0: int, 1: ?int}
     */
    protected function resolveIssetTargetFromArrayDimFetch(Op\Expr\ArrayDimFetch $fetch, Block $block): array
    {
        return [
            $this->compileOperand($fetch->var, $block, true),
            null !== $fetch->dim ? $this->compileOperand($fetch->dim, $block, true) : null,
        ];
    }

    protected function makeIssetOpCode(
        int $resultSlot,
        int $containerSlot,
        ?int $dimSlot,
        bool $issetOnProperty
    ): OpCode {
        $op = new OpCode(OpCode::TYPE_ISSET, $resultSlot, $containerSlot, $dimSlot);
        $op->issetOnProperty = $issetOnProperty;

        return $op;
    }

    protected function unwrapVariableOperand(Operand $operand): ?Operand\Variable
    {
        while ($operand instanceof Temporary) {
            if ($operand->original instanceof Operand\Variable) {
                return $operand->original;
            }
            if (null === $operand->original) {
                return null;
            }
            $operand = $operand->original;
        }
        if ($operand instanceof Operand\Variable) {
            return $operand;
        }

        return null;
    }

    /**
     * isset($a, $b, …) with short-circuit evaluation (PHP semantics).
     * Returns the block where compilation should continue.
     */
    protected function compileIssetMulti(Op\Expr\Isset_ $expr, Block $block): Block
    {
        $resultSlot = $this->compileOperand($expr->result, $block, false);
        $falseSlot = $this->compileBoolConstant($block, false);
        $endBlock = new Block($block->orig);
        $endBlock->inheritUndefinedLocals = true;
        $endBlock->inheritScopeFrom($block);
        $falseBlock = new Block($block->orig);
        $falseBlock->inheritUndefinedLocals = true;
        $falseBlock->inheritScopeFrom($block);
        $falseBlock->addOpCode(new OpCode(
            OpCode::TYPE_ASSIGN,
            $resultSlot,
            $resultSlot,
            $falseSlot
        ));
        $falseJump = new OpCode(OpCode::TYPE_JUMP);
        $falseJump->block1 = $endBlock;
        $falseBlock->addOpCode($falseJump);
        $endBlock->parents[] = $falseBlock;

        $current = $block;
        $vars = $expr->vars;
        $last = count($vars) - 1;
        foreach ($vars as $i => $var) {
            $this->assertIssetVariableOperand($var, $block);
            $propFetch = $this->findCoalescePropertyFetch($var, $block);
            $staticPropFetch = null !== $propFetch
                ? null
                : $this->findCoalesceStaticPropertyFetch($var, $block);
            $dimFetch = null !== $propFetch || null !== $staticPropFetch
                ? null
                : $this->findCoalesceArrayDimFetch($var, $block);
            [$containerSlot, $dimSlot] = null !== $propFetch
                ? $this->resolveIssetTargetFromPropertyFetch($propFetch, $current)
                : (null !== $staticPropFetch
                    ? $this->resolveIssetTargetFromStaticPropertyFetch($staticPropFetch, $current)
                    : (null !== $dimFetch
                        ? $this->resolveIssetTargetFromArrayDimFetch($dimFetch, $current)
                        : $this->resolveIssetTarget($var, $current)));
            $checkSlot = $resultSlot;
            if ($i < $last) {
                $checkSlot = $this->compileBoolTemporary($current);
            }
            if (null === $containerSlot) {
                $varSlot = $this->compileOperand($var, $current, true);
                $current->addOpCode(new OpCode(OpCode::TYPE_ISSET, $checkSlot, $varSlot, null));
            } else {
                $issetOp = $this->makeIssetOpCode(
                    $checkSlot,
                    $containerSlot,
                    $dimSlot,
                    null !== $propFetch
                );
                if (null !== $staticPropFetch) {
                    $issetOp->issetOnStaticProperty = true;
                }
                $current->addOpCode($issetOp);
            }
            if ($i < $last) {
                $next = new Block($block->orig);
                $next->inheritUndefinedLocals = true;
                $next->inheritScopeFrom($current);
                $jump = new OpCode(OpCode::TYPE_JUMPIF, $checkSlot);
                $jump->block1 = $next;
                $jump->block2 = $falseBlock;
                $next->parents[] = $current;
                $falseBlock->parents[] = $current;
                $current->addOpCode($jump);
                $current = $next;
            }
        }

        $doneJump = new OpCode(OpCode::TYPE_JUMP);
        $doneJump->block1 = $endBlock;
        $current->addOpCode($doneJump);
        $endBlock->parents[] = $current;

        return $endBlock;
    }

    protected function compileBoolTemporary(Block $block): int
    {
        $operand = new Temporary;
        $operand->type = Type::bool();
        // JIT assignOperandValue skips operands with empty usages (#99 coalesce branches).
        $operand->usages[] = $operand;

        return $block->getVarSlot($operand, false);
    }

    protected function compileBoolConstant(Block $block, bool $value): int
    {
        $var = new Variable(Variable::TYPE_BOOLEAN);
        $var->bool($value);
        $operand = new Operand\Temporary;
        $operand->type = Type::bool();

        return $block->registerConstant($operand, $var);
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

    /** Sole write-op for a Temporary when php-cfg left a single producer in $operand->ops (#19439). */
    private function soleWriteExprForOperand(Operand $operand): ?Op\Expr
    {
        if (!isset($operand->ops) || !\is_array($operand->ops) || 1 !== \count($operand->ops)) {
            return null;
        }
        $write = $operand->ops[0] ?? null;

        return $write instanceof Op\Expr ? $write : null;
    }

    /**
     * Hoisted enum case fetches already feeding an array literal must not be reused for later calls (#8749).
     */
    private function hoistedEnumCaseFetchConsumedInCfg(Op\Expr\ClassConstFetch $fetch, Block $block): bool
    {
        if (null === $block->orig) {
            return false;
        }
        foreach ($block->orig->children as $child) {
            if ($child === $fetch || $child instanceof Op\Expr\ClassConstFetch) {
                continue;
            }
            if ($child instanceof Op\Expr\Assign) {
                if ($this->operandsReferToSameVariable($child->expr, $fetch->result)) {
                    return true;
                }

                continue;
            }
            if ($child instanceof Op\Expr && $this->cfgExprUsesOperand($child, $fetch->result)) {
                return true;
            }
        }

        return false;
    }

    /**
     * php-cfg hoists `E::A` before `E::A::class` when the case fetch only feeds `::class` (#9426, #9518).
     *
     * @param list<Op> $ops
     */
    private function isHoistedEnumCaseFetchOnlyForCaseClassPseudoConst(
        Op\Expr\ClassConstFetch $fetch,
        array $ops,
        int $index,
        Block $block
    ): bool {
        if (!$this->isCompileTimeEnumCaseClassConstFetch($fetch, $block)) {
            return false;
        }
        for ($j = $index + 1, $n = \count($ops); $j < $n; ++$j) {
            $later = $ops[$j];
            if (!$later instanceof Op\Expr\ClassConstFetch) {
                continue;
            }
            $pseudo = $this->staticNameFromOperand($later->name);
            if (null === $pseudo || 'class' !== strtolower($pseudo)) {
                continue;
            }
            if ($this->operandsReferToSameVariable($later->class, $fetch->result)) {
                return true;
            }
        }

        return false;
    }

    /** True when php-cfg left the operand as an embedded literal in the FuncCall. */
    private function isEmbeddedCallLiteralArg(?Operand $arg): bool
    {
        if (null === $arg) {
            return false;
        }
        // BoundVariable / named CV temps unwrap to Literal(name) via unwrapCfgLiteralOperand —
        // that is the variable *name*, not an embedded call-arg literal. Treating them as
        // literals forceFreshVarSlot's an empty slot and breaks function-static (and any named
        // local) args to builtins like count/implode/json_encode (#28038, re-#15914).
        if (null !== Block::resolveVariableName($arg)) {
            return false;
        }
        if (null !== $this->unwrapCfgLiteralOperand($arg)) {
            return true;
        }
        $root = $this->unwrapOperandChain($arg);
        if ($root instanceof Op\Expr\ClassConstFetch) {
            $name = $this->staticNameFromOperand($root->name);
            if (null !== $name && 'class' === strtolower($name)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Call args that consume a hoisted ClassConstFetch slot skip embedded literals and inline enum fetches (#8933).
     */
    private function callArgUsesHoistedEnumPreludeSlot(?Operand $callArg): bool
    {
        if (null === $callArg || $this->isEmbeddedCallLiteralArg($callArg)) {
            return false;
        }
        // Named CVs / arrow Phi captures are not enum/ConstFetch prelude slots (#31720).
        if (null !== Block::resolveVariableName($callArg)) {
            return false;
        }
        // extract([...], flags: EXTR_SKIP) — array arg must not steal hoisted ConstFetch (#16539).
        if ($this->callArgOperandExpectsArrayProducer($callArg)) {
            return false;
        }
        $root = $this->unwrapOperandChain($callArg);
        if ($root instanceof Temporary) {
            return true;
        }

        // php-cfg dead call-arg Variable temps (e.g. var_dump(E::A::class); #9426).
        return $root instanceof Operand\Variable && !$this->isNamedVariableOperand($callArg);
    }

    /**
     * True when a true/false/null ConstFetch sits immediately before the call (hoisted prelude).
     * Used to keep named CV args on compileOperand beside sibling null literals (#31720).
     */
    private function callHasTrailingHoistedBoolNullConstFetch(Op $cfgCallOp, Block $block): bool
    {
        if (null === $block->orig) {
            return false;
        }
        $callIndex = $this->cfgCallOpIndexInChildren($block->orig->children, $cfgCallOp, $block->orig);
        if (!\is_int($callIndex) || $callIndex < 1) {
            return false;
        }
        for ($i = $callIndex - 1; $i >= 0 && $callIndex - $i <= 8; --$i) {
            $prev = $block->orig->children[$i] ?? null;
            if ($prev instanceof Op\Expr\ConstFetch) {
                $name = $this->staticNameFromOperand($prev->name);

                return null !== $name
                    && \in_array(strtolower($name), ['true', 'false', 'null'], true);
            }
            break;
        }

        return false;
    }

    /**
     * Hoisted ConstFetch / ClassConstFetch / UnaryMinus|Plus stmts immediately before a call (#15899, #16523).
     *
     * @return list<Op\Expr\ConstFetch|Op\Expr\ClassConstFetch|Op\Expr\MagicScriptConst|Op\Expr\UnaryMinus|Op\Expr\UnaryPlus>
     */
    private function hoistedPreludeProducersImmediatelyBeforeCall(Op $callOp, Block $block): array
    {
        if (null === $block->orig) {
            return [];
        }
        $callIndex = $this->cfgCallOpIndexInChildren($block->orig->children, $callOp, $block->orig);
        if (null === $callIndex || $callIndex < 1) {
            return [];
        }
        $producers = [];
        for ($i = $callIndex - 1; $i >= 0; --$i) {
            // Sparse / stale cfgChildren indices — sibling walkers use ?? null (#36387 FinalClassConstCheck).
            $child = $block->orig->children[$i] ?? null;
            if (null === $child) {
                continue;
            }
            if (
                $child instanceof Op\Expr\ConstFetch
                || $child instanceof Op\Expr\ClassConstFetch
                || $child instanceof Op\Expr\MagicScriptConst
            ) {
                array_unshift($producers, $child);
                continue;
            }
            if ($child instanceof Op\Expr\UnaryMinus || $child instanceof Op\Expr\UnaryPlus) {
                // fseek($stream, -1, SEEK_END) — UnaryMinus offset prelude before ConstFetch whence (#16523).
                array_unshift($producers, $child);
                continue;
            }
            if ($child instanceof Op\Expr\Assign) {
                break;
            }
            if ($this->isInlineExprCallArgProducer($child)) {
                break;
            }
            break;
        }

        return $producers;
    }

    /**
     * $q = setlocale(LC_ALL, null) — hoisted ConstFetch preludes sit before the Assign stmt (#10177).
     *
     * @return list<Op\Expr\ConstFetch|Op\Expr\ClassConstFetch>
     */
    private function hoistedPreludeProducersBeforeAssignStmt(Op $callOp, Block $block): array
    {
        if (null === $block->orig) {
            return [];
        }
        $walkFrom = null;
        foreach ($block->orig->children as $i => $child) {
            if (
                $child instanceof Op\Expr\Assign
                && null !== $child->expr
                && ($child->expr === $callOp || $this->exprContainsCfgOp($child->expr, $callOp))
            ) {
                $walkFrom = $i - 1;
                break;
            }
        }
        if (!\is_int($walkFrom)) {
            return [];
        }
        $producers = [];
        for ($i = $walkFrom; $i >= 0; --$i) {
            $child = $block->orig->children[$i] ?? null;
            if (null === $child) {
                continue;
            }
            if ($child instanceof Op\Expr\ConstFetch || $child instanceof Op\Expr\ClassConstFetch) {
                array_unshift($producers, $child);
                continue;
            }
            if ($child instanceof Op\Expr\Assign) {
                break;
            }
            if ($this->isInlineExprCallArgProducer($child)) {
                break;
            }
            break;
        }

        return $producers;
    }

    private function exprContainsCfgOp(Op\Expr $expr, Op $needle): bool
    {
        if ($expr === $needle) {
            return true;
        }
        if (!property_exists($expr, 'expr') || !$expr->expr instanceof Op\Expr) {
            return false;
        }

        return $this->exprContainsCfgOp($expr->expr, $needle);
    }

    private function hoistedConstPreludeProducerForCallArgIndex(Op $callOp, int $argIndex, Block $block): ?Op\Expr
    {
        $callArg = $callOp->args[$argIndex] ?? null;
        if ($callArg instanceof Operand && $this->callArgOperandExpectsArrayProducer($callArg)) {
            return null;
        }
        // var_export(f(), true) / var_export($o->m(), true) — ConstFetch true sits between nested call and consumer (#16556, #17251).
        if (0 === $argIndex && null !== $block->orig) {
            $callIndex = $this->cfgCallOpIndexInChildren($block->orig->children, $callOp, $block->orig);
            if (null !== $callIndex) {
                for ($i = $callIndex - 1; $i >= 0; --$i) {
                    $prev = $block->orig->children[$i] ?? null;
                    if ($prev instanceof Op\Expr\ConstFetch || $prev instanceof Op\Expr\ClassConstFetch) {
                        continue;
                    }
                    if (
                        $prev instanceof Op\Expr\FuncCall
                        || $prev instanceof Op\Expr\NsFuncCall
                        || $prev instanceof Op\Expr\MethodCall
                        || $prev instanceof Op\Expr\StaticCall
                    ) {
                        $consumerFn = strtolower($this->resolveCfgFuncCallName($callOp) ?? '');
                        if ('var_export' === $consumerFn) {
                            if (
                                ($prev instanceof Op\Expr\FuncCall || $prev instanceof Op\Expr\NsFuncCall)
                                && 'define' === strtolower($this->resolveCfgFuncCallName($prev) ?? '')
                            ) {
                                break;
                            }
                            $callArgZero = $callOp->args[0] ?? null;
                            if (
                                $callArgZero instanceof Operand
                                && null !== $prev->result
                                && (
                                    $callArgZero === $prev->result
                                    || $this->operandsReferToSameVariable($callArgZero, $prev->result)
                                )
                            ) {
                                return null;
                            }
                        }
                        if ($prev instanceof Op\Expr\FuncCall || $prev instanceof Op\Expr\NsFuncCall) {
                            $fn = $this->resolveCfgFuncCallName($prev);
                            if (null !== $fn && ReferencableCheck::isArrayInternalPointerBuiltin($fn)) {
                                return null;
                            }
                        }
                    }
                    break;
                }
            }
        }
        if (!$this->callArgHasHoistedConstPrelude($callOp, $argIndex, $block)) {
            return null;
        }
        $preludes = $this->hoistedPreludeProducersImmediatelyBeforeCall($callOp, $block);
        if ([] === $preludes) {
            $preludes = $this->hoistedPreludeProducersBeforeAssignStmt($callOp, $block);
        }
        $preludeOrdinal = 0;
        foreach ($callOp->args as $i => $callArg) {
            if ($this->isEmbeddedCallLiteralArg($callArg)) {
                continue;
            }
            if (!$this->callArgIsDeadInlineTemporary($callArg)) {
                continue;
            }
            if ($callArg instanceof Operand && $this->callArgOperandExpectsArrayProducer($callArg)) {
                continue;
            }
            if ($i === $argIndex) {
                $prelude = $preludes[$preludeOrdinal] ?? null;

                return $prelude instanceof Op\Expr\ConstFetch
                    || $prelude instanceof Op\Expr\ClassConstFetch
                    || $prelude instanceof Op\Expr\UnaryMinus
                    || $prelude instanceof Op\Expr\UnaryPlus
                    ? $prelude
                    : null;
            }
            ++$preludeOrdinal;
        }

        return null;
    }

    private function hoistedPreludeProducerForCallArgIndex(Op $callOp, int $argIndex, Block $block): ?Op\Expr
    {
        $ordinal = $this->hoistedEnumPreludeSlotOrdinalForCallArg($callOp, $argIndex);
        if (null === $ordinal) {
            return null;
        }
        if (
            $this->nestedFuncCallFeedsDeadInlineCallArgZero($block, $callOp, $argIndex)
            || $this->nestedFuncCallFeedsDeadInlineCallArg($block, $callOp, $argIndex)
        ) {
            return null;
        }
        $producers = $this->hoistedPreludeProducersImmediatelyBeforeCall($callOp, $block);
        if (null !== $block->orig) {
            $callIndex = $this->cfgCallOpIndexInChildren($block->orig->children, $callOp, $block->orig);
            if (\is_int($callIndex)) {
                $nestedForArgZero = $this->nestedFuncCallProducerBeforeTrailingConstFetchPreludes(
                    $callOp,
                    $callIndex,
                    $block->orig->children
                );
                if (null !== $nestedForArgZero) {
                    if (0 === $argIndex) {
                        // tempnam(g(), E::A) — nested FuncCall feeds arg #0, not trailing enum (#10303, #16558).
                        return null;
                    }
                    $nestedIndex = $this->cfgCallOpIndexInChildren($block->orig->children, $nestedForArgZero, $block->orig);
                    if (\is_int($nestedIndex)) {
                        $targetArg = $this->siblingMultiArgFuncCallProducerTargetArgIndex(
                            $nestedIndex,
                            $callIndex,
                            $block->orig->children
                        );
                        if (null !== $targetArg && $targetArg === $argIndex) {
                            // unpack('i', pack(...), E::A) — middle arg is nested FuncCall (#8866).
                            return null;
                        }
                    }
                    $sole = $producers[0] ?? null;

                    return $sole instanceof Op\Expr ? $sole : null;
                }
            }
        }

        return $producers[$ordinal] ?? null;
    }

    /**
     * Map call arg index to hoisted ClassConstFetch when php-cfg inserts literal args first (#8796, #8933).
     *
     * @param list<Op\Expr\ClassConstFetch> $precedingFetches
     */
    private function precedingClassConstFetchForCallArgIndex(
        Op $callOp,
        int $argIndex,
        array $precedingFetches
    ): ?Op\Expr\ClassConstFetch {
        if (!property_exists($callOp, 'args') || !is_array($callOp->args)) {
            return null;
        }
        $fetchIndex = 0;
        foreach ($callOp->args as $i => $callArg) {
            if (!$this->callArgUsesHoistedEnumPreludeSlot($callArg)) {
                continue;
            }
            if ($i === $argIndex) {
                if (
                    1 === \count($precedingFetches)
                    && $i < \count($callOp->args) - 1
                ) {
                    // Sole hoisted enum feeds trailing arg when arg #0 is a nested FuncCall (#10303, #16558).
                    return null;
                }
                $fetch = $precedingFetches[$fetchIndex] ?? null;
                // tempnam(sys_get_temp_dir(), E::A) — sole enum prelude feeds trailing arg (#10303, #16558).
                if (
                    0 === $argIndex
                    && 1 === \count($precedingFetches)
                    && 2 === \count($callOp->args)
                ) {
                    $hoistedArgIndices = [];
                    foreach ($callOp->args as $hi => $ha) {
                        if ($this->callArgUsesHoistedEnumPreludeSlot($ha)) {
                            $hoistedArgIndices[] = (int) $hi;
                        }
                    }
                    if (
                        \count($hoistedArgIndices) >= 2
                        && ($hoistedArgIndices[1] ?? null) === \count($callOp->args) - 1
                    ) {
                        return null;
                    }
                }
                // Trailing enum case when an earlier arg uses a nested FuncCall (#10303).
                if (
                    null === $fetch
                    && 1 === \count($precedingFetches)
                    && $i === \count($callOp->args) - 1
                ) {
                    $fetch = $precedingFetches[0];
                }
                if ($fetch instanceof Op\Expr\ClassConstFetch) {
                    $callArg = $callOp->args[$argIndex] ?? null;
                    // php-cfg dead call-arg temps: ordinal mapping is authoritative (#8796, #9888).
                    if (
                        null !== $callArg
                        && !$this->operandsReferToSameVariable($fetch->result, $callArg)
                        && !$this->callArgUsesHoistedEnumPreludeSlot($callArg)
                    ) {
                        return null;
                    }
                }

                return $fetch;
            }
            ++$fetchIndex;
        }

        return null;
    }

    /** Ordinal among call args that use hoisted enum prelude slots (skips embedded literals, #8933). */
    private function hoistedEnumPreludeSlotOrdinalForCallArg(Op $callOp, int $argIndex): ?int
    {
        if (!property_exists($callOp, 'args') || !is_array($callOp->args)) {
            return null;
        }
        $fetchIndex = 0;
        foreach ($callOp->args as $i => $callArg) {
            if (!$this->callArgUsesHoistedEnumPreludeSlot($callArg)) {
                continue;
            }
            if ($i === $argIndex) {
                return $fetchIndex;
            }
            ++$fetchIndex;
        }

        return null;
    }

    /**
     * Sole hoisted arg #0 with nested inline Array_ preludes — wire outer root, not inner (#11300, #12008).
     *
     * @param list<Op\Expr> $producers
     */
    private function matchOutermostNestedInlineArrayProducerForArgZero(
        array $producers,
        int $argIndex,
        int $argCount,
        int $producerCount
    ): ?Op\Expr\Array_ {
        if (0 !== $argIndex) {
            return null;
        }
        $nestedTrailing = $this->splitNestedArrayLiteralChainWithTrailingProducers($producers);
        if (null !== $nestedTrailing) {
            [$arrayChain, $trailing] = $nestedTrailing;
            // new LimitIterator(new ArrayIterator([...]), …) — Array_ feeds inner ctor, not outer arg (#12916).
            if (($trailing[0] ?? null) instanceof Op\Expr\New_) {
                return null;
            }
            $outer = $arrayChain[\count($arrayChain) - 1] ?? null;

            return $outer instanceof Op\Expr\Array_ ? $outer : null;
        }
        $arrayProducers = array_values(array_filter(
            $producers,
            static fn (Op\Expr $producer): bool => $producer instanceof Op\Expr\Array_
        ));
        if (
            \count($arrayProducers) >= 2
            && $this->producersAreNestedArrayLiteralChain($arrayProducers)
            && $this->arrayProducersFormNestedChain($arrayProducers)
        ) {
            $outer = $arrayProducers[\count($arrayProducers) - 1];

            return $outer instanceof Op\Expr\Array_ ? $outer : null;
        }
        if (
            $argCount > $producerCount
            && $this->producersAreNestedArrayLiteralChain($producers)
            && $this->arrayProducersFormNestedChain($producers)
        ) {
            $outer = $producers[$producerCount - 1] ?? null;

            return $outer instanceof Op\Expr\Array_ ? $outer : null;
        }

        return null;
    }

    /**
     * php-cfg emits one Expr_Array producer per nesting level for inline literal args (#4738).
     *
     * @param list<Op\Expr> $producers
     */
    private function producersAreNestedArrayLiteralChain(array $producers): bool
    {
        if ([] === $producers) {
            return false;
        }
        foreach ($producers as $producer) {
            if (!$producer instanceof Op\Expr\Array_) {
                return false;
            }
        }

        return true;
    }

    /**
     * True when inline Array_ producers nest outer-wrapping-inner (#4738, #10848).
     *
     * @param list<Op\Expr> $producers
     */
    private function arrayProducersFormNestedChain(array $producers): bool
    {
        if (\count($producers) < 2) {
            return false;
        }
        for ($i = 1, $n = \count($producers); $i < $n; ++$i) {
            $inner = $producers[$i - 1];
            $outer = $producers[$i];
            if (!$inner instanceof Op\Expr\Array_ || !$outer instanceof Op\Expr\Array_) {
                return false;
            }
            $nested = false;
            foreach ($outer->values as $value) {
                if ($this->operandsReferToSameVariable($value, $inner->result)) {
                    $nested = true;
                    break;
                }
            }
            if (!$nested) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param list<Operand> $callArgs
     */
    private function soleNonEmbeddedCallArgIndex(array $callArgs): ?int
    {
        $index = null;
        $count = 0;
        foreach ($callArgs as $i => $callArg) {
            if ($this->isEmbeddedCallLiteralArg($callArg)) {
                continue;
            }
            ++$count;
            $index = $i;
        }
        if (1 !== $count) {
            return null;
        }

        return $index;
    }

    /**
     * array_column([['n'=>'a'], …], 'n') — nested inline haystack preludes share one hoisted arg (#13703).
     *
     * @param list<Op\Expr> $producers
     * @param list<Operand> $callArgs
     */
    private function matchSoleNestedInlineArrayHaystackProducer(
        array $producers,
        array $callArgs,
        int $argIndex
    ): ?Op\Expr {
        $soleHoisted = $this->soleNonEmbeddedCallArgIndex($callArgs);
        if (null === $soleHoisted || $argIndex !== $soleHoisted) {
            return null;
        }
        $callArg = $callArgs[$soleHoisted] ?? null;
        if (!$callArg instanceof Operand || !$this->callArgOperandExpectsArrayProducer($callArg)) {
            return null;
        }
        if (!$this->producersAreNestedArrayLiteralChain($producers) || \count($producers) < 2) {
            return null;
        }
        $last = $producers[\count($producers) - 1] ?? null;
        if (!$last instanceof Op\Expr\Array_) {
            return null;
        }

        return $last;
    }

    /**
     * Nested inline Array_ preludes for one call arg plus trailing hoisted producers (#10566).
     *
     * e.g. count([1, [2, 3]], COUNT_RECURSIVE) — producers [inner Array_, outer Array_, ConstFetch].
     *
     * @param list<Op\Expr> $producers
     *
     * @return array{0: list<Op\Expr\Array_>, 1: list<Op\Expr>}|null
     */
    private function splitNestedArrayLiteralChainWithTrailingProducers(array $producers): ?array
    {
        $count = \count($producers);
        if ($count < 2) {
            return null;
        }
        $trailing = [];
        $i = $count - 1;
        while ($i >= 0 && !($producers[$i] instanceof Op\Expr\Array_)) {
            $trailing[] = $producers[$i];
            --$i;
        }
        if ([] === $trailing) {
            return null;
        }
        $trailing = array_reverse($trailing);
        $arrayChain = array_slice($producers, 0, $i + 1);
        if ([] === $arrayChain || !$this->producersAreNestedArrayLiteralChain($arrayChain)) {
            return null;
        }

        return [$arrayChain, $trailing];
    }

    /**
     * http_build_query([..], '', '&', PHP_QUERY_RFC3986) — nested Array_ chain + trailing ConstFetch (#15932, #12008).
     *
     * @param list<Op\Expr> $producers
     * @param list<Operand> $callArgs
     */
    private function matchNestedArrayTrailingConstFetchCallArgProducer(
        array $producers,
        array $callArgs,
        int $argIndex
    ): ?Op\Expr {
        $callArg = $callArgs[$argIndex] ?? null;
        if (
            !$this->callArgIsDeadInlineTemporary($callArg)
            || $this->callArgOperandExpectsArrayProducer($callArg)
        ) {
            return null;
        }
        $nestedTrailing = $this->splitNestedArrayLiteralChainWithTrailingProducers($producers);
        if (null === $nestedTrailing) {
            return null;
        }
        [, $trailing] = $nestedTrailing;
        if ([] === $trailing) {
            return null;
        }
        $lastNonEmbedded = null;
        foreach ($callArgs as $i => $candidate) {
            if (!$this->isEmbeddedCallLiteralArg($candidate)) {
                $lastNonEmbedded = (int) $i;
            }
        }
        if (null === $lastNonEmbedded || $argIndex !== $lastNonEmbedded) {
            return null;
        }
        $trailingHoistedOrd = 0;
        foreach ($callArgs as $i => $candidate) {
            if ($i <= 0) {
                continue;
            }
            if (
                !$this->isEmbeddedCallLiteralArg($candidate)
                && $this->callArgIsDeadInlineTemporary($candidate)
            ) {
                ++$trailingHoistedOrd;
                if ($i === $argIndex) {
                    break;
                }
            }
        }
        if ($trailingHoistedOrd < 1) {
            return null;
        }
        $producer = $trailing[$trailingHoistedOrd - 1] ?? null;
        if ($producer instanceof Op\Expr\ConstFetch || $producer instanceof Op\Expr\ClassConstFetch) {
            return $producer;
        }

        return null;
    }

    /**
     * Leading nested inline Array_ chain for one call arg plus remaining hoisted producers (#12258).
     *
     * e.g. array_replace_recursive(['a' => ['b' => 1]], ['a' => null])
     * — producers [inner Array_, outer Array_, ConstFetch, Array_].
     *
     * @param list<Op\Expr> $producers
     *
     * @return array{0: list<Op\Expr\Array_>, 1: list<Op\Expr>}|null
     */
    private function splitLeadingNestedArrayLiteralChainWithRemainingProducers(array $producers): ?array
    {
        $count = \count($producers);
        if ($count < 2) {
            return null;
        }
        for ($end = $count - 2; $end >= 1; --$end) {
            $prefix = \array_slice($producers, 0, $end + 1);
            if (
                $this->producersAreNestedArrayLiteralChain($prefix)
                && $this->arrayProducersFormNestedChain($prefix)
            ) {
                return [$prefix, \array_slice($producers, $end + 1)];
            }
        }
        if ($producers[0] instanceof Op\Expr\Array_) {
            return [[$producers[0]], \array_slice($producers, 1)];
        }

        return null;
    }

    /**
     * @param list<Op\Expr> $remaining
     */
    private function countInlineCallArgProducersInRemaining(array $remaining): int
    {
        if ([] === $remaining) {
            return 0;
        }
        if (
            $this->producersAreNestedArrayLiteralChain($remaining)
            && $this->arrayProducersFormNestedChain($remaining)
        ) {
            return 1;
        }
        $count = 0;
        foreach ($remaining as $producer) {
            if ($producer instanceof Op\Expr\Array_) {
                ++$count;
            } elseif (!$producer instanceof Op\Expr\ConstFetch) {
                ++$count;
            }
        }

        return $count;
    }

    /**
     * @param list<Op\Expr> $remaining
     */
    private function inlineCallArgProducerAtRemainingIndex(array $remaining, int $trailingIndex): ?Op\Expr
    {
        if (
            $this->producersAreNestedArrayLiteralChain($remaining)
            && $this->arrayProducersFormNestedChain($remaining)
        ) {
            return 0 === $trailingIndex ? $remaining[\count($remaining) - 1] : null;
        }
        $seen = 0;
        foreach ($remaining as $producer) {
            if ($producer instanceof Op\Expr\Array_) {
                if ($seen === $trailingIndex) {
                    return $producer;
                }
                ++$seen;
            } elseif (!$producer instanceof Op\Expr\ConstFetch) {
                if ($seen === $trailingIndex) {
                    return $producer;
                }
                ++$seen;
            }
        }

        return null;
    }

    /**
     * array_replace_recursive(['a' => ['b' => 1]], ['a' => null]) — nested arg #0 + null overlay arg #1 (#12258, #16160).
     *
     * @param list<Op\Expr> $producers
     */
    private function matchLeadingNestedInlineArrayMergeFamilyCallArgProducer(
        array $producers,
        int $argIndex,
        int $argCount
    ): ?Op\Expr {
        if ($argCount < 2) {
            return null;
        }
        $leadingNestedRemaining = $this->splitLeadingNestedArrayLiteralChainWithRemainingProducers($producers);
        if (null === $leadingNestedRemaining) {
            return null;
        }
        [$prefixChain, $remaining] = $leadingNestedRemaining;
        $trailingArgCount = $this->countInlineCallArgProducersInRemaining($remaining);
        if (1 + $trailingArgCount !== $argCount) {
            return null;
        }
        if (0 === $argIndex) {
            return $prefixChain[\count($prefixChain) - 1];
        }

        return $this->inlineCallArgProducerAtRemainingIndex($remaining, $argIndex - 1);
    }

    /**
     * ConstFetch prelude before nested inline Array_ call arg (#12007, filter_var + options array).
     *
     * e.g. filter_var('abc', FILTER_VALIDATE_REGEXP, ['options' => ['regexp' => '/^a/']])
     * — producers [ConstFetch, inner Array_, outer Array_].
     *
     * Also: filter_var('01', FILTER_VALIDATE_INT, ['options' => ['flags' => FILTER_FLAG_ALLOW_OCTAL]])
     * — producers [ConstFetch filter, ConstFetch flags, inner Array_, outer Array_] (#22772).
     * Intervening ConstFetches are array-element values; the call arg is still the outermost Array_.
     *
     * @param list<Op\Expr> $producers
     *
     * @return array{0: Op\Expr\ConstFetch, 1: list<Op\Expr\Array_>}|null
     */
    private function splitLeadingConstFetchWithNestedArrayLiteralChain(array $producers): ?array
    {
        $count = \count($producers);
        if ($count < 2) {
            return null;
        }
        $first = $producers[0];
        if (!$first instanceof Op\Expr\ConstFetch) {
            return null;
        }
        $arrayStart = null;
        for ($i = 0; $i < $count; ++$i) {
            if ($producers[$i] instanceof Op\Expr\Array_) {
                $arrayStart = $i;
                break;
            }
            if (!$producers[$i] instanceof Op\Expr\ConstFetch) {
                return null;
            }
        }
        if (null === $arrayStart || $arrayStart < 1) {
            return null;
        }
        $arrayChain = \array_slice($producers, $arrayStart);
        if ([] === $arrayChain || !$this->producersAreNestedArrayLiteralChain($arrayChain)) {
            return null;
        }
        if (!$this->arrayProducersFormNestedChain($arrayChain)) {
            return null;
        }

        return [$first, $arrayChain];
    }

    /**
     * ConstFetch prelude before single inline Array_ call arg (#12326, filter_var flags options).
     *
     * e.g. filter_var('not-int', FILTER_VALIDATE_INT, ['flags' => FILTER_NULL_ON_FAILURE])
     * — producers [ConstFetch filter, ConstFetch flags, Array_ options].
     *
     * @param list<Op\Expr> $producers
     *
     * @return array{0: Op\Expr\ConstFetch, 1: Op\Expr\Array_}|null
     */
    /**
     * FILTER_* names that are option bitmasks, not filter ids (php-src ext/filter/php_filter.h).
     */
    private function isFilterVarOptionFlagConstName(string $name): bool
    {
        return str_starts_with($name, 'filter_flag_')
            || \in_array($name, [
                'filter_null_on_failure',
                'filter_throw_on_failure',
                'filter_require_array',
                'filter_require_scalar',
                'filter_force_array',
            ], true);
    }

    private function splitLeadingConstFetchWithArrayLiteralCallArg(array $producers): ?array
    {
        $count = \count($producers);
        if ($count < 2) {
            return null;
        }
        $first = $producers[0];
        if (!$first instanceof Op\Expr\ConstFetch) {
            return null;
        }
        $last = $producers[$count - 1];
        if (!$last instanceof Op\Expr\Array_) {
            return null;
        }
        $rest = \array_slice($producers, 1);
        if ($this->producersAreNestedArrayLiteralChain($rest) && $this->arrayProducersFormNestedChain($rest)) {
            return null;
        }
        for ($i = 1; $i < $count - 1; ++$i) {
            if (!$producers[$i] instanceof Op\Expr\ConstFetch) {
                return null;
            }
        }

        return [$first, $last];
    }

    /**
     * @param list<Op\Expr> $producers
     *
     * @return array{0: Op\Expr\ConstFetch, 1: Op\Expr\FuncCall|Op\Expr\NsFuncCall}|null
     */
    private function splitLeadingConstFetchWithFuncCallCallArg(array $producers): ?array
    {
        if (2 !== \count($producers)) {
            return null;
        }
        $first = $producers[0];
        $second = $producers[1];
        if (!$first instanceof Op\Expr\ConstFetch) {
            return null;
        }
        if (!$second instanceof Op\Expr\FuncCall && !$second instanceof Op\Expr\NsFuncCall) {
            return null;
        }

        return [$first, $second];
    }

    /**
     * @param list<Op\Expr> $producers
     */
    private function producersAreSiblingCallWithHoistedScalarConstFetch(array $producers): bool
    {
        if (2 !== \count($producers)) {
            return false;
        }
        $call = null;
        $scalarConst = null;
        foreach ($producers as $producer) {
            if ($producer instanceof Op\Expr\FuncCall || $producer instanceof Op\Expr\NsFuncCall
                || $producer instanceof Op\Expr\MethodCall || $producer instanceof Op\Expr\StaticCall
                || $producer instanceof Op\Expr\Include_ || $producer instanceof Op\Expr\Eval_) {
                $call = $producer;
            } elseif ($producer instanceof Op\Expr\ConstFetch) {
                $name = $this->staticNameFromOperand($producer->name);
                if (null !== $name && \in_array(strtolower($name), ['true', 'false', 'null'], true)) {
                    $scalarConst = $producer;
                }
            }
        }

        return null !== $call && null !== $scalarConst;
    }

    /**
     * @param list<Op\Expr> $producers
     */
    private function producersAreSiblingArithmeticWithHoistedScalarConstFetch(array $producers): bool
    {
        if (2 !== \count($producers)) {
            return false;
        }
        $arith = null;
        $scalarConst = null;
        foreach ($producers as $producer) {
            if ($this->isChainedArithmeticBinaryOpExpr($producer)) {
                $arith = $producer;
            } elseif ($producer instanceof Op\Expr\ConstFetch) {
                $name = $this->staticNameFromOperand($producer->name);
                if (null !== $name && \in_array(strtolower($name), ['true', 'false', 'null'], true)) {
                    $scalarConst = $producer;
                }
            }
        }

        return null !== $arith && null !== $scalarConst;
    }

    /**
     * @param list<Op\Expr> $producers
     */
    private function producersAreSiblingCallWithHoistedEnumConstFetch(array $producers): bool
    {
        if (2 !== \count($producers)) {
            return false;
        }
        $call = null;
        $enumFetch = null;
        foreach ($producers as $producer) {
            if ($producer instanceof Op\Expr\FuncCall || $producer instanceof Op\Expr\NsFuncCall) {
                $call = $producer;
            } elseif ($producer instanceof Op\Expr\ClassConstFetch) {
                $enumFetch = $producer;
            }
        }

        return null !== $call && null !== $enumFetch;
    }

    /**
     * fseek($stream, -N, SEEK_*) — hoisted UnaryMinus offset + ConstFetch whence preludes (#16523).
     *
     * @param list<Op\Expr> $producers
     */
    private function producersIncludeUnaryOffsetWithConstWhence(array $producers): bool
    {
        $hasUnary = false;
        $hasConst = false;
        foreach ($producers as $producer) {
            if ($producer instanceof Op\Expr\UnaryMinus || $producer instanceof Op\Expr\UnaryPlus) {
                $hasUnary = true;
            } elseif ($producer instanceof Op\Expr\ConstFetch) {
                $hasConst = true;
            }
        }

        return $hasUnary && $hasConst;
    }

    /**
     * @return array{0: Op\Expr\ConstFetch, 1: Op\Expr\FuncCall|Op\Expr\NsFuncCall}|null
     */
    private function leadingConstFetchFuncCallPreludeBeforeCfgCall(Op $callOp, Block $block): ?array
    {
        if (null === $block->orig) {
            return null;
        }
        $callIndex = $this->cfgCallOpIndexInChildren($block->orig->children, $callOp, $block->orig);
        if (null === $callIndex || $callIndex < 2) {
            return null;
        }
        $func = $block->orig->children[$callIndex - 1] ?? null;
        $const = $block->orig->children[$callIndex - 2] ?? null;
        if (!$const instanceof Op\Expr\ConstFetch) {
            return null;
        }
        if (!$func instanceof Op\Expr\FuncCall && !$func instanceof Op\Expr\NsFuncCall) {
            return null;
        }
        // probe('label', nested_call()) — ConstFetch+FuncCall preludes belong to nested call (#15846).
        // str_contains(print_r(..., true), 'zzz') — trailing outer literal; ConstFetch feeds nested (#24372).
        if (
            property_exists($callOp, 'args')
            && \is_array($callOp->args)
        ) {
            if (
                isset($callOp->args[0])
                && $this->isEmbeddedCallLiteralArg($callOp->args[0])
            ) {
                return null;
            }
            foreach ($callOp->args as $outerArg) {
                if ($this->isEmbeddedCallLiteralArg($outerArg)) {
                    if (
                        $this->hoistedConstFetchFeedsNestedSiblingFuncCallArg(
                            $const,
                            $callIndex - 2,
                            $callIndex,
                            $block->orig->children
                        )
                    ) {
                        return null;
                    }
                    break;
                }
            }
        }

        return [$const, $func];
    }

    /**
     * explode(PATH_SEPARATOR, get_include_path()) — final ARG_SEND must bind ConstFetch + sibling FuncCall (#15833).
     *
     * Not for `str_contains(print_r(..., true), 'zzz')`: the ConstFetch feeds the *nested* callee, and the
     * outer needle is an embedded literal — remapping arg #1 onto the nested EXEC_RETURN made needles
     * alias the haystack (#24372).
     *
     * @param list<OpCode> $emitOps
     */
    private function finalizeLeadingConstFetchFuncCallPreludeCallArgSlot(
        Block $block,
        Op $cfgCallOp,
        int $argIndex,
        array &$emitOps = []
    ): ?string {
        if (
            null === $block->orig
            || !\is_array($cfgCallOp->args ?? null)
            || 2 !== \count($cfgCallOp->args)
            || 'array_slice' === $this->resolveCfgFuncCallName($cfgCallOp)
            || 'array_combine' === $this->resolveCfgFuncCallName($cfgCallOp)
            || (
                isset($cfgCallOp->args[0])
                && $this->isEmbeddedCallLiteralArg($cfgCallOp->args[0])
            )
            || $this->isEmbeddedCallLiteralArg($cfgCallOp->args[$argIndex] ?? null)
            || $this->consumerImmediateUnaryHoistedDeadTempArgZero($cfgCallOp, $block)
        ) {
            return null;
        }
        $constFuncPrelude = $this->leadingConstFetchFuncCallPreludeBeforeCfgCall($cfgCallOp, $block)
            ?? $this->splitLeadingConstFetchWithFuncCallCallArg(
                $this->precedingInlineCallArgProducersBeforeCfgOp($block->orig->children, $cfgCallOp)
            );
        if (null === $constFuncPrelude) {
            return null;
        }
        [$constFetch, $funcProducer] = $constFuncPrelude;
        // ConstFetch true/false/null before nested print_r/var_export feeds that callee, not outer args (#24372).
        if ($constFetch instanceof Op\Expr\ConstFetch) {
            $callIndex = $this->cfgCallOpIndexInChildren($block->orig->children, $cfgCallOp, $block->orig);
            $constIndex = $this->cfgCallOpIndexInChildren($block->orig->children, $constFetch, $block->orig);
            if (
                \is_int($callIndex)
                && \is_int($constIndex)
                && $this->hoistedConstFetchFeedsNestedSiblingFuncCallArg(
                    $constFetch,
                    $constIndex,
                    $callIndex,
                    $block->orig->children
                )
            ) {
                return null;
            }
        }
        $target = match ($argIndex) {
            0 => $constFetch,
            1 => $funcProducer,
            default => null,
        };
        if (!$target instanceof Op\Expr) {
            return null;
        }
        if (null === $block->slotForOperand($target->result)) {
            foreach ($this->compileExpr($target, $block) as $op) {
                $emitOps[] = $op;
            }
        }
        $splitSlot = $block->slotForOperand($target->result);

        return null !== $splitSlot ? (string) $splitSlot : null;
    }

    /**
     * explode(PATH_SEPARATOR, get_include_path()) — defer leading ConstFetch until consumer (#15833).
     *
     * @param list<Op> $ops
     */
    private function isDeferredLeadingConstFetchBeforeSiblingFuncCallConsumer(
        Op\Expr\ConstFetch $fetch,
        array $ops,
        int $fetchIndex
    ): bool {
        $func = $ops[$fetchIndex + 1] ?? null;
        $consumer = $ops[$fetchIndex + 2] ?? null;
        if (
            !($func instanceof Op\Expr\FuncCall || $func instanceof Op\Expr\NsFuncCall)
            || !($consumer instanceof Op\Expr\FuncCall || $consumer instanceof Op\Expr\NsFuncCall)
            || !property_exists($consumer, 'args')
            || !\is_array($consumer->args)
            || 2 !== \count($consumer->args)
        ) {
            return false;
        }
        if (null === $this->splitLeadingConstFetchWithFuncCallCallArg([$fetch, $func])) {
            return false;
        }

        return 'explode' === $this->resolveCfgFuncCallName($consumer);
    }

    /**
     * round(...); fmod(-1.5, …) — immediate UnaryMinus/Plus feeds arg #0 (#13508, #15736).
     */
    private function consumerImmediateUnaryHoistedDeadTempArgZero(?Op $cfgCallOp, Block $block): bool
    {
        if (null === $cfgCallOp || null === $block->orig || !\is_array($cfgCallOp->args ?? null)) {
            return false;
        }
        $callArg = $cfgCallOp->args[0] ?? null;
        if (!$this->callArgIsDeadInlineTemporary($callArg)) {
            return false;
        }
        $deadHoisted = 0;
        foreach ($cfgCallOp->args as $hoistedArg) {
            if ($this->callArgIsDeadInlineTemporary($hoistedArg)) {
                ++$deadHoisted;
            }
        }
        if (1 !== $deadHoisted) {
            return false;
        }
        $callIndex = $this->cfgCallOpIndexInChildren($block->orig->children, $cfgCallOp, $block->orig);
        if (null === $callIndex || $callIndex < 1) {
            return false;
        }
        $immediate = $block->orig->children[$callIndex - 1] ?? null;

        return $immediate instanceof Op\Expr\UnaryMinus || $immediate instanceof Op\Expr\UnaryPlus;
    }

    /**
     * Hoisted ConstFetch before a nested sibling FuncCall — feeds callee arg, not the consumer (#11272).
     *
     * @param list<Op> $cfgChildren
     */
    private function hoistedConstFetchFeedsNestedSiblingFuncCallArg(
        Op\Expr\ConstFetch $fetch,
        int $fetchIndex,
        int $consumerIndex,
        array $cfgChildren
    ): bool {
        if (null === $fetch->result) {
            return false;
        }
        for ($j = $fetchIndex + 1; $j < $consumerIndex; ++$j) {
            $mid = $cfgChildren[$j] ?? null;
            if ($mid instanceof Op\Expr\ConstFetch || $mid instanceof Op\Expr\ClassConstFetch) {
                continue;
            }
            if ($mid instanceof Op\Expr\FuncCall || $mid instanceof Op\Expr\NsFuncCall) {
                if (!property_exists($mid, 'args') || !is_array($mid->args)) {
                    return false;
                }
                $name = $this->staticNameFromOperand($fetch->name);
                if (null === $name || !\in_array(strtolower($name), ['true', 'false', 'null'], true)) {
                    return false;
                }
                foreach ($mid->args as $callArg) {
                    if (null === $callArg) {
                        continue;
                    }
                    if ($this->operandsReferToSameVariable($fetch->result, $callArg)) {
                        return true;
                    }
                    if ($this->callArgIsDeadInlineTemporary($callArg)) {
                        return true;
                    }
                }

                return false;
            }

            return false;
        }

        return false;
    }

    /**
     * php-cfg hoists chained assignment before a call with a dead arg temp (#6758, #9405).
     *
     * @param list<Op\Expr> $producers
     */
    private function producersAreChainedAssignChain(array $producers): bool
    {
        if ([] === $producers) {
            return false;
        }
        foreach ($producers as $producer) {
            if (!$producer instanceof Op\Expr\Assign) {
                return false;
            }
        }
        for ($i = 1, $n = count($producers); $i < $n; ++$i) {
            $inner = $producers[$i - 1];
            $outer = $producers[$i];
            if (!$this->operandsReferToSameVariable($inner->result, $outer->expr)) {
                return false;
            }
        }

        return true;
    }

    /**
     * php-cfg hoists chained Concat before inline call args — wire final result slot (#13458, zend_operators.c).
     *
     * @param list<Op\Expr> $producers
     * @param list<Operand> $callArgs
     */
    private function matchChainedConcatInlineCallArgProducer(
        array $producers,
        array $callArgs,
        int $argIndex
    ): ?Op\Expr {
        $soleHoisted = $this->soleNonEmbeddedCallArgIndex($callArgs);
        if (null === $soleHoisted || $argIndex !== $soleHoisted) {
            return null;
        }
        if ($this->producersAreChainedConcatProducers($producers)) {
            return $producers[\count($producers) - 1];
        }
        if (
            1 === \count($producers)
            && ($producers[0] ?? null) instanceof Op\Expr\BinaryOp\Concat
        ) {
            return $producers[0];
        }

        return null;
    }

    /**
     * php-cfg dead call-arg temp for chained Concat before FuncCall (#13458, #13572).
     *
     * `fopen('/tmp/maint_' . 99 . '/sub/file.txt', 'r')` — arg temp may differ from final Concat.result.
     *
     * @param list<Op> $cfgChildren
     *
     * @return list<Op\Expr\BinaryOp\Concat>|null
     */
    private function chainedConcatInlineCallArgProducersBeforeCall(
        array $cfgChildren,
        int $callIndex,
        Op $callOp
    ): ?array {
        if ($callIndex < 1 || !property_exists($callOp, 'args') || !is_array($callOp->args)) {
            return null;
        }
        $soleHoisted = $this->soleNonEmbeddedCallArgIndex($callOp->args);
        if (null === $soleHoisted) {
            return null;
        }
        $callArg = $callOp->args[$soleHoisted] ?? null;
        if (!$this->callArgIsDeadInlineTemporary($callArg)) {
            return null;
        }
        $immediate = $cfgChildren[$callIndex - 1] ?? null;
        if (!$immediate instanceof Op\Expr\BinaryOp\Concat) {
            return null;
        }
        $chain = [];
        for ($i = $callIndex - 1; $i >= 0; --$i) {
            $child = $cfgChildren[$i];
            if (!$child instanceof Op\Expr\BinaryOp\Concat) {
                break;
            }
            array_unshift($chain, $child);
            if ($i > 0) {
                $prev = $cfgChildren[$i - 1];
                if (
                    $prev instanceof Op\Expr\BinaryOp\Concat
                    && null !== $child->left
                    && $this->operandsReferToSameVariable($prev->result, $child->left)
                ) {
                    continue;
                }
            }
            break;
        }
        if ([] === $chain) {
            return null;
        }
        if (1 === \count($chain)) {
            return $chain;
        }
        if (!$this->producersAreChainedConcatProducers($chain)) {
            return null;
        }

        return $chain;
    }

    /**
     * php-cfg hoists `sprintf('%.10F', 5 * 200.0 / 12)` as sibling Mul/Div before FuncCall (#15929).
     *
     * @param list<Op> $cfgChildren
     *
     * @return list<Op\Expr\BinaryOp\Div|Op\Expr\BinaryOp\Minus|Op\Expr\BinaryOp\Mul|Op\Expr\BinaryOp\Plus>|null
     */
    private function chainedArithmeticInlineCallArgProducersBeforeCall(
        array $cfgChildren,
        int $callIndex,
        Op $callOp
    ): ?array {
        if ($callIndex < 1 || !property_exists($callOp, 'args') || !is_array($callOp->args)) {
            return null;
        }
        $soleHoisted = $this->soleNonEmbeddedCallArgIndex($callOp->args);
        if (null === $soleHoisted) {
            return null;
        }
        $callArg = $callOp->args[$soleHoisted] ?? null;
        if (!$this->callArgIsDeadInlineTemporary($callArg)) {
            return null;
        }
        $immediate = $cfgChildren[$callIndex - 1] ?? null;
        if (!$this->isChainedArithmeticBinaryOpExpr($immediate)) {
            return null;
        }
        $chain = [];
        for ($i = $callIndex - 1; $i >= 0; --$i) {
            $child = $cfgChildren[$i];
            if (!$this->isChainedArithmeticBinaryOpExpr($child)) {
                break;
            }
            array_unshift($chain, $child);
            if ($i > 0) {
                $prev = $cfgChildren[$i - 1];
                if (
                    $this->isChainedArithmeticBinaryOpExpr($prev)
                    && null !== $child->left
                    && $this->operandsReferToSameVariable($prev->result, $child->left)
                ) {
                    continue;
                }
            }
            break;
        }
        if ([] === $chain) {
            return null;
        }
        if (1 === \count($chain)) {
            return $chain;
        }
        if (!$this->producersAreChainedArithmeticProducers($chain)) {
            return null;
        }

        return $chain;
    }

    private function isChainedArithmeticBinaryOpExpr(?Op $expr): bool
    {
        return $expr instanceof Op\Expr\BinaryOp\Plus
            || $expr instanceof Op\Expr\BinaryOp\Minus
            || $expr instanceof Op\Expr\BinaryOp\Mul
            || $expr instanceof Op\Expr\BinaryOp\Div
            || $expr instanceof Op\Expr\BinaryOp\Mod
            || $expr instanceof Op\Expr\BinaryOp\Pow
            || $expr instanceof Op\Expr\BinaryOp\BitwiseAnd
            || $expr instanceof Op\Expr\BinaryOp\BitwiseOr
            || $expr instanceof Op\Expr\BinaryOp\BitwiseXor
            || $expr instanceof Op\Expr\BinaryOp\ShiftLeft
            || $expr instanceof Op\Expr\BinaryOp\ShiftRight;
    }

    /**
     * @param list<Op\Expr> $producers
     */
    private function producersAreChainedArithmeticProducers(array $producers): bool
    {
        if (\count($producers) < 2) {
            return false;
        }
        foreach ($producers as $producer) {
            if (!$this->isChainedArithmeticBinaryOpExpr($producer)) {
                return false;
            }
        }
        for ($i = 1, $n = \count($producers); $i < $n; ++$i) {
            $inner = $producers[$i - 1];
            $outer = $producers[$i];
            if (
                null === $outer->left
                || !$this->operandsReferToSameVariable($inner->result, $outer->left)
            ) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param list<Op\Expr> $producers
     */
    private function producersAreChainedConcatProducers(array $producers): bool
    {
        if (\count($producers) < 2) {
            return false;
        }
        foreach ($producers as $producer) {
            if (!$producer instanceof Op\Expr\BinaryOp\Concat) {
                return false;
            }
        }
        for ($i = 1, $n = \count($producers); $i < $n; ++$i) {
            $inner = $producers[$i - 1];
            $outer = $producers[$i];
            if (
                null === $outer->left
                || !$this->operandsReferToSameVariable($inner->result, $outer->left)
            ) {
                return false;
            }
        }

        return true;
    }

    /**
     * php-cfg hoists chained Mul/Div/Plus/Minus before inline call args — wire final result slot (#15929).
     *
     * @param list<Op\Expr> $producers
     * @param list<Operand> $callArgs
     */
    private function matchChainedArithmeticInlineCallArgProducer(
        array $producers,
        array $callArgs,
        int $argIndex
    ): ?Op\Expr {
        $soleHoisted = $this->soleNonEmbeddedCallArgIndex($callArgs);
        if (null === $soleHoisted || $argIndex !== $soleHoisted) {
            return null;
        }
        if ($this->producersAreChainedArithmeticProducers($producers)) {
            return $producers[\count($producers) - 1];
        }
        if (
            1 === \count($producers)
            && $this->isChainedArithmeticBinaryOpExpr($producers[0] ?? null)
        ) {
            return $producers[0];
        }

        return null;
    }

    private function isInlineExprCallArgConsumer(Op $op): bool
    {
        return $op instanceof Op\Expr\FuncCall
            || $op instanceof Op\Expr\NsFuncCall
            || $op instanceof Op\Expr\MethodCall
            || $op instanceof Op\Expr\StaticCall
            || $op instanceof Op\Expr\New_;
    }

    /** php-cfg f(g(), h()) sibling producers feeding a multi-arg call (#9463, #14828). */
    private function isSiblingMultiArgInlineCallConsumer(Op $consumer): bool
    {
        return $consumer instanceof Op\Expr\FuncCall
            || $consumer instanceof Op\Expr\NsFuncCall
            || $consumer instanceof Op\Expr\MethodCall
            || $consumer instanceof Op\Expr\StaticCall;
    }

    /**
     * First hoisted producer for this multi-arg consumer — contiguous chain only (#16254).
     *
     * var_dump(strlen(), substr()); … var_dump(ftell(), fgetc()) must not treat strlen as the
     * chain start for the second consumer; stmt-level fseek/fwrite between chains stops the scan.
     *
     * @param list<Op> $cfgChildren
     */
    private function firstContiguousSiblingMultiArgProducerIndex(
        int $consumerIndex,
        Op $consumer,
        array $cfgChildren
    ): ?int {
        if (!$this->isSiblingMultiArgInlineCallConsumer($consumer)) {
            return null;
        }
        $first = null;
        for ($j = $consumerIndex - 1; $j >= 0; --$j) {
            $child = $cfgChildren[$j] ?? null;
            if (
                $child instanceof Op\Expr\FuncCall
                || $child instanceof Op\Expr\NsFuncCall
                || $child instanceof Op\Expr\MethodCall
                || $child instanceof Op\Expr\StaticCall
            ) {
                if ($this->isSiblingMultiArgFuncCallProducer($child, $consumer, $j, $consumerIndex, $cfgChildren)) {
                    $first = $j;
                    continue;
                }
                // Not part of this contiguous chain — do not keep scanning the whole block
                // (nested call stmts were O(n²) isSiblingMultiArg probes — #36387 / #36224).
                break;
            } elseif ($child instanceof Op\Expr\ConstFetch || $child instanceof Op\Expr\ClassConstFetch) {
                if (null !== $first) {
                    break;
                }
                continue;
            } elseif ($child instanceof Op\Expr\Array_) {
                if (null !== $first) {
                    break;
                }
                continue;
            } elseif ($this->isUnaryInlineSiblingCallArgExpr($child)) {
                if (null !== $first) {
                    break;
                }
                continue;
            } elseif (null !== $first) {
                break;
            } else {
                break;
            }
        }

        return $first;
    }

    /**
     * @param list<Op> $cfgChildren
     */
    private function countContiguousSiblingMultiArgProducers(
        int $firstProducer,
        int $consumerIndex,
        Op $consumer,
        array $cfgChildren
    ): int {
        $count = 0;
        for ($j = $firstProducer; $j < $consumerIndex; ++$j) {
            $child = $cfgChildren[$j] ?? null;
            if (!$child instanceof Op\Expr) {
                continue;
            }
            if ($this->isSiblingMultiArgFuncCallProducer($child, $consumer, $j, $consumerIndex, $cfgChildren)) {
                ++$count;
            }
        }

        return $count;
    }

    /**
     * @param list<Op> $children
     */
    private function onlyInlineCallArgProducersBetweenIndices(array $children, int $fromIndex, int $toIndex): bool
    {
        if ($fromIndex >= $toIndex - 1) {
            return false;
        }
        for ($k = $fromIndex + 1; $k < $toIndex; ++$k) {
            $stmt = $children[$k];
            if (!$stmt instanceof Op\Expr || !$this->isInlineExprCallArgProducer($stmt)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Producer statement for a specific hoisted call argument (#23354).
     *
     * The $prev heuristics below resolve a hoisted argument from children[$callIndex - 1] — the
     * statement immediately before the call, which is only ever the TRAILING argument's producer.
     * Applied to every index, that silently gave each argument the last one's value:
     * f($x + 1, $x + 2) printed "12 12" and t2($r['a'], $r['b']) printed "BBB|BBB".
     *
     * No positional guessing is needed. php-cfg keeps the link: the hoisted argument temporary is a
     * distinct Operand from the producer's ->result (which is why slotForOperand($arg) misses), but
     * it records that producer as its sole writer. args[$argIndex]->ops[0] therefore names the
     * producer exactly, for every producer kind and any mix of them.
     *
     * Restricted to dead inline temporaries with a single writer — a named variable or a
     * multiply-written temp is not a hoisted argument and stays with the existing paths.
     */
    private function inlineProducerForHoistedCallArgIndex(
        array $cfgChildren,
        Op $callOp,
        int $callIndex,
        int $argIndex
    ): ?Op\Expr {
        $callArg = $callOp->args[$argIndex] ?? null;
        if (!$callArg instanceof Operand\Temporary || !$this->callArgIsDeadInlineTemporary($callArg)) {
            return null;
        }
        $writers = $callArg->ops ?? [];
        if (1 !== \count($writers)) {
            return null;
        }
        $producer = $writers[0];
        if (!$producer instanceof Op\Expr || !$this->isInlineExprCallArgProducer($producer)) {
            return null;
        }
        if (null === $producer->result) {
            return null;
        }
        // The producer must be a hoisted statement of this block, sitting before the call.
        $producerIndex = null;
        for ($i = $callIndex - 1; $i >= 0; --$i) {
            if (($cfgChildren[$i] ?? null) === $producer) {
                $producerIndex = $i;
                break;
            }
        }
        if (null === $producerIndex) {
            return null;
        }

        return $producer;
    }

    /**
     * Slot holding argument $argIndex's own hoisted producer, via php-cfg's exact link (#23354).
     *
     * The hoisted argument temporary is a distinct Operand from the producer's ->result — which is
     * why slotForOperand($arg) misses and the shape heuristics exist — but it records that producer
     * as its sole writer. Restricted to dead inline temporaries with exactly one writer, whose
     * producer is a hoisted statement of this block before the call.
     *
     * @param list<OpCode> $sends
     */
    private function exactHoistedCallArgProducerSlot(
        Block $block,
        ?Op $cfgCallOp,
        int $argIndex,
        array &$sends
    ): ?string {
        if (null === $cfgCallOp || null === $block->orig) {
            return null;
        }
        if (!property_exists($cfgCallOp, 'args') || !is_array($cfgCallOp->args)) {
            return null;
        }
        $callArg = $cfgCallOp->args[$argIndex] ?? null;
        if (!$callArg instanceof Operand\Temporary || !$this->callArgIsDeadInlineTemporary($callArg)) {
            return null;
        }
        if (1 !== \count($callArg->ops ?? [])) {
            return null;
        }
        $producer = $callArg->ops[0];
        if (!$producer instanceof Op\Expr || null === $producer->result) {
            return null;
        }
        if (!$this->isInlineExprCallArgProducer($producer)) {
            return null;
        }
        $callIndex = $this->cfgCallOpIndexInChildren($block->orig->children, $cfgCallOp, $block->orig);
        if (!\is_int($callIndex)) {
            return null;
        }
        // Indexed lookup — walking back to 0 re-scanned every prior statement (#36387).
        $producerIndex = $this->cfgCallOpIndexInChildren($block->orig->children, $producer, $block->orig);
        if (!\is_int($producerIndex) || $producerIndex >= $callIndex) {
            return null;
        }
        $slot = $block->slotForOperand($producer->result);
        if (null === $slot) {
            foreach ($this->compileExpr($producer, $block) as $op) {
                $sends[] = $op;
            }
            $slot = $block->slotForOperand($producer->result);
        }

        return null !== $slot ? (string) $slot : null;
    }

    private function isInlineExprCallArgProducer(Op $op): bool
    {
        return $op instanceof Op\Expr\Array_
            || $op instanceof Op\Expr\ArrayDimFetch
            || $op instanceof Op\Expr\PropertyFetch
            || $op instanceof Op\Expr\StaticPropertyFetch
            || $op instanceof Op\Expr\BinaryOp
            || $op instanceof Op\Expr\New_
            || $op instanceof Op\Expr\ConstFetch
            || $op instanceof Op\Expr\ClassConstFetch
            || $op instanceof Op\Expr\Closure
            || $op instanceof Op\Expr\ArrowFunction
            || $op instanceof Op\Expr\FirstClassCallable
            || $op instanceof Op\Expr\FuncCall
            || $op instanceof Op\Expr\NsFuncCall
            || $op instanceof Op\Expr\StaticCall
            || $op instanceof Op\Expr\MethodCall
            || $op instanceof Op\Expr\NullsafePropertyFetch
            || $op instanceof Op\Expr\NullsafeMethodCall
            || $op instanceof Op\Expr\UnaryMinus
            || $op instanceof Op\Expr\UnaryPlus
            || $op instanceof Op\Expr\BitwiseNot
            || $op instanceof Op\Expr\BooleanNot
            || $op instanceof Op\Expr\Empty_
            || $op instanceof Op\Expr\Eval_
            || $op instanceof Op\Expr\Include_
            || $op instanceof Op\Expr\Isset_
            || $op instanceof Op\Expr\InstanceOf_
            || $op instanceof Op\Expr\In_
            || $op instanceof Op\Expr\Cast
            || $op instanceof Op\Expr\Clone_
            || $op instanceof Op\Expr\MagicScriptConst
            || $op instanceof Op\Expr\Assign
            || $op instanceof Op\Expr\PostInc
            || $op instanceof Op\Expr\PreInc
            || $op instanceof Op\Expr\PostDec
            || $op instanceof Op\Expr\PreDec
            || $op instanceof Op\Expr\ConcatList;
    }

    /**
     * Multi-arg call after MethodCall with an ArrayDimFetch sibling between them (#28821).
     *
     * @param Op[] $ops
     */
    private function multiArgConsumerAfterMethodCallDimFetchSibling(array $ops, int $producerIndex): ?int
    {
        $opCount = \count($ops);
        $sawDimFetch = false;
        for ($j = $producerIndex + 1; $j < $opCount; ++$j) {
            $next = $ops[$j] ?? null;
            if ($next instanceof Op\Expr\ArrayDimFetch) {
                $sawDimFetch = true;
                continue;
            }
            if (
                $next instanceof Op\Expr\FuncCall
                || $next instanceof Op\Expr\NsFuncCall
                || $next instanceof Op\Expr\MethodCall
                || $next instanceof Op\Expr\StaticCall
            ) {
                if (!$sawDimFetch) {
                    return null;
                }
                if (
                    !$this->isSiblingMultiArgInlineCallConsumer($next)
                    || !\is_array($next->args ?? null)
                    || \count($next->args) < 2
                    || $this->deadInlineTemporaryArgCount($next) < 2
                ) {
                    // Single-arg count() etc. — keep looking for var_dump/f(...).
                    if (
                        ($next instanceof Op\Expr\FuncCall || $next instanceof Op\Expr\NsFuncCall)
                        && (!\is_array($next->args ?? null) || \count($next->args) < 2)
                    ) {
                        continue;
                    }

                    return null;
                }
                // Distance must stay within dead-temp window (+ trailing FuncCall siblings).
                if (($j - $producerIndex) > $this->deadInlineTemporaryArgCount($next) + 1) {
                    return null;
                }

                return $j;
            }
            if (
                $next instanceof Op\Expr\ConstFetch
                || $next instanceof Op\Expr\ClassConstFetch
                || $next instanceof Op\Expr\PropertyFetch
                || $next instanceof Op\Expr\NullsafePropertyFetch
                || $next instanceof Op\Expr\StaticPropertyFetch
                || $this->isUnaryInlineSiblingCallArgExpr($next)
            ) {
                continue;
            }

            return null;
        }

        return null;
    }

    /**
     * @param Op[] $ops
     */
    private function deferredSiblingInlineCallArgConsumerIndex(Op $op, array $ops, int $producerIndex): ?int
    {
        $cacheKey = spl_object_id($op);
        if (\array_key_exists($cacheKey, $this->deferredSiblingInlineCallArgConsumerIndexCache)) {
            $cached = $this->deferredSiblingInlineCallArgConsumerIndexCache[$cacheKey];

            return $cached < 0 ? null : $cached;
        }
        $result = $this->computeDeferredSiblingInlineCallArgConsumerIndex($op, $ops, $producerIndex);
        $this->deferredSiblingInlineCallArgConsumerIndexCache[$cacheKey] = null === $result ? -1 : $result;

        return $result;
    }

    /**
     * Forward scan for the multi-arg consumer that should compile a hoisted sibling producer.
     *
     * Bound the walk: isSibling rejects pairs beyond max(8, arity×4), and scanning every later
     * FuncCall in a block of repeated nested call stmts was O(n²) PHP call overhead (#36387).
     *
     * @param Op[] $ops
     */
    private function computeDeferredSiblingInlineCallArgConsumerIndex(Op $op, array $ops, int $producerIndex): ?int
    {
        if (!$this->isSiblingInlineCallProducerExpr($op)) {
            return null;
        }
        if (
            ($op instanceof Op\Expr\FuncCall || $op instanceof Op\Expr\NsFuncCall)
            && $this->funcCallExprHasByRefMutatingSideEffects($op)
        ) {
            return null;
        }
        $opCount = \count($ops);
        // Hard cap past isSibling's practical maxDistance (arity×4); prelude-heavy nests stay inside.
        $scanLimit = min($opCount, $producerIndex + 1 + 32);
        for ($j = $producerIndex + 1; $j < $scanLimit; ++$j) {
            $next = $ops[$j];
            if ($next instanceof Op\Expr\FuncCall || $next instanceof Op\Expr\NsFuncCall) {
                if ($this->isInlineExprCallArgConsumer($next)
                    && (
                        $this->isSiblingMultiArgFuncCallProducer($op, $next, $producerIndex, $j, $ops)
                        || $this->isNestedCallArgProducerSeparatedByConsumerLiteralPreludes(
                            $op,
                            $next,
                            $producerIndex,
                            $j,
                            $ops
                        )
                        || (
                            $op instanceof Op\Expr
                            && $this->isIifeHoistedFuncCallArgProducer(
                                $op,
                                $next,
                                $producerIndex,
                                $j,
                                $ops
                            )
                        )
                        // var_export($e->getAttributeNode()->isId(), true) — leaf MethodCall before
                        // ConstFetch true; nestedSep ordinal can miss the distinct result/arg temp (#25841).
                        || (
                            $op instanceof Op\Expr\MethodCall
                            && $this->onlyScalarConstFetchPreludesBetween($producerIndex, $j, $ops)
                            && property_exists($next, 'args')
                            && \is_array($next->args)
                            && \count($next->args) >= 2
                            && $this->callArgIsDeadInlineTemporary($next->args[0] ?? null)
                        )
                    )
                ) {
                    return $j;
                }

                // var_dump($g(), $g()) — keep scanning past 0/1-arg sibling producers toward the
                // multi-arg consumer. A non-matching ≥2-arg call is the next statement's nest
                // (or an unrelated consumer) — stop so nested stmt blocks stay O(n) (#36387).
                $nextArgCount = property_exists($next, 'args') && \is_array($next->args)
                    ? \count($next->args)
                    : 0;
                if ($nextArgCount >= 2) {
                    break;
                }
                continue;
            }
            if ($next instanceof Op\Expr\MethodCall || $next instanceof Op\Expr\StaticCall) {
                if ($this->isSiblingMultiArgFuncCallProducer($op, $next, $producerIndex, $j, $ops)) {
                    // ConstFetch-null/true/false between MethodCalls: only defer when the leaf feeds
                    // the consumer (importNode item+true — #25702). Skip stmt appendChild (#26458).
                    if (
                        $op instanceof Op\Expr\MethodCall
                        && $this->onlyScalarConstFetchPreludesBetween($producerIndex, $j, $ops)
                        && !$this->methodCallFeedsMultiArgConsumerAcrossScalarConstFetch($op, $next)
                    ) {
                        continue;
                    }
                    return $j;
                }
                // importNode(...->item(0), true) — only true/false/null ConstFetch between leaf MethodCall
                // and consumer; detect structurally (isNestedCallArg can fail under firstSibling reentry) (#25702).
                // Require the leaf to feed the consumer — bare stmt MethodCalls such as appendChild
                // before insertBefore($x, null) must not be deferred (#26458).
                if (
                    $op instanceof Op\Expr\MethodCall
                    && $this->isSiblingMultiArgInlineCallConsumer($next)
                    && $this->onlyScalarConstFetchPreludesBetween($producerIndex, $j, $ops)
                    && $this->methodCallFeedsMultiArgConsumerAcrossScalarConstFetch($op, $next)
                ) {
                    return $j;
                }
                // Non-matching multi-arg MethodCall/StaticCall ends the deferred-consumer search (#36387).
                $nextArgCount = property_exists($next, 'args') && \is_array($next->args)
                    ? \count($next->args)
                    : 0;
                if ($nextArgCount >= 2) {
                    break;
                }
                continue;
            }
            if ($this->isSiblingInlineCallProducerExpr($next)) {
                continue;
            }
            if ($this->isUnaryInlineSiblingCallArgExpr($next)) {
                continue;
            }
            if ($next instanceof Op\Expr\Array_) {
                continue;
            }
            if ($next instanceof Op\Expr\ConstFetch || $next instanceof Op\Expr\ClassConstFetch) {
                continue;
            }
            if ($next instanceof Op\Expr\New_ || $next instanceof Op\Expr\Clone_) {
                continue;
            }
            // var_dump($s->contains($o), $s[$o], count($s)) — dim is a sibling arg, not a chain end (#28821).
            // Do not skip PropertyFetch: saveXML($d->documentElement, LIBXML_*) must not treat a
            // prior stmt MethodCall (loadXML) as a deferred sibling across the property + LIBXML_*
            // preludes — that steals ARG_SEND and dumps the full document (re-#25292 / #29076).
            if ($next instanceof Op\Expr\ArrayDimFetch) {
                continue;
            }
            if ($next instanceof Op\Expr\ArrowFunction
                || $next instanceof Op\Expr\Closure
                || $next instanceof Op\Expr\FirstClassCallable) {
                // array_udiff(array_keys(...), array_keys(...), strcmp(...)) — trailing FCC (#15475, #13990).
                continue;
            }
            break;
        }

        return null;
    }

    /**
     * @param list<Op> $cfgChildren
     */
    private function countSiblingInlineFuncCallProducers(
        int $firstSibling,
        int $consumerIndex,
        array $cfgChildren
    ): int {
        $deadInlineArgCount = $this->deadInlineTemporaryArgCount($cfgChildren[$consumerIndex] ?? null);
        $count = 0;
        for ($j = $firstSibling; $j < $consumerIndex; ++$j) {
            $child = $cfgChildren[$j] ?? null;
            if (!$this->isSiblingInlineCallProducerExpr($child)) {
                continue;
            }
            if (
                $child instanceof Op\Expr\MethodCall
                && $this->methodCallIsSkippedHoistedSiblingProducer(
                    $child,
                    $j,
                    $consumerIndex,
                    $deadInlineArgCount,
                    $cfgChildren
                )
            ) {
                continue;
            }
            if (
                ($child instanceof Op\Expr\FuncCall || $child instanceof Op\Expr\NsFuncCall)
                && $this->builtinUsesTrailingComparatorCallback($this->resolveCfgFuncCallName($child))
            ) {
                continue;
            }
            if ($this->siblingInlineFuncCallSkipsExecReturnOrdinal($child, $j, $cfgChildren)) {
                continue;
            }
            if (
                !($child instanceof Op\Expr\MethodCall)
                && $this->siblingInlineCallProducerSkipsHoistedArgChain($child, $cfgChildren[$j + 1] ?? null)
            ) {
                continue;
            }
            if (
                ($child instanceof Op\Expr\FuncCall || $child instanceof Op\Expr\NsFuncCall)
                && $this->isStatementLevelSideEffectFuncCall($child)
            ) {
                // Never count stmt-level side effects as hoisted arg producers (#25084, #16480).
                continue;
            }
            ++$count;
        }

        return $count;
    }

    /**
     * True when php-cfg hoisted sibling Array_ literals between FuncCall producers (#13778).
     *
     * @param list<Op> $cfgChildren
     */
    private function siblingFuncCallChainHasArrayPrelude(
        int $firstSibling,
        int $consumerIndex,
        array $cfgChildren
    ): bool {
        for ($j = $firstSibling; $j < $consumerIndex; ++$j) {
            if (($cfgChildren[$j] ?? null) instanceof Op\Expr\Array_) {
                return true;
            }
        }

        return false;
    }

    /**
     * 0-based ordinal among hoisted sibling FuncCall producers (skips Array_/ConstFetch between calls).
     *
     * @param list<Op> $cfgChildren
     */
    /**
     * Stmt-level var_dump(g()) — adjacent nested void consumer, no FUNCCALL_EXEC_RETURN slot (#9390).
     *
     * var_export(f()) still emits EXEC_RETURN and must stay in the ordinal map (#8796).
     *
     * @param list<Op> $cfgChildren
     */
    private function siblingInlineFuncCallSkipsExecReturnOrdinal(
        Op $child,
        int $childIndex,
        array $cfgChildren
    ): bool {
        if (!$child instanceof Op\Expr\FuncCall && !$child instanceof Op\Expr\NsFuncCall) {
            return false;
        }
        if ($childIndex < 1) {
            return false;
        }
        $prev = $cfgChildren[$childIndex - 1] ?? null;
        if (!$prev instanceof Op\Expr\FuncCall && !$prev instanceof Op\Expr\NsFuncCall) {
            return false;
        }
        if (!$this->isAdjacentNestedFuncCallProducer($prev, $child, $childIndex - 1, $childIndex)) {
            return false;
        }
        if ('var_dump' !== strtolower($this->resolveCfgFuncCallName($child) ?? '')) {
            return false;
        }
        // var_dump($g(), $h()) — multi-arg sibling wiring still uses EXEC_RETURN (#16029).
        if (\is_array($child->args ?? null) && \count($child->args) >= 2) {
            return false;
        }

        return true;
    }

    private function siblingInlineFuncCallProducerOrdinal(
        int $producerIndex,
        int $firstSibling,
        array $cfgChildren,
        ?int $consumerIndex = null
    ): int {
        if (null === $consumerIndex) {
            // Bound: ordinal consumer sits near the producer (#36387).
            $n = \count($cfgChildren);
            $scanEnd = min($n, $producerIndex + 1 + 32);
            for ($k = $producerIndex + 1; $k < $scanEnd; ++$k) {
                $cand = $cfgChildren[$k] ?? null;
                if (!$this->isSiblingMultiArgInlineCallConsumer($cand)) {
                    continue;
                }
                // Skip 0-arg MethodCall leaves (isId) so ordinals use the outer multi-arg FuncCall
                // (var_export(..., true)) — otherwise UNKNOWN-typed receivers get ord=-1 (#25928).
                if (
                    ($cand instanceof Op\Expr\MethodCall || $cand instanceof Op\Expr\StaticCall)
                    && $this->deadInlineTemporaryArgCount($cand) < 1
                ) {
                    continue;
                }
                $consumerIndex = $k;
                break;
            }
        }
        $consumerIndex ??= $producerIndex + 1;
        $deadInlineArgCount = $this->deadInlineTemporaryArgCount($cfgChildren[$consumerIndex] ?? null);
        $ordinal = -1;
        for ($j = $firstSibling; $j <= $producerIndex; ++$j) {
            $child = $cfgChildren[$j] ?? null;
            if (!$this->isSiblingInlineCallProducerExpr($child)) {
                continue;
            }
            if (
                $child instanceof Op\Expr\MethodCall
                && $this->methodCallIsSkippedHoistedSiblingProducer(
                    $child,
                    $j,
                    $consumerIndex,
                    $deadInlineArgCount,
                    $cfgChildren
                )
            ) {
                continue;
            }
            if (
                ($child instanceof Op\Expr\FuncCall || $child instanceof Op\Expr\NsFuncCall)
                && $this->builtinUsesTrailingComparatorCallback($this->resolveCfgFuncCallName($child))
            ) {
                continue;
            }
            if ($this->siblingInlineFuncCallSkipsExecReturnOrdinal($child, $j, $cfgChildren)) {
                continue;
            }
            if (
                !($child instanceof Op\Expr\MethodCall)
                && $this->siblingInlineCallProducerSkipsHoistedArgChain($child, $cfgChildren[$j + 1] ?? null)
            ) {
                continue;
            }
            ++$ordinal;
        }

        return $ordinal;
    }

    /**
     * Outer hoisted sibling FuncCall producers — skip inner g() in f(g()) chains (#15488).
     *
     * @param list<Op> $cfgChildren
     *
     * @return list<Op\Expr>
     */
    private function outerSiblingInlineFuncCallProducers(
        int $firstSibling,
        int $consumerIndex,
        array $cfgChildren
    ): array {
        $deadInlineArgCount = $this->deadInlineTemporaryArgCount($cfgChildren[$consumerIndex] ?? null);
        $producers = [];
        for ($j = $firstSibling; $j < $consumerIndex; ++$j) {
            $child = $cfgChildren[$j] ?? null;
            if (!$this->isSiblingInlineCallProducerExpr($child) || !$child instanceof Op\Expr) {
                continue;
            }
            if (
                $child instanceof Op\Expr\MethodCall
                && $this->methodCallIsSkippedHoistedSiblingProducer(
                    $child,
                    $j,
                    $consumerIndex,
                    $deadInlineArgCount,
                    $cfgChildren
                )
            ) {
                continue;
            }
            if (
                ($child instanceof Op\Expr\FuncCall || $child instanceof Op\Expr\NsFuncCall)
                && $this->builtinUsesTrailingComparatorCallback($this->resolveCfgFuncCallName($child))
            ) {
                continue;
            }
            if ($this->siblingInlineFuncCallSkipsExecReturnOrdinal($child, $j, $cfgChildren)) {
                continue;
            }
            $next = $cfgChildren[$j + 1] ?? null;
            if (
                $j + 1 < $consumerIndex
                && ($next instanceof Op\Expr\Assign || $next instanceof Op\Expr\AssignRef)
                && null !== $child->result
                && null !== $next->expr
                && $this->operandsReferToSameVariable($child->result, $next->expr)
            ) {
                // $loose = in_array(...); array_search(null, [null]) — stmt-level callee, not outer arg (#11058).
                continue;
            }
            if (
                $j + 1 < $consumerIndex
                && ($next instanceof Op\Expr\FuncCall || $next instanceof Op\Expr\NsFuncCall)
                && $this->isAdjacentNestedFuncCallProducer($child, $next, $j, $j + 1)
            ) {
                continue;
            }
            if (
                !($child instanceof Op\Expr\MethodCall)
                && $this->siblingInlineCallProducerSkipsHoistedArgChain($child, $next)
            ) {
                continue;
            }
            $producers[] = $child;
        }

        return $producers;
    }

    /**
     * @param list<Op> $cfgChildren
     */
    private function outerSiblingInlineFuncCallProducerOrdinal(
        Op\Expr $producer,
        int $firstSibling,
        int $consumerIndex,
        array $cfgChildren
    ): ?int {
        foreach ($this->outerSiblingInlineFuncCallProducers($firstSibling, $consumerIndex, $cfgChildren) as $ordinal => $candidate) {
            if ($candidate === $producer) {
                return $ordinal;
            }
        }

        return null;
    }

    /**
     * @param list<Op> $cfgChildren
     */
    private function siblingInlineFuncCallProducerIndexAtOrdinal(
        int $ordinal,
        int $firstSibling,
        int $consumerIndex,
        array $cfgChildren
    ): ?int {
        $seen = -1;
        for ($j = $firstSibling; $j < $consumerIndex; ++$j) {
            $child = $cfgChildren[$j] ?? null;
            if (!$this->isSiblingInlineCallProducerExpr($child)) {
                continue;
            }
            if (
                ($child instanceof Op\Expr\FuncCall || $child instanceof Op\Expr\NsFuncCall)
                && $this->builtinUsesTrailingComparatorCallback($this->resolveCfgFuncCallName($child))
            ) {
                continue;
            }
            if ($this->siblingInlineFuncCallSkipsExecReturnOrdinal($child, $j, $cfgChildren)) {
                continue;
            }
            ++$seen;
            if ($seen === $ordinal) {
                return $j;
            }
        }

        return null;
    }

    /**
     * @param list<Op> $cfgChildren
     */
    private function siblingInlineFuncCallProducerOrdinalAtIndex(
        int $producerIndex,
        int $firstSibling,
        int $consumerIndex,
        array $cfgChildren
    ): ?int {
        $seen = -1;
        for ($j = $firstSibling; $j < $consumerIndex; ++$j) {
            $child = $cfgChildren[$j] ?? null;
            if (!$this->isSiblingInlineCallProducerExpr($child)) {
                continue;
            }
            if (
                ($child instanceof Op\Expr\FuncCall || $child instanceof Op\Expr\NsFuncCall)
                && $this->builtinUsesTrailingComparatorCallback($this->resolveCfgFuncCallName($child))
            ) {
                continue;
            }
            if ($this->siblingInlineFuncCallSkipsExecReturnOrdinal($child, $j, $cfgChildren)) {
                continue;
            }
            ++$seen;
            if ($j === $producerIndex) {
                return $seen;
            }
        }

        return null;
    }

    /**
     * @param list<Operand|null> $args
     */
    private function deadInlineTemporaryArgOrdinalBeforeIndex(array $args, int $argIndex): int
    {
        $ordinal = 0;
        for ($i = 0; $i < $argIndex; ++$i) {
            if ($this->callArgIsDeadInlineTemporary($args[$i] ?? null)) {
                ++$ordinal;
            }
        }

        return $ordinal;
    }

    private function callHasNamedVariableArgument(Op $cfgCallOp): bool
    {
        if (!property_exists($cfgCallOp, 'args') || !\is_array($cfgCallOp->args)) {
            return false;
        }
        foreach ($cfgCallOp->args as $callArg) {
            if ($callArg instanceof Operand && $this->isNamedVariableOperand($callArg)) {
                return true;
            }
        }

        return false;
    }

    /**
     * array_multisort([..], $labels = [..]) — map dead inline arg index to hoisted Array_ (#15151).
     *
     * php-cfg lowers assign-in-call between the second literal and FuncCall; the first literal is
     * not stmt-immediate-before the call.
     */
    private function inlineArrayMultisortLiteralProducerForArg(?Op $cfgCallOp, Block $block, int $argIndex): ?Op\Expr\Array_
    {
        if (null === $cfgCallOp || null === $block->orig || $argIndex < 0) {
            return null;
        }
        $callIndex = $this->cfgCallOpIndexInChildren($block->orig->children, $cfgCallOp, $block->orig);
        if (null === $callIndex) {
            return null;
        }
        $arrays = [];
        for ($i = 0; $i < $callIndex; ++$i) {
            $child = $block->orig->children[$i];
            if ($child instanceof Op\Expr\Array_) {
                $arrays[] = $child;
            }
        }

        return $arrays[$argIndex] ?? null;
    }

    /**
     * array_diff_assoc(array_keys([..]), array_keys([..])) — hoisted dual Array_ preludes share stmt-before (#16418).
     */
    private function inlineArrayProducerForArrayKeysDeadCallArg(
        Operand $arg,
        Block $block,
        Op $cfgCallOp
    ): ?Op\Expr\Array_ {
        if ($this->callArgIsCoalesceMergeProducer($arg, $block, $cfgCallOp, 0)) {
            return null;
        }
        $cfgChildren = $this->inlineCallArgProducerCfgChildren($block);
        if ([] === $cfgChildren && null !== $block->orig) {
            $cfgChildren = $block->orig->children;
        }
        if ([] === $cfgChildren) {
            return null;
        }
        $callIndex = $this->cfgCallOpIndexInChildren($cfgChildren, $cfgCallOp);
        if (!is_int($callIndex)) {
            return null;
        }
        for ($i = 0; $i < $callIndex; ++$i) {
            $child = $cfgChildren[$i] ?? null;
            if (
                $child instanceof Op\Expr\Array_
                && null !== $child->result
                && $this->operandsReferToSameVariable($child->result, $arg)
            ) {
                return $child;
            }
        }
        $arrayProducers = [];
        for ($i = 0; $i < $callIndex; ++$i) {
            $child = $cfgChildren[$i] ?? null;
            if ($child instanceof Op\Expr\Array_) {
                $arrayProducers[] = $child;
            }
        }
        if ([] === $arrayProducers) {
            return null;
        }
        $stmtBefore = $this->inlineArrayProducerImmediatelyBeforeCfgCall($cfgCallOp, $block);
        if ($stmtBefore instanceof Op\Expr\Array_) {
            $stmtIndex = array_search($stmtBefore, $arrayProducers, true);
            if (is_int($stmtIndex)) {
                return $arrayProducers[$stmtIndex];
            }
        }
        $priorArrayKeys = 0;
        for ($j = $callIndex - 1; $j >= 0; --$j) {
            $child = $cfgChildren[$j] ?? null;
            if (
                ($child instanceof Op\Expr\FuncCall || $child instanceof Op\Expr\NsFuncCall)
                && 'array_keys' === $this->resolveCfgFuncCallName($child)
            ) {
                ++$priorArrayKeys;
                continue;
            }
            if (
                $child instanceof Op\Expr\Array_
                || $child instanceof Op\Expr\ConstFetch
                || $child instanceof Op\Expr\ClassConstFetch
                || $this->isUnaryInlineSiblingCallArgExpr($child)
            ) {
                continue;
            }
            break;
        }
        $arrayOrdinal = $priorArrayKeys;

        return $arrayProducers[$arrayOrdinal] ?? null;
    }

    /** Hoisted inline Array_ ordinal for array_keys() dead arg — CFG stmt index, not operand identity (#16418). */
    private function inlineArrayKeysHoistedArrayOrdinal(Block $block, Op $cfgCallOp): ?int
    {
        $cfgChildren = $this->inlineCallArgProducerCfgChildren($block);
        if ([] === $cfgChildren && null !== $block->orig) {
            $cfgChildren = $block->orig->children;
        }
        if ([] === $cfgChildren) {
            return null;
        }
        $callIndex = $this->cfgCallOpIndexInChildren($cfgChildren, $cfgCallOp);
        if (!is_int($callIndex)) {
            return null;
        }
        $arrayCount = 0;
        for ($i = 0; $i < $callIndex; ++$i) {
            if (($cfgChildren[$i] ?? null) instanceof Op\Expr\Array_) {
                ++$arrayCount;
            }
        }
        if ($arrayCount < 1) {
            return null;
        }
        $stmtBefore = $this->inlineArrayProducerImmediatelyBeforeCfgCall($cfgCallOp, $block);
        if ($stmtBefore instanceof Op\Expr\Array_) {
            $stmtOrdinal = 0;
            for ($i = 0; $i < $callIndex; ++$i) {
                $child = $cfgChildren[$i] ?? null;
                if ($child instanceof Op\Expr\Array_) {
                    if ($child === $stmtBefore) {
                        return $stmtOrdinal;
                    }
                    ++$stmtOrdinal;
                }
            }
        }
        $priorArrayKeys = 0;
        for ($j = $callIndex - 1; $j >= 0; --$j) {
            $child = $cfgChildren[$j] ?? null;
            if (
                ($child instanceof Op\Expr\FuncCall || $child instanceof Op\Expr\NsFuncCall)
                && 'array_keys' === $this->resolveCfgFuncCallName($child)
            ) {
                ++$priorArrayKeys;
                continue;
            }
            if (
                $child instanceof Op\Expr\Array_
                || $child instanceof Op\Expr\ConstFetch
                || $child instanceof Op\Expr\ClassConstFetch
                || $this->isUnaryInlineSiblingCallArgExpr($child)
            ) {
                continue;
            }
            break;
        }

        return $priorArrayKeys < $arrayCount ? $priorArrayKeys : null;
    }

    /** array_merge(['a'=>1], array_keys(...)) — two Array_ preludes before sibling array_keys() (#13760, #16418). */
    private function arrayMergeHasLeadingInlineArrayBeforeArrayKeysSibling(Block $block, Op $cfgCallOp): bool
    {
        if (null === $block->orig) {
            return false;
        }
        $callee = strtolower($this->resolveCfgFuncCallName($cfgCallOp) ?? '');
        if (!\in_array($callee, ['array_merge', 'array_merge_recursive', 'array_replace', 'array_replace_recursive'], true)) {
            return false;
        }
        $callIndex = $this->cfgCallOpIndexInChildren($block->orig->children, $cfgCallOp, $block->orig);
        if (!is_int($callIndex)) {
            return false;
        }
        for ($mj = $callIndex - 1; $mj >= 0; --$mj) {
            $child = $block->orig->children[$mj] ?? null;
            if (
                ($child instanceof Op\Expr\FuncCall || $child instanceof Op\Expr\NsFuncCall)
                && 'array_keys' === $this->resolveCfgFuncCallName($child)
            ) {
                $arrayCount = 0;
                for ($k = 0; $k < $mj; ++$k) {
                    if (($block->orig->children[$k] ?? null) instanceof Op\Expr\Array_) {
                        ++$arrayCount;
                    }
                }

                return $arrayCount >= 2;
            }
            if (
                $child instanceof Op\Expr\Array_
                || $child instanceof Op\Expr\ConstFetch
                || $child instanceof Op\Expr\ClassConstFetch
                || $this->isUnaryInlineSiblingCallArgExpr($child)
                || $this->isSiblingInlineCallProducerExpr($child)
            ) {
                continue;
            }
            break;
        }

        return false;
    }

    /**
     * CFG index after the most recent completed u* diff/intersect stmt (#16045, re-#14021).
     *
     * @param list<Op> $cfgChildren
     */
    private function cfgStartIndexAfterLastTrailingComparatorStmt(int $beforeIndex, array $cfgChildren): int
    {
        for ($i = $beforeIndex - 1; $i >= 0; --$i) {
            $child = $cfgChildren[$i] ?? null;
            if (
                ($child instanceof Op\Expr\FuncCall || $child instanceof Op\Expr\NsFuncCall)
                && $this->builtinUsesTrailingComparatorCallback($this->resolveCfgFuncCallName($child))
            ) {
                return $i + 1;
            }
        }

        return 0;
    }

    /**
     * Skip INIT_ARRAY ordinals from inline Array_ preludes before a prior u* stmt (#14021).
     *
     * @param list<Op> $cfgChildren
     */
    private function initArrayOrdinalOffsetBeforeTrailingComparatorStmt(int $callIndex, array $cfgChildren): int
    {
        $cfgStart = $this->cfgStartIndexAfterLastTrailingComparatorStmt($callIndex, $cfgChildren);
        if ($cfgStart <= 0) {
            return 0;
        }
        $offset = 0;
        for ($i = 0; $i < $cfgStart; ++$i) {
            if (($cfgChildren[$i] ?? null) instanceof Op\Expr\Array_) {
                ++$offset;
            }
        }

        return $offset;
    }

    /** Nth TYPE_INIT_ARRAY in pending emits + block — hoisted sibling array_keys() preludes (#16418). */
    private function slotForInitArrayOrdinal(Block $block, int $targetOrdinal, array $pendingOps = []): ?string
    {
        if ($targetOrdinal < 0) {
            return null;
        }
        $seen = 0;
        foreach ($pendingOps as $op) {
            if (OpCode::TYPE_INIT_ARRAY !== $op->type) {
                continue;
            }
            if ($seen === $targetOrdinal) {
                return (string) $op->arg1;
            }
            ++$seen;
        }
        foreach ($block->opCodes as $op) {
            if (OpCode::TYPE_INIT_ARRAY !== $op->type) {
                continue;
            }
            if ($seen === $targetOrdinal) {
                return (string) $op->arg1;
            }
            ++$seen;
        }

        return null;
    }

    /**
     * php-cfg `array_keys([...])` hoists the literal Array_ stmt immediately before the call (#13778).
     * in_array('a', ['a','b'], true) may hoist ConstFetch between Array_ and FuncCall (#15422).
     */
    private function inlineArrayProducerImmediatelyBeforeCfgCall(?Op $callOp, Block $block): ?Op\Expr\Array_
    {
        if (null === $callOp) {
            return null;
        }
        $cfgChildren = $this->inlineCallArgProducerCfgChildren($block);
        if ([] === $cfgChildren) {
            return null;
        }
        $callIndex = $this->cfgCallOpIndexInChildren($cfgChildren, $callOp);
        if (null === $callIndex || $callIndex < 1) {
            return null;
        }
        for ($probe = $callIndex - 1; $probe >= 0; --$probe) {
            $prev = $cfgChildren[$probe] ?? null;
            if ($prev instanceof Op\Expr\ConstFetch || $prev instanceof Op\Expr\ClassConstFetch) {
                continue;
            }
            if ($prev instanceof Op\Expr\Cast) {
                continue;
            }
            if ($this->isUnaryInlineSiblingCallArgExpr($prev)) {
                continue;
            }
            if ($prev instanceof Op\Expr\Array_) {
                return $prev;
            }

            break;
        }

        return null;
    }

    /**
     * Array_ feeding a dead inline call arg — stmt-before, embedded literal, or cfg producer (#14516).
     */
    private function inlineArrayLiteralForDeadCallArg(Op $callOp, int $argIndex, Block $block): ?Op\Expr\Array_
    {
        if (
            'preg_replace_callback_array' === $this->resolveCfgFuncCallName($callOp)
            && 0 === $argIndex
        ) {
            $patternArg = $callOp->args[0] ?? null;
            if ($patternArg instanceof Operand) {
                $embedded = $this->unwrapArrayLiteralExpr($patternArg);
                if ($embedded instanceof Op\Expr\Array_) {
                    return $embedded;
                }
            }
            $immediate = $this->inlineArrayProducerImmediatelyBeforeCfgCall($callOp, $block);
            if ($immediate instanceof Op\Expr\Array_) {
                return $immediate;
            }
        }
        if (
            'proc_open' === $this->resolveCfgFuncCallName($callOp)
            && \in_array($argIndex, [0, 1], true)
            && \is_array($callOp->args ?? null)
        ) {
            if (0 === $argIndex) {
                $commandArg = $callOp->args[0] ?? null;
                if ($commandArg instanceof Operand) {
                    $embeddedCommand = $this->unwrapArrayLiteralExpr($commandArg);
                    if ($embeddedCommand instanceof Op\Expr\Array_) {
                        return $embeddedCommand;
                    }
                }
            }
            if (null !== $block->orig) {
                $procOpenProducers = $this->precedingInlineCallArgProducersBeforeCfgOp(
                    $block->orig->children,
                    $callOp
                );
                $matched = $this->matchInlineArrayProducersToArrayCallArgs(
                    $procOpenProducers,
                    $callOp->args,
                    $argIndex
                );
                if ($matched instanceof Op\Expr\Array_) {
                    return $matched;
                }
            }
        }
        $immediate = $this->inlineArrayProducerImmediatelyBeforeCfgCall($callOp, $block);
        if ($immediate instanceof Op\Expr\Array_) {
            if (null !== $block->orig) {
                $producers = $this->precedingInlineCallArgProducersBeforeCfgOp($block->orig->children, $callOp);
                if (2 === \count($producers)) {
                    $mapped = $this->matchArrayMergeFuncCallAndArrayInlineProducers($producers, $argIndex);
                    if ($mapped instanceof Op\Expr\Array_ && $mapped === $immediate) {
                        return $immediate;
                    }
                    if ($mapped instanceof Op\Expr\FuncCall || $mapped instanceof Op\Expr\NsFuncCall) {
                        return null;
                    }
                }
            }
            $callArg = $callOp->args[$argIndex] ?? null;
            if (
                null !== $callArg
                && null !== $immediate->result
                && $this->operandsReferToSameVariable($immediate->result, $callArg)
            ) {
                return $immediate;
            }
            // array_keys([...]) / array_diff_assoc(array_keys(...), array_keys(...)) — stmt-before Array_
            // for dead php-cfg temps without shared cfgVar roots (#13778, #13779, #15569).
            if (
                null !== $callArg
                && $this->callArgIsDeadInlineTemporary($callArg)
                && $this->callArgOperandExpectsArrayProducer($callArg)
                && !$this->inlineArrayLiteralStmtBeforeOverriddenBySiblingCallProducer(
                    $callOp,
                    $argIndex,
                    $block
                )
            ) {
                return $immediate;
            }
        }
        $callArg = $callOp->args[$argIndex] ?? null;
        if (!$callArg instanceof Operand) {
            return null;
        }
        $embedded = $this->unwrapArrayLiteralExpr($callArg);
        if ($embedded instanceof Op\Expr\Array_) {
            return $embedded;
        }
        $producer = $this->findCfgProducerExprForOperand($callArg);

        return $producer instanceof Op\Expr\Array_ ? $producer : null;
    }


    /**
     * array_map(null, [[..]]) — null ConstFetch is callback arg #0, not a nested-call prelude (#9143, #15976).
     */
    private function arrayMapNullCallbackPrecedesInlineHaystack(?Op $callOp, ?Block $block): bool
    {
        return null !== $callOp
            && null !== $block
            && 'array_map' === strtolower($this->resolveCfgFuncCallName($callOp) ?? '')
            && null !== $this->arrayMapNullCallbackProducerBeforeCfgCall($callOp, $block);
    }

    /**
     * array_map(null, null, …) / array_map(null, [[..]]) — inline null ConstFetch preludes in CFG order (#9143, #16226).
     *
     * @return list<Op\Expr\ConstFetch>
     */
    private function arrayMapInlineNullConstFetchProducersBeforeCfgCall(Op $cfgCallOp, Block $block): array
    {
        if (null === $block->orig) {
            return [];
        }
        $callbackArg = $cfgCallOp->args[0] ?? null;
        if ($this->isEmbeddedCallLiteralArg($callbackArg)) {
            return [];
        }
        $leadingCallback = $this->leadingCallbackFirstInlineProducerBeforeCfgCall($cfgCallOp, $block);
        if ($leadingCallback instanceof Op\Expr\ArrowFunction
            || $leadingCallback instanceof Op\Expr\Closure
            || $leadingCallback instanceof Op\Expr\FirstClassCallable) {
            return [];
        }
        $callIndex = $this->cfgCallOpIndexInChildren($block->orig->children, $cfgCallOp, $block->orig);
        if (null === $callIndex) {
            return [];
        }
        $nulls = [];
        for ($i = $callIndex - 1; $i >= 0; --$i) {
            $child = $block->orig->children[$i];
            if ($child instanceof Op\Expr\ConstFetch) {
                $constName = $this->staticNameFromOperand($child->name);
                if (null !== $constName && 'null' === strtolower($constName)) {
                    array_unshift($nulls, $child);
                    continue;
                }
            }
            if ($child instanceof Op\Expr\Array_) {
                continue;
            }
            if (!$child instanceof Op\Expr || !$this->isInlineExprCallArgProducer($child)) {
                break;
            }
        }

        return $nulls;
    }

    /**
     * array_map(null, [[..]]) — hoisted null ConstFetch precedes nested Array_ preludes (#9143).
     */
    private function arrayMapNullCallbackProducerBeforeCfgCall(Op $cfgCallOp, Block $block): ?Op\Expr\ConstFetch
    {
        $nulls = $this->arrayMapInlineNullConstFetchProducersBeforeCfgCall($cfgCallOp, $block);
        $first = $nulls[0] ?? null;

        return $first instanceof Op\Expr\ConstFetch ? $first : null;
    }

    /**
     * array_map(null, null, [..]) — inline null haystack operand, not zip Array_ (#16226).
     */
    private function arrayMapInlineNullHaystackProducerForArgIndex(
        Op $cfgCallOp,
        Block $block,
        int $argIndex
    ): ?Op\Expr\ConstFetch {
        if ($argIndex < 1 || !property_exists($cfgCallOp, 'args') || !is_array($cfgCallOp->args)) {
            return null;
        }
        $callArg = $cfgCallOp->args[$argIndex] ?? null;
        if (!$callArg instanceof Operand || $this->isEmbeddedCallLiteralArg($callArg)) {
            return null;
        }
        if ($this->callArgOperandExpectsArrayProducer($callArg)) {
            return null;
        }
        $nullProducers = $this->arrayMapInlineNullConstFetchProducersBeforeCfgCall($cfgCallOp, $block);
        if (\count($nullProducers) < 2) {
            return null;
        }
        $nullHaystackOrdinal = 0;
        for ($i = 1; $i < $argIndex; ++$i) {
            $prior = $cfgCallOp->args[$i] ?? null;
            if (!$prior instanceof Operand || $this->isEmbeddedCallLiteralArg($prior)) {
                continue;
            }
            if (!$this->callArgOperandExpectsArrayProducer($prior)) {
                ++$nullHaystackOrdinal;
            }
        }
        $targetIndex = 1 + $nullHaystackOrdinal;
        $candidate = $nullProducers[$targetIndex] ?? null;

        return $candidate instanceof Op\Expr\ConstFetch ? $candidate : null;
    }

    /**
     * php-cfg array_intersect(f(g()), f(g())) — map arg N to outer hoisted producer slot (#15488).
     */
    private function outerSiblingInlineCallArgProducerSlot(
        Block $block,
        Op $cfgCallOp,
        int $argIndex,
        ?array &$emitOps = null
    ): ?string {
        if (null === $block->orig || !property_exists($cfgCallOp, 'args') || !is_array($cfgCallOp->args)) {
            return null;
        }
        $argCount = \count($cfgCallOp->args);
        if ($argCount < 2 || $argIndex >= $argCount) {
            return null;
        }
        $callIndex = $this->cfgCallOpIndexInChildren($block->orig->children, $cfgCallOp, $block->orig);
        if (null === $callIndex) {
            return null;
        }
        $firstSibling = $this->firstSiblingInlineFuncCallProducerIndex($callIndex, $block->orig->children);
        if (null === $firstSibling) {
            return null;
        }
        $outer = $this->outerSiblingInlineFuncCallProducers(
            $firstSibling,
            $callIndex,
            $block->orig->children
        );
        $embeddedArgCount = 0;
        $deadInlineTempCount = 0;
        $hoistedArgCount = 0;
        foreach ($cfgCallOp->args as $hoistedArg) {
            if (null !== $hoistedArg && $this->isEmbeddedCallLiteralArg($hoistedArg)) {
                ++$embeddedArgCount;
                continue;
            }
            if ($this->callArgIsDeadInlineTemporary($hoistedArg)) {
                ++$deadInlineTempCount;
            }
            if (null !== $hoistedArg) {
                ++$hoistedArgCount;
            }
        }
        if (\count($outer) === $argCount && ($embeddedArgCount === $argCount || $deadInlineTempCount === $argCount)) {
            // array_intersect(f(g()), f(g())) — hoisted outer producers only (#15488).
            $hoistedArgCount = $argCount;
        }
        if (\count($outer) !== $hoistedArgCount || \count($outer) !== $argCount) {
            return null;
        }
        if (\count($outer) >= $callIndex - $firstSibling) {
            return null;
        }
        // A::inc(); A::inc(); var_dump(A::$n, B::$n) — intervening StaticPropertyFetch are the
        // ARG_SEND sources; stmt-level StaticCalls in $outer must not win (#34997).
        // var_dump($g(), $h()) has no intervening fetches, so still binds $outer.
        if ($this->interveningFetchProducersCoverDeadTempCallArgs(
            $firstSibling,
            $callIndex,
            $block->orig->children,
            $cfgCallOp
        )) {
            return null;
        }
        $hoistedOrdinal = null;
        $seen = 0;
        foreach ($cfgCallOp->args as $i => $hoistedArg) {
            if (null !== $hoistedArg && !$this->isEmbeddedCallLiteralArg($hoistedArg)) {
                if ($i === $argIndex) {
                    $hoistedOrdinal = $seen;
                    break;
                }
                ++$seen;
            }
        }
        if (null === $hoistedOrdinal) {
            return null;
        }
        $outerProducer = $outer[$hoistedOrdinal] ?? null;
        if (!$outerProducer instanceof Op\Expr || null === $outerProducer->result) {
            return null;
        }
        if (
            $outerProducer instanceof Op\Expr\FuncCall
            || $outerProducer instanceof Op\Expr\NsFuncCall
        ) {
            if (null === $block->slotForOperand($outerProducer->result)) {
                $prevForce = $this->forceDeferredSiblingCallReturnSlot;
                $this->forceDeferredSiblingCallReturnSlot = true;
                try {
                    foreach ($this->compileExpr($outerProducer, $block) as $op) {
                        if (null !== $emitOps) {
                            $emitOps[] = $op;
                        } else {
                            $block->addOpCode($op);
                        }
                    }
                } finally {
                    $this->forceDeferredSiblingCallReturnSlot = $prevForce;
                }
            }
        }
        $operandSlot = $block->slotForOperand($outerProducer->result);
        if (null !== $operandSlot) {
            $outerProducerIndexForOperand = array_search($outerProducer, $block->orig->children, true);
            $prevProducer = is_int($outerProducerIndexForOperand) && $outerProducerIndexForOperand > 0
                ? ($block->orig->children[$outerProducerIndexForOperand - 1] ?? null)
                : null;
            // array_intersect(f(g()), f(g())) — operand slot beats ordinal EXEC_RETURN when emission order drifts (#16427).
            // in_array/array_search before var_dump — EXEC_RETURN ordinals must win (#9390, #17317).
            if (
                ($outerProducer instanceof Op\Expr\FuncCall || $outerProducer instanceof Op\Expr\NsFuncCall)
                && !$this->funcCallExprLiteralCalleeAllowedAsHoistedProducer($outerProducer)
                && !\in_array(
                    strtolower($this->resolveCfgFuncCallName($outerProducer) ?? ''),
                    ['in_array', 'array_search', 'array_key_exists', 'key_exists'],
                    true
                )
                && ($prevProducer instanceof Op\Expr\FuncCall || $prevProducer instanceof Op\Expr\NsFuncCall)
                && is_int($outerProducerIndexForOperand)
                && $this->isAdjacentNestedFuncCallProducer(
                    $prevProducer,
                    $outerProducer,
                    $outerProducerIndexForOperand - 1,
                    $outerProducerIndexForOperand
                )
            ) {
                return (string) $operandSlot;
            }
        }
        $outerProducerIndex = array_search($outerProducer, $block->orig->children, true);
        if (
            is_int($outerProducerIndex)
            && (
                $outerProducer instanceof Op\Expr\FuncCall
                || $outerProducer instanceof Op\Expr\NsFuncCall
            )
        ) {
            $execReturn = $this->slotForInlineFuncCallProducerExecReturnByCfgIndex(
                $block,
                $outerProducerIndex,
                $block->orig->children
            );
            if (null !== $execReturn) {
                return (string) $execReturn;
            }
        }
        $execReturn = $this->slotForSiblingInlineCallProducerExecReturnByExpr(
            $block,
            $outerProducer,
            $cfgCallOp,
            $block->orig->children
        );
        if (null !== $execReturn) {
            return (string) $execReturn;
        }
        $slot = $block->slotForOperand($outerProducer->result);

        return null !== $slot ? (string) $slot : null;
    }

    /**
     * Map dead inline ?: call-arg temps to the innermost ?: merge phi slots for this call (#15816, #22732).
     *
     * php-cfg writes Phi into Temporary->ops for ?: args and ConstFetch for trailing true/false/null.
     * Only Phi-written args consume ternary merge phi slots — counting all dead temps made
     * `f(cond ? a : b, true)` fail the phiSlots>=deadTempCount guard (#22732).
     */
    private function resolveNestedTernaryMergeCallArgSlot(
        Block $block,
        ?Op $cfgCallOp,
        int $argIndex,
        Operand $arg
    ): ?string {
        if (null === $cfgCallOp || !property_exists($cfgCallOp, 'args') || !\is_array($cfgCallOp->args)) {
            return null;
        }
        if (!$this->callArgIsDeadInlineTemporary($arg) || !$this->callArgTemporaryIsPhiWritten($arg)) {
            return null;
        }
        $phiArgIndexes = [];
        foreach ($cfgCallOp->args as $i => $callArg) {
            if (
                $callArg instanceof Operand
                && $this->callArgIsDeadInlineTemporary($callArg)
                && $this->callArgTemporaryIsPhiWritten($callArg)
            ) {
                $phiArgIndexes[] = (int) $i;
            }
        }
        if ([] === $phiArgIndexes) {
            return null;
        }
        // Multi-?: call args (#15816) or mixed ?: + scalar ConstFetch (#22732) both need remapping.
        // Lone single-arg ?: already wires via compileOperand — only engage when a sibling dead temp
        // (ConstFetch / another Phi) shares the call, matching the historic deadTempCount>=2 gate.
        if (1 === \count($phiArgIndexes)) {
            $siblingDeadTemp = false;
            foreach ($cfgCallOp->args as $i => $callArg) {
                if ((int) $i === $phiArgIndexes[0]) {
                    continue;
                }
                if ($callArg instanceof Operand && $this->callArgIsDeadInlineTemporary($callArg)) {
                    $siblingDeadTemp = true;
                    break;
                }
            }
            if (!$siblingDeadTemp) {
                return null;
            }
        }
        $phiSlots = [];
        foreach ($this->ternaryMergePhiRhsSlots as $mergeCfg) {
            $phi = $this->ternaryMergePhiRhsSlots[$mergeCfg];
            if (null !== $phi) {
                $phiSlots[] = $phi;
            }
        }
        $phiSlots = array_values(array_unique($phiSlots));
        $phiArgCount = \count($phiArgIndexes);
        if (\count($phiSlots) < $phiArgCount) {
            return null;
        }
        $phiSlots = \array_slice($phiSlots, -$phiArgCount);
        $ordinal = \array_search($argIndex, $phiArgIndexes, true);
        if (false === $ordinal || $ordinal >= \count($phiSlots)) {
            return null;
        }

        return (string) $phiSlots[$ordinal];
    }

    /** php-cfg Temporary written by a Phi — ?: / short-circuit merge result (#22732). */
    private function callArgTemporaryIsPhiWritten(?Operand $arg): bool
    {
        if (null === $arg) {
            return false;
        }
        foreach ($arg->ops ?? [] as $embedded) {
            if ($embedded instanceof Op\Phi) {
                return true;
            }
        }

        return false;
    }

    /** php-cfg Temporary written by a hoisted true/false/null ConstFetch (#22732). */
    private function callArgTemporaryIsScalarConstFetchWritten(?Operand $arg): bool
    {
        if (null === $arg) {
            return false;
        }
        foreach ($arg->ops ?? [] as $embedded) {
            if (!$embedded instanceof Op\Expr\ConstFetch) {
                continue;
            }
            $name = $this->staticNameFromOperand($embedded->name);
            if (null !== $name && \in_array(strtolower($name), ['true', 'false', 'null'], true)) {
                return true;
            }
        }

        return false;
    }


    /**
     * Sole writer on a dead call-arg temp — Array_ / ConstFetch / ClassConstFetch (#25337).
     *
     * When a sibling arg is ?: Phi-written, JumpIf merge prebind can make compileOperand
     * resolve the non-Phi temp to the ternary phi slot (array_merge([1], $x?[2]:[3]),
     * twoway(FLAG, 'C'?:'D')). Prefer the embedded writer instead.
     */
    private function soleEmbeddedWriterOnCallArgTemp(?Operand $arg): ?Op\Expr
    {
        if (null === $arg || !$this->callArgIsDeadInlineTemporary($arg)) {
            return null;
        }
        if ($this->callArgTemporaryIsPhiWritten($arg)) {
            return null;
        }
        $ops = $arg->ops ?? [];
        if (1 !== \count($ops) || !($ops[0] instanceof Op\Expr)) {
            return null;
        }
        $writer = $ops[0];
        if (
            $writer instanceof Op\Expr\Array_
            || $writer instanceof Op\Expr\ConstFetch
            || $writer instanceof Op\Expr\ClassConstFetch
        ) {
            return $writer;
        }

        return null;
    }

    /** True when another dead call arg on this call is ?: Phi-written (#25337). */
    private function cfgCallHasPhiWrittenDeadTempSibling(Op $cfgCallOp, int $argIndex): bool
    {
        if (!property_exists($cfgCallOp, 'args') || !\is_array($cfgCallOp->args)) {
            return false;
        }
        foreach ($cfgCallOp->args as $i => $candidate) {
            if ((int) $i === $argIndex || !($candidate instanceof Operand)) {
                continue;
            }
            if (
                $this->callArgIsDeadInlineTemporary($candidate)
                && $this->callArgTemporaryIsPhiWritten($candidate)
            ) {
                return true;
            }
        }

        return false;
    }

    /**
     * Wire non-Phi dead temps beside ?: to their sole embedded writer slot (#25337).
     *
     * @param list<OpCode> $emitOps
     */
    private function resolveNonPhiSiblingOfTernaryCallArgSlot(
        Block $block,
        Op $cfgCallOp,
        int $argIndex,
        Operand $arg,
        array &$emitOps
    ): ?string {
        if (!$this->cfgCallHasPhiWrittenDeadTempSibling($cfgCallOp, $argIndex)) {
            return null;
        }
        $writer = $this->soleEmbeddedWriterOnCallArgTemp($arg);
        if (null === $writer || null === $writer->result) {
            return null;
        }
        $ternaryPhiSlots = [];
        foreach ($this->ternaryMergePhiRhsSlots as $mergeCfg) {
            $phi = $this->ternaryMergePhiRhsSlots[$mergeCfg];
            if (null !== $phi) {
                $ternaryPhiSlots[(string) $phi] = true;
            }
        }
        if ($writer instanceof Op\Expr\Array_) {
            $slot = $block->slotForOperand($writer->result);
            // Prebind may alias the Array_ result onto the ternary phi — emit a fresh INIT_ARRAY.
            if (null === $slot || isset($ternaryPhiSlots[(string) $slot])) {
                $arrayOps = $this->compileArrayLiteral($writer, $block);
                if ([] !== $arrayOps) {
                    $emitOps = array_merge($emitOps, $arrayOps);
                }
                $slot = $this->slotFromInitArrayLiteralOps($arrayOps)
                    ?? $block->slotForOperand($writer->result);
            }

            return null !== $slot ? (string) $slot : null;
        }
        if ($writer instanceof Op\Expr\ConstFetch) {
            $folded = $this->tryFoldGlobalConstFetch($writer);
            if (null !== $folded) {
                return (string) $block->registerConstant(new Operand\Temporary(), $folded);
            }
        }
        $slot = $block->slotForOperand($writer->result);
        if (null !== $slot && !isset($ternaryPhiSlots[(string) $slot])) {
            return (string) $slot;
        }
        foreach ($this->compileExpr($writer, $block) as $op) {
            $emitOps[] = $op;
        }
        $slot = $block->slotForOperand($writer->result);
        if (null !== $slot && !isset($ternaryPhiSlots[(string) $slot])) {
            return (string) $slot;
        }

        return null;
    }

    /**
     * Hoisted ConstFetch wired to this positional arg must keep its slot (#15833).
     * probe('label', in_array(..., g(), true)) — ConstFetch feeds inner callee, not outer (#14237).
     */
    private function shouldRemapHoistedConstFetchToAdjacentNestedCall(
        Op\Expr $matched,
        Op $cfgCallOp,
        int $argIndex,
        ?Block $block = null
    ): bool {
        if (!$matched instanceof Op\Expr\ConstFetch) {
            return true;
        }
        if (!property_exists($cfgCallOp, 'args') || !\is_array($cfgCallOp->args)) {
            return true;
        }
        $callArg = $cfgCallOp->args[$argIndex] ?? null;
        if (
            null !== $callArg
            && null !== $matched->result
            && $this->operandsReferToSameVariable($matched->result, $callArg)
        ) {
            return false;
        }
        if (null !== $block && null !== $block->orig) {
            $producers = $this->precedingInlineCallArgProducersBeforeCfgOp($block->orig->children, $cfgCallOp);
            $split = $this->splitLeadingConstFetchWithFuncCallCallArg($producers);
            if (null !== $split) {
                [$constFetch] = $split;
                if ($matched === $constFetch) {
                    $nonEmbeddedArgIndices = [];
                    foreach ($cfgCallOp->args as $i => $candidate) {
                        if (!$this->isEmbeddedCallLiteralArg($candidate)) {
                            $nonEmbeddedArgIndices[] = (int) $i;
                        }
                    }
                    if (0 === array_search($argIndex, $nonEmbeddedArgIndices, true)) {
                        return false;
                    }
                }
            }
        }

        return true;
    }

    /**
     * date_sunrise(time(), SUNFUNCS_RET_*, …) / date_sun_info(strtotime(...), lat, -lon) — hoisted FuncCall + prelude slots (#13749, #11070, #11336).
     */
    private function wireDateSunFuncHoistedCallArgSlot(Block $block, Op $cfgCallOp, int $argIndex): ?string
    {
        if (null === $block->orig) {
            return null;
        }
        $callee = strtolower($this->resolveCfgFuncCallName($cfgCallOp) ?? '');
        $isDateSunInfo = 'date_sun_info' === $callee;
        if (!\in_array($callee, ['date_sunrise', 'date_sunset', 'date_sun_info'], true)) {
            return null;
        }
        if (0 === $argIndex) {
            $callIndex = $this->cfgCallOpIndexInChildren($block->orig->children, $cfgCallOp, $block->orig);
            if (null === $callIndex) {
                return null;
            }
            for ($i = $callIndex - 1; $i >= 0; --$i) {
                $child = $block->orig->children[$i];
                if ($child instanceof Op\Expr\Assign) {
                    return null;
                }
                if (!$child instanceof Op\Expr\FuncCall && !$child instanceof Op\Expr\NsFuncCall) {
                    continue;
                }
                $producerName = strtolower($this->resolveCfgFuncCallName($child) ?? '');
                if (!\in_array($producerName, ['time', 'gmmktime', 'strtotime'], true)) {
                    return null;
                }
                $producerIndex = $i;
                $execReturn = $this->slotForSiblingInlineCallProducerExecReturnByExpr(
                    $block,
                    $child,
                    $cfgCallOp,
                    $block->orig->children
                );
                if (null !== $execReturn) {
                    return (string) $execReturn;
                }
                $execReturn = $this->slotForInlineFuncCallProducerExecReturnByCfgIndex(
                    $block,
                    $producerIndex,
                    $block->orig->children
                );
                if (null !== $execReturn) {
                    return (string) $execReturn;
                }
                $operandSlot = $block->slotForOperand($child->result);
                if (null !== $operandSlot) {
                    return (string) $operandSlot;
                }
                if (null === $block->slotForOperand($child->result)) {
                    $prevForce = $this->forceDeferredSiblingCallReturnSlot;
                    $this->forceDeferredSiblingCallReturnSlot = true;
                    try {
                        foreach ($this->compileExpr($child, $block) as $op) {
                            $block->addOpCode($op);
                        }
                    } finally {
                        $this->forceDeferredSiblingCallReturnSlot = $prevForce;
                    }
                }
                $execReturn = $this->slotForSiblingInlineCallProducerExecReturnByExpr(
                    $block,
                    $child,
                    $cfgCallOp,
                    $block->orig->children
                );
                if (null !== $execReturn) {
                    return (string) $execReturn;
                }
                $execReturn = $this->slotForInlineFuncCallProducerExecReturnByCfgIndex(
                    $block,
                    $producerIndex,
                    $block->orig->children
                );
                if (null !== $execReturn) {
                    return (string) $execReturn;
                }
                $operandSlot = $block->slotForOperand($child->result);

                return null !== $operandSlot ? (string) $operandSlot : null;
            }

            return null;
        }
        $longitudeArgIndex = $isDateSunInfo ? 2 : 3;
        if ($longitudeArgIndex === $argIndex) {
            foreach ($this->hoistedPreludeProducersImmediatelyBeforeCall($cfgCallOp, $block) as $prelude) {
                if (!$prelude instanceof Op\Expr\UnaryMinus && !$prelude instanceof Op\Expr\UnaryPlus) {
                    continue;
                }
                $existing = $block->slotForOperand($prelude->result);
                if (null !== $existing) {
                    return (string) $existing;
                }
                $folded = $this->tryFoldUnaryLiteralDefault($prelude);
                if (null === $folded) {
                    continue;
                }

                return (string) $block->registerConstant($prelude->result, $folded);
            }

            return null;
        }
        if ($isDateSunInfo || 1 !== $argIndex) {
            return null;
        }
        foreach ($this->hoistedPreludeProducersImmediatelyBeforeCall($cfgCallOp, $block) as $prelude) {
            if (!$prelude instanceof Op\Expr\ConstFetch) {
                continue;
            }
            $name = strtolower($this->staticNameFromOperand($prelude->name) ?? '');
            if (!str_starts_with($name, 'sunfuncs_ret_')) {
                continue;
            }
            $existing = $block->slotForOperand($prelude->result);
            if (null !== $existing) {
                return (string) $existing;
            }
            $folded = $this->tryFoldGlobalConstFetch($prelude);
            if (null === $folded) {
                continue;
            }

            return (string) $block->registerConstant($prelude->result, $folded);
        }

        return null;
    }

    /**
     * array_splice($a, -N, $len, null) — UnaryMinus offset + null replacement (#16328, #10589).
     *
     * @param list<OpCode> $emitOps
     */
    private function wireArraySpliceUnaryOffsetReplacementCallArgSlot(
        Block $block,
        Op $cfgCallOp,
        int $argIndex,
        array &$emitOps = []
    ): ?string {
        if (null === $block->orig) {
            return null;
        }
        if ('array_splice' !== strtolower($this->resolveCfgFuncCallName($cfgCallOp) ?? '')) {
            return null;
        }
        if (!\is_array($cfgCallOp->args ?? null) || \count($cfgCallOp->args) < 4) {
            return null;
        }
        $producers = $this->precedingInlineCallArgProducersBeforeCfgOp($block->orig->children, $cfgCallOp);
        $target = $this->matchArraySpliceUnaryOffsetReplacementProducers(
            $producers,
            $argIndex,
            \count($cfgCallOp->args),
            'array_splice'
        );
        if (!$target instanceof Op\Expr) {
            return null;
        }
        if ($target instanceof Op\Expr\ConstFetch) {
            $folded = $this->tryFoldGlobalConstFetch($target);
            if (null !== $folded) {
                return (string) $block->registerConstant(new Operand\Temporary(), $folded);
            }
        } elseif ($target instanceof Op\Expr\UnaryMinus || $target instanceof Op\Expr\UnaryPlus) {
            $folded = $this->tryFoldUnaryLiteralDefault($target);
            if (null !== $folded) {
                return (string) $block->registerConstant(new Operand\Temporary(), $folded);
            }
        }
        $slot = $block->slotForOperand($target->result);
        if (null === $slot) {
            foreach ($this->compileExpr($target, $block) as $op) {
                $emitOps[] = $op;
            }
            $slot = $block->slotForOperand($target->result);
        }

        return null !== $slot ? (string) $slot : null;
    }

    /** array_splice($a, -N, $len, null) — skip generic hoisted-null prelude on offset/replacement slots (#16328). */
    private function arraySpliceUnaryOffsetReplacementUsesDedicatedProducerWiring(
        Op $cfgCallOp,
        int $argIndex,
        Block $block
    ): bool {
        if (null === $block->orig) {
            return false;
        }
        if ('array_splice' !== strtolower($this->resolveCfgFuncCallName($cfgCallOp) ?? '')) {
            return false;
        }
        if (!\is_array($cfgCallOp->args ?? null) || \count($cfgCallOp->args) < 4) {
            return false;
        }
        $producers = $this->precedingInlineCallArgProducersBeforeCfgOp($block->orig->children, $cfgCallOp);

        return null !== $this->matchArraySpliceUnaryOffsetReplacementProducers(
            $producers,
            $argIndex,
            \count($cfgCallOp->args),
            'array_splice'
        );
    }

    /**
     * mb_substr($s, -N, null[, $enc]) / mb_strcut — UnaryMinus offset + null length (#16481).
     *
     * @param list<OpCode> $emitOps
     */
    private function wireMbstringUnaryOffsetNullLengthCallArgSlot(
        Block $block,
        Op $cfgCallOp,
        int $argIndex,
        array &$emitOps = []
    ): ?string {
        if (null === $block->orig) {
            return null;
        }
        $func = strtolower($this->resolveCfgFuncCallName($cfgCallOp) ?? '');
        if (!\in_array($func, ['mb_substr', 'mb_strcut'], true)) {
            return null;
        }
        if (!\is_array($cfgCallOp->args ?? null) || \count($cfgCallOp->args) < 3) {
            return null;
        }
        $producers = $this->precedingInlineCallArgProducersBeforeCfgOp($block->orig->children, $cfgCallOp);
        $target = $this->matchMbstringUnaryOffsetNullLengthProducers(
            $producers,
            $argIndex,
            \count($cfgCallOp->args),
            $func
        );
        if (!$target instanceof Op\Expr) {
            return null;
        }
        if ($target instanceof Op\Expr\ConstFetch) {
            $folded = $this->tryFoldGlobalConstFetch($target);
            if (null !== $folded) {
                return (string) $block->registerConstant(new Operand\Temporary(), $folded);
            }
        } elseif ($target instanceof Op\Expr\UnaryMinus || $target instanceof Op\Expr\UnaryPlus) {
            $folded = $this->tryFoldUnaryLiteralDefault($target);
            if (null !== $folded) {
                return (string) $block->registerConstant(new Operand\Temporary(), $folded);
            }
        }
        $slot = $block->slotForOperand($target->result);
        if (null === $slot) {
            foreach ($this->compileExpr($target, $block) as $op) {
                $emitOps[] = $op;
            }
            $slot = $block->slotForOperand($target->result);
        }

        return null !== $slot ? (string) $slot : null;
    }

    /** mb_substr/mb_strcut($s, -N, null) — skip generic hoisted-null prelude on offset/length slots (#16481). */
    private function mbstringUnaryOffsetNullLengthUsesDedicatedProducerWiring(
        Op $cfgCallOp,
        int $argIndex,
        Block $block
    ): bool {
        if (null === $block->orig) {
            return false;
        }
        $func = strtolower($this->resolveCfgFuncCallName($cfgCallOp) ?? '');
        if (!\in_array($func, ['mb_substr', 'mb_strcut'], true)) {
            return false;
        }
        if (!\is_array($cfgCallOp->args ?? null) || \count($cfgCallOp->args) < 3) {
            return false;
        }
        $producers = $this->precedingInlineCallArgProducersBeforeCfgOp($block->orig->children, $cfgCallOp);

        return null !== $this->matchMbstringUnaryOffsetNullLengthProducers(
            $producers,
            $argIndex,
            \count($cfgCallOp->args),
            $func
        );
    }

    /**
     * Nested inline producer compile may prepend FUNCCALL_INIT to $block early (#17697);
     * drain back into the producer chain so partitionNestedInlineCallArgProducerOps stays contiguous (#17862).
     *
     * @return list<OpCode>
     */
    private function drainBlockOpcodesAppendedSince(Block $block, int $sinceIndex): array
    {
        $drained = array_slice($block->opCodes, $sinceIndex);
        if ([] === $drained) {
            return [];
        }
        $block->opCodes = array_slice($block->opCodes, 0, $sinceIndex);
        $block->nOpCodes = $sinceIndex;
        $block->invalidateOpcodeDerivedIndexes();

        return $drained;
    }


    /**
     * var_export(g(), true) — nested callee before trailing ConstFetch preludes feeds arg #0 (#11272, #16298).
     * is_a(new C(), Parent::class) — inline New_ before trailing ::class feeds arg #0 (#17502).
     *
     * @param list<Op> $cfgChildren
     */
    private function nestedFuncCallProducerBeforeTrailingConstFetchPreludes(
        Op $consumer,
        int $consumerIndex,
        array $cfgChildren
    ): ?Op\Expr {
        if ($consumerIndex < 1) {
            return null;
        }
        $probeIndex = $consumerIndex - 1;
        while ($probeIndex >= 0) {
            $probe = $cfgChildren[$probeIndex] ?? null;
            if ($probe instanceof Op\Expr\ConstFetch || $probe instanceof Op\Expr\ClassConstFetch) {
                --$probeIndex;
                continue;
            }
            break;
        }
        $prev = $cfgChildren[$probeIndex] ?? null;
        if ($prev instanceof Op\Expr\FuncCall || $prev instanceof Op\Expr\NsFuncCall) {
            if (!$this->isNestedCallArgProducerForConsumer(
                $prev,
                $consumer,
                $probeIndex,
                $consumerIndex,
                $cfgChildren
            )) {
                return null;
            }
        } elseif ($prev instanceof Op\Expr\MethodCall || $prev instanceof Op\Expr\StaticCall) {
            if (!$this->isNestedCallArgProducerSeparatedByConsumerLiteralPreludes(
                $prev,
                $consumer,
                $probeIndex,
                $consumerIndex,
                $cfgChildren
            )) {
                return null;
            }
        } elseif ($prev instanceof Op\Expr\New_) {
            if (
                !property_exists($consumer, 'args')
                || !\is_array($consumer->args)
                || \count($consumer->args) < 2
            ) {
                return null;
            }
            $callArg = $consumer->args[0] ?? null;
            if (
                !$this->callArgIsNewExpression($callArg)
                && (!$callArg instanceof Operand || !$this->callArgIsDeadInlineTemporary($callArg))
            ) {
                return null;
            }
        } else {
            return null;
        }
        $targetArgIndex = $this->siblingMultiArgFuncCallProducerTargetArgIndex(
            $probeIndex,
            $consumerIndex,
            $cfgChildren
        );
        if (null === $targetArgIndex) {
            $targetArgIndex = 0;
        }

        return 0 === $targetArgIndex ? $prev : null;
    }

    /**
     * Single hoisted ArrowFunction/Closure with extra named call args (#9154, array_any/find family).
     *
     * php-cfg may emit `array_any($arr, fn ($v) => …)` as one closure producer plus a named
     * first argument — the closure must not be wired to arg 0.
     */
    private function matchSingleClosureInlineProducer(
        Op\Expr $producer,
        array $callArgs,
        int $argIndex,
        ?string $funcName = null
    ): ?Op\Expr {
        if (
            !($producer instanceof Op\Expr\ArrowFunction)
            && !($producer instanceof Op\Expr\Closure)
        ) {
            return null;
        }
        $callArg = $callArgs[$argIndex] ?? null;
        if (null !== $callArg && $this->operandsReferToSameVariable($producer->result, $callArg)) {
            return $producer;
        }
        $closureSlots = [];
        foreach ($callArgs as $idx => $arg) {
            if (null === $arg || $this->isEmbeddedCallLiteralArg($arg)) {
                continue;
            }
            if ($this->isNamedVariableOperand($arg)) {
                continue;
            }
            $closureSlots[] = $idx;
        }
        if (1 === count($closureSlots) && $closureSlots[0] === $argIndex) {
            return $producer;
        }
        $callbackArgIndex = $this->inlineClosureArrayPairCallbackArgIndex($funcName);
        // array_filter($a, fn(...), ARRAY_FILTER_USE_*) — callback slot from builtin signature (#10232, #9154).
        if ($callbackArgIndex >= 0 && \count($callArgs) >= 3 && $argIndex === $callbackArgIndex) {
            return $producer;
        }
        // array_filter/array_any inline array + fn — callback is arg 1, not arg 0 (#12721).
        if ($callbackArgIndex > 0 && 2 === \count($callArgs) && $argIndex === $callbackArgIndex) {
            return $producer;
        }
        // array_map(fn(...), $arr) — callback is arg 0 (#10651).
        if (
            0 === $callbackArgIndex
            && 2 === \count($callArgs)
            && 0 === $argIndex
            && \count($closureSlots) >= 1
            && 0 === $closureSlots[0]
        ) {
            return $producer;
        }
        if ($this->builtinUsesTrailingComparatorCallback($funcName) && $argIndex === \count($callArgs) - 1) {
            return $producer;
        }

        return null;
    }

    /** Inline strcmp(...) and other FCC comparators — last callback arg only (#13990, zend_closures.c). */
    private function matchSingleFirstClassCallableInlineProducer(
        Op\Expr $producer,
        array $callArgs,
        int $argIndex,
        ?string $funcName = null
    ): ?Op\Expr {
        if (!$producer instanceof Op\Expr\FirstClassCallable) {
            return null;
        }
        $callArg = $callArgs[$argIndex] ?? null;
        if (null !== $callArg && $this->operandsReferToSameVariable($producer->result, $callArg)) {
            return $producer;
        }
        $callbackArgIndex = $this->inlineClosureArrayPairCallbackArgIndex($funcName);
        if ($callbackArgIndex >= 0 && $argIndex === $callbackArgIndex) {
            return $producer;
        }
        if ($this->builtinUsesTrailingComparatorCallback($funcName) && $argIndex === \count($callArgs) - 1) {
            return $producer;
        }
        if (1 === \count($callArgs) && 0 === $argIndex) {
            return $producer;
        }

        return null;
    }

    /** array_udiff* / usort* — comparator is the trailing call argument (ext/standard/array.c). */
    private function builtinUsesTrailingComparatorCallback(?string $funcName): bool
    {
        if (null === $funcName || '' === $funcName) {
            return false;
        }

        return \in_array(strtolower($funcName), [
            'usort',
            'uasort',
            'uksort',
            'array_udiff',
            'array_uintersect',
            'array_udiff_assoc',
            'array_uintersect_assoc',
            'array_udiff_uassoc',
            'array_uintersect_uassoc',
            'array_diff_uassoc',
            'array_intersect_uassoc',
            'array_diff_ukey',
            'array_intersect_ukey',
        ], true);
    }

    /**
     * array_udiff(array_keys(...), array_keys(...), strcmp(...)) — FuncCall/FCC hoists (#13990).
     *
     * @param list<Op\Expr> $producers
     * @param list<Operand> $callArgs
     */
    private function matchTrailingComparatorInlineCallArgProducer(
        array $producers,
        array $callArgs,
        int $argIndex,
        ?string $funcName
    ): ?Op\Expr {
        if (!$this->builtinUsesTrailingComparatorCallback($funcName)) {
            return null;
        }
        $argCount = \count($callArgs);
        if ($argCount < 2) {
            return null;
        }
        $callbackArgIndex = $argCount - 1;
        $callbackProducer = null;
        $funcProducers = [];
        foreach ($producers as $producer) {
            if ($producer instanceof Op\Expr\ArrowFunction
                || $producer instanceof Op\Expr\Closure
                || $producer instanceof Op\Expr\FirstClassCallable) {
                $callbackProducer = $producer;
            } elseif ($producer instanceof Op\Expr\FuncCall || $producer instanceof Op\Expr\NsFuncCall) {
                if ($this->builtinUsesTrailingComparatorCallback($this->resolveCfgFuncCallName($producer))) {
                    continue;
                }
                $funcProducers[] = $producer;
            }
        }
        if (null === $callbackProducer) {
            $callbackArg = $callArgs[$callbackArgIndex] ?? null;
            if (null !== $callbackArg && (
                $this->isEmbeddedCallLiteralArg($callbackArg)
                || !$this->callArgIsDeadInlineTemporary($callbackArg)
            )) {
                $funcArgIndex = 0;
                foreach ($callArgs as $i => $callArg) {
                    if ($i >= $callbackArgIndex) {
                        break;
                    }
                    if ($this->callArgIsDeadInlineTemporary($callArg)) {
                        if ($i === $argIndex) {
                            return $funcProducers[$funcArgIndex] ?? null;
                        }
                        ++$funcArgIndex;
                    }
                }

                return null;
            }

            return null;
        }
        if ($argIndex === $callbackArgIndex) {
            return $callbackProducer;
        }
        $funcArgIndex = 0;
        foreach ($callArgs as $i => $callArg) {
            if ($i >= $callbackArgIndex) {
                break;
            }
            if ($this->isEmbeddedCallLiteralArg($callArg)) {
                continue;
            }
            if (!$this->callArgIsDeadInlineTemporary($callArg)) {
                continue;
            }
            if ($i === $argIndex) {
                return $funcProducers[$funcArgIndex] ?? null;
            }
            ++$funcArgIndex;
        }

        return null;
    }

    /**
     * Hoisted FuncCall producers may supply a dead temp slot — not an unrelated named local (#9074).
     */
    private function namedCallArgMayUseFuncCallProducerResult(Op\Expr $producer, Operand $callArg): bool
    {
        if (!$this->isNamedVariableOperand($callArg)) {
            return true;
        }
        if ($this->operandsReferToSameVariable($producer->result, $callArg)) {
            return true;
        }
        if ($producer instanceof Op\Expr\FuncCall || $producer instanceof Op\Expr\NsFuncCall) {
            return $this->funcCallExprByRefArgMatchesOperand($producer, $callArg);
        }

        return false;
    }

    /** True when a hoisted FuncCall temp is an operand of the consumer call (#8561). */
    private function inlineCallArgProducerFeedsConsumer(Op\Expr $producer, Op $consumer): bool
    {
        if (!property_exists($producer, 'result') || !property_exists($consumer, 'args') || !is_array($consumer->args)) {
            return false;
        }
        $producerRoot = Block::cfgVarRoot($producer->result);
        foreach ($consumer->args as $callArg) {
            if ($callArg === $producer->result) {
                return true;
            }
            if ($this->operandsReferToSameVariable($callArg, $producer->result)) {
                return true;
            }
            if (null !== $producerRoot && Block::cfgVarRoot($callArg) === $producerRoot) {
                return true;
            }
        }

        return false;
    }

    /** (new C())->f(E::A) — inline New_ feeds MethodCall receiver, not a call arg (#16227). */
    private function inlineNewFeedsCallReceiver(Op\Expr\New_ $new, Op $consumer): bool
    {
        if (!$consumer instanceof Op\Expr\MethodCall) {
            return false;
        }
        $receiver = $consumer->var ?? null;
        if (null === $receiver || null === $new->result) {
            return false;
        }

        return $receiver === $new->result
            || $this->operandsReferToSameVariable($receiver, $new->result);
    }

    /** True when a call operand is `new ClassName(...)` (#9904). */
    private function callArgIsNewExpression(?Operand $callArg): bool
    {
        if (null === $callArg) {
            return false;
        }

        return $this->unwrapOperandChain($callArg) instanceof Op\Expr\New_;
    }

    /** True when php-cfg hoisted an inline `new` producer for this call arg (#9904). */
    private function callArgInlineProducerIsNew(?Op $cfgCallOp, int $argIndex, Block $block): bool
    {
        if (null === $cfgCallOp || null === $block->orig) {
            return false;
        }
        if (!property_exists($cfgCallOp, 'args') || !is_array($cfgCallOp->args)) {
            return false;
        }
        $callArg = $cfgCallOp->args[$argIndex] ?? null;
        if ($this->callArgIsNewExpression($callArg)) {
            return true;
        }
        // new Outer(new Inner(...), fn() => …) — Closure/arrow arg is never an inline New_ (#19771).
        if ($callArg instanceof Operand && $this->callArgOpsContainInlineClosure($callArg)) {
            return false;
        }
        $producers = $this->precedingInlineCallArgProducersBeforeCfgOp($block->orig->children, $cfgCallOp);
        $argCount = \count($cfgCallOp->args);
        if (null !== $this->matchNestedNewCtorInlineNewProducer($producers, $argIndex, $argCount, $cfgCallOp->args)) {
            return true;
        }
        if (\count($producers) === $argCount && isset($producers[$argIndex])) {
            $positional = $producers[$argIndex];
            if ($positional instanceof Op\Expr\New_) {
                // Array_ ctor prelude + New_ aligned 1:1 with (iterator, preserve_keys) is wrong —
                // New_ feeds arg #0 only; trailing bool is a separate ConstFetch (#22702).
                if (
                    0 === $argIndex
                    || !(
                        ($producers[0] ?? null) instanceof Op\Expr\Array_
                        && ($producers[1] ?? null) instanceof Op\Expr\New_
                    )
                ) {
                    return true;
                }
            }
            // attachIterator(new ArrayIterator([...]), …) — Array_ is inner-ctor prelude, New_ feeds arg #0 (#13342).
            if (
                !(
                    0 === $argIndex
                    && $positional instanceof Op\Expr\Array_
                    && ($producers[$argIndex + 1] ?? null) instanceof Op\Expr\New_
                )
            ) {
                return false;
            }
        }

        $matched = $this->matchInlineCallArgProducer($producers, $cfgCallOp->args, $argIndex, $cfgCallOp, $block);

        return $matched instanceof Op\Expr\New_;
    }

    /**
     * Hoisted inline `new` feeding a sibling `new` ctor arg must survive stmt dead-temp release (#14483).
     */
    private function markInlineNewProducerKeepSlotForSiblingConsumer(
        Op\Expr\New_ $producer,
        Block $block,
        int $resultSlot
    ): void {
        if (null === $block->orig) {
            return;
        }
        $children = $block->orig->children;
        $producerIndex = null;
        foreach ($children as $i => $child) {
            if ($child === $producer) {
                $producerIndex = $i;
                break;
            }
        }
        if (null === $producerIndex) {
            return;
        }
        for ($i = $producerIndex + 1, $n = \count($children); $i < $n; ++$i) {
            $consumer = $children[$i];
            if (!$this->isInlineExprCallArgConsumer($consumer)) {
                break;
            }
            if (!property_exists($consumer, 'args') || !\is_array($consumer->args)) {
                continue;
            }
            foreach (\array_keys($consumer->args) as $argIndex) {
                if (!$this->callArgInlineProducerIsNew($consumer, (int) $argIndex, $block)) {
                    continue;
                }
                $matched = $this->matchInlineCallArgProducer(
                    $this->precedingInlineCallArgProducersBeforeCfgOp($children, $consumer),
                    $consumer->args,
                    (int) $argIndex,
                    $consumer,
                    $block
                );
                if ($matched === $producer) {
                    $block->markDeferredArrayLiteralKeepSlot($resultSlot);

                    return;
                }
            }
            if ($consumer instanceof Op\Expr\New_) {
                break;
            }
        }
    }

    /**
     * new LimitIterator(new ArrayIterator([...]), …) — Array_ prelude + inline New_ feeds outer arg #0 (#12916).
     *
     * @param list<Op\Expr> $producers
     */
    private function isNestedNewCtorArrayPreludeProducerPattern(
        array $producers,
        int $argIndex,
        int $argCount,
        int $producerCount
    ): bool {
        return null !== $this->matchNestedNewCtorInlineNewProducer($producers, $argIndex, $argCount, []);
    }

    /**
     * Inline `new Outer(new Inner([...]), …)` — Array_ prelude (optional) + first New_ (#12916).
     * ClassConstFetch/ConstFetch feeding the *inner* ctor must not bind outer args (#19439).
     *
     * @param list<Op\Expr> $producers
     */
    private function matchNestedNewCtorInlineNewProducer(
        array $producers,
        int $argIndex,
        int $argCount,
        array $callArgs = []
    ): ?Op\Expr\New_ {
        if ($argCount < 1 || \count($producers) < 1 || $argIndex >= \count($producers)) {
            return null;
        }
        if ([] !== $callArgs) {
            $callArg = $callArgs[$argIndex] ?? null;
            // Only wire a nested New_ when this call arg is that New_ (or its dead temp result).
            // Bare dead temps (e.g. outer mode ClassConstFetch) must not steal the inner New_ (#19439).
            $isNewArg = $this->callArgIsNewExpression($callArg);
            $deadTempFedByNew = false;
            if (
                !$isNewArg
                && $callArg instanceof Operand
                && $this->callArgIsDeadInlineTemporary($callArg)
            ) {
                foreach ($producers as $producer) {
                    if (!$producer instanceof Op\Expr\New_) {
                        continue;
                    }
                    if (
                        null !== $producer->result
                        && $this->operandsReferToSameVariable($producer->result, $callArg)
                    ) {
                        $deadTempFedByNew = true;
                        break;
                    }
                    // php-cfg rewrites New_->result into a distinct Temporary on the outer arg (#19439).
                    if (
                        isset($callArg->ops)
                        && \is_array($callArg->ops)
                        && \in_array($producer, $callArg->ops, true)
                    ) {
                        $deadTempFedByNew = true;
                        break;
                    }
                }
            }
            if (!$isNewArg && !$deadTempFedByNew) {
                return null;
            }
            // ClassConstFetch/ConstFetch at $argIndex may be an *inner* ctor prelude
            // (new Outer(new Inner(..., Class::C), …)); skip via the offset walk (#19439).
        }
        $offset = $argIndex;
        $callArg = [] !== $callArgs ? ($callArgs[$argIndex] ?? null) : null;
        while ($offset < \count($producers)) {
            $candidate = $producers[$offset];
            if ($candidate instanceof Op\Expr\New_) {
                // Triple-nested `new Outer(new Mid(new Inner([...])), …)` — producers list the
                // innermost New_ first; only bind the New_ that feeds this call arg (#19770).
                if (null === $callArg || $this->inlineNewProducerFeedsCallArg($candidate, $callArg)) {
                    return $candidate;
                }
                ++$offset;
                continue;
            }
            if (
                $candidate instanceof Op\Expr\Array_
                || $candidate instanceof Op\Expr\ConstFetch
                || $candidate instanceof Op\Expr\ClassConstFetch
            ) {
                ++$offset;
                continue;
            }

            return null;
        }

        return null;
    }

    /**
     * True when a dead call-arg temp (or New_ expr) is produced by this inline New_ (#18456, #19771).
     * Prevents Array_/New_/ArrowFunction producer lists from wiring the inner New_ to a Closure arg.
     */
    private function inlineNewProducerFeedsCallArg(Op\Expr\New_ $producer, ?Operand $callArg): bool
    {
        if (null === $callArg) {
            return false;
        }
        if ($this->callArgIsNewExpression($callArg)) {
            $root = $this->unwrapOperandChain($callArg);

            return $root === $producer
                || (
                    $root instanceof Op\Expr\New_
                    && null !== $producer->result
                    && null !== $root->result
                    && $this->operandsReferToSameVariable($producer->result, $root->result)
                );
        }
        if (!$this->callArgIsDeadInlineTemporary($callArg)) {
            return false;
        }
        if (
            null !== $producer->result
            && $this->operandsReferToSameVariable($producer->result, $callArg)
        ) {
            return true;
        }

        return isset($callArg->ops)
            && \is_array($callArg->ops)
            && \in_array($producer, $callArg->ops, true);
    }

    /** Dead call-arg temp whose php-cfg ops include an inline Closure/ArrowFunction (#19771). */
    private function callArgOpsContainInlineClosure(?Operand $callArg): bool
    {
        if (!$callArg instanceof Operand) {
            return false;
        }
        $root = $this->unwrapOperandChain($callArg);
        if ($root instanceof Op\Expr\ArrowFunction || $root instanceof Op\Expr\Closure) {
            return true;
        }
        foreach ($callArg->ops ?? [] as $embedded) {
            if ($embedded instanceof Op\Expr\ArrowFunction || $embedded instanceof Op\Expr\Closure) {
                return true;
            }
        }

        return false;
    }

    /**
     * array_key_exists($k, new ArrayObject([...])) — positional New_ with Array_ ctor prelude (#18456).
     * Must not bind producers[argIndex] New_ when that arg is a Closure/ArrowFunction (#19771).
     *
     * @param list<Op\Expr> $producers
     * @param list<Operand|null> $callArgs
     */
    private function matchPositionalInlineNewCallArgProducer(
        array $producers,
        array $callArgs,
        int $argIndex
    ): ?Op\Expr\New_ {
        $callArg = $callArgs[$argIndex] ?? null;
        if (
            null === $callArg
            || (
                !$this->callArgIsDeadInlineTemporary($callArg)
                && !$this->callArgIsNewExpression($callArg)
            )
        ) {
            return null;
        }
        $positional = $producers[$argIndex] ?? null;

        if ($positional instanceof Op\Expr\New_) {
            // producers[argIndex] may be an earlier nested New_ while this arg is a trailing
            // ClassConstFetch/flag or Closure dead temp — only bind when the call arg is that New_
            // (#19769 CachingIterator::FULL_CACHE, #19771 CallbackFilterIterator callback).
            return $this->inlineNewProducerFeedsCallArg($positional, $callArg) ? $positional : null;
        }
        if (
            $positional instanceof Op\Expr\Array_
            && null !== $callArg
            && (
                $this->callArgIsNewExpression($callArg)
                || ($callArg instanceof Operand && $this->callArgIsDeadInlineTemporary($callArg))
            )
        ) {
            for ($i = $argIndex + 1, $n = \count($producers); $i < $n; ++$i) {
                $follow = $producers[$i];
                if ($follow instanceof Op\Expr\New_) {
                    return $this->inlineNewProducerFeedsCallArg($follow, $callArg) ? $follow : null;
                }
                if (
                    $follow instanceof Op\Expr\Array_
                    || $follow instanceof Op\Expr\ConstFetch
                    || $follow instanceof Op\Expr\ClassConstFetch
                ) {
                    continue;
                }

                break;
            }
        }

        // take2('x', new FilesystemIterator($dir, SKIP_DOTS)) — sole producer is New_ at
        // producers[0] while the call arg is index 1 (literal first arg has no producer) (#21957).
        if (1 === \count($producers)) {
            $sole = $producers[0];
            if (
                $sole instanceof Op\Expr\New_
                && $this->inlineNewProducerFeedsCallArg($sole, $callArg)
            ) {
                return $sole;
            }
        }

        return null;
    }

    /**
     * iterator_to_array(new LimitIterator(new ArrayIterator([...]), …)) — trailing inline New_ (#12916).
     *
     * @param list<Op\Expr> $producers
     */
    private function matchTrailingInlineNewCallArgProducer(
        array $producers,
        array $callArgs,
        int $argIndex
    ): ?Op\Expr\New_ {
        if (0 !== $argIndex || 1 !== \count($callArgs)) {
            return null;
        }
        $callArg = $callArgs[$argIndex] ?? null;
        if (
            !$callArg instanceof Operand
            || !$this->callArgIsDeadInlineTemporary($callArg)
            || $this->callArgOperandExpectsArrayProducer($callArg)
        ) {
            return null;
        }
        $last = $producers[\count($producers) - 1] ?? null;

        return $last instanceof Op\Expr\New_ ? $last : null;
    }

    /** Slot for hoisted inline `new` when php-cfg dead temps omit result→slot mapping (#11321). */
    private function slotForInlineNewProducer(Block $block, Op\Expr\New_ $new, array $pendingOps = []): ?string
    {
        $slot = $block->slotForOperand($new->result);
        if (null !== $slot) {
            return (string) $slot;
        }
        $newOrdinal = 0;
        if (null !== $block->orig) {
            foreach ($block->orig->children as $child) {
                if ($child === $new) {
                    break;
                }
                if ($child instanceof Op\Expr\New_) {
                    ++$newOrdinal;
                }
            }
        }
        $seen = 0;
        foreach ($block->opCodes as $op) {
            if (OpCode::TYPE_NEW !== $op->type) {
                continue;
            }
            if ($seen === $newOrdinal) {
                return (string) $op->arg1;
            }
            ++$seen;
        }
        // compileCallArgSends() may emit New_ into $pendingOps before flushing to $block (#13342).
        foreach (array_reverse($pendingOps) as $op) {
            if ($op instanceof OpCode && OpCode::TYPE_NEW === $op->type && null !== $op->arg1) {
                return (string) $op->arg1;
            }
        }

        return null;
    }

    /** True when $producer supplies the specific $callArg operand (#9456, #9904). */
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
                $ops[] = new OpCode(OpCode::TYPE_ASSIGN, $snapshotSlot, $valueSlot);

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
    protected function compileInstanceOf(Op\Expr\InstanceOf_ $expr, Block $block): array
    {
        $union = $expr->classUnion ?? null;
        if ($union instanceof Op\Type\Union_) {
            $names = $this->instanceofUnionNamesFromCfgType($union);
            $op = new OpCode(
                OpCode::TYPE_INSTANCEOF,
                $this->compileOperand($expr->result, $block, false),
                $this->compileOperand($expr->expr, $block, true),
                null
            );
            $op->instanceofUnionTypes = $this->encodeCatchTypeList($names);

            return [$op];
        }

        $op = new OpCode(
            OpCode::TYPE_INSTANCEOF,
            $this->compileOperand($expr->result, $block, false),
            $this->compileOperand($expr->expr, $block, true),
            $this->compileOperand($expr->class, $block, true)
        );
        $keyword = $this->instanceofLexicalScopeKeyword($expr->class);
        if (null !== $keyword) {
            $op->instanceofScopeKeyword = $keyword;
        }

        return [$op];
    }

    /**
     * Lexical instanceof RHS `self`/`parent`/`static` after php-cfg rewrite (#31729).
     *
     * Class methods already lower `self`/`parent` to the FQCN; trait bodies keep the
     * keyword. `static` stays the keyword in class and trait methods (late bind).
     * Do not walk {@see Operand::$original} — a rewritten Literal('CI') may
     * still carry a Name('self') from the parser.
     *
     * @return null|'parent'|'self'|'static'
     */
    private function instanceofLexicalScopeKeyword(?Operand $class): ?string
    {
        if (null === $class) {
            return null;
        }
        if ($class instanceof Operand\Literal && is_string($class->value)) {
            $lc = strtolower($class->value);
            if ('self' === $lc || 'parent' === $lc || 'static' === $lc) {
                return $lc;
            }

            return null;
        }
        if ($class instanceof Operand\Variable && $class->name instanceof Operand\Literal) {
            return $this->instanceofLexicalScopeKeyword($class->name);
        }

        return null;
    }

    /**
     * @return OpCode[]
     */
    protected function compileIn(Op\Expr\In_ $expr, Block $block): array
    {
        return [new OpCode(
            OpCode::TYPE_IN,
            $this->compileOperand($expr->result, $block, false),
            $this->compileInOperandSlot($expr->expr, $expr, 'needle', $block),
            $this->compileInOperandSlot($expr->haystack, $expr, 'haystack', $block),
        )];
    }

    /**
     * php-cfg may assign In_ needle/haystack operands to fresh temps disconnected from
     * preceding Array_/ClassConstFetch producers (#9676, #4682).
     */
    private function compileInOperandSlot(
        Operand $operand,
        Op\Expr\In_ $inExpr,
        string $role,
        Block $block
    ): int|string|null {
        if ('needle' === $role) {
            $varOperand = $this->unwrapVariableOperand($operand);
            if (null !== $varOperand) {
                return $this->compileOperand($varOperand, $block, true);
            }
        }
        $producer = $this->findInOperandProducer($operand, $inExpr, $role, $block);
        if (null !== $producer && null !== $producer->result) {
            return $this->compileOperand($producer->result, $block, true);
        }

        return $this->compileOperand($operand, $block, true);
    }

    private function findInOperandProducer(
        Operand $operand,
        Op\Expr\In_ $inExpr,
        string $role,
        Block $block
    ): ?Op\Expr {
        if (null === $block->orig) {
            return null;
        }
        $inIndex = null;
        foreach ($block->orig->children as $i => $child) {
            if ($child === $inExpr) {
                $inIndex = $i;
                break;
            }
        }
        if (null === $inIndex) {
            return null;
        }
        for ($i = $inIndex - 1; $i >= 0; --$i) {
            $child = $block->orig->children[$i];
            if ($child instanceof Op\Expr && null !== $child->result
                && $this->operandsReferToSameVariable($child->result, $operand)) {
                return $child;
            }
        }
        if ('haystack' === $role) {
            for ($i = $inIndex - 1; $i >= 0; --$i) {
                $child = $block->orig->children[$i];
                if ($child instanceof Op\Expr\Array_) {
                    return $child;
                }
            }

            return null;
        }
        if ($operand instanceof Operand\Variable || null !== $this->unwrapVariableOperand($operand)) {
            return null;
        }
        $arrayIndex = null;
        for ($i = $inIndex - 1; $i >= 0; --$i) {
            if ($block->orig->children[$i] instanceof Op\Expr\Array_) {
                $arrayIndex = $i;
                break;
            }
        }
        $arrayValueVars = [];
        if (null !== $arrayIndex) {
            /** @var Op\Expr\Array_ $arrayExpr */
            $arrayExpr = $block->orig->children[$arrayIndex];
            foreach ($arrayExpr->values as $valueOperand) {
                if ($valueOperand instanceof Operand\Temporary) {
                    $arrayValueVars[spl_object_id($valueOperand)] = true;
                }
            }
            for ($i = $arrayIndex - 1; $i >= 0; --$i) {
                $child = $block->orig->children[$i];
                if ($child instanceof Op\Expr\ClassConstFetch && null !== $child->result) {
                    if (!isset($arrayValueVars[spl_object_id($child->result)])) {
                        return $child;
                    }
                }
            }

            return null;
        }
        for ($i = $inIndex - 1; $i >= 0; --$i) {
            $child = $block->orig->children[$i];
            if ($child instanceof Op\Expr\ClassConstFetch) {
                return $child;
            }
        }

        return null;
    }

    /**
     * @return list<string>
     */
    private function instanceofUnionNamesFromCfgType(Op\Type\Union_ $union): array
    {
        $invalid = ['int', 'string', 'float', 'bool', 'array', 'callable', 'iterable', 'object', 'mixed', 'never', 'void', 'null'];
        $names = [];
        foreach ($union->types as $type) {
            if (!$type instanceof Op\Type\Literal) {
                $this->throwCompileLogic('instanceof union type members must be class or interface names');
            }
            $name = $type->name;
            if (in_array(strtolower($name), $invalid, true)) {
                $this->throwCompileLogic('Type '.$name.' cannot be used in instanceof');
            }
            $names[] = $name;
        }
        if (count($names) < 2) {
            $this->throwCompileLogic('instanceof union requires at least two class or interface names');
        }

        return $names;
    }

    /**
     * @return OpCode[]
     */
    protected function compileClassConstFetch(Op\Expr\ClassConstFetch $expr, Block $block): array
    {
        $this->rejectIllegalLiteralClassNameOperand($expr);
        $constName = $this->staticNameFromOperand($expr->name);
        $className = $this->staticNameFromOperand($expr->class);
        if (null !== $constName && null !== $className) {
            $lcClass = $this->resolveDefaultClassConstScope($className, $block);
            if (null !== $lcClass
                && $this->isCompileTimeEnumCaseConstantMember($lcClass, ClassConstName::key($constName))) {
                return $this->compileClassConstFetchRuntimeOpCodes($expr, $block, $expr->result);
            }
        }
        $folded = $this->tryFoldClassConstFetchDefault($expr, $block, true);
        if (null !== $folded) {
            $block->registerConstant($expr->result, $folded);

            return [];
        }

        return $this->compileClassConstFetchRuntimeOpCodes($expr, $block, $expr->result);
    }

    /**
     * @return list<OpCode>
     */
    protected function compileClassConstFetchRuntimeOpCodes(
        Op\Expr\ClassConstFetch $expr,
        Block $block,
        Operand $destOperand
    ): array {
        $this->rejectIllegalLiteralClassNameOperand($expr);
        $constName = $this->staticNameFromOperand($expr->name);
        $className = $this->staticNameFromOperand($expr->class);
        if (null !== $constName
            && 'class' === strtolower($constName)
            && null !== $className
            && !$this->pseudoClassInCompileScope($className, $block)) {
            $keyword = strtolower($className);
            // Free-function ::class uses zend_ensure_valid_class_fetch_type wording (#32227).
            // File-level still uses the historical global-scope diagnostic (#5024).
            if (in_array($keyword, ['self', 'parent', 'static'], true)
                && $this->compileScopeKnowsNoClassEntry($block)) {
                $sourceFile = $expr->getFile();
                if ('' === $sourceFile) {
                    $sourceFile = 'unknown';
                }
                $this->throwCompileError(
                    PseudoClassTypeHintCompileCheck::messageFor($keyword),
                    $sourceFile,
                    $expr->getLine()
                );
            }
            $this->throwCompileError(
                'Cannot use "'.$keyword.'" in the global scope'
            );
        }
        if (null !== $constName && 'class' === strtolower($constName)) {
            $this->rejectCompileTimeInvalidExprClassPseudoConst($expr, $block);
        }
        $op = new OpCode(
            OpCode::TYPE_CLASS_CONST_FETCH,
            $this->compileOperand($destOperand, $block, false),
            $this->compileClassNameOperand($expr->class, $block),
            $this->compileOperand($expr->name, $block, true)
        );
        // Use-site line for #[\Deprecated] class-const / enum-case notices (Zend zend_attributes.c / #29381).
        // Without this, FatalSite walks back to DECLARE_CLASS and cites the declaration line.
        $this->assignSourceMetadata($op, $expr);
        if (null !== $constName
            && 'class' === strtolower($constName)
            && ($expr->class instanceof Operand\Variable || $expr->class instanceof Operand\Temporary)) {
            $op->classConstFetchOnObject = true;
        }
        $scopeKeyword = $expr->getAttribute('phpcLexicalScopeKeyword');
        if (is_string($scopeKeyword) && '' !== $scopeKeyword) {
            $op->classConstFetchScopeKeyword = $scopeKeyword;
        }

        return [$op];
    }

    /**
     * Zend zend_compile.c — non-string literal class names are compile-time fatals (#29625).
     *
     * Parenthesized int/float scalars lower to {@see Operand\Literal} (unlike true/false/null,
     * which are ConstFetch → Temporary). `Foo::bar` / `(1)::class` both use this path.
     */
    protected function rejectIllegalLiteralClassNameOperand(Op\Expr\ClassConstFetch $expr): void
    {
        $class = $this->unwrapOperandChain($expr->class);
        if (!$class instanceof Operand\Literal || \is_string($class->value)) {
            return;
        }

        throw new CompileFatal(
            $expr->getFile() ?: 'unknown',
            max(1, $expr->getLine()),
            'Illegal class name'
        );
    }

    /**
     * Zend zend_compile.c — constant invalid `::class` operands are compile-time fatals (#17949).
     *
     * @return never
     */
    protected function rejectCompileTimeInvalidExprClassPseudoConst(
        Op\Expr\ClassConstFetch $expr,
        Block $block
    ): void {
        if (null !== $this->staticNameFromOperand($expr->class)) {
            return;
        }
        $classRoot = $this->unwrapOperandChain($expr->class);
        $varName = Block::resolveVariableName($classRoot);
        if (null !== $varName && '' !== $varName) {
            return;
        }
        if ($this->operandDerivesFromNew($expr->class, $block)) {
            return;
        }
        if ($this->cfgOperandReferencesScriptVariable($expr->class, $block)) {
            return;
        }
        $children = null !== $block->orig ? $block->orig->children : [];
        $producer = $this->findCfgExprProducerForOperand($expr->class, $children);
        if ($producer instanceof Op\Expr\New_
            || $producer instanceof Op\Expr\Closure
            || $producer instanceof Op\Expr\ArrowFunction
            || $producer instanceof Op\Expr\FuncCall
            || $producer instanceof Op\Expr\MethodCall
            || $producer instanceof Op\Expr\StaticCall
        ) {
            return;
        }
        $folded = null;
        if ($producer instanceof Op\Expr) {
            $folded = $this->tryFoldCompileTimeExprDefault($producer, $block, $children, true);
        }
        if (null === $folded) {
            $folded = $this->tryFoldCompileTimeOperandDefault($expr->class, $block, $children, true);
        }
        if (null === $folded) {
            return;
        }
        if (Variable::TYPE_STRING === $folded->type) {
            return;
        }
        throw new CompileFatal(
            $expr->getFile() ?: 'unknown',
            max(1, $expr->getLine()),
            \PHPCompiler\VM\EnumCaseSupport::classPseudoConstTypeErrorMessage($folded)
        );
    }

    protected function compileTimeValueTypeLabel(Variable $value): string
    {
        if (Variable::TYPE_OBJECT === $value->type || Variable::TYPE_ENUM_CASE === $value->type) {
            return 'object';
        }

        return match ($value->type) {
            Variable::TYPE_STRING => 'string',
            Variable::TYPE_INTEGER => 'int',
            Variable::TYPE_FLOAT => 'float',
            Variable::TYPE_BOOLEAN => 'bool',
            Variable::TYPE_NULL => 'null',
            Variable::TYPE_ARRAY => 'array',
            default => 'mixed',
        };
    }

    /**
     * @param list<Op> $cfgChildren
     */
    protected function findCfgExprProducerForOperand(Operand $operand, array $cfgChildren): ?Op\Expr
    {
        $root = $this->unwrapOperandChain($operand);
        foreach ($cfgChildren as $child) {
            if (!$child instanceof Op\Expr) {
                continue;
            }
            if (!property_exists($child, 'result') || !$this->operandsReferToSameVariable($child->result, $root)) {
                continue;
            }

            return $child;
        }

        return null;
    }

    protected function cfgOperandReferencesScriptVariable(Operand $operand, Block $block): bool
    {
        $children = null !== $block->orig ? $block->orig->children : [];
        $producer = $this->findCfgExprProducerForOperand($operand, $children);
        if ($producer instanceof Op\Expr) {
            return $this->cfgExprTreeReferencesScriptVariable($producer, $block);
        }
        $name = Block::resolveVariableName($this->unwrapOperandChain($operand));

        return null !== $name && '' !== $name;
    }

    protected function cfgExprTreeReferencesScriptVariable(Op\Expr $expr, Block $block): bool
    {
        if ($expr instanceof Op\Expr\BinaryOp) {
            return $this->cfgOperandReferencesScriptVariable($expr->left, $block)
                || $this->cfgOperandReferencesScriptVariable($expr->right, $block);
        }
        if ($expr instanceof Op\Expr\UnaryMinus
            || $expr instanceof Op\Expr\UnaryPlus
            || $expr instanceof Op\Expr\BitwiseNot
            || $expr instanceof Op\Expr\BooleanNot
        ) {
            return $this->cfgOperandReferencesScriptVariable($expr->expr, $block);
        }
        if ($expr instanceof Op\Expr\Cast) {
            return $this->cfgOperandReferencesScriptVariable($expr->expr, $block);
        }
        if ($expr instanceof Op\Expr\ArrayDimFetch) {
            return $this->cfgOperandReferencesScriptVariable($expr->var, $block)
                || (null !== $expr->dim && $this->cfgOperandReferencesScriptVariable($expr->dim, $block));
        }
        if ($expr instanceof Op\Expr\PropertyFetch) {
            return $this->cfgOperandReferencesScriptVariable($expr->var, $block);
        }
        if ($expr instanceof Op\Expr\ClassConstFetch) {
            return $this->cfgOperandReferencesScriptVariable($expr->class, $block);
        }
        if ($expr instanceof Op\Expr\ConstFetch) {
            return false;
        }

        return false;
    }

    /**
     * Runtime CLASS_CONST_FETCH when compile-time enum case fold fails (#4260, ext/standard/type.c).
     *
     * @return list<OpCode>
     */
    private function compileCallArgRuntimeEnumConstFetchOps(
        Operand $arg,
        Block $block,
        int $argIndex = 0,
        int $callOrdinal = 0,
        ?Op $cfgCallOp = null
    ): array {
        if (null === $block->orig) {
            return [];
        }
        if ($this->callArgOperandIsClosureValue($arg, $block)) {
            return [];
        }
        if (null !== $cfgCallOp && is_array($cfgCallOp->args ?? null)) {
            $callArg = $cfgCallOp->args[$argIndex] ?? null;
            if (null !== $callArg) {
                $callArgRoot = $this->unwrapOperandChain($callArg);
                if ($callArgRoot instanceof Op\Expr\ArrowFunction || $callArgRoot instanceof Op\Expr\Closure) {
                    return [];
                }
            }
        }
        $argRoot = $this->unwrapOperandChain($arg);
        if ($argRoot instanceof Op\Expr\ArrowFunction || $argRoot instanceof Op\Expr\Closure) {
            return [];
        }
        if (null !== $this->findInlineArrayProducerForCallArg($arg, $block, $cfgCallOp)) {
            return [];
        }
        if (
            null !== $cfgCallOp
            && (
                $this->nestedFuncCallFeedsDeadInlineCallArgZero($block, $cfgCallOp, $argIndex)
                || $this->nestedFuncCallFeedsDeadInlineCallArg($block, $cfgCallOp, $argIndex)
            )
        ) {
            return [];
        }
        // register_shutdown_function(fn(...), E::A) — arg #0 is hoisted Closure, not enum prelude (#5751).
        if (
            null !== $cfgCallOp
            && 0 === $argIndex
            && 'register_shutdown_function' === $this->resolveCfgFuncCallName($cfgCallOp)
        ) {
            foreach ($this->precedingInlineCallArgProducersBeforeCfgOp($block->orig->children, $cfgCallOp) as $producer) {
                if ($producer instanceof Op\Expr\Closure || $producer instanceof Op\Expr\ArrowFunction) {
                    return [];
                }
            }
        }
        $fetch = null;
        foreach ($block->orig->children as $child) {
            if ($child instanceof Op\Expr\ClassConstFetch
                && $this->operandsReferToSameVariable($child->result, $arg)) {
                $fetch = $child;
                break;
            }
        }
        if (!$fetch instanceof Op\Expr\ClassConstFetch) {
            $fetch = $this->enumConstFetchForCallOrdinal($block, $callOrdinal, $argIndex);
        }
        if (!$fetch instanceof Op\Expr\ClassConstFetch) {
            $callSite = $this->findCfgCallSiteForArg($block->orig->children, $arg, $cfgCallOp);
            if (null !== $callSite) {
                [$callOp, $siteArgIndex] = $callSite;
                $fetches = $this->precedingCallArgClassConstFetchesBeforeCfgOp($block->orig->children, $callOp, $block);
                $fetch = $this->precedingClassConstFetchForCallArgIndex($callOp, $siteArgIndex, $fetches);
                if (!$fetch instanceof Op\Expr\ClassConstFetch) {
                    $fetch = $this->classConstFetchForHoistedDeadPrelude($callOp, $siteArgIndex, $block);
                }
            }
        }
        if (!$fetch instanceof Op\Expr\ClassConstFetch) {
            $root = $this->unwrapOperandChain($arg);
            if ($root instanceof Op\Expr\ClassConstFetch) {
                $fetch = $root;
            }
        }
        if (!$fetch instanceof Op\Expr\ClassConstFetch) {
            return [];
        }
        $constName = $this->staticNameFromOperand($fetch->name);
        $className = $this->staticNameFromOperand($fetch->class);
        if (null === $constName || null === $className) {
            return [];
        }
        $lcClass = $this->resolveDefaultClassConstScope($className, $block);
        if (null === $lcClass || !$this->isCompileTimeEnumCaseConstantMember($lcClass, ClassConstName::key($constName))) {
            return [];
        }
        if (!$this->callArgNeedsRuntimeEnumConstFetch($arg, $fetch, $block, $cfgCallOp)) {
            return [];
        }

        return $this->compileClassConstFetchRuntimeOpCodes($fetch, $block, $arg);
    }

    /**
     * Guard ordinal/hoisted enum fetch injection — do not overwrite unrelated call-arg slots (#5637).
     */
    private function callArgNeedsRuntimeEnumConstFetch(
        Operand $arg,
        Op\Expr\ClassConstFetch $fetch,
        Block $block,
        ?Op $cfgCallOp = null
    ): bool {
        if ($this->callArgOperandIsClosureValue($arg, $block)) {
            return false;
        }
        if (null !== $cfgCallOp && null !== $block->orig && is_array($cfgCallOp->args ?? null)) {
            $callSite = $this->findCfgCallSiteForArg($block->orig->children, $arg, $cfgCallOp);
            if (null !== $callSite) {
                [$callOp, $siteArgIndex] = $callSite;
                $callArg = $callOp->args[$siteArgIndex] ?? null;
                if (null !== $callArg) {
                    $callArgRoot = $this->unwrapOperandChain($callArg);
                    if ($callArgRoot instanceof Op\Expr\BinaryOp) {
                        return false;
                    }
                    $producers = $this->precedingInlineCallArgProducersBeforeCfgOp(
                        $block->orig->children,
                        $callOp
                    );
                    if (null !== $this->matchBooleanBinaryOpInlineCallArgProducer($producers, $callArg)) {
                        return false;
                    }
                }
            }
        }
        $argRoot = $this->unwrapOperandChain($arg);
        if ($argRoot instanceof Op\Expr\PropertyFetch
            || $argRoot instanceof Op\Expr\NullsafePropertyFetch
            || $argRoot instanceof Op\Expr\NullsafeMethodCall) {
            return false;
        }
        // Guard ordinal/hoisted binding: don't inject enum const fetch ops for scalar-typed call args.
        // php-cfg may create an unrelated temp (e.g. identical/compare result) that happens to align
        // with a dead enum ClassConstFetch statement (#9030).
        if (!$argRoot instanceof Op\Expr\ClassConstFetch && null !== $argRoot->type) {
            $kind = $argRoot->type->type;
            if (
                Type::TYPE_BOOLEAN === $kind
                || Type::TYPE_LONG === $kind
                || Type::TYPE_DOUBLE === $kind
                || Type::TYPE_STRING === $kind
                || Type::TYPE_ARRAY === $kind
                || Type::TYPE_NULL === $kind
            ) {
                return false;
            }
        }
        $root = $argRoot;
        // Compare/arithmetic on enum case — compile the full Expr_* producer, not bare fetch (#8766).
        if ($root instanceof Op\Expr\BinaryOp) {
            return false;
        }
        if ($this->operandsReferToSameVariable($fetch->result, $arg)) {
            return true;
        }
        if ($root instanceof Op\Expr\ClassConstFetch) {
            return $root === $fetch
                || $this->operandsReferToSameVariable($fetch->result, $root->result);
        }
        if (null === $block->orig) {
            return false;
        }
        $callSite = $this->findCfgCallSiteForArg($block->orig->children, $arg, $cfgCallOp);
        if (null === $callSite) {
            return false;
        }
        [$callOp, $siteArgIndex] = $callSite;
        $callArg = $callOp->args[$siteArgIndex] ?? null;
        if (null === $callArg) {
            return false;
        }
        if ($this->operandsReferToSameVariable($fetch->result, $callArg)) {
            return true;
        }
        $callRoot = $this->unwrapOperandChain($callArg);
        if ($callRoot instanceof Op\Expr\ClassConstFetch) {
            return $callRoot === $fetch
                || $this->operandsReferToSameVariable($fetch->result, $callRoot->result);
        }

        // php-cfg dead prelude: ClassConstFetch stmt + distinct call-arg temp (#5933, #8725).
        return $this->isPositionalEnumCaseConstFetchForCallArg($fetch, $callOp, $siteArgIndex, $block);
    }

    /**
     * php-cfg may emit `E::A; f($unrelatedTemp)` with no CFG edge between fetch and arg (#5933, #8725).
     */
    private function isPositionalEnumCaseConstFetchForCallArg(
        Op\Expr\ClassConstFetch $fetch,
        Op $callOp,
        int $argIndex,
        Block $block
    ): bool {
        if (null === $block->orig) {
            return false;
        }
        $constName = $this->staticNameFromOperand($fetch->name);
        $className = $this->staticNameFromOperand($fetch->class);
        if (null === $constName || null === $className) {
            return false;
        }
        $lcClass = $this->resolveDefaultClassConstScope($className, $block);
        if (null === $lcClass || !$this->isCompileTimeEnumCaseConstantMember($lcClass, ClassConstName::key($constName))) {
            return false;
        }
        $children = $block->orig->children;
        $preceding = $this->precedingCallArgClassConstFetchesBeforeCfgOp($children, $callOp, $block);
        if ($this->precedingClassConstFetchForCallArgIndex($callOp, $argIndex, $preceding) === $fetch) {
            return true;
        }
        $hoisted = $this->classConstFetchForHoistedDeadPrelude($callOp, $argIndex, $block);

        return $hoisted === $fetch;
    }

    /**
     * Hoisted enum fetches must not bind to unrelated call-arg slots (pack('i', E::A); #8816, stream_set_timeout($fp, E::A); #6147).
     */
    private function isUnrelatedEnumFetchCallArg(?Operand $callArg, Op\Expr\ClassConstFetch $fetch): bool
    {
        if (null === $callArg) {
            return true;
        }
        if ($this->operandsReferToSameVariable($fetch->result, $callArg)) {
            return false;
        }
        $root = $this->unwrapOperandChain($callArg);
        if ($root instanceof Op\Expr\ClassConstFetch) {
            return $root !== $fetch
                && !$this->operandsReferToSameVariable($fetch->result, $root->result);
        }

        return true;
    }


    /**
     * Lower Closure::fromCallable(constant|[$obj,'m']) to TYPE_FROM_CALLABLE — same as FCC (#26788).
     *
     * Marks {@see OpCode::$fromCallableApi} so VM/JIT use Closure::fromCallable semantics
     * (bind `$this` for `[Class, instanceMethod]`, TypeError prefix) rather than FCC (#27138).
     *
     * Object-array form `[$this, 'method']` must not fold `$this` to class name `"this"`
     * (#27137, #27143, #23688) — lower like bound-method FCC instead.
     *
     * @return OpCode[]|null
     */
    private function tryCompileClosureFromCallableAsFcc(Op\Expr\StaticCall $expr, Block $block): ?array
    {
        $className = $this->literalScopeClassName($expr->class)
            ?? $this->staticNameFromOperand($expr->class);
        $methodName = $this->staticNameFromOperand($expr->name);
        if (null === $className || null === $methodName) {
            return null;
        }
        if ('closure' !== strtolower(ltrim($className, '\\'))) {
            return null;
        }
        if ('fromcallable' !== strtolower($methodName)) {
            return null;
        }
        if (1 !== \count($expr->args)) {
            return null;
        }
        $boundArray = $this->tryCompileClosureFromCallableObjectArray($expr, $block);
        if (null !== $boundArray) {
            return $boundArray;
        }
        $callableName = $this->literalCallableNameForFromCallable($expr->args[0], $block, $expr);
        if (null === $callableName) {
            return null;
        }
        $result = $this->compileOperand($expr->result, $block, false);
        $callableSlot = $this->compileOperand(new Operand\Literal($callableName), $block, true);
        $fromCallable = new OpCode(
            OpCode::TYPE_FROM_CALLABLE,
            $result,
            $callableSlot
        );
        $fromCallable->fromCallableApi = true;
        $this->assignSourceMetadata($fromCallable, $expr);
        return [$fromCallable];
    }
    /**
     * Closure::fromCallable([$obj, 'method']) → INIT_ARRAY + TYPE_FROM_CALLABLE (#27137, #27143).
     *
     * Same shape as {@see compileBoundMethodFirstClassCallable}; keeps the object receiver
     * instead of folding Variable(`this`) to the string class name `"this"`.
     * Runtime method names (Slim `[$this->creator, $this->method]`) stay as operands (#36382).
     *
     * @return OpCode[]|null
     */
    private function tryCompileClosureFromCallableObjectArray(Op\Expr\StaticCall $expr, Block $block): ?array
    {
        $arrayExpr = $this->findFromCallableArrayExpr($expr->args[0], $block, $expr);
        if (!$arrayExpr instanceof Op\Expr\Array_) {
            return null;
        }
        $values = $arrayExpr->values ?? [];
        if (2 !== \count($values)) {
            return null;
        }
        // Class-name string / Class::class → string callable path (#27138).
        if (null !== $this->literalCallableArrayElementString($values[0], $block)) {
            return null;
        }
        $methodLiteral = $this->literalCallableArrayElementString($values[1], $block)
            ?? $this->literalStringAssignedToOperand($values[1], $block);
        $result = $this->compileOperand($expr->result, $block, false);
        $receiverSlot = $this->compileOperand($values[0], $block, true);
        $methodSlot = null !== $methodLiteral
            ? $this->compileOperand(new Operand\Literal($methodLiteral), $block, true)
            : $this->compileOperand($values[1], $block, true);
        $fromCallable = new OpCode(
            OpCode::TYPE_FROM_CALLABLE,
            $result,
            $result
        );
        $fromCallable->fromCallableApi = true;
        $this->assignSourceMetadata($fromCallable, $expr);
        return [
            new OpCode(
                OpCode::TYPE_INIT_ARRAY,
                $result,
                $receiverSlot,
                $this->compileIntegerLiteralSlot(0, $block)
            ),
            new OpCode(
                OpCode::TYPE_ADD_ARRAY_ELEMENT,
                $result,
                $methodSlot,
                $this->compileIntegerLiteralSlot(1, $block)
            ),
            $fromCallable,
        ];
    }
    private function findFromCallableArrayExpr(Operand $arg, Block $block, Op\Expr\StaticCall $callOp): ?Op\Expr\Array_
    {
        if (null === $block->orig) {
            return null;
        }
        foreach ($block->orig->children as $child) {
            if (
                $child instanceof Op\Expr\Array_
                && null !== $child->result
                && $this->operandsReferToSameVariable($child->result, $arg)
            ) {
                return $child;
            }
            // `$c = [$obj, $m]; Closure::fromCallable($c)` — Slim ServerRequestCreator (#36382).
            if (
                $child instanceof Op\Expr\Assign
                && null !== $child->var
                && $this->operandsReferToSameVariable($child->var, $arg)
                && ($child->expr ?? null) instanceof Operand
            ) {
                foreach ($block->orig->children as $inner) {
                    if (
                        $inner instanceof Op\Expr\Array_
                        && null !== $inner->result
                        && $this->operandsReferToSameVariable($inner->result, $child->expr)
                    ) {
                        return $inner;
                    }
                }
            }
        }
        $callIndex = $this->cfgCallOpIndexInChildren($block->orig->children, $callOp, $block->orig);
        if (\is_int($callIndex) && $callIndex > 0) {
            $prev = $block->orig->children[$callIndex - 1] ?? null;
            if ($prev instanceof Op\Expr\Array_) {
                return $prev;
            }
        }
        return null;
    }
    /** Resolve Temporary holding a string literal Assign (php-cfg array element shape). */
    private function literalStringAssignedToOperand(Operand $op, Block $block): ?string
    {
        if ($op instanceof Operand\Literal && \is_string($op->value)) {
            return $op->value;
        }
        if (null === $block->orig) {
            return null;
        }
        foreach ($block->orig->children as $child) {
            if (
                $child instanceof Op\Expr\Assign
                && null !== $child->var
                && $this->operandsReferToSameVariable($child->var, $op)
            ) {
                $expr = $child->expr ?? null;
                if ($expr instanceof Operand\Literal && \is_string($expr->value)) {
                    return $expr->value;
                }
            }
        }
        return null;
    }
    private function literalCallableNameForFromCallable(Operand $arg, Block $block, Op\Expr\StaticCall $callOp): ?string
    {
        $direct = $this->staticNameFromOperand($arg);
        if (null !== $direct) {
            return $direct;
        }
        $arrayExpr = $this->findFromCallableArrayExpr($arg, $block, $callOp);
        if (!$arrayExpr instanceof Op\Expr\Array_) {
            return null;
        }
        $values = $arrayExpr->values ?? [];
        if (2 !== \count($values)) {
            return null;
        }
        $classPart = $this->literalCallableArrayElementString($values[0], $block);
        $methodPart = $this->literalCallableArrayElementString($values[1], $block)
            ?? $this->literalStringAssignedToOperand($values[1], $block);
        if (null === $classPart || null === $methodPart) {
            return null;
        }
        return $classPart.'::'.$methodPart;
    }
    private function literalCallableArrayElementString(Operand $op, Block $block): ?string
    {
        // Only true string literals — Variable(name) may be `$this` / `$obj` and must not
        // fold to a class-name string for Closure::fromCallable (#27137, #27138, #23688).
        if ($op instanceof Operand\Literal && \is_string($op->value)) {
            return $op->value;
        }
        if (null === $block->orig) {
            return null;
        }
        foreach ($block->orig->children as $child) {
            if (
                $child instanceof Op\Expr\ClassConstFetch
                && null !== $child->result
                && $this->operandsReferToSameVariable($child->result, $op)
            ) {
                $name = $this->staticNameFromOperand($child->name);
                if ('class' === strtolower((string) $name)) {
                    return $this->literalScopeClassName($child->class)
                        ?? $this->staticNameFromOperand($child->class);
                }
            }
        }
        return null;
    }
    /**
     * Lower PHP 8.1 first-class callables to Closure objects via TYPE_FROM_CALLABLE (#1230, #4810).
     *
     * @return OpCode[]
     */
    protected function compileFirstClassCallable(Op\Expr\FirstClassCallable $expr, Block $block): array
    {
        $result = $this->compileOperand($expr->result, $block, false);
        // `parent::` / `self::` / `static::` instanceMethod(...) — bound `$this` closures (#17655, #26630).
        // Static methods / static context: fall through to `"Class::m"` string FCC (#26252).
        // Binding `$this` from a static method yields a null receiver and fromCallable fails.
        if (Op\Expr\FirstClassCallable::KIND_STATIC === $expr->kind && null !== $expr->class) {
            $this->rejectPseudoClassFetchOutsideKnownClassScope(
                $this->firstClassCallableScopeKeyword($expr->class),
                $block,
                $expr
            );
        }
        if (
            Op\Expr\FirstClassCallable::KIND_STATIC === $expr->kind
            && null !== $expr->class
            && !$this->blockIsStaticMethodContext($block)
        ) {
            $scope = $this->firstClassCallableScopeKeyword($expr->class);
            if (null !== $scope) {
                return $this->compileBoundMethodFirstClassCallable(
                    $expr,
                    $block,
                    $result,
                    new Operand\Variable(new Operand\Literal('this')),
                    // `static::` is late-bound (virtual); `self`/`parent` pin the resolve class.
                    'static' === $scope ? null : $scope
                );
            }
        }
        // Numeric kinds: avoid php-cfg class const fetch during self-host bundle JIT (#1056).
        if (3 === $expr->kind) {
            return $this->compileBoundMethodFirstClassCallable(
                $expr,
                $block,
                $result,
                $expr->var,
                null
            );
        }

        // php-src never accepts `new Class(...)` FCC (Zend/zend_compile.c; #10130, #26188).
        if (Op\Expr\FirstClassCallable::KIND_NEW === $expr->kind) {
            $this->throwCompileError('Cannot create Closure for new expression');
        }

        $scopeKeyword = null;
        if (2 === $expr->kind && null !== $expr->class) {
            $scopeKeyword = $this->firstClassCallableScopeKeyword($expr->class);
        }

        if (1 === $expr->kind) {
            if ($expr->name instanceof Operand\Literal) {
                $callableSlot = $this->compileFirstClassFunctionNameSlot($expr->name, $block);
            } else {
                // Enum case `(E::A)(...)` is KIND_FUNCTION with non-literal name (#6851, zend_compile.c).
                $callableSlot = $this->compileOperand($expr->name, $block, true);
            }
        } elseif (2 === $expr->kind) {
            $callableSlot = $this->compileFirstClassStaticNameSlot($expr->class, $expr->name, $block);
        } else {
            $this->throwCompileLogic('Unknown first-class callable kind');
        }

        $fromCallable = new OpCode(
            OpCode::TYPE_FROM_CALLABLE,
            $result,
            $callableSlot
        );
        // Bake self/parent → fqcn for AOT/JIT lookup, but keep the keyword so VM can preserve
        // creation-time late-static called_scope (B::viaSelf with self::foo → B, not A) (#27835).
        if ('self' === $scopeKeyword || 'parent' === $scopeKeyword) {
            $fromCallable->fromCallableScope = $scopeKeyword;
        }
        // FCC Error throw site needs opcode line for getLine() (#24397, zend_exceptions.c).
        $this->assignSourceMetadata($fromCallable, $expr);

        return [$fromCallable];
    }

    /**
     * Lower `$obj->m(...)` / `parent|self|static::m(...)` to `[receiver, method]` + TYPE_FROM_CALLABLE
     * (#3566, #17655, #26630).
     *
     * @param null|'parent'|'self' $scope  null = virtual (`$obj->` / `static::`); pin for self/parent
     *
     * @return OpCode[]
     */
    private function compileBoundMethodFirstClassCallable(
        Op\Expr\FirstClassCallable $expr,
        Block $block,
        int $result,
        Operand $receiver,
        ?string $scope = null
    ): array {
        $callableSlot = $this->compileOperand($expr->result, $block, false);
        $receiverSlot = $this->compileOperand($receiver, $block, true);
        $methodSlot = $this->compileOperand($expr->name, $block, true);
        $fromCallable = new OpCode(
            OpCode::TYPE_FROM_CALLABLE,
            $result,
            $callableSlot
        );
        $fromCallable->fromCallableScope = $scope;
        $this->assignSourceMetadata($fromCallable, $expr);

        return [
            new OpCode(
                OpCode::TYPE_INIT_ARRAY,
                $callableSlot,
                $receiverSlot,
                $this->compileIntegerLiteralSlot(0, $block)
            ),
            new OpCode(
                OpCode::TYPE_ADD_ARRAY_ELEMENT,
                $callableSlot,
                $methodSlot,
                $this->compileIntegerLiteralSlot(1, $block)
            ),
            $fromCallable,
        ];
    }

    private function compileFirstClassFunctionNameSlot(Operand $name, Block $block): int
    {
        if (!$name instanceof Operand\Literal) {
            $this->throwCompileLogic('First-class function callable name must be a literal');
        }

        return $this->compileStringLiteralSlot($name->value, $block);
    }

    private function compileFirstClassStaticNameSlot(?Operand $class, Operand $method, Block $block): int
    {
        if (!$class instanceof Operand\Literal || !$method instanceof Operand\Literal) {
            $this->throwCompileLogic('First-class static callable requires literal class and method names');
        }
        $className = $this->resolveFirstClassStaticClassName((string) $class->value, $block);

        return $this->compileStringLiteralSlot($className.'::'.$method->value, $block);
    }

    /**
     * Resolve `parent` / `self` in FCC Class::method strings for AOT/JIT (#26252).
     *
     * VM {@see ClosureSupport::resolveClassScopeName} rewrites at runtime; native emit must
     * bake a real class name or lookup fails with `undefined static method parent::m()`.
     */
    private function resolveFirstClassStaticClassName(string $className, Block $block): string
    {
        $lc = strtolower($className);
        if ('parent' === $lc) {
            if (null !== $this->compilingClassParentLc && '' !== $this->compilingClassParentLc) {
                return $this->compilingClassParentDisplayName() ?? $this->compilingClassParentLc;
            }
            $this->throwCompileError('Cannot use "parent" when current class scope has no parent');
        }
        if ('self' === $lc) {
            if (null !== $block->func && null !== $block->func->class && '' !== (string) $block->func->class->value) {
                return (string) $block->func->class->value;
            }
            if (null !== $this->compilingClassLc && '' !== $this->compilingClassLc) {
                return $this->compilingClassLc;
            }
        }

        return $className;
    }

    /** Display name for the class currently being compiled's extends clause (#26252). */
    private function compilingClassParentDisplayName(): ?string
    {
        if (null !== $this->compilingClassParentName && '' !== $this->compilingClassParentName) {
            return $this->compilingClassParentName;
        }
        if (null === $this->compilingClassParentLc || '' === $this->compilingClassParentLc) {
            return null;
        }

        return $this->compilingClassParentLc;
    }

    private function compileStringLiteralSlot(string $value, Block $block): int
    {
        $var = new Variable(Variable::TYPE_STRING);
        $var->string($value, true);
        $operand = new Temporary();
        $operand->type = Type::string();

        return $block->registerConstant($operand, $var);
    }

    private function compileIntegerLiteralSlot(int $value, Block $block): int
    {
        $var = new Variable(Variable::TYPE_INTEGER);
        $var->int($value);
        $operand = new Temporary();
        $operand->type = Type::int();

        return $block->registerConstant($operand, $var);
    }

    /**
     * Zend/php-src rejects file-scope `final const` below PHP 8.4 (#10324, #15185, #16859).
     */
    protected function rejectFinalGlobalTypedConstantIfUnsupported(Op\Terminal\Const_ $const): void
    {
        if (0 === ($const->flags & \PhpParser\Node\Stmt\Class_::MODIFIER_FINAL)) {
            return;
        }
        if (\PHPCompiler\CompilerVersion::supportsFinalGlobalTypedConstants()) {
            return;
        }
        $this->throwCompileError(\PHPCompiler\Ast\GlobalTypedConstRewriter::FINAL_GLOBAL_CONST_REJECT_MESSAGE);
    }

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

    protected function operandIsInvokableReceiver(Operand $operand, Block $block): bool
    {
        // First-class callables are Closure objects; use FUNC_CALL dispatch, not `$x->__invoke(...)`.
        if (null !== $block->orig) {
            $root = $this->unwrapOperandChain($operand);
            foreach ($block->orig->children as $child) {
                if (!$child instanceof Op\Expr\Assign) {
                    continue;
                }
                if (!$this->operandsReferToSameVariable($child->var, $root)) {
                    continue;
                }
                if ($child->expr instanceof Op\Expr\FirstClassCallable) {
                    return false;
                }
            }
        }

        if ($this->operandHasObjectType($operand)
            && !$this->variableAssignIsNullableClosureBinding($operand, $block)
            && $this->operandObjectTypeHasProvableInvoke($operand, $block)) {
            return true;
        }
        $root = $this->unwrapOperandChain($operand);
        if ($root instanceof Op\Expr\ClassConstFetch
            && $this->classConstFetchIsInvokableEnumCase($root, $block)) {
            return true;
        }
        if ($root instanceof Op\Expr\New_) {
            return $this->newExprHasInvokeMethod($root, $block);
        }
        if (null === $block->orig) {
            return false;
        }
        foreach ($block->orig->children as $child) {
            if (!$child instanceof Op\Expr\Assign) {
                continue;
            }
            if (!$this->operandsReferToSameVariable($child->var, $root)) {
                continue;
            }
            if ($this->assignExprIsNullableClosureBinding($child->expr)) {
                continue;
            }
            if ($this->operandDerivesFromNew($child->expr, $block)) {
                $new = $this->findNewExprForCalleeOperand($operand, $block);
                if (null !== $new && $this->newExprHasInvokeMethod($new, $block)) {
                    return true;
                }
                continue;
            }
            if ($this->operandDerivesFromClosure($child->expr)) {
                return true;
            }
            if ($this->operandHasObjectType($child->expr)
                && $this->operandObjectTypeHasProvableInvoke($child->expr, $block)) {
                return true;
            }
            if ($child->expr instanceof Op\Expr\ClassConstFetch
                && $this->classConstFetchIsInvokableEnumCase($child->expr, $block)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Only rewrite `$v()` to `$v->__invoke()` when __invoke is provable at compile time (#17745).
     *
     * Untyped or non-invokable objects keep FUNCCALL_INIT so Zend callable errors apply.
     */
    protected function operandObjectTypeHasProvableInvoke(Operand $operand, Block $block): bool
    {
        if ($this->callArgOperandIsAssignedClosure($operand, $block)) {
            return true;
        }
        $new = $this->findNewExprForCalleeOperand($operand, $block);
        if (null !== $new) {
            return $this->newExprHasInvokeMethod($new, $block);
        }
        $className = $this->unwrapOperandChain($operand)->type?->userType;
        if (null === $className || '' === ltrim($className, '\\')) {
            return false;
        }
        $lcClass = strtolower(ltrim($className, '\\'));
        if ('closure' === $lcClass) {
            return true;
        }

        return $this->declaredClassHasInstanceMethod($lcClass, '__invoke', $block);
    }

    /**
     * @param non-empty-string $lcClass
     */
    protected function declaredClassHasInstanceMethod(string $lcClass, string $methodLc, Block $block): bool
    {
        $methodLc = strtolower($methodLc);
        // Prefer ClassCompileRegistry — class stmts are hoisted into other CFG blocks (#26426).
        if ($this->classCompileRegistry->hasMethod($lcClass, $methodLc)) {
            return true;
        }
        if (null === $block->orig) {
            return false;
        }
        foreach ($block->orig->children as $child) {
            if (!$child instanceof Op\Stmt\Class_) {
                continue;
            }
            $name = $this->literalScopeClassName($child->name);
            if (null === $name || strtolower($name) !== $lcClass) {
                continue;
            }
            foreach ($child->stmts->children as $stmt) {
                if ($stmt instanceof Op\Stmt\ClassMethod && strtolower($stmt->func->name) === $methodLc) {
                    return true;
                }
            }

            return false;
        }

        return false;
    }

    private function variableAssignIsNullableClosureBinding(Operand $operand, Block $block): bool
    {
        if ($this->variableAssignIsNullableClosureBindingInOrig($operand, $block)) {
            return true;
        }
        $root = $this->unwrapOperandChain($operand);
        if (!$root instanceof CfgVariable) {
            return false;
        }
        $slot = null;
        foreach ($block->eachCfgVarRootSlot() as [$varRoot, $varSlot]) {
            if ($varRoot === $root) {
                $slot = $varSlot;
                break;
            }
        }
        if (null === $slot) {
            return false;
        }
        foreach ($block->opCodes as $op) {
            if (OpCode::TYPE_ASSIGN !== $op->type || $op->arg2 !== $slot) {
                continue;
            }
            $rhs = $block->getOperand((int) $op->arg3);
            if ($this->assignExprIsNullableClosureBinding($rhs)) {
                return true;
            }
        }

        return false;
    }

    private function variableAssignIsNullableClosureBindingInOrig(Operand $operand, Block $block): bool
    {
        if (null === $block->orig) {
            return false;
        }
        $root = $this->unwrapOperandChain($operand);
        foreach ($block->orig->children as $child) {
            if (!$child instanceof Op\Expr\Assign) {
                continue;
            }
            if (!$this->operandsReferToSameVariable($child->var, $root)) {
                continue;
            }
            if ($this->assignExprIsNullableClosureBinding($child->expr)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Parenthesized enum case `(E::A)()` is a callable object, not a string callee (#7386).
     */
    private function classConstFetchIsInvokableEnumCase(
        Op\Expr\ClassConstFetch $fetch,
        Block $block
    ): bool {
        $className = $this->staticNameFromOperand($fetch->class);
        $constName = $this->staticNameFromOperand($fetch->name);
        if (null === $className || null === $constName) {
            return false;
        }
        $lcClass = $this->resolveDefaultClassConstScope($className, $block);
        if (null === $lcClass) {
            $lcClass = strtolower(ltrim($className, '\\'));
        }
        $lcConst = ClassConstName::key($constName);
        if (isset($this->compileTimeEnumCaseConstNames[$lcClass][$lcConst])) {
            return true;
        }
        if (!isset($this->compileTimeClassConsts[$lcClass][$lcConst])) {
            return false;
        }
        $stored = $this->compileTimeClassConsts[$lcClass][$lcConst];

        return Variable::TYPE_ENUM_CASE === $stored->type
            || (Variable::TYPE_OBJECT === $stored->type && EnumCaseSupport::isEnumCase($stored->toObject()));
    }

    protected function operandDerivesFromClosure(Operand $operand): bool
    {
        $root = $this->unwrapOperandChain($operand);

        return $root instanceof Op\Expr\Closure || $root instanceof Op\Expr\ArrowFunction;
    }

    /** php-cfg assigns closure callbacks to temps before user-comparator calls (#8947, array_udiff). */
    private function callArgOperandIsAssignedClosure(Operand $operand, Block $block): bool
    {
        if (null === $block->orig) {
            return false;
        }
        $root = $this->unwrapOperandChain($operand);
        foreach ($block->orig->children as $child) {
            if (!$child instanceof Op\Expr\Assign) {
                continue;
            }
            if (!$this->operandsReferToSameVariable($child->var, $root)) {
                continue;
            }

            return $this->exprDerivesFromClosure($child->expr);
        }

        return false;
    }

    /** Assign RHS is the same inline closure CFG node or a temp referring to it (#5644, composer autoload). */
    private function assignExprMatchesClosureProducer(Operand|Op\Expr $assignExpr, Op\Expr $producer): bool
    {
        if ($assignExpr === $producer) {
            return true;
        }
        if (!$assignExpr instanceof Operand) {
            return false;
        }
        if (null !== $producer->result) {
            return $this->operandsReferToSameVariable($assignExpr, $producer->result);
        }

        return false;
    }

    private function exprDerivesFromClosure(Operand|Op\Expr $expr): bool
    {
        if ($expr instanceof Op\Expr\Closure || $expr instanceof Op\Expr\ArrowFunction) {
            return true;
        }
        if ($expr instanceof Operand) {
            return $this->operandDerivesFromClosure($expr);
        }

        return false;
    }

    /** Inline or assigned closure comparators must not consume hoisted enum prelude slots (#8947). */
    private function callArgOperandIsClosureValue(Operand $operand, Block $block, ?string $calleeName = null): bool
    {
        if ($this->callArgIsNullLiteral($operand)) {
            return false;
        }
        if ($this->isEmbeddedCallLiteralArg($operand)) {
            return false;
        }
        if ($this->operandDerivesFromClosure($operand)) {
            return true;
        }
        if ($this->unwrapOperandChain($operand) instanceof Op\Expr\FirstClassCallable) {
            return true;
        }
        if ($this->callArgOperandIsAssignedClosure($operand, $block)) {
            return true;
        }
        if (null === $block->orig) {
            return false;
        }
        $callSite = $this->findCfgCallSiteForArg($block->orig->children, $operand);
        if (null !== $callSite) {
            [$callOp, $argIndex] = $callSite;
            if (
                0 === $argIndex
                && $this->cfgCallAcceptsSingleInlineClosureCallback($callOp)
            ) {
                foreach ($this->precedingInlineCallArgProducersBeforeCfgOp($block->orig->children, $callOp) as $candidate) {
                    if ($candidate instanceof Op\Expr\Closure || $candidate instanceof Op\Expr\ArrowFunction) {
                        return true;
                    }
                }
            }
            if (property_exists($callOp, 'args') && is_array($callOp->args)) {
                $producers = $this->precedingInlineCallArgProducersBeforeCfgOp($block->orig->children, $callOp);
                foreach ($producers as $candidate) {
                    if ($candidate instanceof Op\Expr\FirstClassCallable
                        && null !== $this->matchSingleFirstClassCallableInlineProducer(
                            $candidate,
                            $callOp->args,
                            $argIndex,
                            $this->resolveInlineCallArgFuncName($callOp, $calleeName)
                        )) {
                        return true;
                    }
                    if (
                        ($candidate instanceof Op\Expr\ArrowFunction || $candidate instanceof Op\Expr\Closure)
                        && null !== $this->matchSingleClosureInlineProducer(
                            $candidate,
                            $callOp->args,
                            $argIndex,
                            $this->resolveInlineCallArgFuncName($callOp, $calleeName)
                        )
                    ) {
                        return true;
                    }
                }
                $producer = $this->matchInlineCallArgProducer($producers, $callOp->args, $argIndex, $callOp, $block, $calleeName);
                if ($producer instanceof Op\Expr\ArrowFunction
                    || $producer instanceof Op\Expr\Closure
                    || $producer instanceof Op\Expr\FirstClassCallable) {
                    return true;
                }
            }
        }
        foreach ($block->orig->children as $child) {
            if ($child instanceof Op\Expr\ArrowFunction
                || $child instanceof Op\Expr\Closure
                || $child instanceof Op\Expr\FirstClassCallable) {
                if ($this->operandsReferToSameVariable($child->result, $operand)) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * bind/bindTo may return null at runtime (internal scope, missing class); do not
     * compile $v() as $v->__invoke() from assign-chain inference (#5170, zend_closures.c).
     */
    private function assignExprIsNullableClosureBinding(?Operand $operand): bool
    {
        if (null === $operand) {
            return false;
        }
        $root = $this->unwrapOperandChain($operand);
        if ($root instanceof Op\Expr\MethodCall) {
            $method = $this->staticNameFromOperand($root->name);

            return null !== $method && in_array(strtolower($method), ['bind', 'bindto'], true);
        }
        if ($root instanceof Op\Expr\StaticCall) {
            $class = $this->staticNameFromOperand($root->class);
            $method = $this->staticNameFromOperand($root->name);

            return null !== $class
                && null !== $method
                && 'closure' === strtolower(ltrim($class, '\\'))
                && 'bind' === strtolower($method);
        }

        return false;
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

    protected function operandDerivesFromNew(?Operand $operand, Block $block): bool
    {
        return null !== $this->findNewExprForCalleeOperand($operand, $block);
    }

    /**
     * Zend: `(new C)(...)` applies outer args only when `__invoke` exists (#10176, zend_compile.c).
     */
    protected function parensNewCallSkippedWithoutInvoke(Operand $callee, Block $block): bool
    {
        $new = $this->findNewExprForCalleeOperand($callee, $block);
        if (null === $new) {
            return false;
        }

        return !$this->newExprHasInvokeMethod($new, $block);
    }

    protected function findNewExprForCalleeOperand(?Operand $operand, Block $block): ?Op\Expr\New_
    {
        if (null === $operand || null === $block->orig) {
            return null;
        }
        $root = $this->unwrapOperandChain($operand);
        foreach ($block->orig->children as $child) {
            if ($child instanceof Op\Expr\New_ && $this->unwrapOperandChain($child->result) === $root) {
                return $child;
            }
        }
        foreach ($block->orig->children as $child) {
            if (!$child instanceof Op\Expr\Assign) {
                continue;
            }
            if (!$this->operandsReferToSameVariable($child->var, $root)) {
                continue;
            }
            if ($child->expr instanceof Op\Expr\New_) {
                return $child->expr;
            }
        }

        return null;
    }

    protected function newExprHasInvokeMethod(Op\Expr\New_ $new, Block $block): bool
    {
        $className = $this->literalScopeClassName($new->class);
        // Named classes: registry sees decls hoisted out of try/catch CFG blocks (#26426).
        if (null !== $className && '' !== $className
            && $this->classCompileRegistry->hasMethod($className, '__invoke')) {
            return true;
        }
        if (null === $className || null === $block->orig) {
            return false;
        }
        // Same-block fallback (anonymous `new class { function __invoke… }` / #10176).
        foreach ($block->orig->children as $child) {
            if (!$child instanceof Op\Stmt\Class_) {
                continue;
            }
            if ($className !== $this->literalScopeClassName($child->name)) {
                continue;
            }
            foreach ($child->stmts->children as $stmt) {
                if (!$stmt instanceof Op\Stmt\ClassMethod) {
                    continue;
                }
                if ('__invoke' === strtolower($stmt->func->name)) {
                    return true;
                }
            }

            return false;
        }

        return false;
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

    /**
     * True when a call arg reads the `@`-suppressed inner expression in the post-END_SILENCE block (#15916).
     */
    private function callArgIsErrorSuppressForwardedResult(Operand $callArg, Block $block): bool
    {
        $endCfg = $block->orig;
        if (null === $endCfg || 1 !== \count($endCfg->parents)) {
            return false;
        }
        $parent = $endCfg->parents[0];
        if (!$parent instanceof ErrorSuppressBlock) {
            return false;
        }
        $primary = $this->findErrorSuppressPrimaryInnerExpr($parent);
        if (null === $primary || !isset($primary->result)) {
            return false;
        }

        return $this->operandsReferToSameVariable($callArg, $primary->result);
    }

    /** FUNCCALL_EXEC_RETURN / TYPE_INCLUDE slot from the {@see ErrorSuppressBlock} parent (#15916, #21938). */
    private function errorSuppressEndBlockInnerResultSlot(Block $block): ?int
    {
        if ($this->errorSuppressEndBlockDiscardsInnerResultForErrorGetLast($block)) {
            return null;
        }
        $endCfg = $block->orig;
        if (null === $endCfg || !$this->isErrorSuppressEndBlock($endCfg)) {
            return null;
        }
        $parentCfg = $endCfg->parents[0];
        if (!$parentCfg instanceof ErrorSuppressBlock || !$this->seen->contains($parentCfg)) {
            return null;
        }
        $parentCompiled = $this->seen[$parentCfg];
        if (!$parentCompiled instanceof Block) {
            return null;
        }
        $primary = $this->findErrorSuppressPrimaryInnerExpr($parentCfg);
        if (null !== $primary && isset($primary->result)) {
            $bound = $parentCompiled->slotForOperand($primary->result);
            if (null !== $bound) {
                return (int) $bound;
            }
        }
        $execReturn = $this->findFuncCallExecReturnSlot($parentCompiled);
        if (null !== $execReturn) {
            return $execReturn;
        }

        return $this->findIncludeReturnSlot($parentCompiled);
    }

    private function isFirstNonEmbeddedDeadInlineCallArg(Op $cfgCallOp, int $argIndex): bool
    {
        if (!property_exists($cfgCallOp, 'args') || !is_array($cfgCallOp->args)) {
            return false;
        }
        foreach ($cfgCallOp->args as $i => $candidate) {
            if (!$candidate instanceof Operand || $this->isEmbeddedCallLiteralArg($candidate)) {
                continue;
            }
            if (!$this->callArgIsDeadInlineTemporary($candidate)) {
                continue;
            }

            return (int) $i === $argIndex;
        }

        return false;
    }

    private function errorSuppressEndBlockInnerResultSlotForCallArg(
        Block $block,
        ?Op $cfgCallOp,
        int $argIndex
    ): ?int {
        if (null === $cfgCallOp || !$this->isFirstNonEmbeddedDeadInlineCallArg($cfgCallOp, $argIndex)) {
            return null;
        }
        $callArg = (property_exists($cfgCallOp, 'args') && \is_array($cfgCallOp->args))
            ? ($cfgCallOp->args[$argIndex] ?? null)
            : null;
        if ($this->callArgOpsContainInlineClosure($callArg)) {
            return null;
        }
        if ($this->errorSuppressEndBlockDiscardsInnerResultForErrorGetLast($block)) {
            return null;
        }
        // `@mkdir(...); new Outer(new Inner(...))` — dead arg temp is the inner New_, not @mkdir (#24368).
        if ($this->callArgInlineProducerIsNew($cfgCallOp, $argIndex, $block)) {
            return null;
        }
        if ($this->errorSuppressEndBlockCallArgHasAdjacentNestedNewProducer($block, $cfgCallOp, $argIndex)) {
            return null;
        }
        // `@mkdir(...); var_export(require $f)` — include in the end block feeds the call, not @mkdir (#21938).
        if ($this->errorSuppressEndBlockCallArgHasTrailingIncludeProducer($block, $cfgCallOp, $argIndex)) {
            return null;
        }
        if ($this->errorSuppressEndBlockCallArgHasTrailingHoistedScalarProducer($block, $cfgCallOp, $argIndex)) {
            return null;
        }
        if ($this->errorSuppressEndBlockCallArgHasTrailingHoistedArrayProducer($block, $cfgCallOp, $argIndex)) {
            return null;
        }
        if ($this->errorSuppressEndBlockCallArgHasTrailingArrayDimFetchProducer($block, $cfgCallOp, $argIndex)) {
            return null;
        }
        if ($this->errorSuppressEndBlockCallArgHasAdjacentNestedFuncCallProducer($block, $cfgCallOp, $argIndex)) {
            return null;
        }
        if ($this->errorSuppressEndBlockCallArgHasTrailingComparisonProducer($block, $cfgCallOp, $argIndex)) {
            return null;
        }
        if ($this->errorSuppressEndBlockCallArgHasTrailingConcatProducer($block, $cfgCallOp, $argIndex)) {
            return null;
        }
        if ($this->errorSuppressEndBlockCallArgHasTrailingClosureProducer($block, $cfgCallOp, $argIndex)) {
            return null;
        }
        if ($this->errorSuppressEndBlockCallArgHasTrailingBitmaskProducer($block, $cfgCallOp, $argIndex)) {
            return null;
        }

        return $this->errorSuppressEndBlockInnerResultSlot($block);
    }

    /**
     * Trailing inline bitmask / scalar option prelude before a post-@ call/ctor feeds this
     * dead-temp arg — not the @ return (#24369, #18523 family).
     *
     * Example: `@mkdir($dir); new FilesystemIterator($dir, CURRENT_AS_PATHNAME | SKIP_DOTS)`.
     * Only the trailing non-embedded arg binds the prelude so `@stat; foo($stat, F|F)` still
     * forwards the suppress result on arg #0.
     */
    private function errorSuppressEndBlockCallArgHasTrailingBitmaskProducer(
        Block $block,
        Op $cfgCallOp,
        int $argIndex
    ): bool {
        if (null === $block->orig || !property_exists($cfgCallOp, 'args') || !\is_array($cfgCallOp->args)) {
            return false;
        }
        $callArg = $cfgCallOp->args[$argIndex] ?? null;
        if (!$callArg instanceof Operand || $this->isEmbeddedCallLiteralArg($callArg)) {
            return false;
        }
        if (!$this->callArgIsDeadInlineTemporary($callArg)) {
            return false;
        }
        if ((int) $argIndex !== $this->trailingNonEmbeddedCallArgIndex($cfgCallOp)) {
            return false;
        }
        $children = $block->orig->children;
        $callIndex = array_search($cfgCallOp, $children, true);
        if (!\is_int($callIndex) || $callIndex < 1) {
            return false;
        }
        $producer = null;
        for ($i = $callIndex - 1; $i >= 0 && $callIndex - $i <= 8; --$i) {
            $prev = $children[$i] ?? null;
            if (
                $prev instanceof Op\Expr\ConstFetch
                || $prev instanceof Op\Expr\ClassConstFetch
            ) {
                continue;
            }
            if ($prev instanceof Op\Expr\Assign) {
                $prev = $prev->expr;
            }
            if (
                $this->isArithmeticInlineCallArgProducer($prev)
                || $prev instanceof Op\Expr\UnaryMinus
                || $prev instanceof Op\Expr\UnaryPlus
                || $prev instanceof Op\Expr\BitwiseNot
                || $prev instanceof Op\Expr\Cast
            ) {
                $producer = $prev;
            }
            break;
        }
        if (null === $producer) {
            return false;
        }
        if (
            null !== $producer->result
            && $this->operandsReferToSameVariable($producer->result, $callArg)
        ) {
            return true;
        }

        // php-cfg allocates a distinct dead arg temp from the BitwiseOr/Plus result (#18523, #24369).
        return true;
    }

    /**
     * `var_export("$d/y")` / `printf("%s", $d."/y")` after `@strlen($d)` —
     * ConcatList / BinaryOp\Concat feeds the dead-temp arg, not the @ return (#23045).
     */
    private function errorSuppressEndBlockCallArgHasTrailingConcatProducer(
        Block $block,
        Op $cfgCallOp,
        int $argIndex
    ): bool {
        if (null === $block->orig || !property_exists($cfgCallOp, 'args') || !\is_array($cfgCallOp->args)) {
            return false;
        }
        $callArg = $cfgCallOp->args[$argIndex] ?? null;
        if (!$callArg instanceof Operand || $this->isEmbeddedCallLiteralArg($callArg)) {
            return false;
        }
        if (!$this->callArgIsDeadInlineTemporary($callArg)) {
            return false;
        }
        $children = $block->orig->children;
        $callIndex = array_search($cfgCallOp, $children, true);
        if (!\is_int($callIndex) || $callIndex < 1) {
            return false;
        }
        $producer = null;
        for ($i = $callIndex - 1; $i >= 0 && $callIndex - $i <= 8; --$i) {
            $prev = $children[$i] ?? null;
            if (
                $prev instanceof Op\Expr\ConstFetch
                || $prev instanceof Op\Expr\ClassConstFetch
                || $prev instanceof Op\Expr\UnaryMinus
                || $prev instanceof Op\Expr\UnaryPlus
            ) {
                continue;
            }
            if ($prev instanceof Op\Expr\ConcatList || $prev instanceof Op\Expr\BinaryOp\Concat) {
                $producer = $prev;
            }
            break;
        }
        if (null === $producer) {
            return false;
        }
        if (
            null !== $producer->result
            && $this->operandsReferToSameVariable($producer->result, $callArg)
        ) {
            return true;
        }

        // php-cfg allocates a distinct dead arg temp from ConcatList/Concat.result (#13466, #23045).
        return $this->isFirstNonEmbeddedDeadInlineCallArg($cfgCallOp, $argIndex);
    }

    /**
     * `@strlen(null); set_error_handler(function...)` — Closure in the end block feeds
     * the dead-temp arg, not the @ return (#23730).
     */
    private function errorSuppressEndBlockCallArgHasTrailingClosureProducer(
        Block $block,
        Op $cfgCallOp,
        int $argIndex
    ): bool {
        if (null === $block->orig || !property_exists($cfgCallOp, 'args') || !\is_array($cfgCallOp->args)) {
            return false;
        }
        $callArg = $cfgCallOp->args[$argIndex] ?? null;
        if (!$callArg instanceof Operand || $this->isEmbeddedCallLiteralArg($callArg)) {
            return false;
        }
        if (!$this->callArgIsDeadInlineTemporary($callArg)) {
            return false;
        }
        $children = $block->orig->children;
        $callIndex = array_search($cfgCallOp, $children, true);
        if (!\is_int($callIndex) || $callIndex < 1) {
            return false;
        }
        for ($i = $callIndex - 1; $i >= 0 && $callIndex - $i <= 8; --$i) {
            $prev = $children[$i] ?? null;
            if ($prev instanceof Op\Expr\Closure || $prev instanceof Op\Expr\ArrowFunction) {
                return true;
            }
            if ($prev instanceof Op\Expr\ConstFetch || $prev instanceof Op\Expr\Assign) {
                continue;
            }
            break;
        }

        return false;
    }

    /**
     * `var_export(require $f)` / `var_export(include $f, true)` after `@mkdir` — Include_/Eval_
     * in the post-silence block feeds arg #0, not the @ return (#21938, #25851).
     *
     * Two-arg form hoists `true`/`false` ConstFetch immediately before the call; skip those so
     * Include_ is still seen (single-arg already matched `$callIndex - 1`).
     */
    private function errorSuppressEndBlockCallArgHasTrailingIncludeProducer(
        Block $block,
        Op $cfgCallOp,
        int $argIndex
    ): bool {
        if (null === $block->orig || !property_exists($cfgCallOp, 'args') || !\is_array($cfgCallOp->args)) {
            return false;
        }
        $callArg = $cfgCallOp->args[$argIndex] ?? null;
        if (!$callArg instanceof Operand || $this->isEmbeddedCallLiteralArg($callArg)) {
            return false;
        }
        if (!$this->callArgIsDeadInlineTemporary($callArg)) {
            return false;
        }
        $children = $block->orig->children;
        $callIndex = array_search($cfgCallOp, $children, true);
        if (!\is_int($callIndex) || $callIndex < 1) {
            return false;
        }
        $producer = null;
        for ($i = $callIndex - 1; $i >= 0 && $callIndex - $i <= 8; --$i) {
            $prev = $children[$i] ?? null;
            if ($this->isHoistedScalarConstFetchImmediatelyBeforeCall($prev)) {
                continue;
            }
            if ($prev instanceof Op\Expr\Include_ || $prev instanceof Op\Expr\Eval_) {
                $producer = $prev;
            }
            break;
        }
        if (null === $producer) {
            return false;
        }
        if (
            null !== $producer->result
            && $this->operandsReferToSameVariable($producer->result, $callArg)
        ) {
            return true;
        }

        return $this->isFirstNonEmbeddedDeadInlineCallArg($cfgCallOp, $argIndex);
    }

    /**
     * `@f(); g(); var_export(h(), true)` — adjacent hoisted callee feeds dead-temp arg, not @ return (#8974).
     * Also `trim($d->saveHTML())` after `@$d->loadHTML(...)` — MethodCall/StaticCall producer (#22345).
     */
    private function errorSuppressEndBlockCallArgHasAdjacentNestedFuncCallProducer(
        Block $block,
        Op $cfgCallOp,
        int $argIndex
    ): bool {
        if (null === $block->orig || !property_exists($cfgCallOp, 'args') || !\is_array($cfgCallOp->args)) {
            return false;
        }
        $callArg = $cfgCallOp->args[$argIndex] ?? null;
        if (!$callArg instanceof Operand || $this->isEmbeddedCallLiteralArg($callArg)) {
            return false;
        }
        if (!$this->callArgIsDeadInlineTemporary($callArg)) {
            return false;
        }
        $callIndex = $this->cfgCallOpIndexInChildren($block->orig->children, $cfgCallOp, $block->orig);
        if (!\is_int($callIndex) || $callIndex < 1) {
            return false;
        }
        $producerIndex = $callIndex - 1;
        $producer = $block->orig->children[$producerIndex] ?? null;
        if (
            !(
                $producer instanceof Op\Expr\FuncCall
                || $producer instanceof Op\Expr\NsFuncCall
                || $producer instanceof Op\Expr\MethodCall
                || $producer instanceof Op\Expr\StaticCall
            )
            || !$this->isNestedCallArgProducerForConsumer(
                $producer,
                $cfgCallOp,
                $producerIndex,
                $callIndex,
                $block->orig->children
            )
        ) {
            return false;
        }
        $targetArgIndex = $this->siblingMultiArgFuncCallProducerTargetArgIndex(
            $producerIndex,
            $callIndex,
            $block->orig->children
        );
        if (null === $targetArgIndex) {
            $targetArgIndex = 0;
        }

        return $argIndex === $targetArgIndex;
    }

    /**
     * `@mkdir($dir); new Outer(new Inner($dir))` — adjacent New_ feeds the dead-temp arg (#24368).
     *
     * php-cfg may rewrite New_->result into a distinct Temporary on the outer arg; link via
     * `$arg->ops` / {@see inlineNewProducerFeedsCallArg} (same family as #19439 / #12916).
     */
    private function errorSuppressEndBlockCallArgHasAdjacentNestedNewProducer(
        Block $block,
        Op $cfgCallOp,
        int $argIndex
    ): bool {
        if (null === $block->orig || !property_exists($cfgCallOp, 'args') || !\is_array($cfgCallOp->args)) {
            return false;
        }
        $callArg = $cfgCallOp->args[$argIndex] ?? null;
        if (!$callArg instanceof Operand || $this->isEmbeddedCallLiteralArg($callArg)) {
            return false;
        }
        if (!$this->callArgIsDeadInlineTemporary($callArg) && !$this->callArgIsNewExpression($callArg)) {
            return false;
        }
        if (
            $callArg instanceof Operand
            && isset($callArg->ops)
            && \is_array($callArg->ops)
        ) {
            foreach ($callArg->ops as $writeOp) {
                if ($writeOp instanceof Op\Expr\New_) {
                    return true;
                }
            }
        }
        $callIndex = $this->cfgCallOpIndexInChildren($block->orig->children, $cfgCallOp, $block->orig);
        if (!\is_int($callIndex) || $callIndex < 1) {
            return false;
        }
        for ($i = $callIndex - 1; $i >= 0 && $callIndex - $i <= 8; --$i) {
            $prev = $block->orig->children[$i] ?? null;
            if ($prev instanceof Op\Expr\Assign) {
                continue;
            }
            if (!$prev instanceof Op\Expr\New_) {
                break;
            }
            if ($this->inlineNewProducerFeedsCallArg($prev, $callArg)) {
                return true;
            }
            // Nested ctor chain: inner New_ immediately precedes outer New_/call (#24368, #12916).
            if ((int) $argIndex === 0 && $i === $callIndex - 1) {
                return true;
            }
            break;
        }

        return false;
    }

    /**
     * `var_dump($h !== false)` after `@fopen` — hoisted compare feeds dead-temp arg, not @ return (#18185, #13694).
     */
    private function errorSuppressEndBlockCallArgHasTrailingComparisonProducer(
        Block $block,
        Op $cfgCallOp,
        int $argIndex
    ): bool {
        if (null === $block->orig || !property_exists($cfgCallOp, 'args') || !\is_array($cfgCallOp->args)) {
            return false;
        }
        $callArg = $cfgCallOp->args[$argIndex] ?? null;
        if (!$callArg instanceof Operand || $this->isEmbeddedCallLiteralArg($callArg)) {
            return false;
        }
        if (!$this->callArgIsDeadInlineTemporary($callArg)) {
            return false;
        }
        $children = $block->orig->children;
        $callIndex = array_search($cfgCallOp, $children, true);
        if (!\is_int($callIndex) || $callIndex < 1) {
            return false;
        }
        for ($i = $callIndex - 1; $i >= 0 && $callIndex - $i <= 8; --$i) {
            $prev = $children[$i] ?? null;
            if ($prev instanceof Op\Expr\Assign) {
                continue;
            }
            if (!$this->isComparisonInlineCallArgProducer($prev)) {
                break;
            }
            if (
                null !== $prev->result
                && (
                    $this->operandsReferToSameVariable($prev->result, $callArg)
                    || $this->callArgIsDeadInlineTemporary($callArg)
                )
            ) {
                return true;
            }
            break;
        }

        return false;
    }

    /**
     * `$v = @f(); g($v)` in END_SILENCE — reads must use the assign CV lvalue, not assign.result temp (#16262).
     */
    private function slotForPostErrorSuppressAssignNamedLocalCallArg(Operand $arg, Block $block): ?int
    {
        $endCfg = $block->orig;
        if (null === $endCfg || !$this->isErrorSuppressEndBlock($endCfg)) {
            return null;
        }
        $parentCfg = $endCfg->parents[0] ?? null;
        if (!$parentCfg instanceof ErrorSuppressBlock) {
            return null;
        }
        $primary = $this->findErrorSuppressPrimaryInnerExpr($parentCfg);
        if (null === $primary || !isset($primary->result)) {
            return null;
        }
        foreach ($endCfg->children as $child) {
            if (!$child instanceof Op\Expr\Assign) {
                continue;
            }
            if (!$this->operandsReferToSameVariable($child->expr, $primary->result)) {
                continue;
            }
            if (!$this->operandsReferToSameVariable($child->var, $arg)) {
                continue;
            }
            $namedDest = $block->slotForNamedAssignDest($child->var);
            if (null !== $namedDest) {
                return (int) $namedDest;
            }
            foreach ($block->opCodes as $op) {
                if (OpCode::TYPE_ASSIGN === $op->type && (int) $op->arg2 === $block->getVarSlot($child->var, false)) {
                    return (int) $op->arg2;
                }
            }
        }

        return null;
    }

    /**
     * Trailing hoisted Array_ before a post-@ call feeds this dead-temp arg (#16205).
     */
    private function errorSuppressEndBlockCallArgHasTrailingHoistedArrayProducer(
        Block $block,
        Op $cfgCallOp,
        int $argIndex
    ): bool {
        if (null === $block->orig || !property_exists($cfgCallOp, 'args') || !\is_array($cfgCallOp->args)) {
            return false;
        }
        $callArg = $cfgCallOp->args[$argIndex] ?? null;
        if (!$callArg instanceof Operand || $this->isEmbeddedCallLiteralArg($callArg)) {
            return false;
        }
        if (!$this->callArgIsDeadInlineTemporary($callArg)) {
            return false;
        }
        $arrayProducer = $this->inlineArrayProducerImmediatelyBeforeCfgCall($cfgCallOp, $block);
        if (!$arrayProducer instanceof Op\Expr\Array_) {
            return false;
        }
        if (
            null !== $arrayProducer->result
            && $this->operandsReferToSameVariable($arrayProducer->result, $callArg)
        ) {
            return true;
        }
        $nonEmbeddedArgIndices = [];
        foreach ($cfgCallOp->args as $i => $candidate) {
            if (!$this->isEmbeddedCallLiteralArg($candidate)) {
                $nonEmbeddedArgIndices[] = (int) $i;
            }
        }
        $producerOrdinal = array_search($argIndex, $nonEmbeddedArgIndices, true);
        if (false === $producerOrdinal) {
            return false;
        }

        return 0 === $producerOrdinal;
    }

    /**
     * Trailing ArrayDimFetch before a post-@ call feeds this dead-temp arg (#18005).
     */
    private function errorSuppressEndBlockCallArgHasTrailingArrayDimFetchProducer(
        Block $block,
        Op $cfgCallOp,
        int $argIndex
    ): bool {
        if (null === $block->orig || !property_exists($cfgCallOp, 'args') || !\is_array($cfgCallOp->args)) {
            return false;
        }
        $callArg = $cfgCallOp->args[$argIndex] ?? null;
        if (!$callArg instanceof Operand || $this->isEmbeddedCallLiteralArg($callArg)) {
            return false;
        }
        if (!$this->callArgIsDeadInlineTemporary($callArg)) {
            return false;
        }
        $children = $block->orig->children;
        $callIndex = array_search($cfgCallOp, $children, true);
        if (!\is_int($callIndex) || $callIndex < 1) {
            return false;
        }
        $producer = $children[$callIndex - 1] ?? null;
        if (!$producer instanceof Op\Expr\ArrayDimFetch) {
            return false;
        }
        if (
            null !== $producer->result
            && $this->operandsReferToSameVariable($producer->result, $callArg)
        ) {
            return true;
        }

        return $this->isFirstNonEmbeddedDeadInlineCallArg($cfgCallOp, $argIndex);
    }

    /**
     * Trailing hoisted true/false/null before a post-@ call feeds this dead-temp arg (#15916).
     *
     * When hoisted scalars only cover later args, arg #0 remains the suppress forward (#10302).
     */
    private function errorSuppressEndBlockCallArgHasTrailingHoistedScalarProducer(
        Block $block,
        Op $cfgCallOp,
        int $argIndex
    ): bool {
        if (null === $block->orig || !property_exists($cfgCallOp, 'args') || !\is_array($cfgCallOp->args)) {
            return false;
        }
        $callArg = $cfgCallOp->args[$argIndex] ?? null;
        if (!$callArg instanceof Operand || $this->isEmbeddedCallLiteralArg($callArg)) {
            return false;
        }
        if (!$this->callArgIsDeadInlineTemporary($callArg)) {
            return false;
        }
        $children = $block->orig->children;
        $callIndex = null;
        foreach ($children as $i => $child) {
            if ($child === $cfgCallOp) {
                $callIndex = $i;
                break;
            }
        }
        if (null === $callIndex) {
            return false;
        }
        $trailingConstFetches = [];
        for ($i = $callIndex - 1; $i >= 0 && $callIndex - $i <= 8; --$i) {
            $prev = $children[$i] ?? null;
            if ($prev instanceof Op\Expr\ConstFetch) {
                $name = $this->staticNameFromOperand($prev->name);
                if (null !== $name && \in_array(strtolower($name), ['true', 'false', 'null'], true)) {
                    array_unshift($trailingConstFetches, $prev);
                    continue;
                }
                break;
            }
            if ($prev instanceof Op\Expr\Assign) {
                continue;
            }
            if ($prev instanceof Op\Expr\BinaryOp\Concat) {
                return false;
            }
            break;
        }
        if ([] === $trailingConstFetches) {
            return false;
        }
        foreach ($trailingConstFetches as $fetch) {
            if (null !== $fetch->result && $this->operandsReferToSameVariable($fetch->result, $callArg)) {
                return true;
            }
        }
        $nonEmbeddedArgIndices = [];
        foreach ($cfgCallOp->args as $i => $candidate) {
            if (!$this->isEmbeddedCallLiteralArg($candidate)) {
                $nonEmbeddedArgIndices[] = (int) $i;
            }
        }
        $producerOrdinal = array_search($argIndex, $nonEmbeddedArgIndices, true);
        if (false === $producerOrdinal) {
            return false;
        }
        if (
            0 === $producerOrdinal
            && \count($nonEmbeddedArgIndices) > 1
            && \count($trailingConstFetches) < \count($nonEmbeddedArgIndices)
        ) {
            return false;
        }

        return isset($trailingConstFetches[$producerOrdinal]);
    }

    /**
     * True when an outer call in a post-@ block consumes the suppressed inner expression (#10336, #15916).
     *
     * Standalone `@mkdir(); stream_context_create(null|[])` must not wire hoisted literal to mkdir's return slot.
     */
    private function callInErrorSuppressEndBlockUsesInnerResultAsArg(Block $block, Op $cfgCallOp): bool
    {
        $endCfg = $block->orig;
        if (null === $endCfg || !$this->isErrorSuppressEndBlock($endCfg)) {
            return false;
        }
        $parentCfg = $endCfg->parents[0];
        if (!$parentCfg instanceof ErrorSuppressBlock) {
            return false;
        }
        $primary = $this->findErrorSuppressPrimaryInnerExpr($parentCfg);
        if (null === $primary || !isset($primary->result)) {
            return false;
        }
        if (!property_exists($cfgCallOp, 'args') || !\is_array($cfgCallOp->args)) {
            return false;
        }
        foreach ($primary->result->usages as $usage) {
            if ($usage === $cfgCallOp) {
                return true;
            }
        }
        foreach ($cfgCallOp->args as $arg) {
            if ($arg instanceof Operand && $this->operandsReferToSameVariable($arg, $primary->result)) {
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
     * Last-chance ARG_SEND slots for filter_input() hoisted ConstFetch / nested options (#15194).
     *
     * @param list<OpCode> $pendingSends
     */
    private function finalizeFilterInputCallArgSlot(
        Block $block,
        ?Op $cfgCallOp,
        int $argIndex,
        array &$pendingSends = []
    ): ?string {
        if (null === $cfgCallOp || null === $block->orig) {
            return null;
        }
        if ('filter_input' !== strtolower($this->resolveCfgFuncCallName($cfgCallOp) ?? '')) {
            return null;
        }
        if (3 === $argIndex) {
            $optionsArg = $cfgCallOp->args[3] ?? null;
            if (
                $optionsArg instanceof Operand
                && $this->callArgIsDeadInlineTemporary($optionsArg)
                && $this->callArgOperandExpectsArrayProducer($optionsArg)
            ) {
                return $this->resolveOutermostInitArraySlotBeforePendingFuncCall($block, $pendingSends);
            }

            return null;
        }
        if (0 !== $argIndex && 2 !== $argIndex) {
            return null;
        }
        $hoisted = [];
        $callIndex = $this->cfgCallOpIndexInChildren($block->orig->children, $cfgCallOp, $block->orig);
        if (null === $callIndex) {
            return null;
        }
        for ($i = $callIndex - 1; $i >= 0; --$i) {
            $child = $block->orig->children[$i];
            if ($child instanceof Op\Expr\ConstFetch) {
                array_unshift($hoisted, $child);
                continue;
            }
            if ($child instanceof Op\Expr\Array_) {
                continue;
            }
            if ($child instanceof Op\Expr\Assign) {
                break;
            }
            break;
        }
        $constFetches = array_values(array_filter(
            $hoisted,
            static fn (Op\Expr $producer): bool => $producer instanceof Op\Expr\ConstFetch
        ));
        $target = match ($argIndex) {
            0 => $constFetches[0] ?? null,
            2 => $constFetches[1] ?? ($constFetches[0] ?? null),
            default => null,
        };
        if (!$target instanceof Op\Expr\ConstFetch) {
            return null;
        }
        $folded = $this->tryFoldGlobalConstFetch($target);
        if (null !== $folded) {
            return (string) $block->registerConstant(new Operand\Temporary(), $folded);
        }
        $slot = $block->slotForOperand($target->result);
        if (null !== $slot) {
            return (string) $slot;
        }
        foreach ($this->compileExpr($target, $block) as $op) {
            $pendingSends[] = $op;
        }
        $slot = $block->slotForOperand($target->result);

        return null !== $slot ? (string) $slot : null;
    }

    /**
     * Last keyed INIT_ARRAY slot for nested inline array call args (#11485).
     *
     * @param list<OpCode> $pendingSends
     */
    private function resolveOutermostInitArraySlotBeforePendingFuncCall(
        Block $block,
        array $pendingSends = []
    ): ?string {
        $outerSlot = null;
        foreach ($block->opCodes as $op) {
            if (OpCode::TYPE_INIT_ARRAY === $op->type && null !== $op->arg1 && null !== $op->arg3) {
                $outerSlot = (string) $op->arg1;
            }
        }
        if (null !== $outerSlot) {
            return $outerSlot;
        }
        $scanOps = array_merge($block->opCodes, $pendingSends);
        for ($i = \count($scanOps) - 1; $i >= 0; --$i) {
            $op = $scanOps[$i];
            if (OpCode::TYPE_INIT_ARRAY === $op->type && null !== $op->arg1) {
                return (string) $op->arg1;
            }
        }

        return null;
    }

    /**
     * Nth FUNCCALL_EXEC_RETURN slot including pending call-arg producer ops (#16097).
     *
     * @param list<OpCode> $pendingOps
     */
    private function slotForFuncCallExecReturnOrdinal(
        Block $block,
        int $producerOrdinal,
        array $pendingOps = []
    ): ?string {
        if ($producerOrdinal < 0) {
            return null;
        }
        $execReturnSlots = $block->funccallExecReturnSlots();
        if ([] !== $pendingOps) {
            foreach ($pendingOps as $op) {
                if (OpCode::TYPE_FUNCCALL_EXEC_RETURN === $op->type && null !== $op->arg1) {
                    $execReturnSlots[] = (int) $op->arg1;
                }
            }
        }

        return isset($execReturnSlots[$producerOrdinal])
            ? (string) $execReturnSlots[$producerOrdinal]
            : null;
    }

    /**
     * Last-chance ARG_SEND slot for array_merge*(array_keys(...), [...]) sibling producers (#12450, #13704, #17781).
     *
     * @param list<OpCode> $sends
     */
    private function finalizeArrayMergeFamilyCallArgSlot(
        Block $block,
        Op $cfgCallOp,
        int $argIndex,
        array &$sends
    ): ?string {
        $callee = strtolower($this->resolveCfgFuncCallName($cfgCallOp) ?? '');
        if (!\in_array(
            $callee,
            ['array_merge', 'array_merge_recursive', 'array_replace', 'array_replace_recursive'],
            true
        )) {
            return null;
        }
        if (\count($cfgCallOp->args ?? []) < 2 || null === $block->orig) {
            return null;
        }
        $producers = $this->arrayMergeFamilyInlineProducersForCfgCall(
            $block->orig->children,
            $cfgCallOp
        );
        $matched = $this->matchArrayMergeFamilyFullInlineCallArgProducer(
            $producers,
            $argIndex,
            \count($cfgCallOp->args ?? []),
            $cfgCallOp->args ?? []
        );
        if (null === $matched) {
            $matched = $this->matchArrayMergeFuncCallAndArrayInlineProducers($producers, $argIndex);
        }
        if (!$matched instanceof Op\Expr) {
            return null;
        }
        if ($matched instanceof Op\Expr\FuncCall || $matched instanceof Op\Expr\NsFuncCall) {
            $funcOrdinal = 0;
            foreach ($producers as $producer) {
                if ($producer === $matched) {
                    break;
                }
                if ($producer instanceof Op\Expr\FuncCall || $producer instanceof Op\Expr\NsFuncCall) {
                    ++$funcOrdinal;
                }
            }
            $execSlot = $this->slotForFuncCallExecReturnOrdinal($block, $funcOrdinal, $sends);
            if (null !== $execSlot) {
                return $execSlot;
            }
            if (null === $block->slotForOperand($matched->result)) {
                foreach ($this->compileExpr($matched, $block) as $op) {
                    $sends[] = $op;
                }
            }
            $execSlot = $this->slotForFuncCallExecReturnOrdinal($block, $funcOrdinal, $sends);
            if (null !== $execSlot) {
                return $execSlot;
            }
            $slot = $block->slotForOperand($matched->result);
            if (null !== $slot) {
                return (string) $slot;
            }

            return null;
        }
        if (null === $block->slotForOperand($matched->result)) {
            if ($matched instanceof Op\Expr\Array_) {
                foreach ($this->compileArrayLiteral($matched, $block) as $op) {
                    $sends[] = $op;
                }
            } else {
                foreach ($this->compileExpr($matched, $block) as $op) {
                    $sends[] = $op;
                }
            }
        }
        $slot = $block->slotForOperand($matched->result);
        if (null !== $slot) {
            return (string) $slot;
        }

        return null;
    }

    /**
     * Last-chance ARG_SEND slot for array_combine() nested array_keys() + trailing Array_ (#15558, #15857).
     *
     * @param list<OpCode> $sends
     */
    private function finalizeArrayCombineCallArgSlot(
        Block $block,
        Op $cfgCallOp,
        int $argIndex,
        array &$sends
    ): ?string {
        if (2 !== \count($cfgCallOp->args ?? []) || null === $block->orig) {
            return null;
        }
        $producers = $this->precedingInlineCallArgProducersBeforeCfgOp(
            $block->orig->children,
            $cfgCallOp
        );
        $matched = $this->matchArrayCombineInlineProducers($producers, $argIndex);
        if (!$matched instanceof Op\Expr) {
            return null;
        }
        if ($matched instanceof Op\Expr\FuncCall || $matched instanceof Op\Expr\NsFuncCall) {
            $funcOrdinal = 0;
            foreach ($producers as $producer) {
                if ($producer === $matched) {
                    break;
                }
                if ($producer instanceof Op\Expr\FuncCall || $producer instanceof Op\Expr\NsFuncCall) {
                    ++$funcOrdinal;
                }
            }
            $execSlot = $this->slotForFuncCallExecReturnOrdinal($block, $funcOrdinal, $sends);
            if (null !== $execSlot) {
                return $execSlot;
            }
            if (null === $block->slotForOperand($matched->result)) {
                foreach ($this->compileExpr($matched, $block) as $op) {
                    $sends[] = $op;
                }
            }
            $execSlot = $this->slotForFuncCallExecReturnOrdinal($block, $funcOrdinal, $sends);
            if (null !== $execSlot) {
                return $execSlot;
            }
            $slot = $block->slotForOperand($matched->result);
            if (null !== $slot) {
                return (string) $slot;
            }

            return null;
        }
        if (null === $block->slotForOperand($matched->result)) {
            foreach ($this->compileExpr($matched, $block) as $op) {
                $sends[] = $op;
            }
        }
        if ($matched instanceof Op\Expr\Array_) {
            $ordinalSlot = $this->slotForArrayCombineSiblingInitArray(
                $block,
                $producers,
                $argIndex,
                $sends
            );
            if (null !== $ordinalSlot) {
                return $ordinalSlot;
            }
            $byArgSlot = $this->slotForArrayCombineInitArrayByArgIndex($block, $cfgCallOp, $argIndex, $sends);
            if (null !== $byArgSlot) {
                return $byArgSlot;
            }
        }
        $slot = $block->slotForOperand($matched->result);
        if (null !== $slot) {
            return (string) $slot;
        }

        return null;
    }

    /**
     * array_combine() with inline array literals — map arg index to INIT_ARRAY ordinal (#16080).
     *
     * @param list<OpCode> $pendingSends
     */
    private function slotForArrayCombineInitArrayByArgIndex(
        Block $block,
        Op $cfgCallOp,
        int $argIndex,
        array $pendingSends
    ): ?string {
        if (2 !== \count($cfgCallOp->args ?? [])) {
            return null;
        }
        foreach ($cfgCallOp->args as $callArg) {
            if (
                null === $callArg
                || !$this->callArgIsDeadInlineTemporary($callArg)
                || !$this->callArgOperandExpectsArrayProducer($callArg)
            ) {
                return null;
            }
        }
        $initSlots = $this->initArraySlotsForCurrentFunccall($block, $pendingSends);
        if (\count($initSlots) <= $argIndex) {
            return null;
        }

        return $initSlots[$argIndex];
    }

    /**
     * array_combine([...], [...]) — map arg index to sibling INIT_ARRAY slot (#16080, #10214).
     *
     * @param list<OpCode> $pendingSends
     */
    private function slotForArrayCombineSiblingInitArray(
        Block $block,
        array $producers,
        int $argIndex,
        array $pendingSends
    ): ?string {
        $matched = $this->matchArrayCombineInlineProducers($producers, $argIndex);
        if (!$matched instanceof Op\Expr\Array_) {
            return null;
        }
        $arrayProducers = array_values(array_filter(
            $producers,
            static fn (Op\Expr $producer): bool => $producer instanceof Op\Expr\Array_
        ));
        if (2 !== \count($arrayProducers)) {
            return null;
        }
        $ordinal = array_search($matched, $arrayProducers, true);
        if (false === $ordinal) {
            return null;
        }
        $initSlots = $this->initArraySlotsForCurrentFunccall($block, $pendingSends);

        return $initSlots[$ordinal] ?? null;
    }

    /**
     * Last-chance ARG_SEND slot for array_column() inline nested haystack + hoisted null (#15914).
     *
     * @param list<OpCode> $sends
     */
    private function finalizeArrayColumnCallArgSlot(
        Block $block,
        Op $cfgCallOp,
        int $argIndex,
        array &$sends
    ): ?string {
        $producers = $this->precedingInlineCallArgProducersBeforeCfgOp(
            $block->orig->children,
            $cfgCallOp
        );
        $callArgs = $cfgCallOp->args ?? [];
        $matched = $this->matchArrayColumnNestedHaystackTrailingProducers(
            $producers,
            $callArgs,
            $argIndex,
            $cfgCallOp
        );
        // array_column([['n'=>'a'], …], 'n') — nested haystack without trailing null (#13703, #15960).
        if (!$matched instanceof Op\Expr && 0 === $argIndex) {
            $matched = $this->matchFoldedFirstNestedSiblingArrayLiteralCallArgProducer(
                $producers,
                $argIndex,
                \count($callArgs),
                $callArgs
            );
            if (null === $matched) {
                $matched = $this->matchSoleNestedInlineArrayHaystackProducer(
                    $producers,
                    $callArgs,
                    $argIndex
                );
            }
            if (null === $matched) {
                $matched = $this->inlineArrayProducerImmediatelyBeforeCfgCall($cfgCallOp, $block);
            }
        }
        if (!$matched instanceof Op\Expr) {
            return null;
        }
        if (null === $block->slotForOperand($matched->result)) {
            foreach ($this->compileExpr($matched, $block) as $op) {
                $sends[] = $op;
            }
        }
        $slot = $block->slotForOperand($matched->result);

        return null !== $slot ? (string) $slot : null;
    }

    /**
     * array_column([[..]], null, 'x') / array_column([[..]], 'name', null) — nested haystack + null (#15914).
     *
     * @param list<Op\Expr> $producers
     * @param list<Operand> $callArgs
     */
    private function matchArrayColumnNestedHaystackTrailingProducers(
        array $producers,
        array $callArgs,
        int $argIndex,
        ?Op $cfgCallOp
    ): ?Op\Expr {
        $nestedTrailing = $this->splitNestedArrayLiteralChainWithTrailingProducers($producers);
        if (null === $nestedTrailing) {
            return null;
        }
        [$arrayChain, $trailing] = $nestedTrailing;
        if (0 === $argIndex) {
            return $arrayChain[\count($arrayChain) - 1];
        }
        if ($this->isEmbeddedCallLiteralArg($callArgs[$argIndex] ?? null)) {
            return null;
        }
        $nullFetch = null;
        foreach ($trailing as $producer) {
            if (!$producer instanceof Op\Expr\ConstFetch) {
                continue;
            }
            $name = $this->staticNameFromOperand($producer->name);
            if (null !== $name && 'null' === strtolower($name)) {
                $nullFetch = $producer;
                break;
            }
        }
        if (null === $nullFetch) {
            return null;
        }
        $nullTarget = $this->arrayColumnNullPreludeArgIndex($cfgCallOp);
        if (null !== $nullTarget && $argIndex === $nullTarget) {
            return $nullFetch;
        }
        if ($argIndex === \count($callArgs) - 1) {
            return $nullFetch;
        }

        return null;
    }

    /**
     * Hoisted null ConstFetch before array_column() maps to column_key or index_key (#4306, #9305, #10535).
     */
    private function arrayColumnNullPreludeArgIndex(?Op $cfgCallOp): ?int
    {
        if (null === $cfgCallOp || !\is_array($cfgCallOp->args ?? null)) {
            return null;
        }
        $args = $cfgCallOp->args;
        $argc = \count($args);
        if (2 === $argc) {
            return 1;
        }
        if (3 !== $argc) {
            return null;
        }
        $columnEmbedded = $this->isEmbeddedCallLiteralArg($args[1] ?? null);
        $indexEmbedded = $this->isEmbeddedCallLiteralArg($args[2] ?? null);
        if ($columnEmbedded && !$indexEmbedded) {
            return 2;
        }
        if (!$columnEmbedded && $indexEmbedded) {
            return 1;
        }

        return null;
    }

    /**
     * Named locals after ?: echo must not be remapped to merge-phi producer temps (#9487).
     */
    private function namedLocalCallArgSlotIfBound(
        Operand $arg,
        Block $block,
        ?Op $cfgCallOp = null,
        ?int $argIndex = null
    ): ?string {
        $probe = $arg;
        if (null !== $cfgCallOp && is_array($cfgCallOp->args ?? null) && isset($cfgCallOp->args[(int) $argIndex])) {
            $probe = $cfgCallOp->args[(int) $argIndex];
        }
        $name = Block::resolveVariableName($probe);
        if (null === $name || '' === $name) {
            $root = Block::cfgVarRoot($probe);
            if ($root instanceof CfgVariable) {
                $name = Block::resolveVariableName($root);
            }
        }
        if (null === $name || '' === $name) {
            $assignedNamed = $this->slotForNamedLocalFromAssignVarOperand($probe, $block);
            if (null !== $assignedNamed) {
                return (string) $assignedNamed;
            }
            return null;
        }
        $namedSlot = $block->slotIndexForVariableName($name);
        if (null === $namedSlot || !$block->isNamedVariableSlot((int) $namedSlot)) {
            return null;
        }

        return (string) $namedSlot;
    }

    /**
     * php-cfg may wire a later named local read to a preceding call's dead result temp (#9074).
     */
    private function preferNamedLocalCallArgSlot(
        Operand $arg,
        Block $block,
        ?string $valueSlot,
        ?string $calleeName = null
    ): ?string
    {
        if (null === $valueSlot) {
            return null;
        }
        $assignedNamed = $this->slotForNamedLocalFromAssignVarOperand($arg, $block);
        if (null !== $assignedNamed) {
            return (string) $assignedNamed;
        }
        if (
            $this->callArgOperandIsClosureValue($arg, $block)
            && !$this->isNamedVariableOperand($arg)
            && null === $this->namedLocalCallArgSlotIfBound($arg, $block)
        ) {
            return $valueSlot;
        }
        $name = Block::resolveVariableName($arg);
        if (null === $name || '' === $name) {
            $root = Block::cfgVarRoot($arg);
            if ($root instanceof CfgVariable) {
                $name = Block::resolveVariableName($root);
            }
        }
        if (null === $name || '' === $name) {
            return $valueSlot;
        }
        if (null !== $calleeName && $name === $calleeName) {
            return $valueSlot;
        }
        // php-cfg dead temps for hoisted scalar ConstFetch / Cast preludes (#9140, #10143).
        if (\in_array(strtolower($name), ['true', 'false', 'null', 'nan', 'inf'], true)) {
            return $valueSlot;
        }
        $namedSlot = $block->slotIndexForVariableName($name);
        if (null === $namedSlot) {
            return $valueSlot;
        }
        if (!$block->isNamedVariableSlot((int) $namedSlot)) {
            return $valueSlot;
        }
        if ((int) $namedSlot === (int) $valueSlot) {
            return $valueSlot;
        }
        // Inline producer temp must not replace an unbound named local (#9973, #9924).
        // Function-local statics bind via TYPE_DECLARE_FUNCTION_STATIC, not ASSIGN (#28038).
        if (
            !$this->blockHasAssignToSlot($block, (int) $namedSlot)
            && !$this->blockHasAssignToSlotInParentBlocks($block, (int) $namedSlot)
            && !$this->blockHasFunctionStaticDeclareToSlot($block, (int) $namedSlot)
        ) {
            return $valueSlot;
        }

        return $namedSlot;
    }

    /**
     * `$path = __DIR__ . '/x'; f($path)` — bind the named local when Concat is inlined (#9973).
     *
     * @return list<OpCode>
     */
    private function tryEmitAdjacentAssignForInlineCallArg(
        Operand $arg,
        ?string $valueSlot,
        Block $block,
        ?Op $cfgCallOp,
        int $argIndex
    ): array {
        if (null === $valueSlot || null === $cfgCallOp || null === $block->orig) {
            return [];
        }
        if (!property_exists($cfgCallOp, 'args') || !is_array($cfgCallOp->args)) {
            return [];
        }
        $callArg = $cfgCallOp->args[$argIndex] ?? null;
        if (null === $callArg || !$this->operandsReferToSameVariable($arg, $callArg)) {
            return [];
        }
        $children = $block->orig->children;
        $prev = null;
        foreach ($children as $i => $child) {
            if (
                !($child instanceof Op\Expr\FuncCall || $child instanceof Op\Expr\NsFuncCall)
                || !property_exists($child, 'args')
                || !is_array($child->args)
            ) {
                continue;
            }
            if ($child !== $cfgCallOp) {
                $sameCall = false;
                if (
                    property_exists($cfgCallOp, 'name')
                    && property_exists($child, 'name')
                    && $this->operandsReferToSameVariable($child->name, $cfgCallOp->name)
                ) {
                    $sameCall = true;
                }
                if (!$sameCall) {
                    continue;
                }
            }
            $siteArg = $child->args[$argIndex] ?? null;
            if (null === $siteArg || !$this->operandsReferToSameVariable($siteArg, $callArg)) {
                continue;
            }
            $prev = $children[$i - 1] ?? null;
            break;
        }
        if (!$prev instanceof Op\Expr\Assign || !$this->operandsReferToSameVariable($prev->var, $callArg)) {
            return [];
        }
        $destSlot = $block->getVarSlot($prev->var, false);
        // List destruct assigns compile in the parent block; skip merge-block phi bind (#10807).
        if (!$this->blockHasAssignToSlot($block, (int) $destSlot)) {
            return [];
        }

        $rhsSlot = (int) $valueSlot;
        if ($rhsSlot === (int) $destSlot) {
            // `$path = 'a' . 'b'` — CONCAT already wrote into destSlot; self-sync would clobber (#16281).
            if ($this->assignAdjacentToBinaryExprProducer($block, $prev)) {
                return [];
            }
            $exprSlot = $block->slotForOperand($prev->expr);
            if (null !== $exprSlot && (int) $exprSlot !== (int) $destSlot) {
                $rhsSlot = (int) $exprSlot;
            } else {
                // Reassigned locals (e.g. $f = fopen after fclose($f)) — use latest ASSIGN RHS (#16271).
                foreach ($block->opCodes as $op) {
                    if (OpCode::TYPE_ASSIGN === $op->type && (int) $op->arg2 === (int) $destSlot) {
                        $rhsSlot = (int) $op->arg3;
                    }
                }
            }
            if ($rhsSlot === (int) $destSlot) {
                return [];
            }
        }

        // `$a = ['k'=>1]; array_values($a)` — an identical dest←rhs ASSIGN already exists.
        // Emitting a second free()+store delrefs the HT and empties string-key walks under
        // thin AOT (#27545 / re-#27212). Peer: skip when CONCAT already wrote dest (#16281).
        foreach ($block->opCodes as $op) {
            if (
                OpCode::TYPE_ASSIGN === $op->type
                && (int) $op->arg2 === (int) $destSlot
                && (int) $op->arg3 === (int) $rhsSlot
            ) {
                return [];
            }
        }

        return [new OpCode(
            OpCode::TYPE_ASSIGN,
            $this->compileOperand($prev->result, $block, false),
            $destSlot,
            $rhsSlot
        )];
    }

    /** `$x = 'a' . 'b'; f($x)` — CFG places BinaryOp immediately before Assign (#16281). */
    private function assignAdjacentToBinaryExprProducer(Block $block, Op\Expr\Assign $assign): bool
    {
        if (null === $block->orig) {
            return false;
        }
        $assignIndex = null;
        foreach ($block->orig->children as $i => $child) {
            if ($child === $assign) {
                $assignIndex = $i;
                break;
            }
        }
        if (null === $assignIndex || $assignIndex < 1) {
            return false;
        }

        $prev = $block->orig->children[$assignIndex - 1];

        return $prev instanceof Op\Expr\BinaryOp || $prev instanceof Op\Expr\ConcatList;
    }

    /**
     * `$ini = "flag = $v"; parse_ini_string($ini)` inside loops — dest must not alias ConcatList temp (#18442).
     *
     * @param-out int $destSlot
     * @param-out int $rhsSlot
     */
    private function reconcileEncapsedConcatListAssignSlots(
        Op\Expr\Assign $assign,
        Block $block,
        int &$destSlot,
        int &$rhsSlot
    ): void {
        $concat = $this->concatListProducerFromAssignExpr($assign->expr);
        if (null === $concat || null === $concat->result) {
            return;
        }
        $producerSlot = $block->slotForOperand($concat->result);
        if (null === $producerSlot) {
            return;
        }
        $producerSlot = (int) $producerSlot;
        $rhsSlot = $producerSlot;
        if ((int) $destSlot === $producerSlot) {
            $name = Block::resolveVariableName($assign->var);
            $cvSlot = null !== $name ? $block->slotIndexForVariableName($name) : null;
            if (null === $cvSlot || (int) $cvSlot === $producerSlot) {
                $cvSlot = $block->forceFreshVarSlot($assign->var);
            }
            $destSlot = (int) $cvSlot;
        }
    }

    /** @return ?Op\Expr\ConcatList */
    private function concatListProducerFromAssignExpr(Operand $expr): ?Op\Expr\ConcatList
    {
        $unwrap = $expr;
        while ($unwrap instanceof Operand\Temporary && null !== $unwrap->original) {
            $unwrap = $unwrap->original;
        }
        if ($unwrap instanceof Op\Expr\ConcatList) {
            return $unwrap;
        }

        return $this->unwrapConcatListExpr($expr);
    }

    private function blockHasAssignToSlot(Block $block, int $destSlot): bool
    {
        foreach ($block->opCodes as $op) {
            if (OpCode::TYPE_ASSIGN === $op->type && (int) $op->arg2 === $destSlot) {
                return true;
            }
        }

        return false;
    }

    /** Function-local `static $x` binds the CV via DECLARE_FUNCTION_STATIC (#28038). */
    private function blockHasFunctionStaticDeclareToSlot(Block $block, int $destSlot): bool
    {
        foreach ($block->opCodes as $op) {
            if (OpCode::TYPE_DECLARE_FUNCTION_STATIC === $op->type && (int) $op->arg1 === $destSlot) {
                return true;
            }
        }

        return false;
    }

    /** Parent CFG blocks (list destruct merge) may hold the assign lowering (#10807). */
    private function blockHasAssignToSlotInParentBlocks(Block $block, int $destSlot, array $visited = []): bool
    {
        foreach ($block->parents as $parent) {
            if (!$parent instanceof Block) {
                continue;
            }
            $id = spl_object_id($parent);
            if (isset($visited[$id])) {
                continue;
            }
            $visited[$id] = true;
            if ($this->blockHasAssignToSlot($parent, $destSlot)) {
                return true;
            }
            if ($this->blockHasAssignToSlotInParentBlocks($parent, $destSlot, $visited)) {
                return true;
            }
        }

        return false;
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
     * Resolve enum `case` fetches feeding array literals — emit runtime CLASS_CONST_FETCH (#5636).
     *
     * @return list<OpCode>
     */
    protected function compileRuntimeEnumCaseFetchOpsForArrayElement(
        Operand $valueOperand,
        Block $block,
        Op\Expr\Array_ $arrayExpr,
        int $elementIndex
    ): array {
        $fetch = $this->findEnumCaseClassConstFetchForArrayElement(
            $valueOperand,
            $block,
            $arrayExpr,
            $elementIndex
        );
        if (null === $fetch) {
            return [];
        }
        $valueSlot = $this->compileOperand($valueOperand, $block, true);
        $op = new OpCode(
            OpCode::TYPE_CLASS_CONST_FETCH,
            $valueSlot,
            $this->compileClassNameOperand($fetch->class, $block),
            $this->compileOperand($fetch->name, $block, true)
        );
        $this->assignSourceMetadata($op, $fetch);

        return [$op];
    }

    private function findEnumCaseClassConstFetchForArrayElement(
        Operand $valueOperand,
        Block $block,
        Op\Expr\Array_ $arrayExpr,
        int $elementIndex,
        bool $forKeyOperand = false
    ): ?Op\Expr\ClassConstFetch {
        $root = $this->unwrapOperandChain($valueOperand);
        if ($root instanceof Op\Expr\ClassConstFetch
            && $this->isCompileTimeEnumCaseClassConstFetch($root, $block)
        ) {
            return $root;
        }
        if (null !== $block->orig) {
            // The Array_ being compiled is the only candidate that matters; walking every
            // prior Array_ child re-did operandsReferToSameVariable O(n) times per element
            // and made nested `[1,2,3]` call stmts O(n²) (#36387).
            foreach ([$arrayExpr] as $child) {
                if (!$child instanceof Op\Expr\Array_) {
                    continue;
                }
                $fetches = $this->precedingClassConstFetchesBeforeCfgOp($block->orig->children, $child);
                $fetches = $this->dropCallArgEnumFetchesBeforeInlineArray($fetches, $child, $block);
                $fetch = $fetches[$elementIndex] ?? null;
                if ($fetch instanceof Op\Expr\ClassConstFetch
                    && $this->isCompileTimeEnumCaseClassConstFetch($fetch, $block)
                ) {
                    if ($this->operandsReferToSameVariable($fetch->result, $valueOperand)) {
                        return $fetch;
                    }
                    // php-cfg may drop the fetch result and leave a literal case-name element
                    // (e.g. `E::A; [ "A", ... ]`) — still treat as enum case fetch (#9039).
                    if ($valueOperand instanceof Operand\Literal && \is_string($valueOperand->value)) {
                        $constName = $this->staticNameFromOperand($fetch->name);
                        if (null !== $constName && $constName === $valueOperand->value) {
                            return $fetch;
                        }
                    }
                    // php-cfg may drop the fetch result and leave a literal backing scalar key
                    // (e.g. `E::A; [ E::A => 1 ]` lowered to key Literal(1)) — recover the enum case fetch (#9024).
                    // Scalar array values must not alias enum backing (#8930, #16316).
                    if ($forKeyOperand
                        && $valueOperand instanceof Operand\Literal
                        && (\is_int($valueOperand->value) || \is_string($valueOperand->value))
                    ) {
                        // Enum-as-key recovery requires key/value literals to match (both the backing scalar).
                        // `[1 => E::B]` / `[1 => 2]` must keep the numeric key (#8930).
                        $elementValue = $arrayExpr->values[$elementIndex] ?? null;
                        if (!$elementValue instanceof Operand\Literal
                            || $elementValue->value !== $valueOperand->value
                        ) {
                            break;
                        }
                        $className = $this->staticNameFromOperand($fetch->class);
                        $constName = $this->staticNameFromOperand($fetch->name);
                        if (null !== $className && null !== $constName) {
                            $lcClass = $this->resolveDefaultClassConstScope($className, $block);
                            $lcConst = ClassConstName::key($constName);
                            $stored = null !== $lcClass
                                ? ($this->compileTimeClassConsts[$lcClass][$lcConst] ?? null)
                                : null;
                            if (null !== $stored) {
                                $stored = $stored->resolveIndirect();
                                $backing = null;
                                if (Variable::TYPE_ENUM_CASE === $stored->type) {
                                    $backing = $stored->toEnumCase()->backingValue->resolveIndirect();
                                } elseif (Variable::TYPE_OBJECT === $stored->type && EnumCaseSupport::isEnumCase($stored->toObject())) {
                                    $backing = $stored->toObject()->enumCaseValue?->resolveIndirect();
                                }
                                if (null !== $backing) {
                                    if (\is_int($valueOperand->value) && Variable::TYPE_INTEGER === $backing->type
                                        && $backing->toInt() === $valueOperand->value
                                    ) {
                                        return $fetch;
                                    }
                                    if (\is_string($valueOperand->value) && Variable::TYPE_STRING === $backing->type
                                        && $backing->toString() === $valueOperand->value
                                    ) {
                                        return $fetch;
                                    }
                                }
                            }
                        }
                    }
                }
                // Hoisted enum fetches may be fewer than mixed scalar/case elements (#16316).
                foreach ($fetches as $fetch) {
                    if (!$fetch instanceof Op\Expr\ClassConstFetch
                        || !$this->isCompileTimeEnumCaseClassConstFetch($fetch, $block)
                    ) {
                        continue;
                    }
                    if ($this->operandsReferToSameVariable($fetch->result, $valueOperand)) {
                        return $fetch;
                    }
                }

                break;
            }
        }

        return null;
    }

    /**
     * in_array(E::A, [1, 2], true) — hoisted needle fetch must not poison int haystack elements (#9888).
     *
     * @param list<Op\Expr\ClassConstFetch> $fetches
     *
     * @return list<Op\Expr\ClassConstFetch>
     */
    private function dropCallArgEnumFetchesBeforeInlineArray(
        array $fetches,
        Op\Expr\Array_ $arrayExpr,
        Block $block
    ): array {
        if ([] === $fetches || null === $block->orig) {
            return $fetches;
        }
        $children = $block->orig->children;
        $arrayIndex = null;
        foreach ($children as $i => $child) {
            if ($child === $arrayExpr) {
                $arrayIndex = $i;
                break;
            }
        }
        if (null === $arrayIndex || $arrayIndex <= 0) {
            return $fetches;
        }
        $preArray = $children[$arrayIndex - 1] ?? null;
        if (!$preArray instanceof Op\Expr\ClassConstFetch) {
            return $fetches;
        }
        for ($i = $arrayIndex + 1, $n = \count($children); $i < $n; ++$i) {
            $next = $children[$i];
            if ($next instanceof Op\Expr\ConstFetch) {
                continue;
            }
            if (!($next instanceof Op\Expr\FuncCall || $next instanceof Op\Expr\NsFuncCall)) {
                return $fetches;
            }
            $callArg0 = $next->args[0] ?? null;
            if ($preArray === ($fetches[0] ?? null)
                && $this->callArgUsesHoistedEnumPreludeSlot($callArg0)
            ) {
                return \array_values(\array_filter(
                    $fetches,
                    static fn (Op\Expr $fetch): bool => $fetch !== $preArray
                ));
            }

            return $fetches;
        }

        return $fetches;
    }

    private function isCompileTimeEnumCaseClassConstFetch(
        Op\Expr\ClassConstFetch $fetch,
        Block $block
    ): bool {
        $className = $this->staticNameFromOperand($fetch->class);
        $constName = $this->staticNameFromOperand($fetch->name);
        if (null === $className || null === $constName) {
            return false;
        }
        $lcClass = $this->resolveDefaultClassConstScope($className, $block);
        if (null === $lcClass) {
            return false;
        }

        return $this->isCompileTimeEnumCaseConstantMember($lcClass, ClassConstName::key($constName));
    }

    /**
     * Fold array element operands, including php-cfg dead ClassConstFetch preludes (#5636).
     */
    protected function tryFoldArrayElementCompileTimeValue(
        Operand $valueOperand,
        Block $block,
        Op\Expr\Array_ $arrayExpr,
        int $elementIndex
    ): ?int {
        $fetch = $this->findEnumCaseClassConstFetchForArrayElement(
            $valueOperand,
            $block,
            $arrayExpr,
            $elementIndex
        );
        if (null !== $fetch) {
            $vm = $this->tryFoldClassConstFetchDefault($fetch, $block, true);
            if (null !== $vm) {
                return $block->registerConstant($valueOperand, $vm);
            }
        }

        return $this->tryFoldCallArgCompileTimeValue($valueOperand, $block);
    }

    /**
     * @return list<OpCode>
     */
    protected function compileArrayLiteral(Op\Expr\Array_ $expr, Block $block): array
    {
        $result = $this->compileOperand($expr->result, $block, false);
        if (empty($expr->values)) {
            return [new OpCode(OpCode::TYPE_INIT_ARRAY, $result)];
        }

        $return = [];
        $started = false;
        $unpackFlags = property_exists($expr, 'unpack') ? $expr->unpack : [];
        $byRefFlags = property_exists($expr, 'byRef') ? $expr->byRef : [];
        for ($i = 0, $n = count($expr->values); $i < $n; ++$i) {
            if (!empty($unpackFlags[$i])) {
                // Zend compile-time IS_CONST unpack of non-array → uncatchable Fatal
                // (zend_compile.c); runtime variables throw catchable Error (#27952).
                $spreadOperand = $expr->values[$i];
                if ($this->isCompileTimeNonTraversableArrayUnpackOperand($spreadOperand)) {
                    $sourceFile = $expr->getFile() ?: ($this->debugLastPhaseInputFile ?? 'unknown');
                    throw new CompileFatal(
                        $sourceFile,
                        max(1, $expr->getLine()),
                        $this->arrayUnpackNonTraversableCompileMessage($spreadOperand)
                    );
                }
                if (!$started) {
                    $return[] = new OpCode(OpCode::TYPE_INIT_ARRAY, $result);
                    $started = true;
                }
                $return[] = new OpCode(
                    OpCode::TYPE_ARRAY_SPREAD,
                    $result,
                    $this->compileOperand($spreadOperand, $block, true),
                    max(0, $expr->getLine())
                );
                continue;
            }

            if (!empty($byRefFlags[$i])) {
                // Reference cells must bind the live lvalue; deferred rematerialization snapshots
                // copy hooked property reads and break set-hook ref writes (#6426, #17353).
                $prefetchOps = $this->compileRuntimeEnumCaseFetchOpsForArrayElement(
                    $expr->values[$i],
                    $block,
                    $expr,
                    $i,
                    !empty($byRefFlags[$i]),
                );
                if ([] !== $prefetchOps) {
                    $valueSlot = $prefetchOps[0]->arg1;
                    $return = array_merge($return, $prefetchOps);
                    $propFetch = null;
                } else {
                    $valueExpr = $expr->values[$i];
                    $propFetch = $this->resolvePropertyFetchForArrayLiteralRef($valueExpr, $block);
                    if (null !== $propFetch) {
                        $valueTemp = new Operand\Temporary();
                        $valueSlot = $block->getVarSlot($valueTemp, false);
                    } else {
                        $valueSlot = $this->compileOperand($valueExpr, $block, true);
                    }
                }
            } else {
                $prefetchOps = $this->compileRuntimeEnumCaseFetchOpsForArrayElement(
                    $expr->values[$i],
                    $block,
                    $expr,
                    $i
                );
                if ([] !== $prefetchOps) {
                    $valueSlot = $prefetchOps[0]->arg1;
                    $return = array_merge($return, $prefetchOps);
                } else {
                    [$rematerializeOps, $valueSlot] = $this->compileDeferredArrayLiteralElementValue(
                        $expr->values[$i],
                        $block,
                        $expr,
                        $i
                    );
                    if ([] !== $rematerializeOps) {
                        $return = array_merge($return, $rematerializeOps);
                    }
                }
            }
            $keyOperand = $expr->keys[$i];
            $keyFetch = $this->findEnumCaseClassConstFetchForArrayElement(
                $keyOperand,
                $block,
                $expr,
                $i,
                true
            );
            if (null !== $keyFetch) {
                $keyTemp = new Operand\Temporary();
                $keySlot = $block->getVarSlot($keyTemp, false);
                $keyOp = new OpCode(
                    OpCode::TYPE_CLASS_CONST_FETCH,
                    $keySlot,
                    $this->compileOperand($keyFetch->class, $block, true),
                    $this->compileOperand($keyFetch->name, $block, true)
                );
                $this->assignSourceMetadata($keyOp, $keyFetch);
                $return[] = $keyOp;
            } else {
                $keySlot = $this->compileOperand($keyOperand, $block, true);
            }
            if (!empty($byRefFlags[$i])) {
                if (!$started) {
                    $return[] = new OpCode(OpCode::TYPE_INIT_ARRAY, $result);
                    $started = true;
                }
                $elemTemp = new Operand\Temporary();
                $elemSlot = $block->getVarSlot($elemTemp, false);
                $return[] = new OpCode(
                    OpCode::TYPE_ARRAY_DIM_FETCH_WRITE,
                    $elemSlot,
                    $result,
                    $keySlot instanceof Operand\NullOperand ? null : $keySlot
                );
                if (null !== $propFetch) {
                    ++$this->forcePropertyFetchForWrite;
                    $propWrite = new OpCode(
                        OpCode::TYPE_PROPERTY_FETCH_WRITE,
                        $valueSlot,
                        $this->compileOperand($propFetch->var, $block, true),
                        $this->compileOperand($propFetch->name, $block, true)
                    );
                    $this->assignSourceMetadata($propWrite, $propFetch);
                    $return[] = $propWrite;
                    --$this->forcePropertyFetchForWrite;
                }
                $return[] = new OpCode(
                    OpCode::TYPE_ASSIGN_REF,
                    $elemSlot,
                    $valueSlot
                );
                continue;
            }
            if (!$started) {
                $return[] = new OpCode(OpCode::TYPE_INIT_ARRAY, $result, $valueSlot, $keySlot);
                $started = true;
            } else {
                $return[] = new OpCode(OpCode::TYPE_ADD_ARRAY_ELEMENT, $result, $valueSlot, $keySlot);
            }
        }

        return $return;
    }

    /**
     * StaticCall lowering — mirror compileFuncCall arg partitioning (#23848, re-#17697).
     *
     * Nested StaticCall/FuncCall producers inside args must emit before outer STATICCALL_INIT,
     * same as FUNCCALL_INIT in {@see compileFuncCall()}.
     *
     * @param list<Op\Expr\Argument|Op\Expr\VariadicPlaceholder> $args
     *
     * @return list<OpCode>
     */
    protected function compileStaticCallOpcodes(
        OpCode $init,
        array $args,
        Operand $result,
        Block $block,
        int $startLine = 0,
        ?Op $cfgCallOp = null,
        ?string $calleeName = null
    ): array {
        $initPrependedBeforeArgConstFetch = false;
        if (!$this->inlineNestedProducerOpsInArgSends) {
            $initPrependedBeforeArgConstFetch = $this->prependFuncCallInitBeforeTrailingArgConstFetches(
                $block,
                $init
            );
        }
        $argSends = $this->compileCallArgSends($args, $block, $calleeName, $cfgCallOp);
        if (
            !$initPrependedBeforeArgConstFetch
            && !$this->inlineNestedProducerOpsInArgSends
        ) {
            $initPrependedBeforeArgConstFetch = $this->prependFuncCallInitBeforeTrailingArgConstFetches(
                $block,
                $init
            );
        }
        [$nestedProducerOps, $outerArgSends] = $this->partitionNestedInlineCallArgProducerOps($argSends);
        $this->rewireArrayBuiltinAdjacentFuncCallArgSendSlots(
            $outerArgSends,
            $nestedProducerOps,
            $block,
            $cfgCallOp,
            $calleeName
        );
        $this->rewireInlineArithmeticBranchCallArgSendSlots($outerArgSends, $nestedProducerOps, $block, $cfgCallOp);
        $this->rewireSiblingMultiArgInlineCallArgSendSlots($outerArgSends, $block, $cfgCallOp, $nestedProducerOps);
        $this->rewireNestedMethodCallHoistedClassConstOuterCallArgSendSlots(
            $outerArgSends,
            $block,
            $cfgCallOp,
            $nestedProducerOps
        );
        $this->rewireHoistedClassConstPreludeCallArgSendSlots($outerArgSends, $block, $cfgCallOp, $nestedProducerOps);
        $this->rewireRegisterShutdownFunctionClosureEnumCallArgSendSlots(
            $outerArgSends,
            $block,
            $cfgCallOp,
            $nestedProducerOps
        );
        $this->rewireSubstrNestedSprintfArgSendSlots($outerArgSends, $block, $cfgCallOp, $calleeName);
        $this->rewireArrayKeysInlineInitArrayArgSendSlots(
            $outerArgSends,
            $block,
            $cfgCallOp,
            $calleeName,
            array_merge($nestedProducerOps, $outerArgSends)
        );
        $this->rewireArrayCombineInlineArgSendSlots($outerArgSends, $block, $argSends, $calleeName, $cfgCallOp);
        $this->rewirePregReplaceCallbackArrayPatternMapArgSendSlots($outerArgSends, $block, $cfgCallOp, $argSends);
        $this->rewireVarExportNestedInlineCallArgSendSlots($outerArgSends, $nestedProducerOps, $block, $cfgCallOp, $calleeName);
        $this->rewireVarExportComparisonReturnFlagCallArgSendSlots(
            $outerArgSends,
            $nestedProducerOps,
            $block,
            $cfgCallOp,
            $calleeName
        );
        $this->rewireIsArrayNestedFileCallArgSendSlots($outerArgSends, $nestedProducerOps, $block, $cfgCallOp, $calleeName);
        $this->rewireInlineBitmaskTrailingCallArgSendSlots($outerArgSends, $nestedProducerOps, $block, $cfgCallOp);
        $this->rewireNamedLocalBeforeInlineBitmaskCallArgSendSlots($outerArgSends, $block, $cfgCallOp);
        $return = [];
        foreach ($outerArgSends as $send) {
            if (OpCode::TYPE_ASSIGN === $send->type) {
                $return[] = $send;
            }
        }
        foreach ($nestedProducerOps as $op) {
            $return[] = $op;
        }
        if (!$initPrependedBeforeArgConstFetch) {
            $return[] = $init;
        }
        foreach ($outerArgSends as $send) {
            if (OpCode::TYPE_ASSIGN !== $send->type) {
                $return[] = $send;
            }
        }
        $return[] = $this->compileStaticCallExecOpcode($result, $block, $startLine, $cfgCallOp);

        return $return;
    }

    /**
     * StaticCall exec opcode — nested StaticCall arg producers need EXEC_RETURN like FuncCall (#23848).
     */
    protected function compileStaticCallExecOpcode(
        Operand $result,
        Block $block,
        int $startLine = 0,
        ?Op $cfgCallOp = null
    ): OpCode {
        $exec = $this->compileFuncCallExecOpcode($result, $block, $startLine, $cfgCallOp);
        if (
            OpCode::TYPE_FUNCCALL_EXEC_NORETURN === $exec->type
            && (
                $this->callResultFeedsInlineCallArg($result, $block)
                || $this->nestedLiteralPreludeInlineCallProducerNeedsReturnSlot($cfgCallOp, $block)
                || $this->siblingInlineCallArgProducerNeedsReturnSlot($cfgCallOp, $block)
            )
        ) {
            return new OpCode(
                OpCode::TYPE_FUNCCALL_EXEC_RETURN,
                $this->compileOperand($result, $block, false),
                $startLine > 0 ? $startLine : null
            );
        }

        return $exec;
    }

    protected function compileMethodCallOpcodes(
        ?int $receiver,
        ?int $methodName,
        array $args,
        Operand $result,
        Block $block,
        int $startLine = 0,
        ?Op $cfgCallOp = null,
        bool $objectCallInvoke = false
    ): array {
        $argSends = $this->compileCallArgSends($args, $block, null, $cfgCallOp);
        [$nestedProducerOps, $outerArgSends] = $this->partitionNestedInlineCallArgProducerOps($argSends);
        $this->rewireInlineArithmeticBranchCallArgSendSlots($outerArgSends, $nestedProducerOps, $block, $cfgCallOp);
        // replaceChild(createElement(...), getElementsByTagName(...)->item(0)) — nested MethodCall
        // producers must run before INIT so they do not clobber the outer pending call (#25563).
        // Note: rewireSiblingMultiArgInlineCallArgSendSlots is FuncCall-oriented and can steal
        // createElement's EXEC_RETURN in favor of getElementsByTagName for MethodCall consumers;
        // mixed PropertyFetch+MethodCall ARG_SEND wiring in compileCallArgSends handles DOM cases.
        $return = [];
        foreach ($outerArgSends as $send) {
            if (OpCode::TYPE_ASSIGN === $send->type) {
                $return[] = $send;
            }
        }
        foreach ($nestedProducerOps as $op) {
            $return[] = $op;
        }
        $init = new OpCode(
            OpCode::TYPE_METHODCALL_INIT,
            $receiver,
            $methodName
        );
        // `$obj(...)` → `__invoke`: Zend object-call handler skips visibility (#26438).
        $init->objectCallInvoke = $objectCallInvoke;
        $return[] = $init;
        foreach ($outerArgSends as $send) {
            if (OpCode::TYPE_ASSIGN !== $send->type) {
                $return[] = $send;
            }
        }
        $return[] = $this->compileFuncCallExecOpcode($result, $block, $startLine, $cfgCallOp);

        return $return;
    }

    protected function compileFuncCallExecOpcode(
        Operand $result,
        Block $block,
        int $startLine = 0,
        ?Op $cfgCallOp = null
    ): OpCode {
        $line = $startLine > 0 ? $startLine : null;
        if ($this->stmtLevelVoidCallBeforeHoistedArrayConsumerPrelude($cfgCallOp, $block)) {
            return new OpCode(
                OpCode::TYPE_FUNCCALL_EXEC_NORETURN,
                $line
            );
        }
        if (
            $this->forceDeferredSiblingCallReturnSlot
            || $this->callNeedsReturnSlot($result, $block, $cfgCallOp)
            || $this->cfgCallOpImmediatelyVoidDiscarded($cfgCallOp, $block)
            || $this->siblingInlineCallArgProducerNeedsReturnSlot($cfgCallOp, $block)
            || $this->outerSiblingInlineFuncCallProducerNeedsReturnSlot($cfgCallOp, $block)
            || $this->hoistedSiblingFeedsLaterMultiArgConsumer($cfgCallOp, $block)
            || $this->methodCallDeadTempFeedsLaterMultiArgMethodCall($cfgCallOp, $block)
            || $this->inlineClosurePairHaystackFuncCallNeedsReturnSlot($cfgCallOp, $block)
            || $this->isAdjacentOuterHoistedFuncCallBeforeMultiArgConsumer($cfgCallOp, $block)
            || $this->nestedLiteralPreludeInlineCallProducerNeedsReturnSlot($cfgCallOp, $block)
            || $this->cfgCallIsHoistedArrayKeysForArrayCombine($cfgCallOp, $block)
            || $this->cfgCallImmediatelyFeedsAdjacentConsumer($cfgCallOp, $block)
        ) {
            return new OpCode(
                OpCode::TYPE_FUNCCALL_EXEC_RETURN,
                $this->compileOperand($result, $block, false),
                $line
            );
        }

        return new OpCode(
            OpCode::TYPE_FUNCCALL_EXEC_NORETURN,
            $line
        );
    }

    /**
     * php-cfg `var_dump($g(), $g())` hoists sibling FuncCall producers with dead arg temps (#9463, #10981).
     * Each producer must FUNCCALL_EXEC_RETURN into its result slot before the outer call sends args.
     *
     * @param list<Op> $cfgChildren
     */
    private function siblingInlineCallArgProducerNeedsReturnSlot(?Op $cfgCallOp, Block $block): bool
    {
        if (
            null === $cfgCallOp
            || null === $block->orig
            || !$this->isSiblingInlineCallProducerExpr($cfgCallOp)
        ) {
            return false;
        }
        $cfgChildren = $block->orig->children;
        $producerIndex = $this->cfgCallOpIndexInChildren($cfgChildren, $cfgCallOp, $block->orig);
        if (!\is_int($producerIndex) || !$cfgCallOp instanceof Op\Expr) {
            return false;
        }
        $n = \count($cfgChildren);
        // Only consumers in the near window after this producer can use it as a hoisted
        // sibling arg — scanning the whole block was O(n²) firstSibling (#36387).
        $scanEnd = min($n, $producerIndex + 1 + 32);
        for ($consumerIndex = $producerIndex + 1; $consumerIndex < $scanEnd; ++$consumerIndex) {
            $consumer = $cfgChildren[$consumerIndex] ?? null;
            if (!$this->isInlineExprCallArgConsumer($consumer)) {
                continue;
            }
            if (!property_exists($consumer, 'args') || !is_array($consumer->args) || \count($consumer->args) < 2) {
                if (
                    property_exists($consumer, 'args')
                    && \is_array($consumer->args)
                    && 1 === \count($consumer->args)
                    && $this->isIifeHoistedFuncCallArgProducer(
                        $cfgCallOp,
                        $consumer,
                        $producerIndex,
                        $consumerIndex,
                        $cfgChildren
                    )
                ) {
                    return true;
                }
                continue;
            }
            $firstSibling = $this->firstSiblingInlineFuncCallProducerIndex($consumerIndex, $cfgChildren);
            if ($this->isNestedCallArgProducerSeparatedByConsumerLiteralPreludes(
                $cfgCallOp,
                $consumer,
                $producerIndex,
                $consumerIndex,
                $cfgChildren
            )) {
                return true;
            }
            // substr(sprintf(...), -N) — lone hoisted FuncCall + UnaryMinus offset (#10673, #13801).
            if ($this->isSiblingMultiArgFuncCallProducer(
                $cfgCallOp,
                $consumer,
                $producerIndex,
                $consumerIndex,
                $cfgChildren
            )) {
                return true;
            }
            if (
                null === $firstSibling
                || $this->countSiblingInlineFuncCallProducers($firstSibling, $consumerIndex, $cfgChildren) < 2
            ) {
                continue;
            }
            // Multi-sibling chain (MethodCall + FuncCall around ArrayDimFetch) (#28821).
            return true;
        }

        return false;
    }

    /**
     * php-cfg array_intersect(f(g()), f(g())) — outer hoisted producers need EXEC_RETURN (#15488).
     */
    private function outerSiblingInlineFuncCallProducerNeedsReturnSlot(?Op $cfgCallOp, Block $block): bool
    {
        if (!$cfgCallOp instanceof Op\Expr || null === $block->orig) {
            return false;
        }
        $cfgChildren = $block->orig->children;
        $producerIndex = $this->cfgCallOpIndexInChildren($cfgChildren, $cfgCallOp);
        if (!is_int($producerIndex)) {
            return false;
        }
        for ($consumerIndex = $producerIndex + 1, $n = \count($cfgChildren); $consumerIndex < $n; ++$consumerIndex) {
            // Bound: nested outer producers sit near the consumer (#36387).
            if ($consumerIndex > $producerIndex + 32) {
                break;
            }
            $consumer = $cfgChildren[$consumerIndex] ?? null;
            if (!$this->isSiblingMultiArgInlineCallConsumer($consumer)) {
                continue;
            }
            if (!property_exists($consumer, 'args') || !\is_array($consumer->args) || \count($consumer->args) < 2) {
                continue;
            }
            $firstSibling = $this->firstSiblingInlineFuncCallProducerIndex($consumerIndex, $cfgChildren);
            if (null === $firstSibling || $firstSibling > $producerIndex) {
                continue;
            }
            $outer = $this->outerSiblingInlineFuncCallProducers($firstSibling, $consumerIndex, $cfgChildren);
            $hoistedArgCount = 0;
            foreach ($consumer->args as $callArg) {
                if (null !== $callArg && !$this->isEmbeddedCallLiteralArg($callArg)) {
                    ++$hoistedArgCount;
                }
            }
            if (\count($outer) !== $hoistedArgCount) {
                continue;
            }
            foreach ($outer as $outerProducer) {
                if ($outerProducer === $cfgCallOp) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * createElement(...) before replaceChild(..., getElementsByTagName(...)->item(0)) — php-cfg marks
     * the MethodCall result as a dead temp (empty usages), so the usual sibling-producer predicates
     * miss it and EXEC_NORETURN drops the new node (#25563).
     *
     * Restricted to {@code create*} factory MethodCalls: a bare empty-usages MethodCall before a
     * multi-arg consumer also matches statement {@code loadXML} ahead of
     * {@code importNode(getElementsByTagName()->item(), true)}, which then drops the load and leaves
     * {@code documentElement} null (#25605, re-#20284).
     *
     * @param list<Op> $ops
     */
    private function methodCallDeadTempFeedsLaterMultiArgMethodCallInOps(
        Op\Expr\MethodCall $producer,
        array $ops,
        int $producerIndex
    ): bool {
        if ($this->methodCallHasStatementLevelSideEffects($producer)) {
            return false;
        }
        if (null !== $producer->result && !empty($producer->result->usages)) {
            return false;
        }
        // Only DOM/document factory creates are inline dead-temp args for multi-arg MethodCalls
        // (#25563). loadXML/loadHTML/etc. are statement-level even when their bool result is unused
        // (#25605).
        if (!$this->methodCallIsDeadTempCreateFactory($producer)) {
            return false;
        }
        $opCount = \count($ops);
        // Bound: create* factory + property/const preludes + multi-arg MethodCall stay near (#36387).
        $scanEnd = min($opCount, $producerIndex + 1 + 32);
        for ($j = $producerIndex + 1; $j < $scanEnd; ++$j) {
            $next = $ops[$j] ?? null;
            if ($next instanceof Op\Expr\MethodCall || $next instanceof Op\Expr\StaticCall) {
                if (!\is_array($next->args ?? null) || \count($next->args) < 2) {
                    continue;
                }
                $deadTempCount = 0;
                foreach ($next->args as $arg) {
                    if ($this->callArgIsDeadInlineTemporary($arg)) {
                        ++$deadTempCount;
                    }
                }
                if ($deadTempCount >= 2) {
                    return true;
                }
                continue;
            }
            if (
                $next instanceof Op\Expr\PropertyFetch
                || $next instanceof Op\Expr\NullsafePropertyFetch
                || $next instanceof Op\Expr\ConstFetch
                || $next instanceof Op\Expr\ClassConstFetch
                || $next instanceof Op\Expr\FuncCall
                || $next instanceof Op\Expr\NsFuncCall
                || $this->isUnaryInlineSiblingCallArgExpr($next)
            ) {
                continue;
            }
            if ($next instanceof Op && $this->isSiblingInlineCallProducerExpr($next)) {
                continue;
            }
            break;
        }

        return false;
    }

    /**
     * createElement / createTextNode / … — dead-temp factories that feed multi-arg MethodCalls (#25563).
     * Not loadXML / query methods (#25605).
     */
    private function methodCallIsDeadTempCreateFactory(Op\Expr\MethodCall $call): bool
    {
        $method = $this->staticNameFromOperand($call->name);
        if (null === $method) {
            return false;
        }

        return str_starts_with(strtolower($method), 'create');
    }

    /** Block-scoped wrapper for {@see methodCallDeadTempFeedsLaterMultiArgMethodCallInOps} (#25563). */
    private function methodCallDeadTempFeedsLaterMultiArgMethodCall(?Op $cfgCallOp, Block $block): bool
    {
        if (!$cfgCallOp instanceof Op\Expr\MethodCall || null === $block->orig) {
            return false;
        }
        $cfgChildren = $block->orig->children;
        $producerIndex = $this->cfgCallOpIndexInChildren($cfgChildren, $cfgCallOp);
        if (!\is_int($producerIndex)) {
            return false;
        }

        return $this->methodCallDeadTempFeedsLaterMultiArgMethodCallInOps(
            $cfgCallOp,
            $cfgChildren,
            $producerIndex
        );
    }

    protected function compileFuncCall(
        ?int $name,
        array $args,
        Operand $result,
        Block $block,
        int $startLine = 0,
        ?Op $cfgCallOp = null
    ): array
    {
        $folded = $this->tryCompileDefineAsGlobalConst($name, $args, $result, $block, $startLine);
        if (null !== $folded) {
            return $folded;
        }

        $isDynamicCallee = null !== $cfgCallOp
            && ($cfgCallOp instanceof Op\Expr\FuncCall || $cfgCallOp instanceof Op\Expr\NsFuncCall)
            && $this->funcCallExprUsesVariableCallee($cfgCallOp);

        // Do not fold ForbiddenWhenDynamic names — keep the dynamic flag observable (#23591).
        $foldedName = $this->tryFoldVariableFunctionName($name, $block);
        if (
            null !== $foldedName
            && $isDynamicCallee
            && null !== ($foldedStr = $this->resolveCompileTimeStringSlot($foldedName, $block))
            && VariableFunctionCall::isForbiddenWhenDynamic($foldedStr)
        ) {
            $foldedName = null;
        }
        $callName = $foldedName ?? $name;
        $calleeName = $this->resolveCompileTimeStringSlot($callName, $block)
            ?? ($name !== null ? $this->resolveCompileTimeStringSlot($name, $block) : null);

        $this->lowerEmbeddedCoalesceCallArgs($args, $block);

        $init = new OpCode(
            OpCode::TYPE_FUNCCALL_INIT,
            $callName,
            $startLine > 0 ? $startLine : null
        );
        $init->funcCallDynamic = $isDynamicCallee;
        if (null !== $cfgCallOp) {
            $this->assignSourceMetadata($init, $cfgCallOp);
        }

        $skipPrependForHaystackFamilyDimFetch = false;
        if (null !== $cfgCallOp && \is_array($cfgCallOp->args ?? null)) {
            foreach ($cfgCallOp->args as $argIndex => $callArg) {
                if (!$callArg instanceof Operand) {
                    continue;
                }
                if ($this->callArgIsDeadInlineHaystackFamilySlot(
                    $cfgCallOp,
                    (int) $argIndex,
                    $calleeName,
                    $callArg
                )) {
                    $skipPrependForHaystackFamilyDimFetch = true;
                    break;
                }
            }
        }

        // var_export(fdiv(...), true) — sibling FuncCall producer must INIT/EXEC before consumer (#5471, #4633).
        // Scope to var_export only — broad skip breaks in_array dim-fetch haystack in echo ternary chains (re-#17000, #17851).
        $skipPrependForSiblingFuncProducer = false;
        if (
            null !== $cfgCallOp
            && null !== $block->orig
            && 'var_export' === strtolower($this->resolveCfgFuncCallName($cfgCallOp) ?? '')
        ) {
            $consumerCfgIndex = $this->cfgCallOpIndexInChildren($block->orig->children, $cfgCallOp, $block->orig);
            if (\is_int($consumerCfgIndex)) {
                $skipPrependForSiblingFuncProducer = null !== $this->firstSiblingInlineFuncCallProducerIndex(
                    $consumerCfgIndex,
                    $block->orig->children
                );
            }
        }
        $initPrependedBeforeArgConstFetch = false;
        $skipPrependForExplodeLeadingConstFunc = null !== $cfgCallOp
            && 'explode' === strtolower($this->resolveCfgFuncCallName($cfgCallOp) ?? '')
            && null !== $this->leadingConstFetchFuncCallPreludeBeforeCfgCall($cfgCallOp, $block);
        // date_sunrise()/date_sunset() hoisted strtotime + SUNFUNCS_RET_* ConstFetch — producer INIT must run first (#17937).
        $skipPrependForDateSunFunc = null !== $cfgCallOp
            && \in_array(
                strtolower($this->resolveCfgFuncCallName($cfgCallOp) ?? ''),
                ['date_sunrise', 'date_sunset', 'date_sun_info'],
                true
            );
        // json_encode(f(), JSON_FORCE_OBJECT) — hoisted JSON_* ConstFetch must not prepend outer
        // INIT before nested FuncCall producers; AOT walked clobbered arg temps (#34559).
        $skipPrependForJsonEncodeNestedCallArg = null !== $cfgCallOp
            && $this->jsonEncodeDeferredInitForNestedCallArg($cfgCallOp, $block);
        // str_pad/mb_str_pad(…, s(), STR_PAD_*) — same INIT/ConstFetch hoist; VM returned nested value (#34890).
        $skipPrependForStrPadNestedCallArg = null !== $cfgCallOp
            && $this->strPadDeferredInitForNestedCallArg($cfgCallOp, $block);
        if (
            !$skipPrependForSiblingFuncProducer
            && !$skipPrependForHaystackFamilyDimFetch
            && !$skipPrependForExplodeLeadingConstFunc
            && !$skipPrependForDateSunFunc
            && !$skipPrependForJsonEncodeNestedCallArg
            && !$skipPrependForStrPadNestedCallArg
        ) {
            $initPrependedBeforeArgConstFetch = $this->prependFuncCallInitBeforeTrailingArgConstFetches(
                $block,
                $init
            );
        }

        $argSends = $this->compileCallArgSends($args, $block, $calleeName, $cfgCallOp);
        $argSends = $this->rewriteCallArgSendsForArraySpreadResult($argSends, $block, $cfgCallOp);
        // Hoisted call-arg ConstFetch lands on $block during compileCallArgSends — prepend INIT now (#17697).
        if (
            !$initPrependedBeforeArgConstFetch
            && !$skipPrependForSiblingFuncProducer
            && !$skipPrependForHaystackFamilyDimFetch
            && !$skipPrependForExplodeLeadingConstFunc
            && !$skipPrependForDateSunFunc
            && !$skipPrependForJsonEncodeNestedCallArg
            && !$skipPrependForStrPadNestedCallArg
        ) {
            $initPrependedBeforeArgConstFetch = $this->prependFuncCallInitBeforeTrailingArgConstFetches(
                $block,
                $init
            );
        }
        [$nestedProducerOps, $outerArgSends] = $this->partitionNestedInlineCallArgProducerOps($argSends);
        $this->rewireArrayBuiltinAdjacentFuncCallArgSendSlots(
            $outerArgSends,
            $nestedProducerOps,
            $block,
            $cfgCallOp,
            $calleeName
        );
        $this->rewireInlineArithmeticBranchCallArgSendSlots($outerArgSends, $nestedProducerOps, $block, $cfgCallOp);
        $this->rewireSiblingMultiArgInlineCallArgSendSlots($outerArgSends, $block, $cfgCallOp, $nestedProducerOps);
        $this->rewireNestedMethodCallHoistedClassConstOuterCallArgSendSlots(
            $outerArgSends,
            $block,
            $cfgCallOp,
            $nestedProducerOps
        );
        $this->rewireHoistedClassConstPreludeCallArgSendSlots($outerArgSends, $block, $cfgCallOp, $nestedProducerOps);
        $this->rewireRegisterShutdownFunctionClosureEnumCallArgSendSlots(
            $outerArgSends,
            $block,
            $cfgCallOp,
            $nestedProducerOps
        );
        $this->rewireSubstrNestedSprintfArgSendSlots($outerArgSends, $block, $cfgCallOp, $calleeName);
        $this->rewireArrayKeysInlineInitArrayArgSendSlots(
            $outerArgSends,
            $block,
            $cfgCallOp,
            $calleeName,
            array_merge($nestedProducerOps, $outerArgSends)
        );
        $this->rewireArrayCombineInlineArgSendSlots($outerArgSends, $block, $argSends, $calleeName, $cfgCallOp);
        $this->rewirePregReplaceCallbackArrayPatternMapArgSendSlots($outerArgSends, $block, $cfgCallOp, $argSends);
        $this->rewireVarExportNestedInlineCallArgSendSlots($outerArgSends, $nestedProducerOps, $block, $cfgCallOp, $calleeName);
        $this->rewireVarExportComparisonReturnFlagCallArgSendSlots(
            $outerArgSends,
            $nestedProducerOps,
            $block,
            $cfgCallOp,
            $calleeName
        );
        $this->rewireIsArrayNestedFileCallArgSendSlots($outerArgSends, $nestedProducerOps, $block, $cfgCallOp, $calleeName);
        $this->rewireInlineBitmaskTrailingCallArgSendSlots($outerArgSends, $nestedProducerOps, $block, $cfgCallOp);
        $this->rewireNamedLocalBeforeInlineBitmaskCallArgSendSlots($outerArgSends, $block, $cfgCallOp);
        $return = [];
        foreach ($outerArgSends as $send) {
            if (OpCode::TYPE_ASSIGN === $send->type) {
                $return[] = $send;
            }
        }
        foreach ($nestedProducerOps as $op) {
            $return[] = $op;
        }
        // php-src resolves callee before evaluating call args (#17697); hoisted nested FuncCall
        // producers (ensureDeferredSiblingInlineCallArgProducersCompiled) must run first (#17708).
        if (!$initPrependedBeforeArgConstFetch) {
            $return[] = $init;
        }
        foreach ($outerArgSends as $send) {
            if (OpCode::TYPE_ASSIGN !== $send->type) {
                $return[] = $send;
            }
        }
        $return[] = $this->compileFuncCallExecOpcode($result, $block, $startLine, $cfgCallOp);
        return $return;
    }

    /**
     * Fold $fn = 'name'; $fn(...) to a literal callee when the name is a compile-time string (#56).
     *
     * Follows TYPE_ASSIGN chains so first-class callables (`strlen(...)`, `C::m(...)`, #1363) fold too.
     */
    protected function tryFoldVariableFunctionName(?int $nameSlot, Block $block): ?int
    {
        if (null === $nameSlot) {
            return null;
        }
        $name = $this->resolveCompileTimeStringSlot($nameSlot, $block);
        if (null === $name) {
            return null;
        }
        $lit = new Literal($name);
        $lit->type = Type::string();

        return $this->compileOperand($lit, $block, true);
    }

    /**
     * Resolve a scope slot to a compile-time string via constants or assign chains (#1363).
     */
    protected function resolveCompileTimeStringSlot(int $slot, Block $block, array &$visited = []): ?string
    {
        if (isset($visited[$slot])) {
            return null;
        }
        $visited[$slot] = true;
        if (isset($block->constants[$slot])) {
            $const = $block->constants[$slot];
            if (Variable::TYPE_STRING !== $const->type) {
                return null;
            }

            return $const->toString();
        }
        foreach ($block->opCodes as $op) {
            if (OpCode::TYPE_ASSIGN !== $op->type || $op->arg2 !== $slot) {
                continue;
            }
            $resolved = $this->resolveCompileTimeStringSlot((int) $op->arg3, $block, $visited);
            if (null !== $resolved) {
                return $resolved;
            }
        }

        foreach ($block->parents as $parent) {
            if (!$parent instanceof Block) {
                continue;
            }
            $resolved = $this->resolveCompileTimeStringSlot($slot, $parent, $visited);
            if (null !== $resolved) {
                return $resolved;
            }
        }

        return null;
    }

    /**
     * Lower define('NAME', literal) to compile-time global constant registration (issue #204).
     *
     * @return list<OpCode>|null
     */
    protected function tryCompileDefineAsGlobalConst(
        ?int $name,
        array $args,
        Operand $result,
        Block $block,
        int $startLine = 0
    ): ?array {
        if (null === $name) {
            return null;
        }
        $nameOp = $block->getOperand($name);
        if (!$nameOp instanceof Operand\Literal || 'define' !== $nameOp->value) {
            return null;
        }
        if (count($args) < 2 || count($args) > 3) {
            return null;
        }
        $constNameArg = $args[0];
        $valueArg = $args[1];
        if (!$constNameArg instanceof Operand\Literal || !$valueArg instanceof Operand\Literal) {
            return null;
        }
        if (Variable::TYPE_STRING !== Variable::mapFromType($constNameArg->type)) {
            return null;
        }
        $caseInsensitiveSlot = null;
        if (3 === count($args)) {
            $caseInsensitiveArg = $args[2];
            if (!$caseInsensitiveArg instanceof Operand\Literal) {
                return null;
            }
            if (Variable::TYPE_BOOLEAN !== Variable::mapFromType($caseInsensitiveArg->type)) {
                return null;
            }
            $caseInsensitiveSlot = $this->compileOperand($caseInsensitiveArg, $block, true);
            if (!isset($block->constants[$caseInsensitiveSlot])) {
                return null;
            }
        }
        $constNameSlot = $this->compileOperand($constNameArg, $block, true);
        $valueSlot = $this->compileOperand($valueArg, $block, true);
        if (!isset($block->constants[$constNameSlot], $block->constants[$valueSlot])) {
            return null;
        }
        $constName = $block->constants[$constNameSlot]->toString();
        if ('' === $constName || str_contains($constName, '::')) {
            return null;
        }
        // File-scope define() may fold later ConstFetch (#204 / #6542). define() inside a
        // function/method still runs when the function does — do not seed compile-time
        // consts or {main} would see the name before the call (#32039).
        if ($this->compileBlockIsFileScopeMain($block)) {
            $this->storeCompileTimeGlobalConst($constName, $block->constants[$valueSlot]);
        }
        $declare = new OpCode(
            OpCode::TYPE_DECLARE_GLOBAL_CONST,
            $constNameSlot,
            $valueSlot,
            $caseInsensitiveSlot
        );
        if ($startLine > 0) {
            $declare->globalConstStartLine = $startLine;
        }
        $ops = [$declare];
        if (!empty($result->usages)) {
            $trueVar = new Variable(Variable::TYPE_BOOLEAN);
            $trueVar->bool(true);
            $trueOperand = new Temporary;
            $trueOperand->type = Type::bool();
            $trueSlot = $block->registerConstant($trueOperand, $trueVar);
            $ops[] = new OpCode(
                OpCode::TYPE_ASSIGN,
                $this->compileOperand($result, $block, false),
                $this->compileOperand($result, $block, false),
                $trueSlot
            );
        }

        return $ops;
    }

    /**
     * Literal includes read caller locals by name; php-cfg may mark those assigns dead (#568).
     */
    private function markCallerLocalsUsedByLiteralInclude(string $path, Block $block): void
    {
        if (!is_file($path)) {
            return;
        }
        $code = file_get_contents($path);
        if (false === $code || '' === $code) {
            return;
        }
        foreach ($block->scopedOperands() as $operand) {
            $name = OperandName::resolve($operand);
            if (null === $name || Superglobals::isSuperglobalName($name)) {
                continue;
            }
            if (!preg_match('/\\$'.preg_quote($name, '/').'\\b/', $code)) {
                continue;
            }
            if ($operand instanceof Temporary && [] === $operand->usages) {
                $operand->usages[] = $operand;
            }
        }
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

    /**
     * Zend zend_compile.c: assignment to $this is a compile-time fatal (#4865).
     *
     * @return never
     */
    protected function rejectThisReassignment(?Operand $var): void
    {
        if (null === $var) {
            return;
        }
        if ('this' === $this->baseVariableName($var)) {
            $this->throwCompileError('Cannot re-assign $this');
        }
    }

    /**
     * Zend zend_compile.c: acquiring a reference to $GLOBALS is a compile-time fatal (#15627).
     *
     * @return never
     */
    protected function rejectGlobalsReferenceAcquisition(?Operand $expr): void
    {
        if (null === $expr) {
            return;
        }
        if ('GLOBALS' === $this->baseVariableName($expr)) {
            $this->throwCompileError('Cannot acquire reference to $GLOBALS');
        }
    }

    /**
     * Zend zend_compile.c zend_ensure_writable_variable(): bare $GLOBALS is not a write target (#32229).
     * Indexed $GLOBALS[$name] remains legal. Message matches php-src exactly.
     *
     * @return never
     */
    protected function rejectGlobalsWrite($var, ?Op $source = null, ?Block $block = null): void
    {
        if (!$var instanceof Operand) {
            return;
        }
        if (!$this->isBareGlobalsVariable($var, $block)) {
            return;
        }
        $detail = '$GLOBALS can only be modified using the $GLOBALS[$name] = $value syntax';
        if (null !== $source) {
            $sourceFile = $source->getFile();
            if ('' === $sourceFile) {
                $sourceFile = 'unknown';
            }
            $this->throwCompileError($detail, $sourceFile, $source->getLine());
        }
        $this->throwCompileError($detail);
    }

    /**
     * Zend zend_compile.c zend_compile_assign_dim(): `$GLOBALS[]` is never a legal write (#32253).
     * Indexed `$GLOBALS[$name]` remains legal; empty-dim append uses a distinct diagnostic from #32229.
     *
     * @return never
     */
    protected function rejectGlobalsAppend(Op\Expr\ArrayDimFetch $fetch, ?Block $block = null): void
    {
        if (!$this->isArrayAppendDim($fetch->dim)) {
            return;
        }
        $container = $fetch->var;
        if (!$container instanceof Operand) {
            return;
        }
        if (!$this->isBareGlobalsVariable($container, $block)) {
            return;
        }
        $detail = 'Cannot append to $GLOBALS';
        $sourceFile = $fetch->getFile();
        if ('' === $sourceFile) {
            $sourceFile = 'unknown';
        }
        $this->throwCompileError($detail, $sourceFile, $fetch->getLine());
    }

    /**
     * True for `$GLOBALS` itself, not `$GLOBALS[$name]` / `$GLOBALS->x` (#32229).
     */
    private function isBareGlobalsVariable(Operand $operand, ?Block $block = null): bool
    {
        if (null !== $this->unwrapArrayDimFetch($operand)) {
            return false;
        }
        if (null !== $this->unwrapPropertyFetch($operand)) {
            return false;
        }
        if (null !== $block && null !== $this->findArrayDimFetchForResult($operand, $block)) {
            return false;
        }

        return 'GLOBALS' === $this->baseVariableName($operand);
    }

    /**
     * Zend zend_compile.c: unset($this) is a compile-time fatal (#5436).
     *
     * @return never
     */
    protected function rejectThisUnset($expr): void
    {
        if (!$expr instanceof Operand) {
            return;
        }
        if ('this' === $this->unsetTargetVariableName($expr)) {
            $this->throwCompileError('Cannot unset $this');
        }
    }

    private function unsetTargetVariableName(Operand $expr): ?string
    {
        $name = $this->baseVariableName($expr);
        if (null !== $name) {
            return $name;
        }
        $var = $this->unwrapVariableOperand($expr);
        if (null !== $var && $var->name instanceof Literal && is_string($var->name->value)) {
            return $var->name->value;
        }

        return null;
    }

    /**
     * @param Op[] $ops
     */
    private function operandUsedInWriteContext(array $ops, int $startIndex, Operand $operand): bool
    {
        for ($j = $startIndex, $count = count($ops); $j < $count; ++$j) {
            $op = $ops[$j];
            if ($this->isDirectWriteUseOfOperand($op, $operand)) {
                return true;
            }
            if ($op instanceof Op\Expr\NullsafePropertyFetch
                && $this->operandsChainEqual($op->var, $operand)) {
                return $this->operandUsedInWriteContext($ops, $j + 1, $op->result);
            }
            // Chained write: $a?->b->x = / ++ — PropertyFetch sits between nullsafe and assign (#25560).
            if ($op instanceof Op\Expr\PropertyFetch
                && $this->operandsChainEqual($op->var, $operand)) {
                return $this->operandUsedInWriteContext($ops, $j + 1, $op->result);
            }
            if ($op instanceof Op\Expr\ArrayDimFetch
                && $this->operandsChainEqual($op->var, $operand)) {
                return $this->operandUsedInWriteContext($ops, $j + 1, $op->result);
            }
            if ($op instanceof Op\Expr\BinaryOp\Coalesce
                && $this->operandsChainEqual($op->left, $operand)
                && $j + 1 < $count
                && $ops[$j + 1] instanceof Op\Expr\Assign
                && $this->isCoalesceAssignTail($ops[$j + 1], $op)
                && $this->operandsChainEqual($ops[$j + 1]->var, $op->left)) {
                return true;
            }
        }

        return false;
    }

    private function isDirectWriteUseOfOperand(Op $op, Operand $operand): bool
    {
        if ($op instanceof Op\Expr\Assign && $this->operandsChainEqual($op->var, $operand)) {
            return true;
        }
        if ($op instanceof Op\Expr\AssignRef && $this->operandsChainEqual($op->var, $operand)) {
            return true;
        }
        if ($op instanceof Op\Terminal\Unset_) {
            foreach ($op->exprs as $var) {
                if ($this->operandsChainEqual($var, $operand)) {
                    return true;
                }
                $target = $var;
                while ($target instanceof Temporary) {
                    if ($this->operandsChainEqual($target, $operand)) {
                        return true;
                    }
                    if (null === $target->original) {
                        break;
                    }
                    $target = $target->original;
                }
            }

            return false;
        }
        if ($op instanceof Op\Expr\PostInc
            || $op instanceof Op\Expr\PreInc
            || $op instanceof Op\Expr\PostDec
            || $op instanceof Op\Expr\PreDec) {
            $write = $op->write ?? $op->read;

            return $this->operandsChainEqual($write, $operand);
        }

        return false;
    }


    /**
     * File-scope `const` names registered during this compile unit (#6935).
     */
    protected function operandIsCompileTimeGlobalConstFetch(?Operand $operand, ?Block $block = null): bool
    {
        if (null === $operand) {
            return false;
        }
        $root = $this->unwrapOperandChain($operand);
        if ($root instanceof Op\Expr\ConstFetch) {
            $name = $this->staticNameFromOperand($root->name);
            if (null === $name) {
                return false;
            }

            return isset($this->compileTimeGlobalConsts[strtolower($name)]);
        }
        if (null === $block || null === $block->orig) {
            return false;
        }
        foreach ($block->orig->children as $child) {
            if (!$child instanceof Op\Expr\ConstFetch) {
                continue;
            }
            if ($this->unwrapOperandChain($child->result) !== $root) {
                continue;
            }
            $name = $this->staticNameFromOperand($child->name);
            if (null === $name) {
                continue;
            }
            if (isset($this->compileTimeGlobalConsts[strtolower($name)])) {
                return true;
            }
        }

        return false;
    }

    /**
     * Class `const` names registered during this compile unit (#5409).
     */
    protected function operandIsCompileTimeClassConstFetch(?Operand $operand, ?Block $block = null): bool
    {
        if (null === $operand || null === $block) {
            return false;
        }
        $root = $this->unwrapOperandChain($operand);
        if ($root instanceof Op\Expr\ClassConstFetch) {
            return $this->compileTimeClassConstFetchRegistered($root, $block);
        }
        if (null === $block->orig) {
            return false;
        }
        foreach ($block->orig->children as $child) {
            if (!$child instanceof Op\Expr\ClassConstFetch) {
                continue;
            }
            if ($this->unwrapOperandChain($child->result) !== $root) {
                continue;
            }
            if ($this->compileTimeClassConstFetchRegistered($child, $block)) {
                return true;
            }
        }

        return false;
    }

    protected function compileTimeClassConstFetchRegistered(
        Op\Expr\ClassConstFetch $fetch,
        Block $block,
    ): bool {
        $className = $this->staticNameFromOperand($fetch->class);
        $constName = $this->staticNameFromOperand($fetch->name);
        if (null === $className || null === $constName || 'class' === strtolower($constName)) {
            return false;
        }
        $lcClass = $this->resolveDefaultClassConstScope($className, $block);
        if (null === $lcClass) {
            return false;
        }

        return isset($this->compileTimeClassConsts[$lcClass][ClassConstName::key($constName)])
            && ClassConstName::matchesDeclared(
                $constName,
                $this->compileTimeClassConstNames[$lcClass][ClassConstName::key($constName)] ?? null
            );
    }

    protected function operandIsCompileTimeConstFetch(?Operand $operand, ?Block $block = null): bool
    {
        return $this->operandIsCompileTimeGlobalConstFetch($operand, $block)
            || $this->operandIsCompileTimeClassConstFetch($operand, $block);
    }

    /**
     * Any ConstFetch / ClassConstFetch (registered or not).
     *
     * Zend rejects dim/prop write and assign-by-ref on constant fetches as
     * "temporary expression in write context" even when the name is undefined
     * or only exists via runtime define() (#5409, #26488). Registration in
     * compileTimeGlobalConsts must not gate this — #17676 correctly stopped
     * seeding define() array values for folding, which regressed write-context.
     */
    protected function operandIsConstOrClassConstFetch(?Operand $operand, ?Block $block = null): bool
    {
        if (null === $operand) {
            return false;
        }
        $root = $this->unwrapOperandChain($operand);
        if ($root instanceof Op\Expr\ConstFetch || $root instanceof Op\Expr\ClassConstFetch) {
            return true;
        }
        if (null === $block || null === $block->orig) {
            return false;
        }
        foreach ($block->orig->children as $child) {
            if (!$child instanceof Op\Expr\ConstFetch && !$child instanceof Op\Expr\ClassConstFetch) {
                continue;
            }
            if ($this->unwrapOperandChain($child->result) === $root) {
                return true;
            }
        }

        return false;
    }

    /**
     * Zend zend_compile.c: mutating a const/class-const array is a compile-time fatal (#6935, #5409, #26488).
     */
    protected function lvalueContainsGlobalConstFetch(?Operand $operand, ?Block $block = null): bool
    {
        if (null === $operand || null === $block) {
            return false;
        }
        if ($operand instanceof Operand\Temporary && null !== $operand->original) {
            if ($operand->original instanceof Op\Expr\PropertyFetch) {
                /** @var Op\Expr\PropertyFetch $propFetch */
                $propFetch = $operand->original;
                if ($this->operandIsConstOrClassConstFetch($propFetch->var, $block)) {
                    return true;
                }

                return $this->lvalueContainsGlobalConstFetch($propFetch->var, $block);
            }
            if ($operand->original instanceof Op\Expr\ArrayDimFetch) {
                /** @var Op\Expr\ArrayDimFetch $dimFetch */
                $dimFetch = $operand->original;
                if ($this->operandIsConstOrClassConstFetch($dimFetch->var, $block)) {
                    return true;
                }

                return $this->lvalueContainsGlobalConstFetch($dimFetch->var, $block);
            }

            return $this->lvalueContainsGlobalConstFetch($operand->original, $block);
        }
        if (null !== $block->orig) {
            $propFetch = $this->findPropertyFetchForResult($operand, $block);
            if (null !== $propFetch) {
                if ($this->operandIsConstOrClassConstFetch($propFetch->var, $block)) {
                    return true;
                }

                return $this->lvalueContainsGlobalConstFetch($propFetch->var, $block);
            }
            $dimFetch = $this->findArrayDimFetchForResult($operand, $block);
            if (null !== $dimFetch) {
                if ($this->operandIsConstOrClassConstFetch($dimFetch->var, $block)) {
                    return true;
                }

                return $this->lvalueContainsGlobalConstFetch($dimFetch->var, $block);
            }
        }

        return $this->operandIsConstOrClassConstFetch($operand, $block);
    }

    /**
     * Resolve Zend-shaped file/line for temporary write-context fatals (#29769 / #27718).
     *
     * @return array{0: string, 1: int}
     */
    protected function resolveWriteContextFatalSite(?Operand $var, ?Block $block, ?Op $siteOp = null): array
    {
        $file = '';
        $line = 0;
        if (null !== $siteOp) {
            $file = (string) ($siteOp->getFile() ?? '');
            $line = (int) $siteOp->getLine();
        }
        if (
            ('' === $file || $line <= 0)
            && $var instanceof Operand\Temporary
            && $var->original instanceof Op
        ) {
            if ('' === $file) {
                $file = (string) ($var->original->getFile() ?? '');
            }
            if ($line <= 0) {
                $line = (int) $var->original->getLine();
            }
        }
        if (('' === $file || $line <= 0) && null !== $block && null !== $block->orig) {
            foreach ($block->orig->children as $child) {
                if (!$child instanceof Op) {
                    continue;
                }
                $matches = false;
                if ($child instanceof Op\Expr\Assign || $child instanceof Op\Expr\AssignRef) {
                    $matches = null !== $var && (
                        $child->var === $var
                        || $this->operandsReferToSameVariable($child->var, $var)
                    );
                } elseif ($child instanceof Op\Expr\ArrayDimFetch || $child instanceof Op\Expr\PropertyFetch) {
                    $matches = null !== $var && (
                        $child->result === $var
                        || $this->operandsReferToSameVariable($child->result, $var)
                    );
                }
                if (!$matches) {
                    continue;
                }
                if ('' === $file) {
                    $file = (string) ($child->getFile() ?? '');
                }
                if ($line <= 0) {
                    $line = (int) $child->getLine();
                }
                if ('' !== $file && $line > 0) {
                    break;
                }
            }
        }
        if ('' === $file && null !== $block) {
            $file = $block->scriptPath();
        }
        if ('' === $file) {
            $file = $this->debugLastPhaseInputFile ?? 'unknown';
        }
        if ('' === $file) {
            $file = 'unknown';
        }

        return [$file, max(1, $line)];
    }

    /**
     * Zend-shaped temporary write-context compile fatal (not parseAndCompile wrapper) (#29769).
     *
     * @return never
     */
    protected function throwWriteContextCompileFatal(
        string $message,
        ?Operand $var = null,
        ?Block $block = null,
        ?Op $siteOp = null,
    ): void {
        [$file, $line] = $this->resolveWriteContextFatalSite($var, $block, $siteOp);
        $this->throwCompileError($message, $file, $line);
    }

    /**
     * @return never
     */
    protected function rejectGlobalConstInWriteContext(?Operand $var, ?Block $block = null, ?Op $siteOp = null): void
    {
        if (!$this->lvalueContainsGlobalConstFetch($var, $block)) {
            return;
        }
        $this->throwWriteContextCompileFatal(
            'Cannot use temporary expression in write context',
            $var,
            $block,
            $siteOp,
        );
    }

    /**
     * True when $operand is an inline Op\Expr\Array_ result (php-cfg may omit ->original).
     */
    protected function operandIsArrayLiteral(?Operand $operand, ?Block $block = null): bool
    {
        if (null === $operand) {
            return false;
        }
        if (null !== $this->unwrapArrayLiteralExpr($operand)) {
            return true;
        }
        if (null === $block) {
            return false;
        }

        return null !== $this->findArrayExprForResult($operand, $block);
    }

    /**
     * Zend zend_compile.c: dim/append/unset on an array literal is a temporary write (#29247).
     *
     * Function-return dims remain writable (f()[0] = …); only inline Expr_Array bases are rejected.
     */
    protected function lvalueContainsArrayLiteral(?Operand $operand, ?Block $block = null): bool
    {
        if (null === $operand || null === $block) {
            return false;
        }
        if ($operand instanceof Operand\Temporary && null !== $operand->original) {
            if ($operand->original instanceof Op\Expr\PropertyFetch) {
                $propFetch = $operand->original;
                if ($this->operandIsArrayLiteral($propFetch->var, $block)) {
                    return true;
                }

                return $this->lvalueContainsArrayLiteral($propFetch->var, $block);
            }
            if ($operand->original instanceof Op\Expr\ArrayDimFetch) {
                $dimFetch = $operand->original;
                if ($this->operandIsArrayLiteral($dimFetch->var, $block)) {
                    return true;
                }

                return $this->lvalueContainsArrayLiteral($dimFetch->var, $block);
            }

            return $this->lvalueContainsArrayLiteral($operand->original, $block);
        }
        if (null !== $block->orig) {
            $propFetch = $this->findPropertyFetchForResult($operand, $block);
            if (null !== $propFetch) {
                if ($this->operandIsArrayLiteral($propFetch->var, $block)) {
                    return true;
                }

                return $this->lvalueContainsArrayLiteral($propFetch->var, $block);
            }
            $dimFetch = $this->findArrayDimFetchForResult($operand, $block);
            if (null !== $dimFetch) {
                if ($this->operandIsArrayLiteral($dimFetch->var, $block)) {
                    return true;
                }

                return $this->lvalueContainsArrayLiteral($dimFetch->var, $block);
            }
        }

        return $this->operandIsArrayLiteral($operand, $block);
    }

    /**
     * @return never
     */
    protected function rejectArrayLiteralInWriteContext(?Operand $var, ?Block $block = null, ?Op $siteOp = null): void
    {
        if (!$this->lvalueContainsArrayLiteral($var, $block)) {
            return;
        }
        $this->throwWriteContextCompileFatal(
            'Cannot use temporary expression in write context',
            $var,
            $block,
            $siteOp,
        );
    }

    /**
     * Shared write-context guards for Assign / unset / FETCH_*_W (incl. by-ref call args) (#29522).
     *
     * Function-return dims remain writable (f(g()[0]) when g returns by value) — only temporary
     * array literals / new / const / bare call returns are rejected.
     */
    protected function rejectTemporaryExpressionInWriteContext(?Operand $var, ?Block $block = null, ?Op $siteOp = null): void
    {
        $this->rejectNewExprInWriteContext($var, $block, null, null, $siteOp);
        $this->rejectArrayLiteralInWriteContext($var, $block, $siteOp);
        $this->rejectGlobalConstInWriteContext($var, $block, $siteOp);
        $this->rejectCallReturnInWriteContext($var, $block, $siteOp);
    }

    /**
     * Zend zend_compile.c: SEND_REF of temporary lit-dim / new-prop / const is illegal (#29522).
     *
     * Function-return dims remain allowed (f(g()[0])); do not call rejectCallReturnInWriteContext.
     */
    protected function rejectTemporaryByRefCallArg(?Operand $arg, ?Block $block = null, ?Op $siteOp = null): void
    {
        $this->rejectNewExprInWriteContext($arg, $block, null, null, $siteOp);
        $this->rejectArrayLiteralInWriteContext($arg, $block, $siteOp);
        $this->rejectGlobalConstInWriteContext($arg, $block, $siteOp);
    }

    /**
     * Zend zend_compile.c: assigning to a property/offset of a `new` temporary is illegal (#6691).
     */
    protected function lvalueContainsNewExpr(?Operand $operand, ?Block $block = null): bool
    {
        if (null === $operand || null === $block) {
            return false;
        }
        if ($operand instanceof Operand\Temporary && null !== $operand->original) {
            if ($operand->original instanceof Op\Expr\PropertyFetch) {
                $propFetch = $operand->original;
                if ($this->operandDerivesFromNew($propFetch->var, $block)) {
                    return true;
                }

                return $this->lvalueContainsNewExpr($propFetch->var, $block);
            }
            if ($operand->original instanceof Op\Expr\ArrayDimFetch) {
                $dimFetch = $operand->original;
                if ($this->operandDerivesFromNew($dimFetch->var, $block)) {
                    return true;
                }

                return $this->lvalueContainsNewExpr($dimFetch->var, $block);
            }

            return $this->lvalueContainsNewExpr($operand->original, $block);
        }
        if (null !== $block->orig) {
            $propFetch = $this->findPropertyFetchForResult($operand, $block);
            if (null !== $propFetch) {
                if ($this->operandDerivesFromNew($propFetch->var, $block)) {
                    return true;
                }

                return $this->lvalueContainsNewExpr($propFetch->var, $block);
            }
            $dimFetch = $this->findArrayDimFetchForResult($operand, $block);
            if (null !== $dimFetch) {
                if ($this->operandDerivesFromNew($dimFetch->var, $block)) {
                    return true;
                }

                return $this->lvalueContainsNewExpr($dimFetch->var, $block);
            }
        }

        return $this->operandDerivesFromNew($operand, $block);
    }

    /**
     * @return never
     */
    protected function rejectNewExprInWriteContext(
        ?Operand $var,
        ?Block $block = null,
        ?Operand $assignExpr = null,
        ?Op $assignOp = null,
        ?Op $siteOp = null,
    ): void {
        if (!$this->lvalueContainsNewExpr($var, $block)) {
            return;
        }
        $site = $siteOp ?? $assignOp;
        if (null !== $assignExpr && null !== $block && null !== $this->findArrayDimFetchForResult($assignExpr, $block)) {
            if ($assignOp instanceof Op\Expr\Assign) {
                $this->throwListDestructNonWritableWriteFatal($assignOp);
            }
            $this->throwWriteContextCompileFatal(
                'Assignments can only happen to writable values',
                $var,
                $block,
                $site,
            );
        }
        $this->throwWriteContextCompileFatal(
            'Cannot use temporary expression in write context',
            $var,
            $block,
            $site,
        );
    }

    /**
     * True when $op is a call whose return may not be used as a write target (zend_compile.c).
     */

}
