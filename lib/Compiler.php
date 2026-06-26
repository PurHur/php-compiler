<?php

/*
 * This file is part of PHP-Compiler, a PHP CFG Compiler for PHP code
 *
 * @copyright 2015 Anthony Ferrara. All rights reserved
 * @license MIT See LICENSE at the root of the project for more info
 */

namespace PHPCompiler;

require_once __DIR__.'/OpCodeNames.php';

use SplObjectStorage;
use PHPCfg\Func as CfgFunc;
use PHPCfg\Op;
use PHPCfg\Block as CfgBlock;
use PHPCfg\ErrorSuppressBlock;
use PHPCfg\Operand;
use PHPCfg\Operand\BoundVariable;
use PHPCfg\Operand\Literal;
use PHPCfg\Operand\Temporary;
use PHPCfg\Operand\Variable as CfgVariable;
use PHPCfg\Script;
use PHPTypes\Type;
use PHPCompiler\VM\AttributeSupport;
use PHPCompiler\VM\ClassConstExpr;
use PHPCompiler\VM\ClassEntry;
use PHPCompiler\VM\EnumCaseSupport;
use PHPCompiler\VM\EnumSupport;
use PHPCompiler\VM\HashTable;
use PHPCompiler\VM\TypeCheck;
use PHPCompiler\VM\Variable;
use PHPCompiler\VM\ClassReadonly;
use PHPCompiler\JIT\OperandName;
use PHPCompiler\Ast\AsymmetricVisibilityRewriter;
use PHPCompiler\Ast\GeneratorYieldSourceMarker;
use PHPCompiler\Compiler\AbstractMethodVisibilityCheck;
use PHPCompiler\Compiler\InterfaceConstVisibilityCheck;
use PHPCompiler\Compiler\InterfaceMethodVisibilityCheck;
use PHPCompiler\Compiler\EnumAbstractMethodCompileCheck;
use PHPCompiler\Compiler\ClassConstDuplicateCheck;
use PHPCompiler\Compiler\EnumBackedCaseCheck;
use PHPCompiler\Compiler\EnumMagicMethodCheck;
use PHPCompiler\Compiler\EnumParentCompileCheck;
use PHPCompiler\Compiler\MagicMethodReturnTypeCheck;
use PHPCompiler\Compiler\NewWithoutParensCompileCheck;
use PHPCompiler\Compiler\ThrowInClassConstCompileCheck;
use PHPCompiler\Compiler\AsymmetricVisibilityCompileCheck;
use PHPCompiler\Compiler\CompileFatal;
use PHPCompiler\Compiler\AttributeClassRegistry;
use PHPCompiler\Compiler\AttributeEntry;
use PHPCompiler\Compiler\AttributeMetadata;
use PHPCompiler\Compiler\AttributeNames;
use PHPCompiler\Compiler\AttributeTargetValidator;
use PHPCompiler\Compiler\DeprecatedMetadata;
use PHPCompiler\Compiler\NoDiscardMetadata;
use PHPCompiler\Compiler\FinalClassConstCheck;
use PHPCompiler\Compiler\TraitClassConstConflictCheck;
use PHPCompiler\Compiler\FinalClassExtensionCheck;
use PHPCompiler\Compiler\FinalMethodOverrideCheck;
use PHPCompiler\Compiler\InterfaceImplementationCheck;
use PHPCompiler\Compiler\ParameterMetadata;
use PHPCompiler\Compiler\GeneratorNeverReturnCompileCheck;
use PHPCompiler\Compiler\GeneratorStaticMethodCompileCheck;
use PHPCompiler\Compiler\ReadonlyClassCompileCheck;
use PHPCompiler\Compiler\SourceLocation;
use PHPCompiler\Compiler\TraitCollisionCheck;
use PHPCompiler\Compiler\TypedClassConstInheritCheck;
use PHPCompiler\Compiler\ClassCompileRegistry;
use PHPCompiler\Compiler\OverrideValidator;
use PHPCompiler\Web\ConstStringFolder;
use PHPCompiler\Web\IncludePathResolver;
use PHPCompiler\Web\Superglobals;

class Compiler {

    protected ?SplObjectStorage $seen = null;
    protected ?SplObjectStorage $funcs = null;

    /** @var SplObjectStorage<CfgBlock, SplObjectStorage<CfgVariable, int>> ?: merge var slots (#3790) */
    private SplObjectStorage $ternaryMergeVarSlots;

    /** @var SplObjectStorage<CfgBlock, int> ?: assign-phi RHS slot from first lowered arm (#9159) */
    private SplObjectStorage $ternaryMergePhiRhsSlots;

    /** @var SplObjectStorage<Op\Stmt\JumpIf, true> ?: return `null !== $p ? $p : null` rewritten (#8563) */
    private SplObjectStorage $rewrittenNeNullReturnJumpIf;

    private ?string $debugLastPhaseInputFile = null;
    /** Source text for the current compile() call — `new Foo()` paren detection (#9116). */
    private ?string $compileSourceCode = null;
    private int $debugLastPhaseCounter = 0;
    private ?string $debugLastPhaseKey = null;

    /** Set from the first compile-time abort (#2642, self-host diagnostics). */
    private ?string $compileAbortDetail = null;

    /** While compiling an arrow function CFG for implicit outer captures (#10304). */
    private bool $compilingArrowAutoCapture = false;

    /** @var array<string, true> lowercase abstract class names seen during compile (#3385). */
    private array $abstractClasses = [];

    /** @var array<string, array<string, array<string, mixed>>> from PropertyHooks preprocessor (#6770). */
    private array $propertyHookRegistry = [];
    /** @var array<string, true> lowercase abstract enum names for instantiate diagnostics (#3737). */
    private array $abstractEnums = [];
    /** 1-based source lines lowered from bare `throw;` (#3508). */
    private array $bareRethrowLines = [];
    /** spl_object_id(Coalesce expr) => scope slot for ?? result (stmt ?? before call args, #9479). */
    private array $coalesceResultSlots = [];
    /** Trailing source bytes after __halt_compiler(); (issue #3479). */
    private ?string $haltCompilerRemaining = null;
    /** {@see OpCode::ASSIGN_REF_FOREACH_PROPERTY_HOOK} for the next AssignRef compile (#6435). */
    private int $assignRefBindRefFlags = 0;

    /** Byte offset where halt trailing data starts; null when no __halt_compiler() (#5455). */
    private ?int $haltCompilerOffset = null;

    /** Lowercase class name while compiling a class body (#3803). */
    private ?string $compilingClassLc = null;

    /** Display class name while compiling a class body (#4286). */
    private ?string $compilingClassDisplayName = null;

    /** @var array<string, true> instance property names declared in the current class body (#4286) */
    private array $compilingClassInstancePropertyNames = [];

    /** @var array<string, true> lowercase method names declared in the current class/interface/enum body (#5218) */
    private array $compilingClassMethodNames = [];

    /** @var array<string, array<string, Variable>> compile-time class constants by lc name */
    private array $compileTimeClassConsts = [];

    /** @var array<string, array<string, int>> compile-time class constant visibility flags by lc name (#6784) */
    private array $compileTimeClassConstVisibility = [];

    /** @var array<string, array<string, DeprecatedMetadata>> deprecated class constants by lc name (#6962) */
    private array $compileTimeClassConstDeprecated = [];

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

    /** @var array<string, true> lowercase user function names declared `: never` (#4117). */
    private array $neverFunctionNames = [];

    /** True while lowering switch to JUMPIF/EQUAL — skip ?: merge slot bridging (#878). */
    private bool $compilingSwitchJumpIfChain = false;

    /** Force FUNCCALL_EXEC_RETURN while lowering hoisted sibling call-arg producers (#10981). */
    private bool $forceDeferredSiblingCallReturnSlot = false;

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
        $v = $_SERVER['PHP_COMPILER_DEBUG_LAST_PHASE'] ?? $_ENV['PHP_COMPILER_DEBUG_LAST_PHASE'] ?? getenv('PHP_COMPILER_DEBUG_LAST_PHASE');
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
        $explicit = $_SERVER['PHP_COMPILER_DEBUG_LAST_PHASE_FILE'] ?? $_ENV['PHP_COMPILER_DEBUG_LAST_PHASE_FILE'] ?? getenv('PHP_COMPILER_DEBUG_LAST_PHASE_FILE');
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
            $fromSource = getenv('PHP_COMPILER_M3_SOURCE');
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
            : ($_SERVER['PHP_COMPILER_DEBUG_LAST_PHASE_STDERR'] ?? $_ENV['PHP_COMPILER_DEBUG_LAST_PHASE_STDERR'] ?? getenv('PHP_COMPILER_DEBUG_LAST_PHASE_STDERR'));
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
    protected function throwCompileError(string $detail): void
    {
        if (null === $this->compileAbortDetail) {
            $this->compileAbortDetail = $detail;
        }

        throw new \CompileError($detail);
    }

    public function compile(Script $script): ?Block {
        $this->resetCompileAbortDetail();
        $this->abstractClasses = [];
        $this->abstractEnums = [];
        $this->coalesceResultSlots = [];
        $this->compileTimeEnumBackedTypes = [];
        $this->compileTimeEnumCaseConstNames = [];
        $this->compileTimeGlobalConsts = [];
        $this->haltCompilerRemaining = null;
        $this->haltCompilerOffset = null;
        $this->compiledClassStaticProperties = [];
        $this->currentClassStaticPropertyCompile = null;
        $this->neverFunctionNames = [];
        $this->scriptHasDnfTypedProperties = false;
        $this->classCompileRegistry = new ClassCompileRegistry();
        $this->attributeClassRegistry = new AttributeClassRegistry();
        $this->seen = new SplObjectStorage;
        $this->ternaryMergeVarSlots = new SplObjectStorage;
        $this->ternaryMergePhiRhsSlots = new SplObjectStorage;
        $this->rewrittenNeNullReturnJumpIf = new SplObjectStorage;
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

        InterfaceImplementationCheck::validate($script, $this->propertyHookRegistry);
        TraitCollisionCheck::validate($script);
        FinalClassExtensionCheck::validate($script);
        FinalMethodOverrideCheck::validate($script);
        OverrideValidator::validateScript($script);
        FinalClassConstCheck::validate($script);
        TraitClassConstConflictCheck::validate($script);
        TypedClassConstInheritCheck::validate($script);
        InterfaceConstVisibilityCheck::validate($script);
        InterfaceMethodVisibilityCheck::validate($script);
        AbstractMethodVisibilityCheck::validate($script);
        MagicMethodReturnTypeCheck::validate($script);
        EnumMagicMethodCheck::validate($script);
        EnumAbstractMethodCompileCheck::validate($script);
        EnumParentCompileCheck::validate($script);
        EnumBackedCaseCheck::validate($script);
        ClassConstDuplicateCheck::validate($script);
        ReadonlyClassCompileCheck::validate($script, $this->knownClassReadonly);
        AsymmetricVisibilityCompileCheck::validate($script);
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
        $this->abstractClasses = [];
        $this->abstractEnums = [];
        $this->coalesceResultSlots = [];
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
        if (null === $returnType) {
            return;
        }
        $this->assertFunctionSignatureNeverType($returnType);
        $this->assertNoRedundantTrueFalseUnion($returnType);
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
                $block->returnTypeConstraint = Variable::TYPE_OBJECT;
                $block->returnClassConstraint = $refName;
                $block->returnDeclaredTypeLabel = ltrim($refName, '\\');

                return;
            }
        }
        if ($this->cfgTypeUsesDnfShape($returnType)) {
            $dnfArms = DnfType::armsFromCfgType(
                $returnType,
                fn (Op\Type\Intersection $t) => $this->intersectionNamesFromCfgType($t),
                fn (Op\Type\Intersection $t) => $this->intersectionDisplayFromCfgType($t),
                fn (Op\Type\Reference $t) => $this->staticNameFromCfgType($t)
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
                return;
            }
            $returnLc = strtolower($returnType->name);
            if ('true' === $returnLc || 'false' === $returnLc) {
                $block->returnTypeConstraint = Variable::TYPE_BOOLEAN;
                $block->returnLiteralBoolType = $returnLc;

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
     * @param list<Op\Expr\Param> $params
     */
    protected function assertNoDuplicateParameterNames(array $params): void
    {
        $seen = [];
        foreach ($params as $param) {
            if (!($param->name instanceof Operand\Literal) || !is_string($param->name->value)) {
                continue;
            }
            $name = $param->name->value;
            if (isset($seen[$name])) {
                $this->throwCompileError(sprintf('Redefinition of parameter $%s', $name));
            }
            $seen[$name] = true;
        }
    }

    /**
     * @param list<Op\Expr\Param> $params
     */
    protected function assertNoDuplicateParameterAttributes(array $params): void
    {
        foreach ($params as $param) {
            $entries = AttributeMetadata::fromOp($param);
            $names = AttributeEntry::namesFromList($entries);
            AttributeNames::assertAllowDynamicPropertiesClassTargetOnly($names, 'parameter');
            AttributeNames::assertOverrideMethodTargetOnly($names, 'parameter');
            AttributeNames::assertCompileTimeConstTargetOnly($names, 'parameter');
            AttributeNames::assertSensitiveParameterParamTargetOnly($names, 'parameter');
            AttributeNames::validateDuplicates($entries, $this->attributeClassRegistry);
        }
    }

    /**
     * php-src: Zend/zend_compile.c — readonly on parameters is only valid in __construct (#6291).
     *
     * @param list<Op\Expr\Param> $params
     */
    protected function assertReadonlyParamOnlyInConstructor(array $params, ?CfgFunc $func): void
    {
        if (null !== $func && '__construct' === $func->name && null !== $func->class) {
            return;
        }
        foreach ($params as $param) {
            if (!$this->isPromotedParamReadonly($param)) {
                continue;
            }
            $this->throwCompileError('Cannot declare promoted property outside a constructor');
        }
    }

    protected function compileCfgBlock(CfgBlock $block, array $params = [], ?CfgFunc $func = null): Block {
        if (null === $this->seen) {
            $this->seen = new SplObjectStorage;
        }
        if (!$this->seen->contains($block)) {
            $this->seen[$block] = $new = new Block($block);
            if ($this->compilingArrowAutoCapture) {
                $new->arrowAutoCapture = true;
            }
            if (null !== $func) {
                $new->func = $func;
                $new->strictTypes = isset($func->strictTypes) ? (bool) $func->strictTypes : false;
                $this->applyReturnTypeFromFunc($new, $func);
            }
            if ([] !== $params) {
                $this->assertNoDuplicateParameterNames($params);
                $this->assertNoDuplicateParameterAttributes($params);
                $this->assertReadonlyParamOnlyInConstructor($params, $func);
            }
            $paramIdx = 0;
            foreach ($params as $param) {
                $new->addOpCode($this->compileParam($param, $new, $paramIdx++));
            }
            if (null !== $func && '__construct' === $func->name && null !== $func->class) {
                $this->compileCtorPromotionAssignments($new, $params);
            }
            $this->compileBlock($new);
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
     * @param list<Op\Expr\Param> $params
     */
    protected function compileEmptyConcreteMethodBlock(array $params, ?CfgFunc $func): Block
    {
        $block = new Block(null);
        if (null !== $func) {
            $block->func = $func;
            $block->strictTypes = isset($func->strictTypes) ? (bool) $func->strictTypes : false;
            $this->applyReturnTypeFromFunc($block, $func);
        }
        if ([] !== $params) {
            $this->assertNoDuplicateParameterNames($params);
            $this->assertNoDuplicateParameterAttributes($params);
            $this->assertReadonlyParamOnlyInConstructor($params, $func);
        }
        $paramIdx = 0;
        foreach ($params as $param) {
            $block->addOpCode($this->compileParam($param, $block, $paramIdx++));
        }
        if (null !== $func && '__construct' === $func->name && null !== $func->class) {
            $this->compileCtorPromotionAssignments($block, $params);
        }
        $block->addOpCode(new OpCode(OpCode::TYPE_RETURN_VOID));

        return $block;
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
            if (\count($block->parents) < 2) {
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
            $child->returnDeclaredType = $parent->returnDeclaredType;
            $child->returnLiteralBoolType = $parent->returnLiteralBoolType;
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
                $compiled->inheritCfgVarSlotsFrom($this->seen[$siblingCfg]);
            }
        }
    }

    /**
     * @return list<CfgBlock>
     */
    private function ternaryMergeTargets(CfgBlock $branchCfg): array
    {
        $merges = [];
        foreach ($branchCfg->children as $child) {
            if (!$child instanceof Op\Stmt\Jump) {
                continue;
            }
            $merge = $child->target;
            if (\count($merge->parents) >= 2) {
                $merges[] = $merge;
            }
        }

        return $merges;
    }

    /** CFG block jumped to at the end of a ?: / if branch (may have one parent while lowering). */
    private function branchJumpMergeTarget(CfgBlock $branchCfg): ?CfgBlock
    {
        foreach ($branchCfg->children as $child) {
            if ($child instanceof Op\Stmt\Jump) {
                return $child->target;
            }
        }

        return null;
    }

    /** Foreach loop heads use Iterator_Valid — not ?: merge blocks (#5657). */
    private function isForeachIteratorHeaderCfgBlock(CfgBlock $cfg): bool
    {
        foreach ($cfg->children as $child) {
            if ($child instanceof Op\Iterator\Valid) {
                return true;
            }
        }

        return false;
    }

    /** Both ?: arms jump to the same CFG merge block (echo/assign phi, #3790, #5510). */
    private function jumpIfTargetsTernaryMerge(Op\Stmt\JumpIf $stmt): bool
    {
        $ifMerge = $this->branchJumpMergeTarget($stmt->if);
        $elseMerge = $this->branchJumpMergeTarget($stmt->else);
        if (null === $ifMerge || $ifMerge !== $elseMerge) {
            return false;
        }
        if (\count($ifMerge->parents) < 2) {
            return false;
        }

        return $this->mergeCfgBlockUsesTernaryPhi($ifMerge);
    }

    /** Both ?: arms jump to a merge block ending in RETURN (#4280, #8563). */
    private function jumpIfTargetsReturnMerge(Op\Stmt\JumpIf $stmt): bool
    {
        $ifMerge = $this->branchJumpMergeTarget($stmt->if);
        $elseMerge = $this->branchJumpMergeTarget($stmt->else);
        if (null === $ifMerge || $ifMerge !== $elseMerge) {
            return false;
        }
        foreach ($ifMerge->children as $child) {
            if ($child instanceof Op\Terminal\Return_) {
                return true;
            }
        }

        return false;
    }

    /**
     * `null !== $param ? $param : null` mis-lowers in AOT when the param arm is if-entry (#8563).
     * Rewrite to `null === $param ? null : $param` at compile time (php-src equivalent).
     */
    private function shouldRewriteNullableNeNullReturnTernary(
        Op\Stmt\JumpIf $stmt,
        ?Op\Expr\BinaryOp\NotIdentical $ne = null
    ): bool {
        if (!$this->jumpIfTargetsReturnMerge($stmt)) {
            return false;
        }
        if (null !== $ne) {
            return $this->branchCfgAssignsNullConst($stmt->else)
                && $this->branchCfgAssignsNonNullValue($stmt->if)
                && ($this->operandIsNull($ne->left) || $this->operandIsNull($ne->right));
        }
        $ne = $this->unwrapOperandToNotIdentical($stmt->cond);
        if (null === $ne) {
            return false;
        }

        return $this->branchCfgAssignsNullConst($stmt->else)
            && $this->branchCfgAssignsNonNullValue($stmt->if)
            && ($this->operandIsNull($ne->left) || $this->operandIsNull($ne->right));
    }

    private function operandIsNull(Operand $operand): bool
    {
        if ($operand->type instanceof Type && Type::TYPE_NULL === $operand->type->type) {
            return true;
        }

        return $this->exprIsNullConst($operand);
    }

    private function unwrapOperandToNotIdentical(Operand $operand): ?Op\Expr\BinaryOp\NotIdentical
    {
        while ($operand instanceof Operand\Temporary) {
            if ($operand->original instanceof Op\Expr\BinaryOp\NotIdentical) {
                return $operand->original;
            }
            if (null === $operand->original) {
                return null;
            }
            $operand = $operand->original;
        }

        return $operand instanceof Op\Expr\BinaryOp\NotIdentical ? $operand : null;
    }

    private function branchCfgAssignsNullConst(CfgBlock $branchCfg): bool
    {
        foreach ($branchCfg->children as $child) {
            if ($child instanceof Op\Expr\Assign && null !== $child->expr && $this->operandIsNull($child->expr)) {
                return true;
            }
        }

        return false;
    }

    private function exprIsNullConst(Operand $expr): bool
    {
        while ($expr instanceof Operand\Temporary && null !== $expr->original) {
            if ($expr->original instanceof Op\Expr\ConstFetch) {
                return $this->constFetchIsNull($expr->original);
            }
            $expr = $expr->original;
        }

        return $expr instanceof Op\Expr\ConstFetch && $this->constFetchIsNull($expr);
    }

    private function constFetchIsNull(Op\Expr\ConstFetch $fetch): bool
    {
        $name = $fetch->name;
        while ($name instanceof Operand\Temporary && null !== $name->original) {
            $name = $name->original;
        }

        return $name instanceof Operand\Literal
            && 'null' === strtolower((string) $name->value);
    }

    private function branchCfgAssignsNonNullValue(CfgBlock $branchCfg): bool
    {
        foreach ($branchCfg->children as $child) {
            if ($child instanceof Op\Expr\Assign && !$this->exprIsNullConst($child->expr)) {
                return true;
            }
        }

        return false;
    }

    private function funcReturnTypeIsNullableScalar(Block $block): bool
    {
        if (null === $block->func) {
            return false;
        }
        $returnType = $block->func->returnType;

        return $returnType instanceof Op\Type\Nullable;
    }

    private function operandIsImplicitNullableParam(Operand $operand, Block $block): bool
    {
        if (!$operand instanceof CfgVariable) {
            return false;
        }
        if (null === $block->func) {
            return false;
        }
        $varName = $operand->name;
        while ($varName instanceof Temporary && null !== $varName->original) {
            $varName = $varName->original;
        }
        if (!$varName instanceof Literal || !is_string($varName->value)) {
            return false;
        }
        foreach ($block->func->params as $param) {
            $paramName = $param->name;
            while ($paramName instanceof Temporary && null !== $paramName->original) {
                $paramName = $paramName->original;
            }
            if (!$paramName instanceof Literal || $paramName->value !== $varName->value) {
                continue;
            }
            if ($param->declaredType instanceof Op\Type\Nullable) {
                return true;
            }
            $slot = $block->slotForOperand($param->result);

            return null !== $slot && isset($block->paramImplicitNullable[$slot]);
        }

        return false;
    }

    /** `return (null ?… $param : null)` / `return (null !== $param ? $param : null)` → `$param ?? null` (#8563). */
    private function nullableParamFromReturnTernaryArms(Op\Stmt\JumpIf $stmt, Block $block): ?Operand
    {
        if (!$this->jumpIfTargetsReturnMerge($stmt) || !$this->funcReturnTypeIsNullableScalar($block)) {
            return null;
        }
        $ifNull = $this->branchCfgAssignsNullConst($stmt->if);
        $elseNull = $this->branchCfgAssignsNullConst($stmt->else);
        if ($ifNull === $elseNull) {
            return null;
        }
        $valueBranch = $ifNull ? $stmt->else : $stmt->if;
        foreach ($valueBranch->children as $child) {
            if (!$child instanceof Op\Expr\Assign || null === $child->expr || $this->exprIsNullConst($child->expr)) {
                continue;
            }
            $src = $child->expr;
            while ($src instanceof Temporary && null !== $src->original) {
                $src = $src->original;
            }
            if ($src instanceof CfgVariable && $this->operandIsImplicitNullableParam($src, $block)) {
                return $src;
            }
        }

        return null;
    }

    private function syntheticNullConstOperand(): Operand
    {
        $nullLit = new Literal(null);
        $nullLit->type = Type::null();

        return $nullLit;
    }

    /** AOT-safe lowering: implicit nullable param returns via ?? null (proven native ABI path). */
    private function emitImplicitNullableParamCoalesceReturn(Operand $paramOp, Block $block): void
    {
        $coalesce = new Op\Expr\BinaryOp\Coalesce($paramOp, $this->syntheticNullConstOperand());
        $endBlock = $this->compileCoalesce($coalesce, $block);
        $endBlock->addOpCode(new OpCode(
            OpCode::TYPE_RETURN,
            $this->compileOperand($coalesce->result, $endBlock, true)
        ));
    }

    /** `?:` in `echo`/concat merge uses echo phi slots; `return ?:` uses RETURN (#4280); `throw ?:` uses TYPE_THROW (#7037). */
    private function mergeCfgBlockUsesEchoPhi(CfgBlock $merge): bool
    {
        foreach ($merge->children as $child) {
            if ($child instanceof Op\Terminal\Echo_) {
                return true;
            }
            if ($child instanceof Op\Terminal\Throw_) {
                return true;
            }
        }

        return false;
    }

    /** `$s = $cond ? $a : $b` merge assigns a shared phi temporary into a named local (#9159). */
    private function mergeCfgBlockUsesAssignPhi(CfgBlock $merge): bool
    {
        if (\count($merge->parents) < 2) {
            return false;
        }
        foreach ($merge->children as $child) {
            if (!$child instanceof Op\Expr\Assign) {
                continue;
            }
            $destRoot = Block::cfgVarRoot($child->var);
            if (!$destRoot instanceof Operand\Variable) {
                continue;
            }
            $phiOperand = $child->expr;
            if (!$phiOperand instanceof Operand) {
                continue;
            }
            $matchedParents = 0;
            foreach ($merge->parents as $parent) {
                $armVar = $this->mergeBranchAssignVarOperand($parent);
                if (null === $armVar) {
                    continue;
                }
                if ($this->operandsReferToSameVariable($armVar, $phiOperand)) {
                    ++$matchedParents;
                }
            }
            if ($matchedParents >= 2) {
                return true;
            }
        }

        return false;
    }

    private function mergeCfgBlockUsesTernaryPhi(CfgBlock $merge): bool
    {
        return $this->mergeCfgBlockUsesEchoPhi($merge)
            || $this->mergeCfgBlockUsesAssignPhi($merge)
            || $this->mergeCfgBlockUsesLogicalShortCircuit($merge);
    }

    /** && / || merge: one arm ends in (bool) cast, sibling in literal assign (php-cfg parseShortCircuiting). */
    private function mergeCfgBlockUsesLogicalShortCircuit(CfgBlock $merge): bool
    {
        if (\count($merge->parents) < 2) {
            return false;
        }
        $hasBoolCastArm = false;
        $hasLiteralAssignArm = false;
        foreach ($merge->parents as $parent) {
            $tail = $this->branchTailExprBeforeJump($parent);
            if ($tail instanceof Op\Expr\Cast\Bool_) {
                $hasBoolCastArm = true;
            }
            if ($tail instanceof Op\Expr\Assign && $tail->expr instanceof Operand\Literal) {
                $hasLiteralAssignArm = true;
            }
        }

        return $hasBoolCastArm && $hasLiteralAssignArm;
    }

    private function branchTailExprBeforeJump(CfgBlock $branch): ?Op\Expr
    {
        $children = $branch->children;
        for ($i = \count($children) - 1; $i >= 0; --$i) {
            $child = $children[$i];
            if ($child instanceof Op\Stmt\Jump) {
                continue;
            }
            if ($child instanceof Op\Expr) {
                return $child;
            }

            break;
        }

        return null;
    }

    /** && / || long-arm bool cast must store into the recorded phi merge slot (#10626). */
    private function logicalShortCircuitPhiMergeSlot(Block $branch): ?int
    {
        if (null === $branch->orig) {
            return null;
        }
        foreach ($this->ternaryMergeTargets($branch->orig) as $mergeCfg) {
            if (!$this->mergeCfgBlockUsesLogicalShortCircuit($mergeCfg)) {
                continue;
            }
            $recorded = $this->ternaryMergePhiRhsSlot($mergeCfg);
            if (null !== $recorded) {
                return $recorded;
            }
            foreach ($mergeCfg->parents as $parentCfg) {
                if ($parentCfg === $branch->orig || !$this->seen->contains($parentCfg)) {
                    continue;
                }
                $sibling = $this->seen[$parentCfg];
                for ($i = $sibling->nOpCodes - 1; $i >= 0; --$i) {
                    $op = $sibling->opCodes[$i];
                    if (OpCode::TYPE_ASSIGN === $op->type) {
                        return (int) $op->arg2;
                    }
                    if (OpCode::TYPE_JUMP === $op->type) {
                        break;
                    }
                }
            }
        }

        return null;
    }

    /** exit($a && $b) ? … — dead call-arg temp must use && phi / parent cast slot (#11592). */
    private function resolveExitLogicalShortCircuitCallArgSlot(Block $block): ?string
    {
        $phi = $this->logicalShortCircuitPhiMergeSlot($block);
        if (null !== $phi) {
            return (string) $phi;
        }
        if (null === $block->orig) {
            return null;
        }
        foreach ($block->orig->parents as $parentCfg) {
            if (!$this->seen->contains($parentCfg)) {
                continue;
            }
            $parentBlock = $this->seen[$parentCfg];
            for ($i = $parentBlock->nOpCodes - 1; $i >= 0; --$i) {
                $op = $parentBlock->opCodes[$i];
                if (OpCode::TYPE_JUMP === $op->type) {
                    continue;
                }
                if (OpCode::TYPE_CAST_BOOL === $op->type) {
                    return (string) $op->arg1;
                }
                if (OpCode::TYPE_ASSIGN === $op->type) {
                    return (string) $op->arg2;
                }
                break;
            }
        }

        return null;
    }

    private function recordTernaryMergeVarSlots(CfgBlock $branchCfg, Block $compiled): void
    {
        foreach ($this->ternaryMergeTargets($branchCfg) as $mergeCfg) {
            if (!$this->ternaryMergeVarSlots->contains($mergeCfg)) {
                $this->ternaryMergeVarSlots[$mergeCfg] = new SplObjectStorage();
            }
            /** @var SplObjectStorage<CfgVariable, int> $map */
            $map = $this->ternaryMergeVarSlots[$mergeCfg];
            $phiRoot = $this->mergeBranchAssignVarRoot($branchCfg);
            $phiSlot = null;
            if (null !== $phiRoot) {
                foreach ($compiled->eachCfgVarRootSlot() as [$root, $slot]) {
                    if ($root === $phiRoot) {
                        $phiSlot = $slot;
                        break;
                    }
                }
            }
            if (null === $phiSlot && $this->seen->contains($mergeCfg)) {
                $phiSlot = $this->mergePhiResultSlot($this->seen[$mergeCfg]);
            }
            if (null !== $phiRoot && null !== $phiSlot) {
                $map[$phiRoot] = $phiSlot;

                continue;
            }
            $this->recordTernaryMergePhiRhsSlot($mergeCfg, $compiled);
            foreach ($compiled->eachCfgVarRootSlot() as [$root, $slot]) {
                if (!$map->contains($root)) {
                    $map[$root] = $slot;
                }
            }
        }
    }

    private function mergeBranchAssignVarRoot(CfgBlock $branchCfg): ?Operand\Variable
    {
        $assignVar = $this->mergeBranchAssignVarOperand($branchCfg);
        if (null === $assignVar) {
            return null;
        }
        $root = Block::cfgVarRoot($assignVar);

        return $root instanceof Operand\Variable ? $root : null;
    }

    private function mergeBranchAssignVarOperand(CfgBlock $branchCfg): ?Operand
    {
        $children = $branchCfg->children;
        $jumpIdx = null;
        foreach ($children as $i => $child) {
            if ($child instanceof Op\Stmt\Jump) {
                $jumpIdx = $i;
                break;
            }
        }
        if (null === $jumpIdx) {
            return null;
        }
        for ($i = $jumpIdx - 1; $i >= 0; --$i) {
            $child = $children[$i];
            if ($child instanceof Op\Expr\Assign) {
                return $child->var;
            }
            if (!$child instanceof Op\Expr) {
                break;
            }
        }

        return null;
    }

    private function applyTernaryMergeVarSlots(CfgBlock $branchCfg, Block $compiled): void
    {
        foreach ($this->ternaryMergeTargets($branchCfg) as $mergeCfg) {
            if (!$this->ternaryMergeVarSlots->contains($mergeCfg)) {
                continue;
            }
            /** @var SplObjectStorage<CfgVariable, int> $map */
            $map = $this->ternaryMergeVarSlots[$mergeCfg];
            foreach ($map as $root) {
                $compiled->prebindCfgVarRoot($root, $map[$root]);
            }
        }
    }

    /** When merge block is already lowered, ?: branch assigns must use its ECHO slot (#3790). */
    private function branchMergeAssignSlot(Block $branch, Op\Expr\Assign $assign): ?int
    {
        if ($this->compilingSwitchJumpIfChain) {
            return null;
        }
        if (null === $branch->orig) {
            return null;
        }
        if ($this->isPropertyWriteAssign($assign, $branch)) {
            return null;
        }
        if ($this->isArrayDimWriteAssign($assign, $branch)) {
            return null;
        }
        if (!$this->isMergeBranchAssign($branch, $assign)) {
            return null;
        }
        $mergeCfg = $this->branchJumpMergeTarget($branch->orig);
        if (null !== $mergeCfg) {
            $recordedPhi = $this->ternaryMergePhiRhsSlot($mergeCfg);
            if (null !== $recordedPhi) {
                return $recordedPhi;
            }
        }
        if (null !== $mergeCfg && $this->isForeachIteratorHeaderCfgBlock($mergeCfg)) {
            return null;
        }
        if (null !== $mergeCfg && $this->seen->contains($mergeCfg)) {
            // Echo/return ?: phi temporaries must still target the merge ECHO/RETURN slot (#3787, #4280, #5506).
            if ($assign->var instanceof Temporary && null === $this->mergeReturnSlot($this->seen[$mergeCfg])) {
                $phiSlot = $this->mergePhiResultSlot($this->seen[$mergeCfg]);
                if (null !== $phiSlot) {
                    return $phiSlot;
                }
            }
        }
        if (null === Block::cfgVarRoot($assign->var) && !$assign->var instanceof Temporary) {
            return null;
        }
        if (null !== $mergeCfg) {
            if ($this->seen->contains($mergeCfg)) {
                $phiSlot = $this->mergePhiResultSlot($this->seen[$mergeCfg]);
                if (null !== $phiSlot) {
                    return $phiSlot;
                }
            }
            $siblingSlot = $this->siblingMergeBranchAssignDestSlot($mergeCfg, $branch->orig);
            if (null !== $siblingSlot) {
                return $siblingSlot;
            }
        }
        foreach ($this->ternaryMergeTargets($branch->orig) as $mergeCfg) {
            if ($this->seen->contains($mergeCfg)) {
                $phiSlot = $this->mergePhiResultSlot($this->seen[$mergeCfg]);
                if (null !== $phiSlot) {
                    return $phiSlot;
                }
            }
            if ($this->ternaryMergeVarSlots->contains($mergeCfg)) {
                /** @var SplObjectStorage<CfgVariable, int> $map */
                $map = $this->ternaryMergeVarSlots[$mergeCfg];
                foreach ($map as $root) {
                    return $map[$root];
                }
            }
        }

        return null;
    }

    private function siblingMergeBranchAssignDestSlot(CfgBlock $mergeCfg, CfgBlock $currentBranch): ?int
    {
        foreach ($mergeCfg->parents as $parentCfg) {
            if ($parentCfg === $currentBranch || !$this->seen->contains($parentCfg)) {
                continue;
            }
            $sibling = $this->seen[$parentCfg];
            for ($i = $sibling->nOpCodes - 1; $i >= 0; --$i) {
                $op = $sibling->opCodes[$i];
                if (OpCode::TYPE_ASSIGN === $op->type) {
                    return $op->arg2;
                }
                if (OpCode::TYPE_JUMP === $op->type) {
                    break;
                }
            }
        }

        return null;
    }

    private function isMergeBranchAssign(Block $branch, Op\Expr\Assign $assign): bool
    {
        if (null === $branch->orig) {
            return false;
        }
        $expectedVar = $this->mergeBranchAssignVarOperand($branch->orig);
        if (null === $expectedVar) {
            return false;
        }

        return $this->operandsReferToSameVariable($expectedVar, $assign->var);
    }

    private function mergeEchoSlot(Block $merge): ?int
    {
        foreach ($merge->opCodes as $op) {
            if (OpCode::TYPE_ECHO === $op->type) {
                return $op->arg1;
            }
        }

        return null;
    }

    /** `$dest = $phi` merge block carries the phi slot on the assign RHS (#9159). */
    private function mergeAssignPhiRhsSlot(Block $merge): ?int
    {
        foreach ($merge->opCodes as $op) {
            if (OpCode::TYPE_ASSIGN !== $op->type || null === $op->arg3) {
                continue;
            }
            // Consecutive match() in one merge block seeds later results with literal `''`;
            // constant RHS is not a phi temp slot (#9856).
            if (isset($merge->constants[$op->arg3])) {
                continue;
            }

            return (int) $op->arg3;
        }

        return null;
    }

    private function recordTernaryMergePhiRhsSlot(CfgBlock $mergeCfg, Block $compiled): void
    {
        if ($this->ternaryMergePhiRhsSlots->contains($mergeCfg)) {
            return;
        }
        for ($i = $compiled->nOpCodes - 1; $i >= 0; --$i) {
            $op = $compiled->opCodes[$i];
            if (OpCode::TYPE_ASSIGN === $op->type) {
                $this->ternaryMergePhiRhsSlots[$mergeCfg] = (int) $op->arg2;

                return;
            }
            if (OpCode::TYPE_JUMP === $op->type) {
                break;
            }
        }
    }

    private function ternaryMergePhiRhsSlot(CfgBlock $mergeCfg): ?int
    {
        if (!$this->ternaryMergePhiRhsSlots->contains($mergeCfg)) {
            return null;
        }

        return $this->ternaryMergePhiRhsSlots[$mergeCfg];
    }

    /** `return $a ? $b : $c` merge block carries the phi slot on RETURN (#4280). */
    private function mergeReturnSlot(Block $merge): ?int
    {
        foreach ($merge->opCodes as $op) {
            if (OpCode::TYPE_RETURN === $op->type) {
                return $op->arg1;
            }
        }

        return null;
    }

    /** `throw $a ? $b : $c` merge block carries the phi slot on TYPE_THROW (#7037). */
    private function mergeThrowSlot(Block $merge): ?int
    {
        foreach ($merge->opCodes as $op) {
            if (OpCode::TYPE_THROW === $op->type) {
                return $op->arg1;
            }
        }

        return null;
    }

    private function mergePhiResultSlot(Block $merge): ?int
    {
        return $this->mergeEchoSlot($merge)
            ?? $this->mergeAssignPhiRhsSlot($merge)
            ?? $this->mergeReturnSlot($merge)
            ?? $this->mergeThrowSlot($merge);
    }

    /** ?: branch throw `new` must not reuse merge phi / echo slot (#3802). */
    private function mergeEchoSlotForBranch(Block $branch): ?int
    {
        if (null === $branch->orig) {
            return null;
        }
        foreach ($this->ternaryMergeTargets($branch->orig) as $mergeCfg) {
            if ($this->seen->contains($mergeCfg)) {
                $slot = $this->mergePhiResultSlot($this->seen[$mergeCfg]);
                if (null !== $slot) {
                    return $slot;
                }
            }
            if ($this->ternaryMergeVarSlots->contains($mergeCfg)) {
                /** @var SplObjectStorage<CfgVariable, int> $map */
                $map = $this->ternaryMergeVarSlots[$mergeCfg];
                foreach ($map as $root) {
                    return $map[$root];
                }
            }
        }

        return null;
    }

    protected function compileBlock(Block $block) {
        $this->compileOps($block->orig->children, $block);
    }

    protected function compileOps(array $ops, Block $block): void {
        // Register file-level `const` / literal define() before class bodies and
        // FUNCDEF defaults so zend_compile_default_value can fold ConstFetch (#6542).
        $this->prescanCompileTimeGlobalConsts($ops, $block);

        // Hoist class-like definitions before functions so JIT/AOT see member
        // constants when compiling FUNCDEF bodies (issue #2215, MiniWebApp Router::CONST).
        foreach ($ops as $child) {
            switch (get_class($child)) {
                case Op\Stmt\Class_::class:
                    $block->addOpCode($this->compileClassLike($child, $block));
                    break;
                case Op\Stmt\Enum_::class:
                    $block->addOpCode($this->compileEnum($child, $block));
                    break;
                case Op\Stmt\Interface_::class:
                    $block->addOpCode($this->compileInterface($child, $block));
                    break;
                case Op\Stmt\Trait_::class:
                    $block->addOpCode($this->compileTrait($child, $block));
                    break;
            }
        }
        foreach ($ops as $child) {
            switch (get_class($child)) {
                case Op\Stmt\Function_::class:
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
                if (!isset($needed[spl_object_id($resultVar)])) {
                    continue;
                }

                $slice[] = $candidate;
                unset($needed[spl_object_id($resultVar)]);
                $deferredOpIndexes[$j] = true;

                foreach ($this->nullsafePreludeOperandVars($candidate) as $dep) {
                    if ($dep instanceof \PHPCfg\Operand\Temporary) {
                        $needed[spl_object_id($dep)] = $dep;
                    }
                }
            }

            if (!empty($slice)) {
                $deferredNullsafePreludeOps[$child] = array_reverse($slice);
            }
        }
        for ($i = 0; $i < $opCount; ++$i) {
            if (isset($deferredOpIndexes[$i])) {
                continue;
            }
            $child = $ops[$i];
            $this->debugWriteLastPhase('Compiler::compileOps op', $block, $child);
            switch (get_class($child)) {
                case Op\Stmt\Function_::class:
                case Op\Stmt\Class_::class:
                case Op\Stmt\Enum_::class:
                case Op\Terminal\Const_::class:
                case Op\Stmt\Interface_::class:
                case Op\Stmt\Trait_::class:
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
                        $resultOverride = null;
                        if (
                            $i + 1 < $opCount
                            && $ops[$i + 1] instanceof Op\Expr\Assign
                            && $this->isCoalesceAssignTail($ops[$i + 1], $child)
                            && null !== $child->left
                            && $this->operandsChainEqual($ops[$i + 1]->var, $child->left)
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
                        && $this->shouldSkipNullsafePropertyFetchForIssetOrEmpty($child, $ops, $i, $block)
                    ) {
                        // Lowered by compileIssetNullsafePropertyFetchChain / compileEmptyNullsafePropertyFetchChain (#4980).
                        break;
                    } elseif ($child instanceof Op\Expr\NullsafePropertyFetch) {
                        if ($this->isNullsafePropertyFetchInWriteContext($ops, $i)) {
                            $this->throwCompileError("Can't use nullsafe operator in write context");
                        }
                        $block = $this->compileNullsafePropertyFetch($child, $block);
                    } elseif ($child instanceof Op\Expr\NullsafeMethodCall) {
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
                        && null !== ($coalesceMatch = $this->findCoalesceUsingPropertyFetchLeft($child, $ops, $i))
                    ) {
                        /** @var Op\Expr\BinaryOp\Coalesce $coalesce */
                        [$coalesce, $coalesceIndex] = $coalesceMatch;
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
                        $block = $this->compileCoalesceForAssign($coalesce, $block, $resultOverride);
                        $i = $coalesceIndex;
                        if (null !== $resultOverride) {
                            ++$i;
                        }
                        break;
                    } elseif (
                        $child instanceof Op\Expr\ArrayDimFetch
                        && $i + 1 < $opCount
                        && $this->isArrayDimFetchOnlyIssetVar($child, $ops[$i + 1])
                    ) {
                        // Lowered by compileIsset via isset(container, dim) — no eager fetch (#99, #273, #539).
                        break;
                    } elseif (
                        $child instanceof Op\Expr\ArrayDimFetch
                        && $i + 1 < $opCount
                        && $this->isArrayDimFetchOnlyUnsetVar($child, $ops[$i + 1])
                    ) {
                        // Lowered by compileTerminal Unset via TYPE_UNSET(container, dim) (#1224).
                        break;
                    } elseif (
                        $child instanceof Op\Expr\PropertyFetch
                        && $i + 1 < $opCount
                        && $this->isPropertyFetchOnlyIssetVar($child, $ops[$i + 1])
                    ) {
                        // Lowered by compileIsset via TYPE_ISSET(container, name) (#3298).
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
                    } elseif ($this->isLoweredByFollowingCoalesce($child, $ops, $i)) {
                        break;
                    } elseif ($this->isLoweredByFollowingThrow($child, $ops, $i)) {
                        break;
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
                    } elseif (
                        ($child instanceof Op\Expr\FuncCall || $child instanceof Op\Expr\NsFuncCall)
                        && $this->isDeferredSiblingInlineCallArgProducer($child, $ops, $i)
                    ) {
                        // Hoisted sibling call-arg producers compile at the consumer via
                        // resolveSiblingInlineCallArgProducerSlot (#9463, #10981).
                        break;
                    } elseif ($this->isForeachLoopVarAssignRefFusion($ops, $i)) {
                        /** @var Op\Iterator\Value $iter */
                        $iter = $ops[$i];
                        /** @var Op\Expr\AssignRef $assign */
                        $assign = $ops[$i + 1];
                        $block->addOpCode(new OpCode(
                            OpCode::TYPE_ITER_VALUE,
                            $this->compileOperand($assign->var, $block, false),
                            $this->compileOperand($iter->var, $block, true),
                            1
                        ));
                        ++$i;
                        break;
                    } elseif (
                        $child instanceof Op\Expr\ArrayDimFetch
                        && $i + 1 < $opCount
                        && $this->isArrayDimFetchOnlyEmptyVar($child, $ops[$i + 1], $block)
                    ) {
                        // Lowered by compileExpr Empty_ via TYPE_ISSET + TYPE_BOOLEAN_NOT (#5307).
                        break;
                    } elseif (
                        $child instanceof Op\Expr\PropertyFetch
                        && $i + 1 < $opCount
                        && $this->isPropertyFetchOnlyEmptyVar($child, $ops[$i + 1], $block)
                    ) {
                        // Lowered by compileExpr Empty_ via TYPE_EMPTY_OBJECT_PROPERTY (#4912).
                        break;
                    } elseif (
                        (
                            $child instanceof Op\Expr\ArrayDimFetch
                            || $this->isListSpreadAssignOp($child)
                        )
                        && $this->isListDestructGroupStart($ops, $i)
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
                        $savedAssignRefFlags = $this->assignRefBindRefFlags;
                        if (
                            $child instanceof Op\Expr\AssignRef
                            && $this->isForeachPropertyHookAssignRefPair($ops, $i)
                        ) {
                            $this->assignRefBindRefFlags = OpCode::ASSIGN_REF_FOREACH_PROPERTY_HOOK;
                        }
                        $this->compileOp($child, $block);
                        $this->assignRefBindRefFlags = $savedAssignRefFlags;
                    }
            }
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
        if ($this->isKeyedListDestructDimFetch($ops, $index)) {
            return false;
        }
        foreach ($block->opCodes as $prev) {
            if (OpCode::TYPE_INIT_ARRAY === $prev->type && null !== $prev->arg3) {
                return true;
            }
            if (OpCode::TYPE_INCLUDE === $prev->type && null !== $prev->arg2) {
                return true;
            }
        }

        return false;
    }

    /**
     * foreach ($iterable as &$loopVar) — fuse Iterator\\Value + AssignRef into one ITER_VALUE (#4431).
     *
     * @param Op[] $ops
     */
    private function isForeachLoopVarAssignRefFusion(array $ops, int $index): bool
    {
        if (!isset($ops[$index + 1])) {
            return false;
        }
        if (!$ops[$index] instanceof Op\Iterator\Value) {
            return false;
        }
        if (!$ops[$index + 1] instanceof Op\Expr\AssignRef) {
            return false;
        }
        /** @var Op\Iterator\Value $iter */
        $iter = $ops[$index];
        /** @var Op\Expr\AssignRef $assign */
        $assign = $ops[$index + 1];

        return $iter->byRef
            && $iter->result === $assign->expr
            && !$this->operandIsPropertyWriteTarget($assign->var);
    }

    /**
     * foreach ($iterable as &$obj->hookedProp) — Iterator\\Value [, PropertyFetch] AssignRef (#6435).
     *
     * @param Op[] $ops
     */
    private function isForeachPropertyHookAssignRefPair(array $ops, int $assignIndex): bool
    {
        if (!isset($ops[$assignIndex]) || !$ops[$assignIndex] instanceof Op\Expr\AssignRef) {
            return false;
        }
        /** @var Op\Expr\AssignRef $assign */
        $assign = $ops[$assignIndex];
        $cursor = $assignIndex - 1;
        if ($cursor >= 0 && $this->isListDestructPropertyFetchStmt($ops[$cursor])) {
            --$cursor;
        }
        if ($cursor < 0 || !$ops[$cursor] instanceof Op\Iterator\Value) {
            return false;
        }
        /** @var Op\Iterator\Value $iter */
        $iter = $ops[$cursor];

        return $iter->byRef
            && $iter->result === $assign->expr
            && (
                $this->operandIsPropertyWriteTarget($assign->var)
                || ($assignIndex > 0 && $this->isListDestructPropertyFetchStmt($ops[$assignIndex - 1]))
            );
    }

    private function operandIsPropertyWriteTarget(Operand $operand): bool
    {
        while ($operand instanceof Operand\Temporary && null !== $operand->original) {
            $operand = $operand->original;
        }

        return $operand instanceof Op\Expr\PropertyFetch
            || $operand instanceof Op\Expr\StaticPropertyFetch;
    }

    /**
     * php-cfg may emit PropertyFetch before Assign for hooked list slots (#6434).
     */
    private function isListDestructPropertyFetchStmt(Op $op): bool
    {
        return $op instanceof Op\Expr\PropertyFetch || $op instanceof Op\Expr\StaticPropertyFetch;
    }

    /**
     * Assign/AssignRef index for one list slot after write-target prelude ops (#6434, #7286).
     *
     * @param Op[] $ops
     */
    private function listDestructSlotAssignIndex(array $ops, int $index): ?int
    {
        if (!$ops[$index] instanceof Op\Expr\ArrayDimFetch) {
            return null;
        }
        /** @var Op\Expr\ArrayDimFetch $fetch */
        $fetch = $ops[$index];
        for ($cursor = $index + 1, $count = count($ops); $cursor < $count; ++$cursor) {
            $op = $ops[$cursor];
            if ($op instanceof Op\Expr\Assign || $op instanceof Op\Expr\AssignRef) {
                return $op->expr === $fetch->result ? $cursor : null;
            }
            if (!$this->isListDestructWriteTargetPreludeOp($op)) {
                return null;
            }
        }

        return null;
    }

    /**
     * CFG ops between a list RHS dim fetch and its slot Assign when the write target is complex.
     */
    private function isListDestructWriteTargetPreludeOp(Op $op): bool
    {
        return $op instanceof Op\Expr\New_
            || $this->isListDestructPropertyFetchStmt($op)
            || $op instanceof Op\Expr\ArrayDimFetch;
    }

    /**
     * php-cfg lowers `["key" => $v] = $array` to array literal + dim fetch + assign pairs (#1234).
     *
     * @param Op[] $ops
     */
    private function isKeyedListDestructDimFetch(array $ops, int $index): bool
    {
        if (!$ops[$index] instanceof Op\Expr\ArrayDimFetch) {
            return false;
        }
        /** @var Op\Expr\ArrayDimFetch $fetch */
        $fetch = $ops[$index];
        if (null === $fetch->var) {
            return false;
        }
        if (!$fetch->dim instanceof Literal || !is_string($fetch->dim->value)) {
            return false;
        }
        $assignIndex = $this->listDestructSlotAssignIndex($ops, $index);
        if (null === $assignIndex) {
            return false;
        }
        /** @var Op\Expr\Assign|Op\Expr\AssignRef $assign */
        $assign = $ops[$assignIndex];

        return $assign->expr === $fetch->result;
    }

    private function assignIsListSpread(Op\Expr\Assign $assign): bool
    {
        return property_exists($assign, 'listSpreadRhs')
            && null !== $assign->listSpreadRhs
            && property_exists($assign, 'listSpreadFromIndex')
            && null !== $assign->listSpreadFromIndex;
    }

    private function isListSpreadAssignOp(Op $op): bool
    {
        return $op instanceof Op\Expr\Assign && $this->assignIsListSpread($op);
    }

    /**
     * php-cfg lowers `list($a, …) = $rhs` to integer-key dim fetches (#4298).
     *
     * @param Op[] $ops
     */
    private function isListDestructGroupStart(array $ops, int $index): bool
    {
        if ($this->isListSpreadAssignOp($ops[$index])) {
            return !$this->isListDestructSpreadTail($ops, $index);
        }
        if (
            !$this->isPlainListDestructDimFetch($ops, $index)
            && !$this->isKeyedListDestructDimFetch($ops, $index)
        ) {
            return false;
        }
        /** @var Op\Expr\ArrayDimFetch $cur */
        $cur = $ops[$index];
        $p = $index - 1;
        while ($p >= 0) {
            $op = $ops[$p];
            if ($op instanceof Op\Expr\Assign || $op instanceof Op\Expr\AssignRef) {
                --$p;
                continue;
            }
            if (
                $op instanceof Op\Expr\ArrayDimFetch
                && ($this->isPlainListDestructDimFetch($ops, $p) || $this->isKeyedListDestructDimFetch($ops, $p))
                && $op->var === $cur->var
            ) {
                return false;
            }

            break;
        }

        return true;
    }

    /**
     * Spread arm at the end of `[$a, ...$rest] = $rhs` — not a separate group start (#4835).
     *
     * @param Op[] $ops
     */
    private function isListDestructSpreadTail(array $ops, int $index): bool
    {
        if (!$this->isListSpreadAssignOp($ops[$index])) {
            return false;
        }
        if ($index < 1) {
            return false;
        }
        $p = $index - 1;
        if ($ops[$p] instanceof Op\Expr\Assign || $ops[$p] instanceof Op\Expr\AssignRef) {
            --$p;
        }

        return $p >= 0
            && ($this->isPlainListDestructDimFetch($ops, $p) || $this->isKeyedListDestructDimFetch($ops, $p));
    }

    /**
     * @param Op[] $ops
     */
    private function isPlainListDestructDimFetch(array $ops, int $index): bool
    {
        if (!$ops[$index] instanceof Op\Expr\ArrayDimFetch) {
            return false;
        }
        if ($this->isKeyedListDestructDimFetch($ops, $index)) {
            return false;
        }
        /** @var Op\Expr\ArrayDimFetch $fetch */
        $fetch = $ops[$index];
        if (null === $fetch->var) {
            return false;
        }
        if (!$fetch->dim instanceof Operand\Literal || !is_int($fetch->dim->value)) {
            return false;
        }

        return $this->isListDestructDimFetchConsumer($ops, $index);
    }

    /**
     * @param Op[] $ops
     */
    private function isListDestructDimFetchConsumer(array $ops, int $index): bool
    {
        if ($index + 1 >= count($ops)) {
            return false;
        }
        $fetch = $ops[$index];
        $assignIndex = $this->listDestructSlotAssignIndex($ops, $index);
        if (null !== $assignIndex) {
            /** @var Op\Expr\Assign|Op\Expr\AssignRef $assign */
            $assign = $ops[$assignIndex];

            return $assign->expr === $fetch->result;
        }
        $next = $ops[$index + 1];

        return $next instanceof Op\Expr\ArrayDimFetch
            && $next->var === $fetch->result
            && $this->isPlainListDestructDimFetch($ops, $index + 1);
    }

    /**
     * Last CFG op index belonging to one top-level `list()` / `[]` destructuring group (#4325).
     *
     * @param Op[] $ops
     */
    private function listDestructGroupEndIndex(array $ops, int $start): int
    {
        $i = $start;
        if ($this->isListSpreadAssignOp($ops[$i])) {
            return $i;
        }
        while (
            $i < count($ops)
            && ($this->isPlainListDestructDimFetch($ops, $i) || $this->isKeyedListDestructDimFetch($ops, $i))
        ) {
            $i = $this->listDestructOpEndIndex($ops, $i);
        }
        if ($i < count($ops) && $this->isListSpreadAssignOp($ops[$i])) {
            return $i;
        }

        return $i - 1;
    }

    /**
     * @param Op[] $ops
     */
    private function listDestructRhsOperand(array $ops, int $start): Operand
    {
        if ($this->isListSpreadAssignOp($ops[$start])) {
            /** @var Op\Expr\Assign $spread */
            $spread = $ops[$start];

            return $spread->listSpreadRhs;
        }
        /** @var Op\Expr\ArrayDimFetch $firstFetch */
        $firstFetch = $ops[$start];

        return $firstFetch->var;
    }

    /**
     * @param Op[] $ops
     */
    private function listDestructOpEndIndex(array $ops, int $index): int
    {
        /** @var Op\Expr\ArrayDimFetch $fetch */
        $fetch = $ops[$index];
        $assignIndex = $this->listDestructSlotAssignIndex($ops, $index);
        if (null !== $assignIndex) {
            /** @var Op\Expr\Assign|Op\Expr\AssignRef $assign */
            $assign = $ops[$assignIndex];
            if ($assign->expr === $fetch->result) {
                return $assignIndex + 1;
            }
        }
        if ($index + 1 < count($ops)) {
            $next = $ops[$index + 1];
            if ($next instanceof Op\Expr\ArrayDimFetch && $next->var === $fetch->result) {
                return $this->listDestructOpEndIndex($ops, $index + 1);
            }
        }

        return $index + 1;
    }

    /**
     * Guard list destructuring: skip slot assignments when RHS is not an array (#4325); string RHS TypeError (#7461).
     *
     * @param Op[] $ops
     *
     * @return array{0: Block, 1: int}
     */
    private function compileListDestructGroup(array $ops, int $start, Block $block): array
    {
        $this->rejectLoneListSpreadAssign($ops, $start);
        $end = $this->listDestructGroupEndIndex($ops, $start);
        $this->rejectListDestructNewExprWriteTargets($ops, $start, $end, $block);
        $rhs = $this->listDestructRhsOperand($ops, $start);

        $checkOp = new OpCode(
            OpCode::TYPE_LIST_UNPACK_CHECK,
            null,
            $this->compileOperand($rhs, $block, true),
        );
        $block->addOpCode($checkOp);

        for ($j = $start; $j <= $end; ++$j) {
            $this->compileOp($ops[$j], $block);
        }
        $checkOp->listUnpackNullInitSlots = $this->collectListDestructAssignTargetSlots($block, $checkOp);

        $mergeBlock = new Block($block->orig);
        $mergeBlock->inheritUndefinedLocals = true;
        $mergeBlock->inheritScopeFrom($block);
        $this->inheritFuncFromParent($mergeBlock, $block);
        $checkOp->block1 = $mergeBlock;

        $assignJump = new OpCode(OpCode::TYPE_JUMP);
        $assignJump->block1 = $mergeBlock;
        $block->addOpCode($assignJump);
        $mergeBlock->parents[] = $block;

        return [$mergeBlock, $end];
    }

    /**
     * Named local slots written by guarded list destruct when assign path is skipped (#10591, #10486).
     *
     * @return list<int>
     */
    private function collectListDestructAssignTargetSlots(Block $block, OpCode $checkOp): array
    {
        $slots = [];
        $found = false;
        foreach ($block->opCodes as $op) {
            if ($op === $checkOp) {
                $found = true;
                continue;
            }
            if (!$found) {
                continue;
            }
            if (OpCode::TYPE_JUMP === $op->type) {
                break;
            }
            if (OpCode::TYPE_ASSIGN === $op->type || OpCode::TYPE_ASSIGN_REF === $op->type) {
                if (null !== $op->arg2 && $block->isNamedVariableSlot((int) $op->arg2)) {
                    $slots[(int) $op->arg2] = (int) $op->arg2;
                }
                continue;
            }
            if (OpCode::TYPE_LIST_SPREAD_ASSIGN === $op->type) {
                if (null !== $op->arg1 && $block->isNamedVariableSlot((int) $op->arg1)) {
                    $slots[(int) $op->arg1] = (int) $op->arg1;
                }
            }
        }

        return array_values($slots);
    }

    private function splitCfgBlockAfterStringKeyedArray(Block $block): Block
    {
        $cont = new Block($block->orig);
        $cont->inheritScopeFrom($block);
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
                if (!$this->isPropertyFetchOnlyCoalesceLeft($fetch, $next)) {
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
     * Echo with embedded ?? / ??= must use compileCoalesce and continue on the merge block (#99, #1960, #1980).
     *
     * @param Op[] $ops
     */
    private function compileEchoWithEmbeddedCoalesce(Op $op, Block $block, array $ops, int $echoIndex): ?Block
    {
        if (!$op instanceof Op\Terminal || 'Terminal_Echo' !== $op->getType()) {
            return null;
        }
        $echoAfterAssign = $this->resolveEchoAfterCoalesceAssign($ops, $echoIndex, $op->expr);
        if (null !== $echoAfterAssign && $this->isStmtCoalesceLoweredBeforeEcho($ops, $echoIndex)) {
            $var = $this->compileOperand($echoAfterAssign, $block, true);
            $line = $op->getLine();
            $block->addOpCode(new OpCode(OpCode::TYPE_ECHO, $var, $line > 0 ? $line : null));

            return $block;
        }
        $coalesces = $this->findEmbeddedCoalesces($op->expr);
        if ([] === $coalesces) {
            return null;
        }
        $echoOperand = $op->expr;
        foreach ($coalesces as $coalesce) {
            $resultOverride = $this->findEchoCoalesceAssignTarget($ops, $echoIndex, $coalesce);
            if (!$this->isCoalesceLoweredBeforeEcho($ops, $echoIndex, $coalesce)) {
                $block = $this->compileCoalesceForAssign($coalesce, $block, $resultOverride);
            }
            if (
                null === $this->unwrapConcatListExpr($echoOperand)
                && $this->operandsChainEqual($echoOperand, $coalesce->result)
            ) {
                $echoOperand = $resultOverride ?? $coalesce->result;
            }
        }
        $concat = $this->unwrapConcatListExpr($op->expr);
        if (null !== $concat) {
            $this->compileOp($concat, $block);
            $var = $this->compileOperand($concat->result, $block, true);
        } else {
            $var = $this->compileOperand($echoOperand, $block, true);
        }
        $line = $op->getLine();
        $block->addOpCode(new OpCode(OpCode::TYPE_ECHO, $var, $line > 0 ? $line : null));

        return $block;
    }

    /**
     * php-cfg: Coalesce; Assign; Terminal_Echo(expr=coalesce.result) — echo the ??= lvalue (#1980).
     *
     * @param Op[] $ops
     */
    private function resolveEchoAfterCoalesceAssign(array $ops, int $echoIndex, Operand $echoExpr): ?Operand
    {
        if ($echoIndex < 2) {
            return null;
        }
        $assign = $ops[$echoIndex - 1];
        $coalesce = $ops[$echoIndex - 2];
        if (!$assign instanceof Op\Expr\Assign || !$coalesce instanceof Op\Expr\BinaryOp\Coalesce) {
            if (
                $echoIndex >= 3
                && $ops[$echoIndex - 1] instanceof Op\Expr\Assign
                && $ops[$echoIndex - 2] instanceof Op\Expr\ArrayDimFetch
                && $ops[$echoIndex - 3] instanceof Op\Expr\BinaryOp\Coalesce
            ) {
                /** @var Op\Expr\Assign $assign */
                $assign = $ops[$echoIndex - 1];
                /** @var Op\Expr\ArrayDimFetch $fetch */
                $fetch = $ops[$echoIndex - 2];
                /** @var Op\Expr\BinaryOp\Coalesce $coalesce */
                $coalesce = $ops[$echoIndex - 3];
                if (
                    $this->isRedundantCoalesceTailAssign($assign, $fetch, $coalesce)
                    && $this->operandsChainEqual($echoExpr, $coalesce->result)
                ) {
                    return $assign->var;
                }
            }

            return null;
        }
        if (
            $this->isCoalesceAssignTail($assign, $coalesce)
            && $this->operandsChainEqual($echoExpr, $coalesce->result)
        ) {
            return $assign->var;
        }

        return null;
    }

    /**
     * @param Op[] $ops
     */
    private function isStmtCoalesceLoweredBeforeEcho(array $ops, int $echoIndex): bool
    {
        if ($echoIndex >= 2 && $ops[$echoIndex - 2] instanceof Op\Expr\BinaryOp\Coalesce) {
            return true;
        }
        if ($echoIndex >= 3 && $ops[$echoIndex - 3] instanceof Op\Expr\BinaryOp\Coalesce) {
            return true;
        }

        return false;
    }

    /**
     * php-cfg: Coalesce; Assign; Terminal_Echo(expr=coalesce.result) for inline ??= (#1980).
     *
     * @param Op[] $ops
     */
    private function findEchoCoalesceAssignTarget(
        array $ops,
        int $echoIndex,
        Op\Expr\BinaryOp\Coalesce $coalesce
    ): ?Operand {
        if ($echoIndex > 0) {
            $prev = $ops[$echoIndex - 1];
            if ($prev instanceof Op\Expr\Assign && $this->isCoalesceAssignTail($prev, $coalesce)) {
                return $prev->var;
            }
        }
        if (
            $echoIndex > 2
            && $ops[$echoIndex - 2] instanceof Op\Expr\Assign
            && $ops[$echoIndex - 3] instanceof Op\Expr\ArrayDimFetch
        ) {
            /** @var Op\Expr\Assign $assign */
            $assign = $ops[$echoIndex - 2];
            /** @var Op\Expr\ArrayDimFetch $fetch */
            $fetch = $ops[$echoIndex - 3];
            if ($this->isRedundantCoalesceTailAssign($assign, $fetch, $coalesce)) {
                return $assign->var;
            }
        }

        return null;
    }

    /**
     * @param Op[] $ops
     */
    private function isCoalesceLoweredBeforeEcho(
        array $ops,
        int $echoIndex,
        Op\Expr\BinaryOp\Coalesce $coalesce
    ): bool {
        for ($j = $echoIndex - 1; $j >= 0; --$j) {
            if ($ops[$j] === $coalesce) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return list<Op\Expr\BinaryOp\Coalesce>
     */
    private function findEmbeddedCoalesces(Operand $operand): array
    {
        $found = [];
        $coalesce = $this->unwrapCoalesceExpr($operand);
        if (null !== $coalesce) {
            $found[] = $coalesce;
        }
        $root = $this->unwrapOperandChain($operand);
        if ($root instanceof Op\Expr\BinaryOp\Coalesce) {
            $found[] = $root;
        }
        $concat = $this->unwrapConcatListExpr($operand);
        if (null !== $concat) {
            foreach ($concat->list as $part) {
                foreach ($this->findEmbeddedCoalesces($part) as $nested) {
                    $found[] = $nested;
                }
            }
        }

        return $found;
    }

    private function compileCoalesceForAssign(
        Op\Expr\BinaryOp\Coalesce $coalesce,
        Block $block,
        ?Operand $resultOverride = null
    ): Block {
        if (null === $resultOverride) {
            $dimFetch = $this->findCoalesceArrayDimFetch($coalesce->left, $block);
            if (null !== $dimFetch && $this->operandsChainEqual($coalesce->result, $dimFetch->result)) {
                $resultOverride = $dimFetch->result;
            } elseif ($this->operandsChainEqual($coalesce->result, $coalesce->left)) {
                $resultOverride = $coalesce->left;
            }
        }

        $block = $this->compileCoalesce($coalesce, $block, $resultOverride);

        // php-cfg keeps a separate coalesce result temp when ??= is an expression (#5337).
        // Skip when echo reads resultOverride directly — syncing would null the override slot (TYPE_ASSIGN).
        if ($this->coalesceAssignNeedsResultTempSync($coalesce, $resultOverride, $block)) {
            $resultSlot = $this->compileOperand($coalesce->result, $block, false);
            $overrideSlot = $this->compileOperand($resultOverride, $block, false);
            $block->addOpCode(new OpCode(
                OpCode::TYPE_ASSIGN,
                $resultSlot,
                $resultSlot,
                $overrideSlot
            ));
        }

        $this->syncCoalesceResultToDistinctFuncCallArg($coalesce, $block, $resultOverride);

        return $block;
    }

    /**
     * php-cfg may allocate a distinct temp for FuncCall args vs Coalesce->result (#9479, enum_int_cast_warning.phpt).
     */
    private function syncCoalesceResultToDistinctFuncCallArg(
        Op\Expr\BinaryOp\Coalesce $coalesce,
        Block $block,
        ?Operand $resultOverride
    ): void {
        if (null === $block->orig) {
            return;
        }
        $ops = $block->orig->children;
        $coalesceIdx = null;
        foreach ($ops as $idx => $op) {
            if ($op === $coalesce) {
                $coalesceIdx = $idx;
                break;
            }
        }
        if (null === $coalesceIdx) {
            return;
        }
        for ($j = $coalesceIdx + 1, $count = count($ops); $j < $count; ++$j) {
            $next = $ops[$j];
            if ($this->isLoweredByFollowingCoalesce($next, $ops, $j)) {
                continue;
            }
            if (!$next instanceof Op\Expr\FuncCall && !$next instanceof Op\Expr\NsFuncCall) {
                return;
            }
            if (1 !== count($next->args)) {
                return;
            }
            $arg = $next->args[0];
            if ($this->operandsChainEqual($arg, $coalesce->result)) {
                return;
            }
            $sourceSlot = $this->compileOperand($resultOverride ?? $coalesce->result, $block, true);
            $destSlot = $this->compileOperand($arg, $block, false);
            $block->addOpCode(new OpCode(
                OpCode::TYPE_ASSIGN,
                $destSlot,
                $destSlot,
                $sourceSlot
            ));

            return;
        }
    }

    /**
     * True when ??= coalesce->result is read outside echo/tail-assign paths (#5337).
     */
    private function coalesceAssignNeedsResultTempSync(
        Op\Expr\BinaryOp\Coalesce $coalesce,
        ?Operand $resultOverride,
        Block $block
    ): bool {
        if (
            null === $resultOverride
            || $this->operandsChainEqual($resultOverride, $coalesce->result)
        ) {
            return false;
        }
        if (null === $block->orig) {
            return true;
        }

        $ops = $block->orig->children;
        $tailAssign = null;
        foreach ($ops as $idx => $op) {
            if ($op !== $coalesce) {
                continue;
            }
            if (
                isset($ops[$idx + 1])
                && $ops[$idx + 1] instanceof Op\Expr\Assign
                && $this->isCoalesceAssignTail($ops[$idx + 1], $coalesce)
            ) {
                $tailAssign = $ops[$idx + 1];
            }
            break;
        }

        foreach ($ops as $op) {
            if ($op instanceof Op\Expr\Assign) {
                if ($op === $tailAssign) {
                    continue;
                }
                if ($this->operandsChainEqual($op->expr, $coalesce->result)) {
                    return true;
                }
            }
            if ($op instanceof Op\Terminal\Echo) {
                if ($this->operandsChainEqual($op->expr, $coalesce->result)) {
                    continue;
                }
            }
            if ($op instanceof Op\Expr\FuncCall || $op instanceof Op\Expr\NsFuncCall) {
                foreach ($op->args as $arg) {
                    if ($this->operandsChainEqual($arg, $coalesce->result)) {
                        return true;
                    }
                }
            }
            if ($op instanceof Op\Expr\MethodCall || $op instanceof Op\Expr\StaticCall) {
                foreach ($op->args as $arg) {
                    if ($this->operandsChainEqual($arg, $coalesce->result)) {
                        return true;
                    }
                }
            }
            if ($op instanceof Op\Terminal\Return_ && null !== $op->expr) {
                if ($this->operandsChainEqual($op->expr, $coalesce->result)) {
                    return true;
                }
            }
            if ($op instanceof Op\Expr\BinaryOp\Coalesce && $op !== $coalesce) {
                if ($this->operandsChainEqual($op->right, $coalesce->result)) {
                    return true;
                }
            }
        }

        return false;
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

    /**
     * php-cfg emits ArrayDimFetch as its own stmt before Isset_; skip duplicate lowering.
     */
    private function isArrayDimFetchOnlyIssetVar(
        Op\Expr\ArrayDimFetch $fetch,
        Op $next
    ): bool {
        if (!$next instanceof Op\Expr\Isset_) {
            return false;
        }
        foreach ($next->vars as $var) {
            $target = $var;
            while ($target instanceof Temporary) {
                if ($target === $fetch->result) {
                    return true;
                }
                if (null === $target->original) {
                    break;
                }
                $target = $target->original;
            }
            if ($target === $fetch->result) {
                return true;
            }
        }

        return false;
    }

    /**
     * php-cfg emits ArrayDimFetch as its own stmt before Terminal_Unset; skip duplicate lowering.
     */
    private function isArrayDimFetchOnlyUnsetVar(
        Op\Expr\ArrayDimFetch $fetch,
        Op $next
    ): bool {
        if (!$next instanceof Op\Terminal\Unset_) {
            return false;
        }
        foreach ($next->exprs as $var) {
            if ($var === $fetch) {
                return true;
            }
            $target = $var;
            while ($target instanceof Temporary) {
                if ($target === $fetch->result) {
                    return true;
                }
                if (null === $target->original) {
                    break;
                }
                $target = $target->original;
            }
            if ($target === $fetch->result) {
                return true;
            }
        }

        return false;
    }

    /**
     * php-cfg emits PropertyFetch as its own stmt before Isset_; skip duplicate lowering.
     */
    private function isPropertyFetchOnlyIssetVar(
        Op\Expr\PropertyFetch $fetch,
        Op $next
    ): bool {
        if (!$next instanceof Op\Expr\Isset_) {
            return false;
        }
        foreach ($next->vars as $var) {
            if ($var === $fetch) {
                return true;
            }
            $target = $var;
            while ($target instanceof Temporary) {
                if ($target === $fetch->result) {
                    return true;
                }
                if (null === $target->original) {
                    break;
                }
                $target = $target->original;
            }
            if ($target === $fetch->result) {
                return true;
            }
        }

        return false;
    }

    private function isPropertyFetchOnlyEmptyVar(
        Op\Expr\PropertyFetch $fetch,
        Op $next,
        Block $block
    ): bool {
        if ($next instanceof Op\Expr\Empty_) {
            $target = $next->expr;
            if ($target === $fetch || $target === $fetch->result) {
                return true;
            }
            while ($target instanceof Temporary) {
                if ($target === $fetch->result) {
                    return true;
                }
                if (null === $target->original) {
                    break;
                }
                $target = $target->original;
            }
            if ($target === $fetch->result) {
                return true;
            }

            return $this->findCoalescePropertyFetch($target, $block) === $fetch;
        }
        if ($this->isInlineExprCallArgConsumer($next)) {
            return $this->funcCallHasEmptyArgUsingPropertyFetch($next, $fetch, $block);
        }

        return false;
    }

    private function funcCallHasEmptyArgUsingPropertyFetch(Op $call, Op\Expr\PropertyFetch $fetch, Block $block): bool
    {
        if (!property_exists($call, 'args') || !is_array($call->args)) {
            return false;
        }
        foreach ($call->args as $arg) {
            if (!$arg instanceof Operand\Temporary || !$arg->original instanceof Op\Expr\Empty_) {
                continue;
            }
            if ($this->emptyExprDependsOnOperand($arg->original, $fetch->result, $block)) {
                return true;
            }
        }

        return false;
    }

    /**
     * php-cfg emits ArrayDimFetch as its own stmt before Empty_; skip duplicate lowering (#5307).
     */
    private function isArrayDimFetchOnlyEmptyVar(
        Op\Expr\ArrayDimFetch $fetch,
        Op $next,
        Block $block
    ): bool {
        if (!$next instanceof Op\Expr\Empty_) {
            return false;
        }
        $target = $next->expr;
        if ($target === $fetch || $target === $fetch->result) {
            return true;
        }
        while ($target instanceof Temporary) {
            if ($target === $fetch->result) {
                return true;
            }
            if (null === $target->original) {
                break;
            }
            $target = $target->original;
        }
        if ($target === $fetch->result) {
            return true;
        }

        return $this->findCoalesceArrayDimFetch($target, $block) === $fetch;
    }

    /**
     * @return list<Op\Expr\NullsafePropertyFetch>
     */
    protected function collectNullsafePropertyFetchChain(?Operand $operand, Block $block): array
    {
        $innermost = $this->findNullsafePropertyFetch($operand, $block);
        if (null === $innermost) {
            return [];
        }
        $chain = [$innermost];
        $var = $innermost->var;
        while (true) {
            $prev = $this->findNullsafePropertyFetchProducing($var, $block);
            if (null === $prev) {
                break;
            }
            array_unshift($chain, $prev);
            $var = $prev->var;
        }

        return $chain;
    }

    /**
     * @return list<Op\Expr\NullsafePropertyFetch>
     */
    protected function collectNullsafePropertyFetchChainForEmpty(Op\Expr\Empty_ $expr, Block $block): array
    {
        $operand = $this->unaryExprOperandForRead($expr, $block);
        if (null === $operand) {
            return [];
        }

        return $this->collectNullsafePropertyFetchChain($operand, $block);
    }

    /**
     * @return ?Op\Expr\NullsafePropertyFetch
     */
    protected function findNullsafePropertyFetch(?Operand $operand, Block $block): ?Op\Expr\NullsafePropertyFetch
    {
        if (null === $operand) {
            return null;
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
                if ($child instanceof Op\Expr\NullsafePropertyFetch && $child->result === $current) {
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
     * @return ?Op\Expr\NullsafePropertyFetch
     */
    protected function findNullsafePropertyFetchProducing(?Operand $operand, Block $block): ?Op\Expr\NullsafePropertyFetch
    {
        if (null === $operand) {
            return null;
        }
        foreach ($block->orig->children as $child) {
            if ($child instanceof Op\Expr\NullsafePropertyFetch && $child->result === $operand) {
                return $child;
            }
        }
        if ($operand instanceof Temporary && null !== $operand->original) {
            return $this->findNullsafePropertyFetchProducing($operand->original, $block);
        }

        return null;
    }

    /**
     * @param Op[] $ops
     */
    protected function shouldSkipNullsafePropertyFetchForIssetOrEmpty(
        Op\Expr\NullsafePropertyFetch $fetch,
        array $ops,
        int $index,
        Block $block
    ): bool {
        for ($j = $index + 1, $count = count($ops); $j < $count; ++$j) {
            $next = $ops[$j];
            if ($next instanceof Op\Expr\NullsafePropertyFetch) {
                continue;
            }
            if ($next instanceof Op\Expr\Isset_ && 1 === count($next->vars)) {
                $chain = $this->collectNullsafePropertyFetchChain($next->vars[0], $block);

                return [] !== $chain && in_array($fetch, $chain, true);
            }
            if ($next instanceof Op\Expr\Empty_) {
                $chain = $this->collectNullsafePropertyFetchChainForEmpty($next, $block);

                return [] !== $chain && in_array($fetch, $chain, true);
            }

            return false;
        }

        return false;
    }

    private function isPropertyWriteAssign(Op\Expr\Assign $assign, Block $block): bool
    {
        if (null !== $this->unwrapPropertyFetch($assign->var)
            || null !== $this->findCoalescePropertyFetch($assign->var, $block)) {
            return true;
        }

        return null !== $this->unwrapStaticPropertyFetch($assign->var)
            || null !== $this->findStaticPropertyFetchForAssign($assign->var, $block);
    }

    /** While-loop ?: merge must not steal array-append write slots (#10702). */
    private function isArrayDimWriteAssign(Op\Expr\Assign $assign, Block $block): bool
    {
        if (null !== $this->unwrapArrayDimFetch($assign->var)) {
            return true;
        }

        return null !== $this->findArrayDimFetchForResult($assign->var, $block);
    }

    private function isPropertyFetchOnlyAssignVar(
        Op\Expr\PropertyFetch $fetch,
        Op $next
    ): bool {
        if (!$next instanceof Op\Expr\Assign) {
            return false;
        }
        $var = $next->var;
        if ($var === $fetch || $var === $fetch->result) {
            return true;
        }
        while ($var instanceof Temporary) {
            if ($var === $fetch->result || $var->original === $fetch) {
                return true;
            }
            if (null === $var->original) {
                break;
            }
            $var = $var->original;
        }

        return $var === $fetch->result;
    }

    private function isPropertyFetchOnlyUnsetVar(
        Op\Expr\PropertyFetch $fetch,
        Op $next
    ): bool {
        if (!$next instanceof Op\Terminal\Unset_) {
            return false;
        }
        foreach ($next->exprs as $var) {
            if ($var === $fetch) {
                return true;
            }
            $target = $var;
            while ($target instanceof Temporary) {
                if ($target === $fetch->result) {
                    return true;
                }
                if (null === $target->original) {
                    break;
                }
                $target = $target->original;
            }
            if ($target === $fetch->result) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return array{0: int, 1: ?int}
     */
    protected function resolveUnsetTarget($expr, Block $block): array
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
        if ($expr instanceof Op\Expr\StaticPropertyFetch) {
            $this->throwCompileLogic(
                'StaticPropertyFetch unset must be lowered via TYPE_STATIC_PROPERTY_UNSET (#2256)'
            );
        }
        if ($expr instanceof Operand) {
            $dimFetch = $this->findCoalesceArrayDimFetch($expr, $block);
            if (null !== $dimFetch) {
                return $this->resolveIssetTargetFromArrayDimFetch($dimFetch, $block);
            }
            foreach ($block->orig->children as $child) {
                if ($child instanceof Op\Expr\PropertyFetch && $child->result === $expr) {
                    return [
                        $this->compileOperand($child->var, $block, true),
                        $this->compileOperand($child->name, $block, true),
                    ];
                }
            }

            return $this->resolveIssetTarget($expr, $block);
        }

        $this->throwCompileLogic('Unsupported unset target: ' . (is_object($expr) ? $expr->getType() : gettype($expr)));
    }

    protected function compileInterface(Op\Stmt\Interface_ $iface, Block $block): OpCode
    {
        $name = $this->staticNameFromOperand($iface->name);
        if (null === $name) {
            $this->throwCompileError('Interface name must be a compile-time class reference');
        }
        $extends = $this->interfaceNamesFromOperands($iface->extends);
        $this->classCompileRegistry->registerInterface($name, $extends, $iface->stmts);

        $return = new OpCode(
            OpCode::TYPE_DECLARE_INTERFACE,
            $this->compileOperand($iface->name, $block, true)
        );
        $this->assignAttributeMetadata($return, $iface);
        AttributeNames::assertOverrideMethodTargetOnly($return->attributeNames, 'class');
        AttributeNames::assertCompileTimeConstTargetOnly($return->attributeNames, 'class');
        AttributeNames::assertSensitiveParameterParamTargetOnly($return->attributeNames, 'class');
        $this->registerAttributeClassFromEntries($name, $return->attributeEntries);
        $return->classImplements = $extends;
        $this->applySealedMetadataFromOp($iface, $return);
        $return->block1 = $this->compileClassBody(
            $iface->stmts,
            OpCode::TYPE_DECLARE_INTERFACE,
            $this->staticNameFromOperand($iface->name)
        );

        return $return;
    }

    protected function compileTrait(Op\Stmt\Trait_ $trait, Block $block): OpCode
    {
        $name = $this->staticNameFromOperand($trait->name);
        if (null === $name) {
            $this->throwCompileError('Trait name must be a compile-time class reference');
        }
        $this->classCompileRegistry->registerTrait($name, $trait->stmts);

        $return = new OpCode(
            OpCode::TYPE_DECLARE_TRAIT,
            $this->compileOperand($trait->name, $block, true)
        );
        $this->assignAttributeMetadata($return, $trait);
        AttributeNames::assertOverrideMethodTargetOnly($return->attributeNames, 'class');
        AttributeNames::assertCompileTimeConstTargetOnly($return->attributeNames, 'class');
        AttributeNames::assertSensitiveParameterParamTargetOnly($return->attributeNames, 'class');
        $this->registerAttributeClassFromEntries($name, $return->attributeEntries);
        $traitLc = strtolower(ltrim($name, '\\'));
        $this->compiledClassStaticProperties[$traitLc] = $this->compiledClassStaticProperties[$traitLc] ?? [];
        $prevClassStaticCompile = $this->currentClassStaticPropertyCompile;
        $this->currentClassStaticPropertyCompile = $traitLc;
        $return->block1 = $this->compileClassBody(
            $trait->stmts,
            OpCode::TYPE_DECLARE_TRAIT,
            $this->staticNameFromOperand($trait->name)
        );
        $this->currentClassStaticPropertyCompile = $prevClassStaticCompile;

        return $return;
    }

    protected function compileEnum(Op\Stmt\Enum_ $enum, Block $block): OpCode
    {
        $backedTypeSlot = null;
        if (null !== $enum->backedType && $enum->backedType instanceof Op\Type\Literal) {
            $backedVar = new Variable(Variable::TYPE_STRING);
            $backedVar->string($enum->backedType->name);
            $backedOperand = new Operand\Temporary;
            $backedOperand->type = Type::string();
            $backedTypeSlot = $block->registerConstant($backedOperand, $backedVar);
        }
        $return = new OpCode(
            OpCode::TYPE_DECLARE_ENUM,
            $this->compileOperand($enum->name, $block, true),
            $backedTypeSlot
        );
        $this->assignAttributeMetadata($return, $enum);
        $return->deprecatedMetadata = DeprecatedMetadata::fromOp($enum);
        AttributeNames::assertOverrideMethodTargetOnly($return->attributeNames, 'class');
        AttributeNames::assertCompileTimeConstTargetOnly($return->attributeNames, 'class');
        AttributeNames::assertSensitiveParameterParamTargetOnly($return->attributeNames, 'class');
        $enumName = $this->staticNameFromOperand($enum->name);
        if (null !== $enumName) {
            AttributeNames::assertAllowDynamicPropertiesNotOnEnum($return->attributeNames, $enumName);
            $this->registerAttributeClassFromEntries($enumName, $return->attributeEntries);
        }
        $return->classImplements = $this->interfaceNamesFromOperands($enum->implements);
        $return->classIsAbstract = VM\ClassAbstract::fromClassFlags($enum->flags ?? 0);
        if ($return->classIsAbstract) {
            $name = $this->staticNameFromOperand($enum->name);
            if (null !== $name) {
                $lc = strtolower(ltrim($name, '\\'));
                $this->abstractClasses[$lc] = true;
                $this->abstractEnums[$lc] = true;
            }
        }
        if (null !== $enumName) {
            $enumLc = strtolower(ltrim($enumName, '\\'));
            $backedTypeName = null;
            if (null !== $enum->backedType && $enum->backedType instanceof Op\Type\Literal) {
                $backedTypeName = $enum->backedType->name;
            }
            $this->compileTimeEnumBackedTypes[$enumLc] = $backedTypeName;
        }
        $return->block1 = $this->compileEnumBody($enum->stmts, $enumName);

        return $return;
    }

    protected function compileEnumBody(CfgBlock $block, ?string $enumName = null): Block
    {
        $result = new Block($block);
        $prevClassLc = $this->compilingClassLc;
        $prevClassDisplayName = $this->compilingClassDisplayName;
        $prevInstancePropertyNames = $this->compilingClassInstancePropertyNames;
        $prevMethodNames = $this->compilingClassMethodNames;
        $this->compilingClassInstancePropertyNames = [];
        $this->compilingClassMethodNames = [];
        if (null !== $enumName) {
            $this->compilingClassLc = strtolower(ltrim($enumName, '\\'));
            $this->compilingClassDisplayName = ltrim($enumName, '\\');
            if (!isset($this->compileTimeClassConsts[$this->compilingClassLc])) {
                $this->compileTimeClassConsts[$this->compilingClassLc] = [];
            }
            if (!isset($this->compileTimeClassConstVisibility[$this->compilingClassLc])) {
                $this->compileTimeClassConstVisibility[$this->compilingClassLc] = [];
            }
            if (!isset($this->compileTimeClassConstDeprecated[$this->compilingClassLc])) {
                $this->compileTimeClassConstDeprecated[$this->compilingClassLc] = [];
            }
            if (!isset($this->compileTimeEnumBackedTypes[$this->compilingClassLc])) {
                $this->compileTimeEnumBackedTypes[$this->compilingClassLc] = null;
            }
            if (!isset($this->compileTimeEnumCaseConstNames[$this->compilingClassLc])) {
                $this->compileTimeEnumCaseConstNames[$this->compilingClassLc] = [];
            }
        } else {
            $this->compilingClassDisplayName = null;
        }
        foreach ($block->children as $child) {
            if ($child instanceof Op\Terminal\Const_) {
                $this->compileClassConstDeclaration($child, $result);
                continue;
            }
            if ($child instanceof Op\Stmt\TraitUse) {
                foreach ($child->traits as $traitOperand) {
                    $result->addOpCode(new OpCode(
                        OpCode::TYPE_USE_TRAIT,
                        $this->compileOperand($traitOperand, $result, true)
                    ));
                }
                $adaptOp = new OpCode(OpCode::TYPE_TRAIT_USE_ADAPTATION);
                $adaptOp->traitAdaptations = [] !== $child->adaptations
                    ? $this->compileTraitAdaptations($child->adaptations)
                    : [];
                $result->addOpCode($adaptOp);
                continue;
            }
            if ($child instanceof Op\Stmt\ClassMethod) {
                $this->compileClassMethodDeclaration($child, $result);

                continue;
            }
            $this->throwCompileLogic('Unsupported enum body element: '.get_class($child));
        }
        $this->compilingClassLc = $prevClassLc;
        $this->compilingClassDisplayName = $prevClassDisplayName;
        $this->compilingClassInstancePropertyNames = $prevInstancePropertyNames;
        $this->compilingClassMethodNames = $prevMethodNames;

        return $result;
    }

    protected function compileClassMethodDeclaration(Op\Stmt\ClassMethod $child, Block $result): void
    {
        $this->registerMethodDeclaration($child->func->name);
        foreach ($child->func->params as $param) {
            $this->assertParamDeclaredType($param->declaredType);
        }
        if ('__construct' === $child->func->name) {
            foreach ($child->func->params as $param) {
                if ($this->isPromotedParam($param)) {
                    $this->compilePromotedPropertyDeclaration($param, $result);
                }
            }
        }
        $methodName = new Operand\Literal($child->func->name);
        $methodName->type = Type::string();
        $visVar = new Variable(Variable::TYPE_INTEGER);
        $visFlags = MethodVisibility::mask($child->func->flags);
        if (($child->func->flags & \PHPCfg\Func::FLAG_STATIC) !== 0) {
            $visFlags |= \PHPCfg\Func::FLAG_STATIC;
        }
        if (($child->func->flags & CfgFunc::FLAG_FINAL) !== 0) {
            $visFlags |= CfgFunc::FLAG_FINAL;
        }
        $visVar->int($visFlags);
        $visOperand = new Operand\Temporary;
        $visOperand->type = Type::int();
        $visIdx = $result->registerConstant($visOperand, $visVar);
        $methodLine = max(0, $child->getLine());
        $declare = new OpCode(
            OpCode::TYPE_DECLARE_METHOD,
            $this->compileOperand($methodName, $result, true),
            $methodLine > 0 ? $methodLine : null,
            $visIdx
        );
        if (null !== $child->func->cfg) {
            $methodBlock = $this->compileCfgBlock($child->func->cfg, $child->func->params, $child->func);
            NoDiscardMetadata::applyToBlock($methodBlock, $child);
            $this->markGeneratorIfNeeded($child, $methodBlock);
            $declare->block1 = $methodBlock;
        } elseif (0 === ($child->func->flags & CfgFunc::FLAG_ABSTRACT)) {
            // php-cfg omits cfg for `{}` method bodies; concrete methods still need block1 (#4758).
            $declare->block1 = $this->compileEmptyConcreteMethodBlock($child->func->params, $child->func);
        }
        $this->assignAttributeMetadata($declare, $child);
        $this->assignSourceMetadata($declare, $child);
        AttributeNames::assertAllowDynamicPropertiesClassTargetOnly($declare->attributeNames, 'method');
        AttributeNames::assertCompileTimeConstTargetOnly($declare->attributeNames, 'method');
        AttributeNames::assertSensitiveParameterParamTargetOnly($declare->attributeNames, 'method');
        $declare->parameterMetadata = $this->parameterMetadataFromParams($child->func->params);
        $declare->deprecatedMetadata = DeprecatedMetadata::fromOp($child);
        $result->addOpCode($declare);
    }

    /**
     * @param list<Op\Expr\Param> $params
     *
     * @return list<ParameterMetadata>
     */
    protected function parameterMetadataFromParams(array $params): array
    {
        $metadata = [];
        foreach ($params as $param) {
            if (!($param->name instanceof Operand\Literal) || !is_string($param->name->value)) {
                continue;
            }
            $metadata[] = new ParameterMetadata(
                $param->name->value,
                AttributeMetadata::fromOp($param)
            );
        }

        return $metadata;
    }

    protected function assignAttributeMetadata(OpCode $op, Op $cfgOp): void
    {
        $entries = AttributeMetadata::fromOp($cfgOp);
        $op->attributeEntries = AttributeNames::validateDuplicates($entries, $this->attributeClassRegistry);
        $op->attributeNames = AttributeEntry::namesFromList($op->attributeEntries);
    }

    protected function assignSourceMetadata(OpCode $op, Op $cfgOp): void
    {
        $op->sourceLocation = SourceLocation::fromOp($cfgOp);
    }

    /**
     * @param list<AttributeEntry> $selfEntries
     */
    protected function registerAttributeClassFromEntries(string $className, array $selfEntries): void
    {
        $this->attributeClassRegistry->registerAttributeClass($className, $selfEntries);
    }

    protected function compileClassLike(Op\Stmt\ClassLike $class, Block $block): OpCode {
        $type = 0;
        if ($class instanceof Op\Stmt\Class_) {
            $type = OpCode::TYPE_DECLARE_CLASS;
        } else {
            $this->throwCompileLogic('Unsupported class type: ' . get_class($class));
        }
        $className = $this->staticNameFromOperand($class->name);
        if (null === $className) {
            $this->throwCompileError('Class name must be a compile-time class reference');
        }
        $parentLc = null;
        if ($class instanceof Op\Stmt\Class_ && null !== $class->extends) {
            $parentName = $this->staticNameFromOperand($class->extends);
            if (null === $parentName) {
                $this->throwCompileError('Parent class name must be a compile-time class reference');
            }
            $parentLc = strtolower(ltrim($parentName, '\\'));
        }
        $interfaceLcs = $this->interfaceNamesFromOperands($class->implements);
        $parentSlot = null;
        if ($class instanceof Op\Stmt\Class_ && null !== $class->extends) {
            $parentSlot = $this->compileOperand($class->extends, $block, true);
        }
        $classFlagsVar = new Variable(Variable::TYPE_INTEGER);
        $classFlagsVar->int(VM\ClassFlags::pack($class->flags));
        $classFlagsOperand = new Operand\Temporary;
        $classFlagsOperand->type = Type::int();
        $readonlySlot = $block->registerConstant($classFlagsOperand, $classFlagsVar);
        $return = new OpCode(
            $type,
            $this->compileOperand($class->name, $block, true),
            $parentSlot,
            $readonlySlot
        );
        $return->classImplements = $interfaceLcs;
        if (VM\StringableSupport::requiresImplementation($return->classImplements)) {
            VM\StringableSupport::assertConcreteClassImplements($class, $className);
        }
        $this->assignAttributeMetadata($return, $class);
        $this->assignSourceMetadata($return, $class);
        $return->deprecatedMetadata = DeprecatedMetadata::fromOp($class);
        AttributeNames::assertOverrideMethodTargetOnly($return->attributeNames, 'class');
        AttributeNames::assertCompileTimeConstTargetOnly($return->attributeNames, 'class');
        AttributeNames::assertSensitiveParameterParamTargetOnly($return->attributeNames, 'class');
        $this->applySealedMetadataFromOp($class, $return);
        $return->classIsAbstract = VM\ClassAbstract::fromClassFlags($class->flags);
        if ($return->classIsAbstract) {
            $this->abstractClasses[strtolower(ltrim($className, '\\'))] = true;
        }
        $classLc = strtolower(ltrim($className, '\\'));
        $this->compiledClassStaticProperties[$classLc] = $this->compiledClassStaticProperties[$classLc] ?? [];
        $prevClassStaticCompile = $this->currentClassStaticPropertyCompile;
        $this->currentClassStaticPropertyCompile = $classLc;
        $return->block1 = $this->compileClassBody(
            $class->stmts,
            $type,
            $className
        );
        $this->currentClassStaticPropertyCompile = $prevClassStaticCompile;
        $this->mergeTraitStaticPropertiesIntoClass($class->stmts, $classLc);
        $this->mergeTraitCompileTimeClassConstsIntoClass($class->stmts, $classLc);
        $this->mergeInterfaceCompileTimeClassConstsIntoClass($classLc, $interfaceLcs);
        if ($class instanceof Op\Stmt\Class_ && null !== $class->extends && null !== $parentLc) {
            foreach ($this->compiledClassStaticProperties[$parentLc] ?? [] as $prop => $_) {
                $this->compiledClassStaticProperties[$classLc][$prop] = true;
            }
        }
        $this->classCompileRegistry->registerClass($className, $parentLc, $interfaceLcs, $class->stmts);
        $this->registerAttributeClassFromEntries($className, $return->attributeEntries);

        return $return;
    }

    protected function mergeTraitStaticPropertiesIntoClass(CfgBlock $stmts, string $classLc): void
    {
        foreach ($stmts->children as $child) {
            if (!$child instanceof Op\Stmt\TraitUse) {
                continue;
            }
            foreach ($child->traits as $traitOperand) {
                $traitName = $this->staticNameFromOperand($traitOperand);
                if (null === $traitName) {
                    continue;
                }
                $traitLc = strtolower(ltrim($traitName, '\\'));
                foreach ($this->compiledClassStaticProperties[$traitLc] ?? [] as $prop => $_) {
                    $this->compiledClassStaticProperties[$classLc][$prop] = true;
                }
            }
        }
    }

    /**
     * Copy trait class constants into the composing class compile-time table (#9430, zend_traits.c).
     */
    protected function mergeTraitCompileTimeClassConstsIntoClass(CfgBlock $stmts, string $classLc): void
    {
        foreach ($stmts->children as $child) {
            if (!$child instanceof Op\Stmt\TraitUse) {
                continue;
            }
            foreach ($child->traits as $traitOperand) {
                $traitName = $this->staticNameFromOperand($traitOperand);
                if (null === $traitName) {
                    continue;
                }
                $this->inheritCompileTimeClassConstsFromTrait(
                    $classLc,
                    strtolower(ltrim($traitName, '\\'))
                );
            }
        }
    }

    /**
     * Copy interface class constants into implementor compile-time table (#9430, zend_constants.c).
     *
     * @param list<string> $interfaceLcs
     */
    protected function mergeInterfaceCompileTimeClassConstsIntoClass(string $classLc, array $interfaceLcs): void
    {
        foreach ($interfaceLcs as $ifaceLc) {
            $this->inheritCompileTimeClassConstsFromInterface($classLc, $ifaceLc);
        }
    }

    protected function inheritCompileTimeClassConstsFromTrait(string $classLc, string $traitLc): void
    {
        if (!isset($this->compileTimeClassConsts[$traitLc])) {
            return;
        }
        if (!isset($this->compileTimeClassConsts[$classLc])) {
            $this->compileTimeClassConsts[$classLc] = [];
        }
        if (!isset($this->compileTimeClassConstVisibility[$classLc])) {
            $this->compileTimeClassConstVisibility[$classLc] = [];
        }
        if (!isset($this->compileTimeClassConstDeprecated[$classLc])) {
            $this->compileTimeClassConstDeprecated[$classLc] = [];
        }
        foreach ($this->compileTimeClassConsts[$traitLc] as $constLc => $value) {
            if (isset($this->compileTimeClassConsts[$classLc][$constLc])) {
                continue;
            }
            $stored = new Variable();
            $stored->copyFrom($value);
            $this->compileTimeClassConsts[$classLc][$constLc] = $stored;
            if (isset($this->compileTimeClassConstVisibility[$traitLc][$constLc])) {
                $this->compileTimeClassConstVisibility[$classLc][$constLc]
                    = $this->compileTimeClassConstVisibility[$traitLc][$constLc];
            }
            if (isset($this->compileTimeClassConstDeprecated[$traitLc][$constLc])) {
                $this->compileTimeClassConstDeprecated[$classLc][$constLc]
                    = $this->compileTimeClassConstDeprecated[$traitLc][$constLc];
            }
        }
    }

    protected function inheritCompileTimeClassConstsFromInterface(string $classLc, string $ifaceLc): void
    {
        if (!isset($this->compileTimeClassConsts[$ifaceLc])) {
            return;
        }
        if (!isset($this->compileTimeClassConsts[$classLc])) {
            $this->compileTimeClassConsts[$classLc] = [];
        }
        if (!isset($this->compileTimeClassConstVisibility[$classLc])) {
            $this->compileTimeClassConstVisibility[$classLc] = [];
        }
        foreach ($this->compileTimeClassConsts[$ifaceLc] as $constLc => $value) {
            if (isset($this->compileTimeClassConsts[$classLc][$constLc])) {
                continue;
            }
            $stored = new Variable();
            $stored->copyFrom($value);
            $this->compileTimeClassConsts[$classLc][$constLc] = $stored;
            if (isset($this->compileTimeClassConstVisibility[$ifaceLc][$constLc])) {
                $this->compileTimeClassConstVisibility[$classLc][$constLc]
                    = $this->compileTimeClassConstVisibility[$ifaceLc][$constLc];
            }
        }
    }

    protected function applySealedMetadataFromOp(Op $op, OpCode $opcode): void
    {
        if (!$op->hasAttribute('compilerSealed')) {
            return;
        }
        $opcode->isSealed = true;
        $permits = $op->getAttribute('compilerSealedPermits');
        $opcode->sealedPermits = \is_array($permits) ? $permits : [];
    }

    /**
     * @param Operand[] $operands
     *
     * @return list<string>
     */
    protected function interfaceNamesFromOperands(array $operands): array
    {
        $names = [];
        foreach ($operands as $operand) {
            $name = $this->staticNameFromOperand($operand);
            if (null === $name) {
                $this->throwCompileError('Interface name must be a compile-time class reference');
            }
            $names[] = strtolower(ltrim($name, '\\'));
        }

        return $names;
    }

    protected function staticNameFromOperand(Operand $op): ?string
    {
        if ($op instanceof Operand\Literal && is_string($op->value)) {
            return $op->value;
        }
        if ($op instanceof Operand\Variable) {
            return $this->staticNameFromOperand($op->name);
        }

        return null;
    }

    /**
     * PHP 8.4: interfaces may declare static properties only with hook syntax (#9754, zend_compile.c).
     */
    protected function interfaceStaticPropertyHookAllowed(Operand $nameOperand): bool
    {
        $propName = $this->staticNameFromOperand($nameOperand);
        $classLc = $this->compilingClassLc;
        if (null === $propName || null === $classLc || '' === $classLc) {
            return false;
        }

        return isset($this->propertyHookRegistry[$classLc][$propName])
            || isset($this->propertyHookRegistry[$classLc][strtolower($propName)]);
    }

    protected function literalScopeClassName(Operand $class): ?string
    {
        if ($class instanceof Operand\Literal && is_string($class->value)) {
            return $class->value;
        }
        if ($class instanceof Operand\Variable) {
            return $this->literalScopeClassName($class->name);
        }
        if (null !== $class->original) {
            if ($class->original instanceof \PhpParser\Node\Name) {
                return $class->original->toString();
            }
            if ($class->original instanceof Operand) {
                return $this->literalScopeClassName($class->original);
            }
        }

        return $this->staticNameFromOperand($class);
    }

    /** True when StaticCall source class is the `parent` keyword (#6735, zend_compile.c). */
    protected function staticCallUsesParentScope(Operand $class): bool
    {
        $name = $this->literalScopeClassName($class);
        if (null !== $name && 'parent' === strtolower($name)) {
            return true;
        }
        $current = $class;
        while (null !== $current) {
            if ($current instanceof Operand\Variable && $current->name instanceof Operand\Literal) {
                if ('parent' === strtolower((string) $current->name->value)) {
                    return true;
                }
            }
            if (property_exists($current, 'original') && null !== $current->original) {
                if ($current->original instanceof \PhpParser\Node\Name) {
                    $parts = $current->original->getParts();
                    if (1 === \count($parts) && 'parent' === strtolower($parts[0])) {
                        return true;
                    }
                }
                if ($current->original instanceof Operand) {
                    $current = $current->original;
                    continue;
                }
            }

            break;
        }

        return false;
    }

    /**
     * True when the static fetch class operand is an instance (new expr or variable), not a class name (#5477).
     */
    protected function staticPropertyClassIsObjectExpression(Operand $class): bool
    {
        if ($class instanceof Operand\Literal && is_string($class->value)) {
            return false;
        }
        $current = $class;
        while (null !== $current) {
            if ($current instanceof Op\Expr\New_) {
                return true;
            }
            if ($current instanceof Operand\Temporary || $current instanceof Operand\Variable) {
                $next = $current->original;
                $current = $next instanceof Operand ? $next : null;

                continue;
            }

            break;
        }

        return $class instanceof Operand\Temporary || $class instanceof Operand\Variable;
    }

    /**
     * @return list<string>
     */
    protected function intersectionNamesFromCfgType(Op\Type\Intersection $type): array
    {
        $names = [];
        foreach ($type->types as $member) {
            $name = $this->staticNameFromCfgType($member);
            if (null === $name) {
                $this->throwCompileError('Intersection type members must be interface names');
            }
            $names[] = strtolower(ltrim($name, '\\'));
        }

        return $names;
    }

    /**
     * True when declared type uses union/intersection/nullable DNF shape (#3094).
     * Plain scalars like `int` stay on paramTypeConstraints / typeConstraint paths.
     */
    /**
     * MCJIT execute for DNF typed property scripts needs at least one try/catch region
     * (empty body is enough — see compliance dnf_property* vs dnf_new_empty_try).
     */
    private function appendMcjitDnfPropertyTryEpilogue(Block $main): void
    {
        $merge = new Block($main->orig);
        $merge->func = $main->func;
        $merge->inheritUndefinedLocals = true;
        $merge->addOpCode(new OpCode(OpCode::TYPE_RETURN_VOID));

        $tryBody = new Block($main->orig);
        $tryBody->func = $main->func;
        $tryBody->inheritUndefinedLocals = true;
        $tryJump = new OpCode(OpCode::TYPE_JUMP);
        $tryJump->block1 = $merge;
        $tryBody->addOpCode($tryJump);

        $catchBody = new Block($main->orig);
        $catchBody->func = $main->func;
        $catchBody->inheritUndefinedLocals = true;
        $catchJump = new OpCode(OpCode::TYPE_JUMP);
        $catchJump->block1 = $merge;
        $catchBody->addOpCode($catchJump);

        $tryOp = new OpCode(OpCode::TYPE_TRY);
        $tryOp->block1 = $tryBody;
        $tryOp->block2 = $merge;
        $main->addOpCode($tryOp);

        $catchOp = new OpCode(OpCode::TYPE_CATCH);
        $catchOp->block1 = $catchBody;
        $catchOp->block2 = $merge;
        $catchOp->catchTypes = 'throwable';
        $main->addOpCode($catchOp);
    }

    protected function cfgTypeUsesDnfShape(?Op\Type $declared): bool
    {
        if (null === $declared) {
            return false;
        }
        if ($declared instanceof Op\Type\Union_ || $declared instanceof Op\Type\Intersection) {
            return true;
        }
        if ($declared instanceof Op\Type\Nullable) {
            return true;
        }

        return false;
    }

    protected function cfgTypeIsStandaloneNever(?Op\Type $type): bool
    {
        if (null === $type) {
            return false;
        }
        if ($type instanceof Op\Type\Never_) {
            return true;
        }

        return $type instanceof Op\Type\Literal && 'never' === strtolower($type->name);
    }

    protected function cfgTypeContainsNever(?Op\Type $type): bool
    {
        if (null === $type) {
            return false;
        }
        if ($type instanceof Op\Type\Never_) {
            return true;
        }
        if ($type instanceof Op\Type\Literal && 'never' === strtolower($type->name)) {
            return true;
        }
        if ($type instanceof Op\Type\Union_) {
            foreach ($type->types as $member) {
                if ($this->cfgTypeContainsNever($member)) {
                    return true;
                }
            }
        }
        if ($type instanceof Op\Type\Intersection) {
            foreach ($type->types as $member) {
                if ($this->cfgTypeContainsNever($member)) {
                    return true;
                }
            }
        }
        if ($type instanceof Op\Type\Nullable) {
            return $this->cfgTypeContainsNever($type->subtype);
        }

        return false;
    }

    /**
     * True when `never` appears inside an intersection (not a top-level union arm only).
     */
    protected function cfgTypeContainsNeverInIntersection(?Op\Type $type): bool
    {
        if (null === $type) {
            return false;
        }
        if ($type instanceof Op\Type\Intersection) {
            foreach ($type->types as $member) {
                if ($this->cfgTypeIsStandaloneNever($member)) {
                    return true;
                }
                if ($this->cfgTypeContainsNeverInIntersection($member)) {
                    return true;
                }
            }

            return false;
        }
        if ($type instanceof Op\Type\Union_) {
            foreach ($type->types as $member) {
                if ($this->cfgTypeContainsNeverInIntersection($member)) {
                    return true;
                }
            }

            return false;
        }
        if ($type instanceof Op\Type\Nullable) {
            return $this->cfgTypeContainsNeverInIntersection($type->subtype);
        }

        return false;
    }

    protected function cfgTypeIsNullLiteral(?Op\Type $type): bool
    {
        return $type instanceof Op\Type\Literal && 'null' === strtolower($type->name);
    }

    protected function cfgTypeIsLiteralBoolName(?Op\Type $type, string $name): bool
    {
        return $type instanceof Op\Type\Literal && $name === strtolower($type->name);
    }

    protected function cfgTypeContainsLiteralBool(?Op\Type $type, string $name): bool
    {
        if (null === $type) {
            return false;
        }
        if ($this->cfgTypeIsLiteralBoolName($type, $name)) {
            return true;
        }
        if ($type instanceof Op\Type\Union_) {
            foreach ($type->types as $member) {
                if ($this->cfgTypeContainsLiteralBool($member, $name)) {
                    return true;
                }
            }
        }
        if ($type instanceof Op\Type\Intersection) {
            foreach ($type->types as $member) {
                if ($this->cfgTypeContainsLiteralBool($member, $name)) {
                    return true;
                }
            }
        }
        if ($type instanceof Op\Type\Nullable) {
            return $this->cfgTypeContainsLiteralBool($type->subtype, $name);
        }

        return false;
    }

    /**
     * Zend zend_compile_type — redundant true|false union must use bool (#12045).
     */
    protected function assertNoRedundantTrueFalseUnion(?Op\Type $type): void
    {
        if (
            $this->cfgTypeContainsLiteralBool($type, 'true')
            && $this->cfgTypeContainsLiteralBool($type, 'false')
        ) {
            $this->throwCompileError('Type contains both true and false, bool should be used instead');
        }
    }

    protected function cfgTypeContainsNull(?Op\Type $type): bool
    {
        if (null === $type) {
            return false;
        }
        if ($this->cfgTypeIsNullLiteral($type)) {
            return true;
        }
        if ($type instanceof Op\Type\Union_) {
            foreach ($type->types as $member) {
                if ($this->cfgTypeContainsNull($member)) {
                    return true;
                }
            }
        }
        if ($type instanceof Op\Type\Intersection) {
            foreach ($type->types as $member) {
                if ($this->cfgTypeContainsNull($member)) {
                    return true;
                }
            }
        }
        if ($type instanceof Op\Type\Nullable) {
            return $this->cfgTypeContainsNull($type->subtype);
        }

        return false;
    }

    /**
     * Zend zend_handle_never_type — PHP 8.2+ allows never in parameter/return unions (#7414).
     */
    protected function assertFunctionSignatureNeverType(?Op\Type $type): void
    {
        if ($this->cfgTypeContainsNeverInIntersection($type)) {
            $this->throwCompileError('never can only be used as a standalone type');
        }
        if ($type instanceof Op\Type\Nullable && $this->cfgTypeContainsNever($type->subtype)) {
            $this->throwCompileError('never can only be used as a standalone type');
        }
        if (
            $type instanceof Op\Type\Union_
            && $this->cfgTypeContainsNever($type)
            && $this->cfgTypeContainsNull($type)
        ) {
            $this->throwCompileError('never can only be used as a standalone type');
        }
    }

    /**
     * Zend zend_handle_property_type — never invalid on properties, including unions (#6967, #7052).
     */
    protected function assertPropertyDeclaredType(?Op\Type $type, string $propName): void
    {
        $this->assertNoRedundantTrueFalseUnion($type);
        if (!$this->cfgTypeContainsNever($type)) {
            return;
        }
        if ($this->cfgTypeIsStandaloneNever($type)) {
            $class = $this->compilingClassDisplayName ?? 'class';
            $this->throwCompileError(sprintf('Property %s::$%s cannot have type never', $class, $propName));
        }
        $this->throwCompileError('never can only be used as a standalone type');
    }

    protected function dnfTypeLabelFromCfgType(?Op\Type $declared): string
    {
        if (null === $declared) {
            return 'mixed';
        }

        return DnfType::labelFromCfgType(
            $declared,
            fn (Op\Type\Intersection $t) => $this->intersectionNamesFromCfgType($t),
            fn (Op\Type\Intersection $t) => $this->intersectionDisplayFromCfgType($t),
            fn (Op\Type\Reference $t) => $this->staticNameFromCfgType($t)
        );
    }

    protected function intersectionDisplayFromCfgType(Op\Type\Intersection $type): string
    {
        $names = [];
        foreach ($type->types as $member) {
            $name = $this->staticNameFromCfgType($member);
            if (null === $name) {
                $this->throwCompileError('Intersection type members must be interface names');
            }
            $names[] = ltrim($name, '\\');
        }

        return implode('&', $names);
    }

    protected function staticNameFromCfgType(?Op\Type $type): ?string
    {
        if (null === $type) {
            return null;
        }
        if ($type instanceof Op\Type\Literal) {
            return $type->name;
        }
        if ($type instanceof Op\Type\Reference) {
            return $this->staticNameFromOperand($type->declaration);
        }

        return null;
    }

    protected function assertParamDeclaredType(?Op\Type $declared): void
    {
        $this->assertFunctionSignatureNeverType($declared);
        $this->assertNoRedundantTrueFalseUnion($declared);
        if ($this->cfgTypeIsStandaloneNever($declared)) {
            $this->throwCompileError('never cannot be used as a parameter type');
        }
    }

    protected function applyParamDeclaredType(Op\Expr\Param $param, Block $block, int $slot, bool $variadicElement = false): void
    {
        $declared = $param->declaredType;
        $this->assertParamDeclaredType($declared);
        if (null !== $declared) {
            $block->paramDeclaredTypes[$slot] = $declared;
        }
        if ($declared instanceof Op\Type\Reference) {
            $className = $this->staticNameFromCfgType($declared);
            if (null !== $className && '' !== $className) {
                $label = ltrim($className, '\\');
                if ($variadicElement) {
                    $block->paramVariadicElementTypeConstraints[$slot] = Variable::TYPE_OBJECT;
                } else {
                    $block->paramTypeConstraints[$slot] = Variable::TYPE_OBJECT;
                    $block->paramClassConstraints[$slot] = $className;
                    $block->paramDeclaredTypeLabels[$slot] = $label;
                }
            }

            return;
        }
        if ($declared instanceof Op\Type\Intersection) {
            $display = $this->intersectionDisplayFromCfgType($declared);
            if ($variadicElement) {
                $block->paramVariadicElementTypeConstraints[$slot] = Variable::TYPE_OBJECT;
                $block->paramVariadicElementIntersectionConstraints[$slot] = $this->intersectionNamesFromCfgType($declared);
                $block->paramVariadicElementIntersectionDisplayLabels[$slot] = $display;
            } else {
                $block->paramTypeConstraints[$slot] = Variable::TYPE_OBJECT;
                $block->paramIntersectionConstraints[$slot] = $this->intersectionNamesFromCfgType($declared);
                $block->paramIntersectionDisplayLabels[$slot] = $display;
            }

            return;
        }
        $arraySpec = $this->genericArraySpecFromCfgType($declared);
        if (null !== $arraySpec) {
            if ($variadicElement) {
                $block->paramVariadicElementTypeConstraints[$slot] = Variable::TYPE_ARRAY;
                $block->paramVariadicElementGenericArrayTypeSpecs[$slot] = $arraySpec;
            } else {
                $block->paramTypeConstraints[$slot] = Variable::TYPE_ARRAY;
                $block->paramGenericArrayTypeSpecs[$slot] = $arraySpec;
            }

            return;
        }
        if ($this->cfgTypeUsesDnfShape($declared)) {
            $dnfArms = DnfType::armsFromCfgType(
                $declared,
                fn (Op\Type\Intersection $t) => $this->intersectionNamesFromCfgType($t),
                fn (Op\Type\Intersection $t) => $this->intersectionDisplayFromCfgType($t),
                fn (Op\Type\Reference $t) => $this->staticNameFromCfgType($t)
            );
            if (DnfType::hasConstraints($dnfArms)) {
                if ($variadicElement) {
                    $block->paramVariadicElementDnfConstraints[$slot] = $dnfArms;
                } else {
                    $block->paramDnfConstraints[$slot] = $dnfArms;
                }

                return;
            }
        }
        if ($declared instanceof Op\Type\Literal) {
            $declName = strtolower($declared->name);
            if ('true' === $declName || 'false' === $declName) {
                if ($variadicElement) {
                    $block->paramVariadicElementTypeConstraints[$slot] = Variable::TYPE_BOOLEAN;
                } else {
                    $block->paramTypeConstraints[$slot] = Variable::TYPE_BOOLEAN;
                    $block->paramLiteralBoolTypes[$slot] = $declName;
                }

                return;
            }
            if ('iterable' === $declName) {
                $block->paramIterableSlots[$slot] = true;

                return;
            }
            if ('mixed' !== $declName) {
                $rawType = Type::fromDecl($declared->name);
                $mapped = Variable::mapFromType($rawType);
                if ($mapped !== Variable::TYPE_UNDEFINED) {
                    if ($variadicElement) {
                        $block->paramVariadicElementTypeConstraints[$slot] = $mapped;
                    } else {
                        $block->paramTypeConstraints[$slot] = $mapped;
                    }
                }
            }
        }
    }

    protected function declNameFromCfgType(?Op\Type $declared): ?string
    {
        if ($declared instanceof Op\Type\Literal) {
            return $declared->name;
        }
        if ($declared instanceof Op\Type\Reference) {
            return $this->staticNameFromOperand($declared->declaration);
        }

        return null;
    }

    protected function genericArraySpecFromCfgType(?Op\Type $declared): ?GenericArrayTypeSpec
    {
        $name = $this->declNameFromCfgType($declared);

        return null !== $name ? GenericArrayTypeSpec::tryParseDeclName($name) : null;
    }

    protected function compileClassBody(CfgBlock $block, int $type, ?string $className = null): Block {
        $result = new Block($block);
        $prevClassLc = $this->compilingClassLc;
        $prevClassDisplayName = $this->compilingClassDisplayName;
        $prevInstancePropertyNames = $this->compilingClassInstancePropertyNames;
        $prevMethodNames = $this->compilingClassMethodNames;
        $this->compilingClassInstancePropertyNames = [];
        $this->compilingClassMethodNames = [];
        if (null !== $className) {
            $this->compilingClassLc = strtolower(ltrim($className, '\\'));
            $this->compilingClassDisplayName = ltrim($className, '\\');
            if (!isset($this->compileTimeClassConsts[$this->compilingClassLc])) {
                $this->compileTimeClassConsts[$this->compilingClassLc] = [];
            }
            if (!isset($this->compileTimeClassConstVisibility[$this->compilingClassLc])) {
                $this->compileTimeClassConstVisibility[$this->compilingClassLc] = [];
            }
            if (!isset($this->compileTimeClassConstDeprecated[$this->compilingClassLc])) {
                $this->compileTimeClassConstDeprecated[$this->compilingClassLc] = [];
            }
        } else {
            $this->compilingClassDisplayName = null;
        }
        foreach ($block->children as $child) {
            switch (get_class($child)) {
                case Op\Stmt\Property::class:
                    if (
                        OpCode::TYPE_DECLARE_CLASS !== $type
                        && OpCode::TYPE_DECLARE_INTERFACE !== $type
                        && OpCode::TYPE_DECLARE_TRAIT !== $type
                    ) {
                        $this->throwCompileLogic('Properties are only supported on classes, interfaces, and traits for now');
                    }
                    if (OpCode::TYPE_DECLARE_INTERFACE === $type) {
                        if ($child->static && !$this->interfaceStaticPropertyHookAllowed($child->name)) {
                            $this->throwCompileLogic('Interfaces cannot declare static properties');
                        }
                        if (!is_null($child->defaultBlock) || null !== $child->defaultVar) {
                            $this->throwCompileLogic('Interface properties cannot have default values');
                        }
                    }
                    if (
                        OpCode::TYPE_DECLARE_CLASS === $type
                        && !$child->static
                        && $child->name instanceof Operand\Literal
                        && is_string($child->name->value)
                    ) {
                        $this->registerInstancePropertyDeclaration($child->name->value);
                    }
                    $propName = '?';
                    if ($child->name instanceof Operand\Literal && is_string($child->name->value)) {
                        $propName = $child->name->value;
                    }
                    $this->assertPropertyDeclaredType($child->declaredType, $propName);
                    $propertyDeclName = $this->declNameFromCfgType($child->declaredType);
                    $declared = null !== $propertyDeclName
                        ? Type::fromDecl($propertyDeclName)
                        : $this->typeFromPropertyDecl($child);
                    if ($child->static && null !== $this->currentClassStaticPropertyCompile) {
                        $staticPropName = $this->staticNameFromOperand($child->name);
                        if (null !== $staticPropName) {
                            $this->compiledClassStaticProperties[$this->currentClassStaticPropertyCompile][strtolower($staticPropName)] = true;
                        }
                    }
                    $declareType = $child->static
                        ? OpCode::TYPE_DECLARE_STATIC_PROPERTY
                        : OpCode::TYPE_DECLARE_PROPERTY;
                    $defaultSlot = null;
                    if (null !== $child->defaultVar) {
                        $defaultSlot = $this->tryFoldPropertyDefaultSlot($child, $result);
                        if (null === $defaultSlot) {
                            if (null !== $child->defaultBlock) {
                                $this->compileOps($child->defaultBlock->children, $result);
                            }
                            $defaultSlot = $this->compileOperand($child->defaultVar, $result, true);
                            if (!isset($result->constants[$defaultSlot])) {
                                if ($this->propertyDefaultIsRuntimeNew($child)) {
                                    // Per-instance `new` defaults: opcodes precede DECLARE_*; VM init at TYPE_NEW (#3391).
                                    $defaultSlot = null;
                                } else {
                                    $propName = '?';
                                    if ($child->name instanceof Operand\Literal && is_string($child->name->value)) {
                                        $propName = $child->name->value;
                                    }
                                    $this->throwCompileLogic(
                                        'Property default must be a compile-time constant (#3803): $'.$propName
                                    );
                                }
                            }
                        }
                    }
                    if (null !== $defaultSlot && null !== $child->declaredType) {
                        $defaultVm = $result->constants[$defaultSlot] ?? null;
                        if (null !== $defaultVm) {
                            $propName = '?';
                            if ($child->name instanceof Operand\Literal && is_string($child->name->value)) {
                                $propName = $child->name->value;
                            }
                            $classPrefix = $this->compilingClassDisplayName ?? 'class';
                            $targetName = ($child->static ? $classPrefix.'::' : '').'$'.$propName;
                            $this->assertCompileTimeDefaultMatchesDeclaredType(
                                $defaultVm,
                                $child->declaredType,
                                'property',
                                $targetName
                            );
                        }
                    }
                    $typeSlot = $this->compileTypeConstrainedVariable(
                        $result,
                        $declared,
                        null !== $propertyDeclName ? $propertyDeclName : $child->declaredType
                    );
                    if (
                        isset($result->constants[$typeSlot])
                        && null !== $result->constants[$typeSlot]->dnfArms
                    ) {
                        $this->scriptHasDnfTypedProperties = true;
                    }
                    $declare = new OpCode(
                        $declareType,
                        $this->compileOperand($child->name, $result, true),
                        $defaultSlot,
                        $typeSlot
                    );
                    $declare->propertyVisibility = MethodVisibility::mask($child->visibility);
                    $declare->propertySetVisibility = $this->asymmetricSetVisibilityFromCfgOp($child);
                    $declare->propertyGetVisibility = $this->asymmetricGetVisibilityFromCfgOp($child);
                    if (!$child->static) {
                        $declare->propertyReadonly = (property_exists($child, 'readonly') && $child->readonly)
                            || (property_exists($child, 'propertyFlags') && $this->isReadonlyPropertyFlags($child->propertyFlags))
                            || $this->isReadonlyPropertyFlags($child->visibility);
                    }
                    $this->assignAttributeMetadata($declare, $child);
                    AttributeTargetValidator::assertEntriesForTarget(
                        $declare->attributeEntries,
                        AttributeSupport::TARGET_PROPERTY,
                        'property',
                        $this->attributeClassRegistry,
                        true
                    );
                    AttributeNames::assertOverrideMethodTargetOnly($declare->attributeNames, 'property');
                    AttributeNames::assertCompileTimeConstTargetOnly($declare->attributeNames, 'property');
                    AttributeNames::assertSensitiveParameterParamTargetOnly($declare->attributeNames, 'property');
                    $declare->deprecatedMetadata = DeprecatedMetadata::fromOp($child);
                    $this->assignSourceMetadata($declare, $child);
                    $result->addOpCode($declare);
                    break;
                case Op\Stmt\ClassMethod::class:
                    $this->compileClassMethodDeclaration($child, $result);
                    break;
                case Op\Terminal\Const_::class:
                    if (
                        OpCode::TYPE_DECLARE_CLASS !== $type
                        && OpCode::TYPE_DECLARE_INTERFACE !== $type
                        && OpCode::TYPE_DECLARE_TRAIT !== $type
                    ) {
                        $this->throwCompileLogic('Class constants are only supported on classes, interfaces, and traits for now');
                    }
                    $this->compileClassConstDeclaration($child, $result);
                    break;
                case Op\Stmt\TraitUse::class:
                    if (
                        OpCode::TYPE_DECLARE_CLASS !== $type
                        && OpCode::TYPE_DECLARE_TRAIT !== $type
                    ) {
                        $this->throwCompileLogic('Trait use is only supported on classes and traits for now');
                    }
                    foreach ($child->traits as $traitOperand) {
                        $result->addOpCode(new OpCode(
                            OpCode::TYPE_USE_TRAIT,
                            $this->compileOperand($traitOperand, $result, true)
                        ));
                    }
                    $adaptOp = new OpCode(OpCode::TYPE_TRAIT_USE_ADAPTATION);
                    $adaptOp->traitAdaptations = [] !== $child->adaptations
                        ? $this->compileTraitAdaptations($child->adaptations)
                        : [];
                    $result->addOpCode($adaptOp);
                    break;
                default:
                    $this->throwCompileLogic('Unsupported class body element: ' . get_class($child));
            }
        }
        $this->compilingClassLc = $prevClassLc;
        $this->compilingClassDisplayName = $prevClassDisplayName;
        $this->compilingClassInstancePropertyNames = $prevInstancePropertyNames;
        $this->compilingClassMethodNames = $prevMethodNames;

        return $result;
    }

    protected function registerMethodDeclaration(string $methodName): void
    {
        $lc = strtolower($methodName);
        if (isset($this->compilingClassMethodNames[$lc])) {
            $class = $this->compilingClassDisplayName ?? 'class';
            $this->throwCompileError(sprintf('Cannot redeclare %s::%s()', $class, $methodName));
        }
        $this->compilingClassMethodNames[$lc] = true;
    }

    protected function registerInstancePropertyDeclaration(string $propName): void
    {
        if (isset($this->compilingClassInstancePropertyNames[$propName])) {
            $class = $this->compilingClassDisplayName ?? 'class';
            $this->throwCompileError(sprintf('Cannot redeclare %s::$%s', $class, $propName));
        }
        $this->compilingClassInstancePropertyNames[$propName] = true;
    }

    protected function compileClassConstDeclaration(Op\Terminal\Const_ $child, Block $result): void
    {
        $constName = $this->staticNameFromOperand($child->name);
        if (null !== $constName && null !== $this->compilingClassLc) {
            $lc = strtolower($constName);
            if (isset($this->compileTimeClassConsts[$this->compilingClassLc][$lc])) {
                // Idempotent re-parse when a JIT helper was already inlined from require_once (#9753, #1492).
                return;
            }
        }
        $valueSlot = $this->tryFoldClassConstValueSlot($child, $result);
        if (null === $valueSlot) {
            $this->compileOps($child->valueBlock->children, $result);
            $valueSlot = $this->compileOperand($child->value, $result, true);
        }
        $typeSlot = null;
        if (property_exists($child, 'declaredType') && null !== $child->declaredType) {
            if (null !== $constName) {
                $result->classConstDeclaredTypes[strtolower($constName)] = $child->declaredType;
            }
            if (!$this->cfgDeclaredTypeIsMixed($child->declaredType)) {
                $this->rejectTypedTraitConstantIfUnsupported($child->name);
                $this->rejectTypedInterfaceConstantIfUnsupported($child->name);
                $declared = $this->typeFromClassConstDecl($child);
                $typeSlot = $this->compileTypeConstrainedVariable($result, $declared, $child->declaredType);
                if (isset($result->constants[$valueSlot])) {
                    $this->verifyClassConstCompileTimeType(
                        $child->name,
                        $result->constants[$valueSlot],
                        $typeSlot,
                        $result
                    );
                }
            }
        }
        $constOp = new OpCode(
            OpCode::TYPE_DECLARE_CLASS_CONST,
            $this->compileOperand($child->name, $result, true),
            $valueSlot,
            $typeSlot
        );
        $constOp->classConstVisibilityFlags = property_exists($child, 'flags')
            ? (int) $child->flags
            : CfgFunc::FLAG_PUBLIC;
        if ($this->cfgTerminalConstIsEnumCase($child)) {
            $constOp->isEnumCaseDeclare = true;
            if (null !== $this->compilingClassLc) {
                $constName = $this->staticNameFromOperand($child->name);
                if (null !== $constName) {
                    $this->compileTimeEnumCaseConstNames[$this->compilingClassLc][strtolower($constName)] = true;
                }
            }
        }
        $constOp->deprecatedMetadata = DeprecatedMetadata::fromOp($child);
        $this->assignAttributeMetadata($constOp, $child);
        AttributeNames::assertCompileTimeConstTargetOnly($constOp->attributeNames, 'class constant');
        AttributeNames::assertSensitiveParameterParamTargetOnly($constOp->attributeNames, 'class constant');
        $result->addOpCode($constOp);
        if (null !== $this->compilingClassLc && isset($result->constants[$valueSlot])) {
            $constName = $this->staticNameFromOperand($child->name);
            if (null !== $constName) {
                $backing = new Variable();
                $backing->copyFrom($result->constants[$valueSlot]);
                if ($constOp->isEnumCaseDeclare) {
                    $stored = $this->compileTimeEnumCaseVar(
                        $this->compilingClassDisplayName ?? $this->compilingClassLc,
                        $constName,
                        $backing,
                        $this->compileTimeEnumBackedTypes[$this->compilingClassLc] ?? null
                    );
                } else {
                    $stored = new Variable();
                    $stored->copyFrom($backing);
                }
                $lcConst = strtolower($constName);
                $this->compileTimeClassConsts[$this->compilingClassLc][$lcConst] = $stored;
                $this->compileTimeClassConstVisibility[$this->compilingClassLc][$lcConst]
                    = ClassConstVisibility::mask($constOp->classConstVisibilityFlags);
                if (null !== $constOp->deprecatedMetadata) {
                    $this->compileTimeClassConstDeprecated[$this->compilingClassLc][$lcConst]
                        = $constOp->deprecatedMetadata;
                }
            }
        }
    }

    /**
     * Distinguish enum `case` from user `const` when php-cfg isEnumCase is missing (#5832).
     * Bare `const` without visibility has flags=0 like cases; trust isEnumCase when set (#6878).
     */
    private function cfgTerminalConstIsEnumCase(Op\Terminal\Const_ $child): bool
    {
        if (property_exists($child, 'isEnumCase')) {
            return $child->isEnumCase;
        }
        if (null === $this->compilingClassLc
            || !array_key_exists($this->compilingClassLc, $this->compileTimeEnumBackedTypes)) {
            return false;
        }
        if (property_exists($child, 'declaredType') && null !== $child->declaredType) {
            return false;
        }
        $flags = property_exists($child, 'flags') ? (int) $child->flags : 0;
        // Enum cases cannot be protected/private/final; those must be user `const`.
        if (0 !== ($flags & (\PHPCfg\Func::FLAG_PROTECTED | \PHPCfg\Func::FLAG_PRIVATE | \PHPCfg\Func::FLAG_FINAL))) {
            return false;
        }

        // When php-cfg omits isEnumCase (#5832), try to distinguish backed enum `case` from user `const`.
        // Heuristic: backed enum cases must have a scalar literal backing value of the enum's backed type.
        $backedType = $this->compileTimeEnumBackedTypes[$this->compilingClassLc] ?? null;
        if (null === $backedType) {
            // Unit enums: enum cases have no backing scalar; default to legacy heuristic.
            return 0 === $flags;
        }
        $vm = $this->vmVariableFromCfgLiteralOperand($child->value);
        if (null === $vm) {
            return false;
        }
        if ('int' === $backedType) {
            return Variable::TYPE_INTEGER === $vm->type;
        }
        if ('string' === $backedType) {
            return Variable::TYPE_STRING === $vm->type;
        }

        return false;
    }

    /**
     * Compile-time enum case singleton for folds (default args, class const inits; #5514).
     */
    private function compileTimeEnumCaseVar(
        string $enumName,
        string $caseName,
        Variable $backing,
        ?string $backedType
    ): Variable {
        $entry = new ClassEntry(ltrim($enumName, '\\'));
        $entry->isEnum = true;
        $entry->backedType = $backedType;
        EnumSupport::ensureBuiltinEnumInterfaces($entry);

        return EnumCaseSupport::compileTimeCaseVariable($entry, $caseName, $backing);
    }

    private function compileTimeStoredValueIsEnumCaseBackingScalar(
        string $lcClass,
        string $lcConst,
        Variable $stored
    ): bool {
        if (!array_key_exists($lcClass, $this->compileTimeEnumBackedTypes)) {
            return false;
        }
        if (!isset($this->compileTimeEnumCaseConstNames[$lcClass][$lcConst])) {
            return false;
        }
        if (Variable::TYPE_OBJECT === $stored->type && EnumCaseSupport::isEnumCase($stored->toObject())) {
            return false;
        }

        return $stored->is(Variable::TYPE_INTEGER) || $stored->is(Variable::TYPE_STRING);
    }

    protected function tryFoldClassConstValueSlot(Op\Terminal\Const_ $terminal, Block $block): ?int
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
            if (
                2 === \count($children)
                && $children[0] instanceof Op\Expr\ClassConstFetch
                && $children[1] instanceof Op\Expr\ArrayDimFetch
            ) {
                $vm = $this->tryFoldClassConstArraySubscriptExpr(
                    $children[0],
                    $children[1],
                    $block
                );
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
            $vm = $this->tryFoldClassConstMatchValueBlock(
                $terminal->valueBlock,
                $terminal->value,
                $block,
                $children
            );
            if (null !== $vm) {
                return $block->registerConstant(new Operand\Temporary(), $vm);
            }
        }
        $vm = $this->vmVariableFromCfgLiteralOperand($terminal->value);
        if (null === $vm) {
            return null;
        }

        return $block->registerConstant(new Operand\Temporary(), $vm);
    }

    /**
     * Fold lowered match() in class constant initializers (#9987, zend_const_expr_to_zval).
     *
     * @param list<Op> $defaultBlockChildren
     */
    protected function tryFoldClassConstMatchValueBlock(
        CfgBlock $entry,
        Operand $result,
        Block $block,
        array $defaultBlockChildren
    ): ?Variable {
        $subject = $this->extractClassConstMatchSubject($entry, $result, $block, $defaultBlockChildren);
        if (null === $subject) {
            return null;
        }

        return $this->evaluateClassConstMatchCfgBlock(
            $entry,
            $subject,
            $result,
            $block,
            $defaultBlockChildren,
            0
        );
    }

    private function extractClassConstMatchSubject(
        CfgBlock $entry,
        Operand $result,
        Block $block,
        array $defaultBlockChildren
    ): ?Variable {
        $start = 0;
        if (isset($entry->children[0]) && $this->isMatchSeedAssign($entry->children[0], $result)) {
            $start = 1;
        }
        $count = \count($entry->children);
        for ($i = $start; $i < $count; ++$i) {
            $child = $entry->children[$i];
            if (!$child instanceof Op\Expr\BinaryOp\Identical) {
                continue;
            }

            return $this->tryFoldCompileTimeOperandDefault(
                $child->left,
                $block,
                $defaultBlockChildren,
                true
            );
        }

        return null;
    }

    /**
     * @param list<Op> $defaultBlockChildren
     */
    private function evaluateClassConstMatchCfgBlock(
        CfgBlock $cfgBlock,
        Variable $subject,
        Operand $result,
        Block $block,
        array $defaultBlockChildren,
        int $startIndex
    ): ?Variable {
        $children = $cfgBlock->children;
        if (0 === $startIndex && isset($children[0]) && $this->isMatchSeedAssign($children[0], $result)) {
            $startIndex = 1;
        }
        $count = \count($children);
        for ($i = $startIndex; $i < $count; ++$i) {
            $child = $children[$i];
            if (
                $child instanceof Op\Expr\BinaryOp\Identical
                && isset($children[$i + 1])
                && $children[$i + 1] instanceof Op\Stmt\JumpIf
            ) {
                $jumpIf = $children[$i + 1];
                $pattern = $this->tryFoldCompileTimeOperandDefault(
                    $child->right,
                    $block,
                    $defaultBlockChildren,
                    true
                );
                if (null === $pattern) {
                    return null;
                }
                if ($subject->identicalTo($pattern)) {
                    return $this->evaluateClassConstMatchArmBlock(
                        $jumpIf->if,
                        $result,
                        $block,
                        $defaultBlockChildren
                    );
                }

                return $this->evaluateClassConstMatchCfgBlock(
                    $jumpIf->else,
                    $subject,
                    $result,
                    $block,
                    $defaultBlockChildren,
                    0
                );
            }
            if ($child instanceof Op\Terminal\Throw_) {
                return null;
            }
            if ($child instanceof Op\Expr\Assign && $this->operandsReferToSameVariable($child->var, $result)) {
                return $this->tryFoldCompileTimeOperandDefault(
                    $child->expr,
                    $block,
                    $defaultBlockChildren,
                    true
                );
            }
        }

        return null;
    }

    /**
     * @param list<Op> $defaultBlockChildren
     */
    private function evaluateClassConstMatchArmBlock(
        CfgBlock $armBlock,
        Operand $result,
        Block $block,
        array $defaultBlockChildren
    ): ?Variable {
        foreach ($armBlock->children as $child) {
            if ($child instanceof Op\Terminal\Throw_) {
                return null;
            }
            if ($child instanceof Op\Expr\Assign && $this->operandsReferToSameVariable($child->var, $result)) {
                return $this->tryFoldCompileTimeOperandDefault(
                    $child->expr,
                    $block,
                    $defaultBlockChildren,
                    true
                );
            }
        }

        return null;
    }

    private function isMatchSeedAssign(Op $op, Operand $result): bool
    {
        if (!$op instanceof Op\Expr\Assign) {
            return false;
        }
        if (!$this->operandsReferToSameVariable($op->var, $result)) {
            return false;
        }
        $lit = $this->vmVariableFromCfgLiteralOperand($op->expr);

        return null !== $lit && Variable::TYPE_STRING === $lit->type && '' === $lit->toString();
    }

    /**
     * Fold {@code self::ARR[1]} in class constant scalar expressions (#5465, zend_compile.c).
     */
    protected function tryFoldClassConstArraySubscriptExpr(
        Op\Expr\ClassConstFetch $fetch,
        Op\Expr\ArrayDimFetch $dimFetch,
        Block $block
    ): ?Variable {
        if (null === $dimFetch->dim) {
            return null;
        }
        $base = $this->tryFoldClassConstFetchDefault($fetch, $block);
        if (null === $base || !$base->is(Variable::TYPE_ARRAY)) {
            return null;
        }
        $dimVm = $this->vmVariableFromCfgLiteralOperand($dimFetch->dim);
        if (null === $dimVm) {
            return null;
        }
        $table = $base->toArray();
        if (!$table->keyExists($dimVm)) {
            return null;
        }
        $elem = $table->findVariable($dimVm, false);
        if (null === $elem) {
            return null;
        }
        $value = new Variable();
        $value->copyFrom($elem->resolveIndirect());

        return $value;
    }

    protected function typeFromClassConstDecl(Op\Terminal\Const_ $child): Type
    {
        if ($child->declaredType instanceof Op\Type\Literal) {
            return Type::fromDecl($child->declaredType->name);
        }
        if (null !== $child->declaredType) {
            return Type::fromTypeDecl($child->declaredType);
        }

        return Type::mixed();
    }

    protected function cfgDeclaredTypeIsMixed(?Op\Type $declaredType): bool
    {
        if (null === $declaredType) {
            return true;
        }
        if ($declaredType instanceof Op\Type\Mixed_) {
            return true;
        }

        return $declaredType instanceof Op\Type\Literal && 'mixed' === strtolower($declaredType->name);
    }

    protected function verifyClassConstCompileTimeType(
        Operand $nameOp,
        Variable $value,
        int $typeSlot,
        Block $block
    ): void {
        if (!isset($block->constants[$typeSlot])) {
            return;
        }
        $constName = $nameOp instanceof Operand\Literal ? (string) $nameOp->value : 'constant';
        try {
            TypeCheck::assertClassConstantTypedValue($value, $block->constants[$typeSlot], $constName);
        } catch (\TypeError $e) {
            $this->throwCompileError($e->getMessage());
        }
    }

    protected function verifyGlobalConstCompileTimeType(
        Operand $nameOp,
        Variable $value,
        int $typeSlot,
        Block $block
    ): void {
        if (!isset($block->constants[$typeSlot])) {
            return;
        }
        $constName = $nameOp instanceof Operand\Literal ? (string) $nameOp->value : 'constant';
        try {
            TypeCheck::assertGlobalConstantTypedValue($value, $block->constants[$typeSlot], $constName);
        } catch (\TypeError $e) {
            $this->throwCompileError($e->getMessage());
        }
    }

    /**
     * Zend 8.2 rejects typed trait constants at parse time; enable at 8.3+ (#5212).
     */
    protected function rejectTypedTraitConstantIfUnsupported(Operand $nameOp): void
    {
        if (CompilerVersion::supportsTypedTraitConstants()) {
            return;
        }
        if (
            null === $this->compilingClassLc
            || !$this->classCompileRegistry->isTrait($this->compilingClassLc)
        ) {
            return;
        }
        $constName = $this->staticNameFromOperand($nameOp) ?? 'constant';
        $this->throwCompileError(
            sprintf('syntax error, unexpected identifier "%s", expecting "="', $constName)
        );
    }

    /**
     * Zend 8.2 rejects typed interface constants at parse time; enable at 8.3+ (#5980, #7042).
     */
    protected function rejectTypedInterfaceConstantIfUnsupported(Operand $nameOp): void
    {
        if (CompilerVersion::supportsInterfaceTypedConstants()) {
            return;
        }
        if (
            null === $this->compilingClassLc
            || !$this->classCompileRegistry->isInterface($this->compilingClassLc)
        ) {
            return;
        }
        $constName = $this->staticNameFromOperand($nameOp) ?? 'constant';
        $this->throwCompileError(
            sprintf('syntax error, unexpected identifier "%s", expecting "="', $constName)
        );
    }

    /**
     * @param list<\PhpParser\Node\Stmt\TraitUseAdaptation> $adaptations
     *
     * @return list<array<string, mixed>>
     */
    protected function compileTraitAdaptations(array $adaptations): array
    {
        $out = [];
        foreach ($adaptations as $adaptation) {
            if ($adaptation instanceof \PhpParser\Node\Stmt\TraitUseAdaptation\Alias) {
                $entry = [
                    'kind' => 'alias',
                    'trait' => null !== $adaptation->trait ? $adaptation->trait->toString() : null,
                    'method' => $adaptation->method->name,
                    'newName' => null !== $adaptation->newName ? $adaptation->newName->name : null,
                ];
                if (null !== $adaptation->newModifier) {
                    $entry['newModifier'] = MethodVisibility::mask((int) $adaptation->newModifier);
                }
                $out[] = $entry;
            } elseif ($adaptation instanceof \PhpParser\Node\Stmt\TraitUseAdaptation\Precedence) {
                $insteadof = [];
                foreach ($adaptation->insteadof as $name) {
                    $insteadof[] = $name->toString();
                }
                $out[] = [
                    'kind' => 'precedence',
                    'trait' => $adaptation->trait->toString(),
                    'method' => $adaptation->method->name,
                    'insteadof' => $insteadof,
                ];
            } else {
                $this->throwCompileLogic('Unsupported TraitUseAdaptation node: ' . get_class($adaptation));
            }
        }

        return $out;
    }

    protected function isPromotedParam(Op\Expr\Param $param): bool
    {
        return property_exists($param, 'promotionFlags') && 0 !== $param->promotionFlags;
    }

    protected function compilePromotedPropertyDeclaration(Op\Expr\Param $param, Block $result): void
    {
        $propName = '?';
        if ($param->name instanceof Operand\Literal && is_string($param->name->value)) {
            $propName = $param->name->value;
            $this->registerInstancePropertyDeclaration($propName);
        }
        $this->assertPropertyDeclaredType($param->declaredType, $propName);
        $defaultSlot = $this->resolvePropertyOrParamDefaultSlot($param, $result);
        if (null !== $defaultSlot && null !== $param->declaredType) {
            $defaultVm = $result->constants[$defaultSlot] ?? null;
            if (null !== $defaultVm) {
                $propName = '?';
                if ($param->name instanceof Operand\Literal && is_string($param->name->value)) {
                    $propName = '$'.$param->name->value;
                }
                $this->assertCompileTimeDefaultMatchesDeclaredType(
                    $defaultVm,
                    $param->declaredType,
                    'property',
                    $propName
                );
            }
        }
        $declared = $this->typeFromParamDecl($param);
        $propName = new Operand\Literal($param->name->value);
        $propName->type = Type::string();
        $typeSlot = $this->compileTypeConstrainedVariable($result, $declared, $param->declaredType);
        if (isset($result->constants[$typeSlot]) && null !== $result->constants[$typeSlot]->dnfArms) {
            $this->scriptHasDnfTypedProperties = true;
        }
        $declare = new OpCode(
            OpCode::TYPE_DECLARE_PROPERTY,
            $this->compileOperand($propName, $result, true),
            $defaultSlot,
            $typeSlot
        );
        $declare->propertyReadonly = $this->isPromotedParamReadonly($param);
        $declare->propertyFromConstructorPromotion = true;
        $declare->propertyVisibility = MethodVisibility::mask($param->promotionFlags);
        $declare->propertySetVisibility = $this->asymmetricSetVisibilityFromCfgOp($param);
        $declare->propertyGetVisibility = $this->asymmetricGetVisibilityFromCfgOp($param);
        $declare->deprecatedMetadata = DeprecatedMetadata::fromOp($param);
        $this->assignAttributeMetadata($declare, $param);
        AttributeTargetValidator::assertPromotedParameterTargets($declare->attributeEntries, $this->attributeClassRegistry);
        AttributeNames::assertOverrideMethodTargetOnly($declare->attributeNames, 'property');
        AttributeNames::assertCompileTimeConstTargetOnly($declare->attributeNames, 'property');
        AttributeNames::assertSensitiveParameterParamTargetOnly($declare->attributeNames, 'property');
        $result->addOpCode($declare);
    }

    protected function isReadonlyPropertyFlags(int $flags): bool
    {
        return 0 !== ($flags & ClassReadonly::MODIFIER_READONLY);
    }

    protected function isPromotedParamReadonly(Op\Expr\Param $param): bool
    {
        return property_exists($param, 'promotionReadonly') && $param->promotionReadonly;
    }

    protected function asymmetricSetVisibilityFromCfgOp(Op $op): int
    {
        if (property_exists($op, 'setVisibility') && 0 !== (int) $op->setVisibility) {
            return (int) $op->setVisibility;
        }
        if (property_exists($op, 'promotionSetVisibility') && 0 !== (int) $op->promotionSetVisibility) {
            return (int) $op->promotionSetVisibility;
        }

        return AsymmetricVisibilityRewriter::extractSetVisibilityFromAttributes($op->getAttributes());
    }

    protected function asymmetricGetVisibilityFromCfgOp(Op $op): int
    {
        if (property_exists($op, 'getVisibility') && 0 !== (int) $op->getVisibility) {
            return (int) $op->getVisibility;
        }
        if (property_exists($op, 'promotionGetVisibility') && 0 !== (int) $op->promotionGetVisibility) {
            return (int) $op->promotionGetVisibility;
        }

        return AsymmetricVisibilityRewriter::extractGetVisibilityFromAttributes($op->getAttributes());
    }

    /**
     * @param list<Op\Expr\Param> $params
     */
    protected function compileCtorPromotionAssignments(Block $block, array $params): void
    {
        $thisVar = new Operand\Variable(new Operand\Literal('this'));
        $thisSlot = $block->getVarSlot($thisVar, true);

        foreach ($params as $param) {
            if (!$this->isPromotedParam($param)) {
                continue;
            }
            if (!($param->name instanceof Operand\Literal) || !is_string($param->name->value)) {
                $this->throwCompileLogic('Promoted constructor parameter must have a simple name');
            }
            $propName = new Operand\Literal($param->name->value);
            $propName->type = Type::string();
            $propSlot = $this->compileOperand($propName, $block, true);
            $fetchTmp = new Temporary();
            $fetchSlot = $block->getVarSlot($fetchTmp, false);
            // Param slot is already registered by compileParam(); do not mark as arg read or
            // getFrame fails on method entry (callArgs holds $this only) (#3816).
            $paramSlot = $this->compileOperand($param->result, $block, false);
            $block->addOpCode(new OpCode(
                OpCode::TYPE_PROPERTY_FETCH,
                $fetchSlot,
                $thisSlot,
                $propSlot
            ));
            $block->addOpCode(new OpCode(
                OpCode::TYPE_ASSIGN,
                $fetchSlot,
                $fetchSlot,
                $paramSlot
            ));
        }
    }

    protected function typeFromPropertyDecl(Op\Stmt\Property $child): Type
    {
        if ($child->declaredType instanceof Op\Type\Literal) {
            return Type::fromDecl($child->declaredType->name);
        }
        if (null !== $child->declaredType) {
            return Type::fromTypeDecl($child->declaredType);
        }

        return $child->type ?? Type::mixed();
    }

    protected function typeFromParamDecl(Op\Expr\Param $param): Type
    {
        if ($param->declaredType instanceof Op\Type\Literal) {
            return Type::fromDecl($param->declaredType->name);
        }
        if (null !== $param->declaredType) {
            return Type::fromTypeDecl($param->declaredType);
        }

        return Type::mixed();
    }

    protected function compileTypeConstrainedVariable(Block $block, Type $type, Op\Type|string|null $cfgTypeOrDeclName = null): int {
        $cfgType = $cfgTypeOrDeclName instanceof Op\Type ? $cfgTypeOrDeclName : null;
        $declName = is_string($cfgTypeOrDeclName) ? $cfgTypeOrDeclName : null;
        $var = new Variable(Variable::TYPE_UNDEFINED);
        $operand = new Operand\Temporary;
        $operand->type = $type;
        $return = $block->registerConstant($operand, $var);
        $arraySpec = null !== $declName ? GenericArrayTypeSpec::tryParseDeclName($declName) : null;
        if (null !== $arraySpec) {
            $var->typeConstraint = Variable::TYPE_ARRAY;
            $var->genericArrayTypeSpec = $arraySpec;
            $var->declaredTypeLabel = $declName;

            return $return;
        }
        $literalBoolName = null;
        if ($cfgType instanceof Op\Type\Literal) {
            $literalBoolName = strtolower($cfgType->name);
        } elseif (null !== $declName) {
            $literalBoolName = strtolower($declName);
        }
        if ('true' === $literalBoolName || 'false' === $literalBoolName) {
            $var->typeConstraint = Variable::TYPE_BOOLEAN;
            $var->literalBoolType = $literalBoolName;
            $var->declaredTypeLabel = $literalBoolName;

            return $return;
        }
        if ($this->cfgTypeUsesDnfShape($cfgType)) {
            $dnfArms = DnfType::armsFromCfgType(
                $cfgType,
                fn (Op\Type\Intersection $t) => $this->intersectionNamesFromCfgType($t),
                fn (Op\Type\Intersection $t) => $this->intersectionDisplayFromCfgType($t),
                fn (Op\Type\Reference $t) => $this->staticNameFromCfgType($t)
            );
            if (DnfType::hasConstraints($dnfArms) && DnfType::requiresDnfLowering($dnfArms)) {
                $var->dnfArms = $dnfArms;
                $var->declaredTypeLabel = $this->dnfTypeLabelFromCfgType($cfgType);

                return $return;
            }
        }
        if (Type::TYPE_UNION === $type->type) {
            // PHPTypes Type::mixed() — untyped properties and `mixed` hints must not coerce writes (#2256).
            if (str_contains($type->toString(), 'callable')) {
                return $return;
            }
            $members = [];
            foreach ($type->subTypes as $sub) {
                $mapped = Variable::mapFromType($sub);
                if (Variable::TYPE_UNDEFINED !== $mapped) {
                    $members[] = $mapped;
                }
            }
            if ([] !== $members) {
                $var->unionTypeConstraints = $members;
                $memberNames = [];
                foreach ($type->subTypes as $sub) {
                    $memberNames[] = $sub->toString();
                }
                $var->declaredTypeLabel = DnfType::zendCanonicalUnionLabel($memberNames);
            }

            return $return;
        }
        $mappedType = Variable::mapFromType($type);
        if ($mappedType === Variable::TYPE_UNDEFINED) {
            return $return;
        }
        if ($mappedType === Variable::TYPE_OBJECT) {
            $var->classConstraint = $type->userType;
        }
        $var->typeConstraint = $mappedType;
        $var->declaredTypeLabel = $type->toString();

        return $return;
    }


    /**
     * Fold parameter/property defaults to block constant slots (Zend zend_compile_default_value, #3803).
     */
    protected function resolvePropertyOrParamDefaultSlot(Op\Expr\Param $param, Block $block, ?int $paramIdx = null): ?int
    {
        if (null === $param->defaultVar) {
            return null;
        }
        $folded = $this->tryFoldParamDefaultSlot($param, $block);
        if (null !== $folded) {
            return $folded;
        }
        if ($this->paramDefaultUsesRuntimeInit($param)) {
            if (null === $paramIdx) {
                // Promoted property metadata: default applied via constructor param (#6652).
                return null;
            }
            $beforeCount = \count($block->opCodes);
            if (null !== $param->defaultBlock) {
                $this->compileOps($param->defaultBlock->children, $block);
            }
            $resultSlot = $this->compileOperand($param->defaultVar, $block, true);
            $newOps = \array_slice($block->opCodes, $beforeCount);
            $block->opCodes = \array_slice($block->opCodes, 0, $beforeCount);
            $block->nOpCodes = \count($block->opCodes);
            $block->paramRuntimeDefaultInitBlocks[$paramIdx] = $block->fragmentForOpcodes($newOps);
            $block->paramRuntimeDefaultResultSlots[$paramIdx] = $resultSlot;

            return null;
        }
        if (null !== $param->defaultBlock) {
            $this->compileOps($param->defaultBlock->children, $block);
        }
        $slot = $this->compileOperand($param->defaultVar, $block, true);
        if (!isset($block->constants[$slot])) {
            $paramName = '?';
            if ($param->name instanceof Operand\Literal && is_string($param->name->value)) {
                $paramName = $param->name->value;
            }
            $this->throwCompileLogic(
                'Parameter default must be a compile-time constant (#3803): $'.$paramName
            );
        }

        return $slot;
    }

    /**
     * Parameter defaults evaluated when the argument is omitted: `new Class()` (#6652).
     * First-class callables are not constant expressions (Zend/zend_compile.c, #9697).
     */
    protected function paramDefaultUsesRuntimeInit(Op\Expr\Param $param): bool
    {
        if ($this->paramDefaultIsRuntimeNew($param)) {
            return true;
        }
        if (null !== $this->paramDefaultFirstClassCallableExpr($param)) {
            $this->throwCompileLogic(ThrowInClassConstCompileCheck::MESSAGE);
        }

        return false;
    }

    /**
     * Parameter default `new Class()` — evaluated when the argument is omitted (#6652).
     */
    protected function paramDefaultIsRuntimeNew(Op\Expr\Param $param): bool
    {
        if (null === $param->defaultVar) {
            return false;
        }
        if (null !== $param->defaultBlock && [] !== $param->defaultBlock->children) {
            $last = $param->defaultBlock->children[\count($param->defaultBlock->children) - 1];
            if ($last instanceof Op\Expr\New_) {
                return true;
            }
        }

        return $this->unwrapOperandChain($param->defaultVar) instanceof Op\Expr\New_;
    }

    protected function paramDefaultFirstClassCallableExpr(Op\Expr\Param $param): ?Op\Expr\FirstClassCallable
    {
        if (null === $param->defaultVar) {
            return null;
        }
        if (null !== $param->defaultBlock && [] !== $param->defaultBlock->children) {
            $last = $param->defaultBlock->children[\count($param->defaultBlock->children) - 1];
            if ($last instanceof Op\Expr\FirstClassCallable) {
                return $last;
            }
        }

        $unwrapped = $this->unwrapOperandChain($param->defaultVar);

        return $unwrapped instanceof Op\Expr\FirstClassCallable ? $unwrapped : null;
    }

    /**
     * Property default `new Class()` — deferred to instance creation (Zend zend_objects.c, #3391).
     */
    protected function propertyDefaultIsRuntimeNew(Op\Stmt\Property $prop): bool
    {
        if (null === $prop->defaultVar) {
            return false;
        }
        if (null !== $prop->defaultBlock && [] !== $prop->defaultBlock->children) {
            $last = $prop->defaultBlock->children[\count($prop->defaultBlock->children) - 1];
            if ($last instanceof Op\Expr\New_) {
                return true;
            }
        }

        return $this->unwrapOperandChain($prop->defaultVar) instanceof Op\Expr\New_;
    }

    protected function tryFoldPropertyDefaultSlot(Op\Stmt\Property $prop, Block $block): ?int
    {
        if (null === $prop->defaultVar) {
            return null;
        }
        $propertyType = $prop->declaredType ?? new Op\Type\Literal('mixed');
        $pseudo = new Op\Expr\Param(
            new Operand\Literal(''),
            new Op\Type\Mixed_(),
            false,
            false,
            $prop->defaultVar,
            $prop->defaultBlock
        );

        return $this->tryFoldParamDefaultSlot($pseudo, $block);
    }

    protected function tryFoldParamDefaultSlot(Op\Expr\Param $param, Block $block): ?int
    {
        if (null === $param->defaultVar) {
            return null;
        }
        if ($param->defaultVar instanceof Operand\NullOperand) {
            return $this->registerNullConstantSlot($block, $param->defaultVar);
        }
        $vm = $this->vmVariableFromCfgLiteralOperand($param->defaultVar);
        if (null !== $vm) {
            return $block->registerConstant($param->defaultVar, $vm);
        }
        if (null === $param->defaultBlock || [] === $param->defaultBlock->children) {
            return null;
        }
        $children = $param->defaultBlock->children;
        if ([] === $children) {
            return null;
        }
        foreach ($children as $child) {
            if (!$child instanceof Op\Stmt\JumpIf) {
                continue;
            }
            $vm = $this->tryFoldCompileTimeTernaryDefault(
                $child,
                $param->defaultVar,
                $block,
                $children,
                true
            );
            if (null !== $vm) {
                return $block->registerConstant($param->defaultVar, $vm);
            }
        }
        $expr = $children[\count($children) - 1];
        if (!$expr instanceof Op\Expr) {
            return null;
        }
        if ($expr instanceof Op\Expr\ConstFetch) {
            $vm = $this->tryFoldGlobalConstFetch($expr);
            if (null !== $vm) {
                return $block->registerConstant($param->defaultVar, $vm);
            }
        }
        if ($expr instanceof Op\Expr\ClassConstFetch) {
            $vm = $this->tryFoldClassConstFetchDefault($expr, $block, true);
            if (null !== $vm) {
                return $block->registerConstant($param->defaultVar, $vm);
            }
        }
        if ($expr instanceof Op\Expr\Array_) {
            $vm = $this->tryBuildCompileTimeArrayFromExpr($expr, $block, $children);
            if (null !== $vm) {
                return $block->registerConstant($param->defaultVar, $vm);
            }
        }
        if ($expr instanceof Op\Expr\ArrayDimFetch) {
            $vm = $this->tryFoldArrayDimFetchCompileTimeDefault($expr, $block, $children, true);
            if (null !== $vm) {
                return $block->registerConstant($param->defaultVar, $vm);
            }
        }
        if ($expr instanceof Op\Expr\UnaryMinus || $expr instanceof Op\Expr\UnaryPlus) {
            $vm = $this->tryFoldUnaryLiteralDefault($expr);
            if (null !== $vm) {
                return $block->registerConstant($param->defaultVar, $vm);
            }
        }
        $vm = $this->tryFoldCompileTimeExprDefault($expr, $block, $children, true);
        if (null !== $vm) {
            return $block->registerConstant($param->defaultVar, $vm);
        }

        return null;
    }

    /**
     * Fold php-cfg ?: lowering (JumpIf + arm assigns) in param/static defaults (#12026).
     *
     * @param list<Op> $defaultBlockChildren
     */
    protected function tryFoldCompileTimeTernaryDefault(
        Op\Stmt\JumpIf $jumpIf,
        Operand $result,
        Block $block,
        array $defaultBlockChildren,
        bool $materializeEnumCase = false
    ): ?Variable {
        $ifMerge = $this->branchJumpMergeTarget($jumpIf->if);
        $elseMerge = $this->branchJumpMergeTarget($jumpIf->else);
        if (null === $ifMerge || $ifMerge !== $elseMerge) {
            return null;
        }
        $ifExpr = $this->branchCfgAssignExprForResult($jumpIf->if, $result);
        $elseExpr = $this->branchCfgAssignExprForResult($jumpIf->else, $result);
        if (null === $ifExpr || null === $elseExpr) {
            return null;
        }
        $condVm = $this->tryFoldCompileTimeOperandDefault(
            $jumpIf->cond,
            $block,
            $defaultBlockChildren,
            $materializeEnumCase
        );
        if (null === $condVm) {
            return null;
        }
        $chosenExpr = $condVm->toBool() ? $ifExpr : $elseExpr;

        return $this->tryFoldCompileTimeOperandDefault(
            $chosenExpr,
            $block,
            $defaultBlockChildren,
            $materializeEnumCase
        );
    }

    private function branchCfgAssignExprForResult(CfgBlock $branchCfg, Operand $result): ?Operand
    {
        $assignVar = $this->mergeBranchAssignVarOperand($branchCfg);
        if (null === $assignVar || !$this->operandsReferToSameVariable($assignVar, $result)) {
            return null;
        }
        foreach ($branchCfg->children as $child) {
            if ($child instanceof Op\Expr\Assign && $this->operandsReferToSameVariable($child->var, $result)) {
                return $child->expr;
            }
        }

        return null;
    }

    /**
     * @param list<Op> $defaultBlockChildren
     */
    protected function tryFoldCompileTimeExprDefault(
        Op\Expr $expr,
        Block $block,
        array $defaultBlockChildren = [],
        bool $materializeEnumCase = false
    ): ?Variable {
        if ($expr instanceof Op\Expr\ConstFetch) {
            return $this->tryFoldGlobalConstFetch($expr);
        }
        if ($expr instanceof Op\Expr\ClassConstFetch) {
            return $this->tryFoldClassConstFetchDefault($expr, $block, $materializeEnumCase);
        }
        if ($expr instanceof Op\Expr\Array_) {
            return $this->tryBuildCompileTimeArrayFromExpr($expr, $block, $defaultBlockChildren);
        }
        if ($expr instanceof Op\Expr\UnaryMinus || $expr instanceof Op\Expr\UnaryPlus) {
            return $this->tryFoldUnaryLiteralDefault($expr);
        }
        if ($expr instanceof Op\Expr\BitwiseNot || $expr instanceof Op\Expr\BooleanNot) {
            return $this->tryFoldCompileTimeUnaryExprDefault(
                $expr,
                $block,
                $defaultBlockChildren,
                $materializeEnumCase
            );
        }
        if ($expr instanceof Op\Expr\BinaryOp\Coalesce) {
            return null;
        }
        if ($expr instanceof Op\Expr\BinaryOp) {
            return $this->tryFoldCompileTimeBinaryExprDefault(
                $expr,
                $block,
                $defaultBlockChildren,
                $materializeEnumCase
            );
        }
        if ($expr instanceof Op\Expr\PropertyFetch) {
            return $this->tryFoldEnumCasePropertyFetchDefault($expr, $block, $defaultBlockChildren);
        }
        if ($expr instanceof Op\Expr\ArrayDimFetch) {
            return $this->tryFoldArrayDimFetchCompileTimeDefault(
                $expr,
                $block,
                $defaultBlockChildren,
                $materializeEnumCase
            );
        }
        if ($expr instanceof Op\Expr\Cast) {
            return $this->tryFoldCompileTimeCastDefault(
                $expr,
                $block,
                $defaultBlockChildren,
                $materializeEnumCase
            );
        }

        return null;
    }

    /**
     * Fold literal-array subscript in const-expr defaults (static/param/property, #12025).
     *
     * @param list<Op> $defaultBlockChildren
     */
    protected function tryFoldArrayDimFetchCompileTimeDefault(
        Op\Expr\ArrayDimFetch $expr,
        Block $block,
        array $defaultBlockChildren = [],
        bool $materializeEnumCase = false
    ): ?Variable {
        if (null === $expr->dim) {
            return null;
        }
        $base = $this->tryFoldCompileTimeOperandDefault(
            $expr->var,
            $block,
            $defaultBlockChildren,
            $materializeEnumCase
        );
        if (null === $base || !$base->is(Variable::TYPE_ARRAY)) {
            return null;
        }
        $dimVm = $this->tryFoldCompileTimeOperandDefault(
            $expr->dim,
            $block,
            $defaultBlockChildren,
            $materializeEnumCase
        );
        if (null === $dimVm) {
            return null;
        }
        $table = $base->toArray();
        if (!$table->keyExists($dimVm)) {
            return null;
        }
        $elem = $table->findVariable($dimVm, false);
        if (null === $elem) {
            return null;
        }
        $value = new Variable();
        $value->copyFrom($elem->resolveIndirect());

        return $value;
    }

    /**
     * Fold compile-time scalar casts, including (string) NAN/INF (#10143, zend_operators.c).
     *
     * @param list<Op> $defaultBlockChildren
     */
    protected function tryFoldCompileTimeCastDefault(
        Op\Expr\Cast $expr,
        Block $block,
        array $defaultBlockChildren = [],
        bool $materializeEnumCase = false
    ): ?Variable {
        $operand = $this->tryFoldCompileTimeOperandDefault(
            $expr->expr,
            $block,
            $defaultBlockChildren,
            $materializeEnumCase
        );
        if (null === $operand) {
            return null;
        }
        $castOpcode = $this->getOpCodeTypeFromCastOp($expr);
        $targetType = match ($castOpcode) {
            OpCode::TYPE_CAST_STRING => Variable::TYPE_STRING,
            OpCode::TYPE_CAST_INT => Variable::TYPE_INTEGER,
            OpCode::TYPE_CAST_FLOAT => Variable::TYPE_FLOAT,
            OpCode::TYPE_CAST_BOOL => Variable::TYPE_BOOLEAN,
            default => null,
        };
        if (null === $targetType) {
            return null;
        }
        $result = new Variable();
        try {
            $result->castFrom($targetType, $operand);
        } catch (\Throwable) {
            return null;
        }

        return $result;
    }

    /**
     * Fold unary const-expr operators in parameter/property defaults (#5166, zend_const_expr_to_zval).
     *
     * @param list<Op> $defaultBlockChildren
     */
    protected function tryFoldCompileTimeUnaryExprDefault(
        Op\Expr $expr,
        Block $block,
        array $defaultBlockChildren = [],
        bool $materializeEnumCase = false
    ): ?Variable {
        if (!$expr instanceof Op\Expr\BitwiseNot && !$expr instanceof Op\Expr\BooleanNot) {
            return null;
        }
        $opCode = $this->getOpCodeTypeFromUnaryOp($expr);
        if (!ClassConstExpr::isSupportedOpcode($opCode)) {
            return null;
        }
        $operand = $this->tryFoldCompileTimeOperandDefault(
            $expr->expr,
            $block,
            $defaultBlockChildren,
            $materializeEnumCase
        );
        if (null === $operand) {
            return null;
        }
        $result = new Variable();
        try {
            $result->unaryOp($opCode, $operand);
        } catch (\Throwable) {
            return null;
        }

        return $result;
    }

    /**
     * Fold binary const-expr operators in parameter/property defaults (#5166, zend_const_expr_to_zval).
     *
     * @param list<Op> $defaultBlockChildren
     */
    protected function tryFoldCompileTimeBinaryExprDefault(
        Op\Expr\BinaryOp $expr,
        Block $block,
        array $defaultBlockChildren = [],
        bool $materializeEnumCase = false
    ): ?Variable {
        if ($expr instanceof Op\Expr\BinaryOp\Coalesce) {
            return null;
        }
        $opCode = $this->getOpCodeTypeFromBinaryOp($expr);
        if (!ClassConstExpr::isSupportedOpcode($opCode)) {
            return null;
        }
        $left = $this->tryFoldCompileTimeOperandDefault(
            $expr->left,
            $block,
            $defaultBlockChildren,
            $materializeEnumCase
        );
        $right = $this->tryFoldCompileTimeOperandDefault(
            $expr->right,
            $block,
            $defaultBlockChildren,
            $materializeEnumCase
        );
        if (null === $left || null === $right) {
            return null;
        }
        if (OpCode::TYPE_CONCAT === $opCode) {
            $result = new Variable(Variable::TYPE_STRING);
            $result->string($left->toString().$right->toString());

            return $result;
        }
        $result = new Variable();
        try {
            if (\in_array($opCode, [
                OpCode::TYPE_PLUS,
                OpCode::TYPE_MINUS,
                OpCode::TYPE_MUL,
                OpCode::TYPE_DIV,
                OpCode::TYPE_MODULO,
                OpCode::TYPE_POW,
            ], true)) {
                $result->numericOp($opCode, $left, $right);
            } else {
                $result->bitwiseOp($opCode, $left, $right);
            }
        } catch (\Throwable) {
            return null;
        }

        return $result;
    }

    /**
     * Fold {@code E::Case->name}/{@code ->value} in parameter/property defaults (#7399, zend_compile.c).
     *
     * @param list<Op> $defaultBlockChildren
     */
    protected function tryFoldEnumCasePropertyFetchDefault(
        Op\Expr\PropertyFetch $expr,
        Block $block,
        array $defaultBlockChildren
    ): ?Variable {
        $propName = $this->staticNameFromOperand($expr->name);
        if (null === $propName) {
            return null;
        }
        $receiver = $this->tryFoldCompileTimeOperandDefault(
            $expr->var,
            $block,
            $defaultBlockChildren,
            true
        );
        if (null === $receiver) {
            return null;
        }
        if (Variable::TYPE_ENUM_CASE === $receiver->type) {
            return $receiver->toEnumCase()->fetchProperty($propName);
        }
        if (Variable::TYPE_OBJECT === $receiver->type && EnumCaseSupport::isEnumCase($receiver->toObject())) {
            return EnumCaseSupport::getProperty($receiver->toObject(), $propName);
        }

        return null;
    }

    /**
     * @param list<Op> $defaultBlockChildren
     */
    protected function tryFoldCompileTimeOperandDefault(
        ?Operand $operand,
        Block $block,
        array $defaultBlockChildren = [],
        bool $materializeEnumCase = false
    ): ?Variable {
        if (null === $operand) {
            return null;
        }
        $vm = $this->vmVariableFromCfgLiteralOperand($operand);
        if (null !== $vm) {
            return $vm;
        }
        foreach ($defaultBlockChildren as $child) {
            if (!$child instanceof Op\Expr) {
                continue;
            }
            if (!property_exists($child, 'result') || !$this->operandsReferToSameVariable($child->result, $operand)) {
                continue;
            }
            $vm = $this->tryFoldCompileTimeExprDefault(
                $child,
                $block,
                $defaultBlockChildren,
                $materializeEnumCase
            );
            if (null !== $vm) {
                return $vm;
            }
        }

        return null;
    }

    protected function tryFoldUnaryLiteralDefault(Op\Expr\UnaryMinus|Op\Expr\UnaryPlus $expr): ?Variable
    {
        $vm = $this->vmVariableFromCfgLiteralOperand($expr->expr);
        if (null === $vm) {
            return null;
        }
        if ($vm->is(Variable::TYPE_INTEGER)) {
            $value = new Variable(Variable::TYPE_INTEGER);
            $n = $vm->toInt();
            $value->int($expr instanceof Op\Expr\UnaryMinus ? -$n : $n);

            return $value;
        }
        if ($vm->is(Variable::TYPE_FLOAT)) {
            $value = new Variable(Variable::TYPE_FLOAT);
            $n = $vm->toFloat();
            $value->float($expr instanceof Op\Expr\UnaryMinus ? -$n : $n);

            return $value;
        }

        return null;
    }

    protected function registerNullConstantSlot(Block $block, Operand $operand): int
    {
        return $block->registerConstant($operand, new Variable(Variable::TYPE_NULL));
    }

    protected function tryFoldGlobalConstFetch(Op\Expr\ConstFetch $expr): ?Variable
    {
        $name = $this->staticNameFromOperand($expr->name);
        if (null === $name) {
            return null;
        }
        $vm = \PHPCompiler\ext\standard\VmPhpCoreConstants::fetch($name);
        if (null !== $vm) {
            return $vm;
        }
        $lc = strtolower($name);
        if ('null' === $lc) {
            return new Variable(Variable::TYPE_NULL);
        }
        if ('true' === $lc) {
            $v = new Variable(Variable::TYPE_BOOLEAN);
            $v->bool(true);

            return $v;
        }
        if ('false' === $lc) {
            $v = new Variable(Variable::TYPE_BOOLEAN);
            $v->bool(false);

            return $v;
        }
        $errorInt = \PHPCompiler\VM\Context::errorReportingConstant($name);
        if (null !== $errorInt) {
            $v = new Variable(Variable::TYPE_INTEGER);
            $v->int($errorInt);

            return $v;
        }
        $lc = strtolower($name);
        if ('inf' === $lc) {
            $v = new Variable(Variable::TYPE_FLOAT);
            $v->float(INF);

            return $v;
        }
        if ('nan' === $lc) {
            $v = new Variable(Variable::TYPE_FLOAT);
            $v->float(NAN);

            return $v;
        }
        if (isset($this->compileTimeGlobalConsts[$lc])) {
            $value = new Variable();
            $value->copyFrom($this->compileTimeGlobalConsts[$lc]);

            return $value;
        }
        $dateStr = \PHPCompiler\ext\standard\DateConstants::CORE_STRING_BY_NAME[$lc] ?? null;
        if (null !== $dateStr) {
            $v = new Variable(Variable::TYPE_STRING);
            $v->string($dateStr);

            return $v;
        }

        return null;
    }

    /**
     * Pre-register global `const` and literal define() for default-value folding (#6542).
     *
     * @param list<Op> $ops
     */
    protected function prescanCompileTimeGlobalConsts(array $ops, Block $block): void
    {
        foreach ($ops as $child) {
            if ($child instanceof Op\Terminal\Const_) {
                $this->prescanGlobalConstTerminal($child, $block);
                continue;
            }
            if ($child instanceof Op\Expr\FuncCall) {
                $this->prescanDefineFuncCall($child, $block);
            }
        }
    }

    protected function prescanGlobalConstTerminal(Op\Terminal\Const_ $const, Block $block): void
    {
        $name = $this->staticNameFromOperand($const->name);
        if (null === $name) {
            return;
        }
        $valueSlot = $this->tryFoldGlobalConstValueSlot($const, $block);
        if (null === $valueSlot || !isset($block->constants[$valueSlot])) {
            return;
        }
        $this->storeCompileTimeGlobalConst($name, $block->constants[$valueSlot]);
    }

    protected function prescanDefineFuncCall(Op\Expr\FuncCall $expr, Block $block): void
    {
        $fnName = $this->staticNameFromOperand($expr->name);
        if (null === $fnName || 'define' !== strtolower($fnName)) {
            return;
        }
        if (count($expr->args) < 2 || count($expr->args) > 3) {
            return;
        }
        $constNameArg = $expr->args[0];
        $valueArg = $expr->args[1];
        if (!$constNameArg instanceof Operand\Literal) {
            return;
        }
        if (Variable::TYPE_STRING !== Variable::mapFromType($constNameArg->type)) {
            return;
        }
        $constName = $constNameArg->value;
        if (!is_string($constName) || '' === $constName || str_contains($constName, '::')) {
            return;
        }
        $vm = $this->tryFoldDefineValueOperand($valueArg, $block);
        if (null === $vm) {
            return;
        }
        $this->storeCompileTimeGlobalConst($constName, $vm);
    }

    /**
     * Fold define('NAME', expr) value operands for compile-time const registration (#5409).
     */
    protected function tryFoldDefineValueOperand(Operand $valueArg, Block $block): ?Variable
    {
        $vm = $this->vmVariableFromCfgLiteralOperand($valueArg);
        if (null !== $vm) {
            return $vm;
        }
        if (null === $block->orig) {
            return null;
        }
        $root = $this->unwrapOperandChain($valueArg);
        foreach ($block->orig->children as $child) {
            if ($child instanceof Op\Expr\Array_
                && $this->operandsReferToSameVariable($child->result, $root)
            ) {
                return $this->tryBuildCompileTimeArrayFromExpr($child);
            }
            if (!$child instanceof Op\Expr || !$this->operandsReferToSameVariable($child->result, $root)) {
                continue;
            }
            $vm = $this->tryFoldCompileTimeExprDefault($child, $block, [$child], true);
            if (null !== $vm) {
                return $vm;
            }
        }

        return null;
    }

    protected function storeCompileTimeGlobalConst(string $name, Variable $value): void
    {
        $lc = strtolower($name);
        if (isset($this->compileTimeGlobalConsts[$lc])) {
            return;
        }
        $stored = new Variable();
        $stored->copyFrom($value);
        $this->compileTimeGlobalConsts[$lc] = $stored;
    }

    protected function tryFoldClassConstFetchDefault(
        Op\Expr\ClassConstFetch $expr,
        Block $block,
        bool $materializeEnumCase = false
    ): ?Variable {
        $constName = $this->staticNameFromOperand($expr->name);
        if (null !== $constName && 'class' === strtolower($constName)) {
            $enumFqcn = $this->tryFoldEnumCaseClassPseudoConstFqcn($expr->class, $block);
            if (null !== $enumFqcn) {
                $value = new Variable(Variable::TYPE_STRING);
                $value->string($enumFqcn);

                return $value;
            }
            $builtinClass = $this->staticNameFromOperand($expr->class);
            if (null !== $builtinClass) {
                $builtinName = BuiltinTypeClassConstant::classNameForTypeOperand($builtinClass);
                if (null !== $builtinName) {
                    $value = new Variable(Variable::TYPE_STRING);
                    $value->string($builtinName);

                    return $value;
                }
            }
        }
        $className = $this->staticNameFromOperand($expr->class);
        if (null === $constName || null === $className) {
            return null;
        }
        $lcClass = $this->resolveDefaultClassConstScope($className, $block);
        if (null === $lcClass) {
            return null;
        }
        if ($this->classCompileRegistry->isTrait($lcClass)) {
            return null;
        }
        $lcConst = strtolower($constName);
        if (isset($this->compileTimeClassConsts[$lcClass][$lcConst])) {
            if (!$this->compileTimeClassConstFetchAllowed($lcClass, $lcConst, $block)) {
                return null;
            }
            // Deprecated constants must fetch at runtime so E_USER_DEPRECATED fires (#6962).
            if (isset($this->compileTimeClassConstDeprecated[$lcClass][$lcConst])) {
                return null;
            }
            $stored = $this->compileTimeClassConsts[$lcClass][$lcConst];
            // Enum case fetches defer to runtime unless folding defaults/const-expr (#8767, #7399).
            if ($this->isCompileTimeEnumCaseConstantMember($lcClass, $lcConst) && !$materializeEnumCase) {
                return null;
            }
            if ($this->compileTimeStoredValueIsEnumCaseBackingScalar($lcClass, $lcConst, $stored)) {
                return $this->compileTimeEnumCaseVar(
                    $className,
                    $constName,
                    $stored,
                    $this->compileTimeEnumBackedTypes[$lcClass] ?? null
                );
            }
            // Non-literal duplicate backing falls back to runtime ensureBackedEnumValuesUnique (#5773).
            if (Variable::TYPE_OBJECT === $stored->type && EnumCaseSupport::isEnumCase($stored->toObject())) {
                if (!$materializeEnumCase) {
                    return null;
                }
                $value = new Variable();
                $value->copyFrom($stored);

                return $value;
            }
            if ($materializeEnumCase && Variable::TYPE_ENUM_CASE === $stored->type) {
                $value = new Variable();
                $value->copyFrom($stored);

                return $value;
            }
            if ($this->isCompileTimeEnumCaseConstantMember($lcClass, $lcConst)) {
                return $this->materializeCompileTimeEnumCaseConstant(
                    $className,
                    $constName,
                    $stored,
                    $this->compileTimeEnumBackedTypes[$lcClass] ?? null
                );
            }
            $value = new Variable();
            $value->copyFrom($stored);

            return $value;
        }
        if (isset($this->runtimeEnumCaseConsts[$lcClass][$lcConst])) {
            $stored = $this->runtimeEnumCaseConsts[$lcClass][$lcConst];
            if ($this->isCompileTimeEnumCaseConstantMember($lcClass, $lcConst) && !$materializeEnumCase) {
                return null;
            }
            if (Variable::TYPE_OBJECT === $stored->type && EnumCaseSupport::isEnumCase($stored->toObject())) {
                if (!$materializeEnumCase) {
                    return null;
                }
                $value = new Variable();
                $value->copyFrom($stored);

                return $value;
            }
            if ($materializeEnumCase && Variable::TYPE_ENUM_CASE === $stored->type) {
                $value = new Variable();
                $value->copyFrom($stored);

                return $value;
            }
            if ($this->isCompileTimeEnumCaseConstantMember($lcClass, $lcConst)) {
                return $this->materializeCompileTimeEnumCaseConstant(
                    $className,
                    $constName,
                    $stored,
                    $this->compileTimeEnumBackedTypes[$lcClass] ?? null
                );
            }
            $value = new Variable();
            $value->copyFrom($stored);

            return $value;
        }

        return $this->tryFoldExternalClassConstFetch($className, $constName);
    }

    private function isCompileTimeEnumCaseConstantMember(string $lcClass, string $lcConst): bool
    {
        if (isset($this->compileTimeEnumCaseConstNames[$lcClass][$lcConst])) {
            return true;
        }
        if (!isset($this->runtimeEnumCaseConsts[$lcClass][$lcConst])) {
            return false;
        }
        $stored = $this->runtimeEnumCaseConsts[$lcClass][$lcConst];

        return Variable::TYPE_ENUM_CASE === $stored->type
            || (Variable::TYPE_OBJECT === $stored->type && EnumCaseSupport::isEnumCase($stored->toObject()));
    }

    /**
     * Fold enum `case` fetches to enum case objects — never expose backing scalars (#5933, #5858).
     */
    private function materializeCompileTimeEnumCaseConstant(
        string $enumName,
        string $caseName,
        Variable $stored,
        ?string $backedType
    ): Variable {
        if (Variable::TYPE_OBJECT === $stored->type && EnumCaseSupport::isEnumCase($stored->toObject())) {
            $value = new Variable();
            $value->copyFrom($stored);

            return $value;
        }
        if (Variable::TYPE_ENUM_CASE === $stored->type) {
            $value = new Variable();
            $value->copyFrom($stored);

            return $value;
        }
        $backing = new Variable();
        $backing->copyFrom($stored);

        return $this->compileTimeEnumCaseVar($enumName, $caseName, $backing, $backedType);
    }

    /**
     * Fold {@code EnumCase::class} to the enum type FQCN (Zend zend_compile.c; #5662).
     */
    protected function tryFoldEnumCaseClassPseudoConstFqcn(Operand $classOperand, Block $block): ?string
    {
        if ($classOperand instanceof Op\Expr\ClassConstFetch) {
            $inner = $this->tryFoldClassConstFetchDefault($classOperand, $block, true);
            if (null !== $inner) {
                $fqcn = $this->enumFqcnFromEnumCaseVariable($inner);
                if (null !== $fqcn) {
                    return $fqcn;
                }
            }
            $className = $this->staticNameFromOperand($classOperand->class);
            $caseName = $this->staticNameFromOperand($classOperand->name);
            if (null !== $className && null !== $caseName) {
                $lcClass = $this->resolveDefaultClassConstScope($className, $block);
                if (null !== $lcClass
                    && $this->isCompileTimeEnumCaseConstantMember($lcClass, strtolower($caseName))
                ) {
                    return ltrim($className, '\\');
                }
            }

            return null;
        }
        if (!$classOperand instanceof Operand\Variable && !$classOperand instanceof Temporary) {
            return null;
        }
        if (null === $block->orig) {
            return null;
        }
        foreach ($block->orig->children as $child) {
            if (!$child instanceof Op\Expr\ClassConstFetch
                || !$this->operandsReferToSameVariable($child->result, $classOperand)
            ) {
                continue;
            }
            $className = $this->staticNameFromOperand($child->class);
            $caseName = $this->staticNameFromOperand($child->name);
            if (null === $className || null === $caseName) {
                continue;
            }
            $lcClass = $this->resolveDefaultClassConstScope($className, $block);
            $lcConst = strtolower($caseName);
            if (null === $lcClass || !$this->isCompileTimeEnumCaseConstantMember($lcClass, $lcConst)) {
                continue;
            }
            $stored = $this->compileTimeClassConsts[$lcClass][$lcConst]
                ?? $this->runtimeEnumCaseConsts[$lcClass][$lcConst]
                ?? null;
            if (null !== $stored) {
                $fqcn = $this->enumFqcnFromEnumCaseVariable($stored);
                if (null !== $fqcn) {
                    return $fqcn;
                }
            }

            return ltrim($className, '\\');
        }

        return null;
    }

    protected function enumFqcnFromEnumCaseVariable(Variable $var): ?string
    {
        $var = $var->resolveIndirect();
        if (Variable::TYPE_ENUM_CASE === $var->type) {
            return $var->toEnumCase()->enumClass->name;
        }
        if (Variable::TYPE_OBJECT === $var->type && EnumCaseSupport::isEnumCase($var->toObject())) {
            return $var->toObject()->class->name;
        }

        return null;
    }

    protected function tryFoldExternalClassConstFetch(string $className, string $constName): ?Variable
    {
        $lcClass = strtolower(ltrim($className, '\\'));
        if ('phpcfg\\func' === $lcClass) {
            $flags = [
                'FLAG_PUBLIC' => \PHPCfg\Func::FLAG_PUBLIC,
                'FLAG_PROTECTED' => \PHPCfg\Func::FLAG_PROTECTED,
                'FLAG_PRIVATE' => \PHPCfg\Func::FLAG_PRIVATE,
                'FLAG_STATIC' => \PHPCfg\Func::FLAG_STATIC,
                'FLAG_ABSTRACT' => \PHPCfg\Func::FLAG_ABSTRACT,
                'FLAG_FINAL' => \PHPCfg\Func::FLAG_FINAL,
                'FLAG_RETURNS_REF' => \PHPCfg\Func::FLAG_RETURNS_REF,
                'FLAG_CLOSURE' => \PHPCfg\Func::FLAG_CLOSURE,
            ];
            $lcConst = strtoupper($constName);
            if (!isset($flags[$lcConst])) {
                return null;
            }
            $value = new Variable(Variable::TYPE_INTEGER);
            $value->int($flags[$lcConst]);

            return $value;
        }

        return $this->tryFoldNativePhpClassConstFetch(ltrim($className, '\\'), $constName);
    }

    /**
     * Fold class constants from already-loaded native PHP classes (bootstrap spine; #6221).
     */
    protected function tryFoldNativePhpClassConstFetch(string $className, string $constName): ?Variable
    {
        // ::class on bootstrap Internal handlers may not be loaded yet (#1492 spine compile).
        $autoload = 'class' === strtolower($constName);
        if (!class_exists($className, $autoload)) {
            return null;
        }
        try {
            $ref = new \ReflectionClassConstant($className, $constName);
        } catch (\ReflectionException) {
            return null;
        }
        $raw = $ref->getValue();
        if (\is_int($raw)) {
            $value = new Variable(Variable::TYPE_INTEGER);
            $value->int($raw);

            return $value;
        }
        if (\is_bool($raw)) {
            $value = new Variable(Variable::TYPE_BOOLEAN);
            $value->bool($raw);

            return $value;
        }
        if (\is_float($raw)) {
            $value = new Variable(Variable::TYPE_FLOAT);
            $value->float($raw);

            return $value;
        }
        if (\is_string($raw)) {
            $value = new Variable(Variable::TYPE_STRING);
            $value->string($raw);

            return $value;
        }

        return null;
    }

    protected function pseudoClassInCompileScope(string $className, Block $block): bool
    {
        $lc = strtolower($className);
        if (!in_array($lc, ['self', 'parent', 'static'], true)) {
            return true;
        }
        if (null !== $this->compilingClassLc) {
            return true;
        }

        return null !== $block->func && null !== $block->func->class;
    }

    protected function resolveDefaultClassConstScope(string $className, Block $block): ?string
    {
        $lc = strtolower($className);
        if ('self' === $lc || 'static' === $lc) {
            if (null !== $this->compilingClassLc) {
                return $this->compilingClassLc;
            }
            if (null !== $block->func && null !== $block->func->class) {
                $name = $this->staticNameFromOperand($block->func->class);

                return null !== $name ? strtolower(ltrim($name, '\\')) : null;
            }

            return null;
        }
        if ('parent' === $lc) {
            return null;
        }

        return strtolower(ltrim($className, '\\'));
    }

    /**
     * Caller class lc for compile-time class const fetch folding (#6784, zend_verify_const_access).
     */
    protected function compileTimeClassConstFetchCallerLc(Block $block): ?string
    {
        if (null !== $this->compilingClassLc) {
            return $this->compilingClassLc;
        }
        if (null !== $block->func && null !== $block->func->class) {
            $name = $this->staticNameFromOperand($block->func->class);

            return null !== $name ? strtolower(ltrim($name, '\\')) : null;
        }

        return null;
    }

    /**
     * Whether a compile-time class const value may be constant-folded at this site (#6784).
     */
    protected function compileTimeClassConstFetchAllowed(
        string $declaringClassLc,
        string $constLc,
        Block $block
    ): bool {
        $vis = $this->compileTimeClassConstVisibility[$declaringClassLc][$constLc] ?? CfgFunc::FLAG_PUBLIC;
        if (MethodVisibility::isPublic($vis)) {
            return true;
        }
        try {
            ClassConstVisibility::assertAccessible(
                $vis,
                $this->compileTimeClassConstFetchCallerLc($block),
                $declaringClassLc,
                $this->classCompileRegistry->traitDisplayName($declaringClassLc),
                $constLc,
                fn (string $callerLc, string $ancestorLc): bool => $this->classCompileRegistry->isClassSubtypeOf(
                    $callerLc,
                    $ancestorLc
                )
            );
        } catch (\LogicException) {
            return false;
        }

        return true;
    }

    /**
     * Non-nullable declared type with `= null` default (php-src implicit nullable, #4449).
     */
    protected function paramIsImplicitNullable(Op\Expr\Param $param, ?int $defaultSlot, Block $block): bool
    {
        if (null === $defaultSlot || null === $param->declaredType) {
            return false;
        }
        if ($param->declaredType instanceof Op\Type\Nullable) {
            return false;
        }
        if ($this->cfgTypeUsesDnfShape($param->declaredType)) {
            return false;
        }
        $default = $block->constants[$defaultSlot] ?? null;

        return null !== $default && Variable::TYPE_NULL === $default->type;
    }

    /**
     * Zend zend_compile.c: property/param defaults must match declared type (#5347, #6558).
     */
    protected function assertParamDefaultMatchesDeclaredType(Op\Expr\Param $param, ?int $defaultSlot, Block $block): void
    {
        if (null === $defaultSlot || null === $param->declaredType) {
            return;
        }
        $default = $block->constants[$defaultSlot] ?? null;
        if (null === $default) {
            return;
        }
        $paramName = '?';
        if ($param->name instanceof Operand\Literal && is_string($param->name->value)) {
            $paramName = '$'.$param->name->value;
        }
        $this->assertCompileTimeDefaultMatchesDeclaredType(
            $default,
            $param->declaredType,
            'parameter',
            $paramName,
            $block,
            $defaultSlot,
            $param
        );
    }

    /**
     * Zend zend_compile.c — zend_verify_const_expr_type() for property/param defaults (#6558).
     */
    protected function assertCompileTimeDefaultMatchesDeclaredType(
        Variable $default,
        ?Op\Type $declaredType,
        string $kind,
        string $targetName,
        ?Block $block = null,
        ?int $defaultSlot = null,
        ?Op\Expr\Param $param = null
    ): void {
        if (null === $declaredType) {
            return;
        }

        $value = $default->resolveIndirect();

        if ($declaredType instanceof Op\Type\Mixed_) {
            return;
        }
        if ($declaredType instanceof Op\Type\Literal && 'mixed' === strtolower($declaredType->name)) {
            return;
        }

        if (
            'parameter' === $kind
            && null !== $param
            && null !== $defaultSlot
            && null !== $block
            && $this->paramIsImplicitNullable($param, $defaultSlot, $block)
        ) {
            return;
        }

        $checkType = $declaredType;
        if ($declaredType instanceof Op\Type\Nullable) {
            if (Variable::TYPE_NULL === $value->type) {
                return;
            }
            $checkType = $declaredType->subtype;
        }

        if (
            $this->cfgTypeUsesDnfShape($checkType)
            || $checkType instanceof Op\Type\Union
            || $checkType instanceof Op\Type\Intersection
        ) {
            return;
        }

        $typeLabel = $this->declNameFromCfgType($checkType) ?? 'mixed';

        if ($checkType instanceof Op\Type\Literal) {
            $nameLc = strtolower($checkType->name);
            if ('true' === $nameLc || 'false' === $nameLc) {
                if (Variable::TYPE_BOOLEAN === $value->type && $value->toBool() === ('true' === $nameLc)) {
                    return;
                }
                $given = TypeCheck::typeNameForConstraint($value->type);
                $this->throwTypedDefaultMismatch($given, $kind, $targetName, $nameLc);

                return;
            }
        }

        if ($value->is(Variable::TYPE_ARRAY)) {
            if ($checkType instanceof Op\Type\Literal) {
                $nameLc = strtolower($checkType->name);
                if ('array' === $nameLc || 'iterable' === $nameLc) {
                    return;
                }
            }
            if (null !== $this->genericArraySpecFromCfgType($checkType)) {
                return;
            }
            $this->throwTypedDefaultMismatch('array', $kind, $targetName, $typeLabel);

            return;
        }

        if ($checkType instanceof Op\Type\Literal && $this->compileTimeDefaultMatchesLiteralType($value, strtolower($checkType->name))) {
            return;
        }

        $classOrScalarName = $this->declNameFromCfgType($checkType);
        if (
            null !== $classOrScalarName
            && $this->compileTimeDefaultMatchesLiteralType($value, strtolower($classOrScalarName))
        ) {
            return;
        }

        $given = TypeCheck::typeNameForConstraint($value->type);
        $this->throwTypedDefaultMismatch($given, $kind, $targetName, $typeLabel);
    }

    protected function throwTypedDefaultMismatch(string $given, string $kind, string $targetName, string $typeLabel): void
    {
        $this->throwCompileError(
            "Cannot use {$given} as default value for {$kind} {$targetName} of type {$typeLabel}"
        );
    }

    protected function compileTimeDefaultMatchesLiteralType(Variable $value, string $typeNameLc): bool
    {
        switch ($typeNameLc) {
            case 'int':
                return $value->is(Variable::TYPE_INTEGER);
            case 'float':
                return $value->is(Variable::TYPE_FLOAT) || $value->is(Variable::TYPE_INTEGER);
            case 'string':
                return $value->is(Variable::TYPE_STRING);
            case 'bool':
                return $value->is(Variable::TYPE_BOOLEAN);
            case 'array':
                return $value->is(Variable::TYPE_ARRAY);
            case 'iterable':
                return $value->is(Variable::TYPE_ARRAY);
            case 'null':
                return $value->is(Variable::TYPE_NULL);
            default:
                return $this->compileTimeDefaultMatchesClassType($value, $typeNameLc);
        }
    }

    protected function compileTimeDefaultMatchesClassType(Variable $value, string $expectedClassLc): bool
    {
        $value = $value->resolveIndirect();
        $expectedClassLc = strtolower(ltrim($expectedClassLc, '\\'));

        if (Variable::TYPE_ENUM_CASE === $value->type) {
            return strtolower(ltrim($value->toEnumCase()->enumClass->name, '\\')) === $expectedClassLc;
        }
        if (Variable::TYPE_OBJECT === $value->type) {
            $obj = $value->toObject();
            if (EnumCaseSupport::isEnumCase($obj)) {
                return strtolower(ltrim($obj->class->name, '\\')) === $expectedClassLc;
            }

            return strtolower(ltrim($obj->class->name, '\\')) === $expectedClassLc;
        }

        return false;
    }

    protected function compileParam(Op\Expr\Param $param, Block $block, int $paramIdx): OpCode {
        if ($param->byRef) {
            $block->paramByRef[$paramIdx] = true;
        }
        if ($param->variadic) {
            assert(null === $param->defaultVar);
            if (null !== $block->variadicParamIndex) {
                $this->throwCompileLogic('Only one variadic parameter is allowed per function');
            }
            $block->variadicParamIndex = $paramIdx;
        }
        $defaultConst = $this->resolvePropertyOrParamDefaultSlot($param, $block, $paramIdx);
        $slot = $this->compileOperand($param->result, $block, false);
        if ($param->name instanceof Operand\Literal && is_string($param->name->value)) {
            $block->paramNames[$paramIdx] = $param->name->value;
        }
        if (AttributeNames::isSensitiveParameter(AttributeNames::fromOp($param))) {
            $block->paramSensitive[$paramIdx] = true;
        }
        $this->applyParamDeclaredType($param, $block, $slot, $param->variadic);
        $this->assertParamDefaultMatchesDeclaredType($param, $defaultConst, $block);
        if ($this->paramIsImplicitNullable($param, $defaultConst, $block)) {
            $block->paramImplicitNullable[$slot] = true;
        }

        return new OpCode(
            OpCode::TYPE_ARG_RECV,
            $slot,
            $paramIdx,
            $defaultConst
        );
    }

    protected function compileFunction(Op\Stmt\Function_ $function, Block $block): OpCode {
        $funcBlock = $this->compileCfgBlock($function->func->cfg, $function->func->params, $function->func);
        NoDiscardMetadata::applyToBlock($funcBlock, $function);
        $this->markGeneratorIfNeeded($function, $funcBlock);
        if ($this->funcDeclReturnTypeIsNever($function->func)) {
            $this->neverFunctionNames[strtolower($function->func->name)] = true;
        }
        $operand = new Operand\Literal($function->func->name);
        $operand->type = Type::string();
        $return = new OpCode(
            OpCode::TYPE_FUNCDEF,
            $this->compileOperand($operand, $block, true)
        );
        $return->block1 = $funcBlock;
        $return->deprecatedMetadata = DeprecatedMetadata::fromOp($function);
        $this->assignAttributeMetadata($return, $function);
        $this->assignSourceMetadata($return, $function);
        AttributeNames::assertCompileTimeConstTargetOnly($return->attributeNames, 'function');
        AttributeNames::assertSensitiveParameterParamTargetOnly($return->attributeNames, 'function');
        return $return;
    }

    protected function compileOp(Op $op, Block $block) {
        if ($op instanceof Op\Expr\ConcatList) {
            $total = count($op->list);
            $return = $this->compileOperand($op->result, $block, false);
            if (0 === $total) {
                $empty = new Operand\Literal('');
                $empty->type = Type::string();
                $emptySlot = $this->compileOperand($empty, $block, true);
                $block->addOpCode(new OpCode(
                    OpCode::TYPE_ASSIGN,
                    $return,
                    $return,
                    $emptySlot
                ));
            } elseif (1 === $total) {
                // Zend string context for a lone encapsed variable (#4785) — not a plain assign.
                $part = $this->compileConcatListPart($op->list[0], $block);
                $block->addOpCode(new OpCode(
                    OpCode::TYPE_CAST_STRING,
                    $return,
                    $part
                ));
            } else {
                $pointer = 2;
                $block->addOpCode(new OpCode(
                    OpCode::TYPE_CONCAT,
                    $return,
                    $this->compileConcatListPart($op->list[0], $block),
                    $this->compileConcatListPart($op->list[1], $block)
                ));
                while ($pointer < $total) {
                    $right = $this->compileConcatListPart($op->list[$pointer++], $block);
                    $block->addOpCode(new OpCode(
                        OpCode::TYPE_CONCAT,
                        $return,
                        $return,
                        $right
                    ));
                }
            }
        } elseif ($op instanceof Op\Expr) {
            $block->addOpCode(...$this->compileExpr($op, $block));
        } elseif ($op instanceof Op\Stmt) {
            $this->compileStmt($op, $block);
        } elseif ($op instanceof Op\Terminal) {
            $terminalOps = $this->compileTerminal($op, $block);
            foreach ($terminalOps as $terminalOp) {
                $block->addOpCode($terminalOp);
            }
        } else {
            $this->throwCompileLogicForOp($op, 'Unknown Op Type: '.opcode_type_name($op->type));
        }
    }

    protected function compileStmt(Op\Stmt $stmt, Block $block) {
        if ($stmt instanceof Op\Stmt\Jump) {
            if (null !== $block->orig && $this->isErrorSuppressEndBlock($block->orig)) {
                $block->addOpCode(new OpCode(OpCode::TYPE_END_SILENCE));
            }
            $target = $this->compileCfgBranch($stmt->target, $block);
            if (!$this->isRedundantTryEntryJump($block, $target)) {
                $op = new OpCode(OpCode::TYPE_JUMP);
                $op->block1 = $target;
                $block->addOpCode($op);
            }
        } elseif ($stmt instanceof Op\Stmt\JumpIf) {
            if (null !== ($paramOp = $this->nullableParamFromReturnTernaryArms($stmt, $block))) {
                $this->emitImplicitNullableParamCoalesceReturn($paramOp, $block);

                return;
            }
            if (null !== $block->orig && $this->isErrorSuppressEndBlock($block->orig)) {
                $block->addOpCode(new OpCode(OpCode::TYPE_END_SILENCE));
            }
            $rewriteNeNull = $this->rewrittenNeNullReturnJumpIf->contains($stmt);
            $op = new OpCode(OpCode::TYPE_JUMPIF, $this->compileOperand($stmt->cond, $block, true));
            if ($rewriteNeNull) {
                $op->block1 = $this->compileCfgBranch($stmt->else, $block);
                $op->block2 = $this->compileCfgBranch($stmt->if, $block);
            } elseif ($this->jumpIfTargetsTernaryMerge($stmt)) {
                // Lower else before if so merge blocks record both branch phi slots (#3790, #5510).
                $op->block2 = $this->compileCfgBranch($stmt->else, $block);
                $op->block1 = $this->compileCfgBranch($stmt->if, $block);
            } else {
                $op->block1 = $this->compileCfgBranch($stmt->if, $block);
                $op->block2 = $this->compileCfgBranch($stmt->else, $block);
            }
            $block->addOpCode($op);
        } elseif ($stmt instanceof Op\Stmt\TryCatch) {
            // Reserve catch \$e slots before merge lowering allocates sibling temps (#9887).
            $reservedCatchVarSlots = [];
            foreach ($stmt->catches as $i => $catchBlock) {
                $catchVar = $stmt->catchVars[$i] ?? null;
                if (null !== $catchVar && null !== $this->resolveCatchVariableName($catchVar)) {
                    $reservedCatchVarSlots[$i] = $block->getVarSlot($catchVar, false);
                }
            }
            $merge = $this->compileCfgBranch($stmt->end, $block);
            $merge = $this->splitMergeBeforeNestedTry($merge);
            // Merge block is entered via TYPE_CATCH before catch locals exist (#195, #2084).
            $merge->inheritUndefinedLocals = true;
            // Lower catch bodies before try so sibling ?: merge prebind cannot clobber try locals (#6411).
            $compiledCatches = [];
            $savedCatchVarSlots = $this->activeCatchVarSlotsByName;
            $savedCatchVarRoots = $this->activeCatchVarRoots;
            $this->activeCatchVarSlotsByName = [];
            $this->activeCatchVarRoots = [];
            foreach ($stmt->catches as $i => $catchBlock) {
                $catchVar = $stmt->catchVars[$i] ?? null;
                $catchName = $this->resolveCatchVariableName($catchVar);
                if (null !== $catchName && isset($reservedCatchVarSlots[$i])) {
                    $lc = strtolower($catchName);
                    $this->activeCatchVarSlotsByName[$lc] = $reservedCatchVarSlots[$i];
                    $root = Block::cfgVarRoot($catchVar);
                    if (null !== $root) {
                        $this->activeCatchVarRoots[] = $root;
                    }
                }
            }
            foreach ($stmt->catches as $i => $catchBlock) {
                $compiledCatch = $this->compileCfgBranch($catchBlock, $block);
                $compiledCatch->inheritUndefinedLocals = true;
                $compiledCatches[] = $compiledCatch;
            }
            $this->activeCatchVarSlotsByName = $savedCatchVarSlots;
            $this->activeCatchVarRoots = $savedCatchVarRoots;
            $try = $this->compileCfgBranch($stmt->try, $block);
            $try->inheritUndefinedLocals = true;
            $tryOp = new OpCode(OpCode::TYPE_TRY);
            $tryOp->block1 = $try;
            $tryOp->block2 = $merge;
            $block->addOpCode($tryOp);
            foreach ($compiledCatches as $i => $compiledCatch) {
                $catchOp = new OpCode(OpCode::TYPE_CATCH);
                $catchOp->block1 = $compiledCatch;
                $catchOp->block2 = $merge;
                $catchOp->catchTypes = $this->encodeCatchTypeList($stmt->catchTypes[$i] ?? []);
                $catchOp->arg3 = $this->resolveCatchVarSlot(
                    $compiledCatch,
                    $stmt->catchVars[$i] ?? null
                ) ?? ($reservedCatchVarSlots[$i] ?? null);
                $block->addOpCode($catchOp);
            }
            if (null !== $stmt->finally) {
                $compiledFinally = $this->compileCfgBranch($stmt->finally, $block);
                // Catch runs before finally on throw; finally block must not inherit catch locals (#195).
                $compiledFinally->inheritUndefinedLocals = true;
                $finallyOp = new OpCode(OpCode::TYPE_FINALLY);
                $finallyOp->block1 = $compiledFinally;
                $finallyOp->block2 = $merge;
                $block->addOpCode($finallyOp);
                $this->rewriteMergeJumpsToFinally($try, $merge, $compiledFinally);
            }
        } elseif ($stmt instanceof Op\Stmt\Switch_) {
            $this->compileSwitchAsJumpIfChain($stmt, $block);
        } elseif ($stmt instanceof Op\Stmt\HaltCompiler) {
            if (null === $this->haltCompilerRemaining) {
                $this->haltCompilerRemaining = $stmt->remaining;
                $this->haltCompilerOffset = $stmt->haltOffset;
            }
            $block->addOpCode(new OpCode(OpCode::TYPE_RETURN_VOID));
        } else {
            $this->throwCompileLogicForOp($stmt, 'Unknown Stmt Type: '.$stmt->getType());
        }
    }

    /** php-cfg: {@see ErrorSuppressBlock} jump target where silenced reads are lowered (#3546). */
    private function isErrorSuppressEndBlock(CfgBlock $block): bool
    {
        if (1 !== \count($block->parents)) {
            return false;
        }
        $parent = $block->parents[0];

        return $parent instanceof ErrorSuppressBlock;
    }

    /**
     * php-cfg {@see ErrorSuppressBlock}: inner expr result is produced in the suppress branch but
     * consumed in the post-suppress block; inheritScopeFrom alone can miss unnamed SSA temps (#10336).
     */
    private function inheritErrorSuppressExpressionSlots(Block $suppressCompiled, Block $endCompiled): void
    {
        $suppressCfg = $suppressCompiled->orig;
        if (!$suppressCfg instanceof ErrorSuppressBlock) {
            return;
        }
        $endCfg = $endCompiled->orig;
        $innerSlots = [];
        foreach ($suppressCfg->children as $child) {
            if (!$this->isErrorSuppressInnerExpr($child)) {
                continue;
            }
            if (!isset($child->result)) {
                continue;
            }
            $slot = $suppressCompiled->slotForOperand($child->result);
            if (null === $slot) {
                $slot = $this->findFuncCallExecReturnSlot($suppressCompiled);
            }
            if (null === $slot) {
                continue;
            }
            $innerSlots[] = [$child->result, $slot];
            $endCompiled->forceBindScopeSlot($child->result, $slot);
            $root = Block::cfgVarRoot($child->result);
            if (null !== $root) {
                $endCompiled->prebindCfgVarRoot($root, $slot);
            }
            foreach ($child->result->usages as $usage) {
                if (
                    !$usage instanceof Op\Expr\FuncCall
                    && !$usage instanceof Op\Expr\NsFuncCall
                    && !$usage instanceof Op\Expr\MethodCall
                    && !$usage instanceof Op\Expr\StaticCall
                    && !$usage instanceof Op\Expr\New_
                ) {
                    continue;
                }
                if (property_exists($usage, 'args') && is_array($usage->args)) {
                    foreach ($usage->args as $arg) {
                        if ($arg instanceof Operand) {
                            $endCompiled->forceBindScopeSlot($arg, $slot);
                        }
                    }
                }
            }
        }
        if (1 === \count($innerSlots) && null !== $endCfg) {
            [, $slot] = $innerSlots[0];
            foreach ($endCfg->children as $endChild) {
                if (
                    !$endChild instanceof Op\Expr\FuncCall
                    && !$endChild instanceof Op\Expr\NsFuncCall
                    && !$endChild instanceof Op\Expr\MethodCall
                    && !$endChild instanceof Op\Expr\StaticCall
                ) {
                    continue;
                }
                if (!property_exists($endChild, 'args') || !is_array($endChild->args)) {
                    continue;
                }
                foreach ($endChild->args as $arg) {
                    if (
                        !$arg instanceof Operand
                        || $arg instanceof Operand\Literal
                        || $arg instanceof Operand\NullOperand
                        || null !== Block::cfgVarRoot($arg)
                    ) {
                        continue;
                    }
                    // PhiResolver can replace the suppress inner result with an unrelated temp (#10336).
                    $endCompiled->forceBindScopeSlot($arg, $slot);
                }
            }
        }
        if (null === $endCfg) {
            return;
        }
        foreach ($innerSlots as [$suppressResult, $slot]) {
            foreach ($endCfg->children as $endChild) {
                $this->bindErrorSuppressResultOperandUsages($endChild, $endCompiled, $suppressResult, $slot);
            }
        }
    }

    private function isErrorSuppressInnerExpr(Op $child): bool
    {
        return $child instanceof Op\Expr\FuncCall
            || $child instanceof Op\Expr\NsFuncCall
            || $child instanceof Op\Expr\MethodCall
            || $child instanceof Op\Expr\StaticCall
            || $child instanceof Op\Expr\New_;
    }

    private function findFuncCallExecReturnSlot(Block $block): ?int
    {
        foreach ($block->opCodes as $op) {
            if (OpCode::TYPE_FUNCCALL_EXEC_RETURN === $op->type) {
                return (int) $op->arg1;
            }
        }

        return null;
    }

    private function bindErrorSuppressResultOperandUsages(
        Op $cfgOp,
        Block $endCompiled,
        Operand $suppressResult,
        int $slot
    ): void {
        if ($cfgOp instanceof Op\Expr) {
            if (property_exists($cfgOp, 'args') && is_array($cfgOp->args)) {
                foreach ($cfgOp->args as $arg) {
                    if ($arg instanceof Operand && $this->operandsReferToSameVariable($suppressResult, $arg)) {
                        $endCompiled->bindScopeSlot($arg, $slot);
                    }
                }
            }
            if (property_exists($cfgOp, 'var') && $cfgOp->var instanceof Operand) {
                if ($this->operandsReferToSameVariable($suppressResult, $cfgOp->var)) {
                    $endCompiled->bindScopeSlot($cfgOp->var, $slot);
                }
            }
            if (property_exists($cfgOp, 'expr') && $cfgOp->expr instanceof Operand) {
                if ($this->operandsReferToSameVariable($suppressResult, $cfgOp->expr)) {
                    $endCompiled->bindScopeSlot($cfgOp->expr, $slot);
                }
            }
        }
        foreach ($cfgOp->children ?? [] as $child) {
            if ($child instanceof Op) {
                $this->bindErrorSuppressResultOperandUsages($child, $endCompiled, $suppressResult, $slot);
            }
        }
    }

    /**
     * Lower CFG switch to JUMPIF/EQUAL chain (JIT-safe; TYPE_CASE branchIf needs bool #96).
     */
    protected function compileSwitchAsJumpIfChain(Op\Stmt\Switch_ $switch, Block $block): void
    {
        if (!isset($switch->cond)) {
            $this->throwCompileLogic('Switch missing condition operand');
        }
        $condSlot = $this->requireOperandSlot(
            $this->compileOperand($switch->cond, $block, true),
            'switch condition'
        );
        $caseCount = count($switch->cases);
        if (0 === $caseCount) {
            $defaultOp = new OpCode(OpCode::TYPE_JUMP);
            $defaultOp->block1 = $this->compileCfgBranch($switch->default, $block);
            $block->addOpCode($defaultOp);

            return;
        }

        $current = $block;
        $savedSwitchJumpIfChain = $this->compilingSwitchJumpIfChain;
        $this->compilingSwitchJumpIfChain = true;
        for ($i = 0; $i < $caseCount; ++$i) {
            $eqSlot = $this->requireOperandSlot(
                $this->compileBoolTemporary($current),
                'switch equality temporary'
            );
            $caseSlot = $this->requireOperandSlot(
                $this->compileSwitchCaseOperand($switch->cases[$i], $current),
                'switch case #'.$i
            );
            $current->addOpCode(new OpCode(
                OpCode::TYPE_EQUAL,
                $eqSlot,
                $condSlot,
                $caseSlot
            ));

            $caseTarget = $this->compileCfgBranch($switch->targets[$i], $block);
            $isLast = $i === $caseCount - 1;
            if ($isLast) {
                $elseTarget = $this->compileCfgBranch($switch->default, $block);
            } else {
                $elseTarget = new Block($block->orig);
                $elseTarget->syntheticCfgBranch = true;
                $elseTarget->inheritUndefinedLocals = true;
                $elseTarget->inheritScopeFrom($current);
                $this->inheritFuncFromParent($elseTarget, $block);
            }

            $jump = new OpCode(OpCode::TYPE_JUMPIF, $eqSlot);
            $jump->block1 = $caseTarget;
            $jump->block2 = $elseTarget;
            $current->addOpCode($jump);
            $caseTarget->parents[] = $current;
            $elseTarget->parents[] = $current;
            if (!$isLast) {
                $current = $elseTarget;
            }
        }
        $this->compilingSwitchJumpIfChain = $savedSwitchJumpIfChain;
    }

    /**
     * Materialize switch case labels at runtime — php-cfg Switch_ cases may lack preceding fetches (#8767).
     */
    protected function compileSwitchCaseOperand(Operand $caseOperand, Block $block): ?int
    {
        if (null !== $block->orig) {
            foreach ($block->orig->children as $child) {
                if (!$child instanceof Op\Expr\ClassConstFetch) {
                    continue;
                }
                if ($child->result !== $caseOperand && !$this->operandsReferToSameVariable($child->result, $caseOperand)) {
                    continue;
                }
                foreach ($this->compileClassConstFetch($child, $block) as $op) {
                    $block->addOpCode($op);
                }

                return $this->compileOperand($caseOperand, $block, true);
            }
        }

        return $this->compileOperand($caseOperand, $block, true);
    }

    protected function getOpCodeTypeFromBinaryOp(Op\Expr\BinaryOp $expr): int {
        if ($expr instanceof Op\Expr\BinaryOp\Concat) {
            return OpCode::TYPE_CONCAT;
        } elseif ($expr instanceof Op\Expr\BinaryOp\Plus) {
            return OpCode::TYPE_PLUS;
        } elseif ($expr instanceof Op\Expr\BinaryOp\Smaller) {
            return OpCode::TYPE_SMALLER;
        } elseif ($expr instanceof Op\Expr\BinaryOp\Greater) {
            return OpCode::TYPE_GREATER;
        } elseif ($expr instanceof Op\Expr\BinaryOp\SmallerOrEqual) {
            return OpCode::TYPE_SMALLER_OR_EQUAL;
        } elseif ($expr instanceof Op\Expr\BinaryOp\GreaterOrEqual) {
            return OpCode::TYPE_GREATER_OR_EQUAL;
        } elseif ($expr instanceof Op\Expr\BinaryOp\Equal) {
            return OpCode::TYPE_EQUAL;
        } elseif ($expr instanceof Op\Expr\BinaryOp\NotEqual) {
            return OpCode::TYPE_NOT_EQUAL;
        } elseif ($expr instanceof Op\Expr\BinaryOp\Identical) {
            return OpCode::TYPE_IDENTICAL;
        } elseif ($expr instanceof Op\Expr\BinaryOp\NotIdentical) {
            return OpCode::TYPE_NOT_IDENTICAL;
        } elseif ($expr instanceof Op\Expr\BinaryOp\Spaceship) {
            return OpCode::TYPE_SPACESHIP;
        } elseif ($expr instanceof Op\Expr\BinaryOp\Minus) {
            return OpCode::TYPE_MINUS;
        } elseif ($expr instanceof Op\Expr\BinaryOp\Mul) {
            return OpCode::TYPE_MUL;
        } elseif ($expr instanceof Op\Expr\BinaryOp\Div) {
            return OpCode::TYPE_DIV;
        } elseif ($expr instanceof Op\Expr\BinaryOp\Mod) {
            return OpCode::TYPE_MODULO;
        } elseif ($expr instanceof Op\Expr\BinaryOp\Pow) {
            return OpCode::TYPE_POW;
        } elseif ($expr instanceof Op\Expr\BinaryOp\BitwiseAnd) {
            return OpCode::TYPE_BITWISE_AND;
        } elseif ($expr instanceof Op\Expr\BinaryOp\BitwiseOr) {
            return OpCode::TYPE_BITWISE_OR;
        } elseif ($expr instanceof Op\Expr\BinaryOp\BitwiseXor) {
            return OpCode::TYPE_BITWISE_XOR;
        } elseif ($expr instanceof Op\Expr\BinaryOp\ShiftLeft) {
            return OpCode::TYPE_SHIFT_LEFT;
        } elseif ($expr instanceof Op\Expr\BinaryOp\ShiftRight) {
            return OpCode::TYPE_SHIFT_RIGHT;
        } elseif ($expr instanceof Op\Expr\BinaryOp\LogicalXor) {
            return OpCode::TYPE_LOGICAL_XOR;
        }
        $this->throwCompileLogic("Unknown BinaryOp Type: " . $expr->getType());
    }

    protected function getOpCodeTypeFromCastOp(Op\Expr\Cast $expr): int {
        if ($expr instanceof Op\Expr\Cast\Array_) {
            return OpCode::TYPE_CAST_ARRAY;
        } elseif ($expr instanceof Op\Expr\Cast\Bool_) {
            return OpCode::TYPE_CAST_BOOL;
        } elseif ($expr instanceof Op\Expr\Cast\Double) {
            return OpCode::TYPE_CAST_FLOAT;
        } elseif ($expr instanceof Op\Expr\Cast\Int_) {
            return OpCode::TYPE_CAST_INT;
        } elseif ($expr instanceof Op\Expr\Cast\Object_) {
            return OpCode::TYPE_CAST_OBJECT;
        } elseif ($expr instanceof Op\Expr\Cast\String_) {
            return OpCode::TYPE_CAST_STRING;
        } elseif ($expr instanceof Op\Expr\Cast\Unset_) {
            return OpCode::TYPE_CAST_UNSET;
        } elseif ($expr instanceof Op\Expr\Cast\Void_) {
            return OpCode::TYPE_CAST_VOID;
        }
        $this->throwCompileLogic("Unknown CastOp Type: " . $expr->getType());
    }

    protected function compileIncDecExpr(Op\Expr $expr, Block $block, int $opcode): array
    {
        // php-cfg may clear write after SSA replace; read still names the lvalue (#4946).
        $write = $expr->write ?? $expr->read;
        $this->rejectThisReassignment($write);
        $this->rejectNullsafeInWriteContext($write, $block);
        $this->rejectNewExprInWriteContext($write, $block);
        $this->rejectGlobalConstInWriteContext($write, $block);

        return [new OpCode(
            $opcode,
            $this->compileOperand($expr->result, $block, false),
            $this->compileOperand($expr->read, $block, true),
            $this->compileOperand($write, $block, false),
        )];
    }

    protected function getOpCodeTypeFromUnaryOp(Op\Expr $expr): int {
        if ($expr instanceof Op\Expr\UnaryMinus) {
            return OpCode::TYPE_UNARY_MINUS;
        } elseif ($expr instanceof Op\Expr\UnaryPlus) {
            return OpCode::TYPE_UNARY_PLUS;
        } elseif ($expr instanceof Op\Expr\BitwiseNot) {
            return OpCode::TYPE_BITWISE_NOT;
        } elseif ($expr instanceof Op\Expr\BooleanNot) {
            return OpCode::TYPE_BOOLEAN_NOT;
        } elseif ($expr instanceof Op\Expr\Clone_) {
            return OpCode::TYPE_CLONE;
        } elseif ($expr instanceof Op\Expr\Empty_) {
            return OpCode::TYPE_EMPTY;
        } elseif ($expr instanceof Op\Expr\Eval_) {
            return OpCode::TYPE_EVAL;
        } elseif ($expr instanceof Op\Expr\Exit_) {
            return OpCode::TYPE_EXIT;
        } elseif ($expr instanceof Op\Expr\Print_) {
            return OpCode::TYPE_PRINT;
        }
        $this->throwCompileLogic("Unknown UnaryOp Type: " . $expr->getType());
    }

    protected function compileExpr(Op\Expr $expr, Block $block): array {
        if ($expr instanceof Op\Expr\BinaryOp\Coalesce) {
            $this->compileCoalesce($expr, $block);

            return [];
        }
        if ($expr instanceof Op\Expr\BinaryOp) {
            if (null !== $expr->left) {
                $this->compileEmbeddedExprForOperand($expr->left, $block);
            }
            if (null !== $expr->right) {
                $this->compileEmbeddedExprForOperand($expr->right, $block);
            }
            $opcode = new OpCode(
                $this->getOpCodeTypeFromBinaryOp($expr),
                $this->compileOperand($expr->result, $block, false),
                null !== $expr->left ? $this->compileOperand($expr->left, $block, true) : null,
                null !== $expr->right ? $this->compileOperand($expr->right, $block, true) : null,
            );
            if ($this->isIncDecBinaryOp($expr)) {
                $opcode->isIncDec = true;
            }

            return [$opcode];
        } elseif ($expr instanceof Op\Expr\Cast) {
            if ($expr instanceof Op\Expr\Cast\Unset_) {
                $this->throwCompileError('The (unset) cast is no longer supported');
            }
            $line = $expr->getLine();
            $castResultSlot = $this->compileOperand($expr->result, $block, false);
            $ops = [new OpCode(
                $this->getOpCodeTypeFromCastOp($expr),
                $castResultSlot,
                $this->compileOperand($expr->expr, $block, true),
                $line > 0 ? $line : null,
            )];
            if ($expr instanceof Op\Expr\Cast\Bool_) {
                $phiSlot = $this->logicalShortCircuitPhiMergeSlot($block);
                if (null !== $phiSlot && $castResultSlot !== $phiSlot) {
                    $ops[] = new OpCode(
                        OpCode::TYPE_ASSIGN,
                        $phiSlot,
                        $phiSlot,
                        $castResultSlot
                    );
                }
            }

            return $ops;
        }
        switch (get_class($expr)) {
            case Op\Expr\ArrowFunction::class:
                return $this->compileAnonymousFunctionExpr($expr, $block);
            case Op\Expr\Closure::class:
                return $this->compileAnonymousFunctionExpr($expr, $block);
            case Op\Expr\Assertion::class:
                if ($expr->result instanceof Operand\Literal) {
                    //short circuit
                    return [];
                } elseif ($expr->expr === $expr->result) {
                    return [];
                }
                return [new OpCode(
                    OpCode::TYPE_TYPE_ASSERT,
                    $this->compileOperand($expr->result, $block, false),   
                    $this->compileOperand($expr->expr, $block, true) 
                )];
            case Op\Expr\Assign::class:
                if (!$this->assignIsListSpread($expr)) {
                    $this->rejectThisReassignment($expr->var);
                    $this->rejectNullsafeInWriteContext($expr->var, $block);
                    $this->rejectNewExprInWriteContext($expr->var, $block, $expr->expr, $expr);
                    $this->rejectGlobalConstInWriteContext($expr->var, $block);
                }
                if ($this->assignIsListSpread($expr)) {
                    $fromIndex = new Operand\Literal($expr->listSpreadFromIndex);
                    $spreadOp = new OpCode(
                        OpCode::TYPE_LIST_SPREAD_ASSIGN,
                        $this->compileOperand($expr->var, $block, false),
                        $this->compileOperand($expr->listSpreadRhs, $block, true),
                        $this->compileOperand($fromIndex, $block, true),
                    );
                    $spreadOp->listSpreadExcludedKeys = $expr->listSpreadExcludedKeys ?? [];

                    return [$spreadOp];
                }
                $staticPropertyFetch = $this->unwrapStaticPropertyFetch($expr->var);
                $emitStaticPropertyFetch = true;
                if (null === $staticPropertyFetch) {
                    $staticPropertyFetch = $this->findStaticPropertyFetchForAssign($expr->var, $block);
                    $emitStaticPropertyFetch = false;
                }
                if (null !== $staticPropertyFetch) {
                    $fetchSlot = $this->compileOperand($staticPropertyFetch->result, $block, false);
                    $rhsSlot = $this->compileOperand($expr->expr, $block, true);
                    $ops = [];
                    if ($emitStaticPropertyFetch) {
                        $ops[] = new OpCode(
                            OpCode::TYPE_STATIC_PROPERTY_FETCH,
                            $fetchSlot,
                            $this->compileOperand($staticPropertyFetch->class, $block, true),
                            $this->compileStaticPropertyNameSlot($staticPropertyFetch->name, $staticPropertyFetch->class, $block)
                        );
                    }
                    $ops[] = new OpCode(
                        OpCode::TYPE_ASSIGN,
                        $fetchSlot,
                        $fetchSlot,
                        $rhsSlot
                    );
                    if ([] !== $expr->result->usages) {
                        $ops[] = new OpCode(
                            OpCode::TYPE_ASSIGN,
                            $this->compileOperand($expr->result, $block, false),
                            $fetchSlot,
                            $rhsSlot
                        );
                    }

                    return $ops;
                }
                $propertyFetch = $this->unwrapPropertyFetch($expr->var)
                    ?? $this->findCoalescePropertyFetch($expr->var, $block);
                if (null !== $propertyFetch) {
                    $fetchSlot = $this->compileOperand($propertyFetch->result, $block, false);
                    $rhsSlot = $this->compileOperand($expr->expr, $block, true);
                    $ops = [
                        new OpCode(
                            OpCode::TYPE_PROPERTY_FETCH,
                            $fetchSlot,
                            $this->compileOperand($propertyFetch->var, $block, true),
                            $this->compileOperand($propertyFetch->name, $block, true)
                        ),
                        new OpCode(
                            OpCode::TYPE_ASSIGN,
                            $fetchSlot,
                            $fetchSlot,
                            $rhsSlot
                        ),
                    ];
                    if ([] !== $expr->result->usages) {
                        $ops[] = new OpCode(
                            OpCode::TYPE_ASSIGN,
                            $this->compileOperand($expr->result, $block, false),
                            $fetchSlot,
                            $rhsSlot
                        );
                    }

                    return $ops;
                }

                $mergeAssignSlot = $this->branchMergeAssignSlot($block, $expr);
                if (null !== $block->orig && $this->isMergeBranchAssign($block, $expr)) {
                    $mergeCfg = $this->branchJumpMergeTarget($block->orig);
                    if (null !== $mergeCfg && $this->mergeCfgBlockUsesTernaryPhi($mergeCfg)) {
                        $recordedPhi = $this->ternaryMergePhiRhsSlot($mergeCfg);
                        if (null !== $recordedPhi) {
                            $mergeAssignSlot = $recordedPhi;
                        }
                    }
                }
                if (null !== $mergeAssignSlot) {
                    $root = Block::cfgVarRoot($expr->var);
                    if ($root instanceof Operand\Variable) {
                        $block->prebindCfgVarRoot($root, $mergeAssignSlot);
                    } else {
                        $block->bindScopeSlot($expr->var, $mergeAssignSlot);
                    }
                }
                $destSlot = null !== $mergeAssignSlot
                    ? $mergeAssignSlot
                    : $this->compileOperand($expr->var, $block, false);
                if (null !== $block->orig && $this->isMergeBranchAssign($block, $expr)) {
                    $mergeCfg = $this->branchJumpMergeTarget($block->orig);
                    if (null !== $mergeCfg && $this->mergeCfgBlockUsesTernaryPhi($mergeCfg)) {
                        if (!$this->ternaryMergePhiRhsSlots->contains($mergeCfg)) {
                            $this->ternaryMergePhiRhsSlots[$mergeCfg] = (int) $destSlot;
                        }
                    }
                }
                $rhsSlot = $this->compileOperand($expr->expr, $block, true);

                return [new OpCode(
                    OpCode::TYPE_ASSIGN,
                    $this->compileOperand($expr->result, $block, false),
                    $destSlot,
                    $rhsSlot
                )];
            case Op\Expr\Exit_::class:
                $exitExpr = null !== $expr->expr
                    ? $this->compileOperand($expr->expr, $block, true)
                    : null;
                $resultSlot = null;
                if ([] !== $expr->result->usages || $block->callResultFeedsReturn($expr->result)) {
                    $resultSlot = $this->compileOperand($expr->result, $block, false);
                }

                $exitOp = new OpCode(
                    OpCode::TYPE_EXIT,
                    $resultSlot,
                    $exitExpr,
                    max(0, $expr->getLine())
                );
                if (null !== $expr->message) {
                    $exitOp->exitMessageSlot = $this->compileOperand($expr->message, $block, true);
                }

                return [$exitOp];
            case Op\Expr\PostInc::class:
                return $this->compileIncDecExpr($expr, $block, OpCode::TYPE_POST_INC);
            case Op\Expr\PreInc::class:
                return $this->compileIncDecExpr($expr, $block, OpCode::TYPE_PRE_INC);
            case Op\Expr\PostDec::class:
                return $this->compileIncDecExpr($expr, $block, OpCode::TYPE_POST_DEC);
            case Op\Expr\PreDec::class:
                return $this->compileIncDecExpr($expr, $block, OpCode::TYPE_PRE_DEC);
            case Op\Expr\UnaryMinus::class:
            case Op\Expr\UnaryPlus::class:
                $foldedUnaryLiteral = $this->tryFoldUnaryLiteralDefault($expr);
                if (null !== $foldedUnaryLiteral) {
                    $block->registerConstant($expr->result, $foldedUnaryLiteral);

                    return [];
                }

                return [new OpCode(
                    $this->getOpCodeTypeFromUnaryOp($expr),
                    $this->compileOperand($expr->result, $block, false),
                    $this->compileUnaryExprReadOperand($expr, $block)
                )];
            case Op\Expr\BitwiseNot::class:
            case Op\Expr\BooleanNot::class:
            case Op\Expr\Clone_::class:
                return [new OpCode(
                    $this->getOpCodeTypeFromUnaryOp($expr),
                    $this->compileOperand($expr->result, $block, false),
                    $this->compileUnaryExprReadOperand($expr, $block)
                )];
            case Op\Expr\Empty_::class:
                $emptyOperand = $this->recoverEmptyExprOperand($expr, $block)
                    ?? $this->unaryExprOperandForRead($expr, $block);
                $propFetch = null !== $emptyOperand
                    ? $this->findCoalescePropertyFetch($emptyOperand, $block)
                    : null;
                if (null === $propFetch && null !== $emptyOperand) {
                    $propFetch = $this->unwrapPropertyFetch($emptyOperand);
                }
                if (null !== $propFetch) {
                    return [new OpCode(
                        OpCode::TYPE_EMPTY_OBJECT_PROPERTY,
                        $this->compileOperand($expr->result, $block, false),
                        $this->compileOperand($propFetch->var, $block, true),
                        $this->compileOperand($propFetch->name, $block, true),
                    )];
                }
                $dimFetch = null !== $emptyOperand
                    ? $this->findCoalesceArrayDimFetch($emptyOperand, $block)
                    : null;
                if (null !== $dimFetch) {
                    $resultSlot = $this->compileOperand($expr->result, $block, false);
                    $checkSlot = $this->compileBoolTemporary($block);
                    [$containerSlot, $dimSlot] = $this->resolveIssetTargetFromArrayDimFetch($dimFetch, $block);
                    if (null !== $containerSlot) {
                        return [
                            $this->makeIssetOpCode($checkSlot, $containerSlot, $dimSlot, false),
                            new OpCode(
                                OpCode::TYPE_BOOLEAN_NOT,
                                $resultSlot,
                                $checkSlot
                            ),
                        ];
                    }
                }

                return [new OpCode(
                    OpCode::TYPE_EMPTY,
                    $this->compileOperand($expr->result, $block, false),
                    $this->compileUnaryExprReadOperand($expr, $block)
                )];
            case Op\Expr\Eval_::class:
                return [new OpCode(
                    $this->getOpCodeTypeFromUnaryOp($expr),
                    $this->compileOperand($expr->result, $block, false),
                    $this->compileOperand($expr->expr, $block, true)
                )];
            case Op\Expr\Print_::class:
                $line = $expr->getLine();

                return [new OpCode(
                    $this->getOpCodeTypeFromUnaryOp($expr),
                    $this->compileOperand($expr->result, $block, false),
                    $this->compileOperand($expr->expr, $block, true),
                    $line > 0 ? $line : null
                )];
            case Op\Expr\ArrayDimFetch::class:
                $mergeEcho = $this->mergeEchoSlotForBranch($block);
                if (null !== $mergeEcho && !$this->isArrayDimFetchForWrite($expr, $block)) {
                    $block->forceFreshVarSlot($expr->result, $mergeEcho);
                }
                $dimSlot = null !== $expr->dim
                    ? $this->compileOperand($expr->dim, $block, true)
                    : null;
                $resultSlot = $this->compileOperand($expr->result, $block, false);
                if (null !== $mergeEcho && null !== $dimSlot && $dimSlot === $mergeEcho && null !== $expr->dim) {
                    $dimSlot = $this->freshLiteralConstantSlot($expr->dim, $block);
                }
                if (null !== $dimSlot && $resultSlot === $dimSlot) {
                    $block->forceFreshVarSlot($expr->result);
                    $resultSlot = $this->compileOperand($expr->result, $block, false);
                }
                $fetchType = $this->isArrayDimFetchForWrite($expr, $block)
                    ? OpCode::TYPE_ARRAY_DIM_FETCH_WRITE
                    : OpCode::TYPE_ARRAY_DIM_FETCH;

                return [new OpCode(
                    $fetchType,
                    $resultSlot,
                    $this->compileOperand($expr->var, $block, true),
                    $dimSlot
                )];
            case Op\Expr\ConstFetch::class:
                $nsName = null;
                if (!is_null($expr->nsName)) {
                    $nsName = $this->compileOperand($expr->nsName, $block, true);
                }
                return [new OpCode(
                    OpCode::TYPE_CONST_FETCH,
                    $this->compileOperand($expr->result, $block, false),
                    $this->compileOperand($expr->name, $block, true),
                    $nsName
                )];
            case Op\Expr\ClassConstFetch::class:
                return $this->compileClassConstFetch($expr, $block);
            case Op\Expr\StaticPropertyFetch::class:
                return [new OpCode(
                    OpCode::TYPE_STATIC_PROPERTY_FETCH,
                    $this->compileOperand($expr->result, $block, false),
                    $this->compileOperand($expr->class, $block, true),
                    $this->compileStaticPropertyNameSlot($expr->name, $expr->class, $block)
                )];
            case Op\Expr\FirstClassCallable::class:
                return $this->compileFirstClassCallable($expr, $block);
            case Op\Expr\FuncCall::class:
                if ($this->parensNewCallSkippedWithoutInvoke($expr->name, $block)) {
                    return [];
                }
                if ($this->operandIsInvokableReceiver($expr->name, $block)) {
                    return $this->compileMethodCallOpcodes(
                        $this->compileOperand($expr->name, $block, true),
                        $this->compileOperand(new Operand\Literal('__invoke'), $block, true),
                        $expr->args,
                        $expr->result,
                        $block,
                        max(0, $expr->getLine()),
                        $expr
                    );
                }

                return $this->compileFuncCall(
                    $this->compileOperand($expr->name, $block, true),
                    $expr->args,
                    $expr->result,
                    $block,
                    max(0, $expr->getLine()),
                    $expr
                );
            case Op\Expr\NsFuncCall::class:
                if ($this->parensNewCallSkippedWithoutInvoke($expr->nsName, $block)) {
                    return [];
                }
                if ($this->operandIsInvokableReceiver($expr->nsName, $block)) {
                    return $this->compileMethodCallOpcodes(
                        $this->compileOperand($expr->nsName, $block, true),
                        $this->compileOperand(new Operand\Literal('__invoke'), $block, true),
                        $expr->args,
                        $expr->result,
                        $block,
                        max(0, $expr->getLine()),
                        $expr
                    );
                }

                return $this->compileFuncCall(
                    $this->compileOperand($expr->nsName, $block, true),
                    $expr->args,
                    $expr->result,
                    $block,
                    max(0, $expr->getLine()),
                    $expr
                );
            case Op\Expr\StaticCall::class:
                $parentScope = $this->staticCallUsesParentScope($expr->class);
                $classSlot = $parentScope
                    ? $this->compileOperand(new Operand\Literal('parent'), $block, true)
                    : $this->compileOperand($expr->class, $block, true);
                $init = new OpCode(
                    OpCode::TYPE_STATICCALL_INIT,
                    $classSlot,
                    $this->compileOperand($expr->name, $block, true)
                );
                $init->staticCallParentScope = $parentScope;
                $return = [$init];
                $className = $this->literalScopeClassName($expr->class)
                    ?? $this->staticNameFromOperand($expr->class);
                $methodName = $this->staticNameFromOperand($expr->name);
                $calleeName = null;
                if (null !== $className && null !== $methodName) {
                    $calleeName = ltrim($className, '\\').'::'.$methodName;
                }
                foreach ($this->compileCallArgSends($expr->args, $block, $calleeName, $expr) as $send) {
                    $return[] = $send;
                }
                $return[] = $this->compileFuncCallExecOpcode(
                    $expr->result,
                    $block,
                    max(0, $expr->getLine()),
                    $expr
                );
                return $return;
            case Op\Expr\New_::class:
                $className = $this->literalScopeClassName($expr->class);
                if (null !== $className) {
                    $lc = strtolower(ltrim($className, '\\'));
                    if (isset($this->abstractClasses[$lc])) {
                        $msg = isset($this->abstractEnums[$lc])
                            ? 'Cannot instantiate enum '.$className
                            : 'Cannot instantiate abstract class '.$className;
                        $this->throwCompileError($msg);
                    }
                }
                $resultSlot = $this->compileOperand($expr->result, $block, false);
                $line = $expr->getLine();
                $return = [
                    new OpCode(
                        OpCode::TYPE_NEW,
                        $resultSlot,
                        $this->compileOperand($expr->class, $block, true),
                        $line > 0 ? $line : null
                    )
                ];
                foreach ($this->compileCallArgSends($expr->args, $block) as $send) {
                    $return[] = $send;
                }
                $return[] = $this->compileFuncCallExecOpcode(
                    $expr->result,
                    $block,
                    $line > 0 ? $line : 0
                );
                return $return;
            case Op\Expr\MethodCall::class:
                $mergeEcho = $this->mergeEchoSlotForBranch($block);
                $catchReceiverSlot = $this->slotForActiveCatchVariable($expr->var);
                $receiverSlot = null !== $catchReceiverSlot
                    ? $catchReceiverSlot
                    : $this->compileOperand($expr->var, $block, true);
                $nameSlot = $this->compileOperand($expr->name, $block, true);
                $prefix = [];
                if (null !== $mergeEcho && $nameSlot === $mergeEcho) {
                    $nameSlot = $this->freshLiteralConstantSlot($expr->name, $block);
                }
                if (null !== $mergeEcho && null === $catchReceiverSlot) {
                    $resultSlot = $this->compileOperand($expr->result, $block, false);
                    if ($resultSlot === $mergeEcho) {
                        $block->forceFreshVarSlot($expr->result);
                    }
                    // Receiver must not alias ?: echo phi (condition var is often reused, #5506).
                    $recvTemp = new Operand\Temporary();
                    $recvSlot = $block->forceFreshVarSlot($recvTemp);
                    $prefix[] = new OpCode(OpCode::TYPE_ASSIGN, $recvSlot, $recvSlot, $receiverSlot);
                    $receiverSlot = $recvSlot;
                }

                return array_merge(
                    $prefix,
                    $this->compileMethodCallOpcodes(
                        $receiverSlot,
                        $nameSlot,
                        $expr->args,
                        $expr->result,
                        $block,
                        max(0, $expr->getLine()),
                        $expr
                    )
                );
            case Op\Expr\PropertyFetch::class:
                return [new OpCode(
                    OpCode::TYPE_PROPERTY_FETCH,
                    $this->compileOperand($expr->result, $block, false),
                    $this->compileOperand($expr->var, $block, true),
                    $this->compileOperand($expr->name, $block, true)
                )];
            case Op\Expr\Array_::class:
                return $this->compileArrayLiteral($expr, $block);
            case Op\Expr\MagicScriptConst::class:
                $line = Op\Expr\MagicScriptConst::KIND_LINE === $expr->kind
                    ? max(1, $expr->getLine())
                    : null;

                return [new OpCode(
                    OpCode::TYPE_SCRIPT_MAGIC,
                    $this->compileOperand($expr->result, $block, false),
                    $line,
                    Op\Expr\MagicScriptConst::KIND_HALT_OFFSET === $expr->kind
                        ? OpCode::SCRIPT_MAGIC_HALT_OFFSET
                        : $expr->kind,
                )];
            case Op\Expr\Include_::class:
                return [$this->compileIncludeOp($expr, $block)];
            case Op\Expr\Isset_::class:
                return $this->compileIsset($expr, $block);
            case Op\Expr\Throw_::class:
                return $this->compileThrowExpression($expr, $block);
            case Op\Iterator\Valid::class:
                return [new OpCode(
                    OpCode::TYPE_ITER_VALID,
                    $this->compileOperand($expr->result, $block, false),
                    $this->compileOperand($expr->var, $block, true)
                )];
            case Op\Iterator\Key::class:
                return [new OpCode(
                    OpCode::TYPE_ITER_KEY,
                    $this->compileOperand($expr->result, $block, false),
                    $this->compileOperand($expr->var, $block, true)
                )];
            case Op\Iterator\Value::class:
                return [new OpCode(
                    OpCode::TYPE_ITER_VALUE,
                    $this->compileOperand($expr->result, $block, false),
                    $this->compileOperand($expr->var, $block, true),
                    $expr->byRef ? 1 : 0
                )];
            case Op\Expr\InstanceOf_::class:
                return $this->compileInstanceOf($expr, $block);
            case Op\Expr\In_::class:
                return $this->compileIn($expr, $block);
            case Op\Expr\AssignRef::class:
                $this->rejectThisReassignment($expr->var);
                $this->rejectNullsafeInWriteContext($expr->var, $block);
                $this->rejectNewExprInWriteContext($expr->var, $block);
                $this->rejectGlobalConstInWriteContext($expr->var, $block);
                // Zend zend_compile.c: ref-binding to const/class-const array element (#5409).
                $this->rejectGlobalConstInWriteContext($expr->expr, $block);
                $bindRefFlags = 0;
                $dimFetch = $this->unwrapArrayDimFetch($expr->expr)
                    ?? $this->findArrayDimFetchForResult($expr->expr, $block);
                $arrayLiteral = null !== $dimFetch
                    ? ($this->unwrapArrayLiteralExpr($dimFetch->var)
                        ?? $this->findArrayExprForResult($dimFetch->var, $block))
                    : null;
                if (null !== $arrayLiteral) {
                    // Zend zend_compile_list_assign: ref target from inline array literal (#3799).
                    $bindRefFlags = 1;
                } elseif (0 !== $this->assignRefBindRefFlags) {
                    $bindRefFlags = $this->assignRefBindRefFlags;
                }
                $ops = [new OpCode(
                    OpCode::TYPE_ASSIGN_REF,
                    $this->compileOperand($expr->var, $block, false),
                    $this->compileOperand($expr->expr, $block, true),
                    $bindRefFlags ?: null
                )];
                if ([] !== $expr->result->usages) {
                    $ops[] = new OpCode(
                        OpCode::TYPE_ASSIGN,
                        $this->compileOperand($expr->result, $block, false),
                        $this->compileOperand($expr->var, $block, false),
                        $this->compileOperand($expr->expr, $block, true)
                    );
                }
                return $ops;
            case Op\Expr\Yield_::class:
                $this->markFunctionGenerator($block);

                return [new OpCode(
                    OpCode::TYPE_YIELD,
                    [] !== $expr->result->usages
                        ? $this->compileOperand($expr->result, $block, false)
                        : null,
                    null !== $expr->value
                        ? $this->compileOperand($expr->value, $block, true)
                        : (null !== $expr->key
                            ? $this->compileOperand($expr->key, $block, true)
                            : null),
                    null !== $expr->value && null !== $expr->key
                        ? $this->compileOperand($expr->key, $block, true)
                        : null,
                )];
            case Op\Expr\YieldFrom::class:
                $this->markFunctionGenerator($block);
                return [new OpCode(
                    OpCode::TYPE_YIELD_FROM,
                    [] !== $expr->result->usages
                        ? $this->compileOperand($expr->result, $block, false)
                        : null,
                    $this->compileOperand($expr->expr, $block, true),
                )];
            case Op\Expr\NullsafePropertyFetch::class:
                $this->compileNullsafePropertyFetch($expr, $block);

                return [];
            case Op\Expr\NullsafeMethodCall::class:
                $this->compileNullsafeMethodCall($expr, $block);

                return [];
        }
        $this->throwCompileLogicForOp($expr, 'Unsupported expression: '.$expr->getType());
    }

    /**
     * @param Op\Expr\ArrowFunction|Op\Expr\Closure $expr
     *
     * @return OpCode[]
     */
    protected function compileAnonymousFunctionExpr($expr, Block $block): array
    {
        if ($this->shouldStubClosureForBootstrap()) {
            $resultSlot = $this->compileOperand($expr->result, $block, false);
            $nullSlot = $this->compileOperand(new Operand\Literal(null), $block, true);

            return [new OpCode(
                OpCode::TYPE_ASSIGN,
                $resultSlot,
                $resultSlot,
                $nullSlot
            )];
        }
        $func = $expr->func;
        $wasArrowAutoCapture = $this->compilingArrowAutoCapture;
        if ($expr instanceof Op\Expr\ArrowFunction) {
            $this->compilingArrowAutoCapture = true;
        }
        try {
            $funcBlock = $this->compileCfgBlock($func->cfg, $func->params, $func);
        } finally {
            $this->compilingArrowAutoCapture = $wasArrowAutoCapture;
        }
        $this->markGeneratorIfNeeded($expr, $funcBlock);
        $op = new OpCode(
            OpCode::TYPE_CLOSURE,
            $this->compileOperand($expr->result, $block, false),
        );
        $op->block1 = $funcBlock;
        $this->assignAttributeMetadata($op, $expr);
        $this->assignSourceMetadata($op, $expr);
        AttributeNames::assertCompileTimeConstTargetOnly($op->attributeNames, 'function');
        AttributeNames::assertSensitiveParameterParamTargetOnly($op->attributeNames, 'function');
        if ($expr instanceof Op\Expr\Closure) {
            foreach ($expr->useVars as $useVar) {
                if (!$useVar instanceof Operand\BoundVariable) {
                    continue;
                }
                $name = $this->boundVariableName($useVar);
                $slot = $funcBlock->getVarSlot($useVar, false);
                $funcBlock->closureCaptureSlots[$slot] = true;
                if ($useVar->byRef) {
                    $funcBlock->closureCaptureByRef[$slot] = true;
                }
                $op->closureCaptures[] = [
                    'name' => $name,
                    'slot' => $slot,
                    'byRef' => $useVar->byRef,
                ];
            }
        } elseif ($expr instanceof Op\Expr\ArrowFunction) {
            // Zend auto-captures outer locals/parameters (zend_compile.c); nested fn-in-fn needs
            // explicit closureCaptures so VM/JIT bind at creation time (#4944, #4952).
            $seenCaptureSlots = [];
            foreach ($funcBlock->args as $captureOperand) {
                $slot = (int) $funcBlock->args[$captureOperand];
                if (isset($seenCaptureSlots[$slot])) {
                    continue;
                }
                $name = Block::resolveVariableName($captureOperand);
                if (null === $name || '' === $name) {
                    continue;
                }
                if (in_array($name, $funcBlock->paramNames, true)) {
                    continue;
                }
                $seenCaptureSlots[$slot] = true;
                $funcBlock->closureCaptureSlots[$slot] = true;
                $op->closureCaptures[] = [
                    'name' => $name,
                    'slot' => $slot,
                    'byRef' => false,
                ];
            }
        }

        return [$op];
    }

    private function boundVariableName(Operand\BoundVariable $useVar): string
    {
        if ($useVar->name instanceof Operand\Literal && is_string($useVar->name->value)) {
            return $useVar->name->value;
        }
        $this->throwCompileLogic('Closure use() variable name must be a literal');
    }

    protected function shouldStubClosureForBootstrap(): bool
    {
        $userScript = getenv('PHP_COMPILER_AOT_USER_SCRIPT');
        if ('1' === $userScript || 'true' === strtolower((string) $userScript)) {
            return false;
        }

        return '1' === (string) getenv('PHP_COMPILER_VENDOR_PRELINK')
            || '1' === (string) getenv('PHP_COMPILER_SELFHOST_AOT');
    }

    protected function markFunctionGenerator(Block $block): void
    {
        if (null === $block->func || null === $this->seen) {
            return;
        }
        foreach ($this->seen as $cfgBlock) {
            $compiled = $this->seen[$cfgBlock];
            if ($compiled->func === $block->func) {
                $compiled->isGenerator = true;
            }
        }
    }

    protected function markGeneratorIfNeeded(Op\CallableOp $callable, Block $funcBlock): void
    {
        if (Block::containsGeneratorOpcodesInCallableBody($funcBlock) || $this->callableOpHasSourceYield($callable)) {
            $this->markFunctionGenerator($funcBlock);
        }
    }

    protected function callableOpHasSourceYield(Op\CallableOp $callable): bool
    {
        if (!$callable instanceof Op) {
            return false;
        }
        $attrs = $callable->getAttributes();

        return isset($attrs[GeneratorYieldSourceMarker::ATTRIBUTE])
            && $attrs[GeneratorYieldSourceMarker::ATTRIBUTE];
    }

    protected function funcDeclReturnTypeIsGenerator(CfgFunc $func): bool
    {
        $returnType = $func->returnType;
        if ($returnType instanceof Op\Type\Literal) {
            return 'Generator' === $returnType->name;
        }
        if ($returnType instanceof Op\Type\Reference) {
            $decl = $returnType->declaration;

            return $decl instanceof Operand\Literal
                && is_string($decl->value)
                && 'Generator' === $decl->value;
        }

        return false;
    }

    protected function funcDeclReturnTypeIsNever(CfgFunc $func): bool
    {
        $returnType = $func->returnType;
        if ($returnType instanceof Op\Type\Never_) {
            return true;
        }
        if ($returnType instanceof Op\Type\Literal && 'never' === strtolower($returnType->name)) {
            return true;
        }

        return false;
    }

    private function isNeverFunctionCallOp(Op $op): bool
    {
        if ($op instanceof Op\Expr\FuncCall) {
            $name = $this->staticNameFromOperand($op->name);
        } elseif ($op instanceof Op\Expr\NsFuncCall) {
            $name = $this->staticNameFromOperand($op->nsName);
        } else {
            return false;
        }
        if (null === $name) {
            return false;
        }

        return isset($this->neverFunctionNames[strtolower($name)]);
    }

    /**
     * Ops after a call to a `: never` function in the same CFG block are unreachable (#4117).
     *
     * @param Op[] $ops
     */
    private function isUnreachableAfterNeverCall(Op $op, array $ops, int $index): bool
    {
        for ($j = $index - 1; $j >= 0; --$j) {
            if ($this->isNeverFunctionCallOp($ops[$j])) {
                return true;
            }
            if (!$ops[$j] instanceof Op\Expr) {
                return false;
            }
        }

        return false;
    }

    private const ISSET_EXPRESSION_COMPILE_ERROR =
        'Cannot use isset() on the result of an expression (you can use "null !== expression" instead)';

    /**
     * Zend zend_compile.c zend_is_variable(): isset() operands must be variables, dims, or properties (#8802).
     */
    protected function assertIssetVariableOperand(Operand $operand, Block $block): void
    {
        if (null !== $this->findCoalescePropertyFetch($operand, $block)) {
            return;
        }
        if (null !== $this->findCoalesceArrayDimFetch($operand, $block)) {
            return;
        }
        if (null !== $this->unwrapVariableOperand($operand)) {
            return;
        }

        $this->throwCompileError(self::ISSET_EXPRESSION_COMPILE_ERROR);
    }

    /**
     * @return OpCode[]
     */
    protected function compileIsset(Op\Expr\Isset_ $expr, Block $block): array
    {
        assert(1 === count($expr->vars));
        $this->assertIssetVariableOperand($expr->vars[0], $block);
        $resultSlot = $this->compileOperand($expr->result, $block, false);
        $propFetch = $this->findCoalescePropertyFetch($expr->vars[0], $block);
        $dimFetch = null !== $propFetch ? null : $this->findCoalesceArrayDimFetch($expr->vars[0], $block);
        [$containerSlot, $dimSlot] = null !== $propFetch
            ? $this->resolveIssetTargetFromPropertyFetch($propFetch, $block)
            : (null !== $dimFetch
                ? $this->resolveIssetTargetFromArrayDimFetch($dimFetch, $block)
                : $this->resolveIssetTarget($expr->vars[0], $block));
        if (null === $containerSlot) {
            $varSlot = $this->compileOperand($expr->vars[0], $block, true);

            return [new OpCode(OpCode::TYPE_ISSET, $resultSlot, $varSlot, null)];
        }

        return [$this->makeIssetOpCode($resultSlot, $containerSlot, $dimSlot, null !== $propFetch)];
    }

    protected function compileIncludeOp(Op\Expr\Include_ $expr, Block $block): OpCode
    {
        $resultSlot = null;
        if (!$block->returnTypeVoid && !$block->returnTypeNever) {
            if ($expr->result instanceof Operand\Temporary) {
                if ([] !== $expr->result->usages) {
                    $resultSlot = $this->compileOperand($expr->result, $block, false);
                }
            } else {
                $resultSlot = $this->compileOperand($expr->result, $block, false);
            }
        }

        $sourceFile = $expr->getFile() ?? '';
        $includeKind = match ($expr->type) {
            Op\Expr\Include_::TYPE_INCLUDE => OpCode::INCLUDE_KIND_INCLUDE,
            Op\Expr\Include_::TYPE_INCLUDE_ONCE => OpCode::INCLUDE_KIND_INCLUDE_ONCE,
            Op\Expr\Include_::TYPE_REQUIRE => OpCode::INCLUDE_KIND_REQUIRE,
            Op\Expr\Include_::TYPE_REQUIRE_ONCE => OpCode::INCLUDE_KIND_REQUIRE_ONCE,
            default => OpCode::INCLUDE_KIND_INCLUDE_ONCE,
        };

        $deploySpec = ConstStringFolder::tryParseDeployInclude($block->orig, $expr->expr, $sourceFile);
        if (null !== $deploySpec) {
            $pathIndex = count($block->deployIncludePaths);
            $block->deployIncludePaths[$pathIndex] = $deploySpec;
            $compilePath = $deploySpec['compile'] ?? '';
            $pathOperand = new Operand\Literal('' !== $compilePath ? $compilePath : ' ');
            $pathOperand->type = Type::string();

            $op = new OpCode(
                OpCode::TYPE_INCLUDE,
                $this->compileOperand($pathOperand, $block, true),
                $resultSlot,
                $pathIndex,
            );
            $op->includeKind = $includeKind;

            return $op;
        }

        $includePath = ConstStringFolder::foldForInclude($block->orig, $expr->expr, $sourceFile);
        if (null !== $includePath) {
            $resolved = IncludePathResolver::resolve($includePath, $expr->getFile());
            if (null !== $resolved) {
                $this->markCallerLocalsUsedByLiteralInclude($resolved, $block);
                $literal = new Operand\Literal($resolved);
                $literal->type = Type::string();
                $pathIndex = count($block->literalIncludePaths);
                $block->literalIncludePaths[$pathIndex] = $resolved;

                $op = new OpCode(
                    OpCode::TYPE_INCLUDE,
                    $this->compileOperand($literal, $block, true),
                    $resultSlot,
                    $pathIndex,
                );
                $op->includeKind = $includeKind;

                return $op;
            }
        }

        $op = new OpCode(
            OpCode::TYPE_INCLUDE,
            $this->compileOperand($expr->expr, $block, true),
            $resultSlot,
        );
        $op->includeKind = $includeKind;

        return $op;
    }

    /**
     * ?? right branch: evaluate RHS expr ops only when the left is null (#3462, #3798).
     *
     * @return array{0: ?int, 1: Block} value slot and block that must receive the outer ?? assign
     */
    private function compileCoalesceRhsValue(Operand $rhs, Block $targetBlock, Block $entryBlock): array
    {
        $exprOp = $this->findOrigExprOpForOperand($rhs, $entryBlock);
        if ($exprOp instanceof Op\Expr\BinaryOp\Coalesce) {
            $afterCoalesce = $this->compileCoalesce($exprOp, $targetBlock);

            return [$this->compileCoalesceRhsResultSlot($exprOp, $afterCoalesce), $afterCoalesce];
        }
        if (null !== $exprOp) {
            if ($exprOp instanceof Op\Expr\Throw_) {
                foreach ($this->compileThrowExpression($exprOp, $targetBlock, $entryBlock) as $op) {
                    $targetBlock->addOpCode($op);
                }

                return [null, $targetBlock];
            }
            $afterExpr = $this->compileDeferredCoalesceBranchExpr($exprOp, $targetBlock);

            return [$afterExpr[1], $afterExpr[0]];
        }

        return [$this->compileOperand($rhs, $targetBlock, true), $targetBlock];
    }

    /**
     * Stmt-deferred ?? RHS ops are lowered with compileOperand(..., isRead: false); read the same slot (#11801).
     */
    private function compileCoalesceRhsResultSlot(Op\Expr $exprOp, Block $block): ?int
    {
        return $this->compileOperand($exprOp->result, $block, false);
    }

    /**
     * Lower stmt-deferred expr ops on a ?? branch (#3462, #5263).
     *
     * @return array{0: Block, 1: ?int} block and result slot for the deferred expr
     */
    private function compileDeferredCoalesceBranchExpr(Op\Expr $exprOp, Block $targetBlock): array
    {
        if ($exprOp instanceof Op\Expr\NullsafePropertyFetch) {
            $after = $this->compileNullsafePropertyFetch($exprOp, $targetBlock);

            return [$after, $this->compileCoalesceRhsResultSlot($exprOp, $after)];
        }
        if ($exprOp instanceof Op\Expr\NullsafeMethodCall) {
            $after = $this->compileNullsafeMethodCall($exprOp, $targetBlock);

            return [$after, $this->compileCoalesceRhsResultSlot($exprOp, $after)];
        }
        // Pre-bind the producer slot so compileExpr cannot collide with the outer ?? result (#11801).
        $resultSlot = $this->compileCoalesceRhsResultSlot($exprOp, $targetBlock);
        foreach ($this->compileExpr($exprOp, $targetBlock) as $op) {
            $targetBlock->addOpCode($op);
        }

        return [$targetBlock, $resultSlot];
    }

    /**
     * php-cfg emits RHS expr ops (New_, Throw_, …) before Coalesce; lower them on the ?? branch (#3462).
     *
     * @param Op[] $ops
     */
    private function isLoweredByFollowingCoalesce(Op $op, array $ops, int $index): bool
    {
        if (!$op instanceof Op\Expr) {
            return false;
        }
        $count = count($ops);
        for ($j = $index + 1; $j < $count; ++$j) {
            $next = $ops[$j];
            if ($next instanceof Op\Expr\BinaryOp\Coalesce) {
                return $this->exprOpFeedsCoalesceRhs($op, $next);
            }
            if (!$next instanceof Op\Expr\Throw_) {
                return false;
            }
        }

        return false;
    }

    private function exprOpFeedsCoalesceRhs(Op\Expr $op, Op\Expr\BinaryOp\Coalesce $coalesce): bool
    {
        if ($this->operandsChainEqual($op->result, $coalesce->right)) {
            return true;
        }
        $rhsRoot = $this->unwrapOperandChain($coalesce->right);
        if ($rhsRoot instanceof Op\Expr\Throw_ && $this->operandsChainEqual($op->result, $rhsRoot->expr)) {
            return true;
        }

        return false;
    }

    /**
     * php-cfg emits inner expr ops (New_, …) before Throw_; lower them inside compileExpr(Throw_) (#3802).
     *
     * @param Op[] $ops
     */
    private function isLoweredByFollowingThrow(Op $op, array $ops, int $index): bool
    {
        if (!$op instanceof Op\Expr) {
            return false;
        }
        $count = count($ops);
        for ($j = $index + 1; $j < $count; ++$j) {
            $next = $ops[$j];
            if ($next instanceof Op\Expr\Throw_) {
                return $this->exprOpFeedsThrowOperand($op, $next);
            }
            if (!$next instanceof Op\Expr) {
                return false;
            }
        }

        return false;
    }

    private function exprOpFeedsThrowOperand(Op\Expr $op, Op\Expr\Throw_ $throw): bool
    {
        return $this->operandsChainEqual($op->result, $throw->expr);
    }

    /**
     * Ops after throw-expr in the same CFG block are unreachable (?: arm, &&/|| RHS, = throw …) (#3802).
     *
     * @param Op[] $ops
     */
    private function isUnreachableAfterThrow(Op $op, array $ops, int $index): bool
    {
        for ($j = $index - 1; $j >= 0; --$j) {
            if ($ops[$j] instanceof Op\Expr\BinaryOp\Coalesce) {
                // ?? RHS throw is lowered on the coalesce branch; following stmts stay reachable (#9447).
                return false;
            }
            if ($ops[$j] instanceof Op\Expr\Throw_) {
                return true;
            }
            if (!$ops[$j] instanceof Op\Expr) {
                return false;
            }
        }

        return false;
    }

    private function findThrowInnerExprOp(Op\Expr\Throw_ $throw, Block $block): ?Op\Expr
    {
        $root = $this->unwrapOperandChain($throw->expr);
        if ($root instanceof Op\Expr) {
            return $root;
        }

        return $this->findOrigExprOpForOperand($throw->expr, $block);
    }

    /**
     * @return list<OpCode>
     */
    private function compileThrowExpression(Op\Expr\Throw_ $expr, Block $block, Block ...$extraSearchBlocks): array
    {
        if ($this->isBareRethrowExpression($expr, $block, ...$extraSearchBlocks)) {
            return [new OpCode(OpCode::TYPE_RETHROW)];
        }

        $newOp = $this->findNewExprForThrowOperand($expr, $block, ...$extraSearchBlocks);
        $ops = [];
        $throwSlot = null;
        if (null !== $newOp) {
            foreach ($this->compileNewExprForThrow($newOp, $block) as $innerOpcode) {
                $ops[] = $innerOpcode;
            }
            $throwSlot = $this->compileOperand($newOp->result, $block, true);
        } else {
            $innerOp = $this->findThrowInnerExprOp($expr, $block);
            if (null !== $innerOp) {
                foreach ($this->compileExpr($innerOp, $block) as $innerOpcode) {
                    $ops[] = $innerOpcode;
                }
            }
        }
        if (null === $throwSlot) {
            $throwSlot = $this->compileOperand($expr->expr, $block, true);
        }
        $line = $expr->getLine();
        $ops[] = new OpCode(
            OpCode::TYPE_THROW,
            $throwSlot,
            $line > 0 ? $line : null
        );

        return $ops;
    }

    private function findNewExprForThrowOperand(Op\Expr\Throw_ $throw, Block ...$searchBlocks): ?Op\Expr\New_
    {
        foreach ($searchBlocks as $searchBlock) {
            if (null === $searchBlock->orig) {
                continue;
            }
            foreach ($searchBlock->orig->children as $child) {
                if ($child instanceof Op\Expr\New_ && $this->operandsChainEqual($child->result, $throw->expr)) {
                    return $child;
                }
            }
        }

        return null;
    }

    /**
     * @return list<OpCode>
     */
    private function compileNewExprForThrow(Op\Expr\New_ $expr, Block $block): array
    {
        $className = $this->literalScopeClassName($expr->class);
        if (null !== $className) {
            $lc = strtolower(ltrim($className, '\\'));
            if (isset($this->abstractClasses[$lc])) {
                $msg = isset($this->abstractEnums[$lc])
                    ? 'Cannot instantiate enum '.$className
                    : 'Cannot instantiate abstract class '.$className;
                $this->throwCompileError($msg);
            }
        }
        $resultSlot = $block->forceFreshVarSlot($expr->result);
        $mergeEcho = $this->mergeEchoSlotForBranch($block);
        if (null !== $mergeEcho && $resultSlot === $mergeEcho) {
            $resultSlot = $block->forceFreshVarSlot($expr->result);
        }
        $line = $expr->getLine();
        $return = [
            new OpCode(
                OpCode::TYPE_NEW,
                $resultSlot,
                $this->compileOperand($expr->class, $block, true),
                $line > 0 ? $line : null
            ),
        ];
        foreach ($this->compileCallArgSends($expr->args, $block) as $send) {
            $return[] = $send;
        }
        $return[] = $this->compileFuncCallExecOpcode(
            $expr->result,
            $block,
            $line > 0 ? $line : 0
        );

        return $return;
    }

    private function compileOrigExprForOperand(Operand $operand, Block $block): void
    {
        $exprOp = $this->findOrigExprOpForOperand($operand, $block);
        if (null === $exprOp) {
            return;
        }
        $this->compileDeferredCoalesceBranchExpr($exprOp, $block);
    }

    private function findOrigExprOpForOperand(Operand $operand, Block $block): ?Op\Expr
    {
        $root = $this->unwrapOperandChain($operand);
        if ($root instanceof Op\Expr) {
            return $root;
        }
        if (null === $block->orig) {
            return null;
        }
        foreach ($block->orig->children as $child) {
            if ($child instanceof Op\Expr && $this->operandsChainEqual($child->result, $operand)) {
                return $child;
            }
        }

        return null;
    }

    protected function compileCoalesce(
        Op\Expr\BinaryOp\Coalesce $expr,
        Block $block,
        ?Operand $resultOverride = null
    ): Block {
        $resultOperand = $resultOverride ?? $expr->result;
        // php-cfg may mark the ?? result dead while it is still assigned on branch blocks (#99).
        if ($resultOperand instanceof Operand\Temporary && [] === $resultOperand->usages) {
            $resultOperand->usages[] = $resultOperand;
        }
        if (null === $expr->left) {
            $propFetch = null;
            $staticPropFetch = null;
            $dimFetch = null;
        } else {
            $propFetch = $this->findCoalescePropertyFetch($expr->left, $block);
            $staticPropFetch = null !== $propFetch
                ? null
                : $this->findCoalesceStaticPropertyFetch($expr->left, $block);
            $dimFetch = null !== $propFetch || null !== $staticPropFetch
                ? null
                : $this->findCoalesceArrayDimFetch($expr->left, $block);
        }
        // ??= on $arr['key']: dim fetch temp is read on the left branch (#3792).
        if (
            null !== $dimFetch
            && $dimFetch->result instanceof Operand\Temporary
            && [] === $dimFetch->result->usages
        ) {
            $dimFetch->result->usages[] = $dimFetch->result;
        }
        $resultSlot = $this->compileOperand($resultOperand, $block, false);

        $checkSlot = $this->compileBoolTemporary($block);
        $issetTarget = null !== $propFetch
            ? $this->resolveIssetTargetFromPropertyFetch($propFetch, $block)
            : (null !== $staticPropFetch
                ? $this->resolveIssetTargetFromStaticPropertyFetch($staticPropFetch, $block)
                : (null !== $dimFetch
                    ? $this->resolveIssetTargetFromArrayDimFetch($dimFetch, $block)
                    : (null !== $expr->left
                        ? $this->resolveCoalesceIssetTarget($expr->left, $block)
                        : null)));
        $useContainerIsset = null !== $issetTarget;
        if ($useContainerIsset) {
            [$containerSlot, $dimSlot] = $issetTarget;
            if (null === $containerSlot) {
                $useContainerIsset = false;
            }
        }
        $evaluatedLeftSlot = null;
        if ($useContainerIsset) {
            $issetOp = $this->makeIssetOpCode(
                $checkSlot,
                $containerSlot,
                $dimSlot,
                null !== $propFetch
            );
            if (
                null !== $propFetch
            ) {
                $issetOp->issetForCoalesceAssign = true;
            }
            if (null !== $staticPropFetch) {
                $issetOp->issetOnStaticProperty = true;
                $issetOp->issetForCoalesceAssign = true;
            }
            $block->addOpCode($issetOp);
        } elseif (null !== $expr->left) {
            $evaluatedLeftSlot = $this->compileOperand($expr->left, $block, true);
            $block->addOpCode(new OpCode(
                OpCode::TYPE_ISSET,
                $checkSlot,
                $evaluatedLeftSlot,
                null
            ));
        } else {
            $block->addOpCode(new OpCode(
                OpCode::TYPE_ISSET,
                $checkSlot,
                $resultSlot,
                null
            ));
        }

        $endBlock = new Block($block->orig);
        $endBlock->inheritUndefinedLocals = true;
        $endBlock->inheritScopeFrom($block);

        $rightBlock = new Block($block->orig);
        $rightBlock->syntheticCfgBranch = true;
        $rightBlock->inheritUndefinedLocals = true;
        $rightBlock->inheritScopeFrom($block);
        [$rightSlot, $rightEmitBlock] = $this->compileCoalesceRhsValue($expr->right, $rightBlock, $block);
        $coalesceAssignTarget = $resultOverride ?? $expr->result;
        if (
            null !== $dimFetch
            && $this->operandsChainEqual($coalesceAssignTarget, $dimFetch->result)
        ) {
            $this->compileArrayDimFetchWrite($dimFetch, $rightEmitBlock);
        }
        if (
            null !== $propFetch
            && $this->operandsChainEqual($coalesceAssignTarget, $propFetch->result)
        ) {
            $this->compilePropertyFetchWrite($propFetch, $rightEmitBlock);
        }
        if (
            null !== $staticPropFetch
            && $this->operandsChainEqual($coalesceAssignTarget, $staticPropFetch->result)
        ) {
            $this->compileStaticPropertyFetchWrite($staticPropFetch, $rightEmitBlock);
        }
        if (null !== $rightSlot && $rightSlot !== $resultSlot) {
            $rightEmitBlock->addOpCode(new OpCode(
                OpCode::TYPE_ASSIGN,
                $resultSlot,
                $resultSlot,
                $rightSlot
            ));
        }

        $leftBlock = new Block($block->orig);
        $leftBlock->syntheticCfgBranch = true;
        $leftBlock->inheritUndefinedLocals = true;
        $leftBlock->inheritScopeFrom($block);
        if ($useContainerIsset) {
            if (null !== $dimFetch) {
                $this->compileArrayDimFetchRead($dimFetch, $leftBlock);
                $leftSlot = $this->compileOperand($dimFetch->result, $leftBlock, true);
                // ??= left branch: skip store when result is the assign lvalue (php-src: no write when set).
                if (null !== $expr->left && !$this->operandsChainEqual($resultOperand, $expr->left)) {
                    $leftBlock->addOpCode(new OpCode(
                        OpCode::TYPE_ASSIGN,
                        $resultSlot,
                        $resultSlot,
                        $leftSlot
                    ));
                }
            } elseif (null !== $propFetch) {
                $this->compilePropertyFetchRead($propFetch, $leftBlock, true);
                $leftSlot = $this->compileOperand($propFetch->result, $leftBlock, true);
                if (!$this->operandsChainEqual($resultOperand, $expr->left)) {
                    $leftBlock->addOpCode(new OpCode(
                        OpCode::TYPE_ASSIGN,
                        $resultSlot,
                        $resultSlot,
                        $leftSlot
                    ));
                }
            } elseif (null !== $staticPropFetch) {
                $this->compileStaticPropertyFetchRead($staticPropFetch, $leftBlock, true);
                $leftSlot = $this->compileOperand($staticPropFetch->result, $leftBlock, true);
                if (!$this->operandsChainEqual($resultOperand, $expr->left)) {
                    $leftBlock->addOpCode(new OpCode(
                        OpCode::TYPE_ASSIGN,
                        $resultSlot,
                        $resultSlot,
                        $leftSlot
                    ));
                }
            } elseif (null !== $expr->left) {
                $leftSlot = $this->compileOperand($expr->left, $leftBlock, true);
                if (!$this->operandsChainEqual($resultOperand, $expr->left)) {
                    $leftBlock->addOpCode(new OpCode(
                        OpCode::TYPE_ASSIGN,
                        $resultSlot,
                        $resultSlot,
                        $leftSlot
                    ));
                }
            }
        } elseif (null !== $evaluatedLeftSlot) {
            // Nullsafe and other pre-evaluated ?? left operands: reuse entry-block temp (#9744).
            if (!$this->operandsChainEqual($resultOperand, $expr->left)) {
                $leftBlock->addOpCode(new OpCode(
                    OpCode::TYPE_ASSIGN,
                    $resultSlot,
                    $resultSlot,
                    $evaluatedLeftSlot
                ));
            }
        }

        $leftJump = new OpCode(OpCode::TYPE_JUMP);
        $leftJump->block1 = $endBlock;
        $leftBlock->addOpCode($leftJump);
        $rightJump = new OpCode(OpCode::TYPE_JUMP);
        $rightJump->block1 = $endBlock;
        $rightEmitBlock->addOpCode($rightJump);
        $endBlock->parents[] = $leftBlock;
        $endBlock->parents[] = $rightEmitBlock;
        $endBlock->inheritScopeFrom($leftBlock);
        $endBlock->inheritScopeFrom($rightEmitBlock);

        $this->coalesceResultSlots[spl_object_id($expr)] = $resultSlot;

        $coalesceOp = new OpCode(
            OpCode::TYPE_COALESCE,
            $resultSlot,
            $checkSlot
        );
        $coalesceOp->block1 = $leftBlock;
        $coalesceOp->block2 = $rightBlock;
        $coalesceOp->block3 = $endBlock;
        $block->addOpCode($coalesceOp);

        return $endBlock;
    }

    /**
     * Emit a read fetch in $block (used by ?? left branch when the stmt fetch was skipped).
     */
    private function compilePropertyFetchRead(
        Op\Expr\PropertyFetch $fetch,
        Block $block,
        bool $propertyHookCoalesceRead = false
    ): void {
        $op = new OpCode(
            OpCode::TYPE_PROPERTY_FETCH,
            $this->compileOperand($fetch->result, $block, false),
            $this->compileOperand($fetch->var, $block, true),
            $this->compileOperand($fetch->name, $block, true)
        );
        if ($propertyHookCoalesceRead) {
            $op->propertyHookCoalesceRead = true;
        }
        $block->addOpCode($op);
    }

    private function compileStaticPropertyFetchRead(
        Op\Expr\StaticPropertyFetch $fetch,
        Block $block,
        bool $propertyHookCoalesceRead = false
    ): void {
        $op = new OpCode(
            OpCode::TYPE_STATIC_PROPERTY_FETCH,
            $this->compileOperand($fetch->result, $block, false),
            $this->compileOperand($fetch->class, $block, true),
            $this->compileStaticPropertyNameSlot($fetch->name, $fetch->class, $block)
        );
        if ($propertyHookCoalesceRead) {
            $op->propertyHookCoalesceRead = true;
        }
        $block->addOpCode($op);
    }

    /**
     * Emit a write fetch in $block (used by ??= right branch when backing is null, #6472).
     */
    private function compilePropertyFetchWrite(Op\Expr\PropertyFetch $fetch, Block $block): void
    {
        $block->addOpCode(new OpCode(
            OpCode::TYPE_PROPERTY_FETCH,
            $this->compileOperand($fetch->result, $block, false),
            $this->compileOperand($fetch->var, $block, true),
            $this->compileOperand($fetch->name, $block, true)
        ));
    }

    private function compileStaticPropertyFetchWrite(Op\Expr\StaticPropertyFetch $fetch, Block $block): void
    {
        $block->addOpCode(new OpCode(
            OpCode::TYPE_STATIC_PROPERTY_FETCH,
            $this->compileOperand($fetch->result, $block, false),
            $this->compileOperand($fetch->class, $block, true),
            $this->compileStaticPropertyNameSlot($fetch->name, $fetch->class, $block)
        ));
    }

    /**
     * Emit a read fetch in $block (used by ?? left branch when the stmt fetch was skipped).
     */
    private function compileArrayDimFetchRead(Op\Expr\ArrayDimFetch $fetch, Block $block): void
    {
        $block->addOpCode(new OpCode(
            OpCode::TYPE_ARRAY_DIM_FETCH,
            $this->compileOperand($fetch->result, $block, false),
            $this->compileOperand($fetch->var, $block, true),
            null !== $fetch->dim ? $this->compileOperand($fetch->dim, $block, true) : null
        ));
    }

    /**
     * Emit a write fetch in $block (used by ??= right branch when the key is absent, issue #1235).
     */
    private function compileArrayDimFetchWrite(Op\Expr\ArrayDimFetch $fetch, Block $block): void
    {
        $block->addOpCode(new OpCode(
            OpCode::TYPE_ARRAY_DIM_FETCH_WRITE,
            $this->compileOperand($fetch->result, $block, false),
            $this->compileOperand($fetch->var, $block, true),
            null !== $fetch->dim ? $this->compileOperand($fetch->dim, $block, true) : null
        ));
    }

    /**
     * Array offset immediately after ?-> property/method fetch (issue #3516).
     *
     * @param Op[] $ops
     */
    private function isNullsafeChainArrayDimFetch(array $ops, int $index): bool
    {
        if ($index < 1) {
            return false;
        }
        $fetch = $ops[$index];
        if (!$fetch instanceof Op\Expr\ArrayDimFetch) {
            return false;
        }
        $prev = $ops[$index - 1];
        if (!$prev instanceof Op\Expr\NullsafePropertyFetch && !$prev instanceof Op\Expr\NullsafeMethodCall) {
            return false;
        }

        return $prev->result === $fetch->var;
    }

    protected function compileNullsafeArrayDimFetch(Op\Expr\ArrayDimFetch $expr, Block $block): Block
    {
        $resultSlot = $this->compileOperand($expr->result, $block, false);
        $containerSlot = $this->compileOperand($expr->var, $block, true);
        $dimSlot = null !== $expr->dim ? $this->compileOperand($expr->dim, $block, true) : null;

        $endBlock = new Block($block->orig);
        $endBlock->inheritUndefinedLocals = true;
        $endBlock->inheritScopeFrom($block);

        $nullBlock = new Block($block->orig);
        $nullBlock->inheritUndefinedLocals = true;
        $nullBlock->inheritScopeFrom($block);
        $nullLiteral = new Operand\Literal(null);
        $nullLiteral->type = Type::null();
        $nullValueSlot = $this->compileOperand($nullLiteral, $nullBlock, true);
        $nullBlock->addOpCode(new OpCode(
            OpCode::TYPE_ASSIGN,
            $resultSlot,
            $resultSlot,
            $nullValueSlot
        ));
        $nullJump = new OpCode(OpCode::TYPE_JUMP);
        $nullJump->block1 = $endBlock;
        $nullBlock->addOpCode($nullJump);

        $fetchBlock = new Block($block->orig);
        $fetchBlock->inheritUndefinedLocals = true;
        $fetchBlock->inheritScopeFrom($block);
        $fetchBlock->addOpCode(new OpCode(
            OpCode::TYPE_ARRAY_DIM_FETCH,
            $this->compileOperand($expr->result, $fetchBlock, false),
            $this->compileOperand($expr->var, $fetchBlock, true),
            $dimSlot
        ));
        $fetchJump = new OpCode(OpCode::TYPE_JUMP);
        $fetchJump->block1 = $endBlock;
        $fetchBlock->addOpCode($fetchJump);
        $endBlock->parents[] = $nullBlock;
        $endBlock->parents[] = $fetchBlock;

        $nullsafeOp = new OpCode(
            OpCode::TYPE_NULLSAFE,
            $resultSlot,
            $containerSlot
        );
        $nullsafeOp->block1 = $nullBlock;
        $nullsafeOp->block2 = $fetchBlock;
        $nullsafeOp->block3 = $endBlock;
        $block->addOpCode($nullsafeOp);

        return $endBlock;
    }

    protected function compileNullsafePropertyFetch(Op\Expr\NullsafePropertyFetch $expr, Block $block): Block
    {
        $resultSlot = $this->compileOperand($expr->result, $block, false);
        $receiverSlot = $this->compileOperand($expr->var, $block, true);

        $endBlock = new Block($block->orig);
        $endBlock->inheritUndefinedLocals = true;
        $endBlock->inheritScopeFrom($block);

        $nullBlock = new Block($block->orig);
        $nullBlock->inheritUndefinedLocals = true;
        $nullBlock->inheritScopeFrom($block);
        $nullLiteral = new Operand\Literal(null);
        $nullLiteral->type = Type::null();
        $nullValueSlot = $this->compileOperand($nullLiteral, $nullBlock, true);
        $nullBlock->addOpCode(new OpCode(
            OpCode::TYPE_ASSIGN,
            $resultSlot,
            $resultSlot,
            $nullValueSlot
        ));
        $nullJump = new OpCode(OpCode::TYPE_JUMP);
        $nullJump->block1 = $endBlock;
        $nullBlock->addOpCode($nullJump);

        $fetchBlock = new Block($block->orig);
        $fetchBlock->inheritUndefinedLocals = true;
        $fetchBlock->inheritScopeFrom($block);
        $nullsafePropertyFetch = new OpCode(
            OpCode::TYPE_PROPERTY_FETCH,
            $this->compileOperand($expr->result, $fetchBlock, false),
            $this->compileOperand($expr->var, $fetchBlock, true),
            $this->compileOperand($expr->name, $fetchBlock, true)
        );
        $nullsafePropertyFetch->nullsafeFetchPropertyRead = true;
        $fetchBlock->addOpCode($nullsafePropertyFetch);
        $fetchJump = new OpCode(OpCode::TYPE_JUMP);
        $fetchJump->block1 = $endBlock;
        $fetchBlock->addOpCode($fetchJump);
        $endBlock->parents[] = $nullBlock;
        $endBlock->parents[] = $fetchBlock;

        $nullsafeOp = new OpCode(
            OpCode::TYPE_NULLSAFE,
            $resultSlot,
            $receiverSlot
        );
        $nullsafeOp->block1 = $nullBlock;
        $nullsafeOp->block2 = $fetchBlock;
        $nullsafeOp->block3 = $endBlock;
        $block->addOpCode($nullsafeOp);

        return $endBlock;
    }

    /**
     * @param list<Op\Expr\NullsafePropertyFetch> $chain
     */
    protected function compileIssetNullsafePropertyFetchChain(
        array $chain,
        Op\Expr\Isset_ $isset,
        Block $block
    ): Block {
        $resultSlot = $this->compileOperand($isset->result, $block, false);
        $endBlock = new Block($block->orig);
        $endBlock->inheritUndefinedLocals = true;
        $endBlock->inheritScopeFrom($block);
        $this->compileIssetNullsafeChainLink($chain, 0, $block, $resultSlot, $endBlock);

        return $endBlock;
    }

    /**
     * @param list<Op\Expr\NullsafePropertyFetch> $chain
     */
    protected function compileEmptyNullsafePropertyFetchChain(
        array $chain,
        Op\Expr\Empty_ $empty,
        Block $block
    ): Block {
        $resultSlot = $this->compileOperand($empty->result, $block, false);
        $endBlock = new Block($block->orig);
        $endBlock->inheritUndefinedLocals = true;
        $endBlock->inheritScopeFrom($block);
        $this->compileEmptyNullsafeChainLink($chain, 0, $block, $resultSlot, $endBlock);

        return $endBlock;
    }

    /**
     * @param list<Op\Expr\NullsafePropertyFetch> $chain
     */
    protected function compileIssetNullsafeChainLink(
        array $chain,
        int $index,
        Block $block,
        int $resultSlot,
        Block $endBlock
    ): void {
        $fetch = $chain[$index];
        $isLast = $index === count($chain) - 1;
        $receiverSlot = $this->compileOperand($fetch->var, $block, true);

        $nullBlock = new Block($block->orig);
        $nullBlock->inheritUndefinedLocals = true;
        $nullBlock->inheritScopeFrom($block);
        $falseSlot = $this->compileBoolConstant($nullBlock, false);
        $nullBlock->addOpCode(new OpCode(
            OpCode::TYPE_ASSIGN,
            $resultSlot,
            $resultSlot,
            $falseSlot
        ));
        $nullJump = new OpCode(OpCode::TYPE_JUMP);
        $nullJump->block1 = $endBlock;
        $nullBlock->addOpCode($nullJump);

        $fetchBlock = new Block($block->orig);
        $fetchBlock->inheritUndefinedLocals = true;
        $fetchBlock->inheritScopeFrom($block);
        if ($isLast) {
            $fetchBlock->addOpCode($this->makeIssetOpCode(
                $resultSlot,
                $this->compileOperand($fetch->var, $fetchBlock, true),
                $this->compileOperand($fetch->name, $fetchBlock, true),
                true
            ));
            $fetchJump = new OpCode(OpCode::TYPE_JUMP);
            $fetchJump->block1 = $endBlock;
            $fetchBlock->addOpCode($fetchJump);
        } else {
            $intermediateSlot = $this->compileOperand($fetch->result, $fetchBlock, false);
            $propFetch = new OpCode(
                OpCode::TYPE_PROPERTY_FETCH,
                $intermediateSlot,
                $this->compileOperand($fetch->var, $fetchBlock, true),
                $this->compileOperand($fetch->name, $fetchBlock, true)
            );
            $propFetch->nullsafeFetchPropertyRead = true;
            $fetchBlock->addOpCode($propFetch);
            $this->compileIssetNullsafeChainLink($chain, $index + 1, $fetchBlock, $resultSlot, $endBlock);
        }

        $endBlock->parents[] = $nullBlock;
        $endBlock->parents[] = $fetchBlock;

        $nullsafeOp = new OpCode(
            OpCode::TYPE_NULLSAFE,
            $isLast ? $resultSlot : $this->compileOperand($fetch->result, $block, false),
            $receiverSlot
        );
        $nullsafeOp->block1 = $nullBlock;
        $nullsafeOp->block2 = $fetchBlock;
        $nullsafeOp->block3 = $endBlock;
        $block->addOpCode($nullsafeOp);
    }

    /**
     * @param list<Op\Expr\NullsafePropertyFetch> $chain
     */
    protected function compileEmptyNullsafeChainLink(
        array $chain,
        int $index,
        Block $block,
        int $resultSlot,
        Block $endBlock
    ): void {
        $fetch = $chain[$index];
        $isLast = $index === count($chain) - 1;
        $receiverSlot = $this->compileOperand($fetch->var, $block, true);

        $nullBlock = new Block($block->orig);
        $nullBlock->inheritUndefinedLocals = true;
        $nullBlock->inheritScopeFrom($block);
        $trueSlot = $this->compileBoolConstant($nullBlock, true);
        $nullBlock->addOpCode(new OpCode(
            OpCode::TYPE_ASSIGN,
            $resultSlot,
            $resultSlot,
            $trueSlot
        ));
        $nullJump = new OpCode(OpCode::TYPE_JUMP);
        $nullJump->block1 = $endBlock;
        $nullBlock->addOpCode($nullJump);

        $fetchBlock = new Block($block->orig);
        $fetchBlock->inheritUndefinedLocals = true;
        $fetchBlock->inheritScopeFrom($block);
        if ($isLast) {
            $fetchBlock->addOpCode(new OpCode(
                OpCode::TYPE_EMPTY_OBJECT_PROPERTY,
                $resultSlot,
                $this->compileOperand($fetch->var, $fetchBlock, true),
                $this->compileOperand($fetch->name, $fetchBlock, true),
            ));
            $fetchJump = new OpCode(OpCode::TYPE_JUMP);
            $fetchJump->block1 = $endBlock;
            $fetchBlock->addOpCode($fetchJump);
        } else {
            $intermediateSlot = $this->compileOperand($fetch->result, $fetchBlock, false);
            $propFetch = new OpCode(
                OpCode::TYPE_PROPERTY_FETCH,
                $intermediateSlot,
                $this->compileOperand($fetch->var, $fetchBlock, true),
                $this->compileOperand($fetch->name, $fetchBlock, true)
            );
            $propFetch->nullsafeFetchPropertyRead = true;
            $fetchBlock->addOpCode($propFetch);
            $this->compileEmptyNullsafeChainLink($chain, $index + 1, $fetchBlock, $resultSlot, $endBlock);
        }

        $endBlock->parents[] = $nullBlock;
        $endBlock->parents[] = $fetchBlock;

        $nullsafeOp = new OpCode(
            OpCode::TYPE_NULLSAFE,
            $isLast ? $resultSlot : $this->compileOperand($fetch->result, $block, false),
            $receiverSlot
        );
        $nullsafeOp->block1 = $nullBlock;
        $nullsafeOp->block2 = $fetchBlock;
        $nullsafeOp->block3 = $endBlock;
        $block->addOpCode($nullsafeOp);
    }

    /**
     * @param list<Op> $deferredPreludeOps
     */
    protected function compileNullsafeMethodCall(
        Op\Expr\NullsafeMethodCall $expr,
        Block $block,
        array $deferredPreludeOps = []
    ): Block
    {
        $resultSlot = $this->compileOperand($expr->result, $block, false);
        $receiverSlot = $this->compileOperand($expr->var, $block, true);

        $endBlock = new Block($block->orig);
        $endBlock->inheritUndefinedLocals = true;
        $endBlock->inheritScopeFrom($block);

        $nullBlock = new Block($block->orig);
        $nullBlock->inheritUndefinedLocals = true;
        $nullBlock->inheritScopeFrom($block);
        $nullLiteral = new Operand\Literal(null);
        $nullLiteral->type = Type::null();
        $nullValueSlot = $this->compileOperand($nullLiteral, $nullBlock, true);
        $nullBlock->addOpCode(new OpCode(
            OpCode::TYPE_ASSIGN,
            $resultSlot,
            $resultSlot,
            $nullValueSlot
        ));
        $nullJump = new OpCode(OpCode::TYPE_JUMP);
        $nullJump->block1 = $endBlock;
        $nullBlock->addOpCode($nullJump);

        $fetchBlock = new Block($block->orig);
        $fetchBlock->inheritUndefinedLocals = true;
        $fetchBlock->inheritScopeFrom($block);
        if (!empty($deferredPreludeOps)) {
            $this->compileOps($deferredPreludeOps, $fetchBlock);
        }
        $fetchBlock->addOpCode(new OpCode(
            OpCode::TYPE_METHODCALL_INIT,
            $this->compileOperand($expr->var, $fetchBlock, true),
            $this->compileOperand($expr->name, $fetchBlock, true)
        ));
        foreach ($this->compileCallArgSends($expr->args, $fetchBlock) as $send) {
            $fetchBlock->addOpCode($send);
        }
        $fetchBlock->addOpCode($this->compileFuncCallExecOpcode(
            $expr->result,
            $fetchBlock,
            max(0, $expr->getLine())
        ));
        $fetchJump = new OpCode(OpCode::TYPE_JUMP);
        $fetchJump->block1 = $endBlock;
        $fetchBlock->addOpCode($fetchJump);
        $endBlock->parents[] = $nullBlock;
        $endBlock->parents[] = $fetchBlock;

        $nullsafeOp = new OpCode(
            OpCode::TYPE_NULLSAFE,
            $resultSlot,
            $receiverSlot
        );
        $nullsafeOp->block1 = $nullBlock;
        $nullsafeOp->block2 = $fetchBlock;
        $nullsafeOp->block3 = $endBlock;
        $block->addOpCode($nullsafeOp);

        return $endBlock;
    }

    /**
     * @return list<\PHPCfg\Operand>
     */
    private function nullsafePreludeOperandVars(Op\Expr $expr): array
    {
        // Minimal dependency extraction for nullsafe argument prelude sinking (#4394).
        // Extend carefully; keep conservative (only single-use temporaries are eligible).
        return match (get_class($expr)) {
            Op\Expr\FuncCall::class => array_merge([$expr->name], $expr->args),
            default => [],
        };
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

        $skipOp = new OpCode(
            OpCode::TYPE_JUMPIF_FUNCTION_STATIC_INITIALIZED,
            null,
            $keySlot
        );
        $skipOp->block1 = $continueBlock;

        if (null !== $terminal->defaultBlock) {
            $this->compileOps($terminal->defaultBlock->children, $block);
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

        return [[$skipOp, $storeOp, $jumpOp], $continueBlock];
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
     * @param Op\Terminal\StaticVar $terminal
     */
    protected function assertFunctionStaticRuntimeInitAllowed(Op\Terminal $terminal): void
    {
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
                $return->string($literal->value);
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
        $callIndex = null;
        foreach ($block->orig->children as $i => $child) {
            if ($child === $cfgCallOp) {
                $callIndex = $i;
                break;
            }
        }
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
                if (OpCode::TYPE_EMPTY === $op->type || OpCode::TYPE_EMPTY_OBJECT_PROPERTY === $op->type) {
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
            if (OpCode::TYPE_EMPTY === $op->type || OpCode::TYPE_EMPTY_OBJECT_PROPERTY === $op->type) {
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
            $this->compileOperand($fetch->class, $block, true),
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
            $dimFetch = null !== $propFetch ? null : $this->findCoalesceArrayDimFetch($var, $block);
            [$containerSlot, $dimSlot] = null !== $propFetch
                ? $this->resolveIssetTargetFromPropertyFetch($propFetch, $current)
                : (null !== $dimFetch
                    ? $this->resolveIssetTargetFromArrayDimFetch($dimFetch, $current)
                    : $this->resolveIssetTarget($var, $current));
            $checkSlot = $resultSlot;
            if ($i < $last) {
                $checkSlot = $this->compileBoolTemporary($current);
            }
            if (null === $containerSlot) {
                $varSlot = $this->compileOperand($var, $current, true);
                $current->addOpCode(new OpCode(OpCode::TYPE_ISSET, $checkSlot, $varSlot, null));
            } else {
                $current->addOpCode($this->makeIssetOpCode(
                    $checkSlot,
                    $containerSlot,
                    $dimSlot,
                    null !== $propFetch
                ));
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
     */
    private function rewriteMergeJumpsToFinally(Block $source, Block $merge, Block $finally): void
    {
        for ($i = 0; $i < $source->nOpCodes; ++$i) {
            $op = $source->opCodes[$i];
            if (OpCode::TYPE_JUMP === $op->type && $op->block1 === $merge) {
                $op->block1 = $finally;
            }
        }
    }

    /**
     * Try/catch merge blocks from php-cfg may include later sibling try/catch in the same end
     * block. JIT pre-lowers merge at beginTry via compileIncludedAtEntry; nested TYPE_TRY in
     * that merge corrupts LLVM EH basic blocks (#4041). Split so merge is prefix-only + JUMP.
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
        if (null === $splitAt || 0 === $splitAt) {
            return $merge;
        }
        $tailOps = \array_slice($merge->opCodes, $splitAt);
        $merge->opCodes = \array_slice($merge->opCodes, 0, $splitAt);
        $merge->nOpCodes = \count($merge->opCodes);
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
            $encoded[] = strtolower(ltrim($name, '\\'));
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
     * True when the fetch result is only used as a write lvalue (assign, unset, or ++/--; issue #103, #1224, #6798).
     * Nested write through a dimension ($obj[$k][] = $v) also requires write fetch on the outer dim (#3446).
     */
    protected function isArrayDimFetchForWrite(Op\Expr\ArrayDimFetch $fetch, Block $block): bool
    {
        foreach ($fetch->result->usages as $usage) {
            if ($usage instanceof Op\Expr\Assign && $usage->var === $fetch->result) {
                continue;
            }
            // AssignRef RHS needs FETCH_DIM_W for reference acquisition (#7441, zend_execute.c).
            if (
                $usage instanceof Op\Expr\AssignRef
                && ($usage->var === $fetch->result || $usage->expr === $fetch->result)
            ) {
                continue;
            }
            if ($usage instanceof Op\Terminal\Unset_ && $this->unsetTerminalUsesOperand($usage, $fetch->result)) {
                continue;
            }
            if ($this->isIncDecUsingOperand($usage, $fetch->result)) {
                continue;
            }
            if (
                $usage instanceof Op\Expr\ArrayDimFetch
                && $usage->var === $fetch->result
                && $this->isArrayDimFetchForWrite($usage, $block)
            ) {
                return true;
            }

            return false;
        }
        if (!empty($fetch->result->usages)) {
            return true;
        }
        // php-cfg often leaves operand->usages empty; fall back to the next stmt in this block.
        $children = $block->orig->children;
        foreach ($children as $i => $child) {
            if ($child !== $fetch) {
                continue;
            }
            if ($i + 1 >= count($children)) {
                break;
            }
            $next = $children[$i + 1];

            if ($next instanceof Op\Expr\Assign && $next->var === $fetch->result) {
                return true;
            }
            if (
                $next instanceof Op\Expr\AssignRef
                && ($next->var === $fetch->result || $next->expr === $fetch->result)
            ) {
                return true;
            }
            if ($next instanceof Op\Terminal\Unset_ && $this->unsetTerminalUsesOperand($next, $fetch->result)) {
                return true;
            }
            if ($this->isIncDecUsingOperand($next, $fetch->result)) {
                return true;
            }
            if (
                $next instanceof Op\Expr\ArrayDimFetch
                && $next->var === $fetch->result
                && $this->isArrayDimFetchForWrite($next, $block)
            ) {
                return true;
            }

            return false;
        }

        return false;
    }

    /**
     * @param Op\Node $usage
     */
    private function isIncDecUsingOperand($usage, Operand $operand): bool
    {
        if (
            !$usage instanceof Op\Expr\PostInc
            && !$usage instanceof Op\Expr\PreInc
            && !$usage instanceof Op\Expr\PostDec
            && !$usage instanceof Op\Expr\PreDec
        ) {
            return false;
        }
        $write = $usage->write ?? $usage->read;

        return $usage->read === $operand || $write === $operand;
    }

    /**
     * php-cfg Expr::result temporaries omit ->original; match list-destruct fetch by result (#3799).
     */
    protected function findArrayDimFetchForResult(Operand $result, Block $block): ?Op\Expr\ArrayDimFetch
    {
        foreach ($block->orig->children as $child) {
            if ($child instanceof Op\Expr\ArrayDimFetch && $child->result === $result) {
                return $child;
            }
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

    /**
     * php-cfg may lower inline Expr_Array / Expr_New results and call/ctor args to distinct
     * temporaries (`f(['a' => 1])`, `new C(['x'])`, `g(new C('x'))`) (#8561).
     *
     * @return ?string producer slot to pass to TYPE_ARG_SEND instead of the empty arg slot
     */
    /**
     * php-cfg hoists enum `case` ClassConstFetch before inline Expr_Array call args (#5721).
     */
    private function findInlineArrayProducerForCallArg(
        Operand $arg,
        Block $block,
        ?Op $cfgCallOp = null,
        ?int $knownArgIndex = null
    ): ?Op\Expr\Array_
    {
        if (null === $block->orig) {
            return null;
        }
        $callSite = $this->findCfgCallSiteForArg($block->orig->children, $arg, $cfgCallOp);
        if (null === $callSite && null !== $cfgCallOp && null !== $knownArgIndex) {
            $callSite = [$cfgCallOp, $knownArgIndex];
        }
        if (null === $callSite) {
            return null;
        }
        [$callOp, $argIndex] = $callSite;
        if (!property_exists($callOp, 'args') || !is_array($callOp->args)) {
            return null;
        }
        $callIndex = null;
        foreach ($block->orig->children as $i => $child) {
            if ($child === $callOp) {
                $callIndex = $i;
                break;
            }
        }
        if (null !== $callIndex) {
            for ($i = $callIndex - 1; $i >= 0; --$i) {
                $child = $block->orig->children[$i];
                if ($child instanceof Op\Expr\FuncCall || $child instanceof Op\Expr\NsFuncCall) {
                    break;
                }
                if ($child instanceof Op\Expr\Array_
                    && $this->operandsReferToSameVariable($child->result, $arg)
                ) {
                    return $child;
                }
            }
        }
        $producers = $this->precedingInlineCallArgProducersBeforeCfgOp($block->orig->children, $callOp);
        if (
            ($this->callIncludesNamedParameter($callOp) || null !== $this->callArgName($callOp->args[$argIndex] ?? $arg))
            && isset($callOp->args[$argIndex])
        ) {
            $namedCallArg = $callOp->args[$argIndex];
            foreach ($producers as $candidate) {
                if (
                    $candidate instanceof Op\Expr\Array_
                    && null !== $candidate->result
                    && $this->operandsReferToSameVariable($candidate->result, $namedCallArg)
                ) {
                    return $candidate;
                }
            }
            if ($this->callArgIsDeadInlineTemporary($namedCallArg)) {
                $unassigned = $this->findUnassignedInlineArrayProducerForDeadCallArg(
                    $producers,
                    $callOp,
                    $argIndex,
                    $block
                );
                if ($unassigned instanceof Op\Expr\Array_) {
                    return $unassigned;
                }
            }
        }
        $producer = $this->matchInlineCallArgProducer($producers, $callOp->args, $argIndex, $callOp, $block);
        if ($producer instanceof Op\Expr\Array_) {
            $producerIdx = array_search($producer, $producers, true);
            if (
                false !== $producerIdx
                && ($producers[$producerIdx + 1] ?? null) instanceof Op\Expr\New_
            ) {
                return null;
            }
            if (0 === $argIndex && !$this->callIncludesNamedParameter($callOp)) {
                $arrayProducers = array_values(array_filter(
                    $producers,
                    static fn (Op\Expr $p): bool => $p instanceof Op\Expr\Array_
                ));
                if (
                    \count($arrayProducers) >= 2
                    && $this->producersAreNestedArrayLiteralChain($arrayProducers)
                ) {
                    return $arrayProducers[\count($arrayProducers) - 1];
                }
            }

            return $producer;
        }

        return null;
    }

    /**
     * php-cfg dead call-arg temps for named parameters — map to hoisted Array_ not assigned to a named local (#11170).
     *
     * @param list<Op\Expr> $producers
     */
    private function findUnassignedInlineArrayProducerForDeadCallArg(
        array $producers,
        Op $callOp,
        int $argIndex,
        Block $block
    ): ?Op\Expr\Array_ {
        if (!property_exists($callOp, 'args') || !is_array($callOp->args)) {
            return null;
        }
        $deadArrayArgIndices = [];
        foreach ($callOp->args as $idx => $callArg) {
            if (!$this->callArgIsDeadInlineTemporary($callArg) || $this->isEmbeddedCallLiteralArg($callArg)) {
                continue;
            }
            if ($this->callArgOperandExpectsArrayProducer($callArg)) {
                $deadArrayArgIndices[] = (int) $idx;
            }
        }
        $positionAmongDeadArrays = array_search($argIndex, $deadArrayArgIndices, true);
        if (false === $positionAmongDeadArrays) {
            return null;
        }
        $unassigned = [];
        foreach ($producers as $producer) {
            if (!$producer instanceof Op\Expr\Array_) {
                continue;
            }
            if ($this->inlineProducerAssignedToNamedLocalBeforeCall($producer, $callOp, $block)) {
                continue;
            }
            $unassigned[] = $producer;
        }
        if ([] === $unassigned) {
            return null;
        }
        if (1 === \count($deadArrayArgIndices)) {
            return $unassigned[\count($unassigned) - 1];
        }

        return $unassigned[$positionAmongDeadArrays] ?? null;
    }

    /**
     * Named `command: [...]` style dead temps — last hoisted Array_ between call and prior Assign (#11170).
     */
    private function resolveNamedDeadTempArrayCallArgSlot(Op $callOp, Block $block): ?string
    {
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
        $lastArray = null;
        for ($i = $callIndex - 1; $i >= 0; --$i) {
            $child = $children[$i];
            if ($child instanceof Op\Expr\Assign || $child instanceof Op\Expr\AssignRef) {
                break;
            }
            if ($child instanceof Op\Expr\Array_) {
                $lastArray = $child;
            }
        }
        if (null === $lastArray) {
            return null;
        }
        if (null === $block->slotForOperand($lastArray->result)) {
            foreach ($this->compileExpr($lastArray, $block) as $op) {
                $block->addOpCode($op);
            }
        }
        $slot = $block->slotForOperand($lastArray->result);

        return null !== $slot ? (string) $slot : null;
    }

    private function inlineProducerAssignedToNamedLocalBeforeCall(
        Op\Expr $producer,
        Op $callOp,
        Block $block
    ): bool {
        if (null === $block->orig || null === $producer->result) {
            return false;
        }
        $children = $block->orig->children;
        $producerIndex = null;
        $callIndex = null;
        foreach ($children as $i => $child) {
            if ($child === $producer) {
                $producerIndex = $i;
            }
            if ($child === $callOp) {
                $callIndex = $i;
            }
        }
        if (null === $producerIndex || null === $callIndex || $producerIndex >= $callIndex) {
            return false;
        }
        for ($i = $producerIndex + 1; $i < $callIndex; ++$i) {
            $stmt = $children[$i];
            if (
                $stmt instanceof Op\Expr\Assign
                && $this->operandsReferToSameVariable($stmt->expr, $producer->result)
                && $this->isNamedVariableOperand($stmt->var)
            ) {
                return true;
            }
        }

        return false;
    }

    /** Dead php-cfg call-arg temp whose inferred type is array-shaped (incl. `string[]`, #11170). */
    private function callArgOperandExpectsArrayProducer(Operand $callArg): bool
    {
        $root = $this->unwrapOperandChain($callArg);
        if (null === $root->type || !method_exists($root->type, 'toString')) {
            return false;
        }
        $repr = $root->type->toString();
        if ('array' === $repr) {
            return true;
        }

        return str_ends_with($repr, '[]');
    }

    /**
     * Stmt-level ?? must not supply slots for literal / hoisted scalar call args (#9225, #10380).
     */
    private function isCallArgUnrelatedToPriorStmtCoalesce(Operand $callArg): bool
    {
        if ($callArg instanceof Operand\Literal || $this->isEmbeddedCallLiteralArg($callArg)) {
            return true;
        }
        // php-cfg clones stmt-level ?? call-arg temps from inner ConstFetch/null operands (#11801).
        $cfgClone = $callArg instanceof Operand\Temporary;
        $root = $this->unwrapOperandChain($callArg);
        if ($root instanceof Op\Expr\ConstFetch) {
            $name = $this->staticNameFromOperand($root->name);
            if (null !== $name) {
                $lookup = strtolower($name);
                if (\in_array($lookup, ['true', 'false'], true)) {
                    return true;
                }
                if ('null' === $lookup) {
                    return !$cfgClone;
                }
                if (!$cfgClone && isset(\PHPCompiler\ext\standard\StdlibConstants::CORE_INT_BY_NAME[$lookup])) {
                    return true;
                }
            }
        }
        $vm = $this->vmVariableFromCfgLiteralOperand($callArg);
        if (null !== $vm && \in_array($vm->type, [
            Variable::TYPE_BOOLEAN,
            Variable::TYPE_INTEGER,
            Variable::TYPE_NULL,
        ], true)) {
            return !$cfgClone;
        }

        return false;
    }

    /** header() replace/response_code must not reuse stmt-level ?? slots (#1887, 005-SessionsWeb). */
    private function headerScalarCallArgMustUseDirectOperand(?string $calleeName, int $argIndex): bool
    {
        if (null === $calleeName || $argIndex < 1) {
            return false;
        }

        return 'header' === strtolower(ltrim($calleeName, '\\'));
    }

    /**
     * @return ?Op\Expr\BinaryOp\Coalesce
     */
    private function findCoalesceStmtForCallArg(Operand $arg, Block $block): ?Op\Expr\BinaryOp\Coalesce
    {
        foreach ($this->findEmbeddedCoalesces($arg) as $coalesce) {
            return $coalesce;
        }
        if (null === $block->orig) {
            return null;
        }
        foreach ($block->orig->children as $child) {
            if (
                $child instanceof Op\Expr\BinaryOp\Coalesce
                && ($child->result === $arg || $this->operandsReferToSameVariable($child->result, $arg))
            ) {
                if (!$this->isCallArgUnrelatedToPriorStmtCoalesce($arg)) {
                    return $child;
                }
            }
        }
        // php-cfg clones call-arg temps from stmt Coalesce result (#8766, #8902).
        foreach ($block->orig->children as $i => $child) {
            if (
                !($child instanceof Op\Expr\FuncCall || $child instanceof Op\Expr\NsFuncCall)
                || !property_exists($child, 'args')
                || !is_array($child->args)
            ) {
                continue;
            }
            $argMatches = false;
            $matchedCallArg = null;
            foreach ($child->args as $callArg) {
                if ($callArg === $arg || $this->operandsReferToSameVariable($callArg, $arg)) {
                    $argMatches = true;
                    $matchedCallArg = $callArg;
                    $root = $this->unwrapOperandChain($callArg);
                    if ($root instanceof Op\Expr\BinaryOp\Coalesce) {
                        return $root;
                    }
                    break;
                }
            }
            if (!$argMatches) {
                continue;
            }
            // Literal / unrelated call args must not pick up a prior stmt-level ?? (#9225, 009-FastCGIWeb).
            if (null !== $matchedCallArg && $this->isCallArgUnrelatedToPriorStmtCoalesce($matchedCallArg)) {
                continue;
            }
            for ($j = $i - 1; $j >= 0; --$j) {
                $prev = $block->orig->children[$j];
                if ($prev instanceof Op\Expr\BinaryOp\Coalesce) {
                    // php-cfg clones call-arg temps from stmt Coalesce.result (#8766, #8902, #9479).
                    if ($j === $i - 1) {
                        return $prev;
                    }
                    if (
                        null !== $matchedCallArg
                        && (
                            $prev->result === $matchedCallArg
                            || $this->operandsReferToSameVariable($prev->result, $matchedCallArg)
                            || $this->operandsReferToSameVariable($prev->left, $matchedCallArg)
                        )
                    ) {
                        return $prev;
                    }
                    // php-cfg may lower later call-arg producers (e.g. var_export(..., true)) between ?? and FuncCall (#11601).
                    if ($this->onlyInlineCallArgProducersBetweenIndices($block->orig->children, $j, $i)) {
                        return $prev;
                    }
                    break;
                }
                if (
                    $prev instanceof Op\Expr\Assign
                    && $j > 0
                ) {
                    $maybeCoalesce = $block->orig->children[$j - 1];
                    if (
                        $maybeCoalesce instanceof Op\Expr\BinaryOp\Coalesce
                        && $this->isCoalesceAssignTail($prev, $maybeCoalesce)
                    ) {
                        // ??= expression value before call — php-cfg inserts Assign between Coalesce and FuncCall (#5337, #10898).
                        return $maybeCoalesce;
                    }
                }
                if ($prev instanceof Op\Expr\FuncCall || $prev instanceof Op\Expr\NsFuncCall) {
                    break;
                }
                if (!$prev instanceof Op\Expr || !$this->isInlineExprCallArgProducer($prev)) {
                    break;
                }
            }
        }

        return null;
    }

    /**
     * Nullsafe lowering splits CFG blocks; result slot lives on TYPE_NULLSAFE (#9732, #9171).
     *
     * @param Op\Expr\NullsafePropertyFetch|Op\Expr\NullsafeMethodCall $nullsafe
     */
    private function slotForNullsafeResult(Block $block, Op\Expr $nullsafe): ?int
    {
        $slot = $block->slotForOperand($nullsafe->result);
        if (null !== $slot) {
            return $slot;
        }
        $seen = [];
        $queue = [$block];
        while ([] !== $queue) {
            $current = array_shift($queue);
            $id = spl_object_id($current);
            if (isset($seen[$id])) {
                continue;
            }
            $seen[$id] = true;
            foreach ($current->opCodes as $op) {
                if (OpCode::TYPE_NULLSAFE === $op->type) {
                    return $op->arg1;
                }
            }
            foreach ($current->parents as $parent) {
                $queue[] = $parent;
            }
        }

        return null;
    }

    private function slotForCoalesceResult(Block $block, Op\Expr\BinaryOp\Coalesce $coalesce): ?int
    {
        $coalesceId = spl_object_id($coalesce);
        if (isset($this->coalesceResultSlots[$coalesceId])) {
            return $this->coalesceResultSlots[$coalesceId];
        }
        $slot = $block->slotForOperand($coalesce->result);
        if (null !== $slot) {
            return $slot;
        }
        $seen = [];
        $queue = [$block];
        while ([] !== $queue) {
            $current = array_shift($queue);
            $id = spl_object_id($current);
            if (isset($seen[$id])) {
                continue;
            }
            $seen[$id] = true;
            foreach ($current->opCodes as $op) {
                if (OpCode::TYPE_COALESCE === $op->type) {
                    return $op->arg1;
                }
            }
            foreach ($current->parents as $parent) {
                $queue[] = $parent;
            }
        }

        return null;
    }

    private function compileCallArgCoalesceSlot(
        Operand $arg,
        Block $block,
        ?Op $cfgCallOp = null,
        ?int $argIndex = null
    ): ?int {
        if ($this->isCallArgUnrelatedToPriorStmtCoalesce($arg)) {
            return null;
        }
        if (
            null !== $cfgCallOp
            && null !== $argIndex
            && $this->headerScalarCallArgMustUseDirectOperand(
                $this->funcCallExprCalleeName($cfgCallOp),
                $argIndex
            )
        ) {
            return null;
        }
        if (
            null !== $cfgCallOp
            && null !== $argIndex
            && is_array($cfgCallOp->args ?? null)
            && isset($cfgCallOp->args[$argIndex])
            && $this->isCallArgUnrelatedToPriorStmtCoalesce($cfgCallOp->args[$argIndex])
        ) {
            return null;
        }
        $coalesce = $this->findCoalesceStmtForCallArg($arg, $block);
        if (null === $coalesce) {
            return null;
        }
        $coalesceSlot = $this->slotForCoalesceResult($block, $coalesce);
        if (null === $coalesceSlot) {
            $this->compileCoalesce($coalesce, $block);
            $coalesceSlot = $this->slotForCoalesceResult($block, $coalesce);
        }

        return $coalesceSlot;
    }

    private function resolvePropertyFetchCoalesceCallArgSlot(
        Op\Expr\PropertyFetch $producer,
        Op $callOp,
        Operand $arg,
        Block $block,
        ?int $argIndex = null
    ): ?int {
        if (!property_exists($callOp, 'args') || !is_array($callOp->args)) {
            return null;
        }
        foreach ($callOp->args as $callArg) {
            foreach ($this->findEmbeddedCoalesces($callArg) as $coalesce) {
                if ($this->findCoalescePropertyFetch($coalesce->left, $block) !== $producer) {
                    continue;
                }
                $coalesceSlot = $this->slotForCoalesceResult($block, $coalesce);
                if (null === $coalesceSlot) {
                    $this->compileCoalesce($coalesce, $block);
                    $coalesceSlot = $this->slotForCoalesceResult($block, $coalesce);
                }
                if (null !== $coalesceSlot) {
                    return $coalesceSlot;
                }
            }
            $root = $this->unwrapOperandChain($callArg);
            if (
                $root instanceof Op\Expr\BinaryOp\Coalesce
                && $this->findCoalescePropertyFetch($root->left, $block) === $producer
            ) {
                $coalesceSlot = $this->slotForCoalesceResult($block, $root);
                if (null === $coalesceSlot) {
                    $this->compileCoalesce($root, $block);
                    $coalesceSlot = $this->slotForCoalesceResult($block, $root);
                }
                if (null !== $coalesceSlot) {
                    return $coalesceSlot;
                }
            }
        }
        $coalesceStmt = $this->findCoalesceStmtForCallArg($arg, $block);
        if (
            null !== $coalesceStmt
            && $this->findCoalescePropertyFetch($coalesceStmt->left, $block) === $producer
        ) {
            return $this->compileCallArgCoalesceSlot($arg, $block, $callOp, $argIndex);
        }

        return null;
    }

    private function resolveArrayDimFetchCoalesceCallArgSlot(
        Op\Expr\ArrayDimFetch $producer,
        Op $callOp,
        Operand $arg,
        Block $block,
        ?int $argIndex = null
    ): ?int {
        if (!property_exists($callOp, 'args') || !is_array($callOp->args)) {
            return null;
        }
        foreach ($callOp->args as $callArg) {
            foreach ($this->findEmbeddedCoalesces($callArg) as $coalesce) {
                if ($this->findCoalesceArrayDimFetch($coalesce->left, $block) !== $producer) {
                    continue;
                }
                $coalesceSlot = $this->slotForCoalesceResult($block, $coalesce);
                if (null === $coalesceSlot) {
                    $this->compileCoalesce($coalesce, $block);
                    $coalesceSlot = $this->slotForCoalesceResult($block, $coalesce);
                }
                if (null !== $coalesceSlot) {
                    return $coalesceSlot;
                }
            }
            $root = $this->unwrapOperandChain($callArg);
            if (
                $root instanceof Op\Expr\BinaryOp\Coalesce
                && $this->findCoalesceArrayDimFetch($root->left, $block) === $producer
            ) {
                $coalesceSlot = $this->slotForCoalesceResult($block, $root);
                if (null === $coalesceSlot) {
                    $this->compileCoalesce($root, $block);
                    $coalesceSlot = $this->slotForCoalesceResult($block, $root);
                }
                if (null !== $coalesceSlot) {
                    return $coalesceSlot;
                }
            }
        }
        $coalesceStmt = $this->findCoalesceStmtForCallArg($arg, $block);
        if (
            null !== $coalesceStmt
            && $this->findCoalesceArrayDimFetch($coalesceStmt->left, $block) === $producer
        ) {
            return $this->compileCallArgCoalesceSlot($arg, $block, $callOp, $argIndex);
        }

        return null;
    }

    /**
     * @param list<Operand> $args
     */
    private function lowerEmbeddedCoalesceCallArgs(array $args, Block $block): void
    {
        foreach ($args as $arg) {
            foreach ($this->findEmbeddedCoalesces($arg) as $coalesce) {
                if (null === $this->slotForCoalesceResult($block, $coalesce)) {
                    $this->compileCoalesce($coalesce, $block);
                }
            }
            $stmtCoalesce = $this->findCoalesceStmtForCallArg($arg, $block);
            if (
                null !== $stmtCoalesce
                && null === $this->slotForCoalesceResult($block, $stmtCoalesce)
            ) {
                $this->compileCoalesce($stmtCoalesce, $block);
            }
        }
    }

    private function findInlineExprCallArgProducerSlot(Operand $arg, Block $block, ?Op $cfgCallOp = null): ?string
    {
        if (null === $block->orig) {
            return null;
        }
        $callSite = $this->findCfgCallSiteForArg($block->orig->children, $arg, $cfgCallOp);
        if (null === $callSite) {
            return null;
        }
        [$callOp, $argIndex] = $callSite;
        if (!property_exists($callOp, 'args') || !is_array($callOp->args)) {
            return null;
        }
        if ($this->headerScalarCallArgMustUseDirectOperand($this->funcCallExprCalleeName($callOp), $argIndex)) {
            return null;
        }
        // Statement-level side-effect calls before f($local) are not inline arg producers (#11093, #11375).
        $namedLocalSlot = $this->namedLocalCallArgSlotIfBound($arg, $block, $callOp, $argIndex);
        if (null !== $namedLocalSlot) {
            return $namedLocalSlot;
        }
        // php-cfg may lower a boolean-producing inline Expr (e.g. `===`) to a distinct arg temp with
        // no dataflow edge, leaving the arg slot empty. Prefer the immediately preceding binary op
        // producer when its inferred type matches the arg (#9030).
        $callIndex = null;
        foreach ($block->orig->children as $i => $child) {
            if ($child === $callOp) {
                $callIndex = $i;
                break;
            }
        }
        if (null !== $callIndex && $callIndex > 0) {
            $producers = $this->precedingInlineCallArgProducersBeforeCfgOp($block->orig->children, $callOp);
            $arrayProducerCount = 0;
            foreach ($producers as $producer) {
                if ($producer instanceof Op\Expr\Array_) {
                    ++$arrayProducerCount;
                }
            }
            $funcCallProducerCount = 0;
            foreach ($producers as $producer) {
                if ($producer instanceof Op\Expr\FuncCall || $producer instanceof Op\Expr\NsFuncCall) {
                    ++$funcCallProducerCount;
                }
            }
            if ($arrayProducerCount >= 2 || $funcCallProducerCount >= 2) {
                $matched = null;
                if (
                    $this->callIncludesNamedParameter($callOp)
                    && isset($callOp->args[$argIndex])
                    && $this->callArgIsDeadInlineTemporary($callOp->args[$argIndex])
                ) {
                    $matched = $this->findUnassignedInlineArrayProducerForDeadCallArg(
                        $producers,
                        $callOp,
                        $argIndex,
                        $block
                    );
                }
                if (!$matched instanceof Op\Expr) {
                    $matched = $this->matchInlineCallArgProducer($producers, $callOp->args, $argIndex, $callOp, $block);
                }
                if ($matched instanceof Op\Expr) {
                    if (null === $block->slotForOperand($matched->result)) {
                        foreach ($this->compileExpr($matched, $block) as $op) {
                            $block->addOpCode($op);
                        }
                    }
                    $slot = $block->slotForOperand($matched->result);
                    if (null !== $slot) {
                        return (string) $slot;
                    }
                }
            }
            if (1 === $funcCallProducerCount && 0 === $arrayProducerCount) {
                $matched = $this->matchInlineCallArgProducer($producers, $callOp->args, $argIndex, $callOp, $block);
                if ($matched instanceof Op\Expr\FuncCall || $matched instanceof Op\Expr\NsFuncCall) {
                    $producerIndex = null;
                    foreach ($block->orig->children as $i => $child) {
                        if ($child === $matched) {
                            $producerIndex = $i;
                            break;
                        }
                    }
                    if (
                        null !== $producerIndex
                        && null !== $callIndex
                        && $this->isAdjacentNestedFuncCallProducer($matched, $callOp, $producerIndex, $callIndex)
                    ) {
                        $slot = $block->slotForOperand($matched->result);
                        if (null === $slot) {
                            foreach ($this->compileExpr($matched, $block) as $op) {
                                $block->addOpCode($op);
                            }
                            $slot = $block->slotForOperand($matched->result);
                        }
                        if (null !== $slot) {
                            return (string) $slot;
                        }
                    }
                }
            }
            if ($this->callIncludesNamedParameter($callOp) && [] !== $producers) {
                $matched = $this->matchInlineCallArgProducer($producers, $callOp->args, $argIndex, $callOp, $block);
                if ($matched instanceof Op\Expr) {
                    if (null === $block->slotForOperand($matched->result)) {
                        foreach ($this->compileExpr($matched, $block) as $op) {
                            $block->addOpCode($op);
                        }
                    }
                    $slot = $block->slotForOperand($matched->result);
                    if (null !== $slot) {
                        return (string) $slot;
                    }
                }
            }
            $prev = $block->orig->children[$callIndex - 1] ?? null;
            if ($prev instanceof Op\Expr\ConstFetch) {
                $name = $this->staticNameFromOperand($prev->name);
                if (null !== $name) {
                    $lookup = strtolower($name);
                    $isHoistedScalar = \in_array($lookup, ['true', 'false', 'null'], true)
                        || isset(\PHPCompiler\ext\standard\StdlibConstants::CORE_INT_BY_NAME[$lookup])
                        || null !== \PHPCompiler\VM\Context::errorReportingConstant($name);
                    if ($isHoistedScalar) {
                        $callArg = $callOp->args[$argIndex] ?? null;
                        $callArgs = $callOp->args;
                        $isLastArg = \is_array($callArgs) && $argIndex === \count($callArgs) - 1;
                        if (null !== $callArg && $this->operandsReferToSameVariable($prev->result, $callArg)) {
                            $slot = $block->slotForOperand($prev->result);
                            if (null !== $slot) {
                                return (string) $slot;
                            }
                            $vm = $this->tryFoldGlobalConstFetch($prev);
                            if (null !== $vm) {
                                return (string) $block->registerConstant($arg, $vm);
                            }
                        }
                        // Hoisted true/false/null only feeds the trailing call arg (#9140, #9660).
                        if (
                            null !== $callArg
                            && $isLastArg
                            && !$this->operandsReferToSameVariable($prev->result, $callArg)
                            && \in_array($lookup, ['true', 'false', 'null'], true)
                        ) {
                            $slot = $block->slotForOperand($prev->result);
                            if (null !== $slot) {
                                return (string) $slot;
                            }
                            $vm = $this->tryFoldGlobalConstFetch($prev);
                            if (null !== $vm) {
                                return (string) $block->registerConstant($arg, $vm);
                            }
                        }
                        // Hoisted SORT_* / PHP_* / E_USER_* int constants (incl. zero-valued SORT_REGULAR) (#9462, #9548, #11526).
                        if (
                            null !== $callArg
                            && $isLastArg
                            && (
                                isset(\PHPCompiler\ext\standard\StdlibConstants::CORE_INT_BY_NAME[$lookup])
                                || null !== \PHPCompiler\VM\Context::errorReportingConstant($name)
                            )
                        ) {
                            $slot = $block->slotForOperand($prev->result);
                            if (null !== $slot) {
                                return (string) $slot;
                            }
                            $vm = $this->tryFoldGlobalConstFetch($prev);
                            if (null !== $vm) {
                                return (string) $block->registerConstant($arg, $vm);
                            }
                        }
                    }
                }
            }
            $argRoot = $this->unwrapOperandChain($arg);
            if (($prev instanceof Op\Expr\BinaryOp || $prev instanceof Op\Expr\InstanceOf_ || $prev instanceof Op\Expr\In_)
                && null !== $prev->result
                && (
                    $prev instanceof Op\Expr\In_
                    || (
                        null !== $argRoot->type
                        && null !== $prev->result->type
                        && $argRoot->type->type === $prev->result->type->type
                        && in_array(
                            $argRoot->type->type,
                            [Type::TYPE_BOOLEAN, Type::TYPE_LONG, Type::TYPE_ARRAY],
                            true
                        )
                    )
                )
            ) {
                if (null === $block->slotForOperand($prev->result)) {
                    foreach ($this->compileExpr($prev, $block) as $op) {
                        $block->addOpCode($op);
                    }
                }
                $slot = $block->slotForOperand($prev->result);
                if (null !== $slot) {
                    return $slot;
                }
            }
            if ($prev instanceof Op\Expr\Assign && null !== $prev->result) {
                $callArg = $callOp->args[$argIndex] ?? null;
                if (
                    null !== $callArg
                    && null !== $prev->var
                    && $this->operandsReferToSameVariable($prev->var, $callArg)
                ) {
                    $slot = $block->slotForOperand($prev->result);
                    if (null !== $slot) {
                        return $slot;
                    }
                }
            }
        }
        $coalesceArg = $this->findCoalesceStmtForCallArg($arg, $block);
        if (null !== $coalesceArg) {
            $coalesceSlot = $this->compileCallArgCoalesceSlot($arg, $block, $callOp, $argIndex);
            if (null !== $coalesceSlot) {
                return $coalesceSlot;
            }
        }
        $producers = $this->precedingInlineCallArgProducersBeforeCfgOp($block->orig->children, $callOp);
        $producer = $this->matchInlineCallArgProducer($producers, $callOp->args, $argIndex, $callOp, $block);
        if (null === $producer) {
            $adjacentSlot = $this->resolveAdjacentNestedFuncCallArgSlot($block, $callOp, $argIndex);
            if (null !== $adjacentSlot) {
                return $adjacentSlot;
            }
            $classConstSlot = $this->slotForHoistedClassConstFetchCallArg($arg, $block, $callOp, $argIndex);
            if (null !== $classConstSlot) {
                return $classConstSlot;
            }
            $logicalPhi = $this->logicalShortCircuitPhiMergeSlot($block);
            if (
                null !== $logicalPhi
                && null !== $cfgCallOp
                && $this->callArgIsDeadInlineTemporary($callOp->args[$argIndex] ?? null)
                && \in_array(strtolower($this->resolveCfgFuncCallName($cfgCallOp) ?? ''), ['exit', 'die'], true)
            ) {
                return (string) $logicalPhi;
            }
            $exitPhi = $this->resolveExitLogicalShortCircuitCallArgSlot($block);
            if (
                null !== $exitPhi
                && null !== $cfgCallOp
                && $this->callArgIsDeadInlineTemporary($callOp->args[$argIndex] ?? null)
                && \in_array(strtolower($this->resolveCfgFuncCallName($cfgCallOp) ?? ''), ['exit', 'die'], true)
            ) {
                return $exitPhi;
            }

            return $this->slotForMatchResultDeadCallArg($arg, $block, $cfgCallOp);
        }
        if ($producer instanceof Op\Expr\PropertyFetch) {
            $coalesceSlot = $this->resolvePropertyFetchCoalesceCallArgSlot(
                $producer,
                $callOp,
                $arg,
                $block,
                $argIndex
            );
            if (null !== $coalesceSlot) {
                return (string) $coalesceSlot;
            }
        }
        if ($producer instanceof Op\Expr\ArrayDimFetch) {
            $coalesceSlot = $this->resolveArrayDimFetchCoalesceCallArgSlot(
                $producer,
                $callOp,
                $arg,
                $block,
                $argIndex
            );
            if (null !== $coalesceSlot) {
                return (string) $coalesceSlot;
            }
        }
        $producerSlot = $block->slotForOperand($producer->result);
        if (
            null === $producerSlot
            && $producer instanceof Op\Expr\Empty_
            && !$this->emptyExprLoweringEmitted($block, $producer)
        ) {
            foreach ($this->compileExpr($producer, $block) as $op) {
                $block->addOpCode($op);
            }
            $producerSlot = $block->slotForOperand($producer->result);
        }
        if (
            null === $producerSlot
            && $producer instanceof Op\Expr\Isset_
            && !$this->issetExprLoweringEmitted($block, $producer)
        ) {
            foreach ($this->compileExpr($producer, $block) as $op) {
                $block->addOpCode($op);
            }
            $producerSlot = $block->slotForOperand($producer->result);
        }
        if (null === $producerSlot && $producer instanceof Op\Expr\ConstFetch) {
            foreach ($this->compileExpr($producer, $block) as $op) {
                $block->addOpCode($op);
            }
            $producerSlot = $block->slotForOperand($producer->result);
        }
        if (null === $producerSlot && $producer instanceof Op\Expr\Cast) {
            foreach ($this->compileExpr($producer, $block) as $op) {
                $block->addOpCode($op);
            }
            $producerSlot = $block->slotForOperand($producer->result);
        }
        if (null === $producerSlot && $producer instanceof Op\Expr\Eval_) {
            foreach ($this->compileExpr($producer, $block) as $op) {
                $block->addOpCode($op);
            }
            $producerSlot = $block->slotForOperand($producer->result);
        }
        if (null === $producerSlot && $producer instanceof Op\Expr\New_) {
            foreach ($this->compileExpr($producer, $block) as $op) {
                $block->addOpCode($op);
            }
            $producerSlot = $this->slotForInlineNewProducer($block, $producer);
        }
        if (null === $producerSlot && $producer instanceof Op\Expr\MagicScriptConst) {
            foreach ($this->compileExpr($producer, $block) as $op) {
                $block->addOpCode($op);
            }
            $producerSlot = $block->slotForOperand($producer->result);
        }
        if (
            null === $producerSlot
            && ($producer instanceof Op\Expr\NullsafePropertyFetch
                || $producer instanceof Op\Expr\NullsafeMethodCall)
        ) {
            $producerSlot = $this->slotForNullsafeResult($block, $producer);
        }
        if (
            null === $producerSlot
            && ($producer instanceof Op\Expr\NullsafePropertyFetch
                || $producer instanceof Op\Expr\NullsafeMethodCall)
        ) {
            foreach ($this->compileExpr($producer, $block) as $op) {
                $block->addOpCode($op);
            }
            $producerSlot = $this->slotForNullsafeResult($block, $producer);
        }
        if (
            null === $producerSlot
            && ($producer instanceof Op\Expr\FuncCall
                || $producer instanceof Op\Expr\NsFuncCall
                || $producer instanceof Op\Expr\StaticCall
                || $producer instanceof Op\Expr\MethodCall)
        ) {
            $callIndex = null;
            $producerIndex = null;
            foreach ($block->orig->children as $i => $child) {
                if ($child === $callOp) {
                    $callIndex = $i;
                }
                if ($child === $producer) {
                    $producerIndex = $i;
                }
            }
            if (
                null !== $callIndex
                && null !== $producerIndex
                && (
                    $this->isNestedCallArgProducerForConsumer(
                        $producer,
                        $callOp,
                        $producerIndex,
                        $callIndex,
                        $block->orig->children
                    )
                    || $this->isSiblingMultiArgFuncCallProducer(
                        $producer,
                        $callOp,
                        $producerIndex,
                        $callIndex,
                        $block->orig->children
                    )
                )
            ) {
                foreach ($this->compileExpr($producer, $block) as $op) {
                    $block->addOpCode($op);
                }
                $producerSlot = $block->slotForOperand($producer->result);
            }
        }
        if (null === $producerSlot) {
            if ($producer instanceof Op\Expr\Closure || $producer instanceof Op\Expr\ArrowFunction) {
                $producerSlot = $this->slotForInlineClosureProducer($producer, $block);
            } elseif ($producer instanceof Op\Expr\FirstClassCallable) {
                $producerSlot = $this->slotForInlineFirstClassCallableProducer($producer, $block);
            }
            if (null === $producerSlot) {
                return null;
            }
        }
        if (
            ($producer instanceof Op\Expr\FuncCall || $producer instanceof Op\Expr\NsFuncCall)
            && !$this->namedCallArgMayUseFuncCallProducerResult($producer, $arg)
        ) {
            return null;
        }
        if ($producer instanceof Op\Expr\Empty_) {
            return $producerSlot;
        }
        if ($producer instanceof Op\Expr\Isset_) {
            return $producerSlot;
        }
        if ($producer instanceof Op\Expr\ArrowFunction || $producer instanceof Op\Expr\Closure) {
            if (null === $producerSlot) {
                foreach ($this->compileExpr($producer, $block) as $op) {
                    $block->addOpCode($op);
                }
                $producerSlot = $block->slotForOperand($producer->result);
            }
            if (null !== $producerSlot) {
                return $producerSlot;
            }
        }
        if ($producer instanceof Op\Expr\FirstClassCallable) {
            $producerSlot = $this->slotForInlineFirstClassCallableProducer($producer, $block);
            if (null !== $producerSlot) {
                return $producerSlot;
            }
        }
        // php-cfg uses distinct result/arg temps for hoisted inline producers (#8766, #8561, #9136).
        if (
            $producer instanceof Op\Expr\Assign
            || $producer instanceof Op\Expr\BinaryOp
            || $producer instanceof Op\Expr\ConstFetch
            || $producer instanceof Op\Expr\ClassConstFetch
            || $producer instanceof Op\Expr\InstanceOf_
            || $producer instanceof Op\Expr\Cast
            || $producer instanceof Op\Expr\MagicScriptConst
            || $producer instanceof Op\Expr\FirstClassCallable
            || $producer instanceof Op\Expr\New_
            || $producer instanceof Op\Expr\UnaryMinus
            || $producer instanceof Op\Expr\UnaryPlus
            || $producer instanceof Op\Expr\BitwiseNot
            || $producer instanceof Op\Expr\BooleanNot
            || $producer instanceof Op\Expr\PostInc
            || $producer instanceof Op\Expr\PreInc
            || $producer instanceof Op\Expr\PostDec
            || $producer instanceof Op\Expr\PreDec
        ) {
            return $producerSlot;
        }
        $argSlot = $this->compileOperand($arg, $block, false);
        if (null === $argSlot) {
            return $producerSlot;
        }
        if ($producerSlot === $argSlot) {
            return $producerSlot;
        }
        if ($this->operandsReferToSameVariable($producer->result, $arg)) {
            if ($this->funcCallExprByRefArgMatchesOperand($producer, $arg)) {
                return null;
            }

            return $producerSlot;
        }
        // php-cfg uses distinct result/arg temps for `$f($a[0])` (#8814, zend_compile.c).
        if ($producer instanceof Op\Expr\ArrayDimFetch) {
            $callIndex = null;
            $producerIndex = null;
            foreach ($block->orig->children as $i => $child) {
                if ($child === $callOp) {
                    $callIndex = $i;
                }
                if ($child === $producer) {
                    $producerIndex = $i;
                }
            }
            if (null !== $callIndex && null !== $producerIndex && $producerIndex === $callIndex - 1) {
                return $producerSlot;
            }
        }
        if (
            $producer instanceof Op\Expr\PropertyFetch
            || $producer instanceof Op\Expr\StaticPropertyFetch
            || $producer instanceof Op\Expr\NullsafePropertyFetch
            || $producer instanceof Op\Expr\NullsafeMethodCall
        ) {
            $callIndex = null;
            $producerIndex = null;
            foreach ($block->orig->children as $i => $child) {
                if ($child === $callOp) {
                    $callIndex = $i;
                }
                if ($child === $producer) {
                    $producerIndex = $i;
                }
            }
            if (null !== $callIndex && null !== $producerIndex && $producerIndex === $callIndex - 1) {
                return $producerSlot;
            }

            return $producerSlot;
        }
        // php-cfg `var_dump(f(), g())` / `var_dump($o->f(), $o->g())` — sibling call producers
        // with distinct result/arg temps (#9351, zend_compile.c call-arg evaluation order).
        if (
            null !== $producerSlot
            && ($producer instanceof Op\Expr\FuncCall
                || $producer instanceof Op\Expr\NsFuncCall
                || $producer instanceof Op\Expr\MethodCall
                || $producer instanceof Op\Expr\StaticCall)
        ) {
            $callIndex = null;
            $producerIndex = null;
            foreach ($block->orig->children as $i => $child) {
                if ($child === $callOp) {
                    $callIndex = $i;
                }
                if ($child === $producer) {
                    $producerIndex = $i;
                }
            }
            if (
                null !== $callIndex
                && null !== $producerIndex
                && $producerIndex < $callIndex
                && !$this->isNestedCallArgProducerForConsumer(
                    $producer,
                    $callOp,
                    $producerIndex,
                    $callIndex,
                    $block->orig->children
                )
                && !$this->isSiblingMultiArgFuncCallProducer(
                    $producer,
                    $callOp,
                    $producerIndex,
                    $callIndex,
                    $block->orig->children
                )
            ) {
                return $producerSlot;
            }
        }
        // php-cfg `f(g())` uses distinct result/arg temporaries (#8561, #7075).
        if (
            $producer instanceof Op\Expr\FuncCall
            || $producer instanceof Op\Expr\NsFuncCall
            || $producer instanceof Op\Expr\StaticCall
            || $producer instanceof Op\Expr\MethodCall
        ) {
            $callIndex = null;
            $producerIndex = null;
            foreach ($block->orig->children as $i => $child) {
                if ($child === $callOp) {
                    $callIndex = $i;
                }
                if ($child === $producer) {
                    $producerIndex = $i;
                }
            }
            if (
                null !== $callIndex
                && null !== $producerIndex
                && $this->isNestedCallArgProducerForConsumer(
                    $producer,
                    $callOp,
                    $producerIndex,
                    $callIndex,
                    $block->orig->children
                )
            ) {
                return $producerSlot;
            }
            if (
                null !== $callIndex
                && null !== $producerIndex
                && $this->isSiblingMultiArgFuncCallProducer(
                    $producer,
                    $callOp,
                    $producerIndex,
                    $callIndex,
                    $block->orig->children
                )
                && $this->siblingMultiArgFuncCallProducerTargetArgIndex(
                    $producerIndex,
                    $callIndex,
                    $block->orig->children
                ) === $argIndex
            ) {
                return $producerSlot;
            }
            if (
                ($producer instanceof Op\Expr\FuncCall || $producer instanceof Op\Expr\NsFuncCall)
                && !$this->inlineCallArgProducerFeedsConsumer($producer, $callOp)
            ) {
                if (
                    null === $callIndex
                    || null === $producerIndex
                    || (
                        !$this->isNestedCallArgProducerForConsumer($producer, $callOp, $producerIndex, $callIndex, $block->orig->children)
                        && !$this->isSiblingMultiArgFuncCallProducer(
                            $producer,
                            $callOp,
                            $producerIndex,
                            $callIndex,
                            $block->orig->children
                        )
                        && !$this->operandsReferToSameVariable($producer->result, $arg)
                    )
                ) {
                    return null;
                }

                return $producerSlot;
            }
        }

        if (
            ($producer instanceof Op\Expr\FuncCall || $producer instanceof Op\Expr\NsFuncCall)
            && $this->inlineCallArgProducerFeedsConsumer($producer, $callOp)
        ) {
            if (null === $producerSlot) {
                $producerSlot = $this->resolveAdjacentNestedFuncCallArgSlot($block, $callOp, $argIndex);
            }
            if (null !== $producerSlot) {
                return $producerSlot;
            }
        }

        return $this->slotForMatchResultDeadCallArg($arg, $block, $cfgCallOp);
    }

    /**
     * php-cfg match lowering seeds a shared var, arms assign to it, merge uses dead arg temp (#9374).
     */
    private function findMatchResultVarForDeadCallArg(
        Operand $arg,
        CfgBlock $cfgBlock,
        Op $callOp
    ): ?Operand {
        if (!property_exists($callOp, 'args') || !is_array($callOp->args)) {
            return null;
        }
        $isCallArg = false;
        foreach ($callOp->args as $callArg) {
            if ($callArg === $arg || $this->operandsReferToSameVariable($callArg, $arg)) {
                $isCallArg = true;
                break;
            }
        }
        if (!$isCallArg) {
            return null;
        }
        foreach ($cfgBlock->children as $child) {
            if (
                $child instanceof Op\Expr
                && property_exists($child, 'result')
                && null !== $child->result
                && $this->operandsReferToSameVariable($child->result, $arg)
            ) {
                return null;
            }
        }
        // Match subject may be produced in the jump parent (ClassConstFetch) while this block
        // only calls phpc_match_unhandled_operand_is_object($cond) — do not reuse result slot (#5448).
        foreach ($cfgBlock->parents as $parent) {
            if (!$this->cfgBlockJumpsToCfgBlock($parent, $cfgBlock)) {
                continue;
            }
            foreach ($parent->children as $child) {
                if (
                    $child instanceof Op\Expr
                    && property_exists($child, 'result')
                    && null !== $child->result
                    && $this->operandsReferToSameVariable($child->result, $arg)
                ) {
                    return null;
                }
            }
        }
        if (!isset($cfgBlock->parents) || [] === $cfgBlock->parents) {
            return null;
        }
        $matchVar = null;
        foreach ($cfgBlock->parents as $parent) {
            if (!$this->cfgBlockJumpsToCfgBlock($parent, $cfgBlock)) {
                continue;
            }
            foreach ($parent->children as $child) {
                if (!$child instanceof Op\Expr\Assign) {
                    continue;
                }
                if (!$child->var instanceof CfgVariable && !$child->var instanceof Temporary) {
                    continue;
                }
                if (null === $matchVar) {
                    $matchVar = $child->var;
                    continue;
                }
                if (!$this->operandsReferToSameVariable($matchVar, $child->var)) {
                    return null;
                }
            }
        }

        return $matchVar;
    }

    /**
     * php-cfg dead call-arg temps for hoisted ClassConstFetch (e.g. UnitEnum::class) must not
     * fall through to match-result slot reuse (#9152, is_subclass_of after is_a).
     */
    private function slotForHoistedClassConstFetchCallArg(
        Operand $arg,
        Block $block,
        Op $callOp,
        int $argIndex
    ): ?string {
        if (null === $block->orig) {
            return null;
        }
        if ($this->callArgOperandIsClosureValue($arg, $block)) {
            return null;
        }
        if (!property_exists($callOp, 'args') || !is_array($callOp->args)) {
            return null;
        }
        $callArg = $callOp->args[$argIndex] ?? null;
        if (!$this->callArgUsesHoistedEnumPreludeSlot($callArg)) {
            return null;
        }
        $fetch = $this->precedingClassConstFetchForCallArgIndex(
            $callOp,
            $argIndex,
            $this->precedingCallArgClassConstFetchesBeforeCfgOp($block->orig->children, $callOp, $block)
        );
        if (!$fetch instanceof Op\Expr\ClassConstFetch) {
            $fetch = $this->classConstFetchForHoistedDeadPrelude($callOp, $argIndex, $block);
        }
        if (!$fetch instanceof Op\Expr\ClassConstFetch) {
            foreach ($block->orig->children as $i => $child) {
                if ($child !== $callOp) {
                    continue;
                }
                if ($i > 0) {
                    $prev = $block->orig->children[$i - 1];
                    $callArg = $callOp->args[$argIndex] ?? null;
                    if (
                        $prev instanceof Op\Expr\ClassConstFetch
                        && null !== $callArg
                        && $this->operandsReferToSameVariable($prev->result, $callArg)
                    ) {
                        $fetch = $prev;
                    }
                }
                break;
            }
        }
        if (!$fetch instanceof Op\Expr\ClassConstFetch) {
            return null;
        }
        if ($this->callArgIsNewExpression($callArg)) {
            return null;
        }
        $slot = $block->slotForOperand($fetch->result);
        if (null === $slot) {
            foreach ($this->compileExpr($fetch, $block) as $op) {
                $block->addOpCode($op);
            }
            $slot = $block->slotForOperand($fetch->result);
        }

        return null !== $slot ? (string) $slot : null;
    }

    /** Resolve VM slot for a hoisted inline Closure/ArrowFunction call-arg producer (#3673). */
    private function slotForInlineClosureProducer(Op\Expr $producer, Block $block): ?int
    {
        if (null === $producer->result) {
            return null;
        }
        $slot = $block->slotForOperand($producer->result);
        if (null !== $slot) {
            return $slot;
        }
        foreach ($block->opCodes as $op) {
            if (OpCode::TYPE_CLOSURE !== $op->type) {
                continue;
            }
            $destSlot = (int) $op->arg1;
            $destOperand = $block->operandForScopeSlot($destSlot);
            if (
                null !== $destOperand
                && $this->operandsReferToSameVariable($destOperand, $producer->result)
            ) {
                return $destSlot;
            }
        }
        for ($i = \count($block->opCodes) - 1; $i >= 0; --$i) {
            $op = $block->opCodes[$i];
            if (
                OpCode::TYPE_STATICCALL_INIT === $op->type
                || OpCode::TYPE_FUNCCALL_INIT === $op->type
            ) {
                break;
            }
            if (OpCode::TYPE_CLOSURE === $op->type) {
                return (int) $op->arg1;
            }
        }
        foreach ($this->compileExpr($producer, $block) as $op) {
            $block->addOpCode($op);
        }

        return $block->slotForOperand($producer->result);
    }

    /** Resolve VM slot for a hoisted inline first-class callable call-arg producer (#9769, zend_compile.c). */
    private function slotForInlineFirstClassCallableProducer(
        Op\Expr\FirstClassCallable $producer,
        Block $block
    ): ?int {
        if (null === $producer->result) {
            return null;
        }
        $slot = $block->slotForOperand($producer->result);
        if (null !== $slot) {
            return $slot;
        }
        foreach ($block->opCodes as $op) {
            if (OpCode::TYPE_FROM_CALLABLE !== $op->type) {
                continue;
            }
            $destSlot = (int) $op->arg1;
            $destOperand = $block->operandForScopeSlot($destSlot);
            if (
                null !== $destOperand
                && $this->operandsReferToSameVariable($destOperand, $producer->result)
            ) {
                return $destSlot;
            }
        }
        foreach ($this->compileFirstClassCallable($producer, $block) as $op) {
            $block->addOpCode($op);
        }

        return $block->slotForOperand($producer->result);
    }

    /** Inline `E::A->m(...)` call args must send the Closure result, not enum-case prefetch slots (#9769). */
    private function resolveInlineFirstClassCallableCallArgSlot(
        Operand $arg,
        Block $block,
        ?Op $cfgCallOp
    ): ?int {
        if (null === $block->orig || null === $cfgCallOp) {
            return null;
        }
        $callSite = $this->findCfgCallSiteForArg($block->orig->children, $arg, $cfgCallOp);
        if (null === $callSite) {
            return null;
        }
        [$callOp, $argIndex] = $callSite;
        if (!property_exists($callOp, 'args') || !is_array($callOp->args)) {
            return null;
        }
        $producers = $this->precedingInlineCallArgProducersBeforeCfgOp($block->orig->children, $callOp);
        $producer = $this->matchInlineCallArgProducer($producers, $callOp->args, $argIndex, $callOp, $block);
        if ($producer instanceof Op\Expr\FirstClassCallable) {
            return $this->slotForInlineFirstClassCallableProducer($producer, $block);
        }
        if (1 === count($callOp->args)) {
            $last = $producers[\count($producers) - 1] ?? null;
            if ($last instanceof Op\Expr\FirstClassCallable) {
                return $this->slotForInlineFirstClassCallableProducer($last, $block);
            }
        }

        return null;
    }

    /** StaticCall inline closure first arg — match hoisted Closure producer to TYPE_CLOSURE slot (#3673). */
    private function resolveInlineClosureCallArgSlot(Operand $arg, Block $block, ?Op $cfgCallOp): ?int
    {
        if (null === $block->orig || null === $cfgCallOp) {
            return null;
        }
        $callSite = $this->findCfgCallSiteForArg($block->orig->children, $arg, $cfgCallOp);
        if (null === $callSite) {
            $argRoot = $this->unwrapOperandChain($arg);
            if ($argRoot instanceof Op\Expr\ArrowFunction || $argRoot instanceof Op\Expr\Closure) {
                return $this->slotForInlineClosureProducer($argRoot, $block);
            }

            return null;
        }
        [$callOp, $argIndex] = $callSite;
        if (!property_exists($callOp, 'args') || !is_array($callOp->args)) {
            return null;
        }
        $callArg = $callOp->args[$argIndex] ?? null;
        if (null !== $callArg) {
            $callArgRoot = $this->unwrapOperandChain($callArg);
            if ($callArgRoot instanceof Op\Expr\ArrowFunction || $callArgRoot instanceof Op\Expr\Closure) {
                $directSlot = $this->slotForInlineClosureProducer($callArgRoot, $block);
                if (null !== $directSlot) {
                    return $directSlot;
                }
            }
        }
        $producers = $this->precedingInlineCallArgProducersBeforeCfgOp($block->orig->children, $callOp);
        $producer = $this->matchInlineCallArgProducer($producers, $callOp->args, $argIndex, $callOp, $block);
        if ($producer instanceof Op\Expr\Closure || $producer instanceof Op\Expr\ArrowFunction) {
            return $this->slotForInlineClosureProducer($producer, $block);
        }
        if ($producer instanceof Op\Expr\FirstClassCallable) {
            return $this->slotForInlineFirstClassCallableProducer($producer, $block);
        }
        foreach ($producers as $candidate) {
            if (!$candidate instanceof Op\Expr\Closure && !$candidate instanceof Op\Expr\ArrowFunction) {
                continue;
            }
            if (null !== $this->matchSingleClosureInlineProducer($candidate, $callOp->args, $argIndex)) {
                return $this->slotForInlineClosureProducer($candidate, $block);
            }
        }
        foreach ($producers as $candidate) {
            if (!$candidate instanceof Op\Expr\FirstClassCallable) {
                continue;
            }
            if (null !== $this->matchInlineCallArgProducer([$candidate], $callOp->args, $argIndex, $callOp)) {
                return $this->slotForInlineFirstClassCallableProducer($candidate, $block);
            }
        }

        return null;
    }

    /**
     * Inline fn()/function() callback args with trailing literal flags (#10232, #9154).
     */
    private function resolvePrecedingClosureCallArgSlot(Op $cfgCallOp, int $argIndex, Block $block): ?int
    {
        if (null === $block->orig || !property_exists($cfgCallOp, 'args') || !is_array($cfgCallOp->args)) {
            return null;
        }
        $callArgs = $cfgCallOp->args;
        if (\count($callArgs) < 2) {
            return null;
        }
        $producers = $this->precedingInlineCallArgProducersBeforeCfgOp($block->orig->children, $cfgCallOp);
        $closureProducer = null;
        foreach ($producers as $candidate) {
            if ($candidate instanceof Op\Expr\ArrowFunction || $candidate instanceof Op\Expr\Closure) {
                $closureProducer = $candidate;
                break;
            }
        }
        if (null === $closureProducer) {
            $callIndex = null;
            foreach ($block->orig->children as $i => $child) {
                if ($child === $cfgCallOp) {
                    $callIndex = $i;
                    break;
                }
            }
            if (null !== $callIndex) {
                for ($i = $callIndex - 1; $i >= 0; --$i) {
                    $prev = $block->orig->children[$i];
                    if ($prev instanceof Op\Expr\ArrowFunction || $prev instanceof Op\Expr\Closure) {
                        $closureProducer = $prev;
                        break;
                    }
                    if (!$this->isInlineExprCallArgProducer($prev)) {
                        break;
                    }
                }
            }
        }
        if (null === $closureProducer) {
            return null;
        }
        $matched = $this->matchSingleClosureInlineProducer($closureProducer, $callArgs, $argIndex);
        if (null === $matched) {
            $matched = $this->matchInlineCallArgProducer($producers, $callArgs, $argIndex, $cfgCallOp);
            if ($matched !== $closureProducer) {
                return null;
            }
        }
        $slot = $this->slotForInlineClosureProducer($closureProducer, $block);
        if (null !== $slot) {
            return $slot;
        }
        for ($i = \count($block->opCodes) - 1; $i >= 0; --$i) {
            $scanOp = $block->opCodes[$i];
            if (OpCode::TYPE_FUNCCALL_INIT === $scanOp->type) {
                break;
            }
            if (OpCode::TYPE_CLOSURE === $scanOp->type) {
                return (int) $scanOp->arg1;
            }
        }

        return null;
    }

    private function cfgBlockJumpsToCfgBlock(CfgBlock $from, CfgBlock $to): bool
    {
        foreach ($from->children as $child) {
            if ($child instanceof Op\Stmt\Jump && $child->target === $to) {
                return true;
            }
            if ($child instanceof Op\Stmt\JumpIf && ($child->if === $to || $child->else === $to)) {
                return true;
            }
        }

        return false;
    }

    private function slotForMatchResultDeadCallArg(
        Operand $arg,
        Block $block,
        ?Op $cfgCallOp
    ): ?string {
        if (null === $block->orig || null === $cfgCallOp) {
            return null;
        }
        $callSite = $this->findCfgCallSiteForArg($block->orig->children, $arg, $cfgCallOp);
        if (null === $callSite) {
            return null;
        }
        [$callOp] = $callSite;
        $matchVar = $this->findMatchResultVarForDeadCallArg($arg, $block->orig, $callOp);
        if (null === $matchVar) {
            return null;
        }
        $slot = $block->slotForOperand($matchVar);
        if (null === $slot) {
            $slot = $this->compileOperand($matchVar, $block, true);
        }

        return null !== $slot ? (string) $slot : null;
    }

    /**
     * Map a hoisted inline call-arg producer to the callee argument index (#8561, #5799).
     *
     * php-cfg may emit fewer preceding Expr_* producers than call args when literals stay
     * embedded in the FuncCall (e.g. array_fill_keys(array('a'), 'x')).
     *
     * @param list<Op\Expr> $producers
     * @param list<Operand> $callArgs
     */
    private function matchInlineCallArgProducer(
        array $producers,
        array $callArgs,
        int $argIndex,
        ?Op $cfgCallOp = null,
        ?Block $block = null
    ): ?Op\Expr
    {
        $producers = $this->filterDeadClassConstFetchInlineProducers($producers);
        $producers = $this->filterNestedNewInlineCallArgProducers($producers);
        $producerCount = count($producers);
        $argCount = count($callArgs);
        if (0 === $producerCount) {
            return null;
        }
        if ($this->callIncludesNamedParameter($cfgCallOp)) {
            $callArg = $callArgs[$argIndex] ?? null;
            if (null === $callArg) {
                return null;
            }
            if (
                $this->callArgIsDeadInlineTemporary($callArg)
                && null !== $cfgCallOp
                && null !== $block
                && null !== $block->orig
            ) {
                $byIndex = $this->inlineHoistedProducerForCallArgIndex(
                    $cfgCallOp,
                    $argIndex,
                    $producers,
                    $block->orig->children
                );
                if ($byIndex instanceof Op\Expr) {
                    return $byIndex;
                }
            }
            foreach ($producers as $producer) {
                if (
                    null !== $producer->result
                    && $this->operandsReferToSameVariable($producer->result, $callArg)
                ) {
                    if (
                        $producer instanceof Op\Expr\Array_
                        && !$this->callArgOperandExpectsArrayProducer($callArg)
                    ) {
                        continue;
                    }

                    return $producer;
                }
            }

            return null;
        }
        // php-cfg hoists `$a = [...]` as Array_+Assign before `array_key_exists('k', $a)` (#9456).
        if ($this->isEmbeddedCallLiteralArg($callArgs[$argIndex] ?? null)) {
            return null;
        }
        $callArg = $callArgs[$argIndex] ?? null;
        if (null !== $callArg) {
            $booleanProducer = $this->matchBooleanBinaryOpInlineCallArgProducer($producers, $callArg);
            if (null !== $booleanProducer) {
                return $booleanProducer;
            }
        }
        if ($this->callArgIsNewExpression($callArg)) {
            foreach ($producers as $producer) {
                if ($producer instanceof Op\Expr\New_) {
                    return $producer;
                }
            }

            return null;
        }
        if ($argCount < $producerCount) {
            // array_fill_keys([[[1]]], 1) — all Array_ preludes belong to the sole hoisted arg (#10848).
            if (
                $this->producersAreNestedArrayLiteralChain($producers)
                && $this->arrayProducersFormNestedChain($producers)
            ) {
                $soleHoisted = $this->soleNonEmbeddedCallArgIndex($callArgs);
                if (null !== $soleHoisted && $argIndex === $soleHoisted) {
                    return $producers[$producerCount - 1];
                }
            }
            $nestedArrayTrailing = $this->splitNestedArrayLiteralChainWithTrailingProducers($producers);
            if (null !== $nestedArrayTrailing) {
                [$arrayChain, $trailing] = $nestedArrayTrailing;
                if (1 + \count($trailing) === $argCount) {
                    if (0 === $argIndex) {
                        return $arrayChain[\count($arrayChain) - 1];
                    }

                    return $trailing[$argIndex - 1] ?? null;
                }
            }
            // php-cfg hoists compare/array-dim preludes before trailing literal args (#5901, #9660).
            if ($argIndex < $argCount - 1) {
                $trailingForLaterArgs = $argCount - 1 - $argIndex;
                $prefixEnd = $producerCount - $trailingForLaterArgs;
                if ($prefixEnd > 0) {
                    $prefixLast = $producers[$prefixEnd - 1] ?? null;
                    if (
                        $prefixLast instanceof Op\Expr\BinaryOp\Identical
                        || $prefixLast instanceof Op\Expr\BinaryOp\NotIdentical
                        || $prefixLast instanceof Op\Expr\BinaryOp\Equal
                        || $prefixLast instanceof Op\Expr\BinaryOp\NotEqual
                        || $prefixLast instanceof Op\Expr\InstanceOf_
                        || $prefixLast instanceof Op\Expr\In_
                        // var_export([...] + [...], true) — Plus prelude before trailing literal (#11511, #10490).
                        || $prefixLast instanceof Op\Expr\BinaryOp\Plus
                        || $prefixLast instanceof Op\Expr\BinaryOp\Concat
                    ) {
                        return $prefixLast;
                    }
                }
            }
            $extra = $producerCount - $argCount;
            $tail = array_slice($producers, -$extra);
            if (
                !$this->producersAreNestedArrayLiteralChain($tail)
                && !$this->producersAreChainedAssignChain($producers)
            ) {
                $filtered = $this->filterNestedNewInlineCallArgProducers($producers);
                if (\count($filtered) === $argCount) {
                    return $filtered[$argIndex] ?? null;
                }
                // PropertyFetch prelude for empty($obj->prop) / isset($obj->prop) call args (#8901).
                foreach ($producers as $producer) {
                    if ($producer instanceof Op\Expr\Empty_ || $producer instanceof Op\Expr\Isset_) {
                        if (1 === $argCount) {
                            return $producer;
                        }
                        $callArg = $callArgs[$argIndex] ?? null;
                        if (null !== $callArg && $this->operandsReferToSameVariable($producer->result, $callArg)) {
                            return $producer;
                        }
                    }
                }
                if (1 === $argCount) {
                    $last = $producers[$producerCount - 1] ?? null;
                    // PropertyFetch/StaticPropertyFetch prelude before ++/-- (#10123, zend_execute.c).
                    if ($last instanceof Op\Expr\PostInc
                        || $last instanceof Op\Expr\PreInc
                        || $last instanceof Op\Expr\PostDec
                        || $last instanceof Op\Expr\PreDec
                    ) {
                        return $last;
                    }
                    if ($last instanceof Op\Expr\NullsafePropertyFetch || $last instanceof Op\Expr\NullsafeMethodCall) {
                        return $last;
                    }
                    // Clone/assign prelude before property read (#9114, var_dump($c->n) in try).
                    if ($last instanceof Op\Expr\PropertyFetch || $last instanceof Op\Expr\ArrayDimFetch) {
                        return $last;
                    }
                    // (new C())->m() inline call-arg (#9428, zend_traits.c alias visibility repro).
                    if ($last instanceof Op\Expr\MethodCall || $last instanceof Op\Expr\StaticCall) {
                        return $last;
                    }
                    // Inline first-class callable call arg (#9769, zend_closures.c).
                    if ($last instanceof Op\Expr\FirstClassCallable) {
                        return $last;
                    }
                    // php-cfg dead temp for `var_dump(E::A::class)` — last producer is Case::class (#9426, #9518).
                    if ($last instanceof Op\Expr\ClassConstFetch) {
                        $pseudoName = $this->staticNameFromOperand($last->name);
                        if (null !== $pseudoName && 'class' === strtolower($pseudoName)) {
                            return $last;
                        }
                    }
                    // Hoisted ConstFetch prelude before inline scalar cast (#10143, #9479).
                    if ($last instanceof Op\Expr\Cast) {
                        return $last;
                    }
                    // Inline array union `var_export([...] + [...])` — Plus after Array_ preludes (#10490, #10578).
                    if ($last instanceof Op\Expr\BinaryOp\Plus) {
                        return $last;
                    }
                    // Hoisted ConstFetch prelude before inline concat call arg (#10663, zend_operators.c).
                    if ($last instanceof Op\Expr\BinaryOp\Concat) {
                        return $last;
                    }
                    // Inline eval() call arg — php-cfg dead temp vs TYPE_EVAL producer (#10661, zif_eval).
                    if ($last instanceof Op\Expr\Eval_) {
                        return $last;
                    }
                    // is_countable(new ArrayIterator([])) — ctor Array_ prelude + inline New_ (#10900).
                    if ($last instanceof Op\Expr\New_) {
                        return $last;
                    }
                    if ($last instanceof Op\Expr\BinaryOp\BitwiseOr
                        || $last instanceof Op\Expr\BinaryOp\BitwiseAnd
                        || $last instanceof Op\Expr\BinaryOp\BitwiseXor
                    ) {
                        return $last;
                    }
                }

                $last = $producers[$producerCount - 1] ?? null;
                if ($last instanceof Op\Expr\BinaryOp\BitwiseOr
                    || $last instanceof Op\Expr\BinaryOp\BitwiseAnd
                    || $last instanceof Op\Expr\BinaryOp\BitwiseXor
                ) {
                    $nonEmbeddedArgIndices = [];
                    foreach ($callArgs as $i => $arg) {
                        if (null !== $arg && !$this->isEmbeddedCallLiteralArg($arg)) {
                            $nonEmbeddedArgIndices[] = $i;
                        }
                    }
                    $trailingNonEmbedded = $nonEmbeddedArgIndices[\count($nonEmbeddedArgIndices) - 1] ?? null;
                    if ($argIndex === $trailingNonEmbedded) {
                        return $last;
                    }
                }

                // iterator_to_array(new ArrayObject([...]), false) — ctor Array_ prelude + New_ + trailing arg (#11321).
                if (
                    $extra >= 1
                    && ($producers[0] ?? null) instanceof Op\Expr\Array_
                    && ($producers[1] ?? null) instanceof Op\Expr\New_
                ) {
                    $mappedIndex = $argIndex + 1;
                    if ($mappedIndex >= 0 && $mappedIndex < $producerCount) {
                        return $producers[$mappedIndex];
                    }
                }

                return null;
            }
            // php-cfg emits inner-then-outer Array_ per inline arg (#4738, #10196, #10662).
            if ($this->producersAreNestedArrayLiteralChain($producers) && 0 === $producerCount % $argCount) {
                $depth = intdiv($producerCount, $argCount);
                $mappedIndex = $argIndex * $depth + ($depth - 1);
            } elseif (
                $extra >= 1
                && ($producers[0] ?? null) instanceof Op\Expr\Array_
                && ($producers[1] ?? null) instanceof Op\Expr\New_
            ) {
                $mappedIndex = $argIndex + 1;
            } elseif (1 === $argCount) {
                $mappedIndex = $producerCount - 1;
            } else {
                $mappedIndex = $argIndex + ($argIndex > 0 ? $extra : 0);
            }
            if ($mappedIndex >= $producerCount || $mappedIndex < 0) {
                return null;
            }

            return $producers[$mappedIndex] ?? null;
        }
        if ($producerCount === $argCount) {
            // filter_var('x', FILTER_*, ['options' => ['regexp' => '/a/']]) — ConstFetch + nested Array_ (#12007).
            $leadingConstNested = $this->splitLeadingConstFetchWithNestedArrayLiteralChain($producers);
            if (null !== $leadingConstNested) {
                [$constFetch, $arrayChain] = $leadingConstNested;
                $arrayArgIndex = $argCount - 1;
                if ($argIndex === $arrayArgIndex) {
                    return $arrayChain[\count($arrayChain) - 1];
                }
                $constArgIndex = null;
                for ($i = $arrayArgIndex - 1; $i >= 0; --$i) {
                    if (!$this->isEmbeddedCallLiteralArg($callArgs[$i] ?? null)) {
                        $constArgIndex = $i;
                        break;
                    }
                }
                if ($argIndex === $constArgIndex) {
                    return $constFetch;
                }

                return null;
            }
            // php-cfg `f(g(), h())` hoists sibling FuncCall producers with dead arg temps (#9463, #10917).
            if ($argIndex < $producerCount) {
                $allSiblingFuncCalls = true;
                foreach ($producers as $candidate) {
                    if (
                        !$candidate instanceof Op\Expr\FuncCall
                        && !$candidate instanceof Op\Expr\NsFuncCall
                    ) {
                        $allSiblingFuncCalls = false;
                        break;
                    }
                }
                if ($allSiblingFuncCalls) {
                    // f(g(), h()) only — unrelated preceding stmt FuncCalls must not feed named locals (#11187).
                    if (!$this->callArgsAreDistinctInlineTemporaries($callArgs)) {
                        return null;
                    }

                    return $producers[$argIndex];
                }
            }
            $closureIdx = null;
            $arrayIdx = null;
            foreach ($producers as $pi => $producer) {
                if ($producer instanceof Op\Expr\ArrowFunction
                    || $producer instanceof Op\Expr\Closure
                    || $producer instanceof Op\Expr\FirstClassCallable) {
                    $closureIdx = $pi;
                } elseif ($producer instanceof Op\Expr\Array_) {
                    $arrayIdx = $pi;
                }
            }
            // Closure/FCC + inline Array_ — match by dead-temp operand wiring first (#10827, array_all/any/find);
            // array_map(callback, array) fallback when links are opaque (#10651, #11450).
            if (null !== $closureIdx && null !== $arrayIdx && 2 === $producerCount && 2 === $argCount) {
                $callArg = $callArgs[$argIndex] ?? null;
                if (null !== $callArg) {
                    if ($this->operandsReferToSameVariable($producers[$arrayIdx]->result, $callArg)) {
                        return $producers[$arrayIdx];
                    }
                    if ($this->operandsReferToSameVariable($producers[$closureIdx]->result, $callArg)) {
                        return $producers[$closureIdx];
                    }
                }
                $callbackArgIndex = $this->inlineClosureArrayPairCallbackArgIndex(
                    $this->resolveCfgFuncCallName($cfgCallOp)
                );
                $arrayArgIndex = 1 - $callbackArgIndex;
                if ($argIndex === $callbackArgIndex) {
                    return $producers[$closureIdx];
                }
                if ($argIndex === $arrayArgIndex) {
                    return $producers[$arrayIdx];
                }

                return null;
            }
            if ($this->producersAreNestedArrayLiteralChain($producers)) {
                // array_fill_keys([[1]], 1) — nested Array_ preludes map to the sole hoisted arg (#10848).
                if (
                    $this->arrayProducersFormNestedChain($producers)
                    && $producerCount >= 2
                ) {
                    $soleHoisted = $this->soleNonEmbeddedCallArgIndex($callArgs);
                    if (null !== $soleHoisted && $argIndex === $soleHoisted) {
                        return $producers[$producerCount - 1];
                    }
                }
                $callArg = $callArgs[$argIndex] ?? null;
                $paired = $producers[$argIndex] ?? null;
                if (
                    null !== $callArg
                    && $paired instanceof Op\Expr\Array_
                    && $this->operandsReferToSameVariable($paired->result, $callArg)
                ) {
                    return $paired;
                }
                // php-cfg dead call-arg temps for sibling inline Array_ producers (#8561, #10231).
                if ($paired instanceof Op\Expr\Array_) {
                    return $paired;
                }
                if ($argIndex < $argCount - 1) {
                    // in_array(null, [null]) — hoisted null needle must not lose to haystack Array_ (#10909).
                    if (
                        $paired instanceof Op\Expr\ConstFetch
                        && $this->operandsReferToSameVariable($paired->result, $callArgs[$argIndex] ?? null)
                    ) {
                        $name = $this->staticNameFromOperand($paired->name);
                        if (null !== $name && \in_array(strtolower($name), ['true', 'false', 'null'], true)) {
                            return $paired;
                        }
                    }

                    return null;
                }
                if ($producerCount > 1) {
                    return $producers[$producerCount - 1];
                }
            }
            $paired = $producers[$argIndex] ?? null;
            if ($paired instanceof Op\Expr\Assign) {
                $callArg = $callArgs[$argIndex] ?? null;
                if (
                    null === $callArg
                    || null === $paired->var
                    || !$this->operandsReferToSameVariable($paired->var, $callArg)
                ) {
                    return null;
                }
            }
            if ($paired instanceof Op\Expr\FuncCall || $paired instanceof Op\Expr\NsFuncCall) {
                $callArg = $callArgs[$argIndex] ?? null;
                if (
                    null !== $callArg
                    && !$this->namedCallArgMayUseFuncCallProducerResult($paired, $callArg)
                ) {
                    return null;
                }
                if (
                    (null === $callArg || !$this->operandsReferToSameVariable($paired->result, $callArg))
                    && $argCount > 1
                    && !$this->callArgsAreDistinctInlineTemporaries($callArgs)
                ) {
                    return null;
                }
            }
            $callArg = $callArgs[$argIndex] ?? null;
            if (
                null !== $callArg
                && !$this->isEmbeddedCallLiteralArg($callArg)
            ) {
                foreach ($producers as $producer) {
                    if (!$producer instanceof Op\Expr\BinaryOp\Coalesce) {
                        continue;
                    }
                    if (
                        $callArg instanceof Operand\Temporary
                        || $producer->result === $callArg
                        || $this->operandsReferToSameVariable($producer->result, $callArg)
                    ) {
                        return $producer;
                    }
                }
            }

            return $paired;
        }
        if (1 === $producerCount) {
            if (
                ($producers[0] instanceof Op\Expr\ConstFetch || $producers[0] instanceof Op\Expr\ClassConstFetch)
                && $argCount - 1 === $argIndex
            ) {
                $callArg = $callArgs[$argIndex] ?? null;
                if (
                    null !== $callArg
                    && $this->operandsReferToSameVariable($producers[0]->result, $callArg)
                ) {
                    return $producers[0];
                }
                if ($producers[0] instanceof Op\Expr\ClassConstFetch) {
                    $pseudoName = $this->staticNameFromOperand($producers[0]->name);
                    if (null !== $pseudoName && 'class' === strtolower($pseudoName)) {
                        return $producers[0];
                    }
                }
                // Fall through — php-cfg dead call-arg temp (#9140, #9260, #9324).
            }
            if (
                $argCount - 1 === $argIndex
                && $producers[0] instanceof Op\Expr\Array_
            ) {
                $callArg = $callArgs[$argIndex] ?? null;
                if (
                    null !== $callArg
                    && $this->operandsReferToSameVariable($producers[0]->result, $callArg)
                ) {
                    return $producers[0];
                }
                // Fall through — dead haystack temp (#9888).
            }
            if ($argCount - 1 === $argIndex) {
                if ($this->isEmbeddedCallLiteralArg($callArgs[0] ?? null)) {
                    return $producers[0];
                }
                // strtotime('next Monday', strtotime('...')) — nested FuncCall feeds trailing arg (#10838).
                if (
                    ($producers[0] instanceof Op\Expr\FuncCall || $producers[0] instanceof Op\Expr\NsFuncCall)
                    && null !== ($callArgs[0] ?? null)
                    && !$this->operandsReferToSameVariable($producers[0]->result, $callArgs[0])
                ) {
                    return $producers[0];
                }
            }
            if ($producers[0] instanceof Op\Expr\Array_) {
                if (1 === $argCount) {
                    return $producers[0];
                }
                $callArg = $callArgs[$argIndex] ?? null;
                if (null === $callArg) {
                    return null;
                }
                if ($this->operandsReferToSameVariable($producers[0]->result, $callArg)) {
                    return $producers[0];
                }
                // Fall through — inline haystack may use a dead temp (#9888).
            }
            if (
                0 === $argIndex
                && !($producers[0] instanceof Op\Expr\Array_)
                && !($producers[0] instanceof Op\Expr\ConstFetch)
                && !($producers[0] instanceof Op\Expr\ClassConstFetch)
                && !($producers[0] instanceof Op\Expr\ArrowFunction)
                && !($producers[0] instanceof Op\Expr\Closure)
                && !$this->isEmbeddedCallLiteralArg($callArgs[0] ?? null)
            ) {
                $callArg = $callArgs[$argIndex] ?? null;
                if (
                    null !== $callArg
                    && ($producers[0] instanceof Op\Expr\FuncCall || $producers[0] instanceof Op\Expr\NsFuncCall)
                    && !$this->namedCallArgMayUseFuncCallProducerResult($producers[0], $callArg)
                ) {
                    return null;
                }
                if (
                    null !== $callArg
                    && ($producers[0] instanceof Op\Expr\FuncCall || $producers[0] instanceof Op\Expr\NsFuncCall)
                    && $this->funcCallExprByRefArgMatchesOperand($producers[0], $callArg)
                ) {
                    return null;
                }

                return $producers[0];
            }
            $closureMatch = $this->matchSingleClosureInlineProducer($producers[0], $callArgs, $argIndex);
            if (null !== $closureMatch) {
                return $closureMatch;
            }

            if ($argCount > $producerCount) {
                return $this->matchInlineCallArgProducerWithEmbeddedLiterals(
                    $producers,
                    $callArgs,
                    $argIndex,
                    $cfgCallOp
                );
            }

            return null;
        }
        if ($argCount > $producerCount) {
            if (0 === $argIndex) {
                $nestedTrailing = $this->splitNestedArrayLiteralChainWithTrailingProducers($producers);
                if (null !== $nestedTrailing) {
                    [$arrayChain, ] = $nestedTrailing;

                    return $arrayChain[\count($arrayChain) - 1];
                }
            }

            return $this->matchInlineCallArgProducerWithEmbeddedLiterals(
                $producers,
                $callArgs,
                $argIndex,
                $cfgCallOp
            );
        }
        if ($argIndex < $producerCount) {
            if (0 === $argIndex) {
                $nestedTrailing = $this->splitNestedArrayLiteralChainWithTrailingProducers($producers);
                if (null !== $nestedTrailing) {
                    [$arrayChain, ] = $nestedTrailing;

                    return $arrayChain[\count($arrayChain) - 1];
                }
                if ($this->producersAreNestedArrayLiteralChain($producers)) {
                    // Nested inline array literal is one call arg — outer Array_ is the producer (#9305, #10042).
                    return $producers[$producerCount - 1];
                }
                $lastArray = null;
                foreach ($producers as $producer) {
                    if ($producer instanceof Op\Expr\Array_) {
                        $lastArray = $producer;
                    }
                }
                if (null !== $lastArray) {
                    return $lastArray;
                }
            }
            // Embedded literal args must not consume hoisted Array_ slots (#12008, http_build_query).
            if ($this->isEmbeddedCallLiteralArg($callArgs[$argIndex] ?? null)) {
                return null;
            }

            return $producers[$argIndex];
        }

        return null;
    }

    /**
     * Map hoisted inline producers when php-cfg embeds literal call args (#8561, #8796).
     *
     * e.g. in_array(1, [1, 2, 3], true) — producers [Array_, ConstFetch] align to args 1 and 2, not 0.
     *
     * @param list<Op\Expr> $producers
     * @param list<Operand> $callArgs
     */
    private function matchInlineCallArgProducerWithEmbeddedLiterals(
        array $producers,
        array $callArgs,
        int $argIndex,
        ?Op $cfgCallOp = null
    ): ?Op\Expr {
        $nestedTrailing = $this->splitNestedArrayLiteralChainWithTrailingProducers($producers);
        if (null !== $nestedTrailing) {
            [$arrayChain, $trailing] = $nestedTrailing;
            if (0 === $argIndex) {
                return $arrayChain[\count($arrayChain) - 1];
            }
            if ($argIndex === \count($callArgs) - 1 && [] !== $trailing) {
                return $trailing[\count($trailing) - 1];
            }
        }
        if ($this->isEmbeddedCallLiteralArg($callArgs[$argIndex] ?? null)) {
            return null;
        }
        $callArg = $callArgs[$argIndex] ?? null;
        if (null === $callArg) {
            return null;
        }
        foreach ($producers as $producer) {
            if ($this->operandsReferToSameVariable($producer->result, $callArg)) {
                return $producer;
            }
        }

        // php-cfg dead call-arg temps: hoisted producers align to non-embedded arg slots (#9324).
        $nonEmbeddedArgIndices = [];
        foreach ($callArgs as $i => $arg) {
            if (null !== $arg && !$this->isEmbeddedCallLiteralArg($arg)) {
                $nonEmbeddedArgIndices[] = $i;
            }
        }
        // array_map(fn, [...], [...]) — php-cfg omits ArrowFunction from hoisted producers (#10094).
        if (
            'array_map' === $this->resolveCfgFuncCallName($cfgCallOp)
            && $this->producersAreNestedArrayLiteralChain($producers)
            && $argIndex >= 1
            && $argIndex - 1 < \count($producers)
        ) {
            return $producers[$argIndex - 1];
        }
        // strtotime('next Monday', strtotime('2024-06-03')) — lone hoisted FuncCall → sole non-embedded arg (#10838).
        if (
            1 === \count($producers)
            && 1 === \count($nonEmbeddedArgIndices)
            && ($producers[0] instanceof Op\Expr\FuncCall || $producers[0] instanceof Op\Expr\NsFuncCall)
            && $argIndex === $nonEmbeddedArgIndices[0]
        ) {
            return $producers[0];
        }
        // in_array(E::A, [E::A, E::B], true) — Array_ + trailing ConstFetch map to haystack/strict slots (#8796, #9888).
        if (\count($producers) >= 2) {
            $arrayProducerIndex = null;
            $constFetchIndices = [];
            $unaryProducerIndex = null;
            foreach ($producers as $pi => $producer) {
                if ($producer instanceof Op\Expr\Array_) {
                    $arrayProducerIndex = $pi;
                } elseif ($producer instanceof Op\Expr\ConstFetch) {
                    $constFetchIndices[] = $pi;
                } elseif ($producer instanceof Op\Expr\UnaryMinus || $producer instanceof Op\Expr\UnaryPlus) {
                    $unaryProducerIndex = $pi;
                }
            }
            // array_slice($a, -2, 2, true) — Array_ + UnaryMinus + trailing ConstFetch (#10579, #10809).
            if (
                null !== $arrayProducerIndex
                && null !== $unaryProducerIndex
                && 1 === \count($constFetchIndices)
                && \count($nonEmbeddedArgIndices) >= 3
            ) {
                $arrayArgIndex = $nonEmbeddedArgIndices[0] ?? null;
                $offsetArgIndex = $nonEmbeddedArgIndices[1] ?? null;
                $trailingArgIndex = $nonEmbeddedArgIndices[\count($nonEmbeddedArgIndices) - 1] ?? null;
                if ($argIndex === $arrayArgIndex) {
                    return $producers[$arrayProducerIndex];
                }
                if ($argIndex === $offsetArgIndex) {
                    return $producers[$unaryProducerIndex];
                }
                if ($argIndex === $trailingArgIndex) {
                    return $producers[$constFetchIndices[0]];
                }

                return null;
            }
            if (null !== $arrayProducerIndex && 1 === \count($constFetchIndices) && \count($nonEmbeddedArgIndices) >= 3) {
                $arrayArgIndex = $nonEmbeddedArgIndices[1] ?? null;
                $literalArgIndex = $nonEmbeddedArgIndices[\count($nonEmbeddedArgIndices) - 1] ?? null;
                if ($argIndex === $arrayArgIndex) {
                    return $producers[$arrayProducerIndex];
                }
                if ($argIndex === $literalArgIndex) {
                    return $producers[$constFetchIndices[0]];
                }

                return null;
            }
            $closureProducerIndex = null;
            foreach ($producers as $pi => $producer) {
                if ($producer instanceof Op\Expr\ArrowFunction
                    || $producer instanceof Op\Expr\Closure
                    || $producer instanceof Op\Expr\FirstClassCallable) {
                    $closureProducerIndex = $pi;
                    break;
                }
            }
            // array_filter($arr, fn(...) => ..., ARRAY_FILTER_USE_BOTH) — hoisted closure + trailing mode const (#10232, #9154).
            if (null !== $closureProducerIndex && 1 === \count($constFetchIndices) && \count($callArgs) >= 3) {
                $callbackArgIndex = null;
                foreach ($nonEmbeddedArgIndices as $idx) {
                    if ($idx > 0) {
                        $callbackArgIndex = $idx;
                        break;
                    }
                }
                $trailingArgIndex = \count($callArgs) - 1;
                if ($argIndex === $callbackArgIndex) {
                    return $producers[$closureProducerIndex];
                }
                if ($argIndex === $trailingArgIndex) {
                    return $producers[$constFetchIndices[0]];
                }

                return null;
            }
            // preg_replace_callback($pat, fn(...), $arr) — closure arg 1, array arg 2 (#10652).
            if (
                null !== $closureProducerIndex
                && null !== $arrayProducerIndex
                && 'preg_replace_callback' === $this->resolveCfgFuncCallName($cfgCallOp)
            ) {
                if (1 === $argIndex) {
                    return $producers[$closureProducerIndex];
                }
                if (2 === $argIndex) {
                    return $producers[$arrayProducerIndex];
                }

                return null;
            }
            // array_map(fn(...), [...]) / array_reduce([...], fn(...)) — closure + inline Array_ (#10651, #10775).
            if (null !== $closureProducerIndex && null !== $arrayProducerIndex) {
                $callbackArgIndex = $this->inlineClosureArrayPairCallbackArgIndex(
                    $this->resolveCfgFuncCallName($cfgCallOp)
                );
                $arrayArgIndex = 1 - $callbackArgIndex;
                if ($argIndex === $callbackArgIndex) {
                    return $producers[$closureProducerIndex];
                }
                if ($argIndex === $arrayArgIndex) {
                    return $producers[$arrayProducerIndex];
                }

                return null;
            }
        }
        // array_column([[..],[..]], 'name', null) — outer Array_ + trailing null ConstFetch (#9305).
        if (
            2 === \count($producers)
            && $producers[0] instanceof Op\Expr\Array_
            && $producers[1] instanceof Op\Expr\ConstFetch
            && \count($nonEmbeddedArgIndices) >= 2
        ) {
            if ($argIndex === $nonEmbeddedArgIndices[0]) {
                return $producers[0];
            }
            if ($argIndex === $nonEmbeddedArgIndices[1]) {
                return $producers[1];
            }

            return null;
        }
        // array_column([[..],[..]], 'name', null) — legacy arg0-only guard when only one non-embedded slot (#9305).
        if (
            2 === \count($producers)
            && $producers[0] instanceof Op\Expr\Array_
            && $producers[1] instanceof Op\Expr\ConstFetch
            && 0 === ($nonEmbeddedArgIndices[0] ?? -1)
            && 0 === $argIndex
            && 1 === \count($nonEmbeddedArgIndices)
        ) {
            return $producers[0];
        }
        // in_array(E::A, [E::A, E::B]) — lone Array_ maps to haystack slot, not enum needle (#8796, #9888).
        if (
            1 === \count($producers)
            && $producers[0] instanceof Op\Expr\Array_
            && \count($nonEmbeddedArgIndices) >= 2
            && \count($producers) < \count($nonEmbeddedArgIndices)
        ) {
            $arrayArgIndex = $nonEmbeddedArgIndices[\count($producers)];

            return $argIndex === $arrayArgIndex ? $producers[0] : null;
        }
        // array_column([['x'=>1]], 'x') — lone outer Array_ maps to first non-embedded arg (#9305, #10042).
        if (
            1 === \count($producers)
            && $producers[0] instanceof Op\Expr\Array_
            && 1 === \count($nonEmbeddedArgIndices)
            && $argIndex === $nonEmbeddedArgIndices[0]
        ) {
            return $producers[0];
        }
        // array_slice($b, 1, -2) — lone UnaryMinus maps to trailing non-embedded arg (#10579).
        if (
            1 === \count($producers)
            && ($producers[0] instanceof Op\Expr\UnaryMinus || $producers[0] instanceof Op\Expr\UnaryPlus)
            && \count($nonEmbeddedArgIndices) >= 2
        ) {
            $unaryArgIndex = $nonEmbeddedArgIndices[\count($nonEmbeddedArgIndices) - 1];

            return $argIndex === $unaryArgIndex ? $producers[0] : null;
        }
        // preg_match*() PREG_* | PREG_* — ConstFetch preludes + dead-temp BitwiseOr (#10517, #3148).
        $lastProducer = $producers[\count($producers) - 1] ?? null;
        if (
            $lastProducer instanceof Op\Expr\BinaryOp\BitwiseOr
            || $lastProducer instanceof Op\Expr\BinaryOp\BitwiseAnd
            || $lastProducer instanceof Op\Expr\BinaryOp\BitwiseXor
        ) {
            $trailingNonEmbedded = $nonEmbeddedArgIndices[\count($nonEmbeddedArgIndices) - 1] ?? null;
            if ($argIndex === $trailingNonEmbedded) {
                return $lastProducer;
            }
        }
        if (\count($producers) !== \count($nonEmbeddedArgIndices)) {
            return null;
        }
        if ($this->isNamedVariableOperand($callArg)) {
            return null;
        }
        $producerOrdinal = array_search($argIndex, $nonEmbeddedArgIndices, true);
        if (false === $producerOrdinal) {
            return null;
        }

        return $producers[$producerOrdinal] ?? null;
    }

    /**
     * Drop `(new C())` preludes when php-cfg lowers `(new C())->prop` as separate inline producers (#8874).
     *
     * @param list<Op\Expr> $producers
     *
     * @return list<Op\Expr>
     */
    private function filterNestedNewInlineCallArgProducers(array $producers): array
    {
        $filtered = [];
        $count = \count($producers);
        for ($i = 0; $i < $count; ++$i) {
            $producer = $producers[$i];
            if ($producer instanceof Op\Expr\New_) {
                $next = $producers[$i + 1] ?? null;
                if (
                    ($next instanceof Op\Expr\PropertyFetch
                        || $next instanceof Op\Expr\MethodCall
                        || $next instanceof Op\Expr\NullsafeMethodCall)
                    && property_exists($next, 'var')
                    && $next->var instanceof Operand
                    && $this->operandsReferToSameVariable($next->var, $producer->result)
                ) {
                    continue;
                }
                // f((string) new C()) — php-cfg dead arg temp; Cast consumes New_ (#9504).
                if (
                    $next instanceof Op\Expr\Cast
                    && property_exists($next, 'expr')
                    && $this->operandsReferToSameVariable($next->expr, $producer->result)
                ) {
                    continue;
                }
                // array_fill_keys([new C()], 1) — New_ prelude is array element, not the keys arg (#10849).
                if (
                    $next instanceof Op\Expr\Array_
                    && property_exists($next, 'values')
                    && \is_array($next->values)
                ) {
                    foreach ($next->values as $entryValue) {
                        if (
                            $entryValue instanceof Operand
                            && $this->operandsReferToSameVariable($entryValue, $producer->result)
                        ) {
                            continue 2;
                        }
                    }
                }
            }
            $filtered[] = $producer;
        }

        return $filtered;
    }

    /**
     * php-cfg dead temps for `var_export(C::AR[0] === E::X, true)` — Identical feeds arg 0, not ClassConstFetch (#5901, #9660).
     *
     * @param list<Op\Expr> $producers
     */
    private function matchBooleanBinaryOpInlineCallArgProducer(array $producers, Operand $callArg): ?Op\Expr
    {
        foreach (array_reverse($producers) as $producer) {
            if (
                $producer instanceof Op\Expr\BinaryOp\Identical
                || $producer instanceof Op\Expr\BinaryOp\NotIdentical
                || $producer instanceof Op\Expr\BinaryOp\Equal
                || $producer instanceof Op\Expr\BinaryOp\NotEqual
                || $producer instanceof Op\Expr\InstanceOf_
                || $producer instanceof Op\Expr\In_
            ) {
                if ($this->operandsReferToSameVariable($producer->result, $callArg)) {
                    return $producer;
                }
            }
        }

        return null;
    }

    /**
     * php-cfg dead ClassConstFetch preludes before inline Array_/Concat call args (#5933, #4109).
     *
     * @param list<Op\Expr> $producers
     *
     * @return list<Op\Expr>
     */
    private function filterDeadClassConstFetchInlineProducers(array $producers): array
    {
        if (count($producers) < 2) {
            return $producers;
        }
        $filtered = [];
        foreach ($producers as $i => $producer) {
            if ($producer instanceof Op\Expr\ClassConstFetch) {
                $feedsLater = false;
                for ($j = $i + 1, $n = count($producers); $j < $n; ++$j) {
                    if ($this->cfgExprUsesOperand($producers[$j], $producer->result)) {
                        $feedsLater = true;
                        break;
                    }
                }
                if ($feedsLater) {
                    continue;
                }
            }
            $filtered[] = $producer;
        }

        return $filtered;
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

        return false;
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
        $root = $this->unwrapOperandChain($callArg);
        if ($root instanceof Temporary) {
            return true;
        }

        // php-cfg dead call-arg Variable temps (e.g. var_dump(E::A::class); #9426).
        return $root instanceof Operand\Variable && !$this->isNamedVariableOperand($callArg);
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
                $fetch = $precedingFetches[$fetchIndex] ?? null;
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
     * ConstFetch prelude before nested inline Array_ call arg (#12007, filter_var + options array).
     *
     * e.g. filter_var('abc', FILTER_VALIDATE_REGEXP, ['options' => ['regexp' => '/^a/']])
     * — producers [ConstFetch, inner Array_, outer Array_].
     *
     * @param list<Op\Expr> $producers
     *
     * @return array{0: Op\Expr\ConstFetch, 1: list<Op\Expr\Array_>}|null
     */
    private function splitLeadingConstFetchWithNestedArrayLiteralChain(array $producers): ?array
    {
        $first = $producers[0] ?? null;
        if (!$first instanceof Op\Expr\ConstFetch) {
            return null;
        }
        $rest = array_slice($producers, 1);
        if ([] === $rest || !$this->producersAreNestedArrayLiteralChain($rest)) {
            return null;
        }
        if (!$this->arrayProducersFormNestedChain($rest)) {
            return null;
        }

        return [$first, $rest];
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

    private function isInlineExprCallArgConsumer(Op $op): bool
    {
        return $op instanceof Op\Expr\FuncCall
            || $op instanceof Op\Expr\NsFuncCall
            || $op instanceof Op\Expr\MethodCall
            || $op instanceof Op\Expr\StaticCall
            || $op instanceof Op\Expr\New_;
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
            || $op instanceof Op\Expr\Isset_
            || $op instanceof Op\Expr\InstanceOf_
            || $op instanceof Op\Expr\In_
            || $op instanceof Op\Expr\Cast
            || $op instanceof Op\Expr\MagicScriptConst
            || $op instanceof Op\Expr\Assign
            || $op instanceof Op\Expr\PostInc
            || $op instanceof Op\Expr\PreInc
            || $op instanceof Op\Expr\PostDec
            || $op instanceof Op\Expr\PreDec;
    }

    /**
     * php-cfg dead call-arg temps: inline producers immediately before the call (#8561, #4633).
     *
     * @param list<Op> $cfgChildren
     *
     * @return list<Op\Expr>
     */
    private function precedingInlineCallArgProducersBeforeCfgOp(array $cfgChildren, Op $callOp): array
    {
        $callIndex = null;
        foreach ($cfgChildren as $i => $child) {
            if ($child === $callOp) {
                $callIndex = $i;
                break;
            }
        }
        if (null === $callIndex) {
            return [];
        }
        $producers = [];
        for ($i = $callIndex - 1; $i >= 0; --$i) {
            $child = $cfgChildren[$i];
            if (!$child instanceof Op\Expr || !$this->isInlineExprCallArgProducer($child)) {
                break;
            }
            if (
                ($child instanceof Op\Expr\ArrowFunction
                    || $child instanceof Op\Expr\Closure
                    || $child instanceof Op\Expr\FirstClassCallable)
                && ($callOp instanceof Op\Expr\FuncCall || $callOp instanceof Op\Expr\NsFuncCall)
            ) {
                $calleeOperand = $callOp instanceof Op\Expr\NsFuncCall
                    ? ($callOp->nsName ?? null)
                    : ($callOp->name ?? null);
                if (
                    null !== $calleeOperand
                    && null !== $child->result
                    && ($calleeOperand === $child->result
                        || $this->operandsReferToSameVariable($calleeOperand, $child->result))
                ) {
                    continue;
                }
            }
            if (
                $child instanceof Op\Expr\ConstFetch
                || $child instanceof Op\Expr\ClassConstFetch
                || $child instanceof Op\Expr\New_
            ) {
                $next = $cfgChildren[$i + 1] ?? null;
                if (
                    (
                        $next instanceof Op\Expr\Array_
                        || $next instanceof Op\Expr\BinaryOp\BitwiseOr
                        || $next instanceof Op\Expr\BinaryOp\BitwiseAnd
                        || $next instanceof Op\Expr\BinaryOp\BitwiseXor
                    )
                    && $this->cfgExprUsesOperand($next, $child->result)
                ) {
                    // Hoisted operand inside sibling inline Array_ / bitmask call arg (#10612, #11304, #11387).
                    continue;
                }
                if (
                    $child instanceof Op\Expr\ConstFetch
                    && ($next instanceof Op\Expr\UnaryMinus || $next instanceof Op\Expr\UnaryPlus)
                    && $next->expr === $child->result
                ) {
                    continue;
                }
            }
            // php-cfg `var_export(substr(..., -2), true)` — UnaryMinus feeds sibling FuncCall arg, not consumer (#10373).
            if ($child instanceof Op\Expr\UnaryMinus
                || $child instanceof Op\Expr\UnaryPlus
                || $child instanceof Op\Expr\BitwiseNot
                || $child instanceof Op\Expr\BooleanNot
            ) {
                $next = $cfgChildren[$i + 1] ?? null;
                if (
                    ($next instanceof Op\Expr\FuncCall || $next instanceof Op\Expr\NsFuncCall)
                    && (
                        $this->isSiblingMultiArgFuncCallProducer($next, $callOp, $i + 1, $callIndex, $cfgChildren)
                        || $this->isNestedCallArgProducerForConsumer($next, $callOp, $i + 1, $callIndex, $cfgChildren)
                        || $this->isAdjacentNestedFuncCallProducer($next, $callOp, $i + 1, $callIndex)
                    )
                ) {
                    continue;
                }
            }
            if ($child instanceof Op\Expr\Assign) {
                if (!property_exists($callOp, 'args') || !is_array($callOp->args)) {
                    break;
                }
                $assignFeedsCallArg = false;
                foreach ($callOp->args as $callArg) {
                    if (
                        $this->operandsReferToSameVariable($child->var, $callArg)
                        || $this->operandsReferToSameVariable($child->result, $callArg)
                    ) {
                        $assignFeedsCallArg = true;
                        break;
                    }
                }
                if (!$assignFeedsCallArg) {
                    break;
                }
                // Prior `$a = [...]; f($a, …)` — not an inline producer for this call (#10579).
                if ($i < $callIndex - 1) {
                    break;
                }
            }
            if (
                ($child instanceof Op\Expr\FuncCall || $child instanceof Op\Expr\NsFuncCall)
                && !$this->inlineCallArgProducerFeedsConsumer($child, $callOp)
                && !$this->isNestedCallArgProducerForConsumer($child, $callOp, $i, $callIndex, $cfgChildren)
                && !$this->isSiblingMultiArgFuncCallProducer($child, $callOp, $i, $callIndex, $cfgChildren)
            ) {
                break;
            }
            if (
                ($child instanceof Op\Expr\FuncCall || $child instanceof Op\Expr\NsFuncCall)
                && $this->isNestedCallArgProducerForConsumer($child, $callOp, $i, $callIndex, $cfgChildren)
                && property_exists($callOp, 'args')
                && is_array($callOp->args)
            ) {
                array_unshift($producers, $child);
                break;
            }
            if (
                ($child instanceof Op\Expr\FuncCall || $child instanceof Op\Expr\NsFuncCall)
                && property_exists($callOp, 'args')
                && is_array($callOp->args)
                && count($callOp->args) >= 2
                && $this->isNestedCallArgProducerSeparatedByConsumerLiteralPreludes(
                    $child,
                    $callOp,
                    $i,
                    $callIndex,
                    $cfgChildren
                )
            ) {
                array_unshift($producers, $child);
                break;
            }
            if ($child instanceof Op\Expr\Array_) {
                $next = $cfgChildren[$i + 1] ?? null;
                // php-cfg `var_export(array_keys([...]), true)` — Array_ feeds sibling FuncCall arg (#10373).
                if (
                    ($next instanceof Op\Expr\FuncCall || $next instanceof Op\Expr\NsFuncCall)
                    && $this->isSiblingMultiArgFuncCallProducer($next, $callOp, $i + 1, $callIndex, $cfgChildren)
                ) {
                    continue;
                }
                // php-cfg `var_export(array_pad([...], -3, 0), true)` — Array_ + UnaryMinus feed nested sibling FuncCall (#10351).
                if ($next instanceof Op\Expr\UnaryMinus || $next instanceof Op\Expr\UnaryPlus) {
                    $afterUnary = $cfgChildren[$i + 2] ?? null;
                    if (
                        ($afterUnary instanceof Op\Expr\FuncCall || $afterUnary instanceof Op\Expr\NsFuncCall)
                        && (
                            $this->isSiblingMultiArgFuncCallProducer($afterUnary, $callOp, $i + 2, $callIndex, $cfgChildren)
                            || $this->isNestedCallArgProducerForConsumer($afterUnary, $callOp, $i + 2, $callIndex, $cfgChildren)
                            || $this->isAdjacentNestedFuncCallProducer($afterUnary, $callOp, $i + 2, $callIndex)
                        )
                    ) {
                        continue;
                    }
                }
                array_unshift($producers, $child);
                $prev = $cfgChildren[$i - 1] ?? null;
                // array_map(fn(...), [...]) / preg_replace_callback($pat, fn(...), $arr) — Closure/FCC before Array_ (#10651, #10652, #11450).
                if ($prev instanceof Op\Expr\Closure
                    || $prev instanceof Op\Expr\ArrowFunction
                    || $prev instanceof Op\Expr\FirstClassCallable) {
                    array_unshift($producers, $prev);
                    break;
                }
                // php-cfg: `invokeArgs(new C(), [...])` — New_ immediately precedes Array_ (#9904).
                if ($prev instanceof Op\Expr\New_) {
                    if ($this->cfgExprUsesOperand($child, $prev->result)) {
                        // New_ is an inline array element — keep walking for closure siblings (#11304).
                        continue;
                    }
                    array_unshift($producers, $prev);
                    break;
                }
                // Sibling inline Array_ call args: `array_replace([...], [...])` (#10231).
                // Nested element literals (`array_column([[...], [...]], ...)`) are not call-arg producers (#9305).
                if ($prev instanceof Op\Expr\Array_) {
                    if ($this->cfgExprUsesOperand($child, $prev->result)) {
                        // Inner→outer nesting within one inline call arg (#10196, #10662); keep walking for siblings.
                        continue;
                    }
                    array_unshift($producers, $prev);

                    break;
                }
                // password_hash(lit, PASSWORD_BCRYPT, [...]) — ConstFetch before trailing Array_ (#10453).
                if ($prev instanceof Op\Expr\ConstFetch || $prev instanceof Op\Expr\ClassConstFetch) {
                    if ($this->cfgExprUsesOperand($child, $prev->result)) {
                        $grandPrev = $cfgChildren[$i - 2] ?? null;
                        if ($grandPrev instanceof Op\Expr\Array_) {
                            continue;
                        }
                        break;
                    }
                    if ($this->isInlineExprCallArgProducer($prev)) {
                        array_unshift($producers, $prev);
                    }
                    break;
                }
                // call_user_func_array(C::class.'::ok', []) — Concat feeds arg #0, Array_ is arg #1 (#11694).
                if ($prev instanceof Op\Expr\BinaryOp\Concat) {
                    $feedsCallArg = false;
                    if (property_exists($callOp, 'args') && is_array($callOp->args)) {
                        foreach ($callOp->args as $callArg) {
                            if ($this->operandsReferToSameVariable($prev->result, $callArg)) {
                                $feedsCallArg = true;
                                break;
                            }
                        }
                    }
                    if ($feedsCallArg) {
                        array_unshift($producers, $prev);
                    }
                }
                break;
            }
            array_unshift($producers, $child);
        }

        return $this->filterDeadVoidStatementMethodCallProducers($producers, $callOp, $cfgChildren);
    }

    /**
     * Drop void statement MethodCalls before a sibling MethodCall inline call-arg (#10778).
     *
     * php-cfg: `$ao->setIteratorClass('X'); echo var_export($ao->getIteratorClass(), true);`
     * hoists both MethodCalls; the void setter must not map to var_export arg 0.
     *
     * Sibling `var_dump($o->f(), $o->f())` also hoists dead-temp MethodCalls — keep those
     * inside the inline-arg distance window (#10816, #9351).
     *
     * @param list<Op\Expr> $producers
     * @param list<Op>       $cfgChildren
     *
     * @return list<Op\Expr>
     */
    private function filterDeadVoidStatementMethodCallProducers(array $producers, Op $callOp, array $cfgChildren): array
    {
        if (\count($producers) < 2) {
            return $producers;
        }
        $consumerIndex = null;
        foreach ($cfgChildren as $i => $child) {
            if ($child === $callOp) {
                $consumerIndex = $i;
                break;
            }
        }
        $tempArgCount = 0;
        if (property_exists($callOp, 'args') && is_array($callOp->args)) {
            foreach ($callOp->args as $arg) {
                if ($arg instanceof Operand\Temporary) {
                    ++$tempArgCount;
                }
            }
        }
        if ($tempArgCount < 1 && property_exists($callOp, 'args') && is_array($callOp->args)) {
            $tempArgCount = \count($callOp->args);
        }
        $filtered = [];
        $count = \count($producers);
        for ($i = 0; $i < $count; ++$i) {
            $producer = $producers[$i];
            if (
                $producer instanceof Op\Expr\MethodCall
                && property_exists($producer, 'result')
                && empty($producer->result->usages)
                && !$this->methodCallInlineProducerSuppliesCallArgValue($producer)
                && $i + 1 < $count
                && $producers[$i + 1] instanceof Op\Expr\MethodCall
            ) {
                if (null !== $consumerIndex) {
                    $producerIndex = null;
                    foreach ($cfgChildren as $pi => $child) {
                        if ($child === $producer) {
                            $producerIndex = $pi;
                            break;
                        }
                    }
                    $distance = null !== $producerIndex ? $consumerIndex - $producerIndex : null;
                    if (null !== $distance && $distance <= $tempArgCount) {
                        $filtered[] = $producer;

                        continue;
                    }
                }

                continue;
            }
            $filtered[] = $producer;
        }

        return $filtered;
    }

    /**
     * php-cfg dead temps for inline call args keep inferred value types (#9351, #10816);
     * void statement calls stay inferred:unknown (#10778).
     */
    private function methodCallInlineProducerSuppliesCallArgValue(Op\Expr\MethodCall $producer): bool
    {
        if (!property_exists($producer, 'result')) {
            return false;
        }
        $type = $producer->result->type ?? null;
        if (null === $type) {
            return true;
        }
        if ($type instanceof \PHPTypes\Type) {
            return \PHPTypes\Type::TYPE_UNKNOWN !== $type->type;
        }
        if ($type instanceof Op\Type\Literal) {
            $name = strtolower((string) ($type->name ?? ''));
            if (str_starts_with($name, 'inferred:')) {
                $inner = substr($name, 9);

                return 'unknown' !== $inner && 'void' !== $inner;
            }

            return 'void' !== $name && 'never' !== $name;
        }

        return true;
    }

    /**
     * php-cfg `f(g())` may lower to adjacent FuncCalls with distinct result/arg temporaries
     * (`strlen(trim($s))` → trim #6, strlen arg #7) (#8561, bootstrap-aot trim).
     *
     * Also `(fn($x) => ...)(g())` where php-cfg inserts the closure callee between nested calls (#8836).
     *
     * @param list<Op> $cfgChildren
     */
    private function isNestedCallArgProducerForConsumer(
        Op\Expr $producer,
        Op $consumer,
        int $producerIndex,
        int $consumerIndex,
        array $cfgChildren
    ): bool {
        if ($this->isAdjacentNestedFuncCallProducer($producer, $consumer, $producerIndex, $consumerIndex)) {
            return true;
        }
        if ($this->isNestedCallArgProducerSeparatedByConsumerLiteralPreludes(
            $producer,
            $consumer,
            $producerIndex,
            $consumerIndex,
            $cfgChildren
        )) {
            return true;
        }
        if ($producerIndex + 2 !== $consumerIndex) {
            return false;
        }
        if (
            !$producer instanceof Op\Expr\FuncCall
            && !$producer instanceof Op\Expr\NsFuncCall
        ) {
            return false;
        }
        if (
            !$consumer instanceof Op\Expr\FuncCall
            && !$consumer instanceof Op\Expr\NsFuncCall
        ) {
            return false;
        }
        $callee = $cfgChildren[$producerIndex + 1] ?? null;
        if (!$callee instanceof Op\Expr\ArrowFunction && !$callee instanceof Op\Expr\Closure) {
            return false;
        }
        if (!property_exists($consumer, 'name')) {
            return false;
        }

        return $consumer->name === $callee->result
            || $this->operandsReferToSameVariable($consumer->name, $callee->result);
    }

    /**
     * php-cfg `var_export(g(), true)` hoists trailing literal ConstFetch between nested calls (#10495).
     *
     * @param list<Op> $cfgChildren
     */
    private function isNestedCallArgProducerSeparatedByConsumerLiteralPreludes(
        Op\Expr $producer,
        Op $consumer,
        int $producerIndex,
        int $consumerIndex,
        array $cfgChildren
    ): bool {
        if ($producerIndex >= $consumerIndex - 1) {
            return false;
        }
        if (
            !$producer instanceof Op\Expr\FuncCall
            && !$producer instanceof Op\Expr\NsFuncCall
            && !$producer instanceof Op\Expr\StaticCall
            && !$producer instanceof Op\Expr\MethodCall
        ) {
            return false;
        }
        if (
            !$consumer instanceof Op\Expr\FuncCall
            && !$consumer instanceof Op\Expr\NsFuncCall
            && !$consumer instanceof Op\Expr\MethodCall
            && !$consumer instanceof Op\Expr\StaticCall
            && !$consumer instanceof Op\Expr\New_
        ) {
            return false;
        }
        if (!property_exists($consumer, 'args') || !is_array($consumer->args) || count($consumer->args) < 2) {
            return false;
        }
        $argCount = \count($consumer->args);
        $literalPreludeCount = 0;
        for ($j = $producerIndex + 1; $j < $consumerIndex; ++$j) {
            $mid = $cfgChildren[$j] ?? null;
            if ($mid instanceof Op\Expr\ConstFetch || $mid instanceof Op\Expr\ClassConstFetch) {
                ++$literalPreludeCount;
                continue;
            }
            if (
                $mid instanceof Op\Expr\FuncCall
                || $mid instanceof Op\Expr\NsFuncCall
                || $mid instanceof Op\Expr\StaticCall
                || $mid instanceof Op\Expr\MethodCall
            ) {
                return false;
            }

            return false;
        }
        $targetArgIndex = $this->siblingMultiArgFuncCallProducerTargetArgIndex(
            $producerIndex,
            $consumerIndex,
            $cfgChildren
        );
        if (null === $targetArgIndex) {
            $targetArgIndex = 0;
            while (
                $targetArgIndex < $argCount
                && ($consumer->args[$targetArgIndex] ?? null) instanceof Operand\Literal
            ) {
                ++$targetArgIndex;
            }
        }
        $targetArg = $consumer->args[$targetArgIndex] ?? null;
        if (!$this->callArgIsDeadInlineTemporary($targetArg)) {
            return false;
        }
        // Trailing hoisted literal preludes only (e.g. var_export(g(), true), in_array('x', g(), true);
        // statement-level calls before multiple hoisted ConstFetch args must not match (#11312, #11373).
        if ($literalPreludeCount !== $argCount - 1 - $targetArgIndex) {
            // var_export(in_array(..., true), true) — nested producer feeds arg0, not arg1 (#11399).
            if (
                0 !== $targetArgIndex
                && $literalPreludeCount > 0
                && null === $this->firstSiblingInlineFuncCallProducerIndex($consumerIndex, $cfgChildren)
                && $this->callArgIsDeadInlineTemporary($consumer->args[0] ?? null)
                && $literalPreludeCount === $argCount - 1
            ) {
                $targetArgIndex = 0;
                if ($literalPreludeCount !== $argCount - 1 - $targetArgIndex) {
                    return false;
                }
            } else {
                return false;
            }
        }

        return true;
    }

    /**
     * php-cfg `var_dump($g(), $g())` hoists sibling FuncCall stmts before the consumer (#9463, #10981).
     * Skip eager compileOps lowering so each producer gets its own EXEC_RETURN slot at the consumer.
     *
     * @param Op[] $ops
     */
    private function isDeferredSiblingInlineCallArgProducer(Op $op, array $ops, int $producerIndex): bool
    {
        $consumerIndex = $this->deferredSiblingInlineCallArgConsumerIndex($op, $ops, $producerIndex);
        if (null === $consumerIndex) {
            return false;
        }
        $firstSibling = $this->firstSiblingInlineFuncCallProducerIndex($consumerIndex, $ops);
        if (null === $firstSibling) {
            return false;
        }

        return ($consumerIndex - $firstSibling) >= 2;
    }

    /**
     * @param Op[] $ops
     */
    private function deferredSiblingInlineCallArgConsumerIndex(Op $op, array $ops, int $producerIndex): ?int
    {
        if (!$op instanceof Op\Expr\FuncCall && !$op instanceof Op\Expr\NsFuncCall) {
            return null;
        }
        $opCount = \count($ops);
        for ($j = $producerIndex + 1; $j < $opCount; ++$j) {
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
                    )
                ) {
                    return $j;
                }

                continue;
            }
            if ($this->isUnaryInlineSiblingCallArgExpr($next)) {
                continue;
            }
            break;
        }

        return null;
    }

    /**
     * php-cfg `var_dump($g(), $g())` hoists sibling FuncCall producers with dead arg temps (#9463).
     *
     * @param list<Op> $cfgChildren
     */
    private function isSiblingMultiArgFuncCallProducer(
        Op\Expr $producer,
        Op $consumer,
        int $producerIndex,
        int $consumerIndex,
        array $cfgChildren
    ): bool {
        if (
            !$producer instanceof Op\Expr\FuncCall
            && !$producer instanceof Op\Expr\NsFuncCall
        ) {
            return false;
        }
        if (
            !$consumer instanceof Op\Expr\FuncCall
            && !$consumer instanceof Op\Expr\NsFuncCall
        ) {
            return false;
        }
        if (!property_exists($consumer, 'args') || !is_array($consumer->args)) {
            return false;
        }
        if ($this->callIncludesNamedParameter($consumer)) {
            return false;
        }
        $argCount = count($consumer->args);
        if ($argCount < 2) {
            return false;
        }
        // Statement-level calls before fscanf($f, '…') are not sibling arg producers (#11093).
        foreach ($consumer->args as $consumerArg) {
            if ($consumerArg instanceof Operand && $this->isNamedVariableOperand($consumerArg)) {
                return false;
            }
        }
        $distance = $consumerIndex - $producerIndex;
        if ($distance < 1 || $distance > $argCount) {
            return false;
        }
        $targetArgIndex = $this->siblingMultiArgFuncCallProducerTargetArgIndex(
            $producerIndex,
            $consumerIndex,
            $cfgChildren
        );
        if (null === $targetArgIndex) {
            return false;
        }
        // Producer at distance d supplies consumer arg d-1; UnaryMinus prelude shifts arg 0 (#10673).
        $targetArg = $consumer->args[$targetArgIndex] ?? null;
        if (!$this->callArgIsDeadInlineTemporary($targetArg)) {
            return false;
        }
        for ($j = $producerIndex + 1; $j < $consumerIndex; ++$j) {
            $mid = $cfgChildren[$j] ?? null;
            if (
                $mid instanceof Op\Expr\ConstFetch
                || $mid instanceof Op\Expr\ClassConstFetch
            ) {
                continue;
            }
            if ($this->isUnaryInlineSiblingCallArgExpr($mid)) {
                if (2 === $distance && $j === $producerIndex + 1) {
                    continue;
                }

                return false;
            }
            if (
                !$mid instanceof Op\Expr\FuncCall
                && !$mid instanceof Op\Expr\NsFuncCall
            ) {
                return false;
            }
        }

        return true;
    }

    private function isUnaryInlineSiblingCallArgExpr(?Op $op): bool
    {
        return $op instanceof Op\Expr\UnaryMinus
            || $op instanceof Op\Expr\UnaryPlus
            || $op instanceof Op\Expr\BitwiseNot
            || $op instanceof Op\Expr\BooleanNot;
    }

    /**
     * @param list<Op> $cfgChildren
     */
    private function siblingMultiArgFuncCallProducerTargetArgIndex(
        int $producerIndex,
        int $consumerIndex,
        array $cfgChildren
    ): ?int {
        $distance = $consumerIndex - $producerIndex;
        if ($distance < 1) {
            return null;
        }
        $mid = $cfgChildren[$producerIndex + 1] ?? null;
        if (2 === $distance && $this->isUnaryInlineSiblingCallArgExpr($mid)) {
            return 0;
        }
        $firstSibling = $this->firstSiblingInlineFuncCallProducerIndex($consumerIndex, $cfgChildren);
        if (null === $firstSibling) {
            return $distance - 1;
        }
        if ($producerIndex < $firstSibling || $producerIndex >= $consumerIndex) {
            return null;
        }

        return $producerIndex - $firstSibling;
    }

    /**
     * First hoisted FuncCall in a sibling inline call-arg chain ending at {@see $consumerIndex}.
     *
     * @param list<Op> $cfgChildren
     */
    private function firstSiblingInlineFuncCallProducerIndex(int $consumerIndex, array $cfgChildren): ?int
    {
        $i = $consumerIndex - 1;
        while ($i >= 0) {
            $child = $cfgChildren[$i] ?? null;
            if ($child instanceof Op\Expr\FuncCall || $child instanceof Op\Expr\NsFuncCall) {
                --$i;
                continue;
            }
            if ($this->isUnaryInlineSiblingCallArgExpr($child) && $i > 0) {
                $before = $cfgChildren[$i - 1] ?? null;
                if ($before instanceof Op\Expr\FuncCall || $before instanceof Op\Expr\NsFuncCall) {
                    --$i;
                    continue;
                }
            }
            break;
        }
        $first = $i + 1;
        if ($first >= $consumerIndex) {
            return null;
        }
        $firstChild = $cfgChildren[$first] ?? null;
        if (!$firstChild instanceof Op\Expr\FuncCall && !$firstChild instanceof Op\Expr\NsFuncCall) {
            return null;
        }

        return $first;
    }

    /**
     * php-cfg `var_dump($g(), $g())` hoists sibling FuncCall stmts before the consumer (#9463, #10981).
     * Compile each producer once with its own EXEC_RETURN slot before ARG_SEND wiring.
     */
    private function ensureDeferredSiblingInlineCallArgProducersCompiled(Block $block, Op $cfgCallOp): void
    {
        if (null === $block->orig || !property_exists($cfgCallOp, 'args') || !is_array($cfgCallOp->args)) {
            return;
        }
        $argCount = \count($cfgCallOp->args);
        if ($argCount < 2) {
            return;
        }
        $callIndex = null;
        foreach ($block->orig->children as $i => $child) {
            if ($child === $cfgCallOp) {
                $callIndex = $i;
                break;
            }
        }
        if (null === $callIndex) {
            return;
        }
        $firstSibling = $this->firstSiblingInlineFuncCallProducerIndex($callIndex, $block->orig->children);
        if (null === $firstSibling) {
            return;
        }
        $siblingCount = $callIndex - $firstSibling;
        if ($siblingCount < 2) {
            return;
        }
        for ($argIndex = 0; $argIndex < $siblingCount; ++$argIndex) {
            $emitOps = [];
            $slot = $this->resolveSiblingInlineCallArgProducerSlot(
                $block,
                $cfgCallOp,
                $argIndex,
                $emitOps
            );
            if (null === $slot && [] === $emitOps) {
                continue;
            }
            foreach ($emitOps as $op) {
                $block->addOpCode($op);
            }
        }
    }

    /**
     * php-cfg `f(g(), h())` — map arg N to the Nth sibling hoisted FuncCall producer (#9463, #10917).
     */
    private function resolveSiblingInlineCallArgProducerSlot(
        Block $block,
        Op $cfgCallOp,
        int $argIndex,
        array &$emitOps = []
    ): ?int {
        if (null === $block->orig || !property_exists($cfgCallOp, 'args') || !is_array($cfgCallOp->args)) {
            return null;
        }
        $argCount = \count($cfgCallOp->args);
        if ($argCount < 2 || $argIndex >= $argCount) {
            return null;
        }
        $callIndex = null;
        foreach ($block->orig->children as $i => $child) {
            if ($child === $cfgCallOp) {
                $callIndex = $i;
                break;
            }
        }
        if (null === $callIndex) {
            return null;
        }
        $firstSibling = $this->firstSiblingInlineFuncCallProducerIndex($callIndex, $block->orig->children);
        if (null === $firstSibling) {
            return null;
        }
        $siblingCount = $callIndex - $firstSibling;
        if ($siblingCount < 2 || $argIndex >= $siblingCount) {
            return null;
        }
        $producerIndex = $firstSibling + $argIndex;
        $producer = $block->orig->children[$producerIndex] ?? null;
        if (!$producer instanceof Op\Expr\FuncCall && !$producer instanceof Op\Expr\NsFuncCall) {
            return null;
        }
        if (!$this->isSiblingMultiArgFuncCallProducer(
            $producer,
            $cfgCallOp,
            $producerIndex,
            $callIndex,
            $block->orig->children
        )) {
            return null;
        }
        if ($this->siblingMultiArgFuncCallProducerTargetArgIndex(
            $producerIndex,
            $callIndex,
            $block->orig->children
        ) !== $argIndex) {
            return null;
        }
        if (null === $block->slotForOperand($producer->result)) {
            $prevForce = $this->forceDeferredSiblingCallReturnSlot;
            $this->forceDeferredSiblingCallReturnSlot = true;
            try {
                foreach ($this->compileExpr($producer, $block) as $op) {
                    $emitOps[] = $op;
                }
            } finally {
                $this->forceDeferredSiblingCallReturnSlot = $prevForce;
            }
        }

        return $block->slotForOperand($producer->result);
    }

    /**
     * strtotime('next Monday', strtotime('...')) — adjacent nested FuncCall feeds trailing arg (#10838).
     */
    private function resolveAdjacentNestedFuncCallArgSlot(
        Block $block,
        Op $cfgCallOp,
        int $argIndex
    ): ?string {
        if (null === $block->orig || !property_exists($cfgCallOp, 'args') || !is_array($cfgCallOp->args)) {
            return null;
        }
        $args = $cfgCallOp->args;
        if (1 === \count($args) && 0 === $argIndex) {
            // var_export(f()) — adjacent nested FuncCall feeds the sole call arg (#11373).
        } elseif (\count($args) < 2 || $argIndex !== \count($args) - 1) {
            return null;
        }
        $callIndex = null;
        foreach ($block->orig->children as $i => $child) {
            if ($child === $cfgCallOp) {
                $callIndex = $i;
                break;
            }
        }
        if (null === $callIndex || $callIndex < 1) {
            return null;
        }
        $prev = $block->orig->children[$callIndex - 1] ?? null;
        if (
            !($prev instanceof Op\Expr\FuncCall || $prev instanceof Op\Expr\NsFuncCall)
            || !$this->isNestedCallArgProducerForConsumer(
                $prev,
                $cfgCallOp,
                $callIndex - 1,
                $callIndex,
                $block->orig->children
            )
        ) {
            return null;
        }
        $leadingArg = $args[0] ?? null;
        if (
            null !== $leadingArg
            && $this->operandsReferToSameVariable($prev->result, $leadingArg)
        ) {
            return null;
        }
        if (null === $block->slotForOperand($prev->result)) {
            foreach ($this->compileExpr($prev, $block) as $op) {
                $block->addOpCode($op);
            }
        }
        $slot = $block->slotForOperand($prev->result);

        return null !== $slot ? (string) $slot : null;
    }

    private function isAdjacentNestedFuncCallProducer(
        Op\Expr $producer,
        Op $consumer,
        int $producerIndex,
        int $consumerIndex
    ): bool {
        if ($producerIndex !== $consumerIndex - 1) {
            return false;
        }
        if (
            !$producer instanceof Op\Expr\FuncCall
            && !$producer instanceof Op\Expr\NsFuncCall
            && !$producer instanceof Op\Expr\StaticCall
            && !$producer instanceof Op\Expr\MethodCall
        ) {
            return false;
        }
        if (
            !$consumer instanceof Op\Expr\FuncCall
            && !$consumer instanceof Op\Expr\NsFuncCall
            && !$consumer instanceof Op\Expr\MethodCall
            && !$consumer instanceof Op\Expr\StaticCall
            && !$consumer instanceof Op\Expr\New_
        ) {
            return false;
        }
        if (!property_exists($consumer, 'args') || !is_array($consumer->args) || [] === $consumer->args) {
            return false;
        }
        $args = $consumer->args;
        if (1 === count($args)) {
            return $this->callArgIsDeadInlineTemporary($args[0] ?? null);
        }
        // php-cfg `f(g(), literal)` — adjacent producer feeds arg0 (#10402, levenshtein(str_repeat(...), 'b')).
        // php-cfg `f($named, g())` — producer feeds last arg (#11409, chown($path, getmyuid())).
        $firstArg = $args[0] ?? null;
        if ($this->callArgIsDeadInlineTemporary($firstArg)) {
            return true;
        }
        $lastArg = $args[count($args) - 1];

        return $this->callArgIsDeadInlineTemporary($lastArg);
    }

    private function isNamedVariableOperand(Operand $arg): bool
    {
        $name = Block::resolveVariableName($arg);
        if (null !== $name && '' !== $name) {
            return true;
        }

        return $arg instanceof Operand\Variable
            && $arg->name instanceof Operand\Literal
            && is_string($arg->name->value)
            && '' !== $arg->name->value;
    }

    /**
     * php-cfg dead multi-arg temps with no dataflow to hoisted producers (#9463, #9351).
     *
     * @param list<Operand> $callArgs
     */
    private function callArgsAreDistinctInlineTemporaries(array $callArgs): bool
    {
        if (count($callArgs) < 2) {
            return false;
        }
        foreach ($callArgs as $callArg) {
            if (!$this->callArgIsDeadInlineTemporary($callArg)) {
                return false;
            }
        }

        return true;
    }

    /** php-cfg dead call-arg slot — Temporary or unnamed inferred Variable wrapper (#10917). */
    private function callArgIsDeadInlineTemporary(?Operand $arg): bool
    {
        if (null === $arg) {
            return false;
        }
        if ($arg instanceof Operand\Temporary) {
            return true;
        }

        return $arg instanceof Operand\Variable && !$this->isNamedVariableOperand($arg);
    }

    /**
     * Hoisted producer ordinal among dead inline call-arg temps (skip embedded literals, #10321).
     *
     * @param list<Operand> $callArgs
     */
    private function inlineHoistedProducerSlotIndexForCallArg(array $callArgs, int $argIndex): ?int
    {
        $callArg = $callArgs[$argIndex] ?? null;
        if (null === $callArg || !$this->callArgIsDeadInlineTemporary($callArg)) {
            return null;
        }
        $slot = 0;
        for ($i = 0; $i < $argIndex; ++$i) {
            $arg = $callArgs[$i] ?? null;
            if (null === $arg || $this->isEmbeddedCallLiteralArg($arg)) {
                continue;
            }
            if ($this->callArgIsDeadInlineTemporary($arg)) {
                ++$slot;
            }
        }

        return $slot;
    }

    /**
     * php-cfg hoists inline Expr_Array / ConstFetch siblings before FuncCall — map arg index to producer (#11591, #10321).
     *
     * @param list<Op>        $cfgChildren
     * @param list<Op\Expr>   $producers
     */
    private function inlineHoistedProducerForCallArgIndex(
        Op $callOp,
        int $argIndex,
        array $producers,
        array $cfgChildren
    ): ?Op\Expr {
        if (!property_exists($callOp, 'args') || !is_array($callOp->args)) {
            return null;
        }
        $producerSlotIndex = $this->inlineHoistedProducerSlotIndexForCallArg($callOp->args, $argIndex);
        if (null === $producerSlotIndex) {
            return null;
        }
        $callIndex = null;
        foreach ($cfgChildren as $i => $child) {
            if ($child === $callOp) {
                $callIndex = $i;
                break;
            }
        }
        if (null === $callIndex) {
            return null;
        }
        $producerCount = \count($producers);
        if ($producerCount < 1 || $producerSlotIndex >= $producerCount) {
            return null;
        }
        $cfgProducerIndex = $callIndex - $producerCount + $producerSlotIndex;
        if ($cfgProducerIndex < 0 || $cfgProducerIndex >= $callIndex) {
            return null;
        }
        $candidate = $cfgChildren[$cfgProducerIndex] ?? null;
        if (!$candidate instanceof Op\Expr || !\in_array($candidate, $producers, true)) {
            return null;
        }

        return $candidate;
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
        int $argIndex
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
        // array_filter($a, fn(...), ARRAY_FILTER_USE_*) — callback is first dead-temp slot (#10232, #9154).
        if (\count($callArgs) >= 3 && \count($closureSlots) >= 1 && $argIndex === $closureSlots[0]) {
            return $producer;
        }
        // array_map(fn(...), $arr) — callback is arg 0 (#10651).
        if (2 === \count($callArgs) && \count($closureSlots) >= 1 && 0 === $argIndex && 0 === $closureSlots[0]) {
            return $producer;
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
        $producers = $this->precedingInlineCallArgProducersBeforeCfgOp($block->orig->children, $cfgCallOp);
        $argCount = \count($cfgCallOp->args);
        if (\count($producers) === $argCount && isset($producers[$argIndex])) {
            return $producers[$argIndex] instanceof Op\Expr\New_;
        }

        $matched = $this->matchInlineCallArgProducer($producers, $cfgCallOp->args, $argIndex, $cfgCallOp, $block);

        return $matched instanceof Op\Expr\New_;
    }

    /** Slot for hoisted inline `new` when php-cfg dead temps omit result→slot mapping (#11321). */
    private function slotForInlineNewProducer(Block $block, Op\Expr\New_ $new): ?string
    {
        $slot = $block->slotForOperand($new->result);
        if (null !== $slot) {
            return (string) $slot;
        }
        for ($i = \count($block->opCodes) - 1; $i >= 0; --$i) {
            $op = $block->opCodes[$i];
            if (OpCode::TYPE_NEW === $op->type) {
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
        foreach ($block->orig->children as $child) {
            if ($child instanceof Op\Expr\PropertyFetch && $child->result === $result) {
                return $child;
            }
        }

        return null;
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
     * VarLikeIdentifier `Class::$name` is a declared static property when `$name` exists on the class;
     * otherwise evaluate the variable `$name` for a runtime property name (Zend zend_compile.c, #3814).
     */
    protected function compileStaticPropertyNameSlot(Operand $name, Operand $class, Block $block): int
    {
        $literalName = $this->staticNameFromOperand($name);
        if (null !== $literalName) {
            $lcProp = strtolower($literalName);
            $className = $this->staticPropertyClassIsObjectExpression($class)
                ? null
                : $this->literalScopeClassName($class);
            if (null !== $className) {
                $lcClass = strtolower(ltrim($className, '\\'));
                // self::/static::/parent:: with a literal member — property name, not a local (#4668).
                if (in_array($lcClass, ['self', 'static', 'parent'], true)) {
                    return $this->compileOperand($name, $block, true);
                }
                if (isset($this->compiledClassStaticProperties[$lcClass][$lcProp])) {
                    return $this->compileOperand($name, $block, true);
                }
            } elseif ($this->staticPropertyClassIsObjectExpression($class)) {
                // (new Class()) / $obj — literal member is a declared property name, not $local (#5477).
                return $this->compileOperand($name, $block, true);
            }
            if (
                null !== $this->compilingClassLc
                && isset($this->compiledClassStaticProperties[$this->compilingClassLc][$lcProp])
            ) {
                return $this->compileOperand($name, $block, true);
            }
            $varOperand = new CfgVariable(new Literal($literalName));

            return $this->compileOperand($varOperand, $block, true);
        }

        return $this->compileOperand($name, $block, true);
    }

    /**
     * ?: echo merge phi must not share a slot with method-name literals (#3790, #5506).
     */
    private function freshLiteralConstantSlot(Operand $operand, Block $block): int
    {
        if (!$operand instanceof Operand\Literal) {
            return $block->forceFreshVarSlot($operand);
        }
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
        $const = new Variable($mappedType);
        switch ($mappedType) {
            case Variable::TYPE_STRING:
                $const->string($operand->value);
                break;
            case Variable::TYPE_INTEGER:
                $const->int($operand->value);
                break;
            case Variable::TYPE_FLOAT:
                $const->float($operand->value);
                break;
            case Variable::TYPE_BOOLEAN:
                $const->bool($operand->value);
                break;
            case Variable::TYPE_NULL:
                break;
            default:
                $this->throwCompileLogic('Unknown Literal Operand Type: ' . ($operand->type ?? 'untyped'));
        }
        $slot = $block->forceFreshVarSlot($operand);
        $block->constants[$slot] = $const;

        return $slot;
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
                    $return->string($operand->value);
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

            return $block->getVarSlot($operand, $isRead);
        } elseif ($operand instanceof Operand\Temporary) {
            return $block->getVarSlot($operand, $isRead);
        }
        $this->throwCompileLogic("Unknown Operand Type: " . $operand->getType());
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
        if ($call instanceof Op\Expr\FuncCall && $call->name instanceof Operand\Literal) {
            return strtolower((string) $call->name->value);
        }
        if ($call instanceof Op\Expr\NsFuncCall && $call->name instanceof Operand\Literal) {
            return strtolower((string) $call->name->value);
        }

        return null;
    }

    /** Callback arg index for closure + inline Array_ hoists (array_map vs array_reduce, #10775). */
    private function inlineClosureArrayPairCallbackArgIndex(?string $funcName): int
    {
        if (in_array($funcName, [
            'array_all',
            'array_any',
            'array_find',
            'array_find_key',
            'array_reduce',
            'array_walk',
            'array_walk_recursive',
            'array_filter',
        ], true)) {
            return 1;
        }

        return 0;
    }

    /** php-cfg dead temps: inline FuncCall/New_/Array_ producer before a call (#8561, #4633). */
    private function callResultFeedsInlineCallArg(Operand $result, Block $block): bool
    {
        if (null === $block->orig) {
            return false;
        }
        foreach ($block->orig->children as $child) {
            if (!$this->isInlineExprCallArgConsumer($child)) {
                continue;
            }
            $producers = $this->precedingInlineCallArgProducersBeforeCfgOp($block->orig->children, $child);
            foreach ($producers as $producer) {
                if ($producer->result === $result || $this->operandsReferToSameVariable($producer->result, $result)) {
                    if ($this->inlineCallArgProducerPassesByRefGuards($producer, $child, $block->orig->children)) {
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
                if ($this->inlineCallArgProducerPassesByRefGuards($matched, $child, $block->orig->children)) {
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
            $producerIndex = array_search($producer, $cfgChildren, true);
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
        return true;
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
     * @return list<OpCode>
     */
    protected function compileTerminal(Op\Terminal $terminal, Block $block): array {
        switch ($terminal->getType()) {
            case 'Terminal_Echo':
                $concat = $this->unwrapConcatListExpr($terminal->expr);
                if (null !== $concat) {
                    $this->compileOp($concat, $block);
                    $var = $this->compileOperand($concat->result, $block, true);
                } else {
                    $this->compileEmbeddedExprForOperand($terminal->expr, $block);
                    $var = $this->compileOperand($terminal->expr, $block, true);
                }

                $line = $terminal->getLine();

                return [new OpCode(
                    OpCode::TYPE_ECHO,
                    $var,
                    $line > 0 ? $line : null
                )];
            case 'Terminal_Return':
                $returnLine = $terminal->getLine();
                $returnLineArg = $returnLine > 0 ? $returnLine : null;
                if ($block->returnTypeNever) {
                    if (!is_null($terminal->expr)) {
                        $this->throwCompileError('A never-returning function must not return');
                    }
                    if ($this->neverFunctionHasAbnormalExitBeforeReturn($block->orig, $terminal)) {
                        return [];
                    }
                    if ($this->neverFunctionReturnIsImplicitFalloff($terminal)) {
                        return [new OpCode(
                            OpCode::TYPE_RETURN_VOID,
                            $returnLineArg
                        )];
                    }
                    $this->throwCompileError('A never-returning function must not return');
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
                return [new OpCode(
                    OpCode::TYPE_ITER_RESET,
                    $this->compileOperand($terminal->var, $block, true)
                )];
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
                        $this->rejectGlobalConstInWriteContext($unsetExpr, $block);
                    }
                    $staticPropertyFetch = $unsetExpr instanceof Op\Expr\StaticPropertyFetch
                        ? $unsetExpr
                        : ($unsetExpr instanceof Operand ? $this->findStaticPropertyFetchForUnset($unsetExpr, $block) : null);
                    if (null !== $staticPropertyFetch) {
                        $ops[] = new OpCode(
                            OpCode::TYPE_STATIC_PROPERTY_UNSET,
                            null,
                            $this->compileOperand($staticPropertyFetch->class, $block, true),
                            $this->compileStaticPropertyNameSlot(
                                $staticPropertyFetch->name,
                                $staticPropertyFetch->class,
                                $block
                            )
                        );
                        continue;
                    }
                    [$containerSlot, $dimSlot] = $this->resolveUnsetTarget($unsetExpr, $block);
                    $ops[] = new OpCode(
                        OpCode::TYPE_UNSET,
                        null,
                        $containerSlot,
                        $dimSlot
                    );
                }

                return $ops;
            case 'Terminal_GlobalVar':
                $globalName = $this->resolveSimpleVariableName($terminal->var);
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
            default:
                $this->throwCompileLogic("Unknown Terminal Type: " . $terminal->getType());
        }
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

        return [new OpCode(
            OpCode::TYPE_INSTANCEOF,
            $this->compileOperand($expr->result, $block, false),
            $this->compileOperand($expr->expr, $block, true),
            $this->compileOperand($expr->class, $block, true)
        )];
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
        $constName = $this->staticNameFromOperand($expr->name);
        $className = $this->staticNameFromOperand($expr->class);
        if (null !== $constName && null !== $className) {
            $lcClass = $this->resolveDefaultClassConstScope($className, $block);
            if (null !== $lcClass
                && $this->isCompileTimeEnumCaseConstantMember($lcClass, strtolower($constName))) {
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
        $constName = $this->staticNameFromOperand($expr->name);
        $className = $this->staticNameFromOperand($expr->class);
        if (null !== $constName
            && 'class' === strtolower($constName)
            && null !== $className
            && !$this->pseudoClassInCompileScope($className, $block)) {
            $this->throwCompileError(
                'Cannot use "'.strtolower($className).'" in the global scope'
            );
        }
        $op = new OpCode(
            OpCode::TYPE_CLASS_CONST_FETCH,
            $this->compileOperand($destOperand, $block, false),
            $this->compileOperand($expr->class, $block, true),
            $this->compileOperand($expr->name, $block, true)
        );
        if (null !== $constName
            && 'class' === strtolower($constName)
            && ($expr->class instanceof Operand\Variable || $expr->class instanceof Operand\Temporary)) {
            $op->classConstFetchOnObject = true;
        }

        return [$op];
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
        if (null === $lcClass || !$this->isCompileTimeEnumCaseConstantMember($lcClass, strtolower($constName))) {
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
        if (null === $lcClass || !$this->isCompileTimeEnumCaseConstantMember($lcClass, strtolower($constName))) {
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
     * Lower PHP 8.1 first-class callables to Closure objects via TYPE_FROM_CALLABLE (#1230, #4810).
     *
     * @return OpCode[]
     */
    protected function compileFirstClassCallable(Op\Expr\FirstClassCallable $expr, Block $block): array
    {
        $result = $this->compileOperand($expr->result, $block, false);
        // Numeric kinds: avoid php-cfg class const fetch during self-host bundle JIT (#1056).
        if (3 === $expr->kind) {
            $callableSlot = $this->compileOperand($expr->result, $block, false);
            $receiver = $this->compileOperand($expr->var, $block, true);
            $method = $this->compileOperand($expr->name, $block, true);

            return [
                new OpCode(
                    OpCode::TYPE_INIT_ARRAY,
                    $callableSlot,
                    $receiver,
                    $this->compileIntegerLiteralSlot(0, $block)
                ),
                new OpCode(
                    OpCode::TYPE_ADD_ARRAY_ELEMENT,
                    $callableSlot,
                    $method,
                    $this->compileIntegerLiteralSlot(1, $block)
                ),
                new OpCode(
                    OpCode::TYPE_FROM_CALLABLE,
                    $result,
                    $callableSlot
                ),
            ];
        }

        if (Op\Expr\FirstClassCallable::KIND_NEW === $expr->kind) {
            $this->throwCompileError('Cannot create Closure for new expression');
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

        return [new OpCode(
            OpCode::TYPE_FROM_CALLABLE,
            $result,
            $callableSlot
        )];
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

        return $this->compileStringLiteralSlot($class->value.'::'.$method->value, $block);
    }

    private function compileStringLiteralSlot(string $value, Block $block): int
    {
        $var = new Variable(Variable::TYPE_STRING);
        $var->string($value);
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
     * Zend ≤8.3 rejects `final const` at compile-unit scope; enable at 8.4+ (#10324, #9909).
     */
    protected function rejectFinalGlobalTypedConstantIfUnsupported(Op\Terminal\Const_ $const): void
    {
        if (CompilerVersion::supportsFinalGlobalTypedConstants()) {
            return;
        }
        if (0 === ($const->flags & \PhpParser\Node\Stmt\Class_::MODIFIER_FINAL)) {
            return;
        }
        $this->throwCompileError(\PHPCompiler\Ast\GlobalTypedConstRewriter::FINAL_GLOBAL_CONST_REJECT_MESSAGE);
    }

    protected function compileGlobalConst(Op\Terminal\Const_ $const, Block $block): OpCode
    {
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
        $this->assignAttributeMetadata($opcode, $const);
        AttributeNames::assertCompileTimeConstTargetOnly($opcode->attributeNames, 'constant');
        AttributeNames::assertSensitiveParameterParamTargetOnly($opcode->attributeNames, 'constant');

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
            && !$this->variableAssignIsNullableClosureBinding($operand, $block)) {
            return true;
        }
        $root = $this->unwrapOperandChain($operand);
        if ($root instanceof Op\Expr\ClassConstFetch
            && $this->classConstFetchIsInvokableEnumCase($root, $block)) {
            return true;
        }
        if ($root instanceof Op\Expr\New_) {
            return true;
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
                return true;
            }
            if ($this->operandDerivesFromClosure($child->expr)) {
                return true;
            }
            if ($this->operandHasObjectType($child->expr)) {
                return true;
            }
            if ($child->expr instanceof Op\Expr\ClassConstFetch
                && $this->classConstFetchIsInvokableEnumCase($child->expr, $block)) {
                return true;
            }
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
        $lcConst = strtolower($constName);
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

            return $this->operandDerivesFromClosure($child->expr);
        }

        return false;
    }

    /** Inline or assigned closure comparators must not consume hoisted enum prelude slots (#8947). */
    private function callArgOperandIsClosureValue(Operand $operand, Block $block): bool
    {
        if ($this->operandDerivesFromClosure($operand)) {
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
            if (property_exists($callOp, 'args') && is_array($callOp->args)) {
                $producers = $this->precedingInlineCallArgProducersBeforeCfgOp($block->orig->children, $callOp);
                foreach ($producers as $candidate) {
                    if (
                        ($candidate instanceof Op\Expr\ArrowFunction || $candidate instanceof Op\Expr\Closure)
                        && null !== $this->matchSingleClosureInlineProducer($candidate, $callOp->args, $argIndex)
                    ) {
                        return true;
                    }
                }
                $producer = $this->matchInlineCallArgProducer($producers, $callOp->args, $argIndex, $callOp, $block);
                if ($producer instanceof Op\Expr\ArrowFunction || $producer instanceof Op\Expr\Closure) {
                    return true;
                }
            }
        }
        foreach ($block->orig->children as $child) {
            if ($child instanceof Op\Expr\ArrowFunction || $child instanceof Op\Expr\Closure) {
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

        return null !== $rootA && null !== $rootB && $rootA === $rootB;
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
        if (null === $className || null === $block->orig) {
            return false;
        }
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

    /** Call args rooted at array dim fetch must use their own producer slot (#10212). */
    private function isCallArgDirectArrayDimFetch(Operand $arg): bool
    {
        return $this->unwrapOperandChain($arg) instanceof Op\Expr\ArrayDimFetch;
    }

    /**
     * php-cfg may wire FuncCall args to dead temps while dim-fetch producers sit immediately
     * before the call (#10212, ext/standard/array.c usort comparators).
     */
    private function resolvePrecedingArrayDimFetchCallArgSlot(
        Operand $arg,
        Block $block,
        ?Op $cfgCallOp,
        int $argIndex
    ): ?string {
        if (null === $block->orig || null === $cfgCallOp) {
            return null;
        }
        // Embedded literals are not dim-fetch producers (#10401, zend_execute.c).
        if ($this->isEmbeddedCallLiteralArg($arg)) {
            return null;
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
            return null;
        }
        /** @var list<Op\Expr\ArrayDimFetch> $dimFetches */
        $dimFetches = [];
        for ($i = $callIndex - 1; $i >= 0; --$i) {
            $child = $children[$i];
            if ($child instanceof Op\Expr\ArrayDimFetch) {
                array_unshift($dimFetches, $child);
                continue;
            }
            break;
        }
        if ([] === $dimFetches) {
            return null;
        }
        $callArgs = property_exists($cfgCallOp, 'args') && is_array($cfgCallOp->args)
            ? $cfgCallOp->args
            : [];
        $dimIndex = $argIndex;
        if (\count($dimFetches) < \count($callArgs)) {
            $nonEmbeddedArgIndices = [];
            foreach ($callArgs as $i => $callArg) {
                if (null !== $callArg && !$this->isEmbeddedCallLiteralArg($callArg)) {
                    $nonEmbeddedArgIndices[] = $i;
                }
            }
            $mapped = array_search($argIndex, $nonEmbeddedArgIndices, true);
            if (false === $mapped) {
                return null;
            }
            $dimIndex = (int) $mapped;
        }
        if (!isset($dimFetches[$dimIndex])) {
            return null;
        }
        $fetch = $dimFetches[$dimIndex];
        $slot = $block->slotForOperand($fetch->result);
        if (null === $slot) {
            foreach ($this->compileExpr($fetch, $block) as $op) {
                $block->addOpCode($op);
            }
            $slot = $block->slotForOperand($fetch->result);
        }

        return null !== $slot ? (string) $slot : null;
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
        $callIndex = null;
        foreach ($cfgChildren as $i => $child) {
            if ($child === $callOp) {
                $callIndex = $i;
                break;
            }
        }
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
     * php-cfg hoists `true`/`false`/`null` as a ConstFetch stmt before FuncCall with a dead arg temp (#9140, #9260).
     */
    private function tryFoldHoistedBoolNullLiteralCallArg(
        Operand $arg,
        Block $block,
        ?Op $cfgCallOp,
        int $argIndex
    ): ?int {
        if (null === $block->orig || null === $cfgCallOp || !property_exists($cfgCallOp, 'args')) {
            return null;
        }
        $callArgs = $cfgCallOp->args;
        if (!is_array($callArgs) || [] === $callArgs || $argIndex !== \count($callArgs) - 1) {
            return null;
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
            return null;
        }
        for ($i = $callIndex - 1; $i >= 0 && $callIndex - $i <= 4; --$i) {
            $prev = $children[$i] ?? null;
            if (!$prev instanceof Op\Expr\ConstFetch) {
                if ($prev instanceof Op\Expr\Assign) {
                    continue;
                }
                // Hoisted null feeds Concat operands, not a trailing call arg (#10663, zend_operators.c).
                if ($prev instanceof Op\Expr\BinaryOp\Concat) {
                    return null;
                }
                break;
            }
            $name = $this->staticNameFromOperand($prev->name);
            if (null === $name || !\in_array(strtolower($name), ['true', 'false', 'null'], true)) {
                break;
            }
            $callArg = $callArgs[$argIndex] ?? null;
            if (null !== $callArg && !$this->isEmbeddedCallLiteralArg($callArg)) {
                $slot = $block->slotForOperand($prev->result);
                if (null !== $slot) {
                    return $slot;
                }
                $vm = $this->tryFoldGlobalConstFetch($prev);
                if (null !== $vm) {
                    return $block->registerConstant($arg, $vm);
                }
            }
            break;
        }

        return null;
    }

    /**
     * Fold compile-time call arguments, including php-cfg dead ClassConstFetch preludes (#5933).
     */
    protected function tryFoldCallArgCompileTimeValue(
        Operand $arg,
        Block $block,
        ?string $calleeName = null,
        ?Op $cfgCallOp = null
    ): ?int
    {
        if (null !== $this->findCoalesceStmtForCallArg($arg, $block)) {
            return null;
        }
        if (null !== $this->findInlineArrayProducerForCallArg($arg, $block, $cfgCallOp)) {
            return null;
        }
        if ($this->isEmbeddedCallLiteralArg($arg)) {
            return null;
        }
        if ($this->callArgIsNewExpression($arg)) {
            return null;
        }
        $vm = $this->vmVariableFromCfgLiteralOperand($arg);
        if (null !== $vm) {
            if (Variable::TYPE_STRING === $vm->type) {
                $lc = strtolower($vm->toString());
                if ('true' === $lc || 'false' === $lc) {
                    $bool = new Variable(Variable::TYPE_BOOLEAN);
                    $bool->bool('true' === $lc);
                    $vm = $bool;
                } elseif ('null' === $lc) {
                    $vm = new Variable(Variable::TYPE_NULL);
                } else {
                    $folded = \PHPCompiler\ext\standard\VmPhpCoreConstants::fetch($vm->toString());
                    if (null !== $folded) {
                        $vm = $folded;
                    } else {
                        $errorInt = \PHPCompiler\VM\Context::errorReportingConstant($vm->toString());
                        if (null !== $errorInt) {
                            $intVar = new Variable(Variable::TYPE_INTEGER);
                            $intVar->int($errorInt);
                            $vm = $intVar;
                        } elseif ('inf' === $lc || 'nan' === $lc) {
                            $floatVar = new Variable(Variable::TYPE_FLOAT);
                            $floatVar->float('inf' === $lc ? INF : NAN);
                            $vm = $floatVar;
                        }
                    }
                }
            }

            return $block->registerConstant($arg, $vm);
        }
        $multisortFold = $this->tryFoldArrayMultisortSortingEnumArg($arg, $block, $calleeName, $cfgCallOp);
        if (null !== $multisortFold) {
            return $multisortFold;
        }
        $root = $this->unwrapOperandChain($arg);
        if ($root instanceof Op\Expr\ClassConstFetch) {
            $vm = $this->tryFoldClassConstFetchDefault($root, $block, true);
            if (null !== $vm) {
                return $block->registerConstant($arg, $vm);
            }
        }
        if (null === $block->orig) {
            return null;
        }
        $callSite = $this->findCfgCallSiteForArg($block->orig->children, $arg, $cfgCallOp);
        if (null !== $callSite) {
            [$callOp, $argIndex] = $callSite;
            $callArg = $callOp->args[$argIndex] ?? null;
            $producers = $this->precedingInlineCallArgProducersBeforeCfgOp($block->orig->children, $callOp);
            $producer = null;
            if (
                property_exists($callOp, 'args')
                && is_array($callOp->args)
            ) {
                $producer = $this->matchInlineCallArgProducer($producers, $callOp->args, $argIndex, $callOp, $block);
            }
            if ($producer instanceof Op\Expr\ConstFetch) {
                $vm = $this->tryFoldGlobalConstFetch($producer);
                if (null !== $vm) {
                    // php-cfg dead call-arg temp vs hoisted ConstFetch.result (#10453, password_hash PASSWORD_BCRYPT + options).
                    $producerSlot = $block->slotForOperand($producer->result);
                    if (null === $producerSlot) {
                        foreach ($this->compileExpr($producer, $block) as $op) {
                            $block->addOpCode($op);
                        }
                        $producerSlot = $block->slotForOperand($producer->result);
                    }
                    if (null !== $producerSlot) {
                        return $producerSlot;
                    }

                    return $block->registerConstant($producer->result, $vm);
                }
            }
            if ($producer instanceof Op\Expr\BinaryOp) {
                $vm = $this->tryFoldCompileTimeBinaryExprDefault(
                    $producer,
                    $block,
                    $block->orig->children ?? [],
                    true
                );
                if (null !== $vm) {
                    $producerSlot = $block->slotForOperand($producer->result);
                    if (null === $producerSlot) {
                        foreach ($this->compileExpr($producer, $block) as $op) {
                            $block->addOpCode($op);
                        }
                        $producerSlot = $block->slotForOperand($producer->result);
                    }
                    if (null !== $producerSlot) {
                        return $producerSlot;
                    }

                    return $block->registerConstant($producer->result, $vm);
                }
            }
            if ($producer instanceof Op\Expr\Cast) {
                $vm = $this->tryFoldCompileTimeCastDefault(
                    $producer,
                    $block,
                    $block->orig->children,
                    true
                );
                if (null !== $vm) {
                    return $block->registerConstant($arg, $vm);
                }
            }
            if ($producer instanceof Op\Expr\ClassConstFetch) {
                $producerConst = $this->staticNameFromOperand($producer->name);
                if (null !== $producerConst && 'class' !== strtolower($producerConst)) {
                    foreach ($producers as $later) {
                        if (!$later instanceof Op\Expr\ClassConstFetch) {
                            continue;
                        }
                        $pseudo = $this->staticNameFromOperand($later->name);
                        if (null === $pseudo || 'class' !== strtolower($pseudo)) {
                            continue;
                        }
                        if ($this->operandsReferToSameVariable($later->class, $producer->result)) {
                            $producer = $later;
                            break;
                        }
                    }
                }
                $vm = $this->tryFoldClassConstFetchDefault($producer, $block, true);
                if (null !== $vm) {
                    return $block->registerConstant($arg, $vm);
                }
            }
            if ($producer instanceof Op\Expr\Closure || $producer instanceof Op\Expr\ArrowFunction) {
                return null;
            }
            if ($producer instanceof Op\Expr\New_) {
                return null;
            }
            if ($producer instanceof Op\Expr\UnaryMinus || $producer instanceof Op\Expr\UnaryPlus) {
                $vm = $this->tryFoldUnaryLiteralDefault($producer);
                if (null !== $vm) {
                    return $block->registerConstant($arg, $vm);
                }
            }
            if ($this->callArgOperandIsClosureValue($arg, $block)) {
                return null;
            }
            if ($this->callArgInlineProducerIsNew($callOp, $argIndex, $block)) {
                return null;
            }
            $fetches = $this->precedingCallArgClassConstFetchesBeforeCfgOp($block->orig->children, $callOp, $block);
            $fetch = $this->precedingClassConstFetchForCallArgIndex($callOp, $argIndex, $fetches);
            if ($this->callArgUsesHoistedEnumPreludeSlot($callArg) && $fetch instanceof Op\Expr\ClassConstFetch) {
                $pseudoName = $this->staticNameFromOperand($fetch->name);
                if (null !== $pseudoName && 'class' === strtolower($pseudoName)) {
                    $vm = $this->tryFoldClassConstFetchDefault($fetch, $block, true);
                    if (null !== $vm) {
                        return $block->registerConstant($arg, $vm);
                    }
                }
                if ($this->callArgNeedsRuntimeEnumConstFetch($arg, $fetch, $block, $cfgCallOp)) {
                    $vm = $this->tryFoldClassConstFetchDefault($fetch, $block, true);
                    if (null !== $vm) {
                        return $block->registerConstant($arg, $vm);
                    }
                }
            }
            $fetch = $this->classConstFetchForHoistedDeadPrelude($callOp, $argIndex, $block);
            if ($this->callArgUsesHoistedEnumPreludeSlot($callArg) && $fetch instanceof Op\Expr\ClassConstFetch) {
                $pseudoName = $this->staticNameFromOperand($fetch->name);
                if (null !== $pseudoName && 'class' === strtolower($pseudoName)) {
                    $vm = $this->tryFoldClassConstFetchDefault($fetch, $block, true);
                    if (null !== $vm) {
                        return $block->registerConstant($arg, $vm);
                    }
                }
                if ($this->callArgNeedsRuntimeEnumConstFetch($arg, $fetch, $block, $cfgCallOp)) {
                    $vm = $this->tryFoldClassConstFetchDefault($fetch, $block, true);
                    if (null !== $vm) {
                        return $block->registerConstant($arg, $vm);
                    }
                }
            }
        }
        foreach ($block->orig->children as $child) {
            if ($child instanceof Op\Expr\Array_
                && $this->operandsReferToSameVariable($child->result, $root)
            ) {
                $vm = $this->tryBuildCompileTimeArrayFromExpr($child, $block, [$child]);
                if (null !== $vm) {
                    return $block->registerConstant($arg, $vm);
                }

                continue;
            }
            if (!$child instanceof Op\Expr || !$this->operandsReferToSameVariable($child->result, $root)) {
                continue;
            }
            $vm = $this->tryFoldCompileTimeExprDefault($child, $block, [$child], true);
            if (null !== $vm) {
                return $block->registerConstant($arg, $vm);
            }
        }

        return null;
    }

    /**
     * @param list<Operand> $args
     *
     * @return list<OpCode>
     */
    private function tryFoldArrayMultisortSortingEnumArg(
        Operand $arg,
        Block $block,
        ?string $calleeName,
        ?Op $cfgCallOp = null
    ): ?int {
        if (null === $calleeName || 'array_multisort' !== strtolower($calleeName)) {
            return null;
        }
        if (null === $block->orig) {
            return null;
        }
        $callSite = $this->findCfgCallSiteForArg($block->orig->children, $arg, $cfgCallOp);
        if (null === $callSite) {
            return null;
        }
        [$callOp, $argIndex] = $callSite;
        $fetch = null;
        $root = $this->unwrapOperandChain($arg);
        if ($root instanceof Op\Expr\ClassConstFetch) {
            $fetch = $root;
        }
        if (!$fetch instanceof Op\Expr\ClassConstFetch) {
            $producers = $this->precedingInlineCallArgProducersBeforeCfgOp($block->orig->children, $callOp);
            if (property_exists($callOp, 'args') && is_array($callOp->args)) {
                $producer = $this->matchInlineCallArgProducer($producers, $callOp->args, $argIndex, $callOp, $block);
                if ($producer instanceof Op\Expr\ClassConstFetch) {
                    $fetch = $producer;
                }
            }
        }
        if (!$fetch instanceof Op\Expr\ClassConstFetch) {
            return null;
        }
        $className = $this->staticNameFromOperand($fetch->class);
        $constName = $this->staticNameFromOperand($fetch->name);
        if (null === $className || null === $constName) {
            return null;
        }
        $lcClass = $this->resolveDefaultClassConstScope($className, $block) ?? strtolower(ltrim($className, '\\'));
        if ('sorting' !== $lcClass || !$this->isCompileTimeEnumCaseConstantMember($lcClass, strtolower($constName))) {
            return null;
        }
        $sortValue = null;
        $lcConst = strtolower($constName);
        if ('ascending' === $lcConst) {
            $sortValue = SORT_ASC;
        } elseif ('descending' === $lcConst) {
            $sortValue = SORT_DESC;
        }
        if (null === $sortValue) {
            return null;
        }
        $intVar = new Variable(Variable::TYPE_INTEGER);
        $intVar->int($sortValue);

        return $block->registerConstant($arg, $intVar);
    }

    protected function compileCallArgSends(
        array $args,
        Block $block,
        ?string $calleeName = null,
        ?Op $cfgCallOp = null
    ): array
    {
        $this->validateCallArgOrder($args);

        if (null !== $cfgCallOp) {
            $this->ensureDeferredSiblingInlineCallArgProducersCompiled($block, $cfgCallOp);
        }

        $sends = [];
        foreach ($args as $argIndex => $arg) {
            $nameSlot = null;
            $unpackFlag = null;
            $callOrdinal = 0;
            foreach ($block->opCodes as $op) {
                if (OpCode::TYPE_FUNCCALL_INIT === $op->type) {
                    ++$callOrdinal;
                }
            }
            $inlineArray = $this->findInlineArrayProducerForCallArg($arg, $block, $cfgCallOp, (int) $argIndex);
            $prefetchOps = [];
            if (null !== $inlineArray) {
                $existingArraySlot = $block->slotForOperand($inlineArray->result);
                if (null !== $existingArraySlot) {
                    $valueSlot = $existingArraySlot;
                } else {
                    $arrayOps = $this->compileArrayLiteral($inlineArray, $block);
                    if ([] !== $arrayOps) {
                        $sends = array_merge($sends, $arrayOps);
                    }
                    $valueSlot = $this->compileOperand($inlineArray->result, $block, true);
                }
            } else {
                $valueSlot = null;
                if (null !== $cfgCallOp && !$this->isCallArgDirectArrayDimFetch($arg)) {
                    $valueSlot = $this->resolveHoistedIssetOrEmptyCallArgSlot(
                        $arg,
                        $block,
                        $cfgCallOp,
                        (int) $argIndex
                    );
                }
                if (null === $valueSlot) {
                    $valueSlot = $this->resolveInlineFirstClassCallableCallArgSlot($arg, $block, $cfgCallOp);
                }
                if (null === $valueSlot && $this->isCallArgDirectArrayDimFetch($arg)) {
                    $valueSlot = $this->compileOperand($arg, $block, true);
                } else                if (null === $valueSlot) {
                    $valueSlot = $this->resolvePrecedingArrayDimFetchCallArgSlot(
                        $arg,
                        $block,
                        $cfgCallOp,
                        (int) $argIndex
                    );
                }
                if (null === $valueSlot && !$this->isCallArgDirectArrayDimFetch($arg)) {
                    $valueSlot = $this->compileCallArgCoalesceSlot($arg, $block, $cfgCallOp, (int) $argIndex);
                }
                if (
                    null === $valueSlot
                    && null !== $cfgCallOp
                    && $this->callArgInlineProducerIsNew($cfgCallOp, (int) $argIndex, $block)
                ) {
                    $producers = $this->precedingInlineCallArgProducersBeforeCfgOp(
                        $block->orig->children ?? [],
                        $cfgCallOp
                    );
                    $newProducer = $this->matchInlineCallArgProducer(
                        $producers,
                        $cfgCallOp->args ?? [],
                        (int) $argIndex,
                        $cfgCallOp,
                        $block
                    );
                    if ($newProducer instanceof Op\Expr\New_) {
                        if (null === $block->slotForOperand($newProducer->result)) {
                            foreach ($this->compileExpr($newProducer, $block) as $op) {
                                $sends[] = $op;
                            }
                        }
                        $valueSlot = $this->slotForInlineNewProducer($block, $newProducer);
                    }
                    if (null === $valueSlot) {
                        $valueSlot = $this->compileOperand($arg, $block, true);
                    }
                }
                if (null === $valueSlot && !$this->isCallArgDirectArrayDimFetch($arg)) {
                    $valueSlot = $this->compileHoistedEmptyCallArg($arg, $block);
                }
                if (null === $valueSlot) {
                    if ($this->isEmbeddedCallLiteralArg($arg)) {
                        $valueSlot = $this->compileOperand($arg, $block, true);
                    }
                }
                if (null === $valueSlot) {
                    if (
                        null === $calleeName
                        || !$this->callArgRequiresByRef($calleeName, (int) $argIndex, $arg, $block)
                    ) {
                        $valueSlot = $this->tryFoldHoistedBoolNullLiteralCallArg(
                            $arg,
                            $block,
                            $cfgCallOp,
                            (int) $argIndex
                        );
                        if (null === $valueSlot) {
                            $valueSlot = $this->tryFoldCallArgCompileTimeValue($arg, $block, $calleeName, $cfgCallOp);
                        }
                        if (
                            null === $valueSlot
                            && null !== $cfgCallOp
                            && is_array($cfgCallOp->args ?? null)
                            && isset($cfgCallOp->args[(int) $argIndex])
                            && $cfgCallOp->args[(int) $argIndex] !== $arg
                        ) {
                            $valueSlot = $this->tryFoldCallArgCompileTimeValue(
                                $cfgCallOp->args[(int) $argIndex],
                                $block,
                                $calleeName,
                                $cfgCallOp
                            );
                        }
                    }
                }
                if (null === $valueSlot && !$this->isCallArgDirectArrayDimFetch($arg)) {
                    $valueSlot = $this->compileCallArgCoalesceSlot($arg, $block, $cfgCallOp, (int) $argIndex);
                }
                if (null === $valueSlot) {
                    $prefetchOps = $this->compileCallArgRuntimeEnumConstFetchOps(
                        $arg,
                        $block,
                        (int) $argIndex,
                        $callOrdinal,
                        $cfgCallOp
                    );
                    if ([] !== $prefetchOps && !$this->callArgOperandIsClosureValue($arg, $block)) {
                        $valueSlot = $prefetchOps[0]->arg1;
                    }
                }
                if (null === $valueSlot) {
                    $valueSlot = $this->findInlineExprCallArgProducerSlot($arg, $block, $cfgCallOp);
                }
                if (null === $valueSlot && null !== $cfgCallOp) {
                    $valueSlot = $this->resolveAdjacentNestedFuncCallArgSlot($block, $cfgCallOp, (int) $argIndex);
                }
                $closureSlot = $this->resolveInlineClosureCallArgSlot($arg, $block, $cfgCallOp);
                if (null === $closureSlot && null !== $cfgCallOp) {
                    $closureSlot = $this->resolvePrecedingClosureCallArgSlot($cfgCallOp, (int) $argIndex, $block);
                }
                if (null !== $closureSlot) {
                    $valueSlot = $closureSlot;
                }
                if (
                    null === $valueSlot
                    && 0 === $argIndex
                    && null !== $calleeName
                    && ('Closure::bind' === $calleeName || 'Closure::fromCallable' === $calleeName)
                ) {
                    for ($i = \count($block->opCodes) - 1; $i >= 0; --$i) {
                        $scanOp = $block->opCodes[$i];
                        if (OpCode::TYPE_STATICCALL_INIT === $scanOp->type) {
                            break;
                        }
                        if (OpCode::TYPE_FROM_CALLABLE === $scanOp->type) {
                            $valueSlot = $scanOp->arg1;
                            break;
                        }
                        if (OpCode::TYPE_CLOSURE === $scanOp->type) {
                            $valueSlot = $scanOp->arg1;
                            break;
                        }
                    }
                }
                if (null === $valueSlot) {
                    $valueSlot = $this->compileOperand($arg, $block, true);
                }
                if (null === $valueSlot && $arg instanceof Operand\NullOperand) {
                    $valueSlot = $this->registerNullConstantSlot($block, $arg);
                }
                if (
                    null !== $valueSlot
                    && !$this->isCallArgDirectArrayDimFetch($arg)
                    && null !== $block->orig
                    && ($arg instanceof Operand\Variable || $arg instanceof Operand\Temporary)
                    && !(
                        null !== $cfgCallOp
                        && $this->callArgInlineProducerIsNew($cfgCallOp, (int) $argIndex, $block)
                    )
                ) {
                    $hasProducer = false;
                    foreach ($block->orig->children as $child) {
                        if (!($child instanceof Op\Expr) || null === $child->result) {
                            continue;
                        }
                        if ($this->operandsReferToSameVariable($child->result, $arg)) {
                            $hasProducer = true;
                            break;
                        }
                    }
                    if (null === $this->findCoalesceStmtForCallArg($arg, $block)) {
                        $producerSlot = $this->findInlineExprCallArgProducerSlot($arg, $block, $cfgCallOp);
                        if (null !== $producerSlot) {
                            $valueSlot = $producerSlot;
                        } elseif (null !== $cfgCallOp) {
                            $producers = $this->precedingInlineCallArgProducersBeforeCfgOp(
                                $block->orig->children,
                                $cfgCallOp
                            );
                            $matched = $this->matchInlineCallArgProducer(
                                $producers,
                                $cfgCallOp->args ?? [],
                                (int) $argIndex,
                                $cfgCallOp,
                                $block
                            );
                            if ($matched instanceof Op\Expr) {
                                $matchedSlot = $block->slotForOperand($matched->result);
                                if (null === $matchedSlot) {
                                    foreach ($this->compileExpr($matched, $block) as $op) {
                                        $block->addOpCode($op);
                                    }
                                    $matchedSlot = $block->slotForOperand($matched->result);
                                }
                                if (null !== $matchedSlot) {
                                    $valueSlot = $matchedSlot;
                                }
                            }
                        }
                    }
                }
                $evalSlot = $this->resolvePrecedingEvalCallArgSlot(
                    $arg,
                    $block,
                    $cfgCallOp,
                    (int) $argIndex
                );
                if (null !== $evalSlot) {
                    $valueSlot = $evalSlot;
                }
                $skipPreferNamedLocal = false;
                if (null !== $cfgCallOp && null !== $block->orig) {
                    $producers = $this->precedingInlineCallArgProducersBeforeCfgOp(
                        $block->orig->children,
                        $cfgCallOp
                    );
                    $arrayProducerCount = 0;
                    foreach ($producers as $producer) {
                        if ($producer instanceof Op\Expr\Array_) {
                            ++$arrayProducerCount;
                        }
                    }
                    if ($arrayProducerCount >= 2 && !$this->callIncludesNamedParameter($cfgCallOp)) {
                        $matched = $this->matchInlineCallArgProducer(
                            $producers,
                            $cfgCallOp->args ?? [],
                            (int) $argIndex,
                            $cfgCallOp
                        );
                        if ($matched instanceof Op\Expr\Array_) {
                            $matchedSlot = $block->slotForOperand($matched->result);
                            if (null === $matchedSlot) {
                                foreach ($this->compileExpr($matched, $block) as $op) {
                                    $block->addOpCode($op);
                                }
                                $matchedSlot = $block->slotForOperand($matched->result);
                            }
                            if (null !== $matchedSlot) {
                                $valueSlot = $matchedSlot;
                                $skipPreferNamedLocal = true;
                            }
                        }
                    }
                }
                if (!$skipPreferNamedLocal) {
                    $valueSlot = $this->preferNamedLocalCallArgSlot(
                        $arg,
                        $block,
                        $valueSlot,
                        (
                            null !== $cfgCallOp
                            && $this->callArgInlineProducerIsNew($cfgCallOp, (int) $argIndex, $block)
                        ) ? null : $calleeName
                    );
                }
            }
            $argName = $this->callArgName($arg);
            if (null !== $argName) {
                $nameOp = new Operand\Literal($argName);
                $nameOp->type = Type::string();
                $nameVar = new Variable(Variable::TYPE_STRING);
                $nameVar->string($argName);
                $nameSlot = $block->registerConstant($nameOp, $nameVar);
            }
            $unpackFlag = $this->callArgUnpack($arg) ? 1 : null;
            if ([] !== $prefetchOps) {
                $sends = array_merge($sends, $prefetchOps);
            }
            if (null !== $cfgCallOp && null !== $block->orig) {
                $producers = $this->precedingInlineCallArgProducersBeforeCfgOp(
                    $block->orig->children,
                    $cfgCallOp
                );
                $namedLocalSlot = $this->namedLocalCallArgSlotIfBound($arg, $block, $cfgCallOp, (int) $argIndex);
                if (\count($producers) >= 2 && null === $namedLocalSlot) {
                    $matched = $this->matchInlineCallArgProducer(
                        $producers,
                        $cfgCallOp->args ?? [],
                        (int) $argIndex,
                        $cfgCallOp,
                        $block
                    );
                    if ($matched instanceof Op\Expr) {
                        if (null === $block->slotForOperand($matched->result)) {
                            foreach ($this->compileExpr($matched, $block) as $op) {
                                $sends[] = $op;
                            }
                        }
                        $matchedSlot = $this->slotForEmittedIssetOrEmptyProducer($block, $matched)
                            ?? (
                                $matched instanceof Op\Expr\New_
                                    ? $this->slotForInlineNewProducer($block, $matched)
                                    : $block->slotForOperand($matched->result)
                            );
                        if (null !== $matchedSlot) {
                            $valueSlot = $matchedSlot;
                        }
                    }
                } elseif (null !== $namedLocalSlot) {
                    $valueSlot = $namedLocalSlot;
                }
                if ('array_column' === strtolower($calleeName ?? '')) {
                    if (0 === $argIndex) {
                        $arrayExpr = null;
                        foreach ($block->orig->children as $i => $child) {
                            if ($child !== $cfgCallOp) {
                                continue;
                            }
                            $prev = $block->orig->children[$i - 1] ?? null;
                            if ($prev instanceof Op\Expr\ConstFetch) {
                                $arrayExpr = $block->orig->children[$i - 2] ?? null;
                            } elseif ($prev instanceof Op\Expr\Array_) {
                                $arrayExpr = $prev;
                            }
                            break;
                        }
                        if ($arrayExpr instanceof Op\Expr\Array_) {
                            if (null === $block->slotForOperand($arrayExpr->result)) {
                                foreach ($this->compileExpr($arrayExpr, $block) as $op) {
                                    $sends[] = $op;
                                }
                            }
                            $arraySlot = $block->slotForOperand($arrayExpr->result);
                            if (null !== $arraySlot) {
                                $valueSlot = $arraySlot;
                            }
                        }
                    } elseif (1 === $argIndex || 2 === $argIndex) {
                        $nullTarget = $this->arrayColumnNullPreludeArgIndex($cfgCallOp);
                        if ($nullTarget === $argIndex) {
                            foreach ($block->orig->children as $i => $child) {
                                if ($child === $cfgCallOp) {
                                    $prev = $block->orig->children[$i - 1] ?? null;
                                    if ($prev instanceof Op\Expr\ConstFetch) {
                                        $name = $this->staticNameFromOperand($prev->name);
                                        if (null !== $name && 'null' === strtolower($name)) {
                                            if (null === $block->slotForOperand($prev->result)) {
                                                foreach ($this->compileExpr($prev, $block) as $op) {
                                                    $sends[] = $op;
                                                }
                                            }
                                            $nullSlot = $block->slotForOperand($prev->result);
                                            if (null !== $nullSlot) {
                                                $valueSlot = $nullSlot;
                                            }
                                        }
                                    }
                                    break;
                                }
                            }
                        }
                    }
                }
                if (
                    \in_array(strtolower($calleeName ?? ''), ['in_array', 'array_search'], true)
                    && 0 === $argIndex
                ) {
                    foreach ($block->orig->children as $i => $child) {
                        if ($child !== $cfgCallOp) {
                            continue;
                        }
                        $callArg = $cfgCallOp->args[0] ?? null;
                        for ($j = $i - 1; $j >= 0; --$j) {
                            $prev = $block->orig->children[$j];
                            if ($prev instanceof Op\Expr\ConstFetch) {
                                if (
                                    null !== $callArg
                                    && $this->operandsReferToSameVariable($prev->result, $callArg)
                                ) {
                                    if (null === $block->slotForOperand($prev->result)) {
                                        foreach ($this->compileExpr($prev, $block) as $op) {
                                            $sends[] = $op;
                                        }
                                    }
                                    $needleSlot = $block->slotForOperand($prev->result);
                                    if (null !== $needleSlot) {
                                        $valueSlot = $needleSlot;
                                    }
                                    break 2;
                                }
                                continue;
                            }
                            if (!$this->isInlineExprCallArgProducer($prev)) {
                                break;
                            }
                        }
                        break;
                    }
                }
            }
            foreach ($this->tryEmitAdjacentAssignForInlineCallArg(
                $arg,
                null !== $valueSlot ? (string) $valueSlot : null,
                $block,
                $cfgCallOp,
                (int) $argIndex
            ) as $assignOp) {
                $sends[] = $assignOp;
            }
            if (null !== $cfgCallOp) {
                $siblingOps = [];
                $siblingSlot = $this->resolveSiblingInlineCallArgProducerSlot(
                    $block,
                    $cfgCallOp,
                    (int) $argIndex,
                    $siblingOps
                );
                if (null !== $siblingSlot) {
                    if ([] !== $siblingOps) {
                        $sends = array_merge($sends, $siblingOps);
                    }
                    $valueSlot = $siblingSlot;
                }
            }
            if (
                null !== $cfgCallOp
                && null !== $nameSlot
                && $this->callArgIsDeadInlineTemporary($arg)
                && $this->callArgOperandExpectsArrayProducer($arg)
                && null !== $block->orig
            ) {
                $producers = $this->precedingInlineCallArgProducersBeforeCfgOp(
                    $block->orig->children,
                    $cfgCallOp
                );
                $matched = $this->findUnassignedInlineArrayProducerForDeadCallArg(
                    $producers,
                    $cfgCallOp,
                    (int) $argIndex,
                    $block
                );
                if ($matched instanceof Op\Expr\Array_) {
                    if (null === $block->slotForOperand($matched->result)) {
                        foreach ($this->compileExpr($matched, $block) as $op) {
                            $sends[] = $op;
                        }
                    }
                    $arraySlot = $block->slotForOperand($matched->result);
                    if (null !== $arraySlot) {
                        $valueSlot = $arraySlot;
                    }
                }
            }
            if (null !== $cfgCallOp && null !== $block->orig) {
                $recoveredIssetEmpty = $this->resolveHoistedIssetOrEmptyCallArgSlot(
                    $arg,
                    $block,
                    $cfgCallOp,
                    (int) $argIndex
                );
                if (null !== $recoveredIssetEmpty) {
                    $valueSlot = $recoveredIssetEmpty;
                }
            }
            if (
                null !== $cfgCallOp
                && $this->callArgIsDeadInlineTemporary($arg)
                && \in_array(strtolower($calleeName ?? ''), ['exit', 'die'], true)
            ) {
                $logicalPhi = $this->resolveExitLogicalShortCircuitCallArgSlot($block);
                if (null !== $logicalPhi) {
                    $valueSlot = $logicalPhi;
                }
            }
            if (
                null !== $cfgCallOp
                && $argIndex > 0
                && null !== $valueSlot
                && is_array($cfgCallOp->args ?? null)
                && isset($cfgCallOp->args[0])
            ) {
                $leadingCoalesce = $this->findCoalesceStmtForCallArg($cfgCallOp->args[0], $block);
                if (null !== $leadingCoalesce) {
                    $coalesceSlot = $this->slotForCoalesceResult($block, $leadingCoalesce);
                    if (null !== $coalesceSlot && (string) $valueSlot === (string) $coalesceSlot) {
                        $hoisted = $this->tryFoldHoistedBoolNullLiteralCallArg(
                            $arg,
                            $block,
                            $cfgCallOp,
                            (int) $argIndex
                        );
                        if (null !== $hoisted) {
                            $valueSlot = $hoisted;
                        } elseif ($this->isCallArgUnrelatedToPriorStmtCoalesce($arg)) {
                            $direct = $this->compileOperand($arg, $block, true);
                            if (null !== $direct) {
                                $valueSlot = $direct;
                            }
                        }
                    }
                }
            }
            $sends[] = new OpCode(OpCode::TYPE_ARG_SEND, $valueSlot, $nameSlot, $unpackFlag);
        }

        return $sends;
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
        if ($this->callArgOperandIsClosureValue($arg, $block)) {
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
        if (
            !$this->blockHasAssignToSlot($block, (int) $namedSlot)
            && !$this->blockHasAssignToSlotInParentBlocks($block, (int) $namedSlot)
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

        return [new OpCode(
            OpCode::TYPE_ASSIGN,
            $this->compileOperand($prev->result, $block, false),
            $destSlot,
            $valueSlot
        )];
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

    private function callArgRequiresByRef(string $calleeName, int $argIndex, ?Operand $arg = null, ?Block $block = null): bool
    {
        if ('array_multisort' === strtolower($calleeName)) {
            if (null !== $arg && null !== $block && $this->isArrayMultisortSortFlagOperand($arg, $block)) {
                return false;
            }

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
     * Zend zend_compile.c: duplicate named params, positional-after-named, unpack ordering (#4299, #4663).
     *
     * @param list<Operand> $args
     */
    private function validateCallArgOrder(array $args): void
    {
        $hadNamed = false;
        $hadUnpack = false;
        /** @var array<string, true> $seenNamedLc */
        $seenNamedLc = [];
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
                $lc = strtolower($argName);
                if (isset($seenNamedLc[$lc])) {
                    $this->throwCompileError("Named parameter \${$argName} overwrites previous argument");
                }
                $seenNamedLc[$lc] = true;
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

        return [
            new OpCode(
                OpCode::TYPE_CLASS_CONST_FETCH,
                $valueSlot,
                $this->compileOperand($fetch->class, $block, true),
                $this->compileOperand($fetch->name, $block, true)
            ),
        ];
    }

    private function findEnumCaseClassConstFetchForArrayElement(
        Operand $valueOperand,
        Block $block,
        Op\Expr\Array_ $arrayExpr,
        int $elementIndex
    ): ?Op\Expr\ClassConstFetch {
        $root = $this->unwrapOperandChain($valueOperand);
        if ($root instanceof Op\Expr\ClassConstFetch
            && $this->isCompileTimeEnumCaseClassConstFetch($root, $block)
        ) {
            return $root;
        }
        if (null !== $block->orig) {
            foreach ($block->orig->children as $child) {
                if (!$child instanceof Op\Expr\Array_
                    || !$this->operandsReferToSameVariable($child->result, $arrayExpr->result)
                ) {
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
                    // php-cfg may drop the fetch result and leave a literal backing scalar element/key
                    // (e.g. `E::A; [ E::A => 1 ]` lowered to key Literal(1)) — recover the enum case fetch (#9024).
                    if ($valueOperand instanceof Operand\Literal
                        && (\is_int($valueOperand->value) || \is_string($valueOperand->value))
                    ) {
                        $className = $this->staticNameFromOperand($fetch->class);
                        $constName = $this->staticNameFromOperand($fetch->name);
                        if (null !== $className && null !== $constName) {
                            $lcClass = $this->resolveDefaultClassConstScope($className, $block);
                            $lcConst = strtolower($constName);
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

        return $this->isCompileTimeEnumCaseConstantMember($lcClass, strtolower($constName));
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
                if (!$started) {
                    $return[] = new OpCode(OpCode::TYPE_INIT_ARRAY, $result);
                    $started = true;
                }
                $return[] = new OpCode(
                    OpCode::TYPE_ARRAY_SPREAD,
                    $result,
                    $this->compileOperand($expr->values[$i], $block, true),
                    max(0, $expr->getLine())
                );
                continue;
            }

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
                $valueSlot = $this->tryFoldArrayElementCompileTimeValue($expr->values[$i], $block, $expr, $i);
                if (null === $valueSlot) {
                    $valueSlot = $this->compileOperand($expr->values[$i], $block, true);
                }
            }
            $keyOperand = $expr->keys[$i];
            $keyFetch = $this->findEnumCaseClassConstFetchForArrayElement(
                $keyOperand,
                $block,
                $expr,
                $i
            );
            if (null !== $keyFetch) {
                $keyTemp = new Operand\Temporary();
                $keySlot = $block->getVarSlot($keyTemp, false);
                $return[] = new OpCode(
                    OpCode::TYPE_CLASS_CONST_FETCH,
                    $keySlot,
                    $this->compileOperand($keyFetch->class, $block, true),
                    $this->compileOperand($keyFetch->name, $block, true)
                );
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
                    OpCode::TYPE_ARRAY_DIM_FETCH,
                    $elemSlot,
                    $result,
                    $keySlot instanceof Operand\NullOperand ? null : $keySlot
                );
                $propFetch = $this->findPropertyFetchForResult($expr->values[$i], $block);
                if (null !== $propFetch) {
                    $return[] = new OpCode(
                        OpCode::TYPE_PROPERTY_FETCH,
                        $valueSlot,
                        $this->compileOperand($propFetch->var, $block, true),
                        $this->compileOperand($propFetch->name, $block, true)
                    );
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

    protected function compileMethodCallOpcodes(
        ?int $receiver,
        ?int $methodName,
        array $args,
        Operand $result,
        Block $block,
        int $startLine = 0,
        ?Op $cfgCallOp = null
    ): array {
        $return = [
            new OpCode(
                OpCode::TYPE_METHODCALL_INIT,
                $receiver,
                $methodName
            ),
        ];
        foreach ($this->compileCallArgSends($args, $block) as $send) {
            $return[] = $send;
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
        if (
            $this->forceDeferredSiblingCallReturnSlot
            || $this->callNeedsReturnSlot($result, $block, $cfgCallOp)
            || $this->cfgCallOpImmediatelyVoidDiscarded($cfgCallOp, $block)
            || $this->siblingInlineCallArgProducerNeedsReturnSlot($cfgCallOp, $block)
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
            || (!$cfgCallOp instanceof Op\Expr\FuncCall && !$cfgCallOp instanceof Op\Expr\NsFuncCall)
        ) {
            return false;
        }
        $cfgChildren = $block->orig->children;
        foreach ($cfgChildren as $consumerIndex => $consumer) {
            if (!$this->isInlineExprCallArgConsumer($consumer)) {
                continue;
            }
            if (!property_exists($consumer, 'args') || !is_array($consumer->args) || \count($consumer->args) < 2) {
                continue;
            }
            $firstSibling = $this->firstSiblingInlineFuncCallProducerIndex($consumerIndex, $cfgChildren);
            if (null === $firstSibling || $consumerIndex - $firstSibling < 2) {
                continue;
            }
            foreach ($cfgChildren as $producerIndex => $producer) {
                if ($producer !== $cfgCallOp || !$producer instanceof Op\Expr) {
                    continue;
                }
                if ($this->isSiblingMultiArgFuncCallProducer(
                    $producer,
                    $consumer,
                    $producerIndex,
                    $consumerIndex,
                    $cfgChildren
                )) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * php-cfg splits `(void) f()` into adjacent FuncCall + Cast_Void with distinct SSA temps (#9779).
     * Use EXEC_RETURN so #[\NoDiscard] sees an intentional discard (Zend zend_execute.c).
     */
    private function cfgCallOpImmediatelyVoidDiscarded(?Op $cfgCallOp, Block $block): bool
    {
        if (null === $cfgCallOp || null === $block->orig) {
            return false;
        }
        $ops = $block->orig->children;
        $count = \count($ops);
        for ($i = 0; $i < $count - 1; ++$i) {
            if ($ops[$i] !== $cfgCallOp) {
                continue;
            }

            return $ops[$i + 1] instanceof Op\Expr\Cast\Void_;
        }

        return false;
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
        $folded = $this->tryCompileDefineAsGlobalConst($name, $args, $result, $block);
        if (null !== $folded) {
            return $folded;
        }

        $callName = $this->tryFoldVariableFunctionName($name, $block) ?? $name;
        $calleeName = $this->resolveCompileTimeStringSlot($callName, $block)
            ?? ($name !== null ? $this->resolveCompileTimeStringSlot($name, $block) : null);

        $this->lowerEmbeddedCoalesceCallArgs($args, $block);

        $argSends = $this->compileCallArgSends($args, $block, $calleeName, $cfgCallOp);
        $return = [];
        foreach ($argSends as $send) {
            if (OpCode::TYPE_ASSIGN === $send->type) {
                $return[] = $send;
            }
        }
        $return[] = new OpCode(OpCode::TYPE_FUNCCALL_INIT, $callName);
        foreach ($argSends as $send) {
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
        Block $block
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
        $this->storeCompileTimeGlobalConst($constName, $block->constants[$valueSlot]);
        $ops = [new OpCode(
            OpCode::TYPE_DECLARE_GLOBAL_CONST,
            $constNameSlot,
            $valueSlot,
            $caseInsensitiveSlot
        )];
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
     * Zend zend_compile.c: nullsafe ?-> in l-value position is a compile-time fatal (#5323).
     *
     * @param Op[] $ops
     */
    private function isNullsafePropertyFetchInWriteContext(array $ops, int $index): bool
    {
        $fetch = $ops[$index] ?? null;
        if (!$fetch instanceof Op\Expr\NullsafePropertyFetch) {
            return false;
        }

        return $this->operandUsedInWriteContext($ops, $index + 1, $fetch->result);
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
     * php-cfg result temps for Expr ops do not chain `original` back to the producer (#5323).
     *
     * @param Op[] $ops
     */
    private function operandIsNullsafePropertyFetchResult(?Operand $operand, array $ops): bool
    {
        if (null === $operand) {
            return false;
        }
        foreach ($ops as $child) {
            if (!$child instanceof Op\Expr\NullsafePropertyFetch) {
                continue;
            }
            if ($this->operandsChainEqual($child->result, $operand)) {
                return true;
            }
        }

        return false;
    }

    protected function lvalueContainsNullsafePropertyFetch(?Operand $operand, ?Block $block = null): bool
    {
        if (null === $operand) {
            return false;
        }
        while ($operand instanceof Temporary) {
            if ($operand->original instanceof Op\Expr\NullsafePropertyFetch) {
                return true;
            }
            if ($operand->original instanceof Op\Expr\ArrayDimFetch) {
                return $this->lvalueContainsNullsafePropertyFetch($operand->original->var, $block);
            }
            if ($operand->original instanceof Op\Expr\PropertyFetch) {
                return $this->lvalueContainsNullsafePropertyFetch($operand->original->var, $block);
            }
            if (null === $operand->original) {
                break;
            }
            $operand = $operand->original;
        }
        if ($operand instanceof Op\Expr\NullsafePropertyFetch) {
            return true;
        }
        if ($operand instanceof Op\Expr\ArrayDimFetch) {
            return $this->lvalueContainsNullsafePropertyFetch($operand->var, $block);
        }
        if (null !== $block && null !== $block->orig) {
            if ($this->operandIsNullsafePropertyFetchResult($operand, $block->orig->children)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Zend zend_compile.c: nullsafe ?-> in l-value position is a compile-time fatal (#5323).
     *
     * @return never
     */
    protected function rejectNullsafeInWriteContext(?Operand $var, ?Block $block = null): void
    {
        if ($this->lvalueContainsNullsafePropertyFetch($var, $block)) {
            $this->throwCompileError("Can't use nullsafe operator in write context");
        }
        if (null !== $block && null !== $block->orig && null !== $var) {
            $dimFetch = $this->unwrapArrayDimFetch($var);
            if (null !== $dimFetch
                && $this->operandIsNullsafePropertyFetchResult($dimFetch->var, $block->orig->children)) {
                $this->throwCompileError("Can't use nullsafe operator in write context");
            }
        }
    }

    /**
     * Zend zend_compile.c: lone `[...$a] = $rhs` is a compile-time fatal (#6936).
     *
     * @param Op[] $ops
     *
     * @return never
     */
    private function rejectLoneListSpreadAssign(array $ops, int $start): void
    {
        if (!$this->isListSpreadAssignOp($ops[$start]) || $this->isListDestructSpreadTail($ops, $start)) {
            return;
        }
        /** @var Op\Expr\Assign $spread */
        $spread = $ops[$start];
        $sourceFile = $spread->getFile() ?? '';
        if ('' === $sourceFile) {
            $sourceFile = 'unknown';
        }
        throw new CompileFatal(
            $sourceFile,
            max(1, $spread->getLine()),
            'Spread operator is not supported in assignments'
        );
    }

    /**
     * Zend zend_compile.c: list()/[] slots on `new` property/array offsets are not writable (#6691, #7286).
     *
     * Scan every assign in the destructuring group — php-cfg may emit New/PropertyFetch between
     * the RHS dim fetch and Assign so dim-fetch walking alone misses slots.
     *
     * @param Op[] $ops
     *
     * @return never
     */
    private function rejectListDestructNewExprWriteTargets(array $ops, int $start, int $end, Block $block): void
    {
        for ($i = $start; $i <= $end; ++$i) {
            $op = $ops[$i];
            if (!$op instanceof Op\Expr\Assign) {
                continue;
            }
            if ($this->lvalueContainsNewExpr($op->var, $block)) {
                $this->throwListDestructNewExprWriteFatal($op);
            }
        }
    }

    /**
     * @return never
     */
    private function throwListDestructNewExprWriteFatal(Op\Expr\Assign $assign): void
    {
        $sourceFile = $assign->getFile() ?? '';
        if ('' === $sourceFile) {
            $sourceFile = 'unknown';
        }
        throw new CompileFatal(
            $sourceFile,
            max(1, $assign->getLine()),
            'Assignments can only happen to writable values'
        );
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

        return isset($this->compileTimeClassConsts[$lcClass][strtolower($constName)]);
    }

    protected function operandIsCompileTimeConstFetch(?Operand $operand, ?Block $block = null): bool
    {
        return $this->operandIsCompileTimeGlobalConstFetch($operand, $block)
            || $this->operandIsCompileTimeClassConstFetch($operand, $block);
    }

    /**
     * Zend zend_compile.c: mutating a const/class-const array is a compile-time fatal (#6935, #5409).
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
                if ($this->operandIsCompileTimeConstFetch($propFetch->var, $block)) {
                    return true;
                }

                return $this->lvalueContainsGlobalConstFetch($propFetch->var, $block);
            }
            if ($operand->original instanceof Op\Expr\ArrayDimFetch) {
                /** @var Op\Expr\ArrayDimFetch $dimFetch */
                $dimFetch = $operand->original;
                if ($this->operandIsCompileTimeConstFetch($dimFetch->var, $block)) {
                    return true;
                }

                return $this->lvalueContainsGlobalConstFetch($dimFetch->var, $block);
            }

            return $this->lvalueContainsGlobalConstFetch($operand->original, $block);
        }
        if (null !== $block->orig) {
            $propFetch = $this->findPropertyFetchForResult($operand, $block);
            if (null !== $propFetch) {
                if ($this->operandIsCompileTimeConstFetch($propFetch->var, $block)) {
                    return true;
                }

                return $this->lvalueContainsGlobalConstFetch($propFetch->var, $block);
            }
            $dimFetch = $this->findArrayDimFetchForResult($operand, $block);
            if (null !== $dimFetch) {
                if ($this->operandIsCompileTimeConstFetch($dimFetch->var, $block)) {
                    return true;
                }

                return $this->lvalueContainsGlobalConstFetch($dimFetch->var, $block);
            }
        }

        return $this->operandIsCompileTimeConstFetch($operand, $block);
    }

    /**
     * @return never
     */
    protected function rejectGlobalConstInWriteContext(?Operand $var, ?Block $block = null): void
    {
        if (!$this->lvalueContainsGlobalConstFetch($var, $block)) {
            return;
        }
        $this->throwCompileError('Cannot use temporary expression in write context');
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
    ): void {
        if (!$this->lvalueContainsNewExpr($var, $block)) {
            return;
        }
        if (null !== $assignExpr && null !== $block && null !== $this->findArrayDimFetchForResult($assignExpr, $block)) {
            if ($assignOp instanceof Op\Expr\Assign) {
                $this->throwListDestructNewExprWriteFatal($assignOp);
            }
            $this->throwCompileError('Assignments can only happen to writable values');
        }
        $this->throwCompileError('Cannot use temporary expression in write context');
    }

}
