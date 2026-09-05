<?php

# This file is generated, changes you make will be lost.
# Make your changes in /compiler/lib/JIT.pre instead.

/*
 * This file is part of PHP-Compiler, a PHP CFG Compiler for PHP code
 *
 * @copyright 2015 Anthony Ferrara. All rights reserved
 * @license MIT See LICENSE at the root of the project for more info
 */

namespace PHPCompiler;

require_once __DIR__.'/OpCodeNames.php';
require_once __DIR__.'/JIT/Concern/CompileBlockInternal.php';
require_once __DIR__.'/JIT/Concern/InitJitMethodCall.php';
require_once __DIR__.'/JIT/Concern/AssignOperand.php';
require_once __DIR__.'/JIT/Concern/AdaptByRefCallArgs.php';
require_once __DIR__.'/JIT/Concern/EmitJitReturn.php';
require_once __DIR__.'/JIT/Concern/TernaryJumpIfEchoMerge.php';
require_once __DIR__.'/JIT/Concern/M3EmitTuSidecarLinktime.php';
require_once __DIR__.'/JIT/Concern/CompileClassAndTraitUses.php';
require_once __DIR__.'/JIT/Concern/InitJitStaticCall.php';
require_once __DIR__.'/JIT/Concern/CompileIncDecAndConcatFlatten.php';
require_once __DIR__.'/JIT/Concern/DateTimeConstructAndMutationMeta.php';
require_once __DIR__.'/JIT/Concern/DomCompileTimeTagMeta.php';
require_once __DIR__.'/JIT/Concern/CoerceReturnPropertyDeclaringAndByRef.php';
require_once __DIR__.'/JIT/Concern/PropertyIncDecCompile.php';
require_once __DIR__.'/JIT/Concern/CallResultCompileTimePropagate.php';
require_once __DIR__.'/JIT/RuntimeInitVmContext.php';
require_once __DIR__.'/JIT/RuntimeInitCompiler.php';
require_once __DIR__.'/JIT/RuntimeInitParsePipeline.php';
require_once __DIR__.'/JIT/M5ParserAstPeer.php';
require_once __DIR__.'/JIT/RuntimeParseM5Native.php';
require_once __DIR__.'/JIT/RuntimeParseM5PhpCfgParser.php';
require_once __DIR__.'/JIT/RuntimeParseM5AstPeer.php';
require_once __DIR__.'/JIT/M5TrivialEchoScript.php';
require_once __DIR__.'/JIT/M5TrivialEchoNative.php';
require_once __DIR__.'/JIT/RuntimePrepareSpineIdentity.php';
require_once __DIR__.'/JIT/M3EmitTuTrivialEchoAot.php';
require_once __DIR__.'/JIT/VmSpineSmokeNative.php';
require_once __DIR__.'/JIT/VmDriverExecuteNative.php';
require_once __DIR__.'/JIT/VmUnitProbeExecuteNative.php';

use PHPCfg\Operand;
use PHPCfg\Op;
use PHPTypes\Type;
use PHPCompiler\Compiler\AttributeClassRegistry;
use PHPCompiler\Compiler\AttributeNames;
use PHPCompiler\JIT\Builtin\AttributeRegistry;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\IssetHelper;
use PHPCompiler\JIT\SelfHostBuiltinPolicy;
use PHPCompiler\JIT\Variable;
use PHPCompiler\Func as CoreFunc;
use PHPLLVM;

class JIT {

    use CompileBlockInternal;
    use InitJitMethodCall;
    use AssignOperand;
    use AdaptByRefCallArgs;
    use EmitJitReturn;
    use TernaryJumpIfEchoMerge;
    use M3EmitTuSidecarLinktime;
    use CompileClassAndTraitUses;
    use InitJitStaticCall;
    use CompileIncDecAndConcatFlatten;
    use DateTimeConstructAndMutationMeta;
    use DomCompileTimeTagMeta;
    use CoerceReturnPropertyDeclaringAndByRef;
    use PropertyIncDecCompile;
    use CallResultCompileTimePropagate;

    private static int $functionNumber = 0;
    private static int $blockNumber = 0;
    /** Nested php-in-PHP helper compiles during an outer JIT::compile() (#10528). */
    private static int $compileDepth = 0;

    public int $optimizationLevel = 3;

    private array $stringConstant = [];
    private array $intConstant = [];
    private array $builtIns = [];

    private array $queue = [];

    /**
     * Deferred single-use CONCAT temps: operand id → leaf operands to append later (#36386).
     *
     * @var array<int, list<\PHPCfg\Operand>>
     */
    private array $concatPendingLeaves = [];

    /**
     * Trait method bodies deferred on abstract composers until a concrete subclass exists
     * so `$this->abstractMethod()` can subtype-dispatch (Psr\Log\LoggerTrait → AbstractLogger, #36382).
     *
     * @var array<string, list<array{block: Block, funcName: string, className: string, traitName: string}>>
     */
    private array $deferredAbstractTraitMethodBodies = [];

    /** @var \SplObjectStorage<OpCode, true> DECLARE_GLOBAL_CONST opcodes that registered (#4941). */
    private \SplObjectStorage $registeredGlobalConstDeclareOpcodes;

    private ?Block $m3EmitTuMainBlock = null;
    private ?Block $m3CompileDriverMainBlock = null;
    private bool $m3EmitTuRuntimeSpineLowered = false;
    private bool $m3CompileDriverRuntimeSpineLowered = false;
    private ?Block $m3EmitTuTrivialEchoBlock = null;
    private ?string $m3EmitTuTrivialEchoSource = null;
    private bool $m3EmitTuSidecarsCached = false;
    /** Parsed lib/Compiler.php CFG — reuse across M3 emit spine method lowers (#17150). */
    private ?\PHPCfg\Script $m3EmitTuCompilerPhpScript = null;

    public Context $context;

    public function __construct(Context $context) {
        $this->context = $context;
        $context->activeJitCompiler = $this;
        $this->registeredGlobalConstDeclareOpcodes = new \SplObjectStorage();
    }

    public function compile(Block $block): PHPLLVM\Value {
        ++self::$compileDepth;
        try {
            if (self::$compileDepth > 1) {
                return JIT\NestedJitCompileScope::run($this->context, function () use ($block): PHPLLVM\Value {
                    return $this->compileUnscoped($block);
                });
            }

            return $this->compileUnscoped($block);
        } finally {
            --self::$compileDepth;
        }
    }

    private function compileUnscoped(Block $block): PHPLLVM\Value {
        JIT\Progress::noteFunction('jit_compile_begin');
        $this->context->resetScriptLocalBindings();
        if (
            is_string($this->context->aotSourceFilename ?? null)
            && '' !== $this->context->aotSourceFilename
            && '' === $block->scriptPath()
        ) {
            $block->setScriptPath($this->context->aotSourceFilename);
        }
        $mainPath = $block->scriptPath();
        if ('' !== $mainPath) {
            $this->context->recordJitIncludedFile($mainPath);
        }
        $this->registeredGlobalConstDeclareOpcodes = new \SplObjectStorage();
        if ($this->shouldUseM3EmitTuNativeBridge() && $this->isM3EmitTuScriptMain($block)) {
            // Inventory emit-helper reuses thin TU spine (#3070); argv-only inventory keeps compile_driver {main}.
            $inventoryEmitHelper = $this->shouldUseM3InventoryEmitDriver($block)
                && $this->isM3CompileDriverBundleScriptMain($block)
                && $this->shouldUseEmitHelperLinkStubs();
            if ($inventoryEmitHelper || !$this->shouldUseM3InventoryEmitDriver($block) || !$this->isM3CompileDriverBundleScriptMain($block)) {
                $this->m3EmitTuMainBlock = $block;
            }
        }
        if (
            $this->isM4BinCompileScriptMain($block)
            && (
                $this->shouldUseM4BinCompileArgvMainNative()
                || $this->shouldUseHelloworldBinCompileInventoryArgvLink()
            )
        ) {
            $this->m3CompileDriverMainBlock = $block;
        }
        if ($this->shouldUseM3CompileDriverMainNative() && $this->isM3CompileDriverBundleScriptMain($block)) {
            $path = $block->scriptPath();
            // bootstrap_loop_smoke/compile_driver.php delegates to helloworld; use that {main} (#2893).
            if (!str_contains($path, 'bootstrap_loop_smoke/compile_driver.php')) {
                // M4 bin/compile.php argv driver must keep honest {main}; do not replace with compile_driver (#2930).
                if (null === $this->m3CompileDriverMainBlock
                    || !$this->isM4BinCompileScriptMain($this->m3CompileDriverMainBlock)) {
                    $this->m3CompileDriverMainBlock = $block;
                }
            }
        }
        if (
            $this->shouldUseM3CompileDriverRealLowering()
            && (null !== $this->m3EmitTuMainBlock || null !== $this->m3CompileDriverMainBlock)
        ) {
            $this->ensureM3EmitTuCompilerRuntimeCompileDeps();
            // Stub-only inventory argv may pre-declare null parse/compileEmitSmoke.
            // Real-lower / M5 argv seed must not — null LLVM entry blocks poison later
            // Runtime.php lowering (#26756 / re-#23468).
            if ($this->shouldEnsureInventoryArgvParseHelperStubs()
                && !$this->shouldRealLowerInventoryArgvParseSpine()
            ) {
                $this->ensureM3EmitTuRuntimeParseSpineDeps();
                $inventoryArgvStubBlock = $this->m3CompileDriverMainBlock ?? $this->m3EmitTuMainBlock;
                if (null !== $inventoryArgvStubBlock) {
                    $this->ensureM3EmitTuRuntimeParseAndCompileDeclBeforeQueue(
                        ['parseandcompile' => true, 'parseandcompileemitsmoke' => true],
                        $inventoryArgvStubBlock
                    );
                    $standaloneLc = strtolower('PHPCompiler\\Runtime::standalone');
                    if (!isset($this->context->functions[$standaloneLc])) {
                        $this->emitM3EmitTuRuntimeStandaloneStubNative(
                            $this->llvmInternalName('PHPCompiler\\Runtime::standalone'),
                            'PHPCompiler\\Runtime::standalone',
                            $inventoryArgvStubBlock
                        );
                    }
                }
            }
        }
        $emitHelperStubBlock = $this->m3CompileDriverMainBlock ?? $this->m3EmitTuMainBlock;
        if (null !== $emitHelperStubBlock && ($this->shouldStubInventoryEmitHelperBundledBodies() || $this->shouldRealLowerInventoryArgvParseSpine())) {
            // Identity stubs with real signatures BEFORE parse host-lower — void stubs from
            // {main}'s Block make prepare look void and leave $code null (#26756 / #11809).
            if ($this->shouldRealLowerInventoryArgvParseSpine() || $this->shouldUseM5DriverHostCompile()) {
                $this->ensureM5ArgvPrepareSpineIdentityStubs();
            }
            foreach (['preparesourceforparser', 'preprocesssourceforparse', 'rewritesourcebeforeparser'] as $methodLc) {
                $logical = 'PHPCompiler\\Runtime::'.$methodLc;
                $lc = strtolower($logical);
                if (isset($this->context->functions[$lc])) {
                    continue;
                }
                $this->compileSkippedCompilerSplitCfgStub(
                    $this->llvmInternalName($logical),
                    $emitHelperStubBlock,
                    $logical
                );
            }
            $parseLc = 'phpcompiler\\runtime::parse';
            if (!isset($this->context->functions[$parseLc])
                && !$this->shouldRealLowerInventoryArgvParseSpine()
            ) {
                $this->emitM3EmitTuRuntimeParseStubNative(
                    $this->llvmInternalName('PHPCompiler\\Runtime::parse'),
                    'PHPCompiler\\Runtime::parse',
                    $emitHelperStubBlock
                );
            }
            $runtimeEmitLc = 'phpcompiler\\runtime::compileemitsmoke';
            if (!isset($this->context->functions[$runtimeEmitLc])) {
                $this->emitM3EmitTuRuntimeCompileEmitSmokeNative(
                    $this->llvmInternalName('PHPCompiler\\Runtime::compileEmitSmoke'),
                    'PHPCompiler\\Runtime::compileEmitSmoke',
                    $emitHelperStubBlock
                );
            }
            $compilerEmitLc = 'phpcompiler\\compiler::compileemitsmoke';
            if (!isset($this->context->functions[$compilerEmitLc])) {
                $this->emitM3EmitTuCompilerCompileEmitSmokeNativeFunction(
                    $this->llvmInternalName('PHPCompiler\\Compiler::compileEmitSmoke'),
                    'PHPCompiler\\Compiler::compileEmitSmoke'
                );
            }
        }
        JIT\Progress::noteFunction('jit_compile_compile_block_begin');
        $this->context->jitUndeclaredInstancePropertyWrites = Block::collectJitUndeclaredInstancePropertyWrites($block);
        $return = $this->compileBlock($block);
        JIT\Progress::noteFunction('jit_compile_compile_block_done');
        JIT\Progress::noteFunction('jit_compile_run_queue_begin');
        if (
            $this->isM4BinCompileScriptMain($block)
            && (
                $this->shouldUseM4BinCompileArgvMainNative()
                || $this->shouldUseHelloworldBinCompileInventoryArgvLink()
            )
        ) {
            $this->filterM4InventoryArgvMainFromQueue();
        }
        $inventoryArgvStubOnly = $this->shouldEnsureInventoryArgvParseHelperStubs()
            && !$this->shouldRealLowerInventoryArgvParseSpine();
        if (!$this->shouldUseM4InventoryArgvNativeEmitRebuild($block) && !$inventoryArgvStubOnly) {
            $this->runQueue();
        }
        JIT\Progress::noteFunction('jit_compile_run_queue_done');
        JIT\Progress::noteFunction('jit_compile_finalize_m3_emit_tu_spine_begin');
        $this->finalizeM3EmitTuRuntimeSpineAfterQueue();
        JIT\Progress::noteFunction('jit_compile_finalize_m3_emit_tu_spine_done');

        JIT\Progress::noteFunction('jit_compile_done');
        if ('1' === Config::getenv('PHP_COMPILER_DETACH_CFG_AFTER_JIT')) {
            Block::detachCfgTree($block, false);
        }
        JIT\Progress::noteFunction('jit_compile_return_begin');
        return $return;
    }

    public function compileFunc(CoreFunc $func): void {
        if ($func instanceof CoreFunc\PHP) {
            $block = $func->block;
            if (
                null !== $block->func
                && '{main}' === $block->func->name
                && $this->isM4BinCompileScriptMain($block)
                && (
                    $this->shouldUseM4BinCompileArgvMainNative()
                    || $this->shouldUseHelloworldBinCompileInventoryArgvLink()
                )
            ) {
                return;
            }
            $name = $func->getName();
            // Large switch crashes LLVM during JIT (issue #540); VM uses host PHP for this helper.
            if ('opcode_type_name' === $name || str_ends_with($name, '\\opcode_type_name')) {
                return;
            }
            $skipName = $this->jitFunctionSkipName($name, $func->block);
            if (
                $this->shouldUseSelfHostJitStubs()
                && JIT\VmUnitProbeExecuteNative::isVmUnitProbeRunName($skipName)
            ) {
                $this->compileVmUnitProbeRunNative(
                    $this->llvmInternalName($name),
                    $func->block,
                    $name
                );

                return;
            }
            if (
                $this->shouldUseSelfHostJitStubs()
                && JIT\VmDriverExecuteNative::isBinVmRunName($skipName, $func->block)
            ) {
                $this->compileBinVmRunNative(
                    $this->llvmInternalName($name),
                    $func->block,
                    $name
                );

                return;
            }
            if (
                $this->isSkippedVmHotPathName($skipName)
                || $this->isSkippedCompilerHotPathName($skipName)
                || $this->isSkippedWebBootstrapHotPathName($skipName)
                || $this->isSkippedLibSpineSmokeHotPathName($skipName)
                || $this->isSkippedSelfHostEntryName($skipName)
                || $this->isSkippedBootstrapInterpreterHotPathName($skipName)
            ) {
                $this->compileBlock($func->block, $name);

                return;
            }
            $this->compileBlock($func->block, $name);
            $this->runQueue();
            return;
        } elseif ($func instanceof CoreFunc\JIT) {
            // No need to do anything, already compiled
            return;
        } elseif ($func instanceof CoreFunc\Internal) {
            $name = strtolower($func->getName());
            if (SelfHostBuiltinPolicy::shouldExternalStub($name)) {
                $this->context->functionProxies[$name] = new JIT\Call\ExternalMethod($func->getName());

                return;
            }
            $this->context->functionProxies[$name] = $func;

            return;
        }
        throw new \LogicException("Unknown func type encountered: " . get_class($func));
    }

    private function runQueue(): void {
        // Upgrade no-throw marks once callees from later enqueue order are proven
        // (top→mid→leaf chains; declaration order must not matter) (#36386).
        JIT\NoThrowCallElision::refineFixpoint($this->context);
        while (!empty($this->queue)) {
            $run = array_shift($this->queue);
            JIT\Progress::notePhase('jit_run_queue_item');
            try {
                $block = $run[1] ?? null;
                if ($block instanceof Block && null !== $block->func) {
                    JIT\Progress::noteEntry($block->func->getScopedName());
                }
            } catch (\Throwable $e) {
                // best-effort only: progress breadcrumbs must not affect codegen
            }
            $classId = $this->context->scope->classId;
            $className = $this->context->scope->className;
            $calledClassName = $this->context->scope->calledClassName;
            $this->context->scope = new JIT\Scope();
            $this->context->scope->classId = $classId;
            $this->context->scope->className = $className;
            $this->context->scope->calledClassName = $calledClassName;
            $this->context->scopeStack = [];
            $this->context->inlineIncludeReturnOperands = [];
            $this->context->inlineIncludeReturnHolders = [];
            $this->context->coalesceAssignTargets = new \SplObjectStorage();
            $this->context->coalesceMergeSlotOperands = [];
            $this->context->ternaryEchoPhiByAliasSlot = [];
            $this->context->ternaryEchoLiteralConditionSlot = null;
            $this->context->ternaryEchoLiteralIf = null;
            $this->context->ternaryEchoLiteralElse = null;
            $this->context->listUnpackSkipAssignPath = false;
            $this->context->listUnpackMergeLlvmBlocks = new \SplObjectStorage();
            $this->context->listUnpackMergeNullInitTargets = [];
            $this->context->listUnpackAssignCallerBlock = null;
            $this->context->listUnpackAssignRootBlock = null;
            $this->context->listUnpackAssignSlots = [];
            $this->context->jitPropertyHookRawProperty = null;
            // Each queued CFG function gets a fresh try/catch stack — dispatch BBs are per-LLVM-function (#3012).
            $this->context->tryCatch->reset();
            // Per-method locals must not reuse another LLVM function's slots (#878, MiniWebApp verify).
            $this->context->namedVariableBindings = [];
            $this->context->refAliasNames = [];
            $this->context->foreachByRefLocalNames = [];
            $this->context->jitImportedGlobalNames = [];
            $llvmFunc = $run[0];
            $cfgBlock = $run[1] ?? null;
            $traitComposing = $this->resolveTraitComposingClassForQueue($llvmFunc, $cfgBlock);
            if ('' !== $traitComposing) {
                $this->context->scope->traitComposingClassName = $traitComposing;
                $composingLc = strtolower(ltrim($traitComposing, '\\'));
                if ('' === $this->context->scope->className
                    || $this->context->type->object->isTraitClass(strtolower(ltrim($this->context->scope->className, '\\')))) {
                    $this->context->scope->className = $composingLc;
                }
                if (0 === $this->context->scope->classId
                    && $this->context->type->object->hasDeclaredClass($traitComposing)) {
                    $this->context->scope->classId = $this->context->type->object->lookup($traitComposing);
                }
            }
            if ($cfgBlock instanceof Block && null !== $cfgBlock->func) {
                $this->context->activeFunction = strtolower($cfgBlock->func->getScopedName());
                // Queued method bodies must bind self:: to *this* method's declaring class.
                // runQueue copies the previous item's className; after DECLARE_CLASS popScope it is
                // empty, and after another helper's method it is stale — both break NestedJIT
                // self::$props (#22037). Traits keep composing-class binding from above (#18878).
                if (null !== $cfgBlock->func->class) {
                    $declaring = (string) $cfgBlock->func->class->value;
                    $declLc = strtolower(ltrim($declaring, '\\'));
                    if (!$this->context->type->object->isTraitClass($declLc)) {
                        $this->context->scope->className = $declLc;
                        if ($this->context->type->object->hasDeclaredClass($declaring)) {
                            $this->context->scope->classId = $this->context->type->object->lookup($declaring);
                        }
                        $this->context->scope->calledClassName = $declLc;
                    }
                } elseif (!$this->queuedFuncIsClassMethodAlias($llvmFunc, $cfgBlock)) {
                    // Free function after a method: do not keep the prior item's className.
                    // Otherwise instanceMethodUsesThis() treats f() as M::f and ARG_RECV shifts
                    // slots (c07_method "Missing required argument 1", #23971).
                    $this->context->scope->className = '';
                    $this->context->scope->classId = 0;
                    $this->context->scope->calledClassName = '';
                }
            } else {
                foreach ($this->context->functions as $name => $candidate) {
                    if ($candidate === $llvmFunc) {
                        $this->context->activeFunction = $name;
                        break;
                    }
                }
            }
            $savedLoweringLlvm = $this->context->loweringLlvmFunction;
            if ($llvmFunc instanceof \PHPLLVM\Value\Function_) {
                $this->context->loweringLlvmFunction = $llvmFunc;
                $this->bindBlockStorageForFunc($llvmFunc);
            }
            try {
                $this->compileBlockInternal($llvmFunc, $cfgBlock, null, null, 0, false, ...$run[2]);
            } finally {
                $this->context->loweringLlvmFunction = $savedLoweringLlvm;
            }
        }
    }

    /**
     * Trait method bodies queue as T::method but register a composing alias C::method (#18878).
     */
    private function resolveTraitComposingClassForQueue(PHPLLVM\Value $llvmFunc, ?Block $cfgBlock): string
    {
        if (!$cfgBlock instanceof Block || null === $cfgBlock->func?->class) {
            return '';
        }
        $traitLc = strtolower(ltrim($cfgBlock->func->class->value, '\\'));
        if (!$this->context->type->object->isTraitClass($traitLc)) {
            return '';
        }
        $methodLc = strtolower($cfgBlock->func->name);
        foreach ($this->context->functions as $name => $candidate) {
            if ($candidate !== $llvmFunc || !str_contains($name, '::')) {
                continue;
            }
            [$classPart, $methodPart] = explode('::', $name, 2);
            if (strtolower($methodPart) !== $methodLc) {
                continue;
            }
            $classLc = strtolower(ltrim($classPart, '\\'));
            if ($classLc === $traitLc || $this->context->type->object->isTraitClass($classLc)) {
                continue;
            }
            if ($this->context->type->object->hasDeclaredClass($classPart)) {
                return $this->context->type->object->classNameForId(
                    $this->context->type->object->lookup($classPart)
                );
            }

            return $classPart;
        }

        return '';
    }

    /** Main `{main}` defers __destruct until `phpc_gc_run_shutdown_destructors` (#4013). */
    private function emitJitDestructAllowDelref(Block $block): void
    {
        if (!$this->context->type->object->hasUserDestructors()) {
            return;
        }
        \PHPCompiler\JIT\Builtin\GcCollectCyclesRuntime::ensureLinked($this->context);
        $deferDelref = true;
        if (null !== $block->func) {
            $name = $block->func->name;
            $deferDelref = '{main}' === $name
                || '__destruct' === $name
                || str_ends_with($name, '::__destruct');
        }
        $this->context->builder->call(
            $this->context->lookupFunction('phpc_destruct_set_allow_delref'),
            $this->context->getTypeFromString('int32')->constInt($deferDelref ? 0 : 1, false)
        );
    }

    /**
     * ?? on superglobals can disturb inherited include locals; restore before use (#866, #784).
     */
    private function maybeRefreshIncludeBindingsBeforeUse(): void
    {
        // Outer include binding refresh must not run while NestedJIT lowers a helper body
        // (htmlspecialchars inside layout.php include) — it stores into outer frame slots
        // using inner-function alloca indices (#36253).
        if (
            $this->context->inlineIncludeDepth > 0
            && !JIT\NestedJitCompileScope::isActive()
        ) {
            JIT\IncludeHelper::refreshInlineIncludeBindings($this->context);
        }
    }

    /**
     * Branch targets must use CFG entry BBs, not include-updated resume slots (#866, #878).
     * Storage is per-LLVM-function ({@see bindBlockStorageForFunc}). When a CFG Block was
     * previously lowered into another LLVM function, re-lower into {@see $func} once —
     * parent checks use {@see TryCatchHelper::sameLlvmFunction} (wrapper === is unstable; #31101).
     */
    public function jitBranchEntryBlock(Block $branch, PHPLLVM\Value\Function_ $func): PHPLLVM\BasicBlock
    {
        $this->bindBlockStorageForFunc($func);
        if ($this->context->scope->blockEntryStorage->contains($branch)) {
            $entry = $this->context->scope->blockEntryStorage[$branch];
            $entryParent = $entry->getParent();
            if ($entryParent instanceof PHPLLVM\Value\Function_
                && JIT\TryCatchHelper::sameLlvmFunction($entryParent, $func)) {
                return $entry;
            }
        }
        if ($this->context->scope->blockStorage->contains($branch)) {
            $cached = $this->context->scope->blockStorage[$branch];
            $cachedParent = $cached->getParent();
            if ($cachedParent instanceof PHPLLVM\Value\Function_
                && JIT\TryCatchHelper::sameLlvmFunction($cachedParent, $func)) {
                return $cached;
            }
        }

        // Missing or foreign-function mapping: lower into $func (per-func map prevents clobber).
        return $this->compileBlockInternal($func, $branch);
    }

    /**
     * Select (or create) CFG→LLVM BB maps for {@see $func}.
     * php-cfg Block object identity is reused across LLVM functions; a single SplObjectStorage
     * therefore leaks branch targets across functions (MiniWebApp #31101).
     */
    private function bindBlockStorageForFunc(PHPLLVM\Value $func): void
    {
        if (!$func instanceof PHPLLVM\Value\Function_) {
            return;
        }
        foreach ($this->context->blockStorageByLlvmFunc as $slot) {
            if (JIT\TryCatchHelper::sameLlvmFunction($slot[0], $func)) {
                $this->context->scope->blockStorage = $slot[1];
                $this->context->scope->blockEntryStorage = $slot[2];

                return;
            }
        }
        $blockStorage = new \SplObjectStorage();
        $blockEntryStorage = new \SplObjectStorage();
        $this->context->blockStorageByLlvmFunc[] = [$func, $blockStorage, $blockEntryStorage];
        $this->context->scope->blockStorage = $blockStorage;
        $this->context->scope->blockEntryStorage = $blockEntryStorage;
    }

    /** Self-host AOT sets PHP_COMPILER_SELFHOST_AOT=1 (#816, #557). */
    private function shouldUseSelfHostJitStubs(): bool
    {
        $flag = Config::getenv('PHP_COMPILER_SELFHOST_AOT');

        return '1' === $flag || 'true' === strtolower((string) $flag);
    }

    /** User script AOT via bin/compile.php: real closure lowering (#3725). */
    private function shouldStubClosureLowering(): bool
    {
        $userScript = Config::getenv('PHP_COMPILER_AOT_USER_SCRIPT');
        if ('1' === $userScript || 'true' === strtolower((string) $userScript)) {
            return false;
        }
        if ($this->shouldUseVendorPrelinkJitStubs()) {
            return true;
        }

        return $this->shouldUseSelfHostJitStubs();
    }

    /** Bundle-only PHP constants (spine smoke defines; bin/compile.php AOT folds false — #2600). */
    /**
     * Fold OpCode::* class constants when php-cfg scopes the class as Type (#2666).
     */
    private function jitFoldOpCodeClassConstant(Operand $classOp, string $constName): ?JIT\Variable
    {
        if (!$classOp instanceof Operand\Literal) {
            return null;
        }
        $ref = OpCode::class.'::'.$constName;
        if (!defined($ref)) {
            return null;
        }
        $lit = new Operand\Literal(constant($ref));
        $lit->type = Type::int();

        return JIT\Variable::fromLiteral($this->context, $lit);
    }

    private function jitFoldPhpCompilerBundleConstant(string $label): ?JIT\Variable
    {
        if (
            'PHP_COMPILER_LIB_SPINE_SMOKE' !== $label
            && !str_ends_with($label, '\\PHP_COMPILER_LIB_SPINE_SMOKE')
        ) {
            return null;
        }
        // Only compiler_lib_spine_smoke/main.php defines this constant; references from
        // bin/compile.php cli_driver must fold false at AOT link (#2600, #2697).
        $lit = new Operand\Literal(false);
        $lit->type = Type::bool();

        return JIT\Variable::fromLiteral($this->context, $lit);
    }

    /**
     * Link-time only: skip non-jittable ext/ class bodies when building native emit helper (#1983).
     * Does not enable self-host Runtime/Compiler stubs (unlike PHP_COMPILER_SELFHOST_AOT).
     */
    private function shouldUseEmitHelperLinkStubs(): bool
    {
        $flag = Config::getenv('PHP_COMPILER_EMIT_HELPER_LINK');

        return '1' === $flag || 'true' === strtolower((string) $flag);
    }

    /**
     * Inventory compile_driver.php emit-helper link: stub argv driver bodies; native emit bridge only (#2540).
     */
    private function shouldStubInventoryEmitHelperBundledBodies(): bool
    {
        return $this->shouldUseM3InventoryEmitDriver() && $this->shouldUseEmitHelperLinkStubs();
    }

    /**
     * Inventory emit-helper link: parse/CFG spine stub retired on executable argv drivers (#8706).
     * Mirror {@see shouldPrelowerRuntimeStandaloneForKeepObjectEmit} — gen-0/spine/inventory/M4
     * argv links must real-lower Runtime::parse for honest native compile (#2967, #3046, #8708).
     */
    private function shouldStubInventoryEmitParseCompileSpine(): bool
    {
        if ($this->shouldUseM4InventoryArgvNativeEmitRebuild()) {
            // M4 bin/compile.php without inventory emit keeps stub spine (#2930); inventory emit needs sidecars + parse (#2967).
            return !$this->shouldUseM3InventoryEmitDriver();
        }
        if (!$this->shouldStubInventoryEmitHelperBundledBodies()) {
            return false;
        }
        if ($this->shouldUseSelfHostExecutableEmit()
            || $this->shouldUseVendorPrelinkExecutableEmit()
            || $this->shouldUseM4BinCompileArgvMainNative()
            || ($this->shouldUseM3CompileDriverMainNative() && $this->shouldUseEmitHelperLinkStubs())
        ) {
            return false;
        }

        return true;
    }

    /**
     * M5 vendor prelink: AOT-compile literal-require vendor bundles without full class lowering (#1416).
     * Set by script/bootstrap-vendor-objects.php during --compile only.
     */
    private function shouldUseVendorPrelinkJitStubs(): bool
    {
        $flag = Config::getenv('PHP_COMPILER_VENDOR_PRELINK');

        return '1' === $flag || 'true' === strtolower((string) $flag);
    }

    /**
     * M5 vendor cold boot: argv compile drivers must real-lower Runtime::standalone so
     * PHP_COMPILER_KEEP_OBJECT_FILE=1 leaves buildBase.o (not sidecar copy only — #3036).
     */
    private function shouldPrelowerRuntimeStandaloneForKeepObjectEmit(): bool
    {
        if (!$this->shouldUseM3CompileDriverRealLowering()) {
            return false;
        }
        // Sidecar host-compiles (bin/compile.php blob, vendor bundles) must keep standalone stubbed.
        if ('1' === (string) Config::getenv('PHP_COMPILER_M3_EMIT_SIDECAR_RECURSION_GUARD')) {
            return false;
        }
        if ('1' === (string) Config::getenv('PHP_COMPILER_M3_EMIT_TU')) {
            return false;
        }

        return $this->shouldUseM4BinCompileArgvMainNative()
            || ($this->shouldUseM3CompileDriverMainNative() && $this->shouldUseEmitHelperLinkStubs())
            || $this->shouldUseVendorPrelinkExecutableEmit();
    }

    /** M5 vendor argv compile: emit-helper spine real-lowers parse/compile/standalone (#3036). */
    private function shouldUseVendorPrelinkObjectEmit(): bool
    {
        if (!$this->shouldUseVendorPrelinkJitStubs()) {
            return false;
        }
        $keep = Config::getenv('PHP_COMPILER_KEEP_OBJECT_FILE');

        return '1' === $keep || 'true' === strtolower((string) $keep);
    }

    /** M5 spine link: prelinked vendor .o + native executable (not object-only — #3052). */
    private function shouldUseVendorPrelinkExecutableEmit(): bool
    {
        if (!$this->shouldUseVendorPrelinkJitStubs()) {
            return false;
        }
        if ($this->shouldUseVendorPrelinkObjectEmit()) {
            return true;
        }
        $selfhost = Config::getenv('PHP_COMPILER_SELFHOST_AOT');

        return '1' === $selfhost || 'true' === strtolower((string) $selfhost);
    }

    /** Gen-0 argv driver + self-host link: real-lower standalone when not vendor-prelink (#3053). */
    private function shouldUseSelfHostExecutableEmit(): bool
    {
        if ($this->shouldUseVendorPrelinkJitStubs()) {
            return false;
        }
        $selfhost = Config::getenv('PHP_COMPILER_SELFHOST_AOT');

        return '1' === $selfhost || 'true' === strtolower((string) $selfhost);
    }

    private function shouldSkipExternalClassBodyLowering(int $classId): bool
    {
        if ($this->isBundledSuperglobalsClass($classId)) {
            return true;
        }
        $className = strtolower($this->context->type->object->classNameForId($classId));
        if ('' !== $className && str_ends_with($className, 'jithelper')) {
            return false;
        }
        if ('' === $className) {
            return $this->shouldUseSelfHostJitStubs()
                || $this->shouldUseEmitHelperLinkStubs()
                || $this->shouldUseM3EmitTuNativeBridge()
                || $this->shouldUseVendorPrelinkJitStubs();
        }
        if ($this->isBundledJitExternalClassPrefix($className)) {
            return true;
        }
        if ($this->shouldUseEmitHelperLinkStubs()
            || $this->shouldUseM3EmitTuNativeBridge()
            || $this->shouldUseVendorPrelinkJitStubs()
        ) {
            return true;
        }
        // bin/compile.php sets PHP_COMPILER_SELFHOST_AOT=1 for LLVM stability (#2600), but user
        // script classes (including synthetic AnonymousClass@line) still need method lowering (#3098).
        if ($this->shouldUseSelfHostJitStubs()) {
            return $this->isSelfHostBundledClassPrefix($className);
        }

        return false;
    }

    private function isBundledJitExternalClassPrefix(string $classLc): bool
    {
        return str_starts_with($classLc, 'phpcfg\\')
            || str_starts_with($classLc, 'phptypes\\')
            || str_starts_with($classLc, 'phpllvm\\')
            || str_starts_with($classLc, 'nikic\\');
    }

    private function isSelfHostBundledClassPrefix(string $classLc): bool
    {
        return $this->isBundledJitExternalClassPrefix($classLc)
            || str_starts_with($classLc, 'phpcompiler\\')
            || 'compiler' === $classLc
            || 'runtime' === $classLc
            || str_starts_with($classLc, 'ircmaxell\\');
    }

    /** Opt-in when linking test/selfhost compile_driver.php bundles (#1056, #1768). */
    private function shouldUseM3CompileDriverMainNative(): bool
    {
        if (!$this->shouldUseM3CompileDriverRealLowering()) {
            return false;
        }
        if ($this->shouldUseM4BinCompileArgvMainNative()) {
            return true;
        }
        $flag = Config::getenv('PHP_COMPILER_M3_COMPILE_DRIVER_MAIN');

        return '1' === $flag || 'true' === strtolower((string) $flag);
    }

    /**
     * True when the active entry is helloworld/bootstrap_loop compile_driver.php under a compiled argv driver.
     *
     * Zend bin/compile.php sets inventory emit env when linking compile_driver.php; native emitMainEntry
     * does not run those putenv hooks, so gate inventory {main} from the entry path (#3053).
     */
    private function isM3HelloworldInventoryCompileDriverTarget(?Block $block = null): bool
    {
        if (!\function_exists('php_compiler_cli_should_skip_entry_driver')) {
            return false;
        }
        /** @var list<string> $paths */
        $paths = [];
        if (null !== $block) {
            $path = $block->scriptPath();
            if ('' !== $path) {
                $paths[] = $path;
            }
        }
        if (null !== $this->m3CompileDriverMainBlock) {
            $path = $this->m3CompileDriverMainBlock->scriptPath();
            if ('' !== $path) {
                $paths[] = $path;
            }
        }
        $fromCtx = $this->context->aotSourceFilename ?? '';
        if ('' !== $fromCtx) {
            $paths[] = $fromCtx;
        }
        foreach (array_unique($paths) as $path) {
            $norm = str_replace('\\', '/', $path);
            if (str_contains($norm, 'compiler_helloworld_smoke/compile_driver.php')
                || str_contains($norm, 'bootstrap_loop_smoke/compile_driver.php')) {
                return true;
            }
        }

        return false;
    }

    /**
     * Inventory-scale M3 emit via compile_driver.php {main} — no separate *_m3_emit_native_entry.php (#2843).
     */
    private function shouldUseM3InventoryEmitDriver(?Block $block = null): bool
    {
        if (!$this->shouldUseM3CompileDriverMainNative()) {
            return false;
        }
        // M4/M5 bin/compile.php host link uses real argv {main} unless inventory emit is explicit (#3004).
        foreach (['PHP_COMPILER_M3_INVENTORY_EMIT_DRIVER', 'BOOTSTRAP_M3_USE_INVENTORY_EMIT_DRIVER'] as $envKey) {
            $flag = getenv($envKey);
            if ('1' === $flag || 'true' === strtolower((string) $flag)) {
                return true;
            }
        }
        if ($this->isM3HelloworldInventoryCompileDriverTarget($block)) {
            return true;
        }

        return false;
    }

    /**
     * Gen-2 helloworld-prefix argv driver re-linking bin/compile.php — inventory {main}, not stub sidecar (#3011).
     */
    private function shouldUseHelloworldBinCompileInventoryArgvLink(): bool
    {
        // Only consulted together with isM4BinCompileScriptMain() — always inventory argv link (#3011).
        return true;
    }

    private function shouldUseM3InventoryEmitForCompileDriverBlock(Block $block): bool
    {
        // M4 bin/compile.php argv driver uses emitMainEntry argv bridge, not compile_driver emit TU (#2930).
        if ($this->isM4BinCompileScriptMain($block) && $this->shouldUseM4BinCompileArgvMainNative()) {
            return false;
        }
        if ($this->shouldUseM3InventoryEmitDriver()) {
            return true;
        }

        return $this->isM4BinCompileScriptMain($block) && $this->shouldUseHelloworldBinCompileInventoryArgvLink();
    }

    /**
     * Inventory argv drivers must real-lower Runtime::parse when not on the M4 stub-spine rebuild path (#2967, #3028).
     */
    private function shouldRealLowerInventoryArgvParseSpine(): bool
    {
        // M5 argv / gen-0 seed: force real parse spine even when M4 bin/compile.php
        // inventory-emit-for-block is false (#26756 / re-#23468).
        if ($this->shouldUseM5DriverHostCompile() && $this->shouldUseM3CompileDriverRealLowering()) {
            return true;
        }

        return $this->shouldUseM3InventoryEmitDriver() && $this->shouldUseM3CompileDriverRealLowering();
    }

    /** Register Runtime parse-diagnostic LLVM stubs for helloworld bin/compile.php inventory argv (#12036). */
    private function shouldEnsureInventoryArgvParseHelperStubs(): bool
    {
        if ($this->shouldRealLowerInventoryArgvParseSpine()) {
            return true;
        }
        $m3Driver = Config::getenv('PHP_COMPILER_M3_COMPILE_DRIVER');
        if ('1' !== $m3Driver && 'true' !== strtolower((string) $m3Driver)) {
            return false;
        }
        $main = $this->m3CompileDriverMainBlock ?? $this->m3EmitTuMainBlock;

        return null !== $main && $this->isM4BinCompileScriptMain($main);
    }

    /**
     * Inventory argv driver real-lowers Runtime::parse but not the full preprocess rewriter chain
     * (SealedClassPreprocessor, PropertyHooks, …) — identity LLVM stubs suffice for gen-0 refresh (#11809).
     */
    private function shouldStubInventoryArgvPreprocessSpineMethods(): bool
    {
        return $this->shouldRealLowerInventoryArgvParseSpine();
    }

    /** Inventory emit TU is compile_driver.php — do not host-compile it again as a link sidecar (#2843). */
    private function shouldSkipM3InventoryEmitDriverSelfSidecar(string $path): bool
    {
        if (!$this->shouldUseM3InventoryEmitDriver()) {
            return false;
        }

        $norm = str_replace('\\', '/', $path);
        if (!str_contains($norm, 'test/selfhost/') || !str_contains($norm, '/compile_driver.php')) {
            return false;
        }
        $mainBlock = $this->m3CompileDriverMainBlock ?? $this->m3EmitTuMainBlock;
        if (null === $mainBlock) {
            return false;
        }
        $mainPath = str_replace('\\', '/', $mainBlock->scriptPath());

        return $mainPath === $norm || str_ends_with($mainPath, '/'.basename($norm));
    }

    private function isM3CompileDriverScriptMain(Block $block): bool
    {
        return null !== $block->func
            && null === $block->func->class
            && '{main}' === $block->func->name;
    }

    /**
     * Host-compile a functional production driver (bin/compile.php) — not link-only sidecar bytes (#1521).
     *
     * Sidecar registration keeps {main} stubbed; set this env when emitting a driver that must run argv/compile.
     */
    private function shouldUseM5DriverHostCompile(): bool
    {
        $flag = Config::getenv('PHP_COMPILER_M5_DRIVER_HOST');

        return '1' === $flag || 'true' === strtolower((string) $flag);
    }

    /**
     * C-floor Runtime::parse (+ PHPCfg peers) — inventory argv compile_driver host link
     * segfaults on NestedJIT PHP CFG (#26756, #36144); same path as M5 argv seed.
     */
    private function shouldUseM5ParseSpineCFloor(): bool
    {
        return $this->shouldUseM5DriverHostCompile() || $this->shouldRealLowerInventoryArgvParseSpine();
    }

    /** Register PHPCfg peers + RuntimeParseM5Native before inventory argv parse spine (#27426). */
    private function ensureM5ParseSpineCFloorSymbols(): void
    {
        if (!$this->shouldUseM5ParseSpineCFloor()) {
            return;
        }
        $m5ForceParserCbs = [
            $this->context,
            fn (string $n): string => $this->llvmInternalName($n),
            function (callable $body): void {
                JIT\NestedJitCompileScope::run($this->context, $body);
            },
            function ($block, string $logical): void {
                $this->compileBlock($block, $logical);
            },
            function (string $logical, $cfgFunc) {
                return $this->context->runtime->compileFunc($logical, $cfgFunc);
            },
            function (string $code, string $path) {
                return $this->context->runtime->parse($code, $path);
            },
        ];
        JIT\RuntimeParseM5AstPeer::ensureMethods(...$m5ForceParserCbs);
        JIT\RuntimeParseM5PhpCfgParser::ensureParse(...$m5ForceParserCbs);
        if ($this->shouldUseM5DriverHostCompile()) {
            $m5TrivialNested = Config::getenv('PHP_COMPILER_M5_TRIVIAL_ECHO_NESTEDJIT');
            if ('1' === $m5TrivialNested || 'true' === strtolower((string) $m5TrivialNested)) {
                $this->ensureM5TrivialEchoScriptParseAndCompileLowered();
            } else {
                JIT\M5TrivialEchoNative::ensureParseAndCompile(
                    $this->context,
                    fn (string $n): string => $this->llvmInternalName($n)
                );
            }
        }
        $parseLogical = 'PHPCompiler\\Runtime::parse';
        $parseLc = strtolower($parseLogical);
        if (!isset($this->context->functions[$parseLc])) {
            JIT\RuntimeParseM5Native::emitFunction(
                $this->context,
                $this->llvmInternalName($parseLogical),
                $parseLogical,
                fn (string $n): string => $this->llvmInternalName($n)
            );
        }
    }

    /**
     * NestedJIT of PHPCfg\Parser::parse under M5 argv host-compile (#27426 / #26756).
     *
     * Vendor parse() has no PHP type hints; CFG defaults to __value__ params/return while
     * RuntimeParseM5Native calls (__object__*, __string__*, __string__*) -> __object__*.
     */
    private function isM5NestedJitPhpCfgParserParse(?string $logicalName): bool
    {
        if (null === $logicalName
            || !$this->shouldUseM5DriverHostCompile()
            || !JIT\NestedJitCompileScope::isActive()
        ) {
            return false;
        }
        $lc = strtolower($logicalName);
        if ('phpcfg\\parser::parse' === $lc
            || 'php\\cfg\\parser::parse' === $lc
            || (str_ends_with($lc, '\\parser::parse') && str_contains($lc, 'cfg'))
        ) {
            return true;
        }
        // activeFunction / llvmInternalName may be mangled PHPCfg_Parser__parse
        if ('phpcfg_parser__parse' === $lc) {
            return true;
        }
        if (str_ends_with($lc, '_parser__parse') && str_contains($lc, 'cfg')) {
            return true;
        }

        return false;
    }

    /**
     * NestedJIT of M5ParserAstPeer::parse under M5 argv (#27426).
     * Typed string $code must stay __string__* for Parser::parse call sites.
     */
    private function isM5NestedJitM5ParserAstPeerParse(?string $logicalName): bool
    {
        if (null === $logicalName
            || !$this->shouldUseM5DriverHostCompile()
            || !JIT\NestedJitCompileScope::isActive()
        ) {
            return false;
        }
        $lc = strtolower($logicalName);

        return 'phpcompiler\\jit\\m5parserastpeer::parse' === $lc
            || 'm5parserastpeer::parse' === $lc
            || str_ends_with($lc, '\\m5parserastpeer::parse')
            || 'phpcompiler_jit_m5parserastpeer__parse' === $lc
            || str_ends_with($lc, '_m5parserastpeer__parse');
    }

    /**
     * Return-ABI string for the function currently being lowered.
     * Prefers the LLVM signature forced at create (Parser::parse → __object__*) over
     * untyped CFG default __value__ (#27426).
     */
    private function effectiveReturnCallbackType(?\PHPCfg\Func $cfgFunc): ?string
    {
        if ($this->isM5NestedJitPhpCfgParserParse($this->context->activeFunction)) {
            return '__object__*';
        }
        $expected = $this->cfgFunctionReturnCallbackType($cfgFunc);
        if (null === $expected && null !== $this->context->activeFunction) {
            $expected = $this->context->functionReturnType[strtolower($this->context->activeFunction)] ?? null;
        }

        return $expected;
    }

    /** Identity prepare/preprocess/rewrite stubs before parse host-lower (#26756 / #11809). */
    private function ensureM5ArgvPrepareSpineIdentityStubs(): void
    {
        JIT\RuntimePrepareSpineIdentity::ensure(
            $this->context,
            fn (string $logical): string => $this->llvmInternalName($logical),
            function (string $logical, $func, array $args, array $defaults): void {
                $this->context->functionProxies[strtolower($logical)] = new JIT\Call\Native(
                    $func,
                    $logical,
                    $args,
                    $defaults
                );
            }
        );
    }

    /**
     * Native argv {main} for production bin/compile.php (M4 full revision / BIN_COMPILE sidecar — #2880).
     */
    private function shouldUseM4BinCompileArgvMainNative(): bool
    {
        if (!$this->shouldUseM3CompileDriverRealLowering()) {
            return false;
        }
        if ($this->shouldUseM5DriverHostCompile()) {
            return true;
        }
        $flag = Config::getenv('PHP_COMPILER_M4_BIN_COMPILE_DRIVER');

        return '1' === $flag || 'true' === strtolower((string) $flag);
    }

    private function isM4BinCompileScriptMain(Block $block): bool
    {
        if (!$this->isM3CompileDriverScriptMain($block)) {
            return false;
        }
        $path = str_replace('\\', '/', $block->scriptPath());
        if ('' === $path) {
            $fromCtx = $this->context->aotSourceFilename ?? '';
            $path = str_replace('\\', '/', is_string($fromCtx) ? $fromCtx : '');
        }

        return str_ends_with($path, '/bin/compile.php');
    }

    /** True when lowering targets script {main}, not spine stubs that reuse the compile-driver CFG block. */
    private function isM4BinCompileNativeMainLogicalName(?string $logicalName): bool
    {
        if (null === $logicalName) {
            return true;
        }

        return '{main}' === strtolower($logicalName);
    }

    /** Drop queued bin/compile.php {main} PHP lowering when native argv rebuild owns {main} (#2930). */
    private function filterM4InventoryArgvMainFromQueue(): void
    {
        $this->queue = array_values(array_filter(
            $this->queue,
            function (array $item): bool {
                $cfg = $item[1] ?? null;
                if (!$cfg instanceof Block) {
                    return true;
                }

                return !$this->isM4BinCompileScriptMain($cfg)
                    || !(
                        $this->shouldUseM4BinCompileArgvMainNative()
                        || $this->shouldUseHelloworldBinCompileInventoryArgvLink()
                    );
            }
        ));
    }

    /**
     * Inventory argv link real-lowers parse/init spine; stub rebuild is M4-only without emit driver (#8708).
     */
    private function shouldUseM4InventoryArgvNativeEmitRebuild(?Block $block = null): bool
    {
        if (!$this->shouldUseM4BinCompileArgvMainNative() || $this->shouldUseM5DriverHostCompile()) {
            return false;
        }
        if ($this->shouldUseM3InventoryEmitDriver()) {
            return false;
        }
        $main = $block ?? $this->m3CompileDriverMainBlock;
        if (null === $main || !$this->isM4BinCompileScriptMain($main)) {
            return false;
        }

        return !$this->shouldUseM3InventoryEmitForCompileDriverBlock($main);
    }

    /** M5 emit sidecar host-compile targets — stub {main} under self-host AOT (#2697, #2699). */
    private function isM5BootstrapSidecarScriptMain(Block $block): bool
    {
        if ($this->shouldUseM5DriverHostCompile()) {
            return false;
        }
        if (!$this->isM3CompileDriverScriptMain($block)) {
            return false;
        }
        $path = $block->scriptPath();

        // bin/compile.php needs real {main} for native CLI driver sidecars (#2697).
        return str_ends_with($path, '/bin/vm.php')
            || str_ends_with($path, '/src/cli_driver.php');
    }

    private function isM3CompileDriverBundleScriptMain(Block $block): bool
    {
        if (!$this->isM3CompileDriverScriptMain($block)) {
            return false;
        }

        return str_contains($block->scriptPath(), 'compile_driver.php');
    }

    /** Opt-in when linking test/selfhost/compiler_helloworld_smoke/compile_driver.php (#1056). */
    private function shouldUseM3CompileDriverRealLowering(): bool
    {
        $flag = Config::getenv('PHP_COMPILER_M3_COMPILE_DRIVER');
        if ('1' !== $flag && 'true' !== strtolower((string) $flag)) {
            return false;
        }
        // M5 argv / gen-0 seed: keep compile-driver allowlist even if user-script AOT
        // briefly cleared SELFHOST_AOT (#26756 / re-#23468).
        if ($this->shouldUseM5DriverHostCompile()) {
            return true;
        }

        return $this->shouldUseSelfHostJitStubs();
    }

    /**
     * Large Composer IncludeHelper graphs: NestedJIT PregJitHelperThinAot while the LLVM
     * module is still small. Mid-graph first use (Nyholm Uri::withUserInfo) stalls for
     * minutes as NestedJIT walks a fat module (#36382).
     *
     * Prefer {@see Runtime::standalone} eager link (flag {@see Runtime::$eagerThinPregHelpers});
     * this helper remains for call sites that set the flag after Context load.
     * Nyholm graphs should use {@see Runtime::$eagerUriComposerHelpers} instead — eager thin
     * preg fattens the module before Uri lowering.
     */
    private function maybeEagerLinkThinPregHelpers(): void
    {
        if (!$this->context->runtime->eagerThinPregHelpers) {
            return;
        }
        // Consume once — NestedJIT of the preg bundle re-enters compile() and must not loop.
        $this->context->runtime->eagerThinPregHelpers = false;
        if (JIT\NestedJitCompileScope::isActive()) {
            return;
        }
        JIT\Progress::noteFunction('eager_thin_preg_begin');
        JIT\Builtin\PregMatchRuntime::ensureLinked($this->context);
        JIT\Progress::noteFunction('eager_thin_preg_done');
    }

    /**
     * Eager NestedJIT UriRawurlencodeReplaceJitHelper + ParseUrl before IncludeHelper fattens
     * the module (#36382). Peer of {@see maybeEagerLinkThinPregHelpers}.
     */
    private function maybeEagerLinkUriComposerHelpers(): void
    {
        if (!$this->context->runtime->eagerUriComposerHelpers) {
            return;
        }
        $this->context->runtime->eagerUriComposerHelpers = false;
        if (JIT\NestedJitCompileScope::isActive()) {
            return;
        }
        JIT\Progress::noteFunction('eager_uri_composer_begin');
        JIT\JitVmHelperLink::ensureCompiled(
            $this->context,
            '/ext/standard/UriRawurlencodeReplaceJitHelper.php',
            ['PHPCompiler\\ext\\standard\\UriRawurlencodeReplaceJitHelper::replaceArgv'],
            '#36382'
        );
        JIT\Builtin\ParseUrlRuntime::ensureLinked($this->context);
        JIT\Progress::noteFunction('eager_uri_composer_done');
    }

    /** Emit native entry TU only — not compile_driver bundles that include compile_smoke_m3_emit (#1937). */
    private function shouldUseM3EmitTuNativeBridge(): bool
    {
        // Inventory emit links compile_driver.php {main} via the same bridge as helloworld_m3_emit (#2843).
        if ($this->shouldUseM3InventoryEmitDriver() && $this->shouldUseEmitHelperLinkStubs()) {
            return true;
        }
        $flag = Config::getenv('PHP_COMPILER_M3_EMIT_TU');

        return '1' === $flag || 'true' === strtolower((string) $flag);
    }

    /** Bundled bootstrap-aot smoke FUNCDEF names (BootstrapAot / legacy Lint bundle) (#1515). */
    private function isBootstrapHelloWorldSmokeName(string $lower): bool
    {
        return str_ends_with($lower, '\\bootstrapaot\\helloworld_compile_smoke')
            || 'helloworld_compile_smoke' === $lower
            || str_ends_with($lower, '\\helloworld_compile_smoke');
    }

    /** M3 native emit bridge entrypoints (Runtime parseAndCompile + standalone — #1983, #2294). */
    private function isBootstrapM3RuntimeEmitBridgeName(string $lower): bool
    {
        return str_ends_with($lower, '\\bootstrapaot\\compile_smoke_m3_emit')
            || 'compile_smoke_m3_emit' === $lower
            || str_ends_with($lower, '\\compile_smoke_m3_emit')
            || str_ends_with($lower, '\\bootstrapaot\\runtime_compile_smoke_m3_emit')
            || 'runtime_compile_smoke_m3_emit' === $lower
            || str_ends_with($lower, '\\runtime_compile_smoke_m3_emit');
    }

    private function isBootstrapRuntimeCtorSmokeName(string $lower): bool
    {
        return str_ends_with($lower, '\\bootstrapaot\\runtime_ctor_smoke')
            || 'runtime_ctor_smoke' === $lower
            || str_ends_with($lower, '\\runtime_ctor_smoke');
    }

    /** M3 HelloWorld compile driver: real LLVM lowering for parseAndCompile + standalone emit (#1056, #1402). */
    private function isM3CompileDriverRealLoweringName(string $lower): bool
    {
        if (!$this->shouldUseM3CompileDriverRealLowering()) {
            return false;
        }
        if ($this->shouldStubM3InventoryEmitJitSpineMethods()) {
            if (preg_match('/\\\\runtime::(loadjit|loadjitcontext|createjit|jitcontextforloadjit|loadjitcompilemodulefuncs|jitemitinplace)$/', $lower)) {
                return false;
            }
            if (str_ends_with($lower, '\\runtime::compile')) {
                return false;
            }
        }

        if ($this->isM3CompileDriverSpineDenyName($lower)) {
            return false;
        }
        if (str_ends_with($lower, '\\runtime::__construct')) {
            return true;
        }
        if (str_ends_with($lower, '\\runtime::parseandcompile')) {
            return true;
        }
        if (str_ends_with($lower, '\\runtime::loadjitcontext')) {
            return true;
        }
        if (str_ends_with($lower, '\\runtime::createjit')) {
            return true;
        }
        if (str_ends_with($lower, '\\runtime::jitcontextforloadjit')) {
            return true;
        }
        if (str_ends_with($lower, '\\runtime::loadjitcompilemodulefuncs')) {
            return true;
        }
        if (str_ends_with($lower, '\\runtime::standalone')) {
            return true;
        }
        if (str_ends_with($lower, '\\runtime::preparesourceforparser')
            || str_ends_with($lower, '\\runtime::preprocesssourceforparse')
            || str_ends_with($lower, '\\runtime::rewritesourcebeforeparser')) {
            if ($this->shouldStubInventoryArgvPreprocessSpineMethods()) {
                return false;
            }

            return !$this->shouldStubInventoryEmitParseCompileSpine();
        }
        if (str_ends_with($lower, '\\runtime::parse')) {
            return !$this->shouldStubInventoryEmitParseCompileSpine();
        }
        if (str_ends_with($lower, '\\runtime::detectfilestricttypes')
            || str_ends_with($lower, '\\runtime::resetparsernameresolverstate')
            || str_ends_with($lower, '\\runtime::recordlastparsefailure')
            || str_ends_with($lower, '\\runtime::formatparseandcompilenulldetail')) {
            // Inventory argv: ensureM3EmitTuRuntimeParseSpineDeps registers link stubs —
            // real-lowering formatParseAndCompileNullDetail hits detached memcpy/GEP (#36144).
            if ($this->shouldRealLowerInventoryArgvParseSpine()) {
                return false;
            }

            return true;
        }
        if (str_ends_with($lower, '\\runtime::compileemitsmoke')) {
            if ($this->shouldRealLowerInventoryArgvParseSpine()) {
                return false;
            }

            return true;
        }
        if (str_ends_with($lower, '\\runtime::parseandcompileemitsmoke')) {
            return true;
        }
        if (str_ends_with($lower, '\\runtime::compile')) {
            if ($this->shouldRealLowerInventoryArgvParseSpine()) {
                return true;
            }

            return true;
        }
        if (str_ends_with($lower, '\\runtime::emitparseandcompilenulldiagnostic')) {
            if ($this->shouldRealLowerInventoryArgvParseSpine()) {
                return false;
            }

            return true;
        }
        if (str_ends_with($lower, '\\runtime::loadjit')) {
            return true;
        }
        if (str_ends_with($lower, '\\runtime::initvmcontext')) {
            return true;
        }
        // M5 argv: C-floor initParsePipeline (compileRuntimeInitParsePipelineM3Native) —
        // NestedJIT of the PHP CFG hung Zend rebuilds for hours (#26756).
        if ($this->shouldUseM5DriverHostCompile()
            && (str_ends_with($lower, '\\runtime::initparsepipeline')
                || str_ends_with($lower, '\\runtime::noteparsecompilenullforscript')
                || str_ends_with($lower, '\\runtime::peeklastparsefailure'))
        ) {
            return false;
        }
        if (str_ends_with($lower, '\\runtime::initparsepipeline')) {
            return true;
        }
        if (str_ends_with($lower, '\\runtime::initcompiler')) {
            return true;
        }
        if (str_ends_with($lower, '\\runtime::loadcoremodules')) {
            return true;
        }
        if (str_ends_with($lower, '\\runtime::noteparsecompilenullforscript')) {
            return true;
        }
        if (str_ends_with($lower, '\\runtime::peeklastparsefailure')) {
            return true;
        }
        if (str_ends_with($lower, '\\runtime::__destruct')) {
            return true;
        }
        if (str_ends_with($lower, 'slotindexforvariablename')) {
            return true;
        }
        if (str_ends_with($lower, 'slotforoperand')) {
            return true;
        }
        if ($this->shouldUseM5DriverHostCompile()) {
            if ('run' === $lower || str_ends_with($lower, '\\php_compiler_cli_dispatch')
                || str_ends_with($lower, '\\php_compiler_cli_should_run_entry_driver')
            ) {
                return true;
            }
        }

        if (str_ends_with($lower, '\\compiler::compilefunc')) {
            return true;
        }

        return false;
    }

    /**
     * Former LLVM 9 crash denylist for M3 compile-driver link (#1402 / #1514).
     *
     * Empty as of #35009: BootstrapAot fixtures are not on the compile spine allowlist, so a deny
     * fragment never changed lowering. Keep the hook for a proven crasher — do not re-add fixtures
     * that are merely stubbed via other SELFHOST_AOT paths.
     *
     * @return list<string> lowercase name fragments
     */
    private function m3CompileDriverSpineDenyNames(): array
    {
        return [];
    }

    private function isM3CompileDriverSpineDenyName(string $lower): bool
    {
        foreach ($this->m3CompileDriverSpineDenyNames() as $fragment) {
            if (str_contains($lower, $fragment)) {
                return true;
            }
        }

        return false;
    }

    /** Block helpers real-lowered on M3 compile-driver spine (#2848, JIT VarFetch path). */
    private function isM3CompileDriverBlockPhpLoweringName(string $lower): bool
    {
        if (!$this->shouldUseM3CompileDriverRealLowering()) {
            return false;
        }

        return str_ends_with($lower, '\\block::slotindexforvariablename')
            || str_ends_with($lower, '\\block::slotforoperand');
    }

    /**
     * Required parameter count for ReflectionMethod AOT metadata (#34216).
     */
    private static function requiredParameterCountFromBlock(Block $block): int
    {
        $required = 0;
        $paramNames = array_values($block->paramNames);
        for ($i = 0, $n = \count($paramNames); $i < $n; ++$i) {
            if (null !== $block->variadicParamIndex && (int) $block->variadicParamIndex === $i) {
                break;
            }
            if (VM\ParamArgumentCountError::parameterHasDefault($block, $i)) {
                break;
            }
            ++$required;
        }

        return $required;
    }

    /**
     * FUNCDEF/DECLARE_METHOD use short names; self-host skip/M3 gates need scoped names (#1402).
     */
    private function jitFunctionSkipName(?string $name, Block $block): string
    {
        $candidate = strtolower((string) $name);
        if (str_contains($candidate, '::')) {
            return $candidate;
        }
        if (null !== $block->func) {
            return strtolower($block->func->getScopedName());
        }

        return $candidate;
    }

    private function compileBlock(Block $block, ?string $funcName = null): PHPLLVM\Value {
        $logicalName = $funcName;
        if (null !== $logicalName && null !== $block->func) {
            JIT\Progress::noteFunction($block->func->getScopedName());
            $this->context->jitLoweringScopedName = $block->func->getScopedName();
        }
        if ([] !== $block->paramSensitive) {
            if (null !== $block->func && null !== $block->func->class) {
                $classLc = strtolower((string) $block->func->class->value);
                $methodLc = strtolower($block->func->name);
                foreach (array_keys($block->paramSensitive) as $position) {
                    JIT\Builtin\ParamSensitiveLowering::recordMethod($classLc, $methodLc, (int) $position);
                }
            } elseif (null !== $funcName && '' !== $funcName) {
                foreach (array_keys($block->paramSensitive) as $index) {
                    JIT\Builtin\ParamSensitiveLowering::recordFunction(strtolower($funcName), (int) $index);
                }
            }
        }
        if (\PHPCompiler\CompilerVersion::supportsReflectionFunctionGetNamedArguments()) {
            $paramNames = array_values($block->paramNames);
            if ([] !== $paramNames) {
                if (null !== $block->func && null !== $block->func->class) {
                    JIT\Builtin\ReflectionNamedArgumentsLowering::recordMethod(
                        strtolower((string) $block->func->class->value),
                        strtolower($block->func->name),
                        $paramNames
                    );
                } elseif (null !== $funcName && '' !== $funcName) {
                    JIT\Builtin\ReflectionNamedArgumentsLowering::recordFunction(strtolower($funcName), $paramNames);
                }
            }
        }
        if (
            null !== $block->variadicParamIndex
            && null !== $funcName
            && '' !== $funcName
            && (null === $block->func || null === $block->func->class)
        ) {
            JIT\Builtin\ReflectionFunctionVariadicLowering::recordFunction(strtolower($funcName));
        }
        // Thin AOT ReflectionFunction::{getNumberOfParameters,isUserDefined,isInternal} (#34218).
        if (
            null !== $funcName
            && '' !== $funcName
            && (null === $block->func || null === $block->func->class)
        ) {
            JIT\Builtin\ReflectionFunctionParamCountLowering::recordUserFunction(
                strtolower($funcName),
                \count(array_values($block->paramNames))
            );
        }
        // Thin AOT ReflectionMethod::{getNumberOfParameters,getNumberOfRequiredParameters} (#34216).
        if (null !== $block->func && null !== $block->func->class) {
            JIT\Builtin\ReflectionMethodQueryLowering::recordUserMethodFromBlock(
                (string) $block->func->class->value,
                $block->func->name,
                $block
            );
        }
        $skipName = $this->jitFunctionSkipName($logicalName, $block);
        if (!is_null($funcName)) {
            $internalName = $this->llvmInternalName($funcName);
        } else {
            $internalName = 'internal_'.(++self::$functionNumber);
            $debugMainName = JIT\AotDebugSymbols::scriptMainFunctionName($block);
            if (null !== $debugMainName) {
                $internalName = $debugMainName;
            }
        }
        if (str_contains($internalName, 'opcode_type_name')) {
            return $this->compileSkippedOpcodeNameStub($internalName, $block);
        }
        // M5 argv / gen-0 seed: ResolveSidecarJitHelper NestedJIT explodes (no phpc_str_replace
        // under NestedJitCompileScope; helper unit failed.json) — identity path stubs (#26756).
        if (
            $this->shouldUseM5DriverHostCompile()
            && null !== $logicalName
            && $this->isM5ArgvResolveSidecarIdentityStubName(strtolower($logicalName))
        ) {
            return $this->emitM5ArgvResolveSidecarIdentityStub($internalName, $logicalName, $block);
        }
        // M5 bootstrap sidecar: CLI entry scripts under `PHP_COMPILER_SELFHOST_AOT=1` only need a
        // linkable bundle; stub {main} to avoid LLVM 9 crashing while lowering argv driver chains
        // (#2697, #2699). `PHP_COMPILER_M5_DRIVER_HOST=1` opts into real argv lowering (#1521).
        if (
            $this->shouldUseSelfHostJitStubs()
            && !$this->shouldUseM5DriverHostCompile()
            && null === $logicalName
            && null !== $block->func
            && '{main}' === $block->func->name
            && $this->isM5BootstrapSidecarScriptMain($block)
        ) {
            return $this->compileSkippedCompilerSplitCfgStub($internalName, $block, '{main}');
        }
        if (
            $this->shouldUseM3CompileDriverMainNative()
            && $this->isM3CompileDriverBundleScriptMain($block)
            && !($this->shouldUseM3InventoryEmitDriver() && $this->shouldUseEmitHelperLinkStubs())
        ) {
            return $this->compileM3CompileDriverMainNative($internalName, $block, $logicalName);
        }
        if ($this->shouldUseM3EmitTuNativeBridge() && $this->isM3EmitTuScriptMain($block)) {
            return $this->compileM3EmitTuMainNative($internalName, $block, $logicalName);
        }
        if (
            $this->isM4BinCompileScriptMain($block)
            && (
                $this->shouldUseM4BinCompileArgvMainNative()
                || $this->shouldUseHelloworldBinCompileInventoryArgvLink()
            )
        ) {
            return $this->compileM3CompileDriverMainNative($internalName, $block, $logicalName);
        }
        if ($this->shouldUseM3EmitTuNativeBridge() && null !== $logicalName) {
            $m3EmitRuntime = strtolower($logicalName);
            if ($this->isM3EmitTuRuntimeSpineLoweringName($m3EmitRuntime)) {
                $methodLc = substr($m3EmitRuntime, (int) strrpos($m3EmitRuntime, '::') + 2);
                if ($this->shouldUseM3EmitTuRuntimeMethodStub($methodLc)) {
                    return $this->compileM3EmitTuRuntimeSpineStub(
                        $internalName,
                        $block,
                        $logicalName,
                        $m3EmitRuntime
                    );
                }
            }
            if ($this->isM3EmitTuCompilerSpineLoweringName($m3EmitRuntime)) {
                if ('phpcompiler\\compiler::compileemitsmoke' === $m3EmitRuntime) {
                    if (!$this->shouldUseM3CompileDriverRealLowering()) {
                        return $this->emitM3EmitTuCompilerCompileEmitSmokeNativeFunction(
                            $internalName,
                            $logicalName
                        );
                    }
                } elseif (!$this->shouldUseM3CompileDriverRealLowering()) {
                    return $this->compileSkippedCompilerSplitCfgStub(
                        $internalName,
                        $block,
                        $logicalName ?? $internalName
                    );
                }
            }
        }
        if (
            null !== $logicalName
            && $this->shouldUseM3EmitTuNativeBridge()
            && $this->isBootstrapM3RuntimeEmitBridgeName(strtolower($logicalName))
        ) {
            return $this->compileBootstrapCompileSmokeM3EmitNative($internalName, $block, $logicalName);
        }
        $emitTuSpine = $this->tryCompileM3EmitTuRuntimeSpineNative($internalName, $block, $logicalName);
        if (null !== $emitTuSpine) {
            return $emitTuSpine;
        }
        $emitTuCompiler = $this->tryCompileM3EmitTuCompilerSpineNative($internalName, $block, $logicalName);
        if (null !== $emitTuCompiler) {
            return $emitTuCompiler;
        }
        if ($this->shouldUseM3CompileDriverRealLowering() && null !== $logicalName) {
            $m3Spine = strtolower($logicalName);
            if ($this->isM3CompileDriverCompilerNativeLoweringName($m3Spine)) {
                return JIT\CompilerOperandChainNative::compile(
                    $this->context,
                    $this->llvmInternalName($internalName),
                    $block,
                    $logicalName
                );
            }
            if (JIT\VariableTypeMapNative::isNativeLoweringName($m3Spine)) {
                return JIT\VariableTypeMapNative::compile(
                    $this->context,
                    $this->llvmInternalName($internalName),
                    $block,
                    $logicalName
                );
            }
            if (JIT\OperandNameNative::isNativeLoweringName($m3Spine)) {
                return JIT\OperandNameNative::compile(
                    $this->context,
                    $this->llvmInternalName($internalName),
                    $block,
                    $logicalName
                );
            }
            if (str_ends_with($m3Spine, '\\runtime::loadjit')) {
                return $this->compileRuntimeLoadJitM3Native($internalName, $block, $logicalName);
            }
            if (str_ends_with($m3Spine, '\\runtime::loadjitcontext')) {
                return $this->compileRuntimeLoadJitContextM3Native($internalName, $block, $logicalName);
            }
            if (str_ends_with($m3Spine, '\\runtime::createjit')) {
                return $this->compileRuntimeCreateJitM3Native($internalName, $block, $logicalName);
            }
            if (str_ends_with($m3Spine, '\\runtime::jitcontextforloadjit')) {
                return $this->compileRuntimeJitContextForLoadJitM3Native($internalName, $block, $logicalName);
            }
            if (str_ends_with($m3Spine, '\\runtime::loadjitcompilemodulefuncs')) {
                return $this->compileRuntimeLoadJitCompileModuleFuncsM3Native($internalName, $block, $logicalName);
            }
            if (str_ends_with($m3Spine, '\\runtime::__construct')) {
                return $this->compileRuntimeConstructM3Native($internalName, $block, $logicalName);
            }
            if (str_ends_with($m3Spine, '\\runtime::initparsepipeline')) {
                return $this->compileRuntimeInitParsePipelineM3Native($internalName, $block, $logicalName);
            }
            if (str_ends_with($m3Spine, '\\runtime::initcompiler')) {
                return $this->compileRuntimeInitCompilerM3Native($internalName, $block, $logicalName);
            }
            if (str_ends_with($m3Spine, '\\runtime::initvmcontext')) {
                return $this->compileRuntimeInitVmContextM3Native($internalName, $block, $logicalName);
            }
            if (str_ends_with($m3Spine, '\\runtime::loadcoremodules')) {
                return $this->compileRuntimeLoadCoreModulesM3Native($internalName, $block, $logicalName);
            }
            if (str_ends_with($m3Spine, '\\runtime::__destruct')) {
                return $this->compileRuntimeDestructM3Native($internalName, $block, $logicalName);
            }
            if (
                $this->shouldUseM3EmitTuNativeBridge()
                && (
                    str_ends_with($m3Spine, '\\runtime::parseandcompile')
                    || str_ends_with($m3Spine, '\\runtime::parseandcompileemitsmoke')
                )
            ) {
                return $this->compileRuntimeParseAndCompileM3Native($internalName, $block, $logicalName);
            }
            if (str_ends_with($m3Spine, '\\runtime::parse')) {
                // M5 argv / inventory argv: C-floor parse (skip prepare list-unpack SEGV) (#26756, #36144).
                if ($this->shouldUseM5ParseSpineCFloor()) {
                    $this->ensureM5ParseSpineCFloorSymbols();

                    return JIT\RuntimeParseM5Native::emitFunction(
                        $this->context,
                        $internalName,
                        $logicalName,
                        fn (string $n): string => $this->llvmInternalName($n)
                    );
                }
                if ($this->shouldUseM3EmitTuRuntimeMethodStub('parse')) {
                    return $this->emitM3EmitTuRuntimeParseStubNative($internalName, $logicalName, $block);
                }

                return $this->compileRuntimeSpinePhpLowering($internalName, $block, $logicalName);
            }
            if ($this->shouldStubInventoryArgvPreprocessSpineMethods()
                && (
                    str_ends_with($m3Spine, '\\runtime::preprocesssourceforparse')
                    || str_ends_with($m3Spine, '\\runtime::rewritesourcebeforeparser')
                )
            ) {
                return $this->compileSkippedCompilerSplitCfgStub(
                    $internalName,
                    $this->m3CompileDriverMainBlock ?? $this->m3EmitTuMainBlock ?? $block,
                    $logicalName ?? $internalName
                );
            }
            if (str_ends_with($m3Spine, '\\runtime::compileemitsmoke')) {
                if ($this->shouldUseM3EmitTuRuntimeMethodStub('compileemitsmoke')) {
                    return $this->emitM3EmitTuRuntimeCompileEmitSmokeNative($internalName, $logicalName, $block);
                }

                return $this->compileRuntimeSpinePhpLowering($internalName, $block, $logicalName);
            }
            if (str_ends_with($m3Spine, '\\runtime::standalone')) {
                if ($this->shouldUseM3EmitTuRuntimeMethodStub('standalone')) {
                    return $this->emitM3EmitTuRuntimeStandaloneStubNative($internalName, $logicalName, $block);
                }

                return $this->compileRuntimeSpinePhpLowering($internalName, $block, $logicalName);
            }
            if (
                str_ends_with($m3Spine, '\\runtime::compile')
                || (
                    str_ends_with($m3Spine, '\\runtime::parseandcompile')
                    && !$this->shouldUseM3EmitTuNativeBridge()
                    && !$this->shouldUseM3InventoryEmitDriver()
                )
                || str_ends_with($m3Spine, '\\runtime::parseandcompileemitsmoke')
            ) {
                return $this->compileRuntimeSpinePhpLowering($internalName, $block, $logicalName);
            }
            if ($this->isM3EmitHelperCompilerPhpLoweringName($m3Spine)) {
                return $this->compileRuntimeSpinePhpLowering($internalName, $block, $logicalName);
            }
            if ($this->isM3CompileDriverBlockPhpLoweringName($m3Spine)) {
                return $this->compileBlockPhpLowering($internalName, $block, $logicalName, $funcName);
            }
        }
        if ($this->shouldUseM3EmitTuNativeBridge() && null !== $logicalName) {
            $m3Compiler = strtolower($logicalName);
            if ('phpcompiler\\compiler::compileemitsmoke' === $m3Compiler
                && !$this->shouldUseM3CompileDriverRealLowering()
            ) {
                return $this->emitM3EmitTuCompilerCompileEmitSmokeNativeFunction($internalName, $logicalName);
            }
        }
        if ($this->shouldUseM3CompileDriverRealLowering() && null !== $logicalName) {
            $m3CompilerSetter = strtolower($logicalName);
            if (str_ends_with($m3CompilerSetter, '\\compiler::setpropertyhookregistry')
                || str_ends_with($m3CompilerSetter, '\\compiler::setknownclassreadonly')
                || str_ends_with($m3CompilerSetter, '\\compiler::setbarerethrowlines')
            ) {
                return $this->emitM3EmitTuCompilerArrayPropertySetterVoidStub(
                    $internalName,
                    $logicalName,
                    $this->m3EmitTuMainBlock ?? $this->m3CompileDriverMainBlock
                );
            }
            if (str_ends_with($m3CompilerSetter, '\\compiler::setcompileabortdetailifempty')
                || str_ends_with($m3CompilerSetter, '\\compiler::setdebuglastphaseinputfile')
            ) {
                return $this->emitM3EmitTuCompilerStringSetterVoidStub(
                    $internalName,
                    $logicalName,
                    $this->m3EmitTuMainBlock ?? $this->m3CompileDriverMainBlock
                );
            }
            if (str_ends_with($m3CompilerSetter, '\\compiler::resetcompileabortdetail')) {
                return $this->emitM3EmitTuCompilerVoidStub(
                    $internalName,
                    $logicalName,
                    $this->m3EmitTuMainBlock ?? $this->m3CompileDriverMainBlock
                );
            }
            if (str_ends_with($m3CompilerSetter, '\\compiler::getdebuglastphaseinputfile')
                || str_ends_with($m3CompilerSetter, '\\compiler::getcompileabortdetail')
            ) {
                return $this->emitM3EmitTuCompilerNullStringGetterStub(
                    $internalName,
                    $logicalName,
                    $this->m3EmitTuMainBlock ?? $this->m3CompileDriverMainBlock
                );
            }
            if ('phpcompiler\\compiler::compile' === $m3CompilerSetter
                && $this->shouldUseM3InventoryEmitDriver()
                && $this->shouldUseEmitHelperLinkStubs()
            ) {
                return $this->emitM3EmitTuCompilerCompileNullStubNative($internalName, $logicalName);
            }
        }
        if ($this->shouldUseSelfHostJitStubs() && null !== $logicalName) {
            $vmSpineLc = strtolower($logicalName);
            if (JIT\VmSpineSmokeNative::isVmRunSmokeName($vmSpineLc)) {
                return $this->compileVmRunSmokeNative(
                    $this->llvmInternalName($internalName),
                    $block,
                    $logicalName
                );
            }
            if (JIT\VmUnitProbeExecuteNative::isVmUnitProbeRunName($vmSpineLc)) {
                return $this->compileVmUnitProbeRunNative(
                    $this->llvmInternalName($internalName),
                    $block,
                    $logicalName
                );
            }
            if (JIT\VmDriverExecuteNative::isBinVmRunName($vmSpineLc, $block)) {
                return $this->compileBinVmRunNative(
                    $this->llvmInternalName($internalName),
                    $block,
                    $logicalName
                );
            }
        }
        if (
            $this->shouldUseSelfHostJitStubs()
            && null !== $logicalName
            && $this->isSuperglobalNameJitFunction($logicalName)
        ) {
            return $this->compileSuperglobalNameNative($internalName, $block, $logicalName);
        }
        if (
            $this->shouldUseSelfHostJitStubs()
            && null !== $logicalName
            && JIT\OperandNameNative::isNativeLoweringName(strtolower($logicalName))
        ) {
            return JIT\OperandNameNative::compile(
                $this->context,
                $this->llvmInternalName($internalName),
                $block,
                $logicalName
            );
        }
        if ($this->isSkippedVmHotPathName($skipName)) {
            return $this->compileSkippedVmHotPathStub($internalName, $block, $logicalName ?? $internalName);
        }
        if ($block->isGenerator) {
            return JIT\GeneratorHelper::compileResumeFunction(
                $this,
                $internalName,
                $block,
                $logicalName ?? $internalName
            );
        }
        if ($this->isSkippedM3EmitTuBundledHelperName($skipName)) {
            return $this->compileSkippedCompilerSplitCfgStub($internalName, $block, $logicalName ?? $internalName);
        }
        if ($this->isSkippedCompilerHotPathName($skipName)
            || $this->isSkippedWebBootstrapHotPathName($skipName)
            || $this->isSkippedLibSpineSmokeHotPathName($skipName)
            || $this->isSkippedSelfHostEntryName($skipName)
            || $this->isSkippedBootstrapInterpreterHotPathName($skipName)
        ) {
            return $this->compileSkippedCompilerSplitCfgStub($internalName, $block, $logicalName ?? $internalName);
        }
        if (
            $this->shouldUseSelfHostJitStubs()
            && null !== $logicalName
            && str_ends_with(strtolower($logicalName), '\\runtime::__construct')
            && !$this->shouldUseM3CompileDriverRealLowering()
        ) {
            return $this->emitM3EmitTuRuntimeConstructNativeFunction($internalName, $logicalName, $block);
        }
        // Emit TU: stub bundled lib/ except M3 compile-driver Compiler/Web CFG (#2540, #2633).
        if ($this->shouldUseM3EmitTuNativeBridge() && null !== $logicalName) {
            $emitLc = strtolower($logicalName);
            if ($this->shouldUseM3CompileDriverRealLowering()
                && (
                    $this->isM3EmitTuCompilerCompileChainLoweringName($emitLc)
                    || $this->isLiteralIncludeDiscoveryRealLoweringMethod($emitLc)
                    || $this->isDeployRootRealLoweringMethod($emitLc)
                    || $this->isSourceBundlerRealLoweringMethod($emitLc)
                    || $this->isConstStringFolderRealLoweringMethod($emitLc)
                    || $this->isSuperglobalsRealLoweringMethod($emitLc)
                    || $this->isIncludePathResolverRealLoweringMethod($emitLc)
                    || $this->isM3EmitTuRuntimeCompileDriverSpineLoweringName($emitLc)
                    || $this->isM3CompileDriverBlockPhpLoweringName($emitLc)
                )
            ) {
                return $this->compileBlockPhpLowering($internalName, $block, $logicalName, $funcName);
            }

            return $this->compileSkippedCompilerSplitCfgStub($internalName, $block, $logicalName ?? $internalName);
        }

        return $this->compileBlockPhpLowering($internalName, $block, $logicalName, $funcName);
    }

    private function compileBlockPhpLowering(
        string $internalName,
        Block $block,
        ?string $logicalName,
        ?string $funcName
    ): PHPLLVM\Value {
        // Note: edit-scaffold keep-path reuses unchanged member LLVM bodies via
        // {@see CompileCache::isKeptUserSymbol()} below — never early-return on
        // restored helpers (NestedJIT must rebind; that bug yielded empty stdout) (#36387).
        $args = [];
        $rawTypes = [];
        $argVars = [];
        $returnsByRef = false;
        $isVoidReturn = false;
        if (!is_null($block->func)) {
            $returnsByRef = $this->cfgFunctionReturnsByRef($block->func);
            $callbackType = $returnsByRef
                ? '__value__*'
                : ($this->cfgFunctionReturnCallbackType($block->func) ?? '__value__');
            $methodLc = strtolower($block->func->name);
            if (
                '__construct' === $methodLc
                || '__destruct' === $methodLc
                || str_ends_with($methodLc, '::__destruct')
            ) {
                $callbackType = 'void';
            }
            // M5 argv NestedJIT of PHPCfg\Parser::parse: untyped CFG defaults to __value__
            // return + mixed params, but RuntimeParseM5Native calls
            // parse(__object__*, __string__*, __string__*) -> __object__* (Script) (#27426).
            if ($this->isM5NestedJitPhpCfgParserParse($logicalName)) {
                $callbackType = '__object__*';
            }
            // Capture before appending `(*)(…)` — elision registry must see void returns
            // with typed params (`void(*)(__string__*)`), not only bare `void` (#36386).
            $isVoidReturn = 'void' === $callbackType;
            $returnType = $this->context->getTypeFromString($callbackType);
            $this->context->functionReturnType[strtolower($logicalName ?? $internalName)] = $callbackType;

            if ($this->instanceMethodUsesThis($block) || $this->closureBodyUsesThis($block)) {
                $rawTypes[] = Type::object();
                $args[] = $this->context->getTypeFromString('__object__*');
            }
            $callbackType .= '(*)(';
            $callbackSep = '';
            foreach ($args as $type) {
                $callbackType .= $callbackSep . $this->context->getStringFromType($type);
                $callbackSep = ', ';
            }
            foreach ($block->func->params as $idx => $param) {
                $rawType = $this->rawTypeFromCfgParam($param);
                $type = $this->llvmTypeForCfgParam($param, $block, $idx);
                // M5 argv NestedJIT of Runtime::parse: keep string formals as __string__*
                // even if CFG marks them mixed after prepare-skip branches — callers pass
                // __string__* from file_get_contents (#26756).
                if (
                    $this->shouldUseM5DriverHostCompile()
                    && JIT\NestedJitCompileScope::isActive()
                    && null !== $logicalName
                    && str_ends_with(strtolower($logicalName), '\\runtime::parse')
                ) {
                    $declName = null;
                    if ($param->declaredType instanceof Op\Type\Literal) {
                        $declName = strtolower($param->declaredType->name);
                    }
                    if (
                        'string' === $declName
                        || Type::TYPE_STRING === ($rawType->type ?? null)
                    ) {
                        $type = $this->context->getTypeFromString('__string__*');
                        $rawType = Type::string();
                    }
                }
                // PHPCfg\Parser::parse($code, $fileName) — no declared types in vendor;
                // force __string__* so RuntimeParseM5Native call sites type-check (#27426).
                if ($this->isM5NestedJitPhpCfgParserParse($logicalName)) {
                    $type = $this->context->getTypeFromString('__string__*');
                    $rawType = Type::string();
                }
                // M5ParserAstPeer::parse(string $code, …) — keep first formal as __string__* (#27426).
                if ($this->isM5NestedJitM5ParserAstPeerParse($logicalName) && 0 === $idx) {
                    $type = $this->context->getTypeFromString('__string__*');
                    $rawType = Type::string();
                }
                $callbackType .= $callbackSep . $this->context->getStringFromType($type);
                $callbackSep = ', ';
                $rawTypes[] = $rawType;
                $args[] = $type;
            }
            foreach (JIT\ClosureHelper::orderedCaptureSlots($block) as $_captureSlot) {
                $captureType = $this->context->getTypeFromString('__value__*');
                $callbackType .= $callbackSep . '__value__*';
                $callbackSep = ', ';
                $rawTypes[] = Type::mixed();
                $args[] = $captureType;
            }
            if ($this->shouldUseSelfHostJitStubs() && null !== $logicalName) {
                $args = $this->normalizeSelfHostNativeCallArgTypes($args, $logicalName);
            }
            $callbackType .= ')';
        } else {
            $callbackType = 'void(*)()';
            $returnType = $this->context->getTypeFromString('void');
        }

        $isVarArgs = false;

        // Keep-path: reuse unchanged user LLVM body from edit-scaffold. Helpers are never
        // in keptUserSymbols — NestedJIT early-return there emptied AOT stdout (#36387).
        if (
            JIT\CompileCache::isEditScaffoldActive()
            && !JIT\NestedJitCompileScope::isActive()
            && JIT\CompileCache::isKeptUserSymbol($internalName)
        ) {
            $existing = $this->context->module->getNamedFunction($internalName);
            if ($existing instanceof PHPLLVM\Value\Function_) {
                $cfgParamCount = null !== $block->func ? count($block->func->params) : 0;
                $thisParamOffset = $this->llvmThisParamOffset($block);
                foreach ($args as $idx => $arg) {
                    $varType = Variable::getTypeFromType($rawTypes[$idx]);
                    $cfgIdx = $idx - $thisParamOffset;
                    $cfgParam = ($cfgIdx >= 0 && $cfgIdx < $cfgParamCount)
                        ? $block->func->params[$cfgIdx]
                        : null;
                    $llvmParamTy = $this->context->getStringFromType($arg);
                    if ('__value__*' === $llvmParamTy) {
                        $varType = Variable::TYPE_VALUE;
                    } elseif ('__object__*' === $llvmParamTy) {
                        $varType = Variable::TYPE_OBJECT;
                    } elseif ('__hashtable__*' === $llvmParamTy) {
                        $varType = Variable::TYPE_HASHTABLE;
                    } elseif ('__string__*' === $llvmParamTy) {
                        $varType = Variable::TYPE_STRING;
                    }
                    if (null !== $cfgParam && $cfgParam->variadic) {
                        $varType = Variable::TYPE_HASHTABLE;
                    }
                    $argVars[] = new Variable($this->context, $varType, Variable::KIND_VALUE, $existing->getParam($idx));
                }

                $lcname = strtolower($logicalName ?? $internalName);
                $this->context->functions[$lcname] = $existing;
                $this->context->functionLlvmSymbols[$lcname] = $internalName;
                $this->context->activeFunction = $lcname;
                if (JIT\CompileCache::isRecording()) {
                    JIT\CompileCache::recordUserLlvmSymbol($internalName, $block);
                }
                if (!is_null($funcName)) {
                    $lcname = strtolower($funcName);
                    $this->context->activeFunction = $lcname;
                    $this->context->functions[$lcname] = $existing;
                    $this->context->functionLlvmSymbols[$lcname] = $internalName;
                    if (JIT\CompileCache::isRecording()) {
                        JIT\CompileCache::recordUserLlvmSymbol($internalName, $block);
                    }
                    $defaultArgs = $this->collectParamDefaults($block);
                    $variadicArgIndex = null;
                    if (null !== $block->variadicParamIndex) {
                        $variadicArgIndex = $block->variadicParamIndex;
                        if ($this->llvmThisParamOffset($block) > 0) {
                            ++$variadicArgIndex;
                        }
                    }
                    $this->context->functionProxies[$lcname] = new JIT\Call\Native(
                        $existing,
                        VM\ParamArgumentCountError::typeErrorDisplayNameForCfgFunc($block->func, $funcName, $block),
                        $args,
                        $defaultArgs,
                        $variadicArgIndex,
                        $this->paramTypeConstraintsForNativeCall($block),
                        $this->paramIntersectionConstraintsForNativeCall($block),
                        $this->paramDnfConstraintsForNativeCall($block),
                        $this->paramClassConstraintsForNativeCall($block),
                        $this->paramByRefForNativeCall($block),
                        $block->paramNames,
                        $block->variadicParamIndex,
                        $this->paramImplicitNullableForNativeCall($block),
                        Block::usesFuncArgsIntrospection($block),
                        $this->collectPromotedRuntimeNewDefaultProps($block)
                    );
                    JIT\NoDiscardCallGuard::registerCallee($this->context, $funcName, $block);
                    JIT\DeprecatedCallGuard::registerCallee($this->context, $funcName, $block);
                    if (
                        $isVoidReturn
                        && Block::isEffectFreeVoidCalleeBody($block)
                        && !$block->noDiscard
                        && null === $block->deprecated
                        && !Block::usesFuncArgsIntrospection($block)
                    ) {
                        $this->context->discardedCallElisionVoidNatives[$lcname] = true;
                    }
                    if ($returnsByRef) {
                        $this->markFunctionReturnsByRef($lcname, $funcName ?? '');
                    }
                }

                // Body already in module — do not queue re-lower.
                return $existing;
            }
        }

        $func = $this->context->module->addFunction(
            $internalName,
            $this->context->context->functionType(
                $returnType,
                $isVarArgs,
                ...$args
            )
        );

        $cfgParamCount = null !== $block->func ? count($block->func->params) : 0;
        // $args/$rawTypes are LLVM-shaped (optional $this at 0). CFG params are not —
        // indexing func->params[$llvmIdx] mis-attributes a trailing variadic onto the
        // preceding formal (Context typed as HT, ...$args left as object) and fails
        // module verify on writeHashtable/setObjectAt (#24429 ext/ds DsFactoryFunction::call).
        $thisParamOffset = $this->llvmThisParamOffset($block);
        foreach ($args as $idx => $arg) {
            $varType = Variable::getTypeFromType($rawTypes[$idx]);
            $cfgIdx = $idx - $thisParamOffset;
            $cfgParam = ($cfgIdx >= 0 && $cfgIdx < $cfgParamCount)
                ? $block->func->params[$cfgIdx]
                : null;
            $llvmParamTy = $this->context->getStringFromType($arg);
            if ('__value__*' === $llvmParamTy) {
                $varType = Variable::TYPE_VALUE;
            } elseif ('__object__*' === $llvmParamTy) {
                $varType = Variable::TYPE_OBJECT;
            } elseif ('__hashtable__*' === $llvmParamTy) {
                $varType = Variable::TYPE_HASHTABLE;
            } elseif ('__string__*' === $llvmParamTy) {
                $varType = Variable::TYPE_STRING;
            }
            if (
                null !== $cfgParam
                && JIT\NestedJitCompileScope::isActive()
                && $this->isCfgVmVariableParamType(
                    $this->declaredTypeFromCfgParam($cfgParam)
                )
            ) {
                $varType = Variable::TYPE_VALUE;
            }
            if (
                null !== $cfgParam
                && JIT\NestedJitCompileScope::isActive()
                && $this->isCfgVmHashTableParamType(
                    $this->declaredTypeFromCfgParam($cfgParam)
                )
            ) {
                $varType = Variable::TYPE_HASHTABLE;
            }
            if (null !== $cfgParam && $cfgParam->variadic) {
                $varType = Variable::TYPE_HASHTABLE;
            }
            $argVars[] = new Variable($this->context, $varType, Variable::KIND_VALUE, $func->getParam($idx));
        }

        $lcname = strtolower($logicalName ?? $internalName);
        $this->context->functions[$lcname] = $func;
        $this->context->functionLlvmSymbols[$lcname] = $internalName;
        $this->context->activeFunction = $lcname;
        if (JIT\CompileCache::isRecording()) {
            if (JIT\NestedJitCompileScope::isActive()) {
                JIT\CompileCache::recordHelperLogical($lcname, $internalName);
            } else {
                JIT\CompileCache::recordUserLlvmSymbol($internalName, $block);
            }
        }
        if (!is_null($funcName)) {
            $lcname = strtolower($funcName);
            $this->context->activeFunction = $lcname;
            $this->context->functions[$lcname] = $func;
            $this->context->functionLlvmSymbols[$lcname] = $internalName;
            if (JIT\CompileCache::isRecording()) {
                if (JIT\NestedJitCompileScope::isActive()) {
                    JIT\CompileCache::recordHelperLogical($lcname, $internalName);
                } else {
                    JIT\CompileCache::recordUserLlvmSymbol($internalName, $block);
                }
            }
            if ($isVarArgs) {
                $this->context->functionProxies[$lcname] = new JIT\Call\Vararg($func, $funcName, count($args));
            } else {
                $defaultArgs = $this->collectParamDefaults($block);
                $variadicArgIndex = null;
                if (null !== $block->variadicParamIndex) {
                    $variadicArgIndex = $block->variadicParamIndex;
                    if ($this->llvmThisParamOffset($block) > 0) {
                        ++$variadicArgIndex;
                    }
                }
                $this->context->functionProxies[$lcname] = new JIT\Call\Native(
                    $func,
                    VM\ParamArgumentCountError::typeErrorDisplayNameForCfgFunc($block->func, $funcName, $block),
                    $args,
                    $defaultArgs,
                    $variadicArgIndex,
                    $this->paramTypeConstraintsForNativeCall($block),
                    $this->paramIntersectionConstraintsForNativeCall($block),
                    $this->paramDnfConstraintsForNativeCall($block),
                    $this->paramClassConstraintsForNativeCall($block),
                    $this->paramByRefForNativeCall($block),
                    $block->paramNames,
                    $block->variadicParamIndex,
                    $this->paramImplicitNullableForNativeCall($block),
                    Block::usesFuncArgsIntrospection($block),
                    $this->collectPromotedRuntimeNewDefaultProps($block)
                );
                JIT\NoDiscardCallGuard::registerCallee($this->context, $funcName, $block);
                JIT\DeprecatedCallGuard::registerCallee($this->context, $funcName, $block);
                if (
                    $isVoidReturn
                    && Block::isEffectFreeVoidCalleeBody($block)
                    && !$block->noDiscard
                    && null === $block->deprecated
                    && !Block::usesFuncArgsIntrospection($block)
                ) {
                    $this->context->discardedCallElisionVoidNatives[$lcname] = true;
                }
            }
            if ($returnsByRef) {
                $this->markFunctionReturnsByRef($lcname, $funcName ?? '');
            }
        }

        $this->precompileClosuresBeforeQueue($block);
        // CFG-only no-throw analysis must run at enqueue — `{main}` lowers method
        // calls before runQueue fills bodies, so body-time analyzeAndRecord is too
        // late for call-site ex_stack / pending-throw elision (#36386).
        if (null !== $block->func && '{main}' !== $block->func->name) {
            $analyzeName = $logicalName ?? $block->func->getScopedName();
            JIT\NoThrowCallElision::analyzeAndRecord(
                $this->context,
                $block,
                strtolower((string) $analyzeName)
            );
        }
        $this->queue[] = [$func, $block, $argVars];
        if ($callbackType === 'void(*)()' && !Block::containsNonLiteralEvalOpcodes($block)) {
            $this->context->addExport($internalName, $callbackType, $block);
        }
        return $func;
    }

    /**
     * Compile nested TYPE_CLOSURE bodies before the enclosing function is queued so
     * `{closure}_N` proxies exist when main lowers `$f = m(); $f()` (#34868).
     *
     * Without this, FUNCCALL_INIT runs before runQueue, closureCandidates() is empty,
     * resolveIndirectCall returns null, and the invoke becomes a null call.
     *
     * Skip when this block also DECLARE_CLASS/INTERFACE/TRAIT/ENUM: precompile would
     * lower STATIC_PROPERTY_FETCH / class-const before TYPE_DECLARE_* runs, baking
     * "undeclared static property" into top-level closures (#34896 leftover of #34868).
     * Method bodies never DECLARE_CLASS — precompile there still covers #34868.
     * Top-level closures compile later at TYPE_CLOSURE (after declares in runQueue).
     */
    private function precompileClosuresBeforeQueue(Block $block): void
    {
        if ($this->blockDeclaresClassLike($block)) {
            return;
        }
        foreach ($block->opCodes as $i => $op) {
            if (OpCode::TYPE_CLOSURE !== $op->type || null === $op->block1) {
                continue;
            }
            if (null !== $op->closurePrecompiledInternalName) {
                continue;
            }
            if ($this->shouldStubClosureLowering()) {
                continue;
            }
            // Fiber::suspend callbacks must use compileResumeFunction at TYPE_CLOSURE —
            // precompileClosureBodyBlock hits FiberSuspendStatic outside resume context
            // (#34868 interaction with #4019; AOT fiber_suspend.phpt).
            if (JIT\FiberHelper::blockContainsFiberSuspend($op->block1)) {
                continue;
            }
            $internalName = JIT\ClosureHelper::nextInternalName();
            $op->closurePrecompiledInternalName = $internalName;
            $this->compileClosureBodyBlock($block, $op->block1, $internalName);
            $lcname = strtolower($internalName);
            if (!isset($this->context->functionProxies[$lcname])) {
                continue;
            }
            $proxy = $this->context->functionProxies[$lcname];
            // Captures wrap must wait until TYPE_CLOSURE (enclosing locals exist then).
            // Recording bare Native here is OK: FUNCCALL_INIT / resolveCall promote
            // `Class::{closure}` Native to RuntimeIndirect so $this reloads from the heap
            // (#35456). TYPE_CLOSURE also refreshes with ClosureWithBinding when present.
            $n = \count($block->opCodes);
            for ($j = $i + 1; $j < $n; ++$j) {
                $next = $block->opCodes[$j];
                if (OpCode::TYPE_RETURN === $next->type && null !== $next->arg1
                    && (int) $next->arg1 === (int) $op->arg1
                ) {
                    $this->recordReturnedClosureProxyForBlock($block, $proxy);
                    break;
                }
                if (OpCode::TYPE_CLOSURE === $next->type || OpCode::TYPE_RETURN === $next->type) {
                    break;
                }
            }
        }
    }

    /** True when the block declares a class-like before runQueue (#34896). */
    private function blockDeclaresClassLike(Block $block): bool
    {
        foreach ($block->opCodes as $op) {
            if (
                OpCode::TYPE_DECLARE_CLASS === $op->type
                || OpCode::TYPE_DECLARE_INTERFACE === $op->type
                || OpCode::TYPE_DECLARE_TRAIT === $op->type
                || OpCode::TYPE_DECLARE_ENUM === $op->type
            ) {
                return true;
            }
        }

        return false;
    }

    /** @param JIT\Call $proxy */
    private function recordReturnedClosureProxyForBlock(Block $block, $proxy): void
    {
        if (null === $block->func) {
            return;
        }
        $funcName = $block->func->name ?? null;
        if (!is_string($funcName) || '' === $funcName || '{main}' === $funcName) {
            return;
        }
        $lc = strtolower($funcName);
        if (null !== $block->func->class && is_string($block->func->class->value ?? null)) {
            $classLc = strtolower(ltrim((string) $block->func->class->value, '\\'));
            if ('' !== $classLc) {
                $lc = $classLc.'::'.$lc;
            }
        }
        $this->context->functionReturnedClosureCall[$lc] = $proxy;
    }

    /** Native invoke names for closures: `{closure}_N` or `Class::{closure}` (#35456). */
    private static function isClosureNativeInvokeName(string $name): bool
    {
        $lc = strtolower($name);

        return str_starts_with($lc, '{closure}_')
            || str_contains($lc, '::{closure}')
            || '{closure}' === $lc;
    }

  /** LLVM/C symbols reserved for the AOT entry wrapper and runtime init (#2779). */
    private const LLVM_RESERVED_FUNCTION_NAMES = [
        'main' => true,
        '__init__' => true,
        '__shutdown__' => true,
    ];

    private function llvmInternalName(string $name): string
    {
        $sanitized = preg_replace('/[^a-zA-Z0-9_]/', '_', $name) ?? $name;
        if (isset(self::LLVM_RESERVED_FUNCTION_NAMES[$sanitized])) {
            return 'php_user_'.$sanitized;
        }
        // Nested JIT: LLVM C API LLVMDumpValue collides with …dumpvalue symbols (#16565).
        if (preg_match('/(?:^|_)dumpvalue$/i', $sanitized)) {
            return preg_replace('/dumpvalue$/i', 'emit_dump_value', $sanitized);
        }

        return $sanitized;
    }

    private function isSuperglobalNameJitFunction(string $name): bool
    {
        $lower = strtolower($name);

        return str_ends_with($lower, '::issuperglobalname') || 'issuperglobalname' === $lower;
    }

    /** Native vm_run_smoke for M2 lib spine VM -r gate (#1846). */
    private function compileVmRunSmokeNative(
        string $internalName,
        Block $block,
        string $logicalName
    ): PHPLLVM\Value {
        $paramTypes = [];
        if (null !== $block->func) {
            foreach ($block->func->params as $idx => $param) {
                $paramTypes[] = $this->llvmTypeForCfgParam($param, $block, $idx);
            }
        }

        return JIT\VmSpineSmokeNative::compileVmRunSmokeNative(
            $this->context,
            $internalName,
            $logicalName,
            $paramTypes
        );
    }

    /** Native vm_unit_probe_run for M3 VM unit probe execute gate (#2619). */
    private function compileVmUnitProbeRunNative(
        string $internalName,
        Block $block,
        string $logicalName
    ): PHPLLVM\Value {
        $paramTypes = [];
        if (null !== $block->func) {
            foreach ($block->func->params as $idx => $param) {
                $paramTypes[] = $this->llvmTypeForCfgParam($param, $block, $idx);
            }
        }

        return JIT\VmUnitProbeExecuteNative::compileVmUnitProbeRunNative(
            $this->context,
            $internalName,
            $logicalName,
            $paramTypes
        );
    }

    /** Native bin/vm.php run() for M2 VM driver execute gate (#2201). */
    private function compileBinVmRunNative(
        string $internalName,
        Block $block,
        string $logicalName
    ): PHPLLVM\Value {
        $paramTypes = [];
        if (null !== $block->func) {
            foreach ($block->func->params as $idx => $param) {
                $paramTypes[] = $this->llvmTypeForCfgParam($param, $block, $idx);
            }
        }

        return JIT\VmDriverExecuteNative::compileBinVmRunNative(
            $this->context,
            $internalName,
            $logicalName,
            $paramTypes
        );
    }

    /** Native __compiler_is_superglobal_name for self-host AOT (issue #1056). */
    private function compileSuperglobalNameNative(
        string $internalName,
        Block $block,
        string $logicalName
    ): PHPLLVM\Value {
        $lcname = strtolower($logicalName);
        if (isset($this->context->functions[$lcname])) {
            return $this->context->functions[$lcname];
        }
        $strPtr = $this->context->getTypeFromString('__string__*');
        $boolTy = $this->context->getTypeFromString('bool');
        $func = $this->context->module->addFunction(
            $this->llvmInternalName($internalName),
            $this->context->context->functionType($boolTy, false, $strPtr)
        );
        $bb = $func->appendBasicBlock('entry');
        $saved = $this->context->builder;
        $this->context->builder = $this->context->context->builderCreate();
        $this->context->builder->positionAtEnd($bb);
        \PHPCompiler\JIT\Builtin\StringSuperglobalName::ensureLinked($this->context);
        $raw = $this->context->builder->call(
            $this->context->lookupFunction('__compiler_is_superglobal_name'),
            $func->getParam(0)
        );
        $this->context->builder->returnValue(
            $this->context->builder->icmp(
                \PHPLLVM\Builder::INT_NE,
                $raw,
                $raw->typeOf()->constInt(0, false)
            )
        );
        $this->context->builder->clearInsertionPosition();
        $this->context->builder = $saved;
        $this->context->functions[$lcname] = $func;
        $this->context->functionReturnType[$lcname] = 'bool';
        $this->context->functionProxies[$lcname] = new JIT\Call\Native(
            $func,
            $logicalName,
            [$strPtr],
            $this->collectParamDefaults($block)
        );

        return $func;
    }

    /**
     * Retired (#8707): inventory emit-helper now real-lowers JIT spine methods; deny-list only via
     * isM3CompileDriverSpineDenyName() for proven LLVM 9 crashers.
     */
    private function shouldStubM3InventoryEmitJitSpineMethods(): bool
    {
        return false;
    }

    private function compileRuntimeLoadJitM3Native(
        string $internalName,
        Block $block,
        string $logicalName
    ): PHPLLVM\Value {
        if ($this->shouldStubM3InventoryEmitJitSpineMethods()) {
            return $this->emitM3EmitTuRuntimeInitVoidStub($internalName, $logicalName, $block);
        }

        return $this->compileRuntimeSpinePhpLowering($internalName, $block, $logicalName);
    }

    /**
     * M3 compile-driver loadJitContext (#1402, #2846): separate FUNCDEF from loadJit to avoid LLVM 9 inlining crash.
     */
    private function compileRuntimeLoadJitContextM3Native(
        string $internalName,
        Block $block,
        string $logicalName
    ): PHPLLVM\Value {
        if ($this->shouldStubM3InventoryEmitJitSpineMethods()) {
            return $this->emitM3EmitTuRuntimeInitVoidStub($internalName, $logicalName, $block);
        }

        return $this->compileRuntimeSpinePhpLowering($internalName, $block, $logicalName);
    }

    /** M3 compile-driver createJit (#1402, #2847): `new JIT` separate from loadJit. */
    private function compileRuntimeCreateJitM3Native(
        string $internalName,
        Block $block,
        string $logicalName
    ): PHPLLVM\Value {
        if ($this->shouldStubM3InventoryEmitJitSpineMethods()) {
            return $this->emitM3EmitTuRuntimeInitVoidStub($internalName, $logicalName, $block);
        }

        return $this->compileRuntimeSpinePhpLowering($internalName, $block, $logicalName);
    }

    /** M3 compile-driver jitContextForLoadJit (#1402, #2847): thin wrapper — separate FUNCDEF from loadJit. */
    private function compileRuntimeJitContextForLoadJitM3Native(
        string $internalName,
        Block $block,
        string $logicalName
    ): PHPLLVM\Value {
        if ($this->shouldStubM3InventoryEmitJitSpineMethods()) {
            return $this->emitM3EmitTuRuntimeInitVoidStub($internalName, $logicalName, $block);
        }

        return $this->compileRuntimeSpinePhpLowering($internalName, $block, $logicalName);
    }

    /** M3 compile-driver loadJitCompileModuleFuncs (#1402, #2847): nested foreach — separate FUNCDEF from loadJit. */
    private function compileRuntimeLoadJitCompileModuleFuncsM3Native(
        string $internalName,
        Block $block,
        string $logicalName
    ): PHPLLVM\Value {
        if ($this->shouldStubM3InventoryEmitJitSpineMethods()) {
            return $this->emitM3EmitTuRuntimeInitVoidStub($internalName, $logicalName, $block);
        }

        return $this->compileRuntimeSpinePhpLowering($internalName, $block, $logicalName);
    }

    /** M3 compile-driver Runtime::__construct (#1494): C-floor vmContext — not full PHP CFG (LLVM 9; #2600). */
    private function compileRuntimeConstructM3Native(
        string $internalName,
        Block $block,
        string $logicalName
    ): PHPLLVM\Value {
        if ($this->shouldUseM3CompileDriverRealLowering()
            || $this->shouldUseM3EmitTuRuntimeMethodStub('__construct')
        ) {
            return $this->emitM3EmitTuRuntimeConstructNativeFunction($internalName, $logicalName, $block);
        }

        return $this->compileRuntimeSpinePhpLowering($internalName, $block, $logicalName);
    }

    /**
     * M3 compile-driver Runtime::__destruct (#2867): void no-op — module shutdown not required at AOT link.
     * PHP CFG foreach over $this->modules LLVM 9-crashed when deny-listed (#1402).
     */
    private function compileRuntimeDestructM3Native(
        string $internalName,
        Block $block,
        string $logicalName
    ): PHPLLVM\Value {
        return $this->emitM3EmitTuRuntimeInitVoidStub($internalName, $logicalName, $block);
    }

    /**
     * Emit-helper link without full compile_driver: real-lower Runtime parse spine (#2559).
     *
     * Uses host parse of lib/Runtime.php at link time; avoids LLVM 9 global ctor from bundling PHPTypes in emit TU.
     */
    private function shouldUseM3EmitTuEmitHelperSpineRealLowering(): bool
    {
        if (!$this->shouldUseM3EmitTuNativeBridge() || !$this->shouldUseEmitHelperLinkStubs()) {
            return false;
        }
        if ($this->shouldUseM3CompileDriverRealLowering()) {
            return false;
        }
        $flag = Config::getenv('PHP_COMPILER_M3_EMIT_HELPER_SPINE');

        return '1' === $flag || 'true' === strtolower((string) $flag);
    }

    /** Emit TU null-returning stubs unless M3 real-lowering is enabled (#2512, #2542). */
    private function shouldUseM3EmitTuRuntimeMethodStub(string $methodLc): bool
    {
        // Inventory argv driver (bin/compile.php re-link) — not emit-helper inventory link (#2540).
        if ($this->shouldUseM3InventoryEmitDriver() && !$this->shouldUseEmitHelperLinkStubs()) {
            static $inventoryEmitSpine = [
                '__construct',
                'initparsepipeline',
                'initcompiler',
                'initvmcontext',
                'loadcoremodules',
                'standalone',
            ];
            if (in_array($methodLc, $inventoryEmitSpine, true)) {
                // Real argv parse spine needs ctor/init; standalone stays stubbed (#15597).
                // Do not void-stub initParsePipeline under M5 — seed would lack $parser (#26756).
                if ($this->shouldUseM5DriverHostCompile() && 'initparsepipeline' === $methodLc) {
                    return false;
                }
                if ($this->shouldRealLowerInventoryArgvParseSpine() && 'standalone' !== $methodLc) {
                    return false;
                }

                return true;
            }
        }
        if (!$this->shouldUseM3EmitTuNativeBridge() && !$this->shouldUseM3InventoryEmitDriver()) {
            return false;
        }
        if (!$this->shouldUseM3CompileDriverRealLowering()) {
            if ($this->shouldUseM3EmitTuEmitHelperSpineRealLowering()) {
                $emitHelperSpineReal = [
                    'preprocesssourceforparse',
                    'rewritesourcebeforeparser',
                    'preparesourceforparser',
                    'parse',
                    'compileemitsmoke',
                ];
                if ($this->shouldUseVendorPrelinkExecutableEmit()
                    || $this->shouldUseSelfHostExecutableEmit()) {
                    $emitHelperSpineReal = ['parse', 'compile', 'standalone'];
                }

                return !in_array($methodLc, $emitHelperSpineReal, true);
            }

            return true;
        }

        return !$this->isM3CompileDriverRealLoweringName('phpcompiler\\runtime::'.$methodLc);
    }

    /**
     * Native parseAndCompile for M3 emit TU — avoids LLVM 9 crash lowering full Runtime::compile (#2516).
     */
    private function compileRuntimeParseAndCompileM3Native(
        string $internalName,
        Block $block,
        string $logicalName
    ): PHPLLVM\Value {
        if ($this->shouldUseM3EmitTuNativeBridge() || $this->shouldUseM3InventoryEmitDriver()) {
            $targetLc = str_ends_with(strtolower($logicalName), '\\runtime::parseandcompileemitsmoke')
                ? 'parseandcompileemitsmoke'
                : 'parseandcompile';

            return \PHPCompiler\JIT\BootstrapCompileSmokeM3Emit::declareRuntimeParseAndCompileViaParseEmitSmoke(
                $this->context,
                $this->llvmInternalName($internalName),
                $logicalName,
                $targetLc
            );
        }

        return $this->compileRuntimeSpinePhpLowering($internalName, $block, $logicalName);
    }

    /**
     * Native Runtime::__construct for emit TU — C-floor vmContext when real-lowering (#2513, #2550).
     */
    private function emitM3EmitTuRuntimeConstructNativeFunction(
        string $internalName,
        string $logicalName,
        Block $block
    ): PHPLLVM\Value {
        $lcname = strtolower($logicalName);
        if (isset($this->context->functions[$lcname])) {
            return $this->context->functions[$lcname];
        }
        $objectPtr = $this->context->getTypeFromString('__object__*');
        $i64 = $this->context->getTypeFromString('int64');
        $voidTy = $this->context->getTypeFromString('void');
        $func = $this->context->module->addFunction(
            $this->llvmInternalName($internalName),
            $this->context->context->functionType($voidTy, false, $objectPtr, $i64)
        );
        $bb = $func->appendBasicBlock('entry');
        $saved = $this->context->builder;
        $this->context->builder = $this->context->context->builderCreate();
        $this->context->builder->positionAtEnd($bb);
        if ($this->shouldUseM3CompileDriverRealLowering() || $this->shouldUseSelfHostJitStubs()) {
            \PHPCompiler\JIT\RuntimeInitVmContext::emit(
                $this->context,
                $this->context->type->object,
                $func->getParam(0)
            );
            $modeSlot = $this->context->type->object->propertyFetch(
                $func->getParam(0),
                'PHPCompiler\\Runtime',
                'mode'
            );
            $modeVar = new JIT\Variable(
                $this->context,
                JIT\Variable::TYPE_NATIVE_LONG,
                JIT\Variable::KIND_VALUE,
                $func->getParam(1)
            );
            $this->context->type->object->propertyStore(
                $modeSlot->objectPropertySlot,
                $modeVar,
                JIT\Variable::TYPE_NATIVE_LONG
            );
        }
        $this->context->builder->returnVoid();
        $this->context->builder->clearInsertionPosition();
        $this->context->builder = $saved;
        $this->context->functions[$lcname] = $func;
        $this->context->functionReturnType[$lcname] = 'void';
        $this->context->functionProxies[$lcname] = new JIT\Call\Native(
            $func,
            $logicalName,
            [$objectPtr, $i64],
            $this->collectParamDefaults($block)
        );

        return $func;
    }

    private function compileRuntimeInitParsePipelineM3Native(
        string $internalName,
        Block $block,
        string $logicalName
    ): PHPLLVM\Value {
        $lcname = strtolower($logicalName);
        // M5 argv / inventory argv: C-floor BEFORE void-stub checks — inventory emit otherwise
        // registers a 1-byte `ret` and leaves $parser null (#26756 / re-#23468, #36144).
        if ($this->shouldUseM5ParseSpineCFloor()) {
            if (isset($this->context->functions[$lcname])) {
                return $this->context->functions[$lcname];
            }
            $objectPtr = $this->context->getTypeFromString('__object__*');
            $func = $this->context->module->addFunction(
                $this->llvmInternalName($internalName),
                $this->context->context->functionType(
                    $this->context->getTypeFromString('void'),
                    false,
                    $objectPtr
                )
            );
            $bb = $func->appendBasicBlock('entry');
            $saved = $this->context->builder;
            $this->context->builder = $this->context->context->builderCreate();
            $this->context->builder->positionAtEnd($bb);
            JIT\RuntimeInitParsePipeline::emit(
                $this->context,
                $this->context->type->object,
                $func->getParam(0)
            );
            $this->context->builder->returnVoid();
            $this->context->builder->clearInsertionPosition();
            $this->context->builder = $saved;
            $this->context->functions[$lcname] = $func;
            $this->context->functionReturnType[$lcname] = 'void';
            $this->context->functionProxies[$lcname] = new JIT\Call\Native(
                $func,
                $logicalName,
                [$objectPtr],
                $this->collectParamDefaults($block)
            );

            return $func;
        }
        if ($this->shouldUseM3EmitTuRuntimeMethodStub('initparsepipeline')) {
            return $this->emitM3EmitTuRuntimeInitVoidStub($internalName, $logicalName, $block);
        }
        if (isset($this->context->functions[$lcname])) {
            return $this->context->functions[$lcname];
        }
        if ($this->shouldUseM3EmitTuNativeBridge()
            || $this->shouldUseM3CompileDriverRealLowering()
            || $this->shouldRealLowerInventoryArgvParseSpine()
        ) {
            $this->compileM3EmitTuRuntimeMethodFromDeclareClassBlocks(['initparsepipeline']);
            if (!isset($this->context->functions[$lcname])) {
                $this->compileM3EmitTuRuntimeMethodFromRuntimePhpFile('initparsepipeline', $logicalName, $lcname);
            }
            if (isset($this->context->functions[$lcname])) {
                return $this->context->functions[$lcname];
            }

            return $this->emitM3EmitTuRuntimeInitVoidStub($internalName, $logicalName, $block);
        }

        return $this->compileRuntimeSpinePhpLowering($internalName, $block, $logicalName);
    }

    private function compileRuntimeInitCompilerM3Native(
        string $internalName,
        Block $block,
        string $logicalName
    ): PHPLLVM\Value {
        if ($this->shouldUseM3EmitTuRuntimeMethodStub('initcompiler')) {
            return $this->emitM3EmitTuRuntimeInitVoidStub($internalName, $logicalName, $block);
        }
        // Emit TU and M3 compile_driver share C-floor initCompiler (#2568); PHP CFG LLVM 9 crash on ctor spine.
        if ($this->shouldUseM3EmitTuNativeBridge() || $this->shouldUseM3CompileDriverRealLowering()) {
            $lcname = strtolower($logicalName);
            if (isset($this->context->functions[$lcname])) {
                return $this->context->functions[$lcname];
            }
            $objectPtr = $this->context->getTypeFromString('__object__*');
            $func = $this->context->module->addFunction(
                $this->llvmInternalName($internalName),
                $this->context->context->functionType(
                    $this->context->getTypeFromString('void'),
                    false,
                    $objectPtr
                )
            );
            $bb = $func->appendBasicBlock('entry');
            $saved = $this->context->builder;
            $this->context->builder = $this->context->context->builderCreate();
            $this->context->builder->positionAtEnd($bb);
            \PHPCompiler\JIT\RuntimeInitCompiler::emit(
                $this->context,
                $this->context->type->object,
                $func->getParam(0)
            );
            $this->context->builder->returnVoid();
            $this->context->builder->clearInsertionPosition();
            $this->context->builder = $saved;
            $this->context->functions[$lcname] = $func;
            $this->context->functionReturnType[$lcname] = 'void';
            $this->context->functionProxies[$lcname] = new JIT\Call\Native(
                $func,
                $logicalName,
                [$objectPtr],
                $this->collectParamDefaults($block)
            );

            return $func;
        }

        return $this->compileRuntimeSpinePhpLowering($internalName, $block, $logicalName);
    }

    private function compileRuntimeInitVmContextM3Native(
        string $internalName,
        Block $block,
        string $logicalName
    ): PHPLLVM\Value {
        // Emit TU and compile_driver share C-floor initVmContext (#2513, #2540).
        $lcname = strtolower($logicalName);
        if (isset($this->context->functions[$lcname])) {
            return $this->context->functions[$lcname];
        }
        $objectPtr = $this->context->getTypeFromString('__object__*');
        $func = $this->context->module->addFunction(
            $this->llvmInternalName($internalName),
            $this->context->context->functionType(
                $this->context->getTypeFromString('void'),
                false,
                $objectPtr
            )
        );
        $bb = $func->appendBasicBlock('entry');
        $saved = $this->context->builder;
        $this->context->builder = $this->context->context->builderCreate();
        $this->context->builder->positionAtEnd($bb);
        \PHPCompiler\JIT\RuntimeInitVmContext::emit($this->context, $this->context->type->object, $func->getParam(0));
        $this->context->builder->returnVoid();
        $this->context->builder->clearInsertionPosition();
        $this->context->builder = $saved;
        $this->context->functions[$lcname] = $func;
        $this->context->functionReturnType[$lcname] = 'void';
        $this->context->functionProxies[$lcname] = new JIT\Call\Native(
            $func,
            $logicalName,
            [$objectPtr],
            $this->collectParamDefaults($block)
        );
        return $func;
    }

    private function compileRuntimeLoadCoreModulesM3Native(
        string $internalName,
        Block $block,
        string $logicalName
    ): PHPLLVM\Value {
        // Same 0-arg-vs-1-arg signature mismatch as initParsePipeline (#2967): the PHP-CFG lowering
        // drops the implicit $this (module load() calls are elided in the self-host spine) while
        // RuntimeEmitTuInit calls it as `void(__object__*)`. Emit the 1-arg void stub in every mode.
        return $this->emitM3EmitTuRuntimeInitVoidStub($internalName, $logicalName, $block);
    }

    /** No-op init helper for emit TU link — real init deferred to Batch A (#2516). */
    private function emitM3EmitTuRuntimeInitVoidStub(
        string $internalName,
        string $logicalName,
        Block $block
    ): PHPLLVM\Value {
        $lcname = strtolower($logicalName);
        if (isset($this->context->functions[$lcname])) {
            return $this->context->functions[$lcname];
        }
        $objectPtr = $this->context->getTypeFromString('__object__*');
        $voidTy = $this->context->getTypeFromString('void');
        $func = $this->context->module->addFunction(
            $this->llvmInternalName($internalName),
            $this->context->context->functionType($voidTy, false, $objectPtr)
        );
        $bb = $func->appendBasicBlock('entry');
        $saved = $this->context->builder;
        $this->context->builder = $this->context->context->builderCreate();
        $this->context->builder->positionAtEnd($bb);
        $this->context->builder->returnVoid();
        $this->context->builder->clearInsertionPosition();
        $this->context->builder = $saved;
        $this->context->functions[$lcname] = $func;
        $this->context->functionReturnType[$lcname] = 'void';
        $this->context->functionProxies[$lcname] = new JIT\Call\Native(
            $func,
            $logicalName,
            [$objectPtr],
            $this->collectParamDefaults($block)
        );

        return $func;
    }

    /**
     * ResolveSidecarJitHelper path remap — identity is enough for gen-0 argv functional smoke
     * (never-seen scripts use live paths). Avoids NestedJIT IR blow-up (#26756 / #23970).
     */
    private function isM5ArgvResolveSidecarIdentityStubName(string $lower): bool
    {
        return str_contains($lower, '\\resolvesidecarjithelper::');
    }

    private function emitM5ArgvResolveSidecarIdentityStub(
        string $internalName,
        string $logicalName,
        Block $block
    ): PHPLLVM\Value {
        $lcname = strtolower($logicalName);
        if (isset($this->context->functions[$lcname])) {
            return $this->context->functions[$lcname];
        }
        $args = $this->normalizeSelfHostNativeCallArgTypes(
            $this->collectStubFunctionArgTypes($block),
            $logicalName
        );
        $callbackType = $this->cfgFunctionReturnCallbackType($block->func) ?? '__value__';
        $returnType = $this->context->getTypeFromString($callbackType);
        $func = $this->context->module->addFunction(
            $this->llvmInternalName($internalName),
            $this->context->context->functionType($returnType, false, ...$args)
        );
        $bb = $func->appendBasicBlock('m5_argv_resolve_sidecar_identity');
        $saved = $this->context->builder;
        $savedActive = $this->context->activeFunction;
        $this->context->builder = $this->context->context->builderCreate();
        $this->context->builder->positionAtEnd($bb);
        $this->context->functions[$lcname] = $func;
        $this->context->activeFunction = $lcname;
        $defaultArgs = $this->collectParamDefaults($block);
        if ($func->countParams() > 0) {
            $this->context->builder->returnValue($func->getParam(0));
        } else {
            $this->emitSelfHostStubReturn($callbackType, $func);
        }
        $this->context->builder->clearInsertionPosition();
        $this->context->builder = $saved;
        $this->context->activeFunction = $savedActive;
        $this->context->functionReturnType[$lcname] = $callbackType;
        $this->context->functionProxies[$lcname] = new JIT\Call\Native(
            $func,
            $logicalName,
            $args,
            $defaultArgs
        );

        return $func;
    }

    private function compileRuntimeSpinePhpLowering(
        string $internalName,
        Block $block,
        string $logicalName
    ): PHPLLVM\Value {
        $lcname = strtolower($logicalName);
        if (isset($this->context->functions[$lcname])) {
            return $this->context->functions[$lcname];
        }
        return $this->compileBlockPhpLowering($internalName, $block, $logicalName, $logicalName);
    }

    /**
     * Stub out opcode_type_name() — the real implementation is a large switch that crashes LLVM 9 JIT (#540).
     */
    private function compileSkippedOpcodeNameStub(string $internalName, Block $block): PHPLLVM\Value
    {
        $lcname = strtolower($internalName);
        if (isset($this->context->functions[$lcname])) {
            return $this->context->functions[$lcname];
        }
        $mangled = $this->llvmInternalName($internalName);
        $func = $this->context->module->addFunction(
            $mangled,
            $this->context->context->functionType(
                $this->context->getTypeFromString('__string__*'),
                false,
                $this->context->getTypeFromString('int64')
            )
        );
        $bb = $func->appendBasicBlock('stub');
        $saved = $this->context->builder;
        $this->context->builder = $this->context->context->builderCreate();
        $this->context->builder->positionAtEnd($bb);
        $this->context->builder->returnValue(
            $this->context->builder->load($this->context->constantStringFromString('TYPE_UNKNOWN'))
        );
        $this->context->builder->clearInsertionPosition();
        $this->context->builder = $saved;
        $this->context->functions[$lcname] = $func;

        return $func;
    }

    private function isSkippedVmHotPathName(string $name): bool
    {
        $lower = strtolower($name);
        // Self-host AOT bundles lib/VM.php for closure lint only; stub the interpreter (#816, #913).
        if (str_contains($lower, '\\vm::')) {
            return true;
        }

        return str_ends_with($lower, '::runframes') || str_ends_with($lower, '::defineclass')
            || str_ends_with($lower, '::getframe');
    }

    /**
     * M3 emit TU bundles Compiler/Runtime for link only — stub JIT/VM/Lint bodies (#2442).
     */
    private function isSkippedM3EmitTuBundledHelperName(string $name): bool
    {
        if (!$this->shouldUseM3EmitTuNativeBridge()) {
            return false;
        }
        $lower = strtolower($name);
        if ($this->isM3EmitTuRuntimeSpineLoweringName($lower)) {
            return false;
        }
        if ($this->isM3EmitTuCompilerSpineLoweringName($lower)) {
            return false;
        }
        if ($this->isBootstrapM3RuntimeEmitBridgeName($lower)) {
            return false;
        }

        return str_contains($lower, '\\jit\\')
            || str_contains($lower, '\\lint\\')
            || str_contains($lower, '\\vm\\')
            || str_contains($lower, '\\printer::')
            || str_contains($lower, '\\handler::')
            || str_contains($lower, '\\optimizer::');
    }

    /** Stub bundled lib/ interpreter helpers for self-host AOT (#557, #816). */
    private function isSkippedBootstrapInterpreterHotPathName(string $name): bool
    {
        if (!$this->shouldUseSelfHostJitStubs()) {
            return false;
        }
        $lower = strtolower($name);
        if ($this->isM3CompileDriverRealLoweringName($lower)) {
            return false;
        }
        if ($this->isM3EmitTuRuntimeSpineLoweringName($lower)) {
            return false;
        }
        if ($this->shouldUseM3CompileDriverRealLowering() && JIT\VariableTypeMapNative::isNativeLoweringName($lower)) {
            return false;
        }
        if ($this->isSkippedSelfHostEntryName($name)) {
            return false;
        }
        if (str_contains($lower, '\\vm::')
            || str_contains($lower, '\\block::')
            || str_contains($lower, '\\frame::')
            || str_contains($lower, '\\module::')
            || str_contains($lower, '\\runtime::')
            || $this->isSkippedJitResultHotPathName($lower)
        ) {
            return true;
        }
        if (!$this->shouldUseSelfHostJitStubs()) {
            return false;
        }

        return str_contains($lower, '\\vm\\')
            || str_contains($lower, '\\vm\\variable::')
            || str_contains($lower, '\\printer::')
            || str_contains($lower, '\\opcode::')
            || str_contains($lower, '\\methodvisibility::')
            || str_contains($lower, '\\nullsafelivenessdetector::')
            || str_contains($lower, '\\moduleabstract::')
            || str_contains($lower, '\\opcodenames::')
            || str_contains($lower, '\\lint\\')
            || (str_contains($lower, '\\bootstrapaot\\') && !$this->isM3CompileDriverRealLoweringName($lower))
            || str_contains($lower, '\\jit\\')
            || str_contains($lower, '\\func\\jit::')
            || str_contains($lower, '\\func\\internal::')
            || str_contains($lower, '\\jit::');
    }

    /** Skip JIT\\Result FFI bodies (getCallable/getFunc) during self-host native link (#816). */
    private function isSkippedJitResultHotPathName(string $lowerName): bool
    {
        if (!$this->shouldUseSelfHostJitStubs()) {
            return false;
        }
        if ($this->isM3CompileDriverRealLoweringName($lowerName)) {
            return false;
        }

        return str_contains($lowerName, '\\jit\\result::');
    }

    /** M3 emit TU: PHP CFG lowering for compile spine only (#1937, #1983). */
    private function isM3EmitHelperCompilerPhpLoweringName(string $lower): bool
    {
        if (!$this->shouldUseEmitHelperLinkStubs()) {
            return false;
        }
        // Emit TU links via native bridge + LLVM stubs; PHP CFG here segfaults LLVM 9 (#2540).
        if ($this->shouldUseM3EmitTuNativeBridge()) {
            return false;
        }
        if ($this->isM3EmitTuCompilerSpineLoweringName($lower)) {
            return true;
        }

        return str_ends_with($lower, '\\compiler::compile')
            || str_ends_with($lower, '\\compiler::compilefunc');
    }

    /**
     * Minimal Compiler CFG chain for native emit TU (trivial echo sources — #1937).
     *
     * @return list<string> method suffixes after \\compiler::
     */
    private function m3EmitTuCompilerSpineMethodSuffixes(): array
    {
        return [
            'compile',
            'compileemitsmoke',
            'compilefunc',
            'compilecfgblock',
            'compilecfgbranch',
            'compileblock',
            'compileops',
            'compileop',
            'compileparam',
            'compileterminal',
            'compileoperand',
            'compilestmt',
            'compileexpr',
            'compileboolconstant',
            'compilebooltemporary',
        ];
    }

    /**
     * Compiler helpers for native lowering on M3 compile_driver link (#1768).
     *
     * PHP CFG lowering of these hits LLVM 9 dominance verify failures; use
     * {@see CompilerOperandChainNative} instead.
     *
     * @return list<string> method suffixes after \\compiler::
     */
    private function m3CompileDriverCompilerNativeLoweringSuffixes(): array
    {
        return [
            'operandschainequal',
            'unwrapoperandchain',
        ];
    }

    /**
     * @return list<string> method suffixes after \\compiler::
     */
    private function m3CompileDriverCompilerPhpLoweringSuffixes(): array
    {
        // M5 (#2666): allow the M3 emit helper to compile inventory-scale sources (lib/Compiler.php,
        // bin/compile.php) by lowering a minimal Compiler compile chain (avoid LLVM 9 emit-TU link
        // crashes when lowering the full Compiler into the helper module; #2540).
        return [
            'compile',
            'compilecfgblock',
            'compilecfgbranch',
            'compileblock',
            'compileops',
            'compileop',
            'compilestmt',
            'compileexpr',
            'compileoperand',
            'compileterminal',
            'compileparam',
            'compilefunction',
            'compilefunccall',
            'compileboolconstant',
            'compilebooltemporary',
            'compilecoalesce',
            'compilenullsafe',
            'compileisset',
            'compileissetmulti',
            'compilearrayliteral',
            'compilearraydimfetchread',
            'findcoalesce',
            'resolvecoalesce',
            'resolveisset',
            'isarraydim',
            'requireoperandslot',
            'resolvesimplevariablename',
            // class-heavy sources (lib/*.php) need class lowering
            'compileclasslike',
            'compileclassbody',
            'compileglobalconst',
            'compileincludeop',
            'compileswitchasjumpifchain',
            'trycompiledefineasglobalconst',
            'compileclassconstfetch',
            'getopcodetype',
            'markcallerlocalsusedbyliteralinclude',
            'setpropertyhookregistry',
            'setknownclassreadonly',
            'setbarerethrowlines',
        ];
    }

    private function isM3CompileDriverCompilerNativeLoweringName(string $lower): bool
    {
        if (!$this->shouldUseM3CompileDriverRealLowering()) {
            return false;
        }
        foreach ($this->m3CompileDriverCompilerNativeLoweringSuffixes() as $suffix) {
            if (str_ends_with($lower, '\\compiler::'.$suffix)) {
                return true;
            }
        }

        return false;
    }

    private function isM3CompileDriverCompilerPhpLoweringName(string $lower): bool
    {
        if (!$this->shouldUseM3CompileDriverRealLowering()) {
            return false;
        }
        foreach ($this->m3CompileDriverCompilerPhpLoweringSuffixes() as $suffix) {
            if (str_ends_with($lower, '\\compiler::'.$suffix)) {
                return true;
            }
        }

        return false;
    }

    private function isM3EmitTuCompilerSpineLoweringName(string $lower): bool
    {
        if (!$this->shouldUseM3EmitTuNativeBridge()) {
            return false;
        }
        foreach ($this->m3EmitTuCompilerSpineMethodSuffixes() as $suffix) {
            if (str_ends_with($lower, '\\compiler::'.$suffix)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Compiler CFG helpers allowed through emit-TU stub gate for M3 compile-driver (#2633).
     *
     * Kept smaller than {@see m3EmitTuCompilerSpineMethodSuffixes()} to avoid LLVM 9 link crash
     * when lowering the full Compiler into the emit-helper module (#2540).
     */
    private function isM3EmitTuCompilerCompileChainLoweringName(string $lower): bool
    {
        if (!$this->shouldUseM3CompileDriverRealLowering()) {
            return false;
        }
        foreach ($this->m3EmitTuCompilerCompileChainLoweringSuffixes() as $suffix) {
            if (str_ends_with($lower, '\\compiler::'.$suffix)) {
                return true;
            }
        }

        return false;
    }

    private function isM3EmitTuRuntimeCompileDriverSpineLoweringName(string $lower): bool
    {
        if (!$this->shouldUseM3CompileDriverRealLowering()) {
            return false;
        }
        foreach ([
            'parse',
            'compileemitsmoke',
            'initparsepipeline',
            'initcompiler',
            'loadcoremodules',
        ] as $suffix) {
            if (str_ends_with($lower, '\\runtime::'.$suffix)) {
                return true;
            }
        }

        return false;
    }

    /** @return list<string> */
    private function m3EmitTuCompilerCompileChainLoweringSuffixes(): array
    {
        return [
            'compilecfgblock',
            'compilecfgbranch',
            'compileblock',
            'compileops',
            'compileop',
            'compilestmt',
            'compileexpr',
            'compileoperand',
            'compileterminal',
            'compileparam',
            'compilefunction',
            'compilefunccall',
            'compileboolconstant',
            'compilebooltemporary',
            'compilecoalesce',
            'compilenullsafe',
            'compileisset',
            'compileissetmulti',
            'compilearrayliteral',
            'compilearraydimfetchread',
            'compileincludeop',
            'compileclasslike',
            'compileclassbody',
            'compileglobalconst',
            'compileclassconstfetch',
            'compileinstanceof',
            'compileswitchasjumpifchain',
            'getopcodetype',
            'compiletypeconstrainedvariable',
            'trycompiledefineasglobalconst',
            'tryfoldvariablefunctionname',
            'compilecallargsends',
            'callargunpack',
            'markcallerlocalsusedbyliteralinclude',
            'requireoperandslot',
            'resolvesimplevariablename',
            'operandschainequal',
            'unwrapoperandchain',
            'splitcfgblockafterstringkeyedarray',
            'inheritfuncfromparent',
            'needscfg',
            'unwrap',
            'isarraydim',
            'findcoalesce',
            'resolvecoalesce',
            'resolveisset',
            'isredundantcoalescetailassign',
            'compilefirstclasscallable',
            'compilefirstclassfunctionnameslot',
            'compilefirstclassstaticnameslot',
            'setpropertyhookregistry',
            'setknownclassreadonly',
            'setbarerethrowlines',
        ];
    }

    /**
     * Lightweight native stubs for Runtime spine in M3 emit TU — never full PHP CFG (#2442).
     *
     * LLVM 9 crashes lowering initVmContext / parseAndCompile bodies in the emit-helper bundle.
     */
    private function tryCompileM3EmitTuRuntimeSpineNative(
        string $internalName,
        Block $block,
        ?string $logicalName
    ): ?PHPLLVM\Value {
        if (!$this->shouldUseM3EmitTuNativeBridge() || null === $logicalName) {
            return null;
        }
        $emitLc = strtolower($logicalName);
        if (!$this->isM3EmitTuRuntimeSpineLoweringName($emitLc)) {
            return null;
        }
        if (str_ends_with($emitLc, '\\runtime::__construct')) {
            return $this->emitM3EmitTuRuntimeConstructNativeFunction($internalName, $logicalName, $block);
        }
        if (str_ends_with($emitLc, '\\runtime::initvmcontext')) {
            if ($this->shouldUseM3EmitTuRuntimeMethodStub('initvmcontext')) {
                return $this->emitM3EmitTuRuntimeInitVoidStub($internalName, $logicalName, $block);
            }

            return $this->compileRuntimeInitVmContextM3Native($internalName, $block, $logicalName);
        }
        if (str_ends_with($emitLc, '\\runtime::initparsepipeline')) {
            if ($this->shouldUseM3EmitTuRuntimeMethodStub('initparsepipeline')) {
                return $this->emitM3EmitTuRuntimeInitVoidStub($internalName, $logicalName, $block);
            }

            return $this->compileRuntimeInitParsePipelineM3Native($internalName, $block, $logicalName);
        }
        if (str_ends_with($emitLc, '\\runtime::initcompiler')) {
            if ($this->shouldUseM3EmitTuRuntimeMethodStub('initcompiler')) {
                return $this->emitM3EmitTuRuntimeInitVoidStub($internalName, $logicalName, $block);
            }

            return $this->compileRuntimeInitCompilerM3Native($internalName, $block, $logicalName);
        }
        if (str_ends_with($emitLc, '\\runtime::loadcoremodules')) {
            if ($this->shouldUseM3EmitTuRuntimeMethodStub('loadcoremodules')) {
                return $this->emitM3EmitTuRuntimeInitVoidStub($internalName, $logicalName, $block);
            }

            return $this->compileRuntimeLoadCoreModulesM3Native($internalName, $block, $logicalName);
        }
        if (str_ends_with($emitLc, '\\runtime::loadjitcontext')) {
            if ($this->shouldUseM3CompileDriverRealLowering() && !$this->shouldStubM3InventoryEmitJitSpineMethods()) {
                return null;
            }

            return $this->emitM3EmitTuRuntimeInitVoidStub($internalName, $logicalName, $block);
        }
        if (str_ends_with($emitLc, '\\runtime::createjit')
            || str_ends_with($emitLc, '\\runtime::jitcontextforloadjit')
            || str_ends_with($emitLc, '\\runtime::loadjitcompilemodulefuncs')
        ) {
            if ($this->shouldUseM3CompileDriverRealLowering() && !$this->shouldStubM3InventoryEmitJitSpineMethods()) {
                return null;
            }

            return $this->emitM3EmitTuRuntimeInitVoidStub($internalName, $logicalName, $block);
        }
        if (str_ends_with($emitLc, '\\runtime::loadjit')
            || str_ends_with($emitLc, '\\runtime::jitemitinplace')
        ) {
            if ($this->shouldUseM3CompileDriverRealLowering()) {
                return null;
            }

            return $this->emitM3EmitTuRuntimeInitVoidStub($internalName, $logicalName, $block);
        }
        if ($this->shouldUseM3CompileDriverRealLowering()) {
            if (str_ends_with($emitLc, '\\runtime::parse')
                || str_ends_with($emitLc, '\\runtime::compileemitsmoke')
                || str_ends_with($emitLc, '\\runtime::standalone')
                || str_ends_with($emitLc, '\\runtime::compile')
                || str_ends_with($emitLc, '\\runtime::parseandcompile')
                || str_ends_with($emitLc, '\\runtime::parseandcompileemitsmoke')
            ) {
                return null;
            }
        }
        if (str_ends_with($emitLc, '\\runtime::parse')) {
            if ($this->shouldUseM3EmitTuRuntimeMethodStub('parse')) {
                return $this->emitM3EmitTuRuntimeParseStubNative($internalName, $logicalName, $block);
            }

            return null;
        }
        if (str_ends_with($emitLc, '\\runtime::compileemitsmoke')) {
            if ($this->shouldUseM3EmitTuRuntimeMethodStub('compileemitsmoke')) {
                return $this->emitM3EmitTuRuntimeCompileEmitSmokeNative($internalName, $logicalName, $block);
            }

            return null;
        }
        if (str_ends_with($emitLc, '\\runtime::standalone')) {
            if ($this->shouldUseM3EmitTuRuntimeMethodStub('standalone')) {
                return $this->emitM3EmitTuRuntimeStandaloneStubNative($internalName, $logicalName, $block);
            }

            return null;
        }
        if (str_ends_with($emitLc, '\\runtime::compile')
            || str_ends_with($emitLc, '\\runtime::parseandcompile')
            || str_ends_with($emitLc, '\\runtime::parseandcompileemitsmoke')
            || str_ends_with($emitLc, '\\runtime::jitcompileblock')
        ) {
            return $this->emitM3EmitTuRuntimeParseStubNative($internalName, $logicalName, $block);
        }

        return null;
    }

    /** Stub Compiler CFG spine in M3 emit TU — LLVM 9 cannot lower full compile() chain (#2442). */
    private function tryCompileM3EmitTuCompilerSpineNative(
        string $internalName,
        Block $block,
        ?string $logicalName
    ): ?PHPLLVM\Value {
        if (!$this->shouldUseM3EmitTuNativeBridge() || null === $logicalName) {
            return null;
        }
        $emitLc = strtolower($logicalName);
        if (!$this->isM3EmitTuCompilerSpineLoweringName($emitLc)) {
            return null;
        }
        if ('phpcompiler\\compiler::compileemitsmoke' === $emitLc) {
            if (!$this->shouldUseM3CompileDriverRealLowering()) {
                return $this->emitM3EmitTuCompilerCompileEmitSmokeNativeFunction($internalName, $logicalName);
            }

            return null;
        }
        if ($this->isM3CompileDriverCompilerNativeLoweringName($emitLc)) {
            return JIT\CompilerOperandChainNative::compile(
                $this->context,
                $this->llvmInternalName($internalName),
                $block,
                $logicalName
            );
        }
        if ($this->isM3CompileDriverCompilerPhpLoweringName($emitLc)) {
            return $this->compileRuntimeSpinePhpLowering($internalName, $block, $logicalName);
        }

        return $this->compileSkippedCompilerSplitCfgStub(
            $internalName,
            $block,
            $logicalName
        );
    }

    /** Runtime methods the M3 emit native bridge calls — never self-host stub (#2442). */
    private function isM3EmitTuRuntimeSpineLoweringName(string $lower): bool
    {
        if (!$this->shouldUseM3EmitTuNativeBridge()) {
            return false;
        }
        if ($this->shouldStubInventoryEmitHelperBundledBodies()) {
            foreach (['parse', 'preparesourceforparser', 'preprocesssourceforparse', 'rewritesourcebeforeparser'] as $stubSuffix) {
                if (str_ends_with($lower, '\\runtime::'.$stubSuffix)) {
                    return false;
                }
            }
        }
        foreach ([
            '__construct',
            'initparsepipeline',
            'initcompiler',
            'initvmcontext',
            'loadcoremodules',
            'preparesourceforparser',
            'parse',
            'compile',
            'compileemitsmoke',
            'parseandcompile',
            'parseandcompileemitsmoke',
            'parseandcompilefile',
            'noteparsecompilenullforscript',
            'peeklastparsefailure',
            'standalone',
            'loadjit',
            'jitcompileblock',
            'jitemitinplace',
        ] as $suffix) {
            if (str_ends_with($lower, '\\runtime::'.$suffix)) {
                return true;
            }
        }

        return false;
    }

    private function isM3EmitTuScriptMain(Block $block): bool
    {
        return null !== $block->func
            && null === $block->func->class
            && '{main}' === $block->func->name;
    }

    private function isSkippedCompilerHotPathName(string $name): bool
    {
        $lower = strtolower($name);
        if ($this->isM3CompileDriverRealLoweringName($lower)) {
            return false;
        }
        if ($this->isM3EmitHelperCompilerPhpLoweringName($lower)) {
            return false;
        }
        if ($this->isM3CompileDriverCompilerPhpLoweringName($lower)) {
            return false;
        }
        if ($this->isM3CompileDriverCompilerNativeLoweringName($lower)) {
            return false;
        }
        if ($this->shouldUseM3EmitTuNativeBridge() && str_contains($lower, '\\compiler::compileemitsmoke')) {
            return false;
        }
        if ($this->shouldUseSelfHostJitStubs() && str_contains($lower, '\\compiler::')) {
            return true;
        }

        return str_contains($lower, 'splitcfgblockafterstringkeyedarray')
            || str_contains($lower, 'compilecfgblock')
            || str_contains($lower, 'compileblock')
            || str_contains($lower, 'compileops')
            || str_contains($lower, 'compileclasslike')
            || str_contains($lower, 'compileclassbody')
            || str_contains($lower, 'compilefunction')
            || str_contains($lower, 'compileglobalconst')
            || str_contains($lower, 'compilestmt')
            || str_contains($lower, 'compileop')
            || str_contains($lower, 'compileswitchasjumpifchain')
            || str_contains($lower, 'compileexpr')
            || str_contains($lower, 'getopcodetype')
            || str_contains($lower, 'compileissetmulti')
            || str_contains($lower, 'compileisset')
            || str_contains($lower, 'compilecoalesce')
            || str_contains($lower, 'compilenullsafe')
            || str_contains($lower, 'compileincludeop')
            || str_contains($lower, 'compileparam')
            || str_contains($lower, 'compileterminal')
            || str_contains($lower, 'compilefunccall')
            || str_contains($lower, 'tryfoldvariablefunctionname')
            || str_contains($lower, 'compilecallargsends')
            || str_contains($lower, 'callargunpack')
            || str_contains($lower, 'compilearrayliteral')
            || str_contains($lower, 'compilearraydimfetchread')
            || str_contains($lower, 'compilebooltemporary')
            || str_contains($lower, 'compileboolconstant')
            || str_contains($lower, 'compiletypeconstrainedvariable')
            || str_contains($lower, 'compileclassconstfetch')
            || str_contains($lower, 'compilefirstclasscallable')
            || str_contains($lower, 'compilefirstclassfunctionnameslot')
            || str_contains($lower, 'compilefirstclassstaticnameslot')
            || str_contains($lower, 'compileinstanceof')
            || str_contains($lower, 'trycompiledefineasglobalconst')
            || str_contains($lower, 'markcallerlocalsusedbyliteralinclude')
            || str_contains($lower, 'requireoperandslot')
            || str_contains($lower, 'resolvesimplevariablename')
            || str_contains($lower, 'unwrap')
            || str_contains($lower, 'needscfg')
            || str_contains($lower, 'inheritfuncfromparent')
            || str_contains($lower, 'isarraydim')
            || str_contains($lower, 'findcoalesce')
            || str_contains($lower, 'resolvecoalesce')
            || str_contains($lower, 'resolveisset')
            || str_contains($lower, 'isredundantcoalescetailassign');
    }

    private function isSkippedSelfHostEntryName(string $name): bool
    {
        if (!$this->shouldUseSelfHostJitStubs()) {
            return false;
        }
        $lower = strtolower($name);
        if ($this->isM3CompileDriverRealLoweringName($lower)) {
            return false;
        }
        if ($this->isM3EmitTuRuntimeSpineLoweringName($lower)) {
            return false;
        }
        // M4 inventory argv rebuild: {main} is native emitMainEntry — skip PHP argv driver bodies (#2930).
        if ($this->shouldUseM4InventoryArgvNativeEmitRebuild()) {
            if ('run' === $lower
                || str_ends_with($lower, '\\php_compiler_cli_dispatch')
                || str_ends_with($lower, '\\php_compiler_cli_should_run_entry_driver')
                || str_ends_with($lower, '\\php_compiler_cli_should_skip_entry_driver')
                || str_ends_with($lower, '\\php_compiler_cli_note_progress')
                || str_ends_with($lower, '\\php_compiler_cli_note_invocation_cwd')
                || str_ends_with($lower, '\\php_compiler_cli_minimal_autoload')
            ) {
                return true;
            }
        }
        // Inventory emit-helper bundles compile_driver.php; PHP CFG for argv driver crashes at {main} (#2540).
        if ($this->shouldStubInventoryEmitHelperBundledBodies()) {
            if ($this->isBootstrapHelloWorldSmokeName($lower)
                || str_contains($lower, 'compiler_helloworld_compile_driver')
                || 'compiler_smoke_greeting' === $lower
                || str_ends_with($lower, '\\compiler_smoke_greeting')
            ) {
                return true;
            }
        }
        // M3 compile-smoke wrapper: native bridge in emit TU only (#1983 approach 3, #1937).
        if ($this->shouldUseM3EmitTuNativeBridge() && $this->isBootstrapM3RuntimeEmitBridgeName($lower)) {
            return true;
        }
        // Self-host bundle includes Runtime/VM/Func for closure only; stub non-Compiler bodies (#913).
        if (str_contains($lower, '\\runtime::')
            || str_contains($lower, '\\func\\php::')
            || str_contains($lower, '\\func::')
            || str_contains($lower, '\\frame::')
            || str_contains($lower, '\\block::')
        ) {
            return true;
        }

        return str_ends_with($lower, '\\compiler::compilefunc')
            || str_ends_with($lower, '\\compiler::compile')
            || str_ends_with($lower, '\\jit\\type_pair')
            || str_ends_with($lower, '\\vm\\type_pair')
            || $this->isBootstrapRuntimeCtorSmokeName($lower)
            || ($this->isBootstrapHelloWorldSmokeName($lower) && !$this->shouldUseM3CompileDriverRealLowering())
            || ($this->isBootstrapM3RuntimeEmitBridgeName($lower) && !$this->shouldUseM3CompileDriverRealLowering());
    }

    private function isSkippedWebBootstrapHotPathName(string $name): bool
    {
        if (!$this->shouldUseSelfHostJitStubs()) {
            return false;
        }
        $lower = strtolower($name);
        return (str_contains($lower, '\\web\\includepathresolver::') && !$this->isIncludePathResolverRealLoweringMethod($lower))
            || (str_contains($lower, '\\web\\literalincludediscovery::') && !$this->isLiteralIncludeDiscoveryRealLoweringMethod($lower))
            || (str_contains($lower, '\\web\\deployroot::') && !$this->isDeployRootRealLoweringMethod($lower))
            || (str_contains($lower, '\\web\\sourcebundler::') && !$this->isSourceBundlerRealLoweringMethod($lower))
            || (str_contains($lower, '\\web\\conststringfolder::') && !$this->isConstStringFolderRealLoweringMethod($lower))
            || (str_contains($lower, '\\web\\superglobals::')
                && !$this->isSuperglobalsRealLoweringMethod($lower)
                && !str_ends_with($lower, '::issuperglobalname'));
    }

    /** Stub M2 lib spine smoke units (Doctor, Cli, Web drivers, ext/standard JIT leaves) for self-host AOT (#1056). */
    private function isSkippedLibSpineSmokeHotPathName(string $name): bool
    {
        if (!$this->shouldUseSelfHostJitStubs()) {
            return false;
        }
        $lower = strtolower($name);

        return str_contains($lower, '\\doctor::')
            || str_contains($lower, '\\cli\\')
            || str_contains($lower, '\\web\\cgiaotdriver::')
            || str_contains($lower, '\\web\\cgidriver::')
            || str_contains($lower, '\\web\\projectdeploy::')
            || str_contains($lower, '\\web\\manifestvalidator::')
            || str_contains($lower, '\\web\\projectmanifest::')
            || str_contains($lower, '\\web\\projectautoload::')
            || str_contains($lower, '\\web\\projectbootstrap::')
            || str_contains($lower, '\\web\\responsecontext::')
            || str_contains($lower, '\\web\\devserver::')
            || str_contains($lower, '\\web\\params::')
            || str_contains($lower, '\\aot\\')
            || str_contains($lower, '\\ext\\standard\\')
            || str_contains($lower, '\\ext\\types\\')
            || str_contains($lower, '\\jit\\varfetchhelper::')
            || str_contains($lower, '\\jit\\unsethelper::')
            || str_contains($lower, '\\jit\\arraybuiltinhelper::')
            || str_contains($lower, '\\jit\\reflectionbuiltinhelper::')
            || str_contains($lower, '\\jit\\typecheck::')
            || str_contains($lower, '\\jit\\errorhandlercallbackpolicy::')
            || str_contains($lower, '\\jit\\builtin\\stringparsestr::')
            || str_contains($lower, '\\builtinparamnames::')
            || str_contains($lower, '\\jit\\builtin\\type\\object_::')
            || str_contains($lower, '\\jit\\builtin\\type\\hashtable::')
            || ($this->shouldUseEmitHelperLinkStubs() && str_contains($lower, '\\phptypes\\'));
    }

    /** IncludePathResolver methods with safe LLVM 9 lowering during self-host AOT (#816). */
    private function isIncludePathResolverRealLoweringMethod(string $lower): bool
    {
        if (!$this->shouldUseM3CompileDriverRealLowering()) {
            return false;
        }

        return str_ends_with($lower, '\\web\\includepathresolver::resolve');
    }

    /**
     * LiteralIncludeDiscovery real LLVM lowering during M3 compile-driver link (#816, #2843).
     *
     * Entry points call private CFG walkers and ConstStringFolder::foldForInclude; stubbed callees
     * return empty paths and break include bundling in bin/compile.php bundles.
     */
    private function isLiteralIncludeDiscoveryRealLoweringMethod(string $lower): bool
    {
        if (!$this->shouldUseM3CompileDriverRealLowering()) {
            return false;
        }
        if ($this->shouldStubInventoryEmitHelperBundledBodies()) {
            return false;
        }
        foreach ([
            'discoverdirectabsolutepaths',
            'discoverabsolutepaths',
            'pathsfrommainscopeforbundle',
            'pathsfromscript',
            'walkcfgblock',
            'walkcfgblockforbundle',
            'walkcfgblockinternal',
            'isbundlescopeboundary',
        ] as $suffix) {
            if (str_ends_with($lower, '\\web\\literalincludediscovery::'.$suffix)) {
                return true;
            }
        }

        return false;
    }

    /** DeployRoot helpers needed by bin/compile.php include bundling (#1521). */
    private function isDeployRootRealLoweringMethod(string $lower): bool
    {
        if (!$this->shouldUseM3CompileDriverRealLowering()) {
            return false;
        }

        return str_ends_with($lower, '\\web\\deployroot::findprojectrootforpath')
            || str_ends_with($lower, '\\web\\deployroot::relativedirfromproject');
    }

    /** SourceBundler entry used when literal includes are folded into one TU (#1521). */
    private function isSourceBundlerRealLoweringMethod(string $lower): bool
    {
        if (!$this->shouldUseM3CompileDriverRealLowering()) {
            return false;
        }

        return str_ends_with($lower, '\\web\\sourcebundler::bundleforaot');
    }

    /** @var list<string> */
    private const WEB_BOOTSTRAP_STUBBED_SUPERGLOBALS_SUFFIXES = [
        'populatefromenvironment',
        'populatecliargv',
    ];

    private function isSuperglobalsStubbedMethod(string $lower): bool
    {
        foreach (self::WEB_BOOTSTRAP_STUBBED_SUPERGLOBALS_SUFFIXES as $suffix) {
            if (str_ends_with($lower, '\\web\\superglobals::'.$suffix)) {
                return true;
            }
        }

        return false;
    }

    private function isSuperglobalsRealLoweringMethod(string $lower): bool
    {
        if ($this->isSuperglobalsStubbedMethod($lower)) {
            return false;
        }
        if (str_ends_with($lower, '\\web\\superglobals::readrequestbody')
            || str_ends_with($lower, '\\web\\superglobals::exportcgienvironment')) {
            return true;
        }

        return $this->shouldUseM3CompileDriverRealLowering()
            && str_contains($lower, '\\web\\superglobals::');
    }

    private function isSuperglobalsM3CompileDriverLoweringMethod(string $lower): bool
    {
        return $this->isSuperglobalsRealLoweringMethod($lower);
    }

    /**
     * ConstStringFolder real LLVM lowering during M3 compile-driver link (#816, #2827).
     *
     * Entry points plus private helpers they call must be real-lowered together; stubbed callees
     * return null and break __DIR__/__FILE__ include-path folding in bin/compile.php bundles.
     */
    private function isConstStringFolderRealLoweringMethod(string $lower): bool
    {
        if (!$this->shouldUseM3CompileDriverRealLowering()) {
            return false;
        }
        foreach ([
            'fold',
            'foldconcat',
            'foldforinclude',
            'tryparsedeployinclude',
            'literalstringvalue',
            'magicscriptconstvalue',
            'sourcedir',
            'findmagicscriptconstforoperand',
            'findmagicscriptconstinblocktree',
            'findconcatforoperand',
            'findconcatinblocktree',
            'folddeploypathconcat',
        ] as $suffix) {
            if (str_ends_with($lower, '\\web\\conststringfolder::'.$suffix)) {
                return true;
            }
        }

        return false;
    }

    private function collectStubFunctionArgTypes(Block $block): array
    {
        $args = [];
        if (null === $block->func) {
            return $args;
        }
        if ($this->instanceMethodUsesThis($block)) {
            $args[] = $this->context->getTypeFromString('__object__*');
        }
        foreach ($block->func->params as $idx => $param) {
            $args[] = $this->llvmTypeForCfgParam($param, $block, $idx);
        }
        return $args;
    }

    /**
     * Self-host: CFG Operand params must use __object__* at call sites (#1056).
     *
     * @param list<PHPLLVM\Type> $args
     *
     * @return list<PHPLLVM\Type>
     */
    private function normalizeSelfHostNativeCallArgTypes(array $args, string $logicalName): array
    {
        if (!$this->shouldUseSelfHostJitStubs()) {
            return $args;
        }
        $lower = strtolower($logicalName);
        if (
            !str_contains($lower, 'operandschainequal')
            && !str_contains($lower, 'unwrapoperandchain')
            && !str_contains($lower, 'operandhasobjecttype')
        ) {
            return $args;
        }
        $objectPtr = $this->context->getTypeFromString('__object__*');
        foreach ($args as $i => $argType) {
            if ('__value__*' === $this->context->getStringFromType($argType)) {
                $args[$i] = $objectPtr;
            }
        }

        return $args;
    }

    /**
     * CFG/compiler Operand handles use native object pointers, not nullable __value__* (#1056).
     */
    private function isCfgObjectIdentityParamType(Type $type): bool
    {
        if (Type::TYPE_OBJECT !== $type->type) {
            return false;
        }
        $name = strtolower($type->classname ?? '');

        return str_contains($name, 'operand') || str_contains($name, '\\op\\');
    }

    /** VM Variable handles use boxed __value__* ABI in nested php-in-PHP JIT helpers (#16565). */
    private function isCfgVmVariableParamType(?Type $type): bool
    {
        if (null === $type || Type::TYPE_OBJECT !== $type->type) {
            return false;
        }
        $name = strtolower(ltrim($type->userType ?? '', '\\'));

        return 'phpcompiler\\vm\\variable' === $name
            || str_ends_with($name, '\\vm\\variable')
            || 'variable' === $name;
    }

    /** VM HashTable handles use __hashtable__* ABI in nested php-in-PHP JIT helpers (#21109). */
    private function isCfgVmHashTableParamType(?Type $type): bool
    {
        if (null === $type || Type::TYPE_OBJECT !== $type->type) {
            return false;
        }
        $name = strtolower(ltrim($type->userType ?? '', '\\'));

        return 'phpcompiler\\vm\\hashtable' === $name
            || str_ends_with($name, '\\vm\\hashtable')
            || 'hashtable' === $name;
    }

    private function isCfgOperandDeclaredName(string $name): bool
    {
        $lc = strtolower(ltrim($name, '\\'));

        return 'operand' === $lc
            || str_ends_with($lc, '\\operand')
            || 'temporary' === $lc
            || str_ends_with($lc, '\\temporary');
    }

    private function declaredTypeFromCfgParam(\PHPCfg\Op\Expr\Param $param): ?Type
    {
        if ($param->declaredType instanceof Op\Type\Literal) {
            if ($this->isCfgOperandDeclaredName($param->declaredType->name)) {
                return Type::object('PHPCfg\\Operand');
            }

            return Type::fromDecl($param->declaredType->name);
        }
        if ($param->declaredType instanceof Op\Type\Reference && null !== $param->declaredType->declaration) {
            $inner = $param->declaredType->declaration;
            if ($inner instanceof \PHPCfg\Operand\Literal) {
                return Type::fromDecl($inner->value);
            }
            if ($inner instanceof Op\Type\Literal) {
                if ($this->isCfgOperandDeclaredName($inner->name)) {
                    return Type::object('PHPCfg\\Operand');
                }

                return Type::fromDecl($inner->name);
            }
            try {
                return Type::fromTypeDecl($inner);
            } catch (\LogicException) {
                return null;
            }
        }
        if (null !== $param->declaredType) {
            try {
                return Type::fromTypeDecl($param->declaredType);
            } catch (\LogicException) {
                return null;
            }
        }

        return null;
    }

    /**
     * User class-typed object formals use boxed {@see __value__*} at the LLVM ABI (#24429).
     * Compiler/runtime methods keep native {@see __object__*} (DOM init, spine helpers).
     */
    private function cfgParamUsesBoxedUserObjectFormal(?Block $block, Type $rawType): bool
    {
        if (Type::TYPE_OBJECT !== $rawType->type) {
            return false;
        }
        if ($this->isCfgObjectIdentityParamType($rawType) || $this->isCfgVmVariableParamType($rawType)) {
            return false;
        }
        if ($this->cfgEnclosingFuncIsCompilerInternal($block)) {
            return false;
        }
        $userType = strtolower(ltrim((string) ($rawType->userType ?? ''), '\\'));

        return '' !== $userType && !\in_array($userType, ['object', 'mixed', 'stdclass'], true);
    }

    private function cfgEnclosingFuncIsCompilerInternal(?Block $block): bool
    {
        if (null === $block || null === $block->func || null === $block->func->class) {
            return false;
        }
        $class = strtolower(ltrim((string) $block->func->class->value, '\\'));

        return str_starts_with($class, 'phpcompiler\\');
    }

    private function llvmTypeForCfgParam(
        \PHPCfg\Op\Expr\Param $param,
        ?Block $block = null,
        ?int $paramIdx = null
    ): PHPLLVM\Type {
        if (
            null !== $block
            && null !== $paramIdx
            && $this->cfgParamIsImplicitNullable($block, $paramIdx)
        ) {
            return $this->context->getTypeFromString('__value__*');
        }
        // Variadic formals are always a packed HT — including `&...$args`, where by-ref
        // applies to *elements*, not the array slot (Zend zend_compile / #27407). Checking
        // byRef first made AOT declare `__value__*` while the Variable stayed TYPE_HASHTABLE,
        // so `__value__writeHashtable` saw a value box where a hashtable pointer was required.
        if ($param->variadic) {
            return $this->context->getTypeFromString('__hashtable__*');
        }
        if ($param->byRef) {
            return $this->context->getTypeFromString('__value__*');
        }
        if ($this->cfgParamDeclaredTypeUsesDnfShape($param)) {
            return $this->context->getTypeFromString('__value__*');
        }
        if ($param->declaredType instanceof Op\Type\Literal
            && 'mixed' === strtolower($param->declaredType->name)
        ) {
            return $this->context->getTypeFromString('__value__*');
        }
        if ($param->declaredType instanceof Op\Type\Literal
            && $this->isCfgOperandDeclaredName($param->declaredType->name)
        ) {
            return $this->context->getTypeFromString('__object__*');
        }
        $declared = $this->declaredTypeFromCfgParam($param);
        if (
            JIT\NestedJitCompileScope::isActive()
            && $this->isCfgVmVariableParamType($declared)
        ) {
            return $this->context->getTypeFromString('__value__*');
        }
        if (
            JIT\NestedJitCompileScope::isActive()
            && $this->isCfgVmHashTableParamType($declared)
        ) {
            return $this->context->getTypeFromString('__hashtable__*');
        }
        if (null !== $declared && $this->isCfgObjectIdentityParamType($declared)) {
            return $this->context->getTypeFromString('__object__*');
        }
        $rawType = $this->rawTypeFromCfgParam($param);
        if (
            JIT\NestedJitCompileScope::isActive()
            && $this->isCfgVmVariableParamType($rawType)
        ) {
            return $this->context->getTypeFromString('__value__*');
        }
        if (
            JIT\NestedJitCompileScope::isActive()
            && $this->isCfgVmHashTableParamType($rawType)
        ) {
            return $this->context->getTypeFromString('__hashtable__*');
        }
        if ($this->isCfgObjectIdentityParamType($rawType)) {
            return $this->context->getTypeFromString('__object__*');
        }
        if ($this->cfgParamUsesBoxedUserObjectFormal($block, $rawType)) {
            return $this->context->getTypeFromString('__value__*');
        }
        $callback = $this->callbackTypeFromPhptype($rawType);
        if (null !== $callback) {
            return $this->context->getTypeFromString($callback);
        }

        return $this->context->getTypeFromType($rawType);
    }

    /** Stub VM hot-path methods whose opcode switches crash LLVM 9 during self-host AOT (#816). */
    private function compileSkippedVmHotPathStub(string $internalName, Block $block, string $logicalName): PHPLLVM\Value
    {
        $lcname = strtolower($logicalName);
        if (isset($this->context->functions[$lcname])) {
            return $this->context->functions[$lcname];
        }
        $args = $this->collectStubFunctionArgTypes($block);
        $callbackType = $this->cfgFunctionReturnCallbackType($block->func) ?? 'void';
        $returnType = $this->context->getTypeFromString($callbackType);
        $func = $this->context->module->addFunction(
            $this->llvmInternalName($internalName),
            $this->context->context->functionType($returnType, false, ...$args)
        );
        $bb = $func->appendBasicBlock('stub');
        $saved = $this->context->builder;
        $this->context->builder = $this->context->context->builderCreate();
        $this->context->builder->positionAtEnd($bb);
        $this->emitSelfHostStubReturn($callbackType, $func, VM::SUCCESS);
        $this->context->builder->clearInsertionPosition();
        $this->context->builder = $saved;
        $this->context->functions[$lcname] = $func;
        $this->context->functionReturnType[$lcname] = $callbackType;
        $this->context->functionProxies[$lcname] = new JIT\Call\Native(
            $func,
            $logicalName,
            $args,
            $this->collectParamDefaults($block)
        );

        return $func;
    }

    /** Stub Compiler::compileCfgBranch() for LLVM 9 self-host AOT (#816). */
    private function compileSkippedCompilerCfgBranchStub(string $internalName, Block $block, string $logicalName): PHPLLVM\Value
    {
        $lcname = strtolower($logicalName);
        if (isset($this->context->functions[$lcname])) {
            return $this->context->functions[$lcname];
        }
        $objectPtr = $this->context->getTypeFromString('__object__*');
        $func = $this->context->module->addFunction(
            $this->llvmInternalName($internalName),
            $this->context->context->functionType($objectPtr, false, $objectPtr, $objectPtr, $objectPtr)
        );
        $bb = $func->appendBasicBlock('stub');
        $saved = $this->context->builder;
        $this->context->builder = $this->context->context->builderCreate();
        $this->context->builder->positionAtEnd($bb);
        $this->context->builder->returnValue($objectPtr->constNull());
        $this->context->builder->clearInsertionPosition();
        $this->context->builder = $saved;
        $this->context->functions[$lcname] = $func;
        $this->context->functionReturnType[$lcname] = '__object__*';
        $this->context->functionProxies[$lcname] = new JIT\Call\Native(
            $func,
            $logicalName,
            [$objectPtr, $objectPtr, $objectPtr],
            []
        );

        return $func;
    }

    /** Thin native LLVM bridge for compile_smoke_m3_emit when emit-helper link is active (#1983). */
    private function compileBootstrapCompileSmokeM3EmitNative(
        string $internalName,
        Block $block,
        string $logicalName
    ): PHPLLVM\Value {
        $this->compileM3EmitTuRuntimeSpineDecls();
        $lcname = strtolower($logicalName);
        if (isset($this->context->functions[$lcname])) {
            return $this->context->functions[$lcname];
        }
        $strPtr = $this->context->getTypeFromString('__string__*');
        $i64 = $this->context->getTypeFromString('int64');
        $func = $this->context->module->addFunction(
            $this->llvmInternalName($internalName),
            $this->context->context->functionType($i64, false, $strPtr, $strPtr)
        );
        $bb = $func->appendBasicBlock('entry');
        $saved = $this->context->builder;
        $this->context->builder = $this->context->context->builderCreate();
        $this->context->builder->positionAtEnd($bb);
        $logPrefix = Config::getenv('PHP_COMPILER_M3_EMIT_LOG_PREFIX');
        if (!is_string($logPrefix) || '' === $logPrefix) {
            $logPrefix = str_contains($lcname, 'runtime_compile_smoke_m3_emit')
                ? 'runtime_compile_smoke_m3_emit'
                : 'compile_smoke_m3_emit';
        }
        \PHPCompiler\JIT\BootstrapCompileSmokeM3Emit::emit(
            $this->context,
            $func->getParam(0),
            $func->getParam(1),
            $logPrefix
        );
        $this->context->builder->clearInsertionPosition();
        $this->context->builder = $saved;
        $this->context->functions[$lcname] = $func;
        $this->context->functionReturnType[$lcname] = 'int64';
        $this->context->functionProxies[$lcname] = new JIT\Call\Native(
            $func,
            $logicalName,
            [$strPtr, $strPtr],
            $this->collectParamDefaults($block)
        );

        return $func;
    }

    /** Native {main} for M3 emit TU — libc getenv + emit bridge after spine pre-lower (#1937, #2550). */
    private function compileM3EmitTuMainNative(string $internalName, Block $block, ?string $logicalName): PHPLLVM\Value
    {
        $lcname = strtolower($logicalName ?? '{main}');
        if (isset($this->context->functions[$lcname])) {
            return $this->context->functions[$lcname];
        }
        if (!$this->m3EmitTuRuntimeSpineLowered) {
            $this->m3EmitTuRuntimeSpineLowered = true;
            $stubBlock = $this->m3EmitTuMainBlock ?? $this->m3CompileDriverMainBlock ?? $block;
            $this->compileM3EmitTuRuntimeSpineDecls($stubBlock);
            $sidecar = $this->isM3EmitTuTrivialEchoSidecarActive();
            $inventoryEmit = $this->shouldUseM3InventoryEmitForCompileDriverBlock($block);
            foreach (['parse', 'compileemitsmoke', 'standalone'] as $methodLc) {
                if ('standalone' === $methodLc && ($sidecar || $inventoryEmit)) {
                    continue;
                }
                $this->compileM3EmitTuRuntimeMethodFromQueue($methodLc);
            }
            $this->runQueue();
        }
        $i64 = $this->context->getTypeFromString('int64');
        $func = $this->context->module->addFunction(
            $this->llvmInternalName($internalName),
            $this->context->context->functionType($i64, false)
        );
        $bb = $func->appendBasicBlock('entry');
        $saved = $this->context->builder;
        $this->context->builder = $this->context->context->builderCreate();
        $this->context->builder->positionAtEnd($bb);
        JIT\Builtin\CliArgvRuntime::ensureLinked($this->context);
        $diagStubBlock = $this->m3EmitTuMainBlock ?? $this->m3CompileDriverMainBlock ?? $block;
        $constructLogical = 'PHPCompiler\\Runtime::__construct';
        $constructLc = strtolower($constructLogical);
        if (!isset($this->context->functions[$constructLc])) {
            $this->emitM3EmitTuRuntimeConstructNativeFunction(
                $this->llvmInternalName($constructLogical),
                $constructLogical,
                $diagStubBlock
            );
        }
        if ($this->shouldRealLowerInventoryArgvParseSpine()) {
            $peekLogical = 'PHPCompiler\\Runtime::peeklastparsefailure';
            $peekLc = strtolower($peekLogical);
            unset(
                $this->context->functions[$peekLc],
                $this->context->functionReturnType[$peekLc],
                $this->context->functionProxies[$peekLc]
            );
            $this->emitM3EmitTuCompilerNullStringGetterStub(
                $this->llvmInternalName($peekLogical),
                $peekLogical,
                $diagStubBlock
            );
        }
        $logPrefix = Config::getenv('PHP_COMPILER_M3_EMIT_LOG_PREFIX');
        if (!is_string($logPrefix) || '' === $logPrefix) {
            $logPrefix = 'compile_smoke_m3_emit';
        }
        \PHPCompiler\JIT\BootstrapCompileSmokeM3Emit::emitMainEntry($this->context, $logPrefix);
        $this->context->builder->clearInsertionPosition();
        $this->context->builder = $saved;
        $this->context->functions[$lcname] = $func;
        $this->context->functionReturnType[$lcname] = 'int64';
        $this->context->functionProxies[$lcname] = new JIT\Call\Native($func, $logicalName ?? '{main}', [], []);

        return $func;
    }

    /** Native {main} for M3 compile_driver bundles — avoids LLVM 9 crash lowering Runtime ctor in PHP CFG (#1768). */
    private function compileM3CompileDriverMainNative(string $internalName, Block $block, ?string $logicalName): PHPLLVM\Value
    {
        $lcname = strtolower($logicalName ?? $internalName);
        if ($this->isM4BinCompileScriptMain($block)
            && ($this->shouldUseM4BinCompileArgvMainNative() || $this->shouldUseHelloworldBinCompileInventoryArgvLink())
        ) {
            unset(
                $this->context->functions[$lcname],
                $this->context->functionReturnType[$lcname],
                $this->context->functionProxies[$lcname]
            );
        }
        if (isset($this->context->functions[$lcname])) {
            return $this->context->functions[$lcname];
        }
        if ($this->isM4BinCompileScriptMain($block)
            && ($this->shouldUseM4BinCompileArgvMainNative() || $this->shouldUseHelloworldBinCompileInventoryArgvLink())
        ) {
            $internalName = 'm4_inventory_argv_main';
        }
        $i64 = $this->context->getTypeFromString('int64');
        $func = $this->context->module->addFunction(
            $this->llvmInternalName($internalName),
            $this->context->context->functionType($i64, false)
        );
        $bb = $func->appendBasicBlock('entry');
        $saved = $this->context->builder;
        $m4BinCompileArgv = $this->isM4BinCompileScriptMain($block) && $this->shouldUseM4BinCompileArgvMainNative();
        $m4NativeRebuild = $m4BinCompileArgv && $this->shouldUseM4InventoryArgvNativeEmitRebuild($block);
        $logPrefix = Config::getenv('PHP_COMPILER_M3_EMIT_LOG_PREFIX');
        if (!is_string($logPrefix) || '' === $logPrefix) {
            $logPrefix = 'helloworld_compile_smoke';
        }
        if ($m4NativeRebuild) {
            $this->context->builder = $this->context->context->builderCreate();
            $this->context->builder->positionAtEnd($bb);
            \PHPCompiler\JIT\BootstrapCompileSmokeM3Emit::emitMainEntry($this->context, $logPrefix);
            $this->context->builder->clearInsertionPosition();
            $this->context->builder = $saved;
            $this->context->functions[$lcname] = $func;
            $this->context->functionReturnType[$lcname] = 'int64';
            $this->m3CompileDriverRuntimeSpineLowered = true;
            $this->filterM4InventoryArgvMainFromQueue();
            $this->ensureM3EmitTuEmitBridgeSpineSymbols();
            $this->compileM3EmitTuRuntimeParseAndCompileNativeDecl([
                'parseandcompile' => true,
                'parseandcompileemitsmoke' => true,
            ]);
            $this->context->functionProxies[$lcname] = new JIT\Call\Native($func, $logicalName ?? '{main}', [], []);

            return $func;
        }
        if ($this->shouldUseM3InventoryEmitForCompileDriverBlock($block) || $m4BinCompileArgv) {
            $this->context->functions[$lcname] = $func;
            $this->context->functionReturnType[$lcname] = 'int64';
            if (!$this->m3CompileDriverRuntimeSpineLowered) {
                $this->m3CompileDriverRuntimeSpineLowered = true;
                $this->context->builder->clearInsertionPosition();
                $this->filterM4InventoryArgvMainFromQueue();
                $this->compileM3EmitTuRuntimeSpineDecls($this->m3CompileDriverMainBlock);
                $sidecar = $this->isM3EmitTuTrivialEchoSidecarActive();
                $inventoryEmit = $this->shouldUseM3InventoryEmitForCompileDriverBlock($block);
                $inventoryArgvParseHelper = $this->shouldEnsureInventoryArgvParseHelperStubs()
                    && !$this->shouldRealLowerInventoryArgvParseSpine();
                if (!$inventoryArgvParseHelper) {
                    foreach (['parse', 'compileemitsmoke', 'standalone'] as $methodLc) {
                        if ('standalone' === $methodLc && ($sidecar || $inventoryEmit)) {
                            continue;
                        }
                        $this->compileM3EmitTuRuntimeMethodFromQueue($methodLc);
                    }
                    if (!$m4BinCompileArgv) {
                        $this->runQueue();
                    }
                }
            }
            if (null !== $this->m3CompileDriverMainBlock) {
                $standaloneLc = strtolower('PHPCompiler\\Runtime::standalone');
                unset(
                    $this->context->functions[$standaloneLc],
                    $this->context->functionReturnType[$standaloneLc],
                    $this->context->functionProxies[$standaloneLc]
                );
                if (!$this->shouldUseM4InventoryArgvNativeEmitRebuild($this->m3CompileDriverMainBlock)
                    || \PHPCompiler\JIT\M3EmitTuTrivialEchoAot::isRegistered($this->context)) {
                    $this->emitM3EmitTuRuntimeStandaloneStubNative(
                        $this->llvmInternalName('PHPCompiler\\Runtime::standalone'),
                        'PHPCompiler\\Runtime::standalone',
                        $this->m3CompileDriverMainBlock
                    );
                }
            }
            $this->context->builder = $this->context->context->builderCreate();
            $this->context->builder->positionAtEnd($bb);
            JIT\Builtin\CliArgvRuntime::ensureLinked($this->context);
            $diagStubBlock = $this->m3CompileDriverMainBlock ?? $this->m3EmitTuMainBlock;
            if (null !== $diagStubBlock) {
                $constructLogical = 'PHPCompiler\\Runtime::__construct';
                $constructLc = strtolower($constructLogical);
                if (!isset($this->context->functions[$constructLc])) {
                    $this->emitM3EmitTuRuntimeConstructNativeFunction(
                        $this->llvmInternalName($constructLogical),
                        $constructLogical,
                        $diagStubBlock
                    );
                }
                if ($this->shouldRealLowerInventoryArgvParseSpine()) {
                    $peekLogical = 'PHPCompiler\\Runtime::peeklastparsefailure';
                    $peekLc = strtolower($peekLogical);
                    unset(
                        $this->context->functions[$peekLc],
                        $this->context->functionReturnType[$peekLc],
                        $this->context->functionProxies[$peekLc]
                    );
                    $this->emitM3EmitTuCompilerNullStringGetterStub(
                        $this->llvmInternalName($peekLogical),
                        $peekLogical,
                        $diagStubBlock
                    );
                }
            }
            \PHPCompiler\JIT\BootstrapCompileSmokeM3Emit::emitMainEntry($this->context, $logPrefix);
        } else {
            $this->context->builder = $this->context->context->builderCreate();
            $this->context->builder->positionAtEnd($bb);
            \PHPCompiler\JIT\ValueEchoHelper::echoLiteral($this->context, "compiler_helloworld_compile_driver ready\n");
            $this->context->builder->returnValue($i64->constInt(0, false));
        }

        $this->context->builder->clearInsertionPosition();
        $this->context->builder = $saved;
        if (!isset($this->context->functions[$lcname])) {
            $this->context->functions[$lcname] = $func;
            $this->context->functionReturnType[$lcname] = 'int64';
        }
        $this->context->functionProxies[$lcname] = new JIT\Call\Native($func, $logicalName ?? '{main}', [], []);

        return $func;
    }

    /** Lower Runtime/Compiler spine before native emit bridge (#1937, #2512). */
    private function compileM3EmitTuRuntimeSpineDecls(?Block $compileDriverStubBlock = null): void
    {
        $emitTu = $this->shouldUseM3EmitTuNativeBridge() && null !== $this->m3EmitTuMainBlock;
        $inventoryArgvCompileDriver = null !== $compileDriverStubBlock
            && $this->shouldUseM3InventoryEmitForCompileDriverBlock($compileDriverStubBlock);
        $compileDriver = null !== $compileDriverStubBlock
            && ($this->shouldUseM3CompileDriverMainNative() || $inventoryArgvCompileDriver);
        if (!$emitTu && !$compileDriver) {
            return;
        }
        $stubBlock = $emitTu ? $this->m3EmitTuMainBlock : $compileDriverStubBlock;
        if ($this->shouldUseM3CompileDriverRealLowering() || $inventoryArgvCompileDriver) {
            $inventoryArgvParseHelper = $this->shouldEnsureInventoryArgvParseHelperStubs()
                && !$this->shouldRealLowerInventoryArgvParseSpine();
            if ($inventoryArgvParseHelper) {
                $this->ensureM3EmitTuCompilerRuntimeCompileDeps();
                $this->ensureM3EmitTuRuntimeParseSpineDeps();
                if (null !== $stubBlock) {
                    $this->ensureM3EmitTuRuntimeInitSpineSymbols($stubBlock);
                    $this->ensureM3EmitTuEmitBridgeSpineSymbols();
                    $this->emitM3EmitTuRuntimeConstructNativeFunction(
                        $this->llvmInternalName('PHPCompiler\\Runtime::__construct'),
                        'PHPCompiler\\Runtime::__construct',
                        $stubBlock
                    );
                }
                $this->compileM3EmitTuRuntimeParseAndCompileNativeDecl([
                    'parseandcompile' => true,
                    'parseandcompileemitsmoke' => true,
                ]);

                return;
            }
            $sidecar = $emitTu && $this->isM3EmitTuTrivialEchoSidecarActive();
            $this->compileM3EmitTuRuntimeSpineMethodsForRealLowering();
            foreach (['initparsepipeline', 'initcompiler', 'initvmcontext', 'loadcoremodules', 'parseandcompileemitsmoke', 'standalone'] as $methodLc) {
                if ('standalone' === $methodLc && ($sidecar || $this->shouldUseM3InventoryEmitForCompileDriverBlock($stubBlock))) {
                    if (null !== $stubBlock) {
                        if ($this->shouldUseM3InventoryEmitForCompileDriverBlock($stubBlock)) {
                            $standaloneLc = strtolower('PHPCompiler\\Runtime::standalone');
                            unset(
                                $this->context->functions[$standaloneLc],
                                $this->context->functionReturnType[$standaloneLc],
                                $this->context->functionProxies[$standaloneLc]
                            );
                        }
                        $this->emitM3EmitTuRuntimeStandaloneStubNative(
                            $this->llvmInternalName('PHPCompiler\\Runtime::standalone'),
                            'PHPCompiler\\Runtime::standalone',
                            $stubBlock
                        );
                    }
                    continue;
                }
                $this->compileM3EmitTuRuntimeMethodFromQueue($methodLc);
            }
            if ($emitTu) {
                $this->compileM3EmitTuCompilerSpineMethodsFromMainBlock(['compileemitsmoke']);
            } else {
                $this->compileM3EmitTuCompilerMethodFromRuntimeModules('compileemitsmoke');
            }
            if (!$this->shouldUseM4InventoryArgvNativeEmitRebuild($compileDriverStubBlock)) {
                $this->runQueue();
            }
            if (null !== $stubBlock && ($emitTu || $compileDriver)) {
                $this->ensureM3EmitTuRuntimeInitSpineSymbols($stubBlock);
                $this->ensureM3EmitTuEmitBridgeSpineSymbols();
                if ($compileDriver) {
                    $this->emitM3EmitTuRuntimeConstructNativeFunction(
                        $this->llvmInternalName('PHPCompiler\\Runtime::__construct'),
                        'PHPCompiler\\Runtime::__construct',
                        $stubBlock
                    );
                }
            }
            $this->compileM3EmitTuRuntimeParseAndCompileNativeDecl([
                'parseandcompile' => true,
                'parseandcompileemitsmoke' => true,
            ]);

            return;
        }
        if (!$emitTu) {
            return;
        }
        $this->compileM3EmitTuRuntimeSpineMethodsForRealLowering();
        if ($this->shouldUseM3EmitTuEmitHelperSpineRealLowering()) {
            foreach (['initparsepipeline', 'initcompiler', 'loadcoremodules'] as $methodLc) {
                // M5 argv uses C-floor initParsePipeline — do not pre-register void ret (#26756).
                if ('initparsepipeline' === $methodLc && $this->shouldUseM5DriverHostCompile()) {
                    continue;
                }
                $logical = 'PHPCompiler\\Runtime::'.$methodLc;
                $this->emitM3EmitTuRuntimeInitVoidStub(
                    $this->llvmInternalName($logical),
                    $logical,
                    $stubBlock
                );
            }
        } elseif (null !== $stubBlock) {
            $this->emitM3EmitTuRuntimeParseStubNative(
                $this->llvmInternalName('PHPCompiler\\Runtime::parse'),
                'PHPCompiler\\Runtime::parse',
                $stubBlock
            );
            $this->emitM3EmitTuRuntimeCompileEmitSmokeNative(
                $this->llvmInternalName('PHPCompiler\\Runtime::compileEmitSmoke'),
                'PHPCompiler\\Runtime::compileEmitSmoke',
                $stubBlock
            );
        }
        $this->emitM3EmitTuRuntimeConstructNativeFunction(
            $this->llvmInternalName('PHPCompiler\\Runtime::__construct'),
            'PHPCompiler\\Runtime::__construct',
            $stubBlock
        );
        $this->compileM3EmitTuRuntimeParseAndCompileNativeDecl([
            'parseandcompile' => true,
            'parseandcompileemitsmoke' => true,
        ]);
        $this->emitM3EmitTuRuntimeBlockPtrStubNative(
            $this->llvmInternalName('PHPCompiler\\Runtime::compile'),
            'PHPCompiler\\Runtime::compile',
            $stubBlock
        );
        $this->emitM3EmitTuRuntimeStandaloneStubNative(
            $this->llvmInternalName('PHPCompiler\\Runtime::standalone'),
            'PHPCompiler\\Runtime::standalone',
            $stubBlock
        );
        $this->compileM3EmitTuCompilerEmitSmokeNativeDecl();
        $this->runQueue();
    }

    /**
     * After spine pre-lower, register emit-bridge decls that call real Runtime::parse (#2512, #2550).
     */
    private function finalizeM3EmitTuRuntimeSpineAfterQueue(): void
    {
        if (!$this->shouldUseM3CompileDriverRealLowering()) {
            return;
        }
        if ($this->shouldUseM3EmitTuNativeBridge() && null !== $this->m3EmitTuMainBlock) {
            $this->compileM3EmitTuRuntimeParseAndCompileNativeDecl([
                'parseandcompile' => true,
                'parseandcompileemitsmoke' => true,
            ]);
            $this->compileM3EmitTuCompilerEmitSmokeNativeDecl();

            return;
        }
        if (
            null !== $this->m3CompileDriverMainBlock
            && (
                $this->shouldUseM3InventoryEmitForCompileDriverBlock($this->m3CompileDriverMainBlock)
                || $this->shouldUseM4InventoryArgvNativeEmitRebuild($this->m3CompileDriverMainBlock)
            )
        ) {
            $this->compileM3EmitTuRuntimeParseAndCompileNativeDecl([
                'parseandcompile' => true,
                'parseandcompileemitsmoke' => true,
            ]);
            $this->compileM3EmitTuCompilerEmitSmokeNativeDecl();
        }
    }

    /**
     * Lower Runtime::parseAndCompile* from lib/Runtime.php for emit/inventory drivers (#2516, #2967).
     *
     * Do not register the native emit-bridge wrapper: it calls back into the same symbol and segfaults.
     *
     * @param array<string, true> $methods lowercase method names
     */
    private function compileM3EmitTuRuntimeParseAndCompileNativeDecl(array $methods): void
    {
        if ([] === $methods) {
            return;
        }
        if (
            !$this->shouldUseM3EmitTuNativeBridge()
            && !$this->shouldUseM3InventoryEmitDriver()
            && !$this->shouldUseM4BinCompileArgvMainNative()
            && !$this->shouldEnsureInventoryArgvParseHelperStubs()
        ) {
            return;
        }
        $this->ensureM3EmitTuEmitBridgeSpineSymbols();
        $savedClassId = $this->context->scope->classId;
        $savedClassName = $this->context->scope->className;
        $this->context->scope->classId = $this->context->type->object->lookup('PHPCompiler\\Runtime');
        $this->context->scope->className = 'phpcompiler\\runtime';
        $forceRealParseSpine = $this->shouldRealLowerInventoryArgvParseSpine();
        $inventoryArgvParseHelper = $this->shouldEnsureInventoryArgvParseHelperStubs()
            && !$forceRealParseSpine;
        $stubBlock = $this->m3EmitTuMainBlock ?? $this->m3CompileDriverMainBlock;
        if ($forceRealParseSpine) {
            // Inventory argv Zend rebuild keeps preprocess CFG stubs; only parse/emit spine is real (#11809).
            $forceRealUnset = $this->shouldUseM3InventoryEmitDriver()
                ? ['parse', 'compileemitsmoke']
                : ['preprocesssourceforparse', 'rewritesourcebeforeparser', 'preparesourceforparser', 'parse', 'compileemitsmoke'];
            foreach ($forceRealUnset as $spineLc) {
                $spineLcKey = strtolower('PHPCompiler\\Runtime::'.$spineLc);
                unset(
                    $this->context->functions[$spineLcKey],
                    $this->context->functionReturnType[$spineLcKey],
                    $this->context->functionProxies[$spineLcKey]
                );
            }
        }
        if (!$this->shouldUseM4InventoryArgvNativeEmitRebuild() && !$inventoryArgvParseHelper) {
            $spineCompileList = $this->shouldUseM3InventoryEmitDriver()
                ? ['parse', 'compileemitsmoke']
                : [
                    'preprocesssourceforparse',
                    'rewritesourcebeforeparser',
                    'preparesourceforparser',
                    'parse',
                    'compileemitsmoke',
                ];
            foreach ($spineCompileList as $spineLc) {
                $spineLcKey = strtolower('PHPCompiler\\Runtime::'.$spineLc);
                if (isset($this->context->functions[$spineLcKey])) {
                    continue;
                }
                $this->compileM3EmitTuRuntimeMethodFromQueue($spineLc);
                if (!isset($this->context->functions[$spineLcKey])) {
                    $this->compileM3EmitTuRuntimeMethodFromModules($spineLc);
                }
            }
        }
        if ($inventoryArgvParseHelper) {
            $this->ensureM3EmitTuRuntimeParseAndCompileDeclBeforeQueue($methods, $stubBlock);
        }
        if (!$this->shouldUseM4InventoryArgvNativeEmitRebuild() && !$inventoryArgvParseHelper) {
            $this->runQueue();
        }
        if (!$inventoryArgvParseHelper) {
            $this->ensureM3EmitTuRuntimeParseAndCompileDeclBeforeQueue($methods, $stubBlock);
        }
        $this->context->scope->classId = $savedClassId;
        $this->context->scope->className = $savedClassName;
    }

    /**
     * Register parse/compileEmitSmoke stubs and parseAndCompile* decls for inventory argv (#12036).
     *
     * @param array<string, true> $methods
     */
    private function ensureM3EmitTuRuntimeParseAndCompileDeclBeforeQueue(array $methods, ?Block $stubBlock): void
    {
        foreach (['parse', 'compileemitsmoke'] as $spineLc) {
            $spineLogical = 'PHPCompiler\\Runtime::'.$spineLc;
            $spineLcKey = strtolower($spineLogical);
            if (isset($this->context->functions[$spineLcKey]) || null === $stubBlock) {
                continue;
            }
            // Do not install null stubs that poison later Runtime.php lowering (#26756).
            if ('parse' === $spineLc && $this->shouldRealLowerInventoryArgvParseSpine()) {
                continue;
            }
            if ('parse' === $spineLc) {
                $this->emitM3EmitTuRuntimeParseStubNative(
                    $this->llvmInternalName($spineLogical),
                    $spineLogical,
                    $stubBlock
                );
            } else {
                $this->emitM3EmitTuRuntimeCompileEmitSmokeNative(
                    $this->llvmInternalName($spineLogical),
                    $spineLogical,
                    $stubBlock
                );
            }
        }
        foreach (array_keys($methods) as $methodLc) {
            $logical = 'PHPCompiler\\Runtime::'.$methodLc;
            $lc = strtolower($logical);
            unset(
                $this->context->functions[$lc],
                $this->context->functionReturnType[$lc],
                $this->context->functionProxies[$lc]
            );
            \PHPCompiler\JIT\BootstrapCompileSmokeM3Emit::declareRuntimeParseAndCompileViaParseEmitSmoke(
                $this->context,
                $this->llvmInternalName($logical),
                $logical,
                $methodLc
            );
        }
    }

    /**
     * Pre-lower emit spine before native emit bridge (#2550, #2559).
     *
     * Compile-driver path: host-lowers Runtime::__construct/parse/compileEmitSmoke from modules.
     * Emit-helper path: link-time trivial-echo AOT sidecar for parseAndCompile* / standalone.
     */
    private function compileM3EmitTuRuntimeSpineMethodsForRealLowering(): void
    {
        if ($this->shouldEnsureInventoryArgvParseHelperStubs()
            && !$this->shouldRealLowerInventoryArgvParseSpine()
        ) {
            return;
        }
        $sidecar = $this->isM3EmitTuTrivialEchoSidecarActive();
        if (!$this->shouldUseM3CompileDriverRealLowering()) {
            return;
        }
        $this->ensureM3EmitTuCompilerRuntimeCompileDeps();
        $this->ensureM3EmitTuRuntimeParseSpineDeps();
        // Void Optimizer/AssignOp::optimize before host-lowering compileEmitSmoke (#11809, #26756).
        $this->ensureM3EmitTuInventoryArgvVmOptimizerStub();
        $emitHelperStubMethods = [];
        if ($this->shouldStubInventoryEmitParseCompileSpine()) {
            $emitHelperStubMethods = ['parse', 'preparesourceforparser', 'preprocesssourceforparse', 'rewritesourcebeforeparser', 'compileemitsmoke'];
        } elseif ($this->shouldRealLowerInventoryArgvParseSpine()) {
            // Inventory argv: real-lower parse; preprocess helpers stay CFG stubs (#11809).
            // Keep compileEmitSmoke stubbed — full CFG hits object::optimize() under NestedJIT (#26756).
            // M5 argv: also skip PHP CFG for init*/diagnostics (native RuntimeEmitTuInit / void stubs);
            // host-lowering initParsePipeline hung the Zend rebuild for hours (#26756).
            $emitHelperStubMethods = [
                'preparesourceforparser',
                'preprocesssourceforparse',
                'rewritesourcebeforeparser',
                'compileemitsmoke',
                // Native __string__* stubs — real PHP lowering returns boxed __value__* and
                // BootstrapCompileSmokeM3Emit::echoLastParseFailureSuffix structGeps __string__
                // fields (#36144).
                'noteparsecompilenullforscript',
                'peeklastparsefailure',
            ];
            if ($this->shouldUseM5DriverHostCompile()) {
                // C-floor initParsePipeline via compileRuntimeInitParsePipelineM3Native (#26756).
                // C-floor Runtime::parse via RuntimeParseM5Native — skip NestedJIT mid-BB + prepare SEGV.
                // prepare/preprocess/rewrite stay as identity stubs (RuntimePrepareSpineIdentity).
                $emitHelperStubMethods = array_merge($emitHelperStubMethods, [
                    'parse',
                    'initparsepipeline',
                ]);
            }
        }
        $inventoryEmitHelper = $this->shouldStubM3InventoryEmitJitSpineMethods();
        foreach ([
            '__construct',
            'parse',
            'preparesourceforparser',
            'compile',
            'compileemitsmoke',
            'parseandcompileemitsmoke',
            'initparsepipeline',
            'initcompiler',
            'loadcoremodules',
            'noteparsecompilenullforscript',
            'peeklastparsefailure',
        ] as $methodLc) {
            if (in_array($methodLc, $emitHelperStubMethods, true)
                || ('compile' === $methodLc && $inventoryEmitHelper)
            ) {
                continue;
            }
            $this->compileM3EmitTuRuntimeMethodFromModules($methodLc);
        }
        // M5 / inventory argv: NestedJIT peer methods then PHPCfg\Parser::parse so C-floor
        // Runtime::parse can call astParser->parse (#26756 / #27426, #36144).
        if ($this->shouldUseM5ParseSpineCFloor()) {
            $this->ensureM5ParseSpineCFloorSymbols();
        }
        // M5 argv seed host-lowers Runtime::parse first; emitting the sidecar standalone
        // stub here runQueues mid-parse and fatals on a null LLVM insert block (#26756).
        if ($sidecar && null !== $this->m3EmitTuMainBlock && !$this->shouldUseM5DriverHostCompile()) {
            $this->emitM3EmitTuRuntimeStandaloneStubNative(
                $this->llvmInternalName('PHPCompiler\\Runtime::standalone'),
                'PHPCompiler\\Runtime::standalone',
                $this->m3EmitTuMainBlock
            );
        }
        if (!$this->shouldUseM4InventoryArgvNativeEmitRebuild()) {
            $this->runQueue();
        }
        if ($sidecar && null !== $this->m3EmitTuMainBlock && $this->shouldUseM5DriverHostCompile()) {
            $this->emitM3EmitTuRuntimeStandaloneStubNative(
                $this->llvmInternalName('PHPCompiler\\Runtime::standalone'),
                'PHPCompiler\\Runtime::standalone',
                $this->m3EmitTuMainBlock
            );
        }
    }

    /**
     * Runtime::compile calls these before Compiler::compile — native void stubs avoid parsing
     * lib/Compiler.php during inventory emit link (#1492).
     */
    private function ensureM3EmitTuCompilerRuntimeCompileDeps(): void
    {
        if (!$this->shouldUseM3CompileDriverRealLowering()) {
            return;
        }
        if (!$this->shouldUseM3EmitTuNativeBridge() && !$this->shouldUseM3InventoryEmitDriver()) {
            return;
        }
        $stubBlock = $this->m3EmitTuMainBlock ?? $this->m3CompileDriverMainBlock;
        foreach (['setpropertyhookregistry', 'setknownclassreadonly', 'setbarerethrowlines'] as $methodLc) {
            $logical = 'PHPCompiler\\Compiler::'.$methodLc;
            $lc = strtolower($logical);
            if (!isset($this->context->functions[$lc])) {
                $this->emitM3EmitTuCompilerArrayPropertySetterVoidStub(
                    $this->llvmInternalName($logical),
                    $logical,
                    $stubBlock
                );
            }
        }
        foreach (['setcompileabortdetailifempty', 'setdebuglastphaseinputfile'] as $methodLc) {
            $logical = 'PHPCompiler\\Compiler::'.$methodLc;
            $lc = strtolower($logical);
            if (!isset($this->context->functions[$lc])) {
                $this->emitM3EmitTuCompilerStringSetterVoidStub(
                    $this->llvmInternalName($logical),
                    $logical,
                    $stubBlock
                );
            }
        }
        $resetLogical = 'PHPCompiler\\Compiler::resetcompileabortdetail';
        $resetLc = strtolower($resetLogical);
        if (!isset($this->context->functions[$resetLc])) {
            $this->emitM3EmitTuCompilerVoidStub(
                $this->llvmInternalName($resetLogical),
                $resetLogical,
                $stubBlock
            );
        }
        foreach (['getdebuglastphaseinputfile', 'getcompileabortdetail'] as $methodLc) {
            $logical = 'PHPCompiler\\Compiler::'.$methodLc;
            $lc = strtolower($logical);
            if (!isset($this->context->functions[$lc])) {
                $this->emitM3EmitTuCompilerNullStringGetterStub(
                    $this->llvmInternalName($logical),
                    $logical,
                    $stubBlock
                );
            }
        }
        if ($this->shouldStubM3InventoryEmitJitSpineMethods()) {
            $compileLogical = 'PHPCompiler\\Compiler::compile';
            $compileLc = strtolower($compileLogical);
            if (!isset($this->context->functions[$compileLc])) {
                $this->emitM3EmitTuCompilerCompileNullStubNative(
                    $this->llvmInternalName($compileLogical),
                    $compileLogical
                );
            }
            foreach (['loadjit', 'loadjitcontext', 'createjit', 'jitcontextforloadjit', 'loadjitcompilemodulefuncs', 'jitemitinplace'] as $methodLc) {
                $logical = 'PHPCompiler\\Runtime::'.$methodLc;
                $lc = strtolower($logical);
                if (!isset($this->context->functions[$lc]) && null !== $stubBlock) {
                    $this->emitM3EmitTuRuntimeInitVoidStub(
                        $this->llvmInternalName($logical),
                        $logical,
                        $stubBlock
                    );
                }
            }
        }
    }

    /** Inventory emit spine: Compiler::compile link stub — emit path uses compileEmitSmoke (#1492). */
    private function emitM3EmitTuCompilerCompileNullStubNative(string $internalName, string $logical): PHPLLVM\Value
    {
        $lc = strtolower($logical);
        if (isset($this->context->functions[$lc])) {
            return $this->context->functions[$lc];
        }
        $objPtr = $this->context->getTypeFromString('__object__*');
        $func = $this->context->module->addFunction(
            $internalName,
            $this->context->context->functionType($objPtr, false, $objPtr, $objPtr)
        );
        $bb = $func->appendBasicBlock('entry');
        $saved = $this->context->builder;
        $this->context->builder = $this->context->context->builderCreate();
        $this->context->builder->positionAtEnd($bb);
        $this->context->builder->returnValue($objPtr->constNull());
        $this->context->builder->clearInsertionPosition();
        $this->context->builder = $saved;
        $this->context->functions[$lc] = $func;
        $this->context->functionReturnType[$lc] = '__object__*';
        $this->context->functionProxies[$lc] = new JIT\Call\Native($func, $logical, [$objPtr, $objPtr], []);

        return $func;
    }

    /** No-op array setter for Compiler spine — LLVM link only; real bodies deferred (#1492). */
    private function emitM3EmitTuCompilerArrayPropertySetterVoidStub(
        string $internalName,
        string $logicalName,
        ?Block $block
    ): PHPLLVM\Value {
        $lcname = strtolower($logicalName);
        if (isset($this->context->functions[$lcname])) {
            return $this->context->functions[$lcname];
        }
        $objectPtr = $this->context->getTypeFromString('__object__*');
        $htPtr = $this->context->getTypeFromString('__hashtable__*');
        $voidTy = $this->context->getTypeFromString('void');
        $func = $this->context->module->addFunction(
            $internalName,
            $this->context->context->functionType($voidTy, false, $objectPtr, $htPtr)
        );
        $bb = $func->appendBasicBlock('entry');
        $saved = $this->context->builder;
        $this->context->builder = $this->context->context->builderCreate();
        $this->context->builder->positionAtEnd($bb);
        $this->context->builder->returnVoid();
        $this->context->builder->clearInsertionPosition();
        $this->context->builder = $saved;
        $this->context->functions[$lcname] = $func;
        $this->context->functionReturnType[$lcname] = 'void';
        $this->context->functionProxies[$lcname] = new JIT\Call\Native(
            $func,
            $logicalName,
            [$objectPtr, $htPtr],
            null !== $block ? $this->collectParamDefaults($block) : []
        );

        return $func;
    }

    /** No-op string setter for Compiler spine — LLVM link only (#11809). */
    private function emitM3EmitTuCompilerStringSetterVoidStub(
        string $internalName,
        string $logicalName,
        ?Block $block
    ): PHPLLVM\Value {
        $lcname = strtolower($logicalName);
        if (isset($this->context->functions[$lcname])) {
            return $this->context->functions[$lcname];
        }
        $objectPtr = $this->context->getTypeFromString('__object__*');
        $strPtr = $this->context->getTypeFromString('__string__*');
        $voidTy = $this->context->getTypeFromString('void');
        $func = $this->context->module->addFunction(
            $internalName,
            $this->context->context->functionType($voidTy, false, $objectPtr, $strPtr)
        );
        $bb = $func->appendBasicBlock('entry');
        $saved = $this->context->builder;
        $this->context->builder = $this->context->context->builderCreate();
        $this->context->builder->positionAtEnd($bb);
        $this->context->builder->returnVoid();
        $this->context->builder->clearInsertionPosition();
        $this->context->builder = $saved;
        $this->context->functions[$lcname] = $func;
        $this->context->functionReturnType[$lcname] = 'void';
        $this->context->functionProxies[$lcname] = new JIT\Call\Native(
            $func,
            $logicalName,
            [$objectPtr, $strPtr],
            null !== $block ? $this->collectParamDefaults($block) : []
        );

        return $func;
    }

    /** No-op void Compiler spine method — LLVM link only (#11809). */
    private function emitM3EmitTuCompilerVoidStub(
        string $internalName,
        string $logicalName,
        ?Block $block
    ): PHPLLVM\Value {
        $lcname = strtolower($logicalName);
        if (isset($this->context->functions[$lcname])) {
            return $this->context->functions[$lcname];
        }
        $objectPtr = $this->context->getTypeFromString('__object__*');
        $voidTy = $this->context->getTypeFromString('void');
        $func = $this->context->module->addFunction(
            $internalName,
            $this->context->context->functionType($voidTy, false, $objectPtr)
        );
        $bb = $func->appendBasicBlock('entry');
        $saved = $this->context->builder;
        $this->context->builder = $this->context->context->builderCreate();
        $this->context->builder->positionAtEnd($bb);
        $this->context->builder->returnVoid();
        $this->context->builder->clearInsertionPosition();
        $this->context->builder = $saved;
        $this->context->functions[$lcname] = $func;
        $this->context->functionReturnType[$lcname] = 'void';
        $this->context->functionProxies[$lcname] = new JIT\Call\Native(
            $func,
            $logicalName,
            [$objectPtr],
            null !== $block ? $this->collectParamDefaults($block) : []
        );

        return $func;
    }

    /** Null string getter for Compiler spine — LLVM link only (#11809). */
    private function emitM3EmitTuCompilerNullStringGetterStub(
        string $internalName,
        string $logicalName,
        ?Block $block
    ): PHPLLVM\Value {
        $lcname = strtolower($logicalName);
        if (isset($this->context->functions[$lcname])) {
            return $this->context->functions[$lcname];
        }
        $objectPtr = $this->context->getTypeFromString('__object__*');
        $strPtr = $this->context->getTypeFromString('__string__*');
        $func = $this->context->module->addFunction(
            $internalName,
            $this->context->context->functionType($strPtr, false, $objectPtr)
        );
        $bb = $func->appendBasicBlock('entry');
        $saved = $this->context->builder;
        $this->context->builder = $this->context->context->builderCreate();
        $this->context->builder->positionAtEnd($bb);
        $this->context->builder->returnValue($strPtr->constNull());
        $this->context->builder->clearInsertionPosition();
        $this->context->builder = $saved;
        $this->context->functions[$lcname] = $func;
        $this->context->functionReturnType[$lcname] = '__string__*';
        $this->context->functionProxies[$lcname] = new JIT\Call\Native(
            $func,
            $logicalName,
            [$objectPtr],
            null !== $block ? $this->collectParamDefaults($block) : []
        );

        return $func;
    }

    /** void(Runtime $this, ?Script $script) — inventory argv parse-null recorder (#12036). */
    private function emitM3EmitTuRuntimeTwoObjectVoidStub(
        string $internalName,
        string $logicalName,
        ?Block $block
    ): PHPLLVM\Value {
        $lcname = strtolower($logicalName);
        if (isset($this->context->functions[$lcname])) {
            return $this->context->functions[$lcname];
        }
        $objectPtr = $this->context->getTypeFromString('__object__*');
        $voidTy = $this->context->getTypeFromString('void');
        $func = $this->context->module->addFunction(
            $internalName,
            $this->context->context->functionType($voidTy, false, $objectPtr, $objectPtr)
        );
        $bb = $func->appendBasicBlock('entry');
        $saved = $this->context->builder;
        $this->context->builder = $this->context->context->builderCreate();
        $this->context->builder->positionAtEnd($bb);
        $this->context->builder->returnVoid();
        $this->context->builder->clearInsertionPosition();
        $this->context->builder = $saved;
        $this->context->functions[$lcname] = $func;
        $this->context->functionReturnType[$lcname] = 'void';
        $this->context->functionProxies[$lcname] = new JIT\Call\Native(
            $func,
            $logicalName,
            [$objectPtr, $objectPtr],
            null !== $block ? $this->collectParamDefaults($block) : []
        );

        return $func;
    }

    /** Private Runtime helpers required before lowering parse() on inventory argv links (#2967). */
    private function ensureM3EmitTuRuntimeParseSpineDeps(): void
    {
        if (!$this->shouldEnsureInventoryArgvParseHelperStubs()) {
            return;
        }
        $this->ensureM3EmitTuRuntimeInventoryArgvParsePreprocessStubs();
        $stubBlock = $this->m3CompileDriverMainBlock ?? $this->m3EmitTuMainBlock;
        if (null === $stubBlock) {
            return;
        }
        foreach ([
            'detectfilestricttypes',
            'resetparsernameresolverstate',
            'formatparseandcompilenulldetail',
            'emitparseandcompilenulldiagnostic',
            'recordlastparsefailure',
            'formatphpparsererrorcontext',
            'emitparsecompilefailurestderr',
            'setdebug',
            'setaotdebugsymbols',
        ] as $methodLc) {
            $logical = 'PHPCompiler\\Runtime::'.$methodLc;
            $lc = strtolower($logical);
            if (isset($this->context->functions[$lc])) {
                continue;
            }
            $this->compileSkippedCompilerSplitCfgStub(
                $this->llvmInternalName($logical),
                $stubBlock,
                $logical
            );
        }
    }

    /**
     * Inventory argv parse spine: stub preprocess/rewrite (heavy rewriter deps) and real-lower prepare wrapper (#11809).
     */
    private function ensureM3EmitTuRuntimeInventoryArgvParsePreprocessStubs(): void
    {
        if (!$this->shouldStubInventoryArgvPreprocessSpineMethods()) {
            return;
        }
        // Prefer identity stubs with real signatures over void stubs from {main} (#26756).
        $this->ensureM5ArgvPrepareSpineIdentityStubs();
        $stubBlock = $this->m3CompileDriverMainBlock ?? $this->m3EmitTuMainBlock;
        if (null === $stubBlock) {
            return;
        }
        foreach (['preprocesssourceforparse', 'rewritesourcebeforeparser'] as $methodLc) {
            $logical = 'PHPCompiler\\Runtime::'.$methodLc;
            $lc = strtolower($logical);
            if (isset($this->context->functions[$lc])) {
                continue;
            }
            $this->compileSkippedCompilerSplitCfgStub(
                $this->llvmInternalName($logical),
                $stubBlock,
                $logical
            );
        }
        $prepareLogical = 'PHPCompiler\\Runtime::preparesourceforparser';
        $prepareLc = strtolower($prepareLogical);
        if (!isset($this->context->functions[$prepareLc])) {
            $this->compileM3EmitTuRuntimeMethodFromRuntimePhpFile('preparesourceforparser', $prepareLogical, $prepareLc);
        }
    }

    /** Inventory argv: AssignOp::optimize is link-only on compileEmitSmoke spine (#11809). */
    private function ensureM3EmitTuInventoryArgvVmOptimizerStub(): void
    {
        if (!$this->shouldUseM3CompileDriverRealLowering()) {
            return;
        }
        // M5 argv / gen-0 seed real-lowers compileEmitSmoke even when inventory-emit
        // classification flickers; still need void optimize() stubs (#26756).
        if (!$this->shouldUseM3InventoryEmitDriver() && !$this->shouldUseM5DriverHostCompile()) {
            return;
        }
        $objectPtr = $this->context->getTypeFromString('__object__*');
        $voidTy = $this->context->getTypeFromString('void');
        foreach ([
            'PHPCompiler\\VM\\Optimizer::optimize',
            'PHPCompiler\\VM\\Optimizer\\AssignOp::optimize',
        ] as $logical) {
            $lc = strtolower($logical);
            if (isset($this->context->functions[$lc])) {
                continue;
            }
            $func = $this->context->module->addFunction(
                $this->llvmInternalName($logical),
                $this->context->context->functionType($voidTy, false, $objectPtr, $objectPtr)
            );
            $bb = $func->appendBasicBlock('entry');
            $saved = $this->context->builder;
            $this->context->builder = $this->context->context->builderCreate();
            $this->context->builder->positionAtEnd($bb);
            $this->context->builder->returnVoid();
            $this->context->builder->clearInsertionPosition();
            $this->context->builder = $saved;
            $this->context->functions[$lc] = $func;
            $this->context->functionReturnType[$lc] = 'void';
            $this->context->functionProxies[$lc] = new JIT\Call\Native(
                $func,
                $logical,
                [$objectPtr, $objectPtr],
                []
            );
        }
    }

    /** Ensure parse + Compiler::compileEmitSmoke exist before emit-bridge LLVM (#2666). */
    private function ensureM3EmitTuEmitBridgeSpineSymbols(): void
    {
        if (!$this->shouldUseM3CompileDriverRealLowering()) {
            return;
        }
        if (!$this->shouldUseM3EmitTuNativeBridge() && !$this->shouldUseM3InventoryEmitDriver()) {
            return;
        }
        $this->ensureM3EmitTuCompilerRuntimeCompileDeps();
        $this->ensureM3EmitTuRuntimeParseSpineDeps();
        $this->ensureM3EmitTuInventoryArgvVmOptimizerStub();
        $stubBlock = $this->m3CompileDriverMainBlock ?? $this->m3EmitTuMainBlock;
        if ($this->shouldStubInventoryEmitParseCompileSpine() && null !== $stubBlock) {
            $parseLc = strtolower('PHPCompiler\\Runtime::parse');
            if (!isset($this->context->functions[$parseLc])) {
                $this->emitM3EmitTuRuntimeParseStubNative(
                    $this->llvmInternalName('PHPCompiler\\Runtime::parse'),
                    'PHPCompiler\\Runtime::parse',
                    $stubBlock
                );
            }
            $runtimeEmitLc = strtolower('PHPCompiler\\Runtime::compileEmitSmoke');
            if (!isset($this->context->functions[$runtimeEmitLc])) {
                $this->emitM3EmitTuRuntimeCompileEmitSmokeNative(
                    $this->llvmInternalName('PHPCompiler\\Runtime::compileEmitSmoke'),
                    'PHPCompiler\\Runtime::compileEmitSmoke',
                    $stubBlock
                );
            }
            $compilerEmitLc = 'phpcompiler\\compiler::compileemitsmoke';
            if (!isset($this->context->functions[$compilerEmitLc])) {
                $this->emitM3EmitTuCompilerCompileEmitSmokeNativeFunction(
                    $this->llvmInternalName('PHPCompiler\\Compiler::compileEmitSmoke'),
                    'PHPCompiler\\Compiler::compileEmitSmoke'
                );
            }
        } else {
            $parseLc = strtolower('PHPCompiler\\Runtime::parse');
            if (!isset($this->context->functions[$parseLc])) {
                $this->compileM3EmitTuRuntimeMethodFromModules('parse');
            }
            $emitSmokeLc = strtolower('PHPCompiler\\Runtime::parseandcompileemitsmoke');
            if (!isset($this->context->functions[$emitSmokeLc])) {
                $this->compileM3EmitTuRuntimeMethodFromModules('parseandcompileemitsmoke');
            }
            foreach (['preparesourceforparser', 'compileemitsmoke', 'noteparsecompilenullforscript', 'peeklastparsefailure'] as $methodLc) {
                $runtimeLc = strtolower('PHPCompiler\\Runtime::'.$methodLc);
                if (!isset($this->context->functions[$runtimeLc])) {
                    $this->compileM3EmitTuRuntimeMethodFromModules($methodLc);
                }
            }
            $compilerEmitLc = 'phpcompiler\\compiler::compileemitsmoke';
            if (!isset($this->context->functions[$compilerEmitLc])) {
                $this->compileM3EmitTuCompilerMethodFromRuntimeModules('compileemitsmoke');
            }
        }
    }

    /**
     * Emit-helper RuntimeEmitTuInit calls these spine symbols; ensure they are defined (#2633).
     */
    private function ensureM3EmitTuRuntimeInitSpineSymbols(Block $stubBlock): void
    {
        if ($this->shouldEnsureInventoryArgvParseHelperStubs()
            && !$this->shouldRealLowerInventoryArgvParseSpine()
        ) {
            foreach (['initparsepipeline', 'initcompiler', 'initvmcontext', 'loadcoremodules'] as $methodLc) {
                $logical = 'PHPCompiler\\Runtime::'.$methodLc;
                $lc = strtolower($logical);
                if (isset($this->context->functions[$lc])) {
                    continue;
                }
                $this->emitM3EmitTuRuntimeInitVoidStub(
                    $this->llvmInternalName($logical),
                    $logical,
                    $stubBlock
                );
            }
            $noteLogical = 'PHPCompiler\\Runtime::noteparsecompilenullforscript';
            $noteLc = strtolower($noteLogical);
            if (!isset($this->context->functions[$noteLc])) {
                $this->emitM3EmitTuRuntimeTwoObjectVoidStub(
                    $this->llvmInternalName($noteLogical),
                    $noteLogical,
                    $stubBlock
                );
            }
            $peekLogical = 'PHPCompiler\\Runtime::peeklastparsefailure';
            $peekLc = strtolower($peekLogical);
            if (!isset($this->context->functions[$peekLc])) {
                $this->emitM3EmitTuCompilerNullStringGetterStub(
                    $this->llvmInternalName($peekLogical),
                    $peekLogical,
                    $stubBlock
                );
            }

            return;
        }
        foreach (['initparsepipeline', 'loadcoremodules'] as $methodLc) {
            $logical = 'PHPCompiler\\Runtime::'.$methodLc;
            $lc = strtolower($logical);
            if ($this->shouldUseM3InventoryEmitDriver()) {
                unset($this->context->functions[$lc], $this->context->functionReturnType[$lc], $this->context->functionProxies[$lc]);
            } elseif (!isset($this->context->functions[$lc])) {
                $this->compileM3EmitTuRuntimeMethodFromModules($methodLc);
            }
            if (!$this->shouldUseM3InventoryEmitDriver() && isset($this->context->functions[$lc])) {
                continue;
            }
            if ('initparsepipeline' === $methodLc) {
                $this->compileRuntimeInitParsePipelineM3Native(
                    $this->llvmInternalName($logical),
                    $stubBlock,
                    $logical
                );
                continue;
            }
            $this->compileRuntimeLoadCoreModulesM3Native(
                $this->llvmInternalName($logical),
                $stubBlock,
                $logical
            );
        }
    }

    /** Link-time trivial-echo AOT sidecar for emit-helper TU (#2559, #2566). */
    private function isM3EmitTuTrivialEchoSidecarActive(): bool
    {
        // M5 argv driver + inventory gen-2→gen-3 bin/compile.php links need bootstrap-aot sidecars
        // even when EMIT_HELPER_LINK is unset (#3004).
        if ($this->shouldUseM5DriverHostCompile() || $this->shouldUseM3InventoryEmitDriver()) {
            $this->cacheM3EmitTuTrivialEchoAtLinkTime();

            return \PHPCompiler\JIT\M3EmitTuTrivialEchoAot::isRegistered($this->context);
        }
        // M4 argv bin/compile.php links with -u PHP_COMPILER_EMIT_HELPER_LINK but still needs
        // compile_smoke / HelloWorld sidecars at link time (#3004, #2880).
        $inventoryArgvSidecar = $this->shouldUseM3InventoryEmitDriver() && $this->shouldUseM4BinCompileArgvMainNative();
        if (!$this->shouldUseEmitHelperLinkStubs() && !$inventoryArgvSidecar) {
            return false;
        }
        if (!$this->shouldUseM3EmitTuNativeBridge() && !$this->shouldUseM3InventoryEmitDriver()) {
            return false;
        }
        $this->cacheM3EmitTuTrivialEchoAtLinkTime();

        return \PHPCompiler\JIT\M3EmitTuTrivialEchoAot::isRegistered($this->context);
    }

    /**
     * Emit TU: native LLVM stubs for Runtime spine — avoid PHP CFG (PHPTypes global ctor; #2540).
     */
    private function compileM3EmitTuRuntimeSpineStub(
        string $internalName,
        Block $block,
        string $logicalName,
        string $lower
    ): PHPLLVM\Value {
        if (str_ends_with($lower, '\\runtime::__construct')) {
            return $this->emitM3EmitTuRuntimeConstructNativeFunction($internalName, $logicalName, $block);
        }
        if (str_ends_with($lower, '\\runtime::initvmcontext')) {
            return $this->compileRuntimeInitVmContextM3Native($internalName, $block, $logicalName);
        }
        if (
            str_ends_with($lower, '\\runtime::initparsepipeline')
            || str_ends_with($lower, '\\runtime::initcompiler')
            || str_ends_with($lower, '\\runtime::loadcoremodules')
            || str_ends_with($lower, '\\runtime::loadjit')
            || str_ends_with($lower, '\\runtime::jitcompileblock')
            || str_ends_with($lower, '\\runtime::jitemitinplace')
        ) {
            return $this->emitM3EmitTuRuntimeInitVoidStub($internalName, $logicalName, $block);
        }
        if (str_ends_with($lower, '\\runtime::parse')) {
            return $this->emitM3EmitTuRuntimeParseStubNative($internalName, $logicalName, $block);
        }
        if (str_ends_with($lower, '\\runtime::compileemitsmoke')) {
            return $this->emitM3EmitTuRuntimeCompileEmitSmokeNative($internalName, $logicalName, $block);
        }
        if (str_ends_with($lower, '\\runtime::standalone')) {
            return $this->emitM3EmitTuRuntimeStandaloneStubNative($internalName, $logicalName, $block);
        }
        if (
            str_ends_with($lower, '\\runtime::parseandcompile')
            || str_ends_with($lower, '\\runtime::parseandcompileemitsmoke')
        ) {
            return $this->compileRuntimeParseAndCompileM3Native($internalName, $block, $logicalName);
        }
        if (
            str_ends_with($lower, '\\runtime::compile')
            || str_ends_with($lower, '\\runtime::parseandcompilefile')
        ) {
            return $this->emitM3EmitTuRuntimeBlockPtrStubNative($internalName, $logicalName, $block);
        }
        // M5 argv diagnostics — void/null stubs (not PHP CFG) (#26756).
        if (str_ends_with($lower, '\\runtime::noteparsecompilenullforscript')) {
            return $this->emitM3EmitTuRuntimeTwoObjectVoidStub($internalName, $logicalName, $block);
        }
        if (str_ends_with($lower, '\\runtime::peeklastparsefailure')) {
            return $this->emitM3EmitTuCompilerNullStringGetterStub($internalName, $logicalName, $block);
        }

        throw new \LogicException('Unhandled M3 emit TU Runtime spine: '.$logicalName);
    }

    /** Stub Runtime::parse for emit TU link — Batch A replaces with real parser (#2516). */
    private function emitM3EmitTuRuntimeParseStubNative(
        string $internalName,
        string $logicalName,
        Block $block
    ): PHPLLVM\Value {
        $lcname = strtolower($logicalName);
        if (isset($this->context->functions[$lcname])) {
            return $this->context->functions[$lcname];
        }
        $objectPtr = $this->context->getTypeFromString('__object__*');
        $strPtr = $this->context->getTypeFromString('__string__*');
        $func = $this->context->module->addFunction(
            $internalName,
            $this->context->context->functionType($objectPtr, false, $objectPtr, $strPtr, $strPtr)
        );
        $bb = $func->appendBasicBlock('entry');
        $saved = $this->context->builder;
        $this->context->builder = $this->context->context->builderCreate();
        $this->context->builder->positionAtEnd($bb);
        $this->context->builder->returnValue($objectPtr->constNull());
        $this->context->builder->clearInsertionPosition();
        $this->context->builder = $saved;
        $this->context->functions[$lcname] = $func;
        $this->context->functionReturnType[$lcname] = '__object__*';
        $this->context->functionProxies[$lcname] = new JIT\Call\Native(
            $func,
            $logicalName,
            [$objectPtr, $strPtr, $strPtr],
            $this->collectParamDefaults($block)
        );

        return $func;
    }

    /** Stub Runtime methods returning ?Block for emit TU link (#2540). */
    private function emitM3EmitTuRuntimeBlockPtrStubNative(
        string $internalName,
        string $logicalName,
        Block $block
    ): PHPLLVM\Value {
        $lcname = strtolower($logicalName);
        if (isset($this->context->functions[$lcname])) {
            return $this->context->functions[$lcname];
        }
        $objectPtr = $this->context->getTypeFromString('__object__*');
        $args = $this->normalizeSelfHostNativeCallArgTypes(
            $this->collectStubFunctionArgTypes($block),
            $logicalName
        );
        $func = $this->context->module->addFunction(
            $internalName,
            $this->context->context->functionType($objectPtr, false, ...$args)
        );
        $bb = $func->appendBasicBlock('entry');
        $saved = $this->context->builder;
        $this->context->builder = $this->context->context->builderCreate();
        $this->context->builder->positionAtEnd($bb);
        $this->context->builder->returnValue($objectPtr->constNull());
        $this->context->builder->clearInsertionPosition();
        $this->context->builder = $saved;
        $this->context->functions[$lcname] = $func;
        $this->context->functionReturnType[$lcname] = '__object__*';
        $this->context->functionProxies[$lcname] = new JIT\Call\Native(
            $func,
            $logicalName,
            $args,
            $this->collectParamDefaults($block)
        );

        return $func;
    }

    /** Native Runtime::compileEmitSmoke — reuse Compiler emit-smoke block stub (#2442). */
    private function emitM3EmitTuRuntimeCompileEmitSmokeNative(
        string $internalName,
        string $logicalName,
        Block $block
    ): PHPLLVM\Value {
        $lcname = strtolower($logicalName);
        if (isset($this->context->functions[$lcname])) {
            return $this->context->functions[$lcname];
        }
        $objectPtr = $this->context->getTypeFromString('__object__*');
        $func = $this->context->module->addFunction(
            $internalName,
            $this->context->context->functionType($objectPtr, false, $objectPtr, $objectPtr)
        );
        $bb = $func->appendBasicBlock('entry');
        $saved = $this->context->builder;
        $this->context->builder = $this->context->context->builderCreate();
        $this->context->builder->positionAtEnd($bb);
        $this->context->builder->returnValue($objectPtr->constNull());
        $this->context->builder->clearInsertionPosition();
        $this->context->builder = $saved;
        $this->context->functions[$lcname] = $func;
        $this->context->functionReturnType[$lcname] = '__object__*';
        $this->context->functionProxies[$lcname] = new JIT\Call\Native(
            $func,
            $logicalName,
            [$objectPtr, $objectPtr],
            $this->collectParamDefaults($block)
        );

        return $func;
    }

    /**
     * Real Runtime::standalone for M5 vendor .o emit (separate symbol from sidecar stub — #3036).
     */
    private function ensureRuntimeStandaloneKeepObjectLoweringForLink(): ?PHPLLVM\Value
    {
        if (!$this->shouldPrelowerRuntimeStandaloneForKeepObjectEmit()) {
            return null;
        }
        $logical = 'PHPCompiler\\Runtime::standaloneKeepObject';
        $lc = strtolower($logical);
        if (isset($this->context->functions[$lc])) {
            return $this->context->functions[$lc];
        }
        $standaloneBlock = null;
        foreach ($this->queue as $item) {
            $func = $item[0];
            if (!$func instanceof CoreFunc\PHP) {
                continue;
            }
            if ('phpcompiler\\runtime::standalone' === strtolower($func->getName())) {
                $standaloneBlock = $func->block;
                break;
            }
        }
        if (null === $standaloneBlock) {
            $this->compileM3EmitTuRuntimeMethodFromModules('standalone');
            $this->runQueue();
            foreach ($this->queue as $item) {
                $func = $item[0];
                if (!$func instanceof CoreFunc\PHP) {
                    continue;
                }
                if ('phpcompiler\\runtime::standalone' === strtolower($func->getName())) {
                    $standaloneBlock = $func->block;
                    break;
                }
            }
            if (null === $standaloneBlock) {
                return null;
            }
        }

        return $this->compileRuntimeSpinePhpLowering(
            $this->llvmInternalName($logical),
            $standaloneBlock,
            $logical
        );
    }

    /** Stub Runtime::standalone for emit TU link — Batch A replaces (#2516). */
    private function emitM3EmitTuRuntimeStandaloneStubNative(
        string $internalName,
        string $logicalName,
        Block $block
    ): PHPLLVM\Value {
        $lcname = strtolower($logicalName);
        if (isset($this->context->functions[$lcname])) {
            return $this->context->functions[$lcname];
        }
        $keepObjectStandalone = $this->ensureRuntimeStandaloneKeepObjectLoweringForLink();
        if (\PHPCompiler\JIT\M3EmitTuTrivialEchoAot::isRegistered($this->context)) {
            \PHPCompiler\JIT\M3EmitTuTrivialEchoAot::ensureSidecarCopyAbisForLink($this->context);
        }
        $objectPtr = $this->context->getTypeFromString('__object__*');
        $strPtr = $this->context->getTypeFromString('__string__*');
        $voidTy = $this->context->getTypeFromString('void');
        $func = $this->context->module->addFunction(
            $internalName,
            $this->context->context->functionType($voidTy, false, $objectPtr, $objectPtr, $strPtr)
        );
        $bb = $func->appendBasicBlock('entry');
        $saved = $this->context->builder;
        $this->context->builder = $this->context->context->builderCreate();
        $this->context->builder->positionAtEnd($bb);
        // M5 gen-0 never-seen echo: C-floor sentinel → cc ELF before sidecar/keepObject (#26756).
        if (\PHPCompiler\JIT\M5TrivialEchoNative::isRegistered($this->context)) {
            [$handled, $merge] = \PHPCompiler\JIT\M5TrivialEchoNative::emitStandaloneSentinelCheck(
                $this->context,
                $func->getParam(1),
                $func->getParam(2),
                'stub'
            );
            $cont = JIT\BasicBlockHelper::append($this->context, 'm5_te_stub_cont');
            $done = JIT\BasicBlockHelper::append($this->context, 'm5_te_stub_done');
            $this->context->builder->positionAtEnd($merge);
            $this->context->builder->branchIf($handled, $done, $cont);
            $this->context->builder->positionAtEnd($done);
            $this->context->builder->returnVoid();
            $this->context->builder->positionAtEnd($cont);
        }
        if (null !== $keepObjectStandalone) {
            \PHPCompiler\JIT\M3EmitTuTrivialEchoAot::emitStandaloneWithKeepObjectDispatch(
                $this->context,
                $func->getParam(0),
                $func->getParam(1),
                $func->getParam(2),
                $keepObjectStandalone
            );
        } elseif (\PHPCompiler\JIT\M3EmitTuTrivialEchoAot::isRegistered($this->context)) {
            \PHPCompiler\JIT\M3EmitTuTrivialEchoAot::emitStandaloneWriteCachedAot(
                $this->context,
                $func->getParam(1),
                $func->getParam(2)
            );
        } else {
            $this->context->builder->returnVoid();
        }
        $this->context->builder->clearInsertionPosition();
        $this->context->builder = $saved;
        $this->context->functions[$lcname] = $func;
        $this->context->functionReturnType[$lcname] = 'void';
        $this->context->functionProxies[$lcname] = new JIT\Call\Native(
            $func,
            $logicalName,
            [$objectPtr, $objectPtr, $strPtr],
            $this->collectParamDefaults($block)
        );

        return $func;
    }

    /** Register native compileEmitSmoke with Compiler object metadata (#1937). */
    private function compileM3EmitTuCompilerEmitSmokeNativeDecl(): void
    {
        if (
            !$this->shouldUseM3EmitTuNativeBridge()
            && !$this->shouldUseM3InventoryEmitDriver()
            && !$this->shouldUseM4BinCompileArgvMainNative()
            && !$this->shouldEnsureInventoryArgvParseHelperStubs()
        ) {
            return;
        }
        if ($this->shouldUseM3CompileDriverRealLowering()
            || $this->shouldUseM3EmitTuEmitHelperSpineRealLowering()
        ) {
            $this->compileM3EmitTuCompilerMethodFromRuntimeModules('compileemitsmoke');

            return;
        }
        $logical = 'PHPCompiler\\Compiler::compileEmitSmoke';
        $lc = strtolower($logical);
        if (isset($this->context->functions[$lc])) {
            return;
        }
        $this->context->pushScope();
        $this->context->scope->classId = $this->context->type->object->lookup('PHPCompiler\\Compiler');
        $this->context->scope->className = 'phpcompiler\\compiler';
        $this->emitM3EmitTuCompilerCompileEmitSmokeNativeFunction(
            $this->llvmInternalName($logical),
            $logical
        );
        $this->context->popScope();
    }

    /**
     * Pre-lower selected Compiler methods from the bundled emit TU (#1937).
     *
     * @param list<string> $methodLcs lowercase method names without class prefix
     */
    private function compileM3EmitTuCompilerSpineMethodsFromMainBlock(array $methodLcs): void
    {
        if (!$this->shouldUseM3EmitTuNativeBridge()) {
            return;
        }
        foreach ($methodLcs as $methodLc) {
            $this->compileM3EmitTuCompilerMethodFromRuntimeModules($methodLc);
        }
        if (null === $this->m3EmitTuMainBlock) {
            return;
        }
        $allowed = array_fill_keys($methodLcs, true);
        foreach ($this->m3EmitTuMainBlock->opCodes as $op) {
            if (OpCode::TYPE_DECLARE_CLASS !== $op->type) {
                continue;
            }
            $nameOp = $this->m3EmitTuMainBlock->getOperand($op->arg1);
            if (!$nameOp instanceof Operand\Literal) {
                continue;
            }
            $lc = strtolower(str_replace('/', '\\', ltrim($nameOp->value, '\\')));
            if ('phpcompiler\\compiler' !== $lc || null === $op->block1) {
                continue;
            }
            $this->context->pushScope();
            $this->context->scope->classId = $this->context->type->object->declareClass($nameOp);
            $this->context->scope->className = $lc;
            foreach ($op->block1->opCodes as $methodOp) {
                if (OpCode::TYPE_DECLARE_METHOD !== $methodOp->type) {
                    continue;
                }
                $methodOpName = $op->block1->getOperand($methodOp->arg1);
                if (!$methodOpName instanceof Operand\Literal) {
                    continue;
                }
                $methodLc = strtolower($methodOpName->value);
                if (!isset($allowed[$methodLc])) {
                    continue;
                }
                $logical = $lc.'::'.$methodLc;
                if (!isset($this->context->functions[strtolower($logical)])) {
                    $this->compileBlock($methodOp->block1, $logical);
                }
            }
            $this->context->popScope();

            return;
        }
    }

    private function compileM3EmitTuCompilerMethodFromRuntimeModules(string $methodLc): void
    {
        $logical = 'PHPCompiler\\Compiler::'.$methodLc;
        $lc = strtolower($logical);
        if (isset($this->context->functions[$lc])) {
            return;
        }
        // Inventory compile_driver already require_once's Compiler.php — avoid O(module×func) scan (#2967).
        if ($this->shouldUseM3InventoryEmitDriver()) {
            $this->compileM3EmitTuCompilerMethodFromCompilerPhpFile($methodLc, $logical, $lc);

            return;
        }
        foreach ($this->context->runtime->modules as $module) {
            foreach ($module->getFunctions() as $func) {
                if (!$func instanceof CoreFunc\PHP) {
                    continue;
                }
                if (strtolower($func->getName()) !== $lc) {
                    continue;
                }
                $this->compileBlock($func->block, $logical);

                return;
            }
        }
        $this->compileM3EmitTuCompilerMethodFromCompilerPhpFile($methodLc, $logical, $lc);
    }

    /** Lower Compiler spine method from lib/Compiler.php (inventory argv driver avoids module scan, #2967). */
    private function compileM3EmitTuCompilerMethodFromCompilerPhpFile(string $methodLc, string $logical, string $lc): void
    {
        $compilerPath = dirname(__DIR__).'/Compiler.php';
        if (!is_file($compilerPath)) {
            return;
        }
        if (null === $this->m3EmitTuCompilerPhpScript) {
            try {
                $this->m3EmitTuCompilerPhpScript = $this->context->runtime->parse(
                    (string) file_get_contents($compilerPath),
                    $compilerPath
                );
            } catch (\Throwable $e) {
                return;
            }
        }
        $script = $this->m3EmitTuCompilerPhpScript;
        foreach ($script->functions as $cfgFunc) {
            $funcLc = strtolower($cfgFunc->name);
            if ($funcLc !== $lc && $funcLc !== $methodLc && !str_ends_with($funcLc, '\\'.$methodLc)) {
                continue;
            }
            $compiled = $this->context->runtime->compileFunc($logical, $cfgFunc);
            if ($compiled instanceof CoreFunc\PHP) {
                $this->compileBlock($compiled->block, $logical);
            }

            return;
        }
    }

    /** Pre-lower Runtime spine from JIT queue before emit bridge binds symbols (#2512, #2550). */
    private function compileM3EmitTuRuntimeMethodFromQueue(string $methodLc): void
    {
        $logical = 'PHPCompiler\\Runtime::'.$methodLc;
        $lc = strtolower($logical);
        if (isset($this->context->functions[$lc])) {
            return;
        }
        foreach ($this->queue as $item) {
            $func = $item[0];
            if (!$func instanceof CoreFunc\PHP) {
                continue;
            }
            if (strtolower($func->getName()) !== $lc) {
                continue;
            }
            $this->compileBlock($func->block, $logical);

            return;
        }
        $this->compileM3EmitTuRuntimeMethodFromDeclareClassBlocks([$methodLc]);
        $this->compileM3EmitTuRuntimeMethodFromModules($methodLc);
    }

    private function compileM3EmitTuRuntimeMethodFromModules(string $methodLc): void
    {
        if ($this->shouldUseM3EmitTuEmitHelperSpineRealLowering()) {
            static $emitHelperHostSpine = ['parse', 'compileemitsmoke'];
            if (in_array($methodLc, $emitHelperHostSpine, true)) {
                $logical = 'PHPCompiler\\Runtime::'.$methodLc;
                $lc = strtolower($logical);
                if (!isset($this->context->functions[$lc])) {
                    $this->compileM3EmitTuRuntimeMethodFromRuntimePhpFile($methodLc, $logical, $lc);
                }
            }

            return;
        }
        $logical = 'PHPCompiler\\Runtime::'.$methodLc;
        $lc = strtolower($logical);
        // Inventory emit OR M5 argv seed real-lower: host-parse lib/Runtime.php (#2967, #26756).
        // M4 bin/compile.php + M5_DRIVER_HOST can have shouldRealLower true while
        // shouldUseM3InventoryEmitDriver() is false — still need the Runtime.php path.
        if ($this->shouldUseM3InventoryEmitDriver() || $this->shouldRealLowerInventoryArgvParseSpine()) {
            if ('__construct' === $methodLc) {
                if (!isset($this->context->functions[$lc])) {
                    $stubBlock = $this->m3CompileDriverMainBlock ?? $this->m3EmitTuMainBlock;
                    if (null !== $stubBlock) {
                        $this->emitM3EmitTuRuntimeConstructNativeFunction(
                            $this->llvmInternalName($logical),
                            $logical,
                            $stubBlock
                        );
                    }
                }

                return;
            }
            // Never scan O(modules×funcs) on inventory argv links (#2967). parse/compileEmitSmoke from
            // Runtime.php; ctor/init* use native M3 via compileBlock / ensureM3EmitTuRuntimeInitSpineSymbols.
            if (in_array($methodLc, [
                'parse',
                'preparesourceforparser',
                'preprocesssourceforparse',
                'rewritesourcebeforeparser',
                'compileemitsmoke',
                'peeklastparsefailure',
                'noteparsecompilenullforscript',
            ], true)) {
                // Inventory argv / compile_driver: compileEmitSmoke must stay stubbed — full CFG
                // hits Object_::optimize() under NestedJIT and SEGV (#26756, #36144).
                // peekLastParseFailure / noteParseCompileNullForScript must stay stubbed too —
                // real lowering returns __value__* but BootstrapCompileSmokeM3Emit::echoLastParseFailureSuffix
                // structGeps __string__ fields on the call result (#36144).
                if ($this->shouldRealLowerInventoryArgvParseSpine()
                    && in_array($methodLc, [
                        'compileemitsmoke',
                        'peeklastparsefailure',
                        'noteparsecompilenullforscript',
                    ], true)
                ) {
                    $stubBlock = $this->m3CompileDriverMainBlock ?? $this->m3EmitTuMainBlock;
                    if (null === $stubBlock) {
                        return;
                    }
                    if ('compileemitsmoke' === $methodLc) {
                        $this->emitM3EmitTuRuntimeCompileEmitSmokeNative(
                            $this->llvmInternalName($logical),
                            $logical,
                            $stubBlock
                        );
                    } elseif ('noteparsecompilenullforscript' === $methodLc) {
                        $this->emitM3EmitTuRuntimeTwoObjectVoidStub(
                            $this->llvmInternalName($logical),
                            $logical,
                            $stubBlock
                        );
                    } else {
                        $this->emitM3EmitTuCompilerNullStringGetterStub(
                            $this->llvmInternalName($logical),
                            $logical,
                            $stubBlock
                        );
                    }

                    return;
                }
                if ('parse' === $methodLc && $this->shouldUseM5ParseSpineCFloor()) {
                    $this->ensureM5ParseSpineCFloorSymbols();

                    return;
                }
                // Inventory argv / M5 argv: diagnostic helpers must return native __string__* (not
                // boxed __value__*) — BootstrapCompileSmokeM3Emit::echoLastParseFailureSuffix
                // structGeps __string__ fields (#26756, #36144).
                if (in_array($methodLc, ['noteparsecompilenullforscript', 'peeklastparsefailure'], true)
                    && ($this->shouldUseM5DriverHostCompile() || $this->shouldRealLowerInventoryArgvParseSpine())
                ) {
                    $stubBlock = $this->m3CompileDriverMainBlock ?? $this->m3EmitTuMainBlock;
                    if (null === $stubBlock) {
                        return;
                    }
                    if ('noteparsecompilenullforscript' === $methodLc) {
                        $this->emitM3EmitTuRuntimeTwoObjectVoidStub(
                            $this->llvmInternalName($logical),
                            $logical,
                            $stubBlock
                        );
                    } else {
                        $this->emitM3EmitTuCompilerNullStringGetterStub(
                            $this->llvmInternalName($logical),
                            $logical,
                            $stubBlock
                        );
                    }

                    return;
                }
                // M5 argv seed: identity preprocess/rewrite CFG stubs (#11809 / #26756).
                if ($this->shouldUseM5DriverHostCompile()
                    && in_array($methodLc, ['preprocesssourceforparse', 'rewritesourcebeforeparser'], true)
                ) {
                    $stubBlock = $this->m3CompileDriverMainBlock ?? $this->m3EmitTuMainBlock;
                    if (null === $stubBlock) {
                        return;
                    }
                    $this->compileSkippedCompilerSplitCfgStub(
                        $this->llvmInternalName($logical),
                        $stubBlock,
                        $logical
                    );

                    return;
                }
                if ($this->shouldRealLowerInventoryArgvParseSpine()) {
                    // Drop map entry so Runtime.php lowering can run; early null stubs must not win (#26756).
                    unset(
                        $this->context->functions[$lc],
                        $this->context->functionReturnType[$lc],
                        $this->context->functionProxies[$lc]
                    );
                } elseif (isset($this->context->functions[$lc])) {
                    return;
                }
                $this->compileM3EmitTuRuntimeMethodFromRuntimePhpFile($methodLc, $logical, $lc);
                if (!isset($this->context->functions[$lc])) {
                    $this->compileM3EmitTuRuntimeMethodFromDeclareClassBlocks([$methodLc]);
                }

                return;
            }
        }
        if (isset($this->context->functions[$lc])) {
            return;
        }
        foreach ($this->context->runtime->modules as $module) {
            foreach ($module->getFunctions() as $func) {
                if (!$func instanceof CoreFunc\PHP) {
                    continue;
                }
                if (strtolower($func->getName()) !== $lc) {
                    continue;
                }
                $this->compileBlock($func->block, $logical);

                return;
            }
        }
        if (null === $this->m3EmitTuMainBlock) {
            return;
        }
        foreach ($this->m3EmitTuMainBlock->opCodes as $op) {
            if (OpCode::TYPE_DECLARE_CLASS !== $op->type) {
                continue;
            }
            $nameOp = $this->m3EmitTuMainBlock->getOperand($op->arg1);
            if (!$nameOp instanceof Operand\Literal) {
                continue;
            }
            $classLc = strtolower(str_replace('/', '\\', ltrim($nameOp->value, '\\')));
            if ('phpcompiler\\runtime' !== $classLc || null === $op->block1) {
                continue;
            }
            $this->context->pushScope();
            $this->context->scope->classId = $this->context->type->object->lookup('PHPCompiler\\Runtime');
            $this->context->scope->className = $classLc;
            foreach ($op->block1->opCodes as $methodOp) {
                if (OpCode::TYPE_DECLARE_METHOD !== $methodOp->type) {
                    continue;
                }
                $methodOpName = $op->block1->getOperand($methodOp->arg1);
                if (!$methodOpName instanceof Operand\Literal || strtolower($methodOpName->value) !== $methodLc) {
                    continue;
                }
                if (null !== $methodOp->block1) {
                    $this->compileBlock($methodOp->block1, $logical);
                }
            }
            $this->context->popScope();

            return;
        }
    }

    /** NestedJIT M5TrivialEchoScript::parseAndCompile for gen-0 functional-smoke (#26756). */
    private function ensureM5TrivialEchoScriptParseAndCompileLowered(): void
    {
        if (!$this->shouldUseM5DriverHostCompile()) {
            return;
        }
        $logical = JIT\M5TrivialEchoScript::logicalName();
        $lc = strtolower($logical);
        if (isset($this->context->functions[$lc])) {
            return;
        }
        $path = __DIR__.'/JIT/M5TrivialEchoScript.php';
        if (!is_file($path)) {
            return;
        }
        try {
            $script = $this->context->runtime->parse((string) file_get_contents($path), $path);
        } catch (\Throwable $e) {
            return;
        }
        $savedClassId = $this->context->scope->classId;
        $savedClassName = $this->context->scope->className;
        $this->context->scope->classId = $this->context->type->object->lookup('PHPCompiler\\JIT\\M5TrivialEchoScript');
        $this->context->scope->className = 'phpcompiler\\jit\\m5trivialechoscript';
        foreach ($script->main->cfg->children as $child) {
            if (!$child instanceof Op\Stmt\Class_) {
                continue;
            }
            $className = $this->cfgOperandClassName($child->name);
            $classLc = null === $className
                ? null
                : strtolower(str_replace('/', '\\', ltrim($className, '\\')));
            if (null === $classLc || 'phpcompiler\\jit\\m5trivialechoscript' !== $classLc) {
                continue;
            }
            foreach ($child->stmts->children as $bodyChild) {
                if (!$bodyChild instanceof Op\Stmt\ClassMethod) {
                    continue;
                }
                if (strtolower($bodyChild->func->name) !== 'parseandcompile') {
                    continue;
                }
                if (null === $bodyChild->func->cfg) {
                    break;
                }
                $compiled = $this->context->runtime->compileFunc($logical, $bodyChild->func);
                if ($compiled instanceof CoreFunc\PHP) {
                    JIT\NestedJitCompileScope::run($this->context, function () use ($compiled, $logical): void {
                        $this->compileBlock($compiled->block, $logical);
                    });
                }
                $this->context->scope->classId = $savedClassId;
                $this->context->scope->className = $savedClassName;

                return;
            }
        }
        $this->context->scope->classId = $savedClassId;
        $this->context->scope->className = $savedClassName;
    }

    /** Lower Runtime::parse / compileEmitSmoke from lib/Runtime.php for inventory argv driver (#2967). */
    private function compileM3EmitTuRuntimeMethodFromRuntimePhpFile(string $methodLc, string $logical, string $lc): void
    {
        $runtimePath = __DIR__.'/Runtime.php';
        if (!is_file($runtimePath)) {
            return;
        }
        try {
            $script = $this->context->runtime->parse((string) file_get_contents($runtimePath), $runtimePath);
        } catch (\Throwable $e) {
            return;
        }
        foreach ($script->functions as $cfgFunc) {
            $funcLc = strtolower($cfgFunc->name);
            if ($funcLc !== $lc && $funcLc !== $methodLc && !str_ends_with($funcLc, '\\'.$methodLc)) {
                continue;
            }
            $compiled = $this->context->runtime->compileFunc($logical, $cfgFunc);
            if ($compiled instanceof CoreFunc\PHP) {
                // Isolate builder/block maps — host-lowering Runtime.php mid argv compile
                // otherwise leaves parentless instructions at module verify (#26756).
                JIT\NestedJitCompileScope::run($this->context, function () use ($compiled, $logical): void {
                    $this->compileBlock($compiled->block, $logical);
                });
            }

            return;
        }
        $savedClassId = $this->context->scope->classId;
        $savedClassName = $this->context->scope->className;
        $this->context->scope->classId = $this->context->type->object->lookup('PHPCompiler\\Runtime');
        $this->context->scope->className = 'phpcompiler\\runtime';
        foreach ($script->main->cfg->children as $child) {
            if (!$child instanceof Op\Stmt\Class_) {
                continue;
            }
            $className = $this->cfgOperandClassName($child->name);
            $classLc = null === $className
                ? null
                : strtolower(str_replace('/', '\\', ltrim($className, '\\')));
            if (null === $classLc || !in_array($classLc, ['phpcompiler\\runtime', 'runtime'], true)) {
                continue;
            }
            foreach ($child->stmts->children as $bodyChild) {
                if (!$bodyChild instanceof Op\Stmt\ClassMethod) {
                    continue;
                }
                if (strtolower($bodyChild->func->name) !== $methodLc) {
                    continue;
                }
                if (null === $bodyChild->func->cfg) {
                    break;
                }
                $compiled = $this->context->runtime->compileFunc($logical, $bodyChild->func);
                if ($compiled instanceof CoreFunc\PHP) {
                    JIT\NestedJitCompileScope::run($this->context, function () use ($compiled, $logical): void {
                        $this->compileBlock($compiled->block, $logical);
                    });
                }
                $this->context->scope->classId = $savedClassId;
                $this->context->scope->className = $savedClassName;

                return;
            }
        }
        $this->context->scope->classId = $savedClassId;
        $this->context->scope->className = $savedClassName;
    }

    private function cfgOperandClassName(Operand $operand): ?string
    {
        if ($operand instanceof Operand\Literal && is_string($operand->value)) {
            return $operand->value;
        }
        if ($operand instanceof Operand\Variable) {
            return $this->cfgOperandClassName($operand->name);
        }

        return null;
    }

    /**
     * Find Runtime methods on bundled declare_class blocks (private init* may be absent from queue).
     *
     * @param list<string> $methodLcs lowercase method names without class prefix
     */
    private function compileM3EmitTuRuntimeMethodFromDeclareClassBlocks(array $methodLcs): void
    {
        if (
            !$this->shouldUseM3EmitTuNativeBridge()
            && !$this->shouldRealLowerInventoryArgvParseSpine()
        ) {
            return;
        }
        $allowed = array_fill_keys($methodLcs, true);
        $blocks = [];
        if (null !== $this->m3CompileDriverMainBlock) {
            $blocks[] = $this->m3CompileDriverMainBlock;
        }
        if (null !== $this->m3EmitTuMainBlock) {
            $blocks[] = $this->m3EmitTuMainBlock;
        }
        foreach ($this->queue as $item) {
            $blocks[] = $item[1];
        }
        foreach ($blocks as $block) {
            if (null === $block) {
                continue;
            }
            foreach ($block->opCodes as $op) {
                if (OpCode::TYPE_DECLARE_CLASS !== $op->type) {
                    continue;
                }
                $nameOp = $block->getOperand($op->arg1);
                if (!$nameOp instanceof Operand\Literal) {
                    continue;
                }
                $classLc = strtolower(str_replace('/', '\\', ltrim($nameOp->value, '\\')));
                if ('phpcompiler\\runtime' !== $classLc || null === $op->block1) {
                    continue;
                }
                $this->context->pushScope();
                $this->context->scope->classId = $this->context->type->object->declareClass($nameOp);
                $this->context->scope->className = $classLc;
                foreach ($op->block1->opCodes as $methodOp) {
                    if (OpCode::TYPE_DECLARE_METHOD !== $methodOp->type) {
                        continue;
                    }
                    $methodOpName = $op->block1->getOperand($methodOp->arg1);
                    if (!$methodOpName instanceof Operand\Literal) {
                        continue;
                    }
                    $methodLc = strtolower($methodOpName->value);
                    if (!isset($allowed[$methodLc])) {
                        continue;
                    }
                    $logical = $classLc.'::'.$methodLc;
                    if (!isset($this->context->functions[strtolower($logical)])) {
                        $this->compileBlock($methodOp->block1, $logical);
                    }
                }
                $this->context->popScope();
            }
        }
    }

    /** Pre-lower Compiler::compile only; callees compile on demand (#1937). */
    private function compileM3EmitTuCompilerCompileDecl(): void
    {
        if (!$this->shouldUseM3EmitTuNativeBridge() || null === $this->m3EmitTuMainBlock) {
            return;
        }
        $logical = 'phpcompiler\\compiler::compile';
        if (isset($this->context->functions[$logical])) {
            return;
        }
        foreach ($this->m3EmitTuMainBlock->opCodes as $op) {
            if (OpCode::TYPE_DECLARE_CLASS !== $op->type) {
                continue;
            }
            $nameOp = $this->m3EmitTuMainBlock->getOperand($op->arg1);
            if (!$nameOp instanceof Operand\Literal) {
                continue;
            }
            $lc = strtolower(str_replace('/', '\\', ltrim($nameOp->value, '\\')));
            if ('phpcompiler\\compiler' !== $lc || null === $op->block1) {
                continue;
            }
            foreach ($op->block1->opCodes as $methodOp) {
                if (OpCode::TYPE_DECLARE_METHOD !== $methodOp->type) {
                    continue;
                }
                $this->context->pushScope();
                $this->context->scope->classId = $this->context->type->object->declareClass($nameOp);
                $this->context->scope->className = $lc;
                $this->context->popScope();

                return;
            }
        }
    }

    /** Emit TU: native compileEmitSmoke with PHPCfg property typing (#1937). */
    private function emitM3EmitTuCompilerCompileEmitSmokeNativeFunction(
        string $internalName,
        string $logical
    ): PHPLLVM\Value {
        $lc = strtolower($logical);
        if (isset($this->context->functions[$lc])) {
            return $this->context->functions[$lc];
        }
        $objPtr = $this->context->getTypeFromString('__object__*');
        $htPtr = $this->context->getTypeFromString('__hashtable__*');
        $func = $this->context->module->addFunction(
            $internalName,
            $this->context->context->functionType($objPtr, false, $objPtr, $objPtr)
        );
        $bb = $func->appendBasicBlock('entry');
        $saved = $this->context->builder;
        $this->context->builder = $this->context->context->builderCreate();
        $this->context->builder->positionAtEnd($bb);
        $this->context->builder->returnValue($objPtr->constNull());
        $this->context->builder->clearInsertionPosition();
        $this->context->builder = $saved;
        $this->context->functions[$lc] = $func;
        $this->context->functionReturnType[$lc] = '__object__*';
        $this->context->functionProxies[$lc] = new JIT\Call\Native(
            $func,
            $logical,
            [$objPtr, $objPtr],
            []
        );

        return $func;
    }

    /** Stub Compiler CFG helpers that crash LLVM 9 during self-host AOT (#816). */
    private function compileSkippedCompilerSplitCfgStub(string $internalName, Block $block, string $logicalName): PHPLLVM\Value
    {
        $lcname = strtolower($logicalName);
        if (isset($this->context->functions[$lcname])) {
            return $this->context->functions[$lcname];
        }
        if ($this->shouldUseM3EmitTuNativeBridge() && $this->isBootstrapM3RuntimeEmitBridgeName($lcname)) {
            return $this->compileBootstrapCompileSmokeM3EmitNative($internalName, $block, $logicalName);
        }
        if ($this->isM3CompileDriverCompilerNativeLoweringName($lcname)) {
            return JIT\CompilerOperandChainNative::compile(
                $this->context,
                $this->llvmInternalName($internalName),
                $block,
                $logicalName
            );
        }
        if ($this->shouldUseM3CompileDriverRealLowering() && JIT\VariableTypeMapNative::isNativeLoweringName($lcname)) {
            return JIT\VariableTypeMapNative::compile(
                $this->context,
                $this->llvmInternalName($internalName),
                $block,
                $logicalName
            );
        }
        if (JIT\OperandNameNative::isNativeLoweringName($lcname)) {
            return JIT\OperandNameNative::compile(
                $this->context,
                $this->llvmInternalName($internalName),
                $block,
                $logicalName
            );
        }
        if ($this->isM3CompileDriverCompilerPhpLoweringName($lcname)) {
            return $this->compileRuntimeSpinePhpLowering($internalName, $block, $logicalName);
        }
        $args = $this->normalizeSelfHostNativeCallArgTypes(
            $this->collectStubFunctionArgTypes($block),
            $logicalName
        );
        $callbackType = $this->cfgFunctionReturnCallbackType($block->func) ?? '__object__*';
        $returnType = $this->context->getTypeFromString($callbackType);
        $func = $this->context->module->addFunction(
            $this->llvmInternalName($internalName),
            $this->context->context->functionType($returnType, false, ...$args)
        );
        $bb = $func->appendBasicBlock('stub');
        $saved = $this->context->builder;
        $savedActive = $this->context->activeFunction;
        $this->context->builder = $this->context->context->builderCreate();
        $this->context->builder->positionAtEnd($bb);
        // Register before defaults: string defaults need entryAlloca/parentFunction (#21972).
        // Collect while insert is live — after clearInsertionPosition main may be null.
        $this->context->functions[$lcname] = $func;
        $this->context->activeFunction = $lcname;
        $defaultArgs = $this->collectParamDefaults($block);
        $this->emitSelfHostStubReturn($callbackType, $func);
        $this->context->builder->clearInsertionPosition();
        $this->context->builder = $saved;
        $this->context->activeFunction = $savedActive;
        $this->context->functionReturnType[$lcname] = $callbackType;
        $this->context->functionProxies[$lcname] = new JIT\Call\Native(
            $func,
            $logicalName,
            $args,
            $defaultArgs
        );
        if (null !== $logicalName) {
            JIT\NoDiscardCallGuard::registerCallee($this->context, $logicalName, $block);
            JIT\DeprecatedCallGuard::registerCallee($this->context, $logicalName, $block);
        }

        return $func;
    }

    public function compileSubBlock(
        PHPLLVM\Value $func,
        Block $block,
        Variable ...$args
    ): PHPLLVM\BasicBlock {
        $limit = $block->nOpCodes;
        if ($limit > 0 && OpCode::TYPE_JUMP === $block->opCodes[$limit - 1]->type) {
            --$limit;
        }

        return $this->compileBlockInternal($func, $block, $limit, null, 0, false, ...$args);
    }

    /**
     * Try-body lowering after catch dispatch may have seeded blockStorage (#4041 / #25841).
     *
     * @param list<Variable> $args
     */
    public function compileTrySubBlock(
        PHPLLVM\Value $func,
        Block $block,
        array $args
    ): PHPLLVM\BasicBlock {
        $limit = $block->nOpCodes;
        if ($limit > 0 && OpCode::TYPE_JUMP === $block->opCodes[$limit - 1]->type) {
            --$limit;
        }

        return $this->compileBlockInternal($func, $block, $limit, null, 0, true, ...$args);
    }

    /**
     * Lower a ?? / ??= arm at a pre-built entry BB after the test BB is sealed (#32880).
     *
     * Compiling arms before {@see Builder::branchIf} leaves the test BB open; NestedJIT /
     * {@see JIT\BasicBlockHelper::ensureOpenInsertBlock} can resume into it (often
     * {@code prop_value_done} after {@code new}) and plant a second terminator.
     *
     * @param list<Variable> $args
     */
    public function compileSubBlockAtEntry(
        PHPLLVM\Value $func,
        Block $block,
        PHPLLVM\BasicBlock $entryBlock,
        Variable ...$args
    ): PHPLLVM\BasicBlock {
        $limit = $block->nOpCodes;
        if ($limit > 0 && OpCode::TYPE_JUMP === $block->opCodes[$limit - 1]->type) {
            --$limit;
        }

        return $this->compileBlockInternal($func, $block, $limit, $entryBlock, 0, true, ...$args);
    }

    /**
     * Inline an included compilation unit at a dedicated entry block (issue #568 / MiniWebApp templates).
     */
    public function compileIncludedAtEntry(
        PHPLLVM\Value $func,
        Block $block,
        PHPLLVM\BasicBlock $entryBlock,
        ?int $opcodeLimit = null
    ): PHPLLVM\BasicBlock {
        $limit = $opcodeLimit ?? $this->includedAtEntryOpcodeLimit($block);

        $this->context->inlineIncludeExitBlock = null;
        $exit = $this->compileBlockInternal($func, $block, $limit, $entryBlock, 0, true);
        if (null !== $this->context->inlineIncludeExitBlock) {
            $exit = $this->context->inlineIncludeExitBlock;
        }
        JIT\BasicBlockHelper::ensureOpenInsertBlock($this->context, 'included_at_entry_cont');

        return $exit;
    }

    /**
     * Lower a catch arm at {@see TryCatchHelper::buildDispatch} match entry (#4041).
     *
     * Catch CFG blocks may already sit in blockStorage from an earlier partial compile;
     * force re-lowering at the dispatch match BB and skip the trailing merge JUMP
     * (TryCatchHelper branches to merge after the arm body).
     *
     * @param list<Variable> $args
     */
    public function compileCatchArmAtEntry(
        PHPLLVM\Value $func,
        Block $block,
        PHPLLVM\BasicBlock $entryBlock,
        Variable ...$args
    ): PHPLLVM\BasicBlock {
        $limit = $block->nOpCodes;
        if ($limit > 0 && OpCode::TYPE_JUMP === $block->opCodes[$limit - 1]->type) {
            --$limit;
        }

        $this->context->inlineIncludeExitBlock = null;
        // Catch arms fall through to try-merge; suppress the void-main trailing
        // returnVoid() that would skip AFTER (#23641).
        $savedSynthetic = $block->syntheticCfgBranch;
        $block->syntheticCfgBranch = true;
        try {
            $exit = $this->compileBlockInternal($func, $block, $limit, $entryBlock, 0, true, ...$args);
        } finally {
            $block->syntheticCfgBranch = $savedSynthetic;
        }
        if (null !== $this->context->inlineIncludeExitBlock) {
            $exit = $this->context->inlineIncludeExitBlock;
        }

        return $exit;
    }

    /**
     * Lower a finally CFG arm at entry (#4246).
     *
     * finallyBbFor pre-seeds blockStorage[finally] before calling here; without
     * allowRecompile the body is skipped and finally is an empty fall-through (#24105).
     * syntheticCfgBranch suppresses void-main returnVoid so the epilogue edge remains.
     *
     * @param list<Variable> $args
     */
    public function compileFinallyAtEntry(
        PHPLLVM\Value $func,
        Block $block,
        PHPLLVM\BasicBlock $entryBlock,
        Variable ...$args
    ): PHPLLVM\BasicBlock {
        $limit = $block->nOpCodes;
        if ($limit > 0 && OpCode::TYPE_JUMP === $block->opCodes[$limit - 1]->type) {
            --$limit;
        }

        $this->context->inlineIncludeExitBlock = null;
        // Mirror compileCatchArmAtEntry (#23641 / #24105): re-lower at the pinned
        // finally BB and keep the tail open for TryCatchHelper's epilogue branch.
        $savedSynthetic = $block->syntheticCfgBranch;
        $block->syntheticCfgBranch = true;
        try {
            $exit = $this->compileBlockInternal($func, $block, $limit, $entryBlock, 0, true, ...$args);
        } finally {
            $block->syntheticCfgBranch = $savedSynthetic;
        }
        if (null !== $this->context->inlineIncludeExitBlock) {
            $exit = $this->context->inlineIncludeExitBlock;
        }
        JIT\BasicBlockHelper::ensureOpenInsertBlock($this->context, 'finally_at_entry_cont');

        return $exit;
    }

    /** Resume LLVM return after return-through-finally (#4246). */
    public function emitPendingReturnResume(PHPLLVM\Value $func): void
    {
        JIT\Builtin\JitReturnPending::registerDeclarations($this->context);
        JIT\Builtin\JitReturnPending::ensureLinked($this->context);
        $builder = $this->context->builder;
        $i32 = $this->context->getTypeFromString('int32');
        $isVoid = $builder->call($this->context->lookupFunction('phpc_jit_return_pending_is_void'));
        $isVoidBool = $builder->icmp(PHPLLVM\Builder::INT_NE, $isVoid, $i32->constInt(0, false));
        $voidBb = $func->appendBasicBlock('pending_return_void');
        $valueBb = $func->appendBasicBlock('pending_return_value');
        $builder->branchIf($isVoidBool, $voidBb, $valueBb);
        $builder->positionAtEnd($voidBb);
        if ($this->isVoidLlvmFunction($func)) {
            $builder->returnVoid();
        } else {
            $builder->returnValue($this->defaultLlvmReturnValue($func));
        }
        $builder->positionAtEnd($valueBb);
        $valuePtr = $builder->call($this->context->lookupFunction('phpc_jit_take_return_pending'));
        if ($this->isVoidLlvmFunction($func)) {
            $builder->returnVoid();
        } else {
            $expected = null;
            if (null !== $this->context->activeFunction) {
                $expected = $this->context->functionReturnType[$this->context->activeFunction] ?? null;
            }
            // Prefer the LLVM return type: untyped PHP functions return __value__ even when
            // functionReturnType is unset — defaulting to readLong corrupted null/bool/float
            // pending returns after finally (#24105).
            $llvmRet = null;
            $sig = JIT\BasicBlockHelper::llvmFunctionSignatureType($func);
            if (null !== $sig) {
                $llvmRet = $this->context->getStringFromType($sig->getReturnType());
            }
            if ('__value__' === $llvmRet || '__value__' === $expected) {
                $retval = $builder->load($valuePtr);
            } else {
                $retval = $this->loadPendingReturnValue($valuePtr, $expected ?? $llvmRet);
                $retval = $this->alignRetvalToLlvmFnReturn($retval, $func);
            }
            $builder->returnValue($retval);
        }
    }

    private function loadPendingReturnValue(PHPLLVM\Value $valuePtr, ?string $expectedReturn): PHPLLVM\Value
    {
        if ('__value__' === $expectedReturn) {
            return $this->context->builder->load($valuePtr);
        }
        $read = match ($expectedReturn) {
            'string', '__string__*' => '__value__readString',
            'double' => '__value__readDouble',
            'bool', 'int1' => '__value__readLong',
            '__object__*' => '__value__readObject',
            '__hashtable__*' => '__value__readHashtable',
            default => '__value__readLong',
        };
        $loaded = $this->context->builder->call($this->context->lookupFunction($read), $valuePtr);
        if ('bool' === $expectedReturn || 'int1' === $expectedReturn) {
            return $this->context->builder->truncOrBitCast(
                $loaded,
                $this->context->getTypeFromString('int1')
            );
        }

        return $loaded;
    }

    /**
     * Opcode limit for compileIncludedAtEntry: skip redundant try-entry JUMP only (#2084).
     */
    private function includedAtEntryOpcodeLimit(Block $block): int
    {
        $limit = $block->nOpCodes;
        while ($limit > 0 && !isset($block->opCodes[$limit - 1])) {
            --$limit;
        }
        if ($limit > 0 && OpCode::TYPE_JUMP === $block->opCodes[$limit - 1]->type) {
            $jump = $block->opCodes[$limit - 1];
            if (null !== $jump->block1 && $this->isRedundantTryEntryJump($block, $jump->block1)) {
                --$limit;
            }
        }

        return $limit;
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

    /**
     * Compile opcodes before yield from to evaluate the container (e.g. inner() call).
     *
     * @return JIT\Variable container variable for yield from
     */
    public function compileGeneratorYieldFromSetup(
        \PHPLLVM\Value\Function_ $func,
        Block $block,
        \PHPLLVM\BasicBlock $entryBlock,
        OpCode $yieldFromOp,
        ?string $innerResumeName = null,
        int $prefixStart = 0
    ): JIT\Variable {
        $yfIdx = null;
        foreach ($block->opCodes as $i => $op) {
            if ($op === $yieldFromOp) {
                $yfIdx = $i;
                break;
            }
        }
        if (null === $yfIdx) {
            throw new \LogicException('yield from opcode not found in generator block');
        }
        if (
            $this->generatorYieldFromPrefixNeedsCompile($block, $yfIdx, $innerResumeName, $prefixStart)
        ) {
            $savedStorage = $this->context->scope->blockStorage;
            $this->context->scope->blockStorage = new \SplObjectStorage();
            $exit = $this->compileGeneratorResumePrefix($func, $block, $prefixStart, $yfIdx, $entryBlock);
            $this->context->builder->positionAtEnd($exit);
            $this->context->scope->blockStorage = $savedStorage;
        }
        if (null === $yieldFromOp->arg2) {
            throw new \LogicException('yield from missing container operand');
        }

        return $this->context->getVariableFromOp($block->getOperand($yieldFromOp->arg2));
    }

    /**
     * Compile prefix opcodes before yield from when the container is not yet materialized (#3074).
     * Includes inline array literals (INIT_ARRAY) and dynamic containers (call/assign).
     */
    private function generatorYieldFromPrefixNeedsCompile(
        Block $block,
        int $yfIdx,
        ?string $innerResumeName,
        int $prefixStart = 0
    ): bool {
        if (null !== $innerResumeName) {
            return true;
        }
        if (
            $yfIdx <= $prefixStart
            || !JIT\GeneratorHelper::prefixSegmentSafeForYieldFromInit($block, $prefixStart, $yfIdx)
        ) {
            return false;
        }

        return true;
    }

    /**
     * Compile opcodes in [$startIndex, $limit) for generator resume prefix segments (#3074).
     */
    public function compileGeneratorResumePrefix(
        PHPLLVM\Value\Function_ $func,
        Block $block,
        int $startIndex,
        int $limit,
        PHPLLVM\BasicBlock $entryBlock
    ): PHPLLVM\BasicBlock {
        return $this->compileBlockInternal(
            $func,
            $block,
            $limit,
            $entryBlock,
            $startIndex,
            true
        );
    }

    /**
     * Dest operands for guarded list() / [] destruct in this CFG block (#4531).
     *
     * @return list<Operand>
     */
    private function listUnpackAssignTargetsInBlock(Block $block): array
    {
        $targets = [];
        $seen = new \SplObjectStorage();
        foreach ($block->opCodes as $op) {
            if (OpCode::TYPE_ASSIGN !== $op->type) {
                continue;
            }
            if (null === $op->arg2) {
                continue;
            }
            $dest = $block->getOperand($op->arg2);
            $name = JIT\OperandName::resolve($dest);
            if (null === $name || '' === $name) {
                continue;
            }
            if ($seen->contains($dest)) {
                continue;
            }
            $seen[$dest] = true;
            $targets[] = $dest;
        }

        return $targets;
    }

    /** `return $c ? $a : $b` nullable arm — direct return avoids AOT merge-slot segfault (#8555). */

    /** @return bool false when class return TypeError was emitted (skip ret) */
    private function emitJitClassReturnTypeCheck(Block $block, Variable $return): bool
    {
        return JIT\ClassReturnCheck::enforce($this->context, $block, $return);
    }

    /**
     * Scalar `: string`/`: int`/… return enforce under strict_types, weak coerce otherwise (#26427).
     *
     * @return bool false when TypeError was emitted (skip ret)
     */
    private function emitJitScalarReturnTypeCheck(Block $block, Variable &$return): bool
    {
        return JIT\ScalarReturnCheck::enforce($this->context, $block, $return);
    }





    /**
     * CFG typed a call result as object (or object|null / object|false) while the LLVM
     * ABI may still return a boxed __value__* (#34019, #34024).
     *
     * @param-out string $className
     */
    private function callResultCfgWantsObject(Operand $result, ?string &$className = null): bool
    {
        $className = 'object';
        if ($this->context->hasVariableOp($result)) {
            $prior = $this->context->getVariableFromOp($result);
            if (Variable::TYPE_OBJECT === $prior->type) {
                $tagged = $prior->classUserType ?? null;
                if (is_string($tagged) && '' !== $tagged) {
                    $className = $tagged;
                } elseif (null !== $result->type && is_string($result->type->userType ?? null) && '' !== $result->type->userType) {
                    $className = $result->type->userType;
                }

                return true;
            }
        }
        $type = $result->type;
        if (null === $type) {
            return false;
        }
        if (Type::TYPE_OBJECT === $type->type) {
            if (is_string($type->userType ?? null) && '' !== $type->userType) {
                $className = $type->userType;
            }

            return true;
        }
        if (Type::TYPE_UNION !== $type->type || [] === ($type->subTypes ?? [])) {
            return false;
        }
        foreach ($type->subTypes as $sub) {
            if (Type::TYPE_OBJECT !== $sub->type) {
                continue;
            }
            if (is_string($sub->userType ?? null) && '' !== $sub->userType) {
                $className = $sub->userType;
            }

            return true;
        }

        return false;
    }

    /**
     * Pin call SSA results in the open insert block after callee CFG splits (#18052).
     *
     * Bool-return instance methods (e.g. DOMDocument::loadHTML) branch to fresh
     * continuations; boxed __value__* results from the next call can be unreachable
     * for assignOperandValue unless copied on-stack in the current block.
     *
     * Runtime-bridge bool builtins (stream_supports_lock, file_exists) return i1 after
     * NestedJitCompileScope helper linking; pin those too so ?: echo / JUMPIF still see
     * a dominated value (#19459).
     */
    private function materializeCallResultReachable(PHPLLVM\Value $llvmResult): PHPLLVM\Value
    {
        $ty = $this->context->getStringFromType($llvmResult->typeOf());
        if ('__object__*' === $ty) {
            JIT\BasicBlockHelper::ensureOpenInsertBlock($this->context, 'call_result_obj_reach_cont');
            $objSlot = JIT\BasicBlockHelper::entryAlloca($this->context, $this->context->getTypeFromString('__object__*'));
            $this->context->builder->store($llvmResult, $objSlot);

            return $this->context->builder->load($objSlot);
        }
        if ('int1' === $ty || 'bool' === $ty) {
            JIT\BasicBlockHelper::ensureOpenInsertBlock($this->context, 'call_result_i1_reach_cont');
            $i1Slot = JIT\BasicBlockHelper::entryAlloca(
                $this->context,
                $this->context->getTypeFromString('int1')
            );
            $this->context->builder->store($llvmResult, $i1Slot);

            return $this->context->builder->load($i1Slot);
        }
        if ('__value__*' !== $ty) {
            return $llvmResult;
        }
        JIT\BasicBlockHelper::ensureOpenInsertBlock($this->context, 'call_result_reach_cont');
        $slot = JIT\BasicBlockHelper::entryAlloca($this->context, $this->context->getTypeFromString('__value__*'));
        $this->context->builder->store(
            JIT\JitValueBox::normalizeValuePtr($this->context, $llvmResult),
            $slot
        );

        return $this->context->builder->load($slot);
    }

    /**
     * True when the current call returns a freshly allocated `__string__*` the caller owns
     * (str_repeat / NestedJIT StrRepeat helper / user `: string` returns). Borrowed
     * `__string__*` results must stay KIND_VALUE so freeDeadVariables is a no-op (#36388).
     */
    private function callResultOwnsFreshString(): bool
    {
        $toCall = $this->context->scope->toCall;
        if ($toCall instanceof CoreFunc\Internal) {
            return $this->isOwningStringInternalName($toCall->getName());
        }
        if ($toCall instanceof JIT\Call\Native) {
            $name = strtolower($toCall->name);
            if ($this->isOwningStringInternalName($name)) {
                return true;
            }
            if (str_contains($name, 'strrepeat')) {
                return true;
            }
            // User / NestedJIT PHP with declared string return — ZEND_RETURN transfers ownership.
            $ret = $this->context->functionReturnType[$name] ?? null;
            if ('__string__*' !== $ret) {
                return false;
            }
            // Builtin/reflection proxies also advertise __string__*; only treat names that
            // were compiled as user funcs (present in functionReturnType from analyzeFunc).
            // Heuristic: exclude dotted LLVM mangles and Reflection* / known runtime helpers.
            if (str_contains($name, 'reflection') || str_starts_with($name, '__')) {
                return false;
            }
            if (str_contains($name, '.') && !str_contains($name, '\\')) {
                return false;
            }

            return true;
        }

        return false;
    }

    private function isOwningStringInternalName(string $name): bool
    {
        static $owning = [
            'str_repeat' => true,
            'str_pad' => true,
        ];

        return isset($owning[strtolower($name)]);
    }

    private function assignCallResultOperand(Operand $result, PHPLLVM\Value $llvmResult, bool $returnsByRef): void
    {
        if ('void' === $this->context->getStringFromType($llvmResult->typeOf())) {
            return;
        }
        if (!$returnsByRef) {
            // Void JIT __construct proxies return null __value__*; never materialize that onto
            // the EXEC_RETURN operand — it shares the `new` temp (#23641). When assignOperand
            // boxed the temp to TYPE_VALUE, the old TYPE_OBJECT-only guard missed it and typed
            // property stores kept an empty object shell (#35752).
            if ($this->isVoidJitConstructCallThatDiscardsExecReturn($this->context->scope->toCall)) {
                if ($this->context->hasVariableOp($result)) {
                    $prior = $this->context->getVariableFromOp($result);
                    if (
                        Variable::TYPE_OBJECT === $prior->type
                        || Variable::TYPE_VALUE === $prior->type
                    ) {
                        $this->markNewObjectConstructedAfterCall(
                            $this->context->scope->toCall,
                            $this->context->scope->args
                        );
                        if ($this->context->scope->toCall instanceof JIT\Call\BcMathNumberConstruct) {
                            $thisArg = $this->context->scope->args[0] ?? null;
                            $ct = ($thisArg instanceof Variable)
                                ? $thisArg->compileTimeBcmathNumber
                                : null;
                            if (null !== $ct) {
                                $prior->compileTimeBcmathNumber = $ct;
                                $name = JIT\OperandName::resolve($result);
                                if (null !== $name && '' !== $name) {
                                    $resolved = $this->context->resolveRefAliasName($name);
                                    if (isset($this->context->namedVariableBindings[$resolved])) {
                                        $this->context->namedVariableBindings[$resolved]
                                            ->compileTimeBcmathNumber = $ct;
                                    }
                                    $this->context->bindVariableByName($resolved, $prior);
                                    $prior->compileTimeBcmathNumber = $ct;
                                }
                            }
                        }

                        return;
                    }
                }

                return;
            }
            // FUNCCALL_EXEC_RETURN must materialize even when php-cfg dropped result usages
            // (nested f(g()) arg temps — strlen(trim($s)), #8561).
            $llvmTy = $this->context->getStringFromType($llvmResult->typeOf());
            if (
                $this->context->hasVariableOp($result)
                && ('__value__*' === $llvmTy || '__value__' === $llvmTy)
            ) {
                $prior = $this->context->getVariableFromOp($result);
                if (Variable::TYPE_OBJECT === $prior->type) {
                    // Legacy path: void __construct on an unboxed TYPE_OBJECT `new` temp.
                    if ($this->isVoidJitConstructCall($this->context->scope->toCall)) {
                        if (
                            $this->context->scope->toCall instanceof JIT\Call\DateTimeConstruct
                            || $this->context->scope->toCall instanceof JIT\Call\DateTimeImmutableConstruct
                        ) {
                            // JitDateTimeConstruct returns an initialized __value__* box (#35752).
                            // Drop the empty New_ shell and assign the box below (#35802).
                        } else {
                            $this->markNewObjectConstructedAfterCall(
                                $this->context->scope->toCall,
                                $this->context->scope->args
                            );
                            if ($this->context->scope->toCall instanceof JIT\Call\BcMathNumberConstruct) {
                                $thisArg = $this->context->scope->args[0] ?? null;
                                $ct = ($thisArg instanceof Variable)
                                    ? $thisArg->compileTimeBcmathNumber
                                    : null;
                                if (null !== $ct) {
                                    $prior->compileTimeBcmathNumber = $ct;
                                    $name = JIT\OperandName::resolve($result);
                                    if (null !== $name && '' !== $name) {
                                        $resolved = $this->context->resolveRefAliasName($name);
                                        if (isset($this->context->namedVariableBindings[$resolved])) {
                                            $this->context->namedVariableBindings[$resolved]
                                                ->compileTimeBcmathNumber = $ct;
                                        }
                                        $this->context->bindVariableByName($resolved, $prior);
                                        $prior->compileTimeBcmathNumber = $ct;
                                    }
                                }
                            }

                            return;
                        }
                    }
                    // Inline f(); g() must not inherit object-typed operand slots (#18052).
                    $prior->free();
                    unset($this->context->scope->variables[$result]);
                }
            }
            $llvmResult = $this->materializeCallResultReachable($llvmResult);
            JIT\BasicBlockHelper::ensureOpenInsertBlock($this->context, 'call_assign_cont');
            $llvmTy = $this->context->getStringFromType($llvmResult->typeOf());
            if ('int1' === $llvmTy || 'bool' === $llvmTy) {
                // Keep runtime-bridge i1 results in an entry alloca (KIND_VARIABLE) so
                // JUMPIF / ?: literal-echo redirect can reload after CFG splits (#19459).
                if ($this->context->hasVariableOp($result)) {
                    $this->context->getVariableFromOp($result)->free();
                }
                $i1Slot = JIT\BasicBlockHelper::entryAlloca(
                    $this->context,
                    $this->context->getTypeFromString('int1')
                );
                $this->context->builder->store($llvmResult, $i1Slot);
                $this->context->setVariableOp(
                    $result,
                    new Variable(
                        $this->context,
                        Variable::TYPE_NATIVE_BOOL,
                        Variable::KIND_VARIABLE,
                        $i1Slot
                    )
                );

                return;
            }
            // Owning `__string__*` from known allocators / typed :string returns only.
            // Promoting every `__string__*` call (borrowed getters, shared returns) made
            // freeDeadVariables delref live strings — MiniWebApp SIGSEGV (#36388).
            // php-src: Zend/zend_execute.c ZEND_ASSIGN of IS_STRING return values.
            if ('__string__*' === $llvmTy && $this->callResultOwnsFreshString()) {
                if ($this->context->hasVariableOp($result)) {
                    $this->context->getVariableFromOp($result)->free();
                }
                $strSlot = JIT\BasicBlockHelper::entryAlloca(
                    $this->context,
                    $this->context->getTypeFromString('__string__*')
                );
                $this->context->builder->store($llvmResult, $strSlot);
                $strVar = new Variable(
                    $this->context,
                    Variable::TYPE_STRING,
                    Variable::KIND_VARIABLE,
                    $strSlot
                );
                // Unnamed FUNCCALL result temps must still delref on freeDeadVariables /
                // ASSIGN move — mark ephemeral so Variable::free() always delrefs (#36388).
                $strVar->ephemeralStringTemp = true;
                $this->context->setVariableOp($result, $strVar);
                $name = JIT\OperandName::resolve($result);
                if (null !== $name && '' !== $name) {
                    $resolved = $this->context->resolveRefAliasName($name);
                    $this->context->bindVariableByName($resolved, $strVar);
                }

                return;
            }
            if ($this->context->scope->toCall instanceof JIT\Call\NestedClosureInvoke) {
                $llvmTy = $this->context->getStringFromType($llvmResult->typeOf());
                if ('__value__*' === $llvmTy || '__value__' === $llvmTy || JIT\JitNestedHelperCoerce::isValueBox($this->context, $llvmResult)) {
                    $ptr = JIT\JitNestedHelperCoerce::valueBoxPtrFromHelperResult($this->context, $llvmResult);
                    if ($this->context->hasVariableOp($result)) {
                        $this->context->getVariableFromOp($result)->free();
                    }
                    $slot = JIT\JitValueBox::alloc($this->context);
                    JIT\JitValueBox::copyFromPointer($this->context, $slot, $ptr);
                    $this->context->setVariableOp(
                        $result,
                        new Variable(
                            $this->context,
                            Variable::TYPE_VALUE,
                            Variable::KIND_VARIABLE,
                            $slot
                        )
                    );

                    return;
                }
                $this->assignOperandValue($result, $llvmResult, true);

                return;
            }
            // HashTable::iterate() returns the receiver HT for IteratorHelper foreach.
            // CFG often types the Traversable temp as PHPCompiler\VM\Variable (element
            // type leak); keep TYPE_HASHTABLE so ObjectPropertyForeach does not win (#27226).
            if (
                $this->context->scope->toCall instanceof JIT\Call\HashTableIterate
                && '__hashtable__*' === $llvmTy
            ) {
                if ($this->context->hasVariableOp($result)) {
                    $this->context->getVariableFromOp($result)->free();
                }
                $this->context->setVariableOp(
                    $result,
                    new Variable(
                        $this->context,
                        Variable::TYPE_HASHTABLE,
                        Variable::KIND_VALUE,
                        $llvmResult
                    )
                );

                return;
            }
            // WeakReference::get() returns an owning __value__ box. Promote to an entry
            // alloca KIND_VARIABLE so freeDeadVariables at ternary/branch edges can
            // valueDelref (KIND_VALUE free is a no-op and would keep the referent) (#27118).
            if ($this->context->scope->toCall instanceof JIT\Call\WeakReferenceGet) {
                $ptr = JIT\JitValueBox::coerceToValuePtrForStore($this->context, $llvmResult);
                if ($this->context->hasVariableOp($result)) {
                    $this->context->getVariableFromOp($result)->free();
                }
                $slot = JIT\JitValueBox::alloc($this->context);
                JIT\JitValueBox::copyFromPointer($this->context, $slot, $ptr);
                // Drop the call-local owning box; the entry alloca holds the strong ref.
                $this->context->builder->call(
                    $this->context->lookupFunction('__value__writeNull'),
                    $ptr
                );
                $resultVar = new Variable(
                    $this->context,
                    Variable::TYPE_VALUE,
                    Variable::KIND_VARIABLE,
                    $slot
                );
                $this->context->setVariableOp($result, $resultVar);
                $this->context->pendingWeakReferenceGetResult = $result;

                return;
            }
            if ($this->context->scope->toCall instanceof JIT\Call\WeakReferenceCreate) {
                $this->assignOperandValue($result, $llvmResult, true);
                if ($this->context->hasVariableOp($result)) {
                    $this->context->getVariableFromOp($result)->classUserType = 'WeakReference';
                }

                return;
            }
            // XMLReader::XML()/fromString() — CFG types XML() as bool (InternalArgInfo) but the
            // static factory returns a __value__ object box. Force VALUE storage + classUserType
            // so ASSIGN/$reader->nodeType do not take the non-object property path (#28670).
            // Instance XML() returns i1 bool after resetting $this — skip (#35106).
            if (
                (
                    $this->context->scope->toCall instanceof JIT\Call\XmlReaderXML
                    || $this->context->scope->toCall instanceof JIT\Call\XmlReaderFromString
                    || $this->context->scope->toCall instanceof JIT\Call\XmlReaderFromUri
                    || $this->context->scope->toCall instanceof JIT\Call\XmlReaderFromStream
                    || $this->context->scope->toCall instanceof JIT\Call\XmlReaderOpen
                )
                && !(
                    (
                        $this->context->scope->toCall instanceof JIT\Call\XmlReaderXML
                        || $this->context->scope->toCall instanceof JIT\Call\XmlReaderOpen
                    )
                    && !$this->context->extensionLowering->xmlReaderFactoryIsObject()
                )
            ) {
                $ptr = JIT\JitValueBox::coerceToValuePtrForStore($this->context, $llvmResult);
                if ($this->context->hasVariableOp($result)) {
                    $this->context->getVariableFromOp($result)->free();
                }
                $slot = JIT\JitValueBox::alloc($this->context);
                JIT\JitValueBox::copyFromPointer($this->context, $slot, $ptr);
                $resultVar = new Variable(
                    $this->context,
                    Variable::TYPE_VALUE,
                    Variable::KIND_VARIABLE,
                    $slot
                );
                $resultVar->classUserType = 'XMLReader';
                $this->context->setVariableOp($result, $resultVar);
                $result->type = new Type(Type::TYPE_OBJECT, [], 'XMLReader');
                $name = JIT\OperandName::resolve($result);
                if (null !== $name && '' !== $name) {
                    $resolved = $this->context->resolveRefAliasName($name);
                    $this->context->bindVariableByName($resolved, $resultVar);
                }

                return;
            }
            if (
                $this->context->scope->toCall instanceof JIT\Call\XmlWriterToMemory
                || $this->context->scope->toCall instanceof JIT\Call\XmlWriterToUri
                || $this->context->scope->toCall instanceof JIT\Call\XmlWriterToStream
            ) {
                $ptr = JIT\JitValueBox::coerceToValuePtrForStore($this->context, $llvmResult);
                if ($this->context->hasVariableOp($result)) {
                    $this->context->getVariableFromOp($result)->free();
                }
                $slot = JIT\JitValueBox::alloc($this->context);
                JIT\JitValueBox::copyFromPointer($this->context, $slot, $ptr);
                $resultVar = new Variable(
                    $this->context,
                    Variable::TYPE_VALUE,
                    Variable::KIND_VARIABLE,
                    $slot
                );
                $resultVar->classUserType = 'XMLWriter';
                $this->context->extensionLowering->bindXmlWriterResult($resultVar);
                $this->context->setVariableOp($result, $resultVar);
                $result->type = new Type(Type::TYPE_OBJECT, [], 'XMLWriter');
                $name = JIT\OperandName::resolve($result);
                if (null !== $name && '' !== $name) {
                    $resolved = $this->context->resolveRefAliasName($name);
                    $this->context->bindVariableByName($resolved, $resultVar);
                }

                return;
            }
            // DOMElement::removeAttributeNode() — InternalArgInfo still says bool (PHP 5 era)
            // until php-types-dom-removeattributenode-return.patch applies. Force VALUE +
            // DOMAttr so `$removed->name` is not the non-object property path (#32707).
            if ($this->context->scope->toCall instanceof JIT\Call\DomElementRemoveAttributeNode) {
                $ptr = JIT\JitValueBox::coerceToValuePtrForStore($this->context, $llvmResult);
                if ($this->context->hasVariableOp($result)) {
                    $this->context->getVariableFromOp($result)->free();
                }
                $slot = JIT\JitValueBox::alloc($this->context);
                JIT\JitValueBox::copyFromPointer($this->context, $slot, $ptr);
                $resultVar = new Variable(
                    $this->context,
                    Variable::TYPE_VALUE,
                    Variable::KIND_VARIABLE,
                    $slot
                );
                $resultVar->classUserType = 'DOMAttr';
                $this->context->setVariableOp($result, $resultVar);
                $result->type = new Type(Type::TYPE_OBJECT, [], 'DOMAttr');
                $name = JIT\OperandName::resolve($result);
                if (null !== $name && '' !== $name) {
                    $resolved = $this->context->resolveRefAliasName($name);
                    $this->context->bindVariableByName($resolved, $resultVar);
                }

                return;
            }
            // Call ABI returns __value__* / __value__ while CFG typed the result as an
            // object (or object|null / object|false union). Inline `$call()?->prop` then
            // kept TYPE_OBJECT storage, so nullsafe skipped the value-box short-circuit and
            // property-fetch GEPed the box (empty / SIGSEGV). Force TYPE_VALUE + classUserType
            // for all such calls — not a per-Call whitelist (#34019 getElementById; #34024
            // cloneNode / createElement / importNode / appendChild; peer #32707).
            $objectClassName = null;
            if (
                ('__value__*' === $llvmTy || '__value__' === $llvmTy)
                && $this->callResultCfgWantsObject($result, $objectClassName)
            ) {
                $ptr = JIT\JitValueBox::coerceToValuePtrForStore($this->context, $llvmResult);
                if ($this->context->hasVariableOp($result)) {
                    $this->context->getVariableFromOp($result)->free();
                }
                $slot = JIT\JitValueBox::alloc($this->context);
                JIT\JitValueBox::copyFromPointer($this->context, $slot, $ptr);
                $resultVar = new Variable(
                    $this->context,
                    Variable::TYPE_VALUE,
                    Variable::KIND_VARIABLE,
                    $slot
                );
                $resultVar->classUserType = $objectClassName ?? 'object';
                $this->context->setVariableOp($result, $resultVar);
                $result->type = new Type(Type::TYPE_OBJECT, [], $objectClassName ?? 'object');
                $name = JIT\OperandName::resolve($result);
                if (null !== $name && '' !== $name) {
                    $resolved = $this->context->resolveRefAliasName($name);
                    $this->context->bindVariableByName($resolved, $resultVar);
                }
                JIT\BasicBlockHelper::ensureOpenInsertBlock($this->context, 'call_value_box_object_post_assign');

                return;
            }
            if (
                $this->context->hasVariableOp($result)
                && ('__value__*' === $llvmTy || '__value__' === $llvmTy)
                && $this->context->scope->toCall instanceof CoreFunc\Internal
            ) {
                $prior = $this->context->getVariableFromOp($result);
                if (Variable::TYPE_VALUE !== $prior->type) {
                    $ptr = JIT\JitValueBox::coerceToValuePtrForStore($this->context, $llvmResult);
                    $prior->free();
                    $slot = JIT\JitValueBox::alloc($this->context);
                    JIT\JitValueBox::copyFromPointer($this->context, $slot, $ptr);
                    $this->context->setVariableOp(
                        $result,
                        new Variable(
                            $this->context,
                            Variable::TYPE_VALUE,
                            Variable::KIND_VARIABLE,
                            $slot
                        )
                    );

                    return;
                }
            }
            $this->assignOperandValue($result, $llvmResult, true);

            return;
        }
        // By-ref FUNCCALL_EXEC_RETURN must materialize even when php-cfg dropped result
        // usages — otherwise ARG_SEND / var_dump(f()) dumps a fresh null box while the
        // call's __value__* is dead (#34717; peer by-value path #8561).
        $ptr = '__value__*' === $this->context->getStringFromType($llvmResult->typeOf())
            ? JIT\JitValueBox::normalizeValuePtr($this->context, $llvmResult)
            : JIT\JitValueBox::coerceToValuePtrForStore($this->context, $llvmResult);
        if ($this->context->hasVariableOp($result)) {
            $this->context->getVariableFromOp($result)->free();
        }
        $refVar = new Variable(
            $this->context,
            Variable::TYPE_VALUE,
            Variable::KIND_VALUE,
            $ptr
        );
        $refVar->valueBoxAliasPtr = $ptr;
        $refVar->assignRefLvalueAlias = true;
        $refVar->addref();
        $this->context->setVariableOp($result, $refVar);
        $name = JIT\OperandName::resolve($result);
        if (null !== $name && '' !== $name) {
            $resolved = $this->context->resolveRefAliasName($name);
            $this->context->bindVariableByName($resolved, $refVar);
        }
    }

    /**
     * LLVM return type tag for a CFG function (must match compileBlock() signature lowering).
     */
    private function cfgFunctionReturnCallbackType(?\PHPCfg\Func $cfgFunc): ?string
    {
        if (null === $cfgFunc) {
            return null;
        }
        if ('__construct' === strtolower($cfgFunc->name)) {
            return 'void';
        }
        if ('__destruct' === strtolower($cfgFunc->name)) {
            return 'void';
        }
        // Literal `void`/`never` must win before rawTypeFromCfgReturn: Type::fromDecl('void')
        // is TYPE_NULL, and callbackTypeFromPhptype(TYPE_NULL) yields `__value__` — that wrongly
        // adds an sret slot and shifts every PHP arg (breaks MultipartNative etc., #5965).
        if ($cfgFunc->returnType instanceof Op\Type\Literal) {
            $lit = strtolower($cfgFunc->returnType->name);
            if ('void' === $lit || 'never' === $lit) {
                return 'void';
            }
            // Bare `: iterable` is Traversable|array — boxed `__value__` ABI, not class
            // `__object__*` (Type::fromDecl maps the name to TYPE_OBJECT, #29888).
            if ('iterable' === $lit) {
                return '__value__';
            }
        }
        if ($cfgFunc->returnType instanceof Op\Type\Void_) {
            return 'void';
        }
        if ($cfgFunc->returnType instanceof Op\Type\Never_) {
            return 'void';
        }
        if ($cfgFunc->returnType instanceof Op\Type\Nullable) {
            $rawReturn = $this->rawTypeFromCfgReturn($cfgFunc->returnType->subtype);
            if (null !== $rawReturn) {
                $callback = $this->callbackTypeFromPhptype($rawReturn);
                if (null !== $callback) {
                    // Nullable scalar returns use __value__* (param/return ABI parity with
                    // cfgParamIsImplicitNullable); non-nullable __string__* cannot carry null (#8563).
                    if ('__value__' !== $callback && '__object__*' !== $callback) {
                        return '__value__*';
                    }

                    return $callback;
                }
            }
        }
        $rawReturn = $this->rawTypeFromCfgReturn($cfgFunc->returnType);
        if (null !== $rawReturn) {
            $callback = $this->callbackTypeFromPhptype($rawReturn);
            if (null !== $callback) {
                return $callback;
            }
        }
        if ($cfgFunc->returnType instanceof Op\Type\Literal) {
            switch (strtolower($cfgFunc->returnType->name)) {
                case 'void':
                case 'never':
                    return 'void';
                case 'int':
                    return 'int64';
                case 'float':
                    return 'double';
                case 'string':
                    return '__string__*';
                case 'bool':
                    return 'bool';
                case 'object':
                    return '__object__*';
                case 'array':
                    return '__hashtable__*';
                case 'mixed':
                    // Avoid Type::fromDecl('mixed') → __object__* (#12348 / #32728).
                    return '__value__';
                default:
                    return '__value__';
            }
        }

        return '__value__';
    }

    /** Class const / property default lowering only; values live in $block->constants (self-host bundle). */
    private function isSelfHostClassBodyEpilogueOpcode(int $type): bool
    {
        return OpCode::TYPE_UNARY_MINUS === $type
            || OpCode::TYPE_PLUS === $type
            || OpCode::TYPE_MUL === $type
            || OpCode::TYPE_BITWISE_OR === $type
            || OpCode::TYPE_BITWISE_AND === $type
            || OpCode::TYPE_BITWISE_XOR === $type
            || OpCode::TYPE_SHIFT_LEFT === $type
            || OpCode::TYPE_SHIFT_RIGHT === $type;
    }

    /** Bootstrap fixture: compile only isSuperglobalName from bundled Web\\Superglobals (#816). */
    private function isBundledSuperglobalsClass(int $classId): bool
    {
        $name = strtolower($this->context->scope->className ?? '');

        return 'phpcompiler\\web\\superglobals' === $name || 'superglobals' === $name;
    }

    /**
     * DECLARE_* name slot may be a Temporary with the string in $block->constants (#22642).
     */
    private function jitResolveClassLikeDeclareNameOperand(Block $block, OpCode $op): ?Operand\Literal
    {
        $nameOp = $block->getOperand($op->arg1);
        if ($nameOp instanceof Operand\Literal && is_string($nameOp->value)) {
            return $nameOp;
        }
        if (isset($block->constants[$op->arg1])) {
            $const = $block->constants[$op->arg1];
            if (VM\Variable::TYPE_STRING === $const->type) {
                return new Operand\Literal($const->toString());
            }
        }

        return null;
    }

    /**
     * Zend E_COMPILE_ERROR when a second class/interface/trait/enum reuses a name (#31110).
     *
     * @return true when fatal IR was emitted and the DECLARE body must be skipped
     */
    private function emitDuplicateClassLikeDeclareFatalIfNeeded(
        OpCode $op,
        Block $block,
        string $kind,
        string $name
    ): bool {
        $object = $this->context->type->object;
        if (!$object->shouldRejectUserDeclare($name, $op)) {
            $object->recordUserDeclareOpcode($name, $op);

            return false;
        }
        JIT\ImplementsHierarchyJitGuard::emitCompileFatal(
            $this->context,
            sprintf('Cannot declare %s %s, because the name is already in use', $kind, $name),
            $block->scriptPath(),
            $op->sourceLocation
        );

        return true;
    }

    public function assignIncludeResult(Operand $result): void
    {
        if ([] !== $this->context->inlineIncludeReturnHolders) {
            $holder = $this->context->inlineIncludeReturnHolders[
                array_key_last($this->context->inlineIncludeReturnHolders)
            ];
            $this->assignOperand($result, $holder, true);

            return;
        }
        if ([] !== $this->context->inlineIncludeReturnOperands) {
            $holderOp = $this->context->inlineIncludeReturnOperands[
                array_key_last($this->context->inlineIncludeReturnOperands)
            ];
            $this->assignOperand($result, $this->context->getVariableFromOp($holderOp), true);

            return;
        }
        $this->assignOperand(
            $result,
            new Variable(
                $this->context,
                Variable::TYPE_NATIVE_LONG,
                Variable::KIND_VALUE,
                $this->context->constantFromInteger(1)
            )
        );
    }

    public function assignOperandForced(Operand $result, Variable $value): void
    {
        $this->assignOperand($result, $value, true);
    }

    private function promoteNativeLongLvalueToValueBox(
        Operand $resultOp,
        Variable $result,
        Variable $value
    ): void {
        // When the old lvalue was an i64 alloca, already-compiled loop headers
        // still read from it. Write the long component of the value box back to
        // the old alloca so backedges see the updated value (#32605).
        $oldAlloca = null;
        if (Variable::KIND_VARIABLE === $result->kind
            && Variable::TYPE_NATIVE_LONG === $result->type
            && null !== $result->value
        ) {
            $oldAlloca = $result->value;
        }
        if (!$result->includeBinding) {
            $result->free();
        }
        $slot = JIT\JitValueBox::alloc($this->context);
        $slotPtr = JIT\JitValueBox::pointer($this->context, $slot);
        JIT\JitValueBox::assignToPointer(
            $this->context,
            $slotPtr,
            $value
        );
        if (null !== $oldAlloca) {
            $readLong = $this->context->lookupFunction('__value__readLong');
            $longVal = $this->context->builder->call($readLong, $slotPtr);
            $this->context->builder->store($longVal, $oldAlloca);
        }
        $promoted = new Variable(
            $this->context,
            Variable::TYPE_VALUE,
            Variable::KIND_VARIABLE,
            $slot
        );
        $promoted->compileTimeConstantName = $value->compileTimeConstantName;
        $promoted->compileTimeEnumCase = $value->compileTimeEnumCase;
        $promoted->compileTimeFloat = $value->compileTimeFloat;
        $promoted->compileTimeLong = $value->compileTimeLong;
        $this->syncCompileTimeString($promoted, $value, false);
        $this->context->setVariableOp($resultOp, $promoted);
        $resolved = JIT\OperandName::resolve($resultOp);
        if (null !== $resolved && '' !== $resolved) {
            $this->context->bindVariableByName(
                $this->context->resolveRefAliasName($resolved),
                $promoted
            );
        }
        // Int-local widen to VALUE must mark assigned — guards only track VALUE slots
        // (#23471 e28/mandelbrot spurious undef warnings after #35643).
        $this->markScopeVariableAssignedIfTracked($resultOp, $promoted);
    }

    /**
     * Widen `$x = 0; $x = 0.7` locals to a native double alloca instead of a heap
     * __value__ box so float loops stay on fadd/fmul (#36407 / #23471).
     */
    private function promoteNativeLongLvalueToNativeDouble(
        Operand $resultOp,
        Variable $result,
        Variable $value
    ): void {
        $oldAlloca = null;
        if (Variable::KIND_VARIABLE === $result->kind
            && Variable::TYPE_NATIVE_LONG === $result->type
            && null !== $result->value
        ) {
            $oldAlloca = $result->value;
        }
        if (!$result->includeBinding) {
            $result->free();
        }
        $doubleTy = $this->context->getTypeFromString('double');
        $slot = JIT\BasicBlockHelper::entryAlloca($this->context, $doubleTy);
        if (Variable::TYPE_NATIVE_DOUBLE === $value->type) {
            $fp = $this->context->helper->loadValue($value);
        } elseif (Variable::TYPE_VALUE === $value->type && null !== $value->compileTimeFloat) {
            $fp = $doubleTy->constReal($value->compileTimeFloat);
        } else {
            throw new \LogicException('promoteNativeLongLvalueToNativeDouble: unexpected value type '.$value->type);
        }
        $this->context->builder->store($fp, $slot);
        if (null !== $oldAlloca) {
            $longVal = $this->context->builder->fpToSi(
                $fp,
                $this->context->getTypeFromString('int64')
            );
            $this->context->builder->store($longVal, $oldAlloca);
        }
        $promoted = new Variable(
            $this->context,
            Variable::TYPE_NATIVE_DOUBLE,
            Variable::KIND_VARIABLE,
            $slot
        );
        $promoted->compileTimeConstantName = $value->compileTimeConstantName;
        $promoted->compileTimeEnumCase = $value->compileTimeEnumCase;
        $promoted->compileTimeFloat = $value->compileTimeFloat;
        $promoted->compileTimeLong = null;
        $this->syncCompileTimeString($promoted, $value, false);
        $this->context->setVariableOp($resultOp, $promoted);
        $resolved = JIT\OperandName::resolve($resultOp);
        if (null !== $resolved && '' !== $resolved) {
            $this->context->bindVariableByName(
                $this->context->resolveRefAliasName($resolved),
                $promoted
            );
        }
        $this->markScopeVariableAssignedIfTracked($resultOp, $promoted);
    }

    private function nativeLongWidenAssignIsNativeDouble(Variable $value): bool
    {
        return Variable::TYPE_NATIVE_DOUBLE === $value->type
            || (Variable::TYPE_VALUE === $value->type && null !== $value->compileTimeFloat);
    }

    /**
     * First assignment to a script global must populate the heap box (#1492 bootstrap-aot).
     *
     * Without this, makeVariableFromValueOp keeps an SSA rvalue while a later VAR_FETCH rebinds
     * the name to an empty script-global wrapper — SplObjectStorage::contains() then reads null.
     *
     * Also covers `global $name` imports after foreach/try phi merges drop the slot binding (#16828).
     */
    private function tryAssignScriptGlobalFirstBinding(Operand $resultOp, JIT\Variable $value): bool
    {
        $block = $this->context->jitEnclosingBlock;
        if (null === $block) {
            return false;
        }
        $name = JIT\OperandName::resolve($resultOp);
        if (null === $name || '' === $name) {
            $slot = $block->slotForOperand($resultOp);
            if (null !== $slot) {
                foreach ($block->scopedOperands() as $scopeOp) {
                    if ($block->slotForOperand($scopeOp) !== $slot) {
                        continue;
                    }
                    $scopeName = JIT\OperandName::resolve($scopeOp);
                    if (null !== $scopeName && '' !== $scopeName) {
                        $name = $scopeName;
                        break;
                    }
                }
            }
        }
        if (null === $name || '' === $name || \PHPCompiler\Web\Superglobals::isSuperglobalName($name)) {
            return false;
        }
        $mainScriptGlobal = $block->isMainScript() && !$this->context->isForeachByRefLocalName($name, $block);
        $importedGlobal = isset($this->context->jitImportedGlobalNames[$name]);
        if (!$mainScriptGlobal && !$importedGlobal) {
            return false;
        }
        // Native scalar {main} counters use stack allocas — do not re-bind them onto a
        // heap __value__ box (that undoes Variable::fromOp / #36408).
        $resolved = $this->context->resolveRefAliasName($name);
        if (isset($this->context->namedVariableBindings[$resolved])) {
            $existing = $this->context->namedVariableBindings[$resolved];
            if (
                Variable::TYPE_NATIVE_LONG === $existing->type
                || Variable::TYPE_NATIVE_BOOL === $existing->type
                || Variable::TYPE_NATIVE_DOUBLE === $existing->type
            ) {
                return false;
            }
        }
        $globalVar = $this->context->ensureScriptGlobal($name);
        $this->context->setVariableOp($resultOp, $globalVar);
        $globalPtr = JIT\JitValueBox::valuePtrFromVariable($this->context, $globalVar);
        JIT\JitValueBox::assignToPointer(
            $this->context,
            $globalPtr,
            $value
        );
        JIT\JitValueBox::publishAfterWrite($this->context, $globalPtr);
        $this->invalidateScriptGlobalCompileTimeMetadata($globalVar);
        $globalVar->compileTimeEnumCase = $value->compileTimeEnumCase;
        $this->syncCompileTimeString($globalVar, $value, false);
        $this->syncCompileTimeBcmathNumber($globalVar, $value, false);
        $this->syncCompileTimeDomTagName($globalVar, $value, false);
        $this->syncCompileTimeDatePeriod($globalVar, $value, false);
        $this->context->bindVariableByName($this->context->resolveRefAliasName($name), $globalVar);
        $this->markScopeVariableAssignedIfTracked($resultOp, $globalVar);

        return true;
    }

    /**
     * Publish CONCAT into the {main} script-global box ECHO reads (#36366).
     *
     * Uses the opcode {@see Block} (same as attachEchoScriptGlobalName), not
     * {@see Context::$jitEnclosingBlock}, which can disagree mid-compile.
     */
    private function publishConcatResultToMainScriptGlobal(
        Block $block,
        Operand $destOp,
        JIT\Variable $value,
        ?int $destSlot = null
    ): bool {
        if (!$block->isMainScript()) {
            return false;
        }
        // Same name resolution markAssigned uses — OperandName alone can miss when
        // the CONCAT dest Temporary shares a slot with a named Variable (#36366).
        $name = null;
        if (null !== $destSlot) {
            $name = $this->resolveLocalNameForOperand($block, $destOp, $destSlot);
        }
        if (null === $name || '' === $name) {
            $name = JIT\UndefinedVariableHelper::resolveTrackableName($destOp, $value);
        }
        if (null === $name || '' === $name) {
            $name = JIT\OperandName::resolve($destOp);
        }
        if (null === $name || '' === $name) {
            $slot = $destSlot ?? $block->slotForOperand($destOp);
            if (null !== $slot) {
                foreach ($block->scopedOperands() as $scopeOp) {
                    if ($block->slotForOperand($scopeOp) !== $slot) {
                        continue;
                    }
                    $scopeName = JIT\OperandName::resolve($scopeOp);
                    if (null !== $scopeName && '' !== $scopeName) {
                        $name = $scopeName;
                        break;
                    }
                }
            }
        }
        if (
            null === $name
            || '' === $name
            || \PHPCompiler\Web\Superglobals::isSuperglobalName($name)
            || $this->context->isForeachByRefLocalName($name, $block)
        ) {
            return false;
        }
        $globalVar = $this->context->ensureScriptGlobal($name);
        $globalPtr = JIT\JitValueBox::valuePtrFromVariable($this->context, $globalVar);
        JIT\JitValueBox::assignToPointer($this->context, $globalPtr, $value);
        JIT\JitValueBox::publishAfterWrite($this->context, $globalPtr);
        $this->invalidateScriptGlobalCompileTimeMetadata($globalVar);
        $this->syncCompileTimeString($globalVar, $value, false);
        // Keep dest / named binding on the native `__string__*` result. Rebinding to
        // the script-global TYPE_VALUE box made ECHO read an empty heap box when
        // assignToPointer did not materialize the string bytes (#36366).
        $this->context->setVariableOp($destOp, $value);
        $this->context->bindVariableByName($this->context->resolveRefAliasName($name), $value);
        $this->markScopeVariableAssignedIfTracked($destOp, $value);

        return true;
    }

    /**
     * Script-global heap boxes keep stale {@see JIT\Variable::$compileTimeLong} after ++/-- or
     * assign-op unless cleared; echo must not constant-fold those operands (#23842).
     */
    private function invalidateScriptGlobalCompileTimeMetadata(JIT\Variable $global): void
    {
        if (!$global->functionStaticGlobal) {
            return;
        }
        $global->compileTimeLong = null;
        $global->compileTimeFloat = null;
        $global->compileTimeString = null;
        $global->compileTimeBcmathNumber = null;
        $global->isNullConstant = false;
        $global->compileTimeConstantName = null;
        $global->compileTimeEnumCase = null;
    }

    /**
     * Re-bind echo/print operands to the module-global heap box when the name is a script global.
     *
     * Scope slots can retain TYPE_NATIVE_LONG rvalues from an earlier literal assign even after
     * inc/dec or assign-op updated the heap box (#23842).
     */
    private function resolveScriptGlobalForRuntimeRead(
        Operand $op,
        ?Block $block = null,
        ?string $nameOverride = null,
        bool $skipUndefGuard = false
    ): ?JIT\Variable {
        $name = $nameOverride ?? JIT\OperandName::resolve($op);
        if (null === $name || '' === $name || \PHPCompiler\Web\Superglobals::isSuperglobalName($name)) {
            return null;
        }
        $block ??= $this->context->jitFunctionRootBlock ?? $this->context->jitEnclosingBlock;
        if (null === $block) {
            return null;
        }
        if ($block->isMainScript() && !$this->context->isForeachByRefLocalName($name, $block)) {
            if ($this->shouldDeferScriptGlobalForInlineIncludeBinding($name, $op, $block)) {
                return null;
            }
            // Native scalar {main} counters live in stack allocas — do not redirect reads
            // onto an empty heap box (#36408). Boxed float/int results from property
            // arithmetic also land in a local `__value__` alloca while the module
            // script-global stays null (#36386 nbody / sqrt).
            $resolved = $this->context->resolveRefAliasName($name);
            if (isset($this->context->namedVariableBindings[$resolved])) {
                $existing = $this->context->namedVariableBindings[$resolved];
                if (
                    Variable::TYPE_NATIVE_LONG === $existing->type
                    || Variable::TYPE_NATIVE_BOOL === $existing->type
                    || Variable::TYPE_NATIVE_DOUBLE === $existing->type
                    || (
                        Variable::TYPE_VALUE === $existing->type
                        && Variable::KIND_VARIABLE === $existing->kind
                    )
                ) {
                    return null;
                }
            }

            return $this->ensureScriptGlobalForRuntimeRead($op, $name, $skipUndefGuard);
        }
        if ($block->declaresGlobalName($name) || isset($this->context->jitImportedGlobalNames[$name])) {
            return $this->ensureScriptGlobalForRuntimeRead($op, $name, $skipUndefGuard);
        }
        $resolved = $this->context->resolveRefAliasName($name);
        if (
            isset($this->context->namedVariableBindings[$resolved])
            && $this->context->namedVariableBindings[$resolved]->functionStaticGlobal
        ) {
            // Nested-function `static $s` is a module box, not $GLOBALS. Echo must
            // read that binding — ensureScriptGlobal() allocated a second empty slot (#31966).
            return $this->context->namedVariableBindings[$resolved];
        }

        return null;
    }

    /**
     * Module-global script variable with ZEND_CHECK_UNDEFINED_VAR before read (#10360, #36081).
     *
     * {main} locals and `global $name` imports share ensureScriptGlobal() heap boxes; reads
     * previously skipped UndefinedVariableHelper when echoScriptGlobalName was set (#23842).
     */
    /**
     * AssignOp peephole fuses CONCAT+ASSIGN in-place (#16281); echo must read that CV slot,
     * not the {main} script-global sidecar the echo opcode names (#36366 / p16).
     */
    private function jitBlockHasInBlockConcatToSlot(Block $block, int $slot): bool
    {
        foreach ($block->opCodes as $prior) {
            if (OpCode::TYPE_CONCAT === $prior->type && null !== $prior->arg1 && (int) $prior->arg1 === $slot) {
                return true;
            }
        }

        return false;
    }

    private function ensureScriptGlobalForRuntimeRead(
        Operand $op,
        string $name,
        bool $skipUndefGuard = false
    ): JIT\Variable {
        $global = $this->context->ensureScriptGlobal($name);
        if (!$skipUndefGuard) {
            JIT\UndefinedVariableHelper::guardBeforeScriptGlobalName($this->context, $name);
        }

        return $global;
    }

    /** Resolve a CV name when the assign/echo slot wraps a Temporary without OperandName (#36081). */
    private function resolveLocalNameForOperand(Block $block, Operand $op, int $slot): ?string
    {
        $name = JIT\OperandName::resolve($op);
        if (null !== $name && '' !== $name) {
            return $name;
        }
        foreach ($block->scopedOperands() as $scopeOp) {
            if ($block->slotForOperand($scopeOp) !== $slot) {
                continue;
            }
            $name = JIT\OperandName::resolve($scopeOp);
            if (null !== $name && '' !== $name) {
                return $name;
            }
        }

        return null;
    }

    /**
     * Inlined {main} includes inherit caller locals — never route those names through the
     * standalone script-global sidecar (#866, coalesce_then_inherited_local).
     */
    private function shouldDeferScriptGlobalForInlineIncludeBinding(
        string $name,
        ?Operand $op = null,
        ?Block $block = null
    ): bool {
        if ($this->context->inlineIncludeDepth <= 0) {
            return false;
        }
        // Inlined {main} units share the caller's LLVM function — locals live in
        // include-binding allocas, not standalone script-global sidecars (#866).
        $block ??= $this->context->jitEnclosingBlock ?? $this->context->jitFunctionRootBlock;
        if (null !== $block && $block->isMainScript()) {
            return !\PHPCompiler\Web\Superglobals::isSuperglobalName($name);
        }
        if (JIT\IncludeBindingEmitHelper::refreshFrameDeclaresName($this->context, $name)) {
            return true;
        }
        if (null !== $op && $this->context->hasVariableOp($op)) {
            return $this->context->getVariableFromOp($op)->includeBinding;
        }

        return false;
    }

    /** Scope operand for value reads that may emit undefined-variable E_WARNING (#10358, #10360, #26147). */
    private function variableFromOpForRuntimeRead(Operand $op): JIT\Variable
    {
        $var = $this->context->getVariableFromOp($op);
        JIT\UndefinedVariableHelper::guardBeforeRuntimeRead($this->context, $op, $var);
        $var = $this->ensureNamedNativeLongLocalAlloca($op, $var);

        return $var;
    }

    private function markScopeVariableAssignedIfTracked(Operand $resultOp, JIT\Variable $result): void
    {
        JIT\UndefinedVariableHelper::markAssigned($this->context, $resultOp, $result);
    }

    /**
     * Prefer active foreach by-ref lvalues over {main} script-global slots (#4364).
     *
     * Foreach/try phi merges can drop {@see JIT\Variable::$functionStaticGlobal} on `global $name`
     * lvalues after an early return (src/llvm-env.php, issue #16828).
     */
    private function resolveAssignLvalue(Operand $resultOp): JIT\Variable
    {
        $block = $this->context->jitEnclosingBlock;
        if (null !== $block && null !== $block->func) {
            $slot = $block->slotForOperand($resultOp);
            if (null !== $slot) {
                foreach ($block->func->params as $paramIdx => $param) {
                    if (!isset($block->paramByRef[$paramIdx])) {
                        continue;
                    }
                    if ($block->slotForOperand($param->result) !== $slot) {
                        continue;
                    }
                    if (!$this->context->hasVariableOp($param->result)) {
                        continue;
                    }
                    $paramVar = $this->context->getVariableFromOp($param->result);
                    if (
                        null !== $paramVar->valueBoxAliasPtr
                        || $paramVar->borrowedValueEntry
                    ) {
                        $this->context->scope->variables[$resultOp] = $paramVar;

                        return $paramVar;
                    }
                }
            }
        }
        $name = JIT\OperandName::resolve($resultOp);
        if (null !== $name && '' !== $name) {
            $resolved = $this->context->resolveRefAliasName($name);
            if (isset($this->context->namedVariableBindings[$resolved])) {
                $bound = $this->context->namedVariableBindings[$resolved];
                if (
                    null !== $bound->valueBoxAliasPtr
                    || $bound->borrowedValueEntry
                    || null !== $bound->foreachByRefPackedArm
                    || null !== $bound->objectPropertySlot
                    || $bound->assignRefLvalueAlias
                ) {
                    $this->context->scope->variables[$resultOp] = $bound;

                    return $bound;
                }
            }
        }
        $result = $this->context->getVariableFromOp($resultOp);
        if (null !== $result->foreachByRefPackedArm || $result->borrowedValueEntry) {
            return $result;
        }
        if (null !== $name && '' !== $name) {
            $resolved = $this->context->resolveRefAliasName($name);
            if (isset($this->context->namedVariableBindings[$resolved])) {
                $bound = $this->context->namedVariableBindings[$resolved];
                if ($bound->functionStaticGlobal || null !== $bound->staticPropertyGlobal) {
                    $this->context->scope->variables[$resultOp] = $bound;

                    return $bound;
                }
                if (null !== $bound->foreachByRefPackedArm || $bound->borrowedValueEntry) {
                    $this->context->scope->variables[$resultOp] = $bound;

                    return $bound;
                }
            }
            $block = $this->context->jitEnclosingBlock;
            if (null !== $block) {
                $resolvedBinding = $this->context->resolveRefAliasName($name);
                if (
                    isset($this->context->namedVariableBindings[$resolvedBinding])
                    && (
                        $this->context->namedVariableBindings[$resolvedBinding]->functionStaticGlobal
                        || null !== $this->context->namedVariableBindings[$resolvedBinding]->staticPropertyGlobal
                    )
                ) {
                    $global = $this->context->namedVariableBindings[$resolvedBinding];
                    $this->context->scope->variables[$resultOp] = $global;

                    return $global;
                }
            }
            $block = $this->context->jitEnclosingBlock;
            if (null !== $block && (
                $block->declaresGlobalName($name)
                || isset($this->context->jitImportedGlobalNames[$name])
            )) {
                $global = $this->context->ensureScriptGlobal($name);
                $this->context->bindVariableByName($name, $global);
                $this->context->scope->variables[$resultOp] = $global;

                return $global;
            }
        }
        if (null === $name || '' === $name || !$result->functionStaticGlobal) {
            $recovered = $this->recoverScriptGlobalAssignLvalueBySlot($resultOp, $result);
            if (null !== $recovered) {
                return $recovered;
            }

            return $result;
        }
        $resolved = $this->context->resolveRefAliasName($name);
        if (isset($this->context->namedVariableBindings[$resolved])) {
            $bound = $this->context->namedVariableBindings[$resolved];
            if (null !== $bound->foreachByRefPackedArm || $bound->borrowedValueEntry) {
                $this->context->scope->variables[$resultOp] = $bound;

                return $bound;
            }
        }
        foreach ($this->context->scope->variables as $scopeOp) {
            if (!$scopeOp instanceof Operand) {
                continue;
            }
            $scopeName = JIT\OperandName::resolve($scopeOp);
            if (null === $scopeName || $resolved !== $this->context->resolveRefAliasName($scopeName)) {
                continue;
            }
            $scopeVar = $this->context->scope->variables[$scopeOp];
            if (null !== $scopeVar->foreachByRefPackedArm || $scopeVar->borrowedValueEntry) {
                $this->context->scope->variables[$resultOp] = $scopeVar;

                return $scopeVar;
            }
        }

        return $result;
    }

    /**
     * Foreach/try phi operands may lose the variable name while keeping the global slot (#16828).
     */
    private function recoverScriptGlobalAssignLvalueBySlot(Operand $resultOp, JIT\Variable $result): ?JIT\Variable
    {
        $block = $this->context->jitEnclosingBlock;
        if (null === $block) {
            return null;
        }
        $slot = $block->slotForOperand($resultOp);
        if (null === $slot) {
            return null;
        }
        foreach ($block->scopedOperands() as $scopeOp) {
            if ($block->slotForOperand($scopeOp) !== $slot) {
                continue;
            }
            if ($this->context->scope->variables->contains($scopeOp)) {
                $scopeVar = $this->context->scope->variables[$scopeOp];
                if ($scopeVar->functionStaticGlobal) {
                    $this->context->scope->variables[$resultOp] = $scopeVar;

                    return $scopeVar;
                }
            }
            $scopeName = JIT\OperandName::resolve($scopeOp);
            if (null === $scopeName || '' === $scopeName) {
                continue;
            }
            if (
                !$block->declaresGlobalName($scopeName)
                && !isset($this->context->jitImportedGlobalNames[$scopeName])
            ) {
                continue;
            }
            $global = $this->context->ensureScriptGlobal($scopeName);
            $this->context->bindVariableByName($scopeName, $global);
            $this->context->scope->variables[$resultOp] = $global;

            return $global;
        }

        return null;
    }

    private function resolveScriptGlobalAssignTarget(Operand $resultOp, JIT\Variable $result): ?JIT\Variable
    {
        if ($result->functionStaticGlobal) {
            return $result;
        }
        $name = JIT\OperandName::resolve($resultOp);
        if (null === $name || '' === $name) {
            $block = $this->context->jitEnclosingBlock;
            if (null !== $block) {
                $slot = $block->slotForOperand($resultOp);
                if (null !== $slot) {
                    foreach ($block->scopedOperands() as $scopeOp) {
                        if ($block->slotForOperand($scopeOp) !== $slot) {
                            continue;
                        }
                        $scopeName = JIT\OperandName::resolve($scopeOp);
                        if (null !== $scopeName && '' !== $scopeName) {
                            $name = $scopeName;
                            break;
                        }
                    }
                }
            }
        }
        if (null === $name || '' === $name || \PHPCompiler\Web\Superglobals::isSuperglobalName($name)) {
            return null;
        }
        $resolved = $this->context->resolveRefAliasName($name);
        if (
            isset($this->context->namedVariableBindings[$resolved])
            && $this->context->namedVariableBindings[$resolved]->functionStaticGlobal
        ) {
            return $this->context->namedVariableBindings[$resolved];
        }
        $root = $this->context->jitFunctionRootBlock ?? $this->context->jitEnclosingBlock;
        if (isset($this->context->jitImportedGlobalNames[$name])) {
            $global = $this->context->ensureScriptGlobal($name);
            $this->context->bindVariableByName($name, $global);

            return $global;
        }
        if (null !== $root && $root->declaresGlobalName($name)) {
            $global = $this->context->ensureScriptGlobal($name);
            $this->context->bindVariableByName($name, $global);

            return $global;
        }

        return null;
    }

    /** Both ?: arms jump to a shared RETURN/THROW merge block (#4280, #8555). */
    private function findJumpIfSharedReturnOperand(?Block $ifBranch, ?Block $elseBranch): ?\PHPCfg\Operand
    {
        if (null === $ifBranch || null === $elseBranch) {
            return null;
        }
        $ifMerge = $this->jumpBlockTarget($ifBranch);
        $elseMerge = $this->jumpBlockTarget($elseBranch);
        if (null === $ifMerge || $ifMerge !== $elseMerge) {
            return null;
        }

        foreach ($ifMerge->opCodes as $mergeOp) {
            if (OpCode::TYPE_RETURN === $mergeOp->type) {
                return $ifMerge->getOperand($mergeOp->arg1);
            }
            if (OpCode::TYPE_THROW === $mergeOp->type) {
                return $ifMerge->getOperand($mergeOp->arg1);
            }
        }

        return null;
    }

    private function jumpBlockTarget(Block $branch): ?Block
    {
        $limit = min($branch->nOpCodes, \count($branch->opCodes));
        for ($i = $limit - 1; $i >= 0; --$i) {
            $branchOp = $branch->opCodes[$i] ?? null;
            if (null === $branchOp) {
                continue;
            }
            if (OpCode::TYPE_JUMP === $branchOp->type) {
                return $branchOp->block1;
            }
        }

        return null;
    }

    /** Last assign before a ?: branch JUMP targets the shared RETURN merge (#8555). */
    private function isTernaryBranchMergeAssign(Block $branch, OpCode $assignOp): bool
    {
        if (null === $this->context->ternarySharedReturnSlot) {
            return false;
        }
        $merge = $this->jumpBlockTarget($branch);
        if (null === $merge) {
            return false;
        }
        // Do not emitJitReturnFromValue when merge still has PLUS/etc. (#33719).
        if (!$this->mergeBlockIsPureTernaryReturn($merge)) {
            return false;
        }
        $limit = min($branch->nOpCodes, \count($branch->opCodes));
        if ($limit < 2 || OpCode::TYPE_JUMP !== $branch->opCodes[$limit - 1]->type) {
            return false;
        }
        $prior = $branch->opCodes[$limit - 2];

        return OpCode::TYPE_ASSIGN === $prior->type && $prior === $assignOp;
    }

    private function recordListUnpackAssignSlot(Operand $resultOp, Variable $slot): void
    {
        if (null === $this->context->listUnpackAssignRootBlock) {
            return;
        }
        $name = JIT\OperandName::resolve($resultOp);
        if (null === $name || '' === $name) {
            return;
        }
        if (
            Variable::KIND_VARIABLE !== $slot->kind
            || (Variable::TYPE_VALUE !== $slot->type && Variable::TYPE_STRING !== $slot->type)
        ) {
            $lvalue = $this->resolveAssignLvalue($resultOp);
            if (
                Variable::KIND_VARIABLE !== $lvalue->kind
                || (Variable::TYPE_VALUE !== $lvalue->type && Variable::TYPE_STRING !== $lvalue->type)
            ) {
                return;
            }
            $slot = $lvalue;
        }
        $this->context->listUnpackAssignSlots[
            $this->context->resolveRefAliasName($name)
        ] = $slot;
    }

    /**
     * Chained ?? call-arg merge slots may carry isNullConstant from a dead CFG arm when
     * the send block was compiled early; copy the live boxed value at the send site (#17590).
     */
    private function materializeCoalesceMergeSlotArgSend(Block $block, Operand $sendOperand): Variable
    {
        if (!$this->context->hasVariableOp($sendOperand)) {
            $func = $this->context->builder->getInsertBlock()->getParent();
            $llvmBlock = $this->context->builder->getInsertBlock();
            $this->context->makeVariableFromOp($func, $llvmBlock, $block, $sendOperand);
        }
        $slotVar = $this->context->getVariableFromOp($sendOperand);
        if (Variable::TYPE_VALUE !== $slotVar->type) {
            $slotVar->isNullConstant = false;
            $slotVar->compileTimeString = null;
            $slotVar->compileTimeFloat = null;
            $slotVar->compileTimeConstantName = null;
            $slotVar->compileTimeEnumCase = null;

            return $slotVar;
        }
        // Always copy into a stack __value__ — AOT {main} named locals are script globals
        // (KIND_VALUE + __value__**). Returning them as-is let echo bitcast the global (#24009).
        $srcPtr = JIT\JitValueBox::valuePtrFromVariable($this->context, $slotVar);
        JIT\JitValueBox::publishAfterWrite($this->context, $srcPtr);
        $destSlot = JIT\JitValueBox::alloc($this->context);
        JIT\JitValueBox::copyFromPointer($this->context, $destSlot, $srcPtr);
        JIT\JitValueBox::publishAfterWrite(
            $this->context,
            JIT\JitValueBox::pointer($this->context, $destSlot)
        );

        return new Variable(
            $this->context,
            Variable::TYPE_VALUE,
            Variable::KIND_VARIABLE,
            $destSlot
        );
    }

    private function prepareNestedJitCalleeParamArgument(Variable $arg): Variable
    {
        if (!JIT\NestedJitCompileScope::isActive() || Variable::TYPE_STRING !== $arg->type) {
            return $arg;
        }
        if (Variable::KIND_VALUE !== $arg->kind) {
            $materialized = JIT\JitStringArg::lowerDominating(
                $this->context,
                $arg,
                'nested JIT string parameter'
            );

            return new Variable(
                $this->context,
                Variable::TYPE_STRING,
                Variable::KIND_VALUE,
                $materialized
            );
        }
        $llvmTy = $this->context->getStringFromType($arg->value->typeOf());
        if ('__string__*' !== $llvmTy) {
            return $arg;
        }
        $slot = JIT\BasicBlockHelper::entryAlloca(
            $this->context,
            $this->context->getTypeFromString('__string__*')
        );
        $owned = $this->context->builder->call(
            $this->context->lookupFunction('__string__separate'),
            $arg->value
        );
        $this->context->builder->store($owned, $slot);

        return new Variable(
            $this->context,
            Variable::TYPE_STRING,
            Variable::KIND_VALUE,
            $this->context->builder->load($slot)
        );
    }

    /**
     * prepareIndexWrite / prepareStringKeyWrite allocate an orphan __value__ box plus HT
     * write markers. Assigns commit into the HT (#21947); keep the orphan box in sync so
     * later reads of the same Variable (chained `$r = $a[i] = v`, array-literal elements)
     * observe the expression value rather than an empty box (#24055).
     */
    private function syncDimWriteOrphanValueBox(Variable $dimLvalue, Variable $value): void
    {
        if (Variable::TYPE_VALUE !== $dimLvalue->type || Variable::KIND_VARIABLE !== $dimLvalue->kind) {
            return;
        }
        $orphanPtr = JIT\JitValueBox::pointer($this->context, $dimLvalue->value);
        JIT\JitValueBox::assignToPointer($this->context, $orphanPtr, $value);
        JIT\JitValueBox::publishAfterWrite($this->context, $orphanPtr);
    }

    /**
     * Promote ASSIGN_REF source to a stable `__value__` box and bind the name (#34649).
     */
    private function ensureAssignRefSharedValueBox(
        Variable $srcVar,
        string $srcName,
        Operand $srcOp
    ): Variable {
        if (
            Variable::TYPE_VALUE === $srcVar->type
            && Variable::KIND_VARIABLE === $srcVar->kind
        ) {
            if (null === $srcVar->valueBoxAliasPtr) {
                $srcVar->valueBoxAliasPtr = JIT\JitValueBox::valuePtrFromVariable(
                    $this->context,
                    $srcVar
                );
            }
            $srcVar->assignRefLvalueAlias = true;
            $this->context->bindVariableByName($srcName, $srcVar);
            $this->context->setVariableOp($srcOp, $srcVar);

            return $srcVar;
        }
        $slot = JIT\JitValueBox::alloc($this->context);
        $slotPtr = JIT\JitValueBox::pointer($this->context, $slot);
        JIT\JitValueBox::assignToPointer($this->context, $slotPtr, $srcVar);
        JIT\JitValueBox::publishAfterWrite($this->context, $slotPtr);
        $boxed = new Variable(
            $this->context,
            Variable::TYPE_VALUE,
            Variable::KIND_VARIABLE,
            $slot
        );
        $boxed->valueBoxAliasPtr = $slotPtr;
        $boxed->assignRefLvalueAlias = true;
        $this->context->bindVariableByName($srcName, $boxed);
        $this->context->setVariableOp($srcOp, $boxed);

        return $boxed;
    }

    /**
     * Store `$v`'s `__value__*` into the object's property void** (Zend ASSIGN_REF).
     */
    private function pointObjectPropertySlotAtValueBox(Variable $propVar, Variable $boxVar): void
    {
        if (null === $propVar->objectPropertySlot) {
            return;
        }
        $boxPtr = JIT\JitValueBox::valuePtrFromVariable($this->context, $boxVar);
        $slot = JIT\Builtin\Type\ObjectInstancePropertyLlvm::dominatingSlotPtr(
            $this->context->type->object,
            $propVar
        );
        $voidPtr = $this->context->getTypeFromString('void*');
        $this->context->builder->store(
            $this->context->builder->pointerCast($boxPtr, $voidPtr),
            $slot
        );
    }

    /**
     * `$a[] =& $x` / `$a[0] =& $x`: copy the shared box into the HT entry and register it for
     * write-through (#34685 / #34689).
     *
     * @see php-src Zend/zend_execute.c zend_assign_to_variable_reference
     */
    private function syncAssignRefDimEntryFromShared(Variable $destVar, Variable $shared): bool
    {
        $entryPtr = $this->assignRefDestEntryPointer($destVar);
        if (null === $entryPtr) {
            return false;
        }
        $sharedPtr = JIT\JitValueBox::valuePtrFromVariable($this->context, $shared);
        JIT\JitValueBox::copyIntoPointer($this->context, $entryPtr, $sharedPtr);
        if (null === $shared->assignRefSyncEntryPtrs) {
            $shared->assignRefSyncEntryPtrs = [];
        }
        $shared->assignRefSyncEntryPtrs[] = $entryPtr;
        $this->markAssignRefLvalueAlias($shared);

        return true;
    }

    /**
     * After `$x = …` on a multi-append ASSIGN_REF shared box, refresh every HT alias (#34685).
     */
    private function syncAssignRefHtEntriesFromShared(Variable $shared): void
    {
        if (null === $shared->assignRefSyncEntryPtrs || [] === $shared->assignRefSyncEntryPtrs) {
            return;
        }
        $sharedPtr = JIT\JitValueBox::valuePtrFromVariable($this->context, $shared);
        foreach ($shared->assignRefSyncEntryPtrs as $entryPtr) {
            JIT\JitValueBox::copyIntoPointer($this->context, $entryPtr, $sharedPtr);
        }
    }

    /**
     * Mark ASSIGN_REF lvalue so #34465 does not strip objectPropertySlot on `$v = …` (#34649).
     */
    private function markAssignRefLvalueAlias(Variable $var): void
    {
        if (
            null !== $var->objectPropertySlot
            || null !== $var->staticPropertyGlobal
            || null !== $var->writableHt
            || null !== $var->valueBoxAliasPtr
            || (
                Variable::TYPE_VALUE === $var->type
                && Variable::KIND_VARIABLE === $var->kind
            )
        ) {
            $var->assignRefLvalueAlias = true;
        }
    }

    /**
     * True when ASSIGN_REF dest is a FETCH_DIM_W / []= / property / static lvalue (#34645).
     *
     * @see php-src Zend/zend_execute.c zend_assign_to_variable_reference
     */
    private function isAssignRefWritableDest(Variable $var): bool
    {
        if (null !== $var->objectPropertySlot || null !== $var->staticPropertyGlobal) {
            return true;
        }
        if (null !== $var->writableHt) {
            return null !== $var->writableIndex
                || null !== $var->writableStringKey
                || null !== $var->writableObjectKey
                || null !== $var->writableValueBoxKey;
        }
        if (null !== $var->writableArrayAccessReceiver && null !== $var->writableArrayAccessKey) {
            return true;
        }
        // reserveAppendSlot: KIND_VARIABLE pointing at the HT entry (no writableHt markers).
        return Variable::TYPE_VALUE === $var->type
            && Variable::KIND_VARIABLE === $var->kind
            && null === $var->valueBoxAliasPtr
            && !$var->functionStaticGlobal
            && null === $var->objectPropertySlot
            && null === $var->staticPropertyGlobal;
    }

    /**
     * `$a[] = &$o->p`: after the value is copied into the dim entry, redirect the source
     * lvalue onto that entry so later property/static writes update the array (#5349).
     */
    private function aliasAssignRefDestOntoSourceStorage(Variable $destVar, Variable $srcVar): void
    {
        $entryPtr = $this->assignRefDestEntryPointer($destVar);
        if (null === $entryPtr) {
            return;
        }
        if (null !== $srcVar->objectPropertySlot) {
            $voidPtr = $this->context->getTypeFromString('void*');
            $this->context->builder->store(
                $this->context->builder->pointerCast($entryPtr, $voidPtr),
                $srcVar->objectPropertySlot
            );
            if (Variable::TYPE_VALUE === $srcVar->type) {
                $srcVar->value = $entryPtr;
            }

            return;
        }
        if (null !== $srcVar->staticPropertyGlobal) {
            $srcVar->valueBoxAliasPtr = JIT\JitValueBox::normalizeValuePtr($this->context, $entryPtr);
            if (Variable::TYPE_VALUE === $srcVar->type && Variable::KIND_VARIABLE === $srcVar->kind) {
                $srcVar->value = $entryPtr;
            }

            return;
        }
        if (null !== $srcVar->valueBoxAliasPtr) {
            // Named locals already rebound; leftover aliases (dim→dim) share the entry box.
            JIT\JitValueBox::copyFromPointer(
                $this->context,
                $entryPtr,
                JIT\JitValueBox::normalizeValuePtr($this->context, $srcVar->valueBoxAliasPtr)
            );
            $srcVar->valueBoxAliasPtr = JIT\JitValueBox::normalizeValuePtr($this->context, $entryPtr);
        }
    }

    /**
     * `$r = &$a[0]` / `[&$x] = $a`: point the named dest at the live HT entry (Zend IS_REFERENCE).
     *
     * FETCH_DIM_W orphan boxes are empty until hydrated; ref binds must alias the slot (#34673).
     *
     * @see php-src Zend/zend_execute.c zend_assign_to_variable_reference
     */
    private function aliasAssignRefNamedDestToDimEntry(Variable $dimLvalue): void
    {
        $entryPtr = $this->assignRefDestEntryPointer($dimLvalue);
        if (null === $entryPtr) {
            return;
        }
        $dimLvalue->valueBoxAliasPtr = JIT\JitValueBox::normalizeValuePtr($this->context, $entryPtr);
        $dimLvalue->borrowedValueEntry = true;
    }

    /**
     * Live `__value__*` for an ASSIGN_REF dim dest (append entry or packed writableHt).
     *
     * Packed `$a[0]=&$x` on an empty HT must materialise the slot first — a bare GEP into
     * `values` does not bump `used`, so later `$x=9` never appears in the array (#34689).
     *
     * @see php-src Zend/zend_execute.c zend_fetch_dimension_address / zend_assign_to_variable_reference
     * @see JIT\HashTableWriteLlvm::hydrateIndexWriteLvalue
     */
    private function assignRefDestEntryPointer(Variable $destVar): ?\PHPLLVM\Value
    {
        // Packed index + string-key FETCH_DIM_W orphans (#34740 / #34673 / #34689).
        $dimEntry = JIT\HashTableWriteLlvm::liveEntryPointerForWritableDim($this->context, $destVar);
        if (null !== $dimEntry) {
            return $dimEntry;
        }
        if (
            Variable::TYPE_VALUE === $destVar->type
            && Variable::KIND_VARIABLE === $destVar->kind
            && null === $destVar->writableHt
            && null === $destVar->objectPropertySlot
            && null === $destVar->staticPropertyGlobal
        ) {
            return JIT\JitValueBox::valuePtrFromVariable($this->context, $destVar);
        }

        return null;
    }

    /**
     * Record Closure invoke proxy for a compile-time array literal element (#24106 peer).
     *
     * foreach ($arr as $k => $fn) loses Variable::closureCall on $fn when the value is
     * loaded from a hashtable slot — RuntimeIndirectClosureCall then skips pending-throw
     * catch wiring and TypeError inside the closure SIGABRTs under AOT (#33971 peer).
     */
    private function registerArrayElementClosureCallProxy(
        Block $block,
        Operand $arrayResultOp,
        ?int $keyArg,
        Variable $element
    ): void {
        if (null === $element->closureCall) {
            return;
        }
        $arraySlot = $block->slotForOperand($arrayResultOp);
        if (null === $arraySlot) {
            return;
        }
        $keyLabel = $this->compileTimeArrayElementKeyLabel($block, $keyArg);
        if (null === $keyLabel) {
            return;
        }
        $this->context->closureCallByArrayResultSlot[$arraySlot][$keyLabel] = $element->closureCall;
        $this->context->closureCallOrderedByArrayResultSlot[$arraySlot][] = $element->closureCall;
    }

    /**
     * Restore ClosureWithCaptures on foreach value locals when the container was a literal (#24106).
     */
    /**
     * Foreach iter closures: runtime index dispatch into literal build-order table (#34240).
     */
    private function reattachForeachIterClosureInvokeMetadata(
        Block $block,
        Operand $arrayOp,
        Operand $destOp,
        Variable $value
    ): void {
        $result = $this->context->getVariableFromOp($destOp);
        $this->preserveClosureInvokeMetadata($destOp, $result, $value);
        $result->closureCall = null;
        $result->closureIsStatic = false;
        $result->closureIsMethodFake = false;
        $destSlot = $block->slotForOperand($destOp);
        if (null !== $destSlot) {
            unset($this->context->fccClosureCallByResultSlot[$destSlot]);
        }
        $arraySlot = $block->slotForOperand($arrayOp);
        if (null === $arraySlot) {
            $result->foreachClosureProxyTable = null;
            $result->foreachContainerSlotKey = null;

            return;
        }
        $table = $this->context->closureCallOrderedByArrayResultSlot[$arraySlot] ?? [];
        if ([] === $table) {
            $result->foreachClosureProxyTable = null;
            $result->foreachContainerSlotKey = null;

            return;
        }
        $arrayVar = $this->context->getVariableFromOp($arrayOp);
        $containerKey = $this->context->foreachSlotMapKey($arrayVar);
        if (!isset($this->context->foreachIndexSlots[$containerKey])) {
            throw new \LogicException(
                'foreach closure dispatch: missing index slot for container key '.$containerKey
            );
        }
        $result->foreachClosureProxyTable = $table;
        $result->foreachContainerSlotKey = $containerKey;
        $resolved = JIT\OperandName::resolve($destOp);
        if (null !== $resolved && '' !== $resolved) {
            $this->context->bindVariableByName($resolved, $result);
        }
    }

    private function compileTimeArrayElementKeyLabel(Block $block, ?int $keyArg): ?string
    {
        if (null === $keyArg) {
            return null;
        }
        $intKey = $this->tryCompileTimeArrayLiteralIntKey($block, $keyArg);
        if (null !== $intKey) {
            return (string) $intKey;
        }
        $op = $block->getOperand($keyArg);
        if ($op instanceof Operand\Literal) {
            if (is_string($op->value)) {
                return $op->value;
            }
            if (is_int($op->value)) {
                return (string) $op->value;
            }
        }
        if (isset($block->constants[$keyArg])) {
            $const = $block->constants[$keyArg];
            if (VM\Variable::TYPE_STRING === $const->type) {
                return $const->toString();
            }
            if (VM\Variable::TYPE_INTEGER === $const->type) {
                return (string) $const->toInt();
            }
        }
        $keyVar = $this->jitArrayElementKeyVariable($block, $keyArg);

        return $this->normalizeArrayElementKeyLabel($keyVar);
    }

    private function normalizeArrayElementKeyLabel(?Variable $key): ?string
    {
        if (null === $key) {
            return null;
        }
        if (null !== $key->compileTimeString) {
            return $key->compileTimeString;
        }

        return null;
    }

    /** Keep Closure invoke proxy across assigns into locals / value boxes (#24106, #23973). */
    private function preserveClosureInvokeMetadata(Operand $resultOp, Variable $result, Variable $value): void
    {
        if (null !== $value->foreachClosureProxyTable && [] !== $value->foreachClosureProxyTable) {
            $result->foreachClosureProxyTable = $value->foreachClosureProxyTable;
            $result->foreachContainerSlotKey = $value->foreachContainerSlotKey;
        }
        if (null === $value->closureCall) {
            if (null !== $value->foreachClosureProxyTable && [] !== $value->foreachClosureProxyTable) {
                $resolved = JIT\OperandName::resolve($resultOp);
                if (null !== $resolved && '' !== $resolved) {
                    $this->context->bindVariableByName($resolved, $result);
                }
            }

            return;
        }
        // FCC `$b = $obj->m(...)` is CFG-typed as array, so `$b` starts as a hashtable.
        // Stamping closureCall onto that HT leaves `$b()` aborting under AOT while
        // `((new C)->m(...))(3)` (temp, still object) works (#28613, peer #24106).
        if (Variable::TYPE_OBJECT === $value->type && Variable::TYPE_OBJECT !== $result->type) {
            $this->context->setVariableOp($resultOp, $value);
            $result = $value;
        }
        $result->closureCall = $value->closureCall;
        $result->closureIsStatic = $value->closureIsStatic;
        $result->closureIsMethodFake = $value->closureIsMethodFake;
        $resolved = JIT\OperandName::resolve($resultOp);
        if (null !== $resolved && '' !== $resolved) {
            $this->context->bindVariableByName($resolved, $result);
        }
        if (null !== $this->context->jitCurrentBlock) {
            $slot = $this->context->jitCurrentBlock->slotForOperand($resultOp);
            if (null !== $slot) {
                $this->context->fccClosureCallByResultSlot[$slot] = $value->closureCall;
            }
        }
    }

    /**
     * Stash Closure invoke proxy when a user function/method returns a known closure (#34868).
     *
     * Cross-function `$f = m(); $f()` cannot see the callee Variable::closureCall; without this
     * map EXEC_RETURN leaves a bare object and FUNCCALL_INIT falls through to a null callee.
     */
    private function recordFunctionReturnedClosureCall(Block $block, Variable $return): void
    {
        if (null === $return->closureCall || null === $block->func) {
            return;
        }
        $funcName = $block->func->name ?? null;
        if (!is_string($funcName) || '' === $funcName || '{main}' === $funcName) {
            return;
        }
        $lc = strtolower($funcName);
        if (null !== $block->func->class && is_string($block->func->class->value ?? null)) {
            $classLc = strtolower(ltrim((string) $block->func->class->value, '\\'));
            if ('' !== $classLc) {
                $lc = $classLc.'::'.$lc;
            }
        }
        $this->context->functionReturnedClosureCall[$lc] = $return->closureCall;
    }

    /**
     * Reattach callee-returned Closure invoke metadata onto EXEC_RETURN's result (#34868).
     */
    private function attachReturnedClosureInvokeMetadata(Block $block, OpCode $op): void
    {
        $toCall = $this->context->scope->toCall;
        $lc = null;
        if ($toCall instanceof JIT\Call\Native) {
            $lc = strtolower($toCall->name);
        } elseif ($toCall instanceof CoreFunc\Internal) {
            $lc = strtolower($toCall->getName());
        }
        if (null === $lc || !isset($this->context->functionReturnedClosureCall[$lc])) {
            return;
        }
        $proxy = $this->context->functionReturnedClosureCall[$lc];
        $resultOp = $block->getOperand($op->arg1);
        if (null === $resultOp || !$this->context->hasVariableOp($resultOp)) {
            return;
        }
        $var = $this->context->getVariableFromOp($resultOp);
        // Caller-frame result Variable — safe to use as closureObject for heap reload (#35456).
        if ($proxy instanceof JIT\Call\ClosureWithBinding) {
            $proxy = $proxy->withClosureObject($var);
        }
        $var->closureCall = $proxy;
        $resolved = JIT\OperandName::resolve($resultOp);
        if (null !== $resolved && '' !== $resolved) {
            $this->context->bindVariableByName($resolved, $var);
        }
        $this->context->fccClosureCallByResultSlot[(int) $op->arg1] = $proxy;
        $slot = $block->slotForOperand($resultOp);
        if (null !== $slot) {
            $this->context->fccClosureCallByResultSlot[$slot] = $proxy;
        }
    }

    /**
     * Closure::bind / bindTo boxReturn() drops Variable::closureCall; reattach the
     * ClosureWithBinding stashed on lastClosureCallProxy so `$b()` / immediate
     * invoke use bound $this + scope instead of RuntimeIndirect abort (#27219).
     */
    private function attachBoundClosureInvokeMetadata(Block $block, OpCode $op): void
    {
        $toCall = $this->context->scope->toCall;
        if (
            !($toCall instanceof JIT\Call\ClosureBindTo)
            && !($toCall instanceof JIT\Call\ClosureBind)
        ) {
            return;
        }
        $proxy = $this->context->lastClosureCallProxy;
        if (!($proxy instanceof JIT\Call\ClosureWithBinding)) {
            return;
        }
        $resultOp = $block->getOperand($op->arg1);
        if ($this->context->hasVariableOp($resultOp)) {
            $var = $this->context->getVariableFromOp($resultOp);
            $var->closureCall = $proxy;
            $resolved = JIT\OperandName::resolve($resultOp);
            if (null !== $resolved && '' !== $resolved) {
                $this->context->bindVariableByName($resolved, $var);
            }
        }
        $this->context->fccClosureCallByResultSlot[(int) $op->arg1] = $proxy;
    }

    /**
     * Recover FCC invoke proxy when temp/local metadata was dropped before FUNCCALL_INIT (#24166).
     */
    private function resolveFccClosureCallForCalleeSlot(Block $block, int $nameSlot, array &$visited = []): ?JIT\Call
    {
        if (isset($visited[$nameSlot])) {
            return null;
        }
        $visited[$nameSlot] = true;
        if (isset($this->context->fccClosureCallByResultSlot[$nameSlot])) {
            return $this->context->fccClosureCallByResultSlot[$nameSlot];
        }
        foreach ($block->opCodes as $prior) {
            if (OpCode::TYPE_ASSIGN !== $prior->type || (int) $prior->arg2 !== $nameSlot) {
                if (OpCode::TYPE_ITER_VALUE === $prior->type && (int) $prior->arg1 === $nameSlot) {
                    $destOp = $block->getOperand($prior->arg1);
                    if (null !== $destOp && $this->context->hasVariableOp($destOp)) {
                        $var = $this->context->getVariableFromOp($destOp);
                        if (null !== $var->closureCall) {
                            return $var->closureCall;
                        }
                        $table = $var->foreachClosureProxyTable ?? null;
                        $slotKey = $var->foreachContainerSlotKey ?? null;
                        if (null !== $table && [] !== $table && is_string($slotKey) && '' !== $slotKey) {
                            return new JIT\Call\ForeachIndexedClosureCall($var, $table, $slotKey);
                        }
                    }
                }
                continue;
            }
            $resolved = $this->resolveFccClosureCallForCalleeSlot($block, (int) $prior->arg3, $visited);
            if (null !== $resolved) {
                return $resolved;
            }
        }

        return null;
    }

    private function bindDimFetchReadResult(Operand $resultOp, Variable $fetched, bool $forceBranchMerge): void
    {
        if (
            Variable::TYPE_VALUE === $fetched->type
            && $this->context->hasVariableOp($resultOp)
            && Variable::TYPE_OBJECT === $this->context->getVariableFromOp($resultOp)->type
        ) {
            // Object-typed temps from fromOp are uninitialized __object__** slots — bind the
            // HT value box directly so chained ->prop uses __value__readObject (#31938).
            $this->context->setVariableOp($resultOp, $fetched);
            $fetched->addref();

            return;
        }
        if ($forceBranchMerge) {
            $this->assignOperand($resultOp, $fetched, true);
        } else {
            $this->assignOperand($resultOp, $fetched);
        }
    }

    private function valueBoxPointer(Variable $value): PHPLLVM\Value
    {
        return JIT\JitValueBox::valuePtrFromVariable($this->context, $value);
    }

    private function unboxValueToNativeDouble(Variable $value): PHPLLVM\Value
    {
        $valuePtr = $this->valueBoxPointer($value);
        $map = $this->context->structFieldMap['__value__'];
        $typeByte = $this->context->builder->load(
            $this->context->builder->structGep($valuePtr, $map['type'])
        );
        $i8 = $this->context->getTypeFromString('int8');
        $doubleTy = $this->context->getTypeFromString('double');
        $isDouble = $this->context->builder->icmp(
            \PHPLLVM\Builder::INT_EQ,
            $typeByte,
            $i8->constInt(Variable::TYPE_NATIVE_DOUBLE, false)
        );
        $isLong = $this->context->builder->icmp(
            \PHPLLVM\Builder::INT_EQ,
            $typeByte,
            $i8->constInt(Variable::TYPE_NATIVE_LONG, false)
        );
        $readDouble = $this->context->builder->call(
            $this->context->lookupFunction('__value__readDouble'),
            $valuePtr
        );
        $readLong = $this->context->builder->call(
            $this->context->lookupFunction('__value__readLong'),
            $valuePtr
        );
        $fromLong = $this->context->builder->siToFp($readLong, $doubleTy);

        return $this->context->builder->select(
            $isDouble,
            $readDouble,
            $this->context->builder->select($isLong, $fromLong, $doubleTy->constReal(0.0))
        );
    }

    private function assignOperandValue(Operand $result, PHPLLVM\Value $value, bool $force = false): void {
        if (!$force && empty($result->usages) && !$this->context->scope->variables->contains($result)) {
            return;
        }
        if (!$this->context->hasVariableOp($result)) {
            $this->context->makeVariableFromValueOp($value, $result);

            return;
        }
        $dest = $this->context->getVariableFromOp($result);
        if ($dest->kind !== Variable::KIND_VARIABLE) {
            if ($dest->functionStaticGlobal) {
                $source = new Variable(
                    $this->context,
                    Variable::TYPE_VALUE,
                    Variable::KIND_VALUE,
                    $value
                );
                $this->assignOperand($result, $source);

                return;
            }
            $name = JIT\OperandName::resolve($result);
            if (null === $name || '' === $name) {
                $block = $this->context->jitEnclosingBlock;
                if (null !== $block) {
                    $slot = $block->slotForOperand($result);
                    if (null !== $slot) {
                        foreach ($block->scopedOperands() as $scopeOp) {
                            if ($block->slotForOperand($scopeOp) !== $slot) {
                                continue;
                            }
                            $scopeName = JIT\OperandName::resolve($scopeOp);
                            if (null !== $scopeName && '' !== $scopeName) {
                                $name = $scopeName;
                                break;
                            }
                        }
                    }
                }
            }
            if (null !== $name && '' !== $name && isset($this->context->jitImportedGlobalNames[$name])) {
                $source = new Variable(
                    $this->context,
                    Variable::TYPE_VALUE,
                    Variable::KIND_VALUE,
                    $value
                );
                $this->assignOperand($result, $source);

                return;
            }
            $valueTyEarly = $this->context->getStringFromType($value->typeOf());
            if (
                Variable::KIND_VALUE === $dest->kind
                && Variable::TYPE_STRING === $dest->type
                && ('int1' === $valueTyEarly || 'bool' === $valueTyEarly)
            ) {
                // && short-circuit / boolean-not can target a phi slot still typed string (#1492, #16828).
                $this->context->setVariableOp(
                    $result,
                    new Variable(
                        $this->context,
                        Variable::TYPE_NATIVE_BOOL,
                        Variable::KIND_VALUE,
                        $value
                    )
                );

                return;
            }
            if (Variable::KIND_VALUE === $dest->kind) {
                // Folded spine-guard bindings and foreach/try phi temps (#16828).
                $this->context->makeVariableFromValueOp($value, $result);

                return;
            }
            throw new \LogicException('Cannot assignOperandValue to a value');
        }
        $valueTy = $this->context->getStringFromType($value->typeOf());
        $destTy = $this->context->getStringFromType($dest->value->typeOf());
        if (Variable::TYPE_NATIVE_BOOL === $dest->type) {
            if ('__value__' === $valueTy || '__value__*' === $valueTy) {
                // By-value `__value__` must be stored into an alloca first — pointer() on a
                // struct yields illegal addrspacecast %__value__ → %__value__* (#27346).
                $valuePtr = JIT\JitValueBox::coerceToValuePtrForStore($this->context, $value);
                $dest->free();
                $slot = JIT\JitValueBox::alloc($this->context);
                JIT\JitValueBox::copyFromPointer($this->context, $slot, $valuePtr);
                $this->context->setVariableOp(
                    $result,
                    new Variable(
                        $this->context,
                        Variable::TYPE_VALUE,
                        Variable::KIND_VARIABLE,
                        $slot
                    )
                );

                return;
            }
            if ('int1' === $valueTy || 'bool' === $valueTy) {
                if (Variable::KIND_VALUE === $dest->kind) {
                    $dest->free();
                    $this->context->setVariableOp(
                        $result,
                        Variable::fromValueOp($this->context, $value, $result)
                    );

                    return;
                }
                $dest->free();
                $this->context->builder->store($value, $dest->value);
                $dest->addref();

                return;
            }
        }
        if (Variable::TYPE_NATIVE_LONG === $dest->type || Variable::TYPE_NATIVE_DOUBLE === $dest->type) {
            if ('__value__' === $valueTy || '__value__*' === $valueTy) {
                // Property-hook get returns by-value `__value__` into a typed int/float slot
                // (PROFILE=8.4); coerce rather than pointerCast the struct (#27346).
                $valuePtr = JIT\JitValueBox::coerceToValuePtrForStore($this->context, $value);
                $dest->free();
                $slot = JIT\JitValueBox::alloc($this->context);
                JIT\JitValueBox::copyFromPointer($this->context, $slot, $valuePtr);
                $this->context->setVariableOp(
                    $result,
                    new Variable(
                        $this->context,
                        Variable::TYPE_VALUE,
                        Variable::KIND_VARIABLE,
                        $slot
                    )
                );

                return;
            }
        }
        if ('__string__*' === $valueTy && Variable::TYPE_VALUE === $dest->type) {
            // Replace the value-box Variable with a typed string. The previous path wrote the
            // string into the existing box but set isNullConstant while emitting the null-ptr
            // IR arm — that PHP-side flag stuck even when the runtime takes the copy arm, so
            // UnhandledMatchError::__construct saw a "null" message after match helpers (#29747).
            $dest->free();
            unset($this->context->scope->variables[$result]);
            $this->context->setVariableOp(
                $result,
                new Variable(
                    $this->context,
                    Variable::TYPE_STRING,
                    Variable::KIND_VALUE,
                    $value
                )
            );

            return;
        }
        if ('__object__*' === $valueTy && Variable::TYPE_VALUE === $dest->type) {
            $slot = JIT\BasicBlockHelper::entryAlloca($this->context, $this->context->getTypeFromString('__object__*'));
            $this->context->builder->store($value, $slot);
            $var = new Variable(
                $this->context,
                Variable::TYPE_OBJECT,
                Variable::KIND_VARIABLE,
                $slot
            );
            $var->addref();
            $this->context->setVariableOp($result, $var);
            $resolved = JIT\OperandName::resolve($result);
            if (null !== $resolved && '' !== $resolved) {
                $this->context->bindVariableByName(
                    $this->context->resolveRefAliasName($resolved),
                    $var
                );
            }

            return;
        }
        if ('__value__*' === $valueTy && Variable::TYPE_VALUE === $dest->type) {
            $dest->free();
            $isNullPtr = $this->context->builder->icmp(
                PHPLLVM\Builder::INT_EQ,
                $value,
                $value->typeOf()->constNull()
            );
            $nullBlock = JIT\BasicBlockHelper::append($this->context, 'assign_value_null_ptr');
            $copyBlock = JIT\BasicBlockHelper::append($this->context, 'assign_value_copy_ptr');
            $doneBlock = JIT\BasicBlockHelper::append($this->context, 'assign_value_ptr_done');
            $this->context->builder->branchIf($isNullPtr, $nullBlock, $copyBlock);
            $this->context->builder->positionAtEnd($nullBlock);
            $this->context->builder->call(
                $this->context->lookupFunction('__value__writeNull'),
                JIT\JitValueBox::pointer($this->context, $dest->value)
            );
            $this->context->builder->branch($doneBlock);
            $this->context->builder->positionAtEnd($copyBlock);
            JIT\JitValueBox::copyFromPointer($this->context, $dest->value, $value);
            $this->context->builder->branch($doneBlock);
            $this->context->builder->positionAtEnd($doneBlock);
            $dest->addref();

            return;
        }
        $source = new Variable(
            $this->context,
            $this->jitTypeFromLlvmValue($value),
            Variable::KIND_VALUE,
            $value
        );
        if ($source->type === $dest->type) {
            $dest->free();
            if (Variable::TYPE_VALUE === $dest->type && ('__value__' === $destTy || '__value__*' === $destTy)) {
                $destLlvm = $dest->value->typeOf();
                $destPointsAtStruct = '__value__' === $destTy;
                if (
                    '__value__*' === $destTy
                    && \PHPLLVM\Type::KIND_POINTER === $destLlvm->getKind()
                    && '__value__' === $this->context->getStringFromType($destLlvm->getElementType())
                ) {
                    $destPointsAtStruct = true;
                }
                if ('__value__' === $valueTy && $destPointsAtStruct) {
                    $this->context->builder->store($value, $dest->value);
                    $dest->addref();
                    $this->copyValueBoxJitFlags($dest, $source);

                    return;
                }
                $ptr = '__value__*' === $valueTy
                    ? $value
                    : $this->valueBoxPointer($source);
                if ($destPointsAtStruct) {
                    JIT\JitValueBox::copyFromPointer($this->context, $dest->value, $ptr);
                } else {
                    $this->context->builder->store($ptr, $dest->value);
                }
                $dest->addref();
                $this->copyValueBoxJitFlags($dest, $source);

                return;
            }
            $toStore = $value;
            if ('__value__*' === $valueTy && '__value__' === $destTy) {
                $toStore = $this->context->builder->load($value);
            }
            $this->context->builder->store($toStore, $dest->value);
            $dest->addref();
            $this->copyValueBoxJitFlags($dest, $source);

            return;
        }
        $this->assignOperand($result, $source);
    }

    private function syncCompileTimeString(Variable $dest, Variable $src, bool $force): void
    {
        if ($force || null !== $src->compileTimeString) {
            $dest->compileTimeString = $src->compileTimeString;
        }
        if ($force || null !== $src->classUserType) {
            $dest->classUserType = $src->classUserType;
        }
    }

    private function syncCompileTimeFloat(Variable $dest, Variable $src, bool $force): void
    {
        if ($force || null !== $src->compileTimeFloat) {
            $dest->compileTimeFloat = $src->compileTimeFloat;
        }
    }

    private function syncCompileTimeBcmathNumber(Variable $dest, Variable $src, bool $force): void
    {
        // Only propagate present metadata. Force-merge must not wipe construct-time
        // Number value/scale used for AOT fold (#24683); script-global invalidate
        // clears then re-syncs from the assign source.
        if (null !== $src->compileTimeBcmathNumber) {
            $dest->compileTimeBcmathNumber = $src->compileTimeBcmathNumber;
        }
    }

    private function syncCompileTimeDomTagName(Variable $dest, Variable $src, bool $force): void
    {
        if ($force || null !== $src->compileTimeDomTagName) {
            $dest->compileTimeDomTagName = $src->compileTimeDomTagName;
        }
        if ($force || null !== $src->compileTimeDomInnerXml) {
            $dest->compileTimeDomInnerXml = $src->compileTimeDomInnerXml;
        }
        if ($force || null !== $src->compileTimeDomInnerXmlParent) {
            $dest->compileTimeDomInnerXmlParent = $src->compileTimeDomInnerXmlParent;
        }
        // firstChild/lastChild index for thin-AOT replaceChild INNER_XML rebuild (#28671).
        if ($force || null !== $src->compileTimeDomChildIndex) {
            $dest->compileTimeDomChildIndex = $src->compileTimeDomChildIndex;
        }
        if ($force || null !== $src->compileTimeDomNodePath) {
            $dest->compileTimeDomNodePath = $src->compileTimeDomNodePath;
        }
        if ($force || null !== $src->compileTimeDomLineNo) {
            $dest->compileTimeDomLineNo = $src->compileTimeDomLineNo;
        }
        if ($force || null !== $src->compileTimeDomTextData) {
            $dest->compileTimeDomTextData = $src->compileTimeDomTextData;
        }
        if ($force || null !== $src->compileTimeDomAttributes) {
            // Copy the bag — a shared array ref lets later setAttribute / mutation see the
            // wrong identity after replaceChild synced oldChild onto the result (#35386).
            if (null === $src->compileTimeDomAttributes) {
                $dest->compileTimeDomAttributes = null;
            } else {
                $copied = [];
                foreach ($src->compileTimeDomAttributes as $attrName => $attrVal) {
                    $copied[$attrName] = $attrVal;
                }
                $dest->compileTimeDomAttributes = $copied;
            }
        }
        if ($force || null !== $src->compileTimeDomElementId) {
            $dest->compileTimeDomElementId = $src->compileTimeDomElementId;
        }
        if ($force || null !== $src->compileTimeDomAttrLocalName) {
            $dest->compileTimeDomAttrLocalName = $src->compileTimeDomAttrLocalName;
        }
        if ($force || null !== $src->compileTimeDomAttrNamespace) {
            $dest->compileTimeDomAttrNamespace = $src->compileTimeDomAttrNamespace;
        }
        if ($force || null !== $src->compileTimeDomLoadXml) {
            $dest->compileTimeDomLoadXml = $src->compileTimeDomLoadXml;
        }
        if ($force || null !== $src->compileTimeDomNodeListLength) {
            $dest->compileTimeDomNodeListLength = $src->compileTimeDomNodeListLength;
        }
        if ($force || null !== $src->compileTimeDomImportHostSxeToken) {
            $dest->compileTimeDomImportHostSxeToken = $src->compileTimeDomImportHostSxeToken;
        }
    }

    private function syncCompileTimeDatePeriod(Variable $dest, Variable $src, bool $force): void
    {
        if ($force || null !== $src->compileTimeLong) {
            $dest->compileTimeLong = $src->compileTimeLong;
        }
        if ($force || null !== $src->compileTimeDatePeriodTimestamps) {
            $dest->compileTimeDatePeriodTimestamps = $src->compileTimeDatePeriodTimestamps;
            $dest->compileTimeDatePeriodTimezone = $src->compileTimeDatePeriodTimezone;
        }
        if ($force || \is_array($src->compileTimeDatePeriodSerialize)) {
            $dest->compileTimeDatePeriodSerialize = $src->compileTimeDatePeriodSerialize;
        }
        if ($force || null !== $src->compileTimeDateInterval) {
            $dest->compileTimeDateInterval = $src->compileTimeDateInterval;
        }
        // DateTimeZone zone id — must not share compileTimeString with class name (#29732).
        if ($force || null !== $src->compileTimeTimezoneName) {
            $dest->compileTimeTimezoneName = $src->compileTimeTimezoneName;
        }
        if ($force || null !== $src->compileTimeDateTimeTimestamp) {
            $dest->compileTimeDateTimeTimestamp = $src->compileTimeDateTimeTimestamp;
            $dest->compileTimeDateTimeMicrosecond = $src->compileTimeDateTimeMicrosecond;
        }
    }

    /**
     * Record that a named local holds a DateTimeZone with a known id (#29732).
     */
    private function noteDateTimeZoneLocal(Operand $resultOp, Variable $value): void
    {
        if (null === $value->compileTimeTimezoneName || '' === $value->compileTimeTimezoneName) {
            return;
        }
        $assignedName = JIT\OperandName::resolve($resultOp);
        if (null === $assignedName || '' === $assignedName) {
            return;
        }
        $resolved = $this->context->resolveRefAliasName($assignedName);
        $this->context->lastAssignedDateTimeZoneLocalName = $resolved;
        $this->context->dateTimeZoneLocalNames[$resolved] = $value->compileTimeTimezoneName;
        $this->context->bindVariableByName($resolved, $value);
        $this->context->scope->variables[$resultOp] = $value;
    }

    /** Record that a named local holds a DateTime instant (#32691). */
    private function noteDateTimeLocal(Operand $resultOp, Variable $value): void
    {
        if (null === $value->compileTimeDateTimeTimestamp) {
            return;
        }
        $assignedName = JIT\OperandName::resolve($resultOp);
        if (null === $assignedName || '' === $assignedName) {
            return;
        }
        $resolved = $this->context->resolveRefAliasName($assignedName);
        $this->context->dateTimeLocalInstants[$resolved] = [
            'timestamp' => (int) $value->compileTimeDateTimeTimestamp,
            'timezone' => $value->compileTimeTimezoneName,
            'microsecond' => (int) ($value->compileTimeDateTimeMicrosecond ?? 0),
            'className' => $value->compileTimeDateTimeClassName ?? $value->classUserType ?? 'DateTime',
        ];
        $this->context->bindVariableByName($resolved, $value);
    }

    private function copyValueBoxJitFlags(Variable $dest, Variable $src, bool $force = false): void
    {
        if (Variable::TYPE_VALUE !== $dest->type || Variable::TYPE_VALUE !== $src->type) {
            return;
        }
        $dest->valueBoxHashtable = $src->valueBoxHashtable;
        $dest->compileTimeEmptyArrayLiteral = $src->compileTimeEmptyArrayLiteral;
        $dest->isNullConstant = $src->isNullConstant;
        $dest->compileTimeConstantName = $src->compileTimeConstantName;
        $dest->compileTimeEnumCase = $src->compileTimeEnumCase;
        $dest->compileTimeLong = $src->compileTimeLong;
        $this->syncCompileTimeString($dest, $src, $force);
        $this->syncCompileTimeFloat($dest, $src, $force);
        $this->syncCompileTimeBcmathNumber($dest, $src, $force);
        $this->syncCompileTimeDomTagName($dest, $src, $force);
        $this->syncCompileTimeDatePeriod($dest, $src, $force);
    }

    /** Keep borrowed object-property hashtable metadata on locals ($cfg = $this->config, #848). */
    private function maybeCopyObjectPropertyBacking(Variable $dest, Variable $src, bool $force): void
    {
        // Branch-merge assigns (?-> / ??) must read the unified __value__ slot at the merge block (#3219).
        // ??= write-fetch is the exception: dropping objectPropertySlot loses the store (#33748).
        if (
            $force
            && $this->context->retainCoalesceInstancePropertyLvalue
            && null !== $src->objectPropertySlot
        ) {
            $this->copyObjectPropertyBacking($dest, $src);
        } elseif ($force) {
            $dest->objectPropertySlot = null;
            $dest->objectPropertyType = null;
            $dest->objectPropertyReceiver = null;
            $dest->objectPropertyName = null;
            $dest->objectPropertyClassName = null;
            $dest->objectPropertyDnfArms = null;
            $dest->objectPropertyClassConstraint = null;
            $dest->objectPropertyDeclaredTypeLabel = null;
        } elseif ($this->isScalarObjectPropertyAliasType($src->objectPropertyType)) {
            // Scalar prop reads: copy the value only (#34465 / peer #33849).
            $dest->objectPropertySlot = null;
            $dest->objectPropertyType = null;
            $dest->objectPropertyReceiver = null;
            $dest->objectPropertyReceiverOp = null;
            $dest->objectPropertyName = null;
            $dest->objectPropertyClassName = null;
            $dest->objectPropertyDnfArms = null;
            $dest->objectPropertyClassConstraint = null;
            $dest->objectPropertyDeclaredTypeLabel = null;
        } else {
            $this->copyObjectPropertyBacking($dest, $src);
        }
        if (JIT\GeneratorHelper::isGeneratorVariable($src)) {
            $dest->generatorStatePtr = $src->generatorStatePtr;
            $dest->generatorResumeName = $src->generatorResumeName;
            $dest->isJitGenerator = $src->isJitGenerator;
        }
        if (null !== $src->closureCall) {
            $dest->closureCall = $src->closureCall;
        }
        if (null !== $src->foreachClosureProxyTable) {
            $dest->foreachClosureProxyTable = $src->foreachClosureProxyTable;
            $dest->foreachContainerSlotKey = $src->foreachContainerSlotKey;
        }
        if ($src->closureIsStatic) {
            $dest->closureIsStatic = true;
        }
        if ($src->closureIsMethodFake) {
            $dest->closureIsMethodFake = true;
        }
        if (null !== $src->fiberResumeName) {
            $dest->fiberResumeName = $src->fiberResumeName;
            $dest->fiberStatePtr = $src->fiberStatePtr;
        }
    }

    private function copyObjectPropertyBacking(Variable $dest, Variable $src): void
    {
        $dest->objectPropertySlot = $src->objectPropertySlot;
        $dest->objectPropertyType = $src->objectPropertyType;
        $dest->objectPropertyReceiver = $src->objectPropertyReceiver;
        $dest->objectPropertyReceiverOp = $src->objectPropertyReceiverOp;
        $dest->objectPropertyName = $src->objectPropertyName;
        $dest->objectPropertyClassName = $src->objectPropertyClassName;
        $dest->objectPropertyDnfArms = $src->objectPropertyDnfArms;
        $dest->objectPropertyClassConstraint = $src->objectPropertyClassConstraint;
        $dest->objectPropertyDeclaredTypeLabel = $src->objectPropertyDeclaredTypeLabel;
        $dest->closureCall = $src->closureCall;
        $dest->closureIsStatic = $src->closureIsStatic;
        $dest->closureIsMethodFake = $src->closureIsMethodFake;
        if (null !== $src->foreachClosureProxyTable) {
            $dest->foreachClosureProxyTable = $src->foreachClosureProxyTable;
            $dest->foreachContainerSlotKey = $src->foreachContainerSlotKey;
        }
        $dest->generatorStatePtr = $src->generatorStatePtr;
        $dest->generatorResumeName = $src->generatorResumeName;
        $dest->isJitGenerator = $src->isJitGenerator;
        $dest->fiberResumeName = $src->fiberResumeName;
        $dest->fiberStatePtr = $src->fiberStatePtr;
        if (Variable::TYPE_OBJECT === $src->type && Variable::TYPE_OBJECT === $dest->type) {
            $srcKey = spl_object_id($this->context->helper->loadValue($src));
            $destKey = spl_object_id($this->context->helper->loadValue($dest));
            if (isset($this->context->fiberResumeByObjectValueId[$srcKey])) {
                $this->context->fiberResumeByObjectValueId[$destKey]
                    = $this->context->fiberResumeByObjectValueId[$srcKey];
            }
        }
    }

    /**
     * Write a JIT value into an embedded {@see __value__} field on generator state (#3074).
     */
    public function assignValueToGeneratorField(
        \PHPLLVM\Value $destField,
        Variable $src,
        ?Operand $srcOp
    ): void {
        $destPtr = JIT\JitValueBox::normalizeValuePtr($this->context, $destField);
        if (JIT\Variable::TYPE_STRING === $src->type) {
            $this->context->builder->call(
                $this->context->lookupFunction('__value__writeString'),
                $destPtr,
                $this->context->helper->loadValue($src)
            );

            return;
        }
        if (JIT\Variable::TYPE_NATIVE_LONG === $src->type) {
            $this->context->builder->call(
                $this->context->lookupFunction('__value__writeLong'),
                $destPtr,
                $this->context->helper->loadValue($src)
            );

            return;
        }
        if (JIT\Variable::TYPE_NATIVE_DOUBLE === $src->type) {
            $this->context->builder->call(
                $this->context->lookupFunction('__value__writeDouble'),
                $destPtr,
                $this->context->helper->loadValue($src)
            );

            return;
        }
        if (JIT\Variable::TYPE_NATIVE_BOOL === $src->type) {
            $this->context->builder->call(
                $this->context->lookupFunction('__value__writeBool'),
                $destPtr,
                $this->context->helper->loadValue($src)
            );

            return;
        }
        if (JIT\Variable::TYPE_NULL === $src->type) {
            $this->context->builder->call(
                $this->context->lookupFunction('__value__writeNull'),
                $destPtr
            );

            return;
        }
        if (JIT\Variable::TYPE_VALUE === $src->type) {
            JIT\JitValueBox::copyFromPointer(
                $this->context,
                $destField,
                JIT\JitValueBox::valuePtrFromVariable($this->context, $src)
            );

            return;
        }
        if (JIT\Variable::TYPE_OBJECT === $src->type) {
            $this->context->builder->call(
                $this->context->lookupFunction('__value__writeObject'),
                $destPtr,
                $this->context->helper->loadValue($src)
            );

            return;
        }
        if (JIT\Variable::TYPE_HASHTABLE === $src->type) {
            $ht = $this->context->helper->loadValue($src);
            $this->context->refcount->addref($ht);
            $this->context->builder->call(
                $this->context->lookupFunction('__value__writeHashtable'),
                $destPtr,
                $ht
            );

            return;
        }
        if (0 !== ($src->type & JIT\Variable::IS_NATIVE_ARRAY)) {
            // materialize addrefs to rc=1; writeHashtable → rc=2; delref → sole owner (#36388).
            $htPtr = JIT\HashTableHelper::materializeNativeArrayForCall($this->context, $src);
            $this->context->builder->call(
                $this->context->lookupFunction('__value__writeHashtable'),
                $destPtr,
                $htPtr
            );
            $this->context->refcount->delref(
                $this->context->builder->pointerCast(
                    $htPtr,
                    $this->context->getTypeFromString('__ref__virtual*')
                )
            );

            return;
        }
        if (null !== $srcOp) {
            $lit = $srcOp instanceof Operand\Literal ? $srcOp : null;
            if (null !== $lit && null !== $lit->type) {
                $boxed = JIT\Variable::fromLiteral($this->context, $lit);
                $this->assignValueToGeneratorField($destField, $boxed, null);

                return;
            }
        }
        throw new \LogicException('Unsupported generator yield value type in JIT (issue #3074)');
    }

    private function markJitThisConstructedIfLeavingConstruct(Block $block): void
    {
        if (!$this->isJitConstructFrame($block)) {
            return;
        }
        $thisVar = $this->resolveThisVariable($block);
        if (null === $thisVar || Variable::TYPE_OBJECT !== $thisVar->type) {
            return;
        }
        $this->context->type->object->markObjectConstructed(
            $this->context->helper->loadValue($thisVar)
        );
    }

    private function isJitConstructFrame(Block $block): bool
    {
        $func = $block->func ?? null;
        if (null === $func) {
            return false;
        }
        $name = strtolower($func->name);

        return '__construct' === $name || str_ends_with($name, '::__construct');
    }

    /** Void JIT __construct Call proxies whose EXEC_RETURN must not wipe `new` (#23641). */
    private function isVoidJitConstructCall(?JIT\Call $toCall): bool
    {
        if (null === $toCall) {
            return false;
        }
        if ($toCall instanceof JIT\Call\ExceptionConstruct) {
            return true;
        }
        if (
            $toCall instanceof JIT\Call\RuntimeIndirectInstanceMethodCall
            && '__construct' === $toCall->methodLc
        ) {
            return true;
        }
        if (
            $toCall instanceof JIT\Call\NoOpConstruct
        ) {
            return true;
        }
        if ($toCall instanceof JIT\Call\ReflectionClassConstruct
            || $toCall instanceof JIT\Call\ReflectionObjectConstruct
            || $toCall instanceof JIT\Call\ReflectionFunctionConstruct
            || $toCall instanceof JIT\Call\ReflectionExtensionConstruct
            || $toCall instanceof JIT\Call\ReflectionParameterConstruct
            || $toCall instanceof JIT\Call\ReflectionPropertyConstruct
            || $toCall instanceof JIT\Call\ReflectionMethodConstruct
            || $toCall instanceof JIT\Call\ReflectionClassConstantConstruct
            || $toCall instanceof JIT\Call\ReflectionConstantConstruct
            || $toCall instanceof JIT\Call\ReflectionEnumConstruct
            || $toCall instanceof JIT\Call\RandomizerConstruct
            || $toCall instanceof JIT\Call\SimpleXMLElementConstruct
            || $toCall instanceof JIT\Call\BcMathNumberConstruct
            || $toCall instanceof JIT\Call\SensitiveParameterValueConstruct
            || $toCall instanceof JIT\Call\DateTimeConstruct
            || $toCall instanceof JIT\Call\DateTimeImmutableConstruct
            || $toCall instanceof JIT\Call\DateTimeZoneConstruct
            || $toCall instanceof JIT\Call\DateIntervalConstruct
            || $toCall instanceof JIT\Call\DatePeriodConstruct
            || $toCall instanceof JIT\Call\DomDocumentConstruct
            || $toCall instanceof JIT\Call\ZipArchiveConstruct
            || $toCall instanceof JIT\Call\PdoConstruct
            || $toCall instanceof JIT\Call\ArrayIteratorConstruct
            || $toCall instanceof JIT\Call\RecursiveIteratorIteratorConstruct
            || $toCall instanceof JIT\Call\LimitIteratorConstruct
            || $toCall instanceof JIT\Call\RegexIteratorConstruct
            || $toCall instanceof JIT\Call\CallbackFilterIteratorConstruct
            || $toCall instanceof JIT\Call\CachingIteratorConstruct
            || $toCall instanceof JIT\Call\ParentIteratorConstruct
            || $toCall instanceof JIT\Call\RecursiveTreeIteratorConstruct
            || ($toCall instanceof JIT\Call\AppendIteratorMethod
                && '__construct' === strtolower($toCall->methodName()))
            || ($toCall instanceof JIT\Call\MultipleIteratorMethod
                && '__construct' === strtolower($toCall->methodName()))
            || ($toCall instanceof JIT\Call\SplHtPosIteratorMethod
                && '__construct' === strtolower($toCall->methodName()))
            || ($toCall instanceof JIT\Call\EmptyIteratorMethod
                && '__construct' === strtolower($toCall->methodName()))
            || ($toCall instanceof JIT\Call\FilterIteratorMethod
                && '__construct' === strtolower($toCall->methodName()))
            || ($toCall instanceof JIT\Call\SplHeapMethod
                && '__construct' === strtolower($toCall->methodName()))
            || ($toCall instanceof JIT\Call\SplPriorityQueueMethod
                && '__construct' === strtolower($toCall->methodName()))
            || ($toCall instanceof JIT\Call\SplDllistMethod
                && '__construct' === strtolower($toCall->methodName()))
            || ($toCall instanceof JIT\Call\SplFixedArrayMethod
                && '__construct' === strtolower($toCall->methodName()))
            || ($toCall instanceof JIT\Call\DirectoryIteratorMethod
                && '__construct' === strtolower($toCall->methodName()))
            || ($toCall instanceof JIT\Call\SplFileObjectMethod
                && '__construct' === strtolower($toCall->methodName()))
            || ($toCall instanceof JIT\Call\Sqlite3Method
                && '__construct' === strtolower($toCall->methodName()))
            || ($toCall instanceof JIT\Call\GlobIteratorMethod
                && '__construct' === strtolower($toCall->methodName()))
        ) {
            return true;
        }
        if ($toCall instanceof JIT\Call\Native || $toCall instanceof JIT\Call\ExternalMethod) {
            $name = strtolower(
                $toCall instanceof JIT\Call\Native ? $toCall->name : $toCall->proxyName
            );

            return str_ends_with($name, '::__construct');
        }

        return false;
    }

    /**
     * Like {@see isVoidJitConstructCall} but DateTime ctors return the initialized object box (#35752).
     */
    private function isVoidJitConstructCallThatDiscardsExecReturn(?JIT\Call $toCall): bool
    {
        if (!$this->isVoidJitConstructCall($toCall)) {
            return false;
        }
        if (
            $toCall instanceof JIT\Call\DateTimeConstruct
            || $toCall instanceof JIT\Call\DateTimeImmutableConstruct
        ) {
            return false;
        }

        return true;
    }


    /**
     * After a call with by-ref out parameters, mark those CVs assigned so later
     * ZEND_CHECK_UNDEFINED_VAR stays quiet (#36081 regression on j08_preg / preg_match $matches).
     *
     * php-src: zend_execute.c — callee write through ZEND_SEND_REF defines the CV.
     *
     * @param list<Operand> $callOperands
     */
    private function markByRefOutParamsAssignedAfterCall(
        ?JIT\Call $toCall,
        array $callOperands,
        Block $block
    ): void {
        if (null === $toCall) {
            return;
        }
        $byRefIndices = [];
        if ($toCall instanceof CoreFunc\Internal) {
            $name = $toCall->getName();
            $byRefIndices = BuiltinByRefParams::forFunction($name);
            $variadicFrom = BuiltinByRefParams::variadicByRefFromIndex($name);
            if (null !== $variadicFrom) {
                for ($idx = $variadicFrom, $n = \count($callOperands); $idx < $n; ++$idx) {
                    $byRefIndices[] = $idx;
                }
            }
        } elseif ($toCall instanceof JIT\Call\Native) {
            foreach ($toCall->paramByRefByArg as $idx => $_) {
                if (null !== $toCall->variadicArgIndex && $idx === $toCall->variadicArgIndex) {
                    continue;
                }
                $byRefIndices[] = $idx;
            }
            if (
                null !== $toCall->variadicArgIndex
                && isset($toCall->paramByRefByArg[$toCall->variadicArgIndex])
            ) {
                $start = $toCall->variadicArgIndex;
                $end = \count($callOperands) - 1;
                if (null !== $toCall->namedArgsVariadicIndex) {
                    $trailing = \count($toCall->paramNames) - $toCall->namedArgsVariadicIndex - 1;
                    if ($trailing > 0) {
                        $end = \count($callOperands) - $trailing - 1;
                    }
                }
                for ($idx = $start; $idx <= $end; ++$idx) {
                    $byRefIndices[] = $idx;
                }
            }
        } else {
            return;
        }
        $seen = [];
        foreach ($byRefIndices as $idx) {
            if (isset($seen[$idx])) {
                continue;
            }
            $seen[$idx] = true;
            $operand = $callOperands[$idx] ?? null;
            if (null === $operand) {
                continue;
            }
            $this->markByRefOutParamOperandAssigned($block, $operand);
        }
    }

    private function markByRefOutParamOperandAssigned(Block $block, Operand $operand): void
    {
        if (!$this->context->hasVariableOp($operand)) {
            $this->context->aliasVariableOpFromSlot($block, $operand);
        }
        if (!$this->context->hasVariableOp($operand)) {
            return;
        }
        $var = $this->context->getVariableFromOp($operand);
        JIT\UndefinedVariableHelper::markAssigned($this->context, $operand, $var);
    }

    /** True when the pending outgoing call passes argument $argIndex by reference (ZEND_SEND_REF). */
    private function isOutgoingByRefArgIndex(?JIT\Call $toCall, int $argIndex): bool
    {
        if (null === $toCall) {
            return false;
        }
        if ($toCall instanceof CoreFunc\Internal) {
            return BuiltinByRefParams::isByRefArg($toCall->getName(), $argIndex);
        }
        if ($toCall instanceof JIT\Call\Native) {
            if (isset($toCall->paramByRefByArg[$argIndex])) {
                return true;
            }
            if (
                null !== $toCall->variadicArgIndex
                && isset($toCall->paramByRefByArg[$toCall->variadicArgIndex])
                && $argIndex >= $toCall->variadicArgIndex
            ) {
                $end = $toCall->variadicArgIndex;
                if (null !== $toCall->namedArgsVariadicIndex) {
                    $trailing = \count($toCall->paramNames) - $toCall->namedArgsVariadicIndex - 1;
                    if ($trailing > 0) {
                        return $argIndex <= $end;
                    }
                }

                return true;
            }
        }

        return false;
    }

    /**
     * @param list<JIT\Variable|array{unpack: JIT\Variable}> $callArgs
     */
    private function markNewObjectConstructedAfterCall(?JIT\Call $toCall, array $callArgs): void
    {
        if (null === $toCall) {
            return;
        }
        if (
            $toCall instanceof JIT\Call\RuntimeIndirectInstanceMethodCall
            && '__construct' === $toCall->methodLc
        ) {
            if ([] === $callArgs) {
                return;
            }
            $first = $callArgs[0];
            if (is_array($first)) {
                $first = $first['unpack'] ?? null;
            }
            if (!$first instanceof JIT\Variable || Variable::TYPE_OBJECT !== $first->type) {
                return;
            }
            $this->context->type->object->markObjectConstructed(
                $this->context->helper->loadValue($first)
            );

            return;
        }
        if ($toCall instanceof JIT\Call\Native) {
            $name = strtolower($toCall->name);
        } elseif ($toCall instanceof JIT\Call\ExternalMethod) {
            $name = strtolower($toCall->proxyName);
        } elseif ($toCall instanceof JIT\Call\SimpleXMLElementConstruct) {
            $name = 'simplexmlelement::__construct';
        } elseif ($toCall instanceof JIT\Call\ExceptionConstruct) {
            $name = 'exception::__construct';
        } elseif ($this->isVoidJitConstructCall($toCall)) {
            $name = '::__construct';
        } else {
            return;
        }
        if (!str_ends_with($name, '::__construct')) {
            return;
        }
        if ([] === $callArgs) {
            return;
        }
        $first = $callArgs[0];
        if (is_array($first)) {
            $first = $first['unpack'] ?? null;
        }
        if (!$first instanceof JIT\Variable || Variable::TYPE_OBJECT !== $first->type) {
            return;
        }
        $this->context->type->object->markObjectConstructed(
            $this->context->helper->loadValue($first)
        );
    }

    private function jitTypeFromLlvmValue(PHPLLVM\Value $value): int
    {
        switch ($this->context->getStringFromType($value->typeOf())) {
            case 'double':
                return Variable::TYPE_NATIVE_DOUBLE;
            case 'int1':
            case 'bool':
                return Variable::TYPE_NATIVE_BOOL;
            case 'int64':
            case 'long long':
            case 'int32':
            case 'size_t':
            case 'unsigned int':
                return Variable::TYPE_NATIVE_LONG;
            case '__string__*':
                return Variable::TYPE_STRING;
            case '__object__*':
                return Variable::TYPE_OBJECT;
            case '__hashtable__*':
                return Variable::TYPE_HASHTABLE;
            case '__value__':
            case '__value__*':
                return Variable::TYPE_VALUE;
            default:
                throw new \LogicException(
                    'Cannot infer JIT variable type from LLVM type: '
                    .$this->context->getStringFromType($value->typeOf())
                );
        }
    }

    /**
     * Promote named function locals from stale KIND_VALUE i64 literals to an alloca (#36018).
     */
    private function ensureNamedNativeLongLocalAlloca(Operand $resultOp, JIT\Variable $result): JIT\Variable
    {
        $name = JIT\OperandName::resolve($resultOp);
        if (null !== $name && '' !== $name) {
            $resolved = $this->context->resolveRefAliasName($name);
            if (isset($this->context->namedVariableBindings[$resolved])) {
                $bound = $this->context->namedVariableBindings[$resolved];
                if (Variable::KIND_VARIABLE === $bound->kind && Variable::TYPE_NATIVE_LONG === $bound->type) {
                    $this->context->setVariableOp($resultOp, $bound);

                    return $bound;
                }
            }
        }
        if (Variable::KIND_VARIABLE === $result->kind && Variable::TYPE_NATIVE_LONG === $result->type) {
            return $result;
        }
        if (Variable::TYPE_NATIVE_LONG !== $result->type || Variable::KIND_VALUE !== $result->kind) {
            return $result;
        }
        if (null === $result->value || \PHPLLVM\Value::KIND_CONSTANT_INT !== $result->value->getKind()) {
            return $result;
        }
        if (null === $name || '' === $name) {
            return $result;
        }
        $block = $this->context->jitEnclosingBlock;
        if (null === $block || null === $block->func || $block->isMainScript()) {
            return $result;
        }
        $i64 = $this->context->getTypeFromString('int64');
        $slot = JIT\BasicBlockHelper::entryAlloca($this->context, $i64);
        $this->context->builder->store($this->context->helper->loadValue($result), $slot);
        $allocaVar = new JIT\Variable(
            $this->context,
            Variable::TYPE_NATIVE_LONG,
            Variable::KIND_VARIABLE,
            $slot
        );
        $allocaVar->addref();
        $allocaVar->compileTimeLong = null;
        $this->context->setVariableOp($resultOp, $allocaVar);
        $this->context->bindVariableByName($this->context->resolveRefAliasName($name), $allocaVar);

        return $allocaVar;
    }

    /**
     * Named locals ($i++) must not constant-fold on a stale LLVM i64 literal — loop
     * JUMPIF still reads the original slot (#36018 / peer #32605 / #32831).
     */
    private function isNamedLocalIncDec(Operand $readOp, Operand $writeOp): bool
    {
        $name = JIT\OperandName::resolve($readOp) ?? JIT\OperandName::resolve($writeOp);
        if (null === $name || '' === $name) {
            return false;
        }
        $block = $this->context->jitEnclosingBlock;
        if (null === $block || null === $block->func || $block->isMainScript()) {
            return false;
        }

        return true;
    }

    /**
     * Promote `$i = 0; …; $i++` locals from KIND_VALUE i64 literals to an alloca so
     * loop headers and post-increment share one mutable slot (#36018).
     *
     * @return array{0: JIT\Variable, 1: JIT\Variable}
     */
    private function materializeNamedNativeLongLocalForIncDec(
        Operand $readOp,
        Operand $writeOp,
        JIT\Variable $read,
        JIT\Variable $write
    ): array {
        if (!$this->isNamedLocalIncDec($readOp, $writeOp)) {
            return [$read, $write];
        }
        if (Variable::KIND_VARIABLE === $write->kind && Variable::TYPE_NATIVE_LONG === $write->type) {
            return [$read, $write];
        }
        if (Variable::TYPE_NATIVE_LONG !== $read->type || Variable::TYPE_NATIVE_LONG !== $write->type) {
            return [$read, $write];
        }
        if (Variable::KIND_VALUE !== $write->kind || null === $write->value) {
            return [$read, $write];
        }
        if (\PHPLLVM\Value::KIND_CONSTANT_INT !== $write->value->getKind()) {
            return [$read, $write];
        }
        $i64 = $this->context->getTypeFromString('int64');
        $slot = JIT\BasicBlockHelper::entryAlloca($this->context, $i64);
        $cur = $this->context->helper->loadValue($read);
        $this->context->builder->store($cur, $slot);
        $allocaVar = new JIT\Variable(
            $this->context,
            Variable::TYPE_NATIVE_LONG,
            Variable::KIND_VARIABLE,
            $slot
        );
        $allocaVar->addref();
        $allocaVar->compileTimeLong = null;
        $name = JIT\OperandName::resolve($writeOp) ?? JIT\OperandName::resolve($readOp);
        if (null !== $name && '' !== $name) {
            $this->context->bindVariableByName($this->context->resolveRefAliasName($name), $allocaVar);
        }
        $this->context->setVariableOp($writeOp, $allocaVar);
        if ($readOp !== $writeOp) {
            $this->context->setVariableOp($readOp, $allocaVar);
        }

        return [$allocaVar, $allocaVar];
    }

    /**
     * @return list<\PHPCfg\Operand>
     */
    private function consumeConcatPendingLeaves(\PHPCfg\Operand $op): array
    {
        $id = spl_object_id($op);
        if (isset($this->concatPendingLeaves[$id])) {
            $leaves = $this->concatPendingLeaves[$id];
            unset($this->concatPendingLeaves[$id]);

            return $leaves;
        }

        return [$op];
    }

    private function isSingleUseConcatChainTemp(
        Block $block,
        \PHPCfg\Operand $resultOp,
        int $resultSlot,
        int $fromIndex
    ): bool {
        $name = JIT\OperandName::resolve($resultOp);
        if (null !== $name && '' !== $name) {
            return false;
        }
        $useCount = 0;
        $n = \count($block->opCodes);
        for ($j = $fromIndex + 1; $j < $n; ++$j) {
            $other = $block->opCodes[$j];
            $reads = false;
            if (null !== $other->arg2 && (int) $other->arg2 === $resultSlot) {
                $reads = true;
            }
            if (null !== $other->arg3 && (int) $other->arg3 === $resultSlot) {
                $reads = true;
            }
            if (!$reads) {
                continue;
            }
            ++$useCount;
            if (OpCode::TYPE_CONCAT !== $other->type) {
                return false;
            }
        }

        return 1 === $useCount;
    }

    private function ensureNativeStringSlotForConcatFlatten(
        PHPLLVM\Value $func,
        Block $block,
        OpCode $op,
        \PHPCfg\Operand $destOp,
        Variable $result
    ): ?Variable {
        if (
            Variable::TYPE_STRING === $result->type
            && Variable::KIND_VARIABLE === $result->kind
        ) {
            $destSlotTy = null !== $result->value
                ? $this->context->getStringFromType($result->value->typeOf())
                : '';
            if ('__string__**' === $destSlotTy || 'ptr' === $destSlotTy) {
                return $result;
            }
        }
        if (
            Variable::TYPE_VALUE === $result->type
            || JIT\JitValueBox::isValueOperand($result)
            || (
                Variable::TYPE_STRING === $result->type
                && Variable::KIND_VALUE === $result->kind
            )
        ) {
            $destSlot = JIT\BasicBlockHelper::entryAllocaForFunction(
                $this->context,
                $func,
                $this->context->getTypeFromString('__string__*')
            );
            $promoted = new Variable(
                $this->context,
                Variable::TYPE_STRING,
                Variable::KIND_VARIABLE,
                $destSlot
            );
            JIT\BasicBlockHelper::storeAtFunctionEntry(
                $this->context,
                $func,
                $this->context->type->string->pointer->constNull(),
                $destSlot
            );
            if (
                Variable::TYPE_VALUE === $result->type
                || JIT\JitValueBox::isValueOperand($result)
            ) {
                $this->seedNativeStringSlotFromValueBox($result, $destSlot);
            } elseif (null !== $result->value && Variable::KIND_VALUE === $result->kind) {
                JIT\BasicBlockHelper::storeAtFunctionEntry(
                    $this->context,
                    $func,
                    $result->value,
                    $destSlot
                );
                $promoted->addref();
            }
            $this->context->setVariableOp($destOp, $promoted);
            $this->bindPromotedStringConcatDest($block, $destOp, $promoted);

            return $promoted;
        }

        return null;
    }

    private function appendConcatLeafToNativeString(
        Variable $dest,
        \PHPCfg\Operand $leafOp,
        Block $block
    ): void {
        if ($leafOp instanceof Operand\Literal && \is_string($leafOp->value)) {
            if ('' === $leafOp->value) {
                return;
            }
            $lit = new Variable(
                $this->context,
                Variable::TYPE_STRING,
                Variable::KIND_VALUE,
                $this->context->builder->load(
                    $this->context->constantStringFromString($leafOp->value)
                )
            );
            $lit->compileTimeString = $leafOp->value;
            $this->context->type->string->appendInPlace($dest, $lit);

            return;
        }
        if (!$this->context->hasVariableOp($leafOp)) {
            // Literal ints / unresolved — coerce via makeVariable if possible.
            if ($leafOp instanceof Operand\Literal && \is_int($leafOp->value)) {
                $i64 = $this->context->getTypeFromString('int64');
                $this->context->type->string->appendInPlaceLong(
                    $dest,
                    $i64->constInt((int) $leafOp->value, true)
                );

                return;
            }
            $this->context->makeVariableFromOp(
                JIT\BasicBlockHelper::parentFunction($this->context),
                $this->context->builder->getInsertBlock(),
                $block,
                $leafOp
            );
        }
        $leaf = $this->context->getVariableFromOp($leafOp);
        if (Variable::TYPE_NATIVE_LONG === $leaf->type
            && JIT\IncDecResourceProvenance::cannotBeResourceForString($leafOp)
        ) {
            $this->context->type->string->appendInPlaceLong(
                $dest,
                $this->context->helper->loadValue($leaf)
            );

            return;
        }
        $coerced = JIT\JitNativeString::coerce($this->context, $leaf, $leafOp);
        $this->context->type->string->appendInPlace($dest, $coerced);
    }

    /**
     * @param list<\PHPCfg\Operand> $leaves
     */
    private function materializeConcatLeaves(array $leaves, Block $block): Variable
    {
        if ([] === $leaves) {
            return new Variable(
                $this->context,
                Variable::TYPE_STRING,
                Variable::KIND_VALUE,
                $this->context->builder->load($this->context->constantStringFromString(''))
            );
        }
        $acc = null;
        foreach ($leaves as $leafOp) {
            if (!$this->context->hasVariableOp($leafOp)) {
                if ($leafOp instanceof Operand\Literal && \is_string($leafOp->value)) {
                    $next = new Variable(
                        $this->context,
                        Variable::TYPE_STRING,
                        Variable::KIND_VALUE,
                        $this->context->builder->load(
                            $this->context->constantStringFromString($leafOp->value)
                        )
                    );
                    $next->compileTimeString = $leafOp->value;
                } elseif ($leafOp instanceof Operand\Literal && \is_int($leafOp->value)) {
                    $i64 = $this->context->getTypeFromString('int64');
                    $next = new Variable(
                        $this->context,
                        Variable::TYPE_NATIVE_LONG,
                        Variable::KIND_VALUE,
                        $i64->constInt((int) $leafOp->value, true)
                    );
                } else {
                    $this->context->makeVariableFromOp(
                        JIT\BasicBlockHelper::parentFunction($this->context),
                        $this->context->builder->getInsertBlock(),
                        $block,
                        $leafOp
                    );
                    $next = $this->context->getVariableFromOp($leafOp);
                }
            } else {
                $next = $this->context->getVariableFromOp($leafOp);
            }
            if (null === $acc) {
                if (Variable::TYPE_NATIVE_LONG === $next->type) {
                    $acc = new Variable(
                        $this->context,
                        Variable::TYPE_STRING,
                        Variable::KIND_VALUE,
                        JIT\JitNativeString::fromLong(
                            $this->context,
                            $this->context->helper->loadValue($next)
                        )
                    );
                } else {
                    $acc = JIT\JitNativeString::coerce($this->context, $next, $leafOp);
                }
                continue;
            }
            $acc = $this->compileConcatIntoNewString($acc, $next, null, $leafOp);
        }

        return $acc;
    }

    /** Allocate a fresh native string holding left . right (php-src string concat semantics). */
    private function compileConcatIntoNewString(
        Variable $left,
        Variable $right,
        ?\PHPCfg\Operand $leftOp = null,
        ?\PHPCfg\Operand $rightOp = null
    ): Variable
    {
        $this->context->intrinsic->builder = $this->context->builder;
        $leftIsLong = Variable::TYPE_NATIVE_LONG === $left->type;
        $rightIsLong = Variable::TYPE_NATIVE_LONG === $right->type;
        if ($rightIsLong && !$leftIsLong) {
            return $this->compileConcatStringAndI64($left, $right, $leftOp, false);
        }
        if ($leftIsLong && !$rightIsLong) {
            return $this->compileConcatStringAndI64($right, $left, $rightOp, true);
        }
        $left = JIT\JitNativeString::coerce($this->context, $left, $leftOp);
        $right = JIT\JitNativeString::coerce($this->context, $right, $rightOp);
        $leftVar = $this->context->helper->loadValue($left);
        $rightVar = $this->context->helper->loadValue($right);
        $map = $this->context->structFieldMap['__string__'];
        $leftSize = $this->context->builder->load(
            $this->context->builder->structGep($leftVar, $map['length'])
        );
        $rightSize = $this->context->builder->load(
            $this->context->builder->structGep($rightVar, $map['length'])
        );
        $size = $this->context->builder->addNoUnsignedWrap(
            $leftSize,
            $this->context->builder->intCast($rightSize, $leftSize->typeOf())
        );
        $result = $this->context->builder->call(
            $this->context->lookupFunction('__string__alloc'),
            $size
        );
        $char = $this->context->builder->structGep($result, $map['value']);
        $leftChar = $this->context->builder->structGep($leftVar, $map['value']);
        $this->context->intrinsic->memcpy($char, $leftChar, $leftSize, false);
        $char = $this->context->builder->gep($char, $leftSize);
        $rightChar = $this->context->builder->structGep($rightVar, $map['value']);
        $this->context->intrinsic->memcpy($char, $rightChar, $rightSize, false);

        $var = new Variable(
            $this->context,
            Variable::TYPE_STRING,
            Variable::KIND_VALUE,
            $result
        );
        if (
            null !== ($left->compileTimeString ?? null)
            && null !== ($right->compileTimeString ?? null)
        ) {
            $var->compileTimeString = $left->compileTimeString.$right->compileTimeString;
        }

        return $var;
    }

    /**
     * string . int / int . string in one alloc (php-src concat + zend_print_long_to_buf).
     *
     * Avoids a heap temp for the decimal digits (#36386 / str-builder, template-render).
     */
    private function compileConcatStringAndI64(
        Variable $strSide,
        Variable $longSide,
        ?\PHPCfg\Operand $strOp,
        bool $longFirst
    ): Variable {
        $this->context->intrinsic->builder = $this->context->builder;
        $strSide = JIT\JitNativeString::coerce($this->context, $strSide, $strOp);
        $strVar = $this->context->helper->loadValue($strSide);
        $longVal = $this->context->helper->loadValue($longSide);
        [$digits, $digitLen] = JIT\JitNativeString::writeDecimalDigits($this->context, $longVal);
        $map = $this->context->structFieldMap['__string__'];
        $strLen = $this->context->builder->load(
            $this->context->builder->structGep($strVar, $map['length'])
        );
        $strBytes = $this->context->builder->structGep($strVar, $map['value']);
        $total = $this->context->builder->addNoUnsignedWrap(
            $strLen,
            $this->context->builder->intCast($digitLen, $strLen->typeOf())
        );
        $result = $this->context->builder->call(
            $this->context->lookupFunction('__string__alloc'),
            $total
        );
        $dest = $this->context->builder->structGep($result, $map['value']);
        if ($longFirst) {
            $this->context->intrinsic->memcpy($dest, $digits, $digitLen, false);
            $this->context->intrinsic->memcpy(
                $this->context->builder->gep($dest, $digitLen),
                $strBytes,
                $strLen,
                false
            );
        } else {
            $this->context->intrinsic->memcpy($dest, $strBytes, $strLen, false);
            $this->context->intrinsic->memcpy(
                $this->context->builder->gep($dest, $strLen),
                $digits,
                $digitLen,
                false
            );
        }
        $var = new Variable(
            $this->context,
            Variable::TYPE_STRING,
            Variable::KIND_VALUE,
            $result
        );
        $var->compileTimeString = null;

        return $var;
    }

    private function compileBinaryOp(OpCode $op, Variable $left, Variable $right): Variable
    {
        if ($this->isOrderedCompareOpcode($op->type)) {
            [$left, $right] = $this->materializeOrderedCompareNativeLongOperands($left, $right);
        }
        // VALUE×VALUE &|^ must go through Helper::binaryOp so string tags use
        // StringBitwiseNot::emitBinary (Zend bitwise_*_function). A prior
        // readLong-only short-circuit coerced "$a & $b" to int (#35312).
        return $this->context->helper->binaryOp($op, $left, $right);
    }

    private static function isOrderedCompareOpcode(int $opcodeType): bool
    {
        return OpCode::TYPE_SMALLER === $opcodeType
            || OpCode::TYPE_GREATER === $opcodeType
            || OpCode::TYPE_SMALLER_OR_EQUAL === $opcodeType
            || OpCode::TYPE_GREATER_OR_EQUAL === $opcodeType;
    }

    /**
     * User-function `$i < $len` must not use orderedNativeLongToValue on boxed
     * property temps — snapshot to i64 like `(int)$len` (#36018).
     *
     * @return array{0: Variable, 1: Variable}
     */
    private function materializeOrderedCompareNativeLongOperands(Variable $left, Variable $right): array
    {
        $block = $this->context->jitEnclosingBlock;
        if (null === $block || null === $block->func || $block->isMainScript()) {
            return [$left, $right];
        }
        if (Variable::TYPE_NATIVE_LONG === $left->type
            && Variable::TYPE_VALUE === $right->type
            && JIT\JitValueBox::isValueOperand($right)
        ) {
            $right = $this->coerceValueBoxToNativeLongAlloca($right);
        }
        if (Variable::TYPE_NATIVE_LONG === $right->type
            && Variable::TYPE_VALUE === $left->type
            && JIT\JitValueBox::isValueOperand($left)
        ) {
            $left = $this->coerceValueBoxToNativeLongAlloca($left);
        }

        return [$left, $right];
    }

    private function coerceValueBoxToNativeLongAlloca(Variable $var): Variable
    {
        if (null !== $var->objectPropertySlot) {
            $propType = $var->objectPropertyType ?? $var->type;
            if (Variable::TYPE_NATIVE_LONG === $propType) {
                return $this->snapshotNativeScalarPropertyRead($var, $propType);
            }
        }
        $long = ext\standard\JitZendScalarCast::emitIntCast($this->context, $var);
        $i64 = $this->context->getTypeFromString('int64');
        $slot = JIT\BasicBlockHelper::entryAlloca($this->context, $i64);
        $this->context->builder->store($long, $slot);
        $native = new Variable(
            $this->context,
            Variable::TYPE_NATIVE_LONG,
            Variable::KIND_VARIABLE,
            $slot
        );
        $native->addref();
        $native->compileTimeLong = null;

        return $native;
    }

    private function jitVariableArrayClassConstant(string $constName): ?Variable
    {
        switch (strtolower($constName)) {
            case 'native_type_map':
                return $this->jitVariableNativeTypeMapConstant();
            case 'type_map':
                return $this->jitVariableTypeMapConstant();
            default:
                return null;
        }
    }

    private function jitArrayElementKeyVariable(Block $block, ?int $keyArg): ?Variable
    {
        if (null === $keyArg) {
            return null;
        }
        $intKey = $this->tryCompileTimeArrayLiteralIntKey($block, $keyArg);
        if (null !== $intKey) {
            return new Variable(
                $this->context,
                Variable::TYPE_NATIVE_LONG,
                Variable::KIND_VALUE,
                $this->context->constantFromInteger($intKey, 'int64')
            );
        }

        return $this->context->getVariableFromOp($block->getOperand($keyArg));
    }

    /**
     * Zend array-literal key: int keys and canonical numeric strings share one slot (#4151).
     */
    private function tryCompileTimeArrayLiteralIntKey(Block $block, int $keyArg): ?int
    {
        if (isset($block->constants[$keyArg])) {
            $const = $block->constants[$keyArg];
            if (VM\Variable::TYPE_INTEGER === $const->type) {
                return $const->toInt();
            }
            if (VM\Variable::TYPE_STRING === $const->type) {
                return VM\HashTable::tryIntFromNumericString($const->toString());
            }
            if (VM\Variable::TYPE_FLOAT === $const->type) {
                return $const->toInt();
            }
        }
        $op = $block->getOperand($keyArg);
        if ($op instanceof Operand\Literal) {
            if (is_int($op->value)) {
                return $op->value;
            }
            if (is_string($op->value)) {
                return VM\HashTable::tryIntFromNumericString($op->value);
            }
            if (is_float($op->value)) {
                return (int) $op->value;
            }
        }

        return null;
    }

    private function bumpNativeArrayNextFreeForExplicitIntKey(
        Variable $array,
        ?int $keyArg,
        Block $block
    ): void {
        if (null === $keyArg || 0 === ($array->type & Variable::IS_NATIVE_ARRAY)) {
            return;
        }
        $keyOp = $block->getOperand($keyArg);
        if (!$keyOp instanceof Operand\Literal || !is_int($keyOp->value)) {
            return;
        }
        $needed = $keyOp->value + 1;
        if ($needed > $array->nextFreeElement) {
            $array->nextFreeElement = $needed;
        }
    }

    private function jitVariableNativeTypeMapConstant(): Variable
    {
        $slot = JIT\BasicBlockHelper::entryAlloca(
            $this->context,
            $this->context->getTypeFromString('__hashtable__*')
        );
        $result = new Variable(
            $this->context,
            Variable::TYPE_HASHTABLE,
            Variable::KIND_VARIABLE,
            $slot
        );
        JIT\HashTableHelper::initArray($this->context, $result);
        foreach (JIT\Variable::NATIVE_TYPE_MAP as $typeKey => $typeName) {
            $key = Variable::fromConstantInt($this->context, $typeKey);
            $lit = new Operand\Literal($typeName);
            $lit->type = Type::string();
            $element = Variable::fromLiteral($this->context, $lit);
            JIT\HashTableHelper::addElement($this->context, $result, $element, $key);
        }

        return $result;
    }

    private function jitVariableTypeMapConstant(): Variable
    {
        $slot = JIT\BasicBlockHelper::entryAlloca(
            $this->context,
            $this->context->getTypeFromString('__hashtable__*')
        );
        $result = new Variable(
            $this->context,
            Variable::TYPE_HASHTABLE,
            Variable::KIND_VARIABLE,
            $slot
        );
        JIT\HashTableHelper::initArray($this->context, $result);
        foreach (JIT\Variable::TYPE_MAP as $typeKey => $typeValue) {
            $key = Variable::fromConstantInt($this->context, $typeKey);
            $element = Variable::fromConstantInt($this->context, $typeValue);
            JIT\HashTableHelper::addElement($this->context, $result, $element, $key);
        }

        return $result;
    }

    /**
     * @return array<int, Variable>
     */
    private function resolveClassNameForPseudoConst(Block $block, Operand $classOp): string
    {
        if (!$classOp instanceof Operand\Literal) {
            throw new \LogicException('Class::class requires a literal class name for JIT/AOT');
        }

        return $this->resolveJitStaticScopeClass($block, $classOp);
    }

    /**
     * Copy enclosing method/trait class onto a nested closure Func before its body is lowered.
     *
     * php-cfg leaves closure Func->class unset; VM installs it at TYPE_CLOSURE runtime (#25793).
     * AOT compiles the body first, so self::class otherwise hits PseudoClassScope::fatalInGlobalScope
     * (#26459 — __CLASS__ in traits rewrites to self::class).
     */
    private function propagateEnclosingClassOntoClosureFunc(Block $enclosing, Block $closureBody): void
    {
        if (null === $closureBody->func) {
            return;
        }
        $existing = $closureBody->func->class;
        if (null !== $existing && null !== $existing->value && '' !== (string) $existing->value) {
            return;
        }
        if (null === $enclosing->func || null === $enclosing->func->class) {
            return;
        }
        $enclosingClass = $enclosing->func->class;
        if (null === $enclosingClass->value || '' === (string) $enclosingClass->value) {
            return;
        }
        $closureBody->func->class = $enclosingClass;
    }

    /**
     * Compile a nested closure body while preserving trait composing / class scope (#26459).
     *
     * Nested {@see compileBlock} of closures can leave scope->classId pointing at a prior
     * NestedJIT helper class; keep traitComposingClassName and prefer it for self::class.
     */
    private function compileClosureBodyBlock(Block $enclosing, Block $closureBody, string $internalName): void
    {
        $this->propagateEnclosingClassOntoClosureFunc($enclosing, $closureBody);
        $savedComposing = $this->context->scope->traitComposingClassName;
        $savedClassName = $this->context->scope->className;
        $savedClassId = $this->context->scope->classId;
        if ('' === $savedComposing) {
            // Inherit composing from enclosing method compile (set in trait flatten / runQueue).
            if ('' !== $savedClassName
                && !$this->context->type->object->isTraitClass(strtolower(ltrim($savedClassName, '\\')))) {
                if ($this->context->type->object->hasDeclaredClass($savedClassName)) {
                    $this->context->scope->traitComposingClassName = $this->context->type->object->classNameForId(
                        $this->context->type->object->lookup($savedClassName)
                    );
                } else {
                    $this->context->scope->traitComposingClassName = $savedClassName;
                }
            }
        }
        try {
            $this->compileBlock($closureBody, $internalName);
        } finally {
            $this->context->scope->traitComposingClassName = $savedComposing;
            $this->context->scope->className = $savedClassName;
            $this->context->scope->classId = $savedClassId;
        }
    }

    private function resolveJitStaticScopeClass(Block $block, Operand\Literal $classOp): string
    {
        $lc = strtolower($classOp->value);
        if ('self' === $lc) {
            if (null === $block->func || null === $block->func->class) {
                PseudoClassScope::fatalNoActiveClassScope('self');
            }
            $declaringClass = $block->func->class->value;
            $declaringLc = strtolower(ltrim($declaringClass, '\\'));
            if ($this->context->type->object->isTraitClass($declaringLc)) {
                $composing = $this->context->scope->traitComposingClassName;
                if ('' !== $composing && !$this->context->type->object->isTraitClass(strtolower(ltrim($composing, '\\')))) {
                    return $composing;
                }
                // Prefer scope->className before classId: NestedJIT leaves classId on the last
                // helper class (e.g. DirHandleJitHelper) when compiling nested closures (#26459).
                $scopeName = $this->context->scope->className;
                if ('' !== $scopeName && !$this->context->type->object->isTraitClass(strtolower(ltrim($scopeName, '\\')))) {
                    if ($this->context->type->object->hasDeclaredClass($scopeName)) {
                        return $this->context->type->object->classNameForId(
                            $this->context->type->object->lookup($scopeName)
                        );
                    }

                    return $scopeName;
                }
                if ($this->context->scope->classId > 0) {
                    $fromId = $this->context->type->object->classNameForId($this->context->scope->classId);
                    if ('' !== $fromId && !$this->context->type->object->isTraitClass(strtolower(ltrim($fromId, '\\')))) {
                        return $fromId;
                    }
                }
            }

            return $declaringClass;
        }
        if ('static' === $lc) {
            if ($this->context->scope->calledClassName !== '') {
                return $this->context->scope->calledClassName;
            }
            if (null !== $block->func && null !== $block->func->class) {
                return $block->func->class->value;
            }
            PseudoClassScope::fatalNoActiveClassScope('static');
        }
        if ('parent' === $lc) {
            if (null === $block->func || null === $block->func->class) {
                PseudoClassScope::fatalNoActiveClassScope('parent');
            }
            $declaringClass = $block->func->class->value;
            $declaringLc = strtolower(ltrim($declaringClass, '\\'));
            if ($this->context->type->object->isTraitClass($declaringLc)) {
                $composing = $this->context->scope->traitComposingClassName;
                if ('' !== $composing && !$this->context->type->object->isTraitClass(strtolower(ltrim($composing, '\\')))) {
                    $declaringClass = $composing;
                } elseif ($this->context->scope->classId > 0) {
                    $fromId = $this->context->type->object->classNameForId($this->context->scope->classId);
                    if ('' !== $fromId && !$this->context->type->object->isTraitClass(strtolower(ltrim($fromId, '\\')))) {
                        $declaringClass = $fromId;
                    }
                } else {
                    $scopeName = $this->context->scope->className;
                    if ('' !== $scopeName && !$this->context->type->object->isTraitClass(strtolower(ltrim($scopeName, '\\')))) {
                        $declaringClass = $scopeName;
                    } else {
                        $called = $this->context->scope->calledClassName;
                        if ('' !== $called && strtolower(ltrim($called, '\\')) !== $declaringLc) {
                            $declaringClass = $called;
                        }
                    }
                }
            }
            $parentLc = $this->context->type->object->parentClassLc($declaringClass);
            if (null === $parentLc) {
                throw new \LogicException('parent:: used when class has no parent');
            }

            return $parentLc;
        }

        return $classOp->value;
    }

    private function jitIsClassSameOrSubclassOf(string $classLc, string $ancestorLc): bool
    {
        $current = strtolower(ltrim($classLc, '\\'));
        $ancestorLc = strtolower(ltrim($ancestorLc, '\\'));
        while (true) {
            if ($current === $ancestorLc) {
                return true;
            }
            $parentLc = $this->context->type->object->parentClassLc($current);
            if (null === $parentLc) {
                return false;
            }
            $current = $parentLc;
        }
    }

    private function blockUsesThis(Block $block): bool
    {
        foreach ($block->orig->hoistedOperands as $hoisted) {
            if ('this' === JIT\OperandName::resolve($hoisted)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Free closures that read $this need an implicit __object__* param so Closure::call /
     * bindTo can prepend temporary $this (JIT.pre blockUsesThis; #26872).
     */
    private function closureBodyUsesThis(Block $block): bool
    {
        if (null === $block->func) {
            return false;
        }
        if (0 === (($block->func->flags ?? 0) & \PHPCfg\Func::FLAG_CLOSURE)) {
            return false;
        }
        if (($block->func->flags ?? 0) & \PHPCfg\Func::FLAG_STATIC) {
            return false;
        }

        return $this->blockUsesThis($block);
    }

    /** 1 when LLVM param 0 is $this (instance method or this-using closure) (#26872). */
    private function llvmThisParamOffset(Block $block): int
    {
        return ($this->instanceMethodUsesThis($block) || $this->closureBodyUsesThis($block)) ? 1 : 0;
    }

    private function instanceMethodUsesThis(Block $block): bool
    {
        if (null === $block->func) {
            return false;
        }
        if (($block->func->flags ?? 0) & \PHPCfg\Func::FLAG_STATIC) {
            return false;
        }
        // Closures inherit func->class via propagateEnclosingClassOntoClosureFunc (#26459).
        // Only closureBodyUsesThis should add LLVM $this (#27163).
        if (($block->func->flags ?? 0) & \PHPCfg\Func::FLAG_CLOSURE) {
            return false;
        }
        if (null !== $block->func->class) {
            return true;
        }
        // Nested file JIT: func->class may be unset while scope carries the declaring class (#16075).
        // Do not treat leftover scope->className as applying to {main}, closures, or free functions
        // — that adds a spurious __object__* / thisParamOffset (standalone main #22638; clone-with
        // IIFE #23046; c07_method f($m->g(1),$m->g(2)) "Missing required argument 1" #23971).
        if (
            '' !== $this->context->scope->className
            && '{main}' !== $block->func->name
            && 0 === (($block->func->flags ?? 0) & \PHPCfg\Func::FLAG_CLOSURE)
        ) {
            $methodLc = strtolower($block->func->name);
            $classLc = strtolower(ltrim($this->context->scope->className, '\\'));
            $proxyLc = $classLc.'::'.$methodLc;
            if (
                $this->context->functionIsRegistered($proxyLc)
                || isset($this->context->functions[$proxyLc])
            ) {
                return true;
            }
        }

        return str_contains($block->func->getScopedName(), '::');
    }

    /**
     * True when a queued LLVM function is registered as Class::method (NestedJIT #16075).
     */
    private function queuedFuncIsClassMethodAlias(PHPLLVM\Value $llvmFunc, Block $cfgBlock): bool
    {
        $methodLc = strtolower($cfgBlock->func->name);
        foreach ($this->context->functions as $name => $candidate) {
            if ($candidate !== $llvmFunc || !str_contains($name, '::')) {
                continue;
            }
            [, $methodPart] = explode('::', $name, 2);
            if (strtolower($methodPart) === $methodLc) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param Operand\Literal|Operand\Variable|Operand\Temporary $receiverOp
     */
    /**
     * Fold `$obj->method(...)` FCC array callables to direct instance dispatch (#4040).
     */
    private function tryInitBoundMethodFccDirect(Block $block, ?int $calleeSlot): bool
    {
        if (null === $calleeSlot) {
            return false;
        }
        $methodLc = JIT\BoundMethodCallableHelper::resolveMethodLcFromCalleeSlot($block, $calleeSlot);
        if (null === $methodLc) {
            return false;
        }
        $receiverOp = JIT\BoundMethodCallableHelper::resolveBoundMethodReceiverOperand($block, $calleeSlot);
        if (null === $receiverOp) {
            return false;
        }
        if (null === $receiverOp->type || Type::TYPE_OBJECT !== $receiverOp->type->type) {
            return false;
        }
        $this->initJitMethodCall($block, $receiverOp, $methodLc);

        return true;
    }

    /**
     * Fold `['Class','method']()` array callables to INIT_STATIC_METHOD_CALL (#32299).
     *
     * RuntimeVariableFunction only dispatches string function names; an array callee
     * previously emitted abort() (rc=134). php-src: Zend/zend_execute.c ZEND_INIT_DYNAMIC_CALL.
     */
    private function tryInitStaticArrayCallableDirect(Block $block, ?int $calleeSlot): bool
    {
        if (null === $calleeSlot) {
            return false;
        }
        $slots = VM\VmBoundMethodCallable::resolveStaticArrayCallableSlots($block, $calleeSlot);
        if (null === $slots) {
            return false;
        }
        $this->initJitStaticCall($slots[2], $slots[0], $slots[1], false, true);

        return true;
    }

    private function jitInstanceMethodReceiverVariable(Variable $receiverVar): Variable
    {
        if (Variable::TYPE_VALUE !== $receiverVar->type) {
            return $receiverVar;
        }
        $objVal = JIT\ClosureHelper::loadObjectFromCallable($this->context, $receiverVar);
        $objVar = new Variable(
            $this->context,
            Variable::TYPE_OBJECT,
            Variable::KIND_VALUE,
            $objVal
        );
        $objVar->addref();

        return $objVar;
    }

    /**
     * Nested loadHTML helper compile can leave method-call receiver temps without script-global alias (#17954).
     */
    private function isNestedJitHelperScopeClassName(string $className): bool
    {
        $lc = strtolower(ltrim($className, '\\'));

        return str_ends_with($lc, 'jithelper')
            || str_starts_with($lc, 'phpcompiler\\ext\\')
            || str_starts_with($lc, 'phpcompiler\\jit\\builtin\\')
            || str_starts_with($lc, 'phpcompiler\\vm\\');
    }

    private function resolveUserScriptDomDocumentReceiver(
        Block $block,
        Operand $receiverOp,
        string $declaringClassLc,
        string $methodLc,
        Variable $receiverVar
    ): Variable {
        if (!JIT\DomInstanceMethodJit::shouldDeferToVmClassMethodLowering()) {
            return $receiverVar;
        }
        if ('domdocument' !== $declaringClassLc) {
            return $receiverVar;
        }
        if (!\in_array($methodLc, ['loadhtml', 'getelementbyid', 'createelement'], true)) {
            return $receiverVar;
        }
        if (null !== $receiverVar->valueBoxAliasPtr || $receiverVar->functionStaticGlobal) {
            return $receiverVar;
        }

        $name = JIT\OperandName::resolve($receiverOp);
        if (null !== $name && '' !== $name) {
            $resolved = $this->context->resolveRefAliasName($name);
            if (isset($this->context->namedVariableBindings[$resolved])) {
                return $this->context->namedVariableBindings[$resolved];
            }
        }

        $slot = $block->slotForOperand($receiverOp);
        if (null !== $slot) {
            foreach ($block->scopedOperands() as $scopeOp) {
                if ($block->slotForOperand($scopeOp) !== $slot) {
                    continue;
                }
                $scopeName = JIT\OperandName::resolve($scopeOp);
                if (null === $scopeName || '' === $scopeName) {
                    continue;
                }
                $resolved = $this->context->resolveRefAliasName($scopeName);
                if (isset($this->context->namedVariableBindings[$resolved])) {
                    return $this->context->namedVariableBindings[$resolved];
                }
            }
        }

        return $receiverVar;
    }

    /** Nested JIT: VM HashTable/Variable helpers for php-in-PHP ext helpers (#12910). */
    private function tryInitNestedVmHelperMethodCall(
        string $declaringClassLc,
        string $methodLc,
        Variable $receiverVar
    ): bool {
        if (!JIT\NestedJitCompileScope::isActive() && !\PHPCompiler\AOT\ExternalMethodBind::spineChunkMode()) {
            return false;
        }
        // Prefer TYPE_HASHTABLE / HashTable class before ObjectEntry — both expose
        // compareSpaceship; wrong bridge fails NestedJIT module verify (#21109).
        if (
            JIT\NestedVmHashTableMethodLlvm::isNestedHashTableMethod($methodLc)
            && (
                'phpcompiler\\vm\\hashtable' === $declaringClassLc
                || Variable::TYPE_HASHTABLE === $receiverVar->type
            )
        ) {
            if (!JIT\NestedVmHashTableMethodLlvm::ensureMethod($this->context, $methodLc)) {
                return false;
            }
            $proxyName = 'phpcompiler\\vm\\hashtable::'.$methodLc;
            $this->context->scope->toCall = $this->context->resolveFunctionProxy($proxyName);
            $this->context->scope->args = [$receiverVar];

            return true;
        }
        if (
            JIT\NestedVmObjectMethodLlvm::isNestedObjectMethod($methodLc)
            && (
                'phpcompiler\\vm\\objectentry' === $declaringClassLc
                || 'object' === $declaringClassLc
                || (
                    Variable::TYPE_OBJECT === $receiverVar->type
                    && Variable::TYPE_HASHTABLE !== $receiverVar->type
                )
            )
        ) {
            if (!JIT\NestedVmObjectMethodLlvm::ensureMethod($this->context, $methodLc)) {
                return false;
            }
            $proxyName = 'phpcompiler\\vm\\objectentry::'.$methodLc;
            $this->context->scope->toCall = $this->context->resolveFunctionProxy($proxyName);
            $this->context->scope->args = [$receiverVar];

            return true;
        }
        if (JIT\NestedVmHashTableMethodLlvm::isNestedHashTableMethod($methodLc)) {
            // Leaked NestedJIT receiver userType (enums etc.) — catch-all after Object (#21109).
            if (!JIT\NestedVmHashTableMethodLlvm::ensureMethod($this->context, $methodLc)) {
                return false;
            }
            $proxyName = 'phpcompiler\\vm\\hashtable::'.$methodLc;
            $this->context->scope->toCall = $this->context->resolveFunctionProxy($proxyName);
            $this->context->scope->args = [$receiverVar];

            return true;
        }
        if (
            JIT\NestedContextMethodLlvm::isNestedContextMethod($methodLc)
            && ('phpcompiler\\vm\\context' === $declaringClassLc || 'object' === $declaringClassLc)
        ) {
            if (!JIT\NestedContextMethodLlvm::ensureMethod($this->context, $methodLc)) {
                return false;
            }
            $proxyName = 'phpcompiler\\vm\\context::'.$methodLc;
            $this->context->scope->toCall = $this->context->resolveFunctionProxy($proxyName);
            $this->context->scope->args = [$receiverVar];

            return true;
        }
        if (
            'coercevariabletostring' === $methodLc
            && ('phpcompiler\\vm' === $declaringClassLc || 'object' === $declaringClassLc)
        ) {
            $proxyName = 'phpcompiler\\vm::coercevariabletostring';
            if (!$this->context->functionIsRegistered($proxyName)) {
                $this->context->functionProxies[$proxyName] = new JIT\Call\VmCoerceVariableToString();
            }
            $this->context->scope->toCall = $this->context->resolveFunctionProxy($proxyName);
            $this->context->scope->args = [$receiverVar];

            return true;
        }
        if (!JIT\NestedVmVariableMethodLlvm::isNestedVariableMethod($methodLc)) {
            return false;
        }
        // Bare `Variable` (use-import) must match FQCN — same as isCfgVmVariableParamType (#20785).
        // NestedJIT helper className fallback (DomCreateElementJitHelper etc.): still accept
        // when the receiver is a value-box Variable param (#22678 AOT append/replaceChild).
        // Also accept *JitHelper declaringClass when NestedJIT leaked scope->className onto
        // `new Variable()` temps ($x->null() → ArrayReduceJitHelper::null, #24117).
        // Same leak on NestedJIT'd Vm* SSOT classes: `$outVars[$i]->byRefTarget()` →
        // VmSscanf::byreftarget (#27663 fscanf/vfscanf AOT).
        if (
            'phpcompiler\\vm\\variable' !== $declaringClassLc
            && 'object' !== $declaringClassLc
            && 'variable' !== $declaringClassLc
            && !str_ends_with($declaringClassLc, '\\vm\\variable')
            && !(Variable::TYPE_VALUE === $receiverVar->type)
            && !str_ends_with($declaringClassLc, 'jithelper')
            && !preg_match('/\\\\vm[a-z0-9_]*$/', $declaringClassLc)
        ) {
            return false;
        }
        if (!JIT\NestedVmVariableMethodLlvm::ensureMethod($this->context, $methodLc)) {
            return false;
        }
        $proxyName = 'phpcompiler\\vm\\variable::'.$methodLc;
        $this->context->scope->toCall = $this->context->resolveFunctionProxy($proxyName);
        $this->context->scope->args = [$receiverVar];

        return true;
    }

    /** Lazily register Runtime inventory-argv stubs on helloworld bin/compile.php (#12036). */
    private function tryInitInventoryArgvRuntimeParseHelperCall(
        string $methodLc,
        Variable $dispatchReceiver
    ): bool {
        if (!$this->shouldEnsureInventoryArgvParseHelperStubs()) {
            return false;
        }
        $stubBlock = $this->m3CompileDriverMainBlock ?? $this->m3EmitTuMainBlock;
        if ('standalone' === $methodLc && null !== $stubBlock) {
            $logical = 'PHPCompiler\\Runtime::standalone';
            $lc = strtolower($logical);
            if (!$this->context->functionIsRegistered($lc)) {
                $this->emitM3EmitTuRuntimeStandaloneStubNative(
                    $this->llvmInternalName($logical),
                    $logical,
                    $stubBlock
                );
            }
            if ($this->context->functionIsRegistered($lc)) {
                $this->context->scope->toCall = $this->context->resolveFunctionProxy($lc);
                $this->context->scope->args = [$dispatchReceiver];

                return true;
            }
        }
        if (('parseandcompile' === $methodLc || 'parseandcompileemitsmoke' === $methodLc) && null !== $stubBlock) {
            $this->ensureM3EmitTuRuntimeParseAndCompileDeclBeforeQueue(
                ['parseandcompile' => true, 'parseandcompileemitsmoke' => true],
                $stubBlock
            );
            $logical = 'PHPCompiler\\Runtime::'.$methodLc;
            $lc = strtolower($logical);
            if ($this->context->functionIsRegistered($lc)) {
                $this->context->scope->toCall = $this->context->resolveFunctionProxy($lc);
                $this->context->scope->args = [$dispatchReceiver];

                return true;
            }
        }
        static $allowed = [
            'detectfilestricttypes' => true,
            'resetparsernameresolverstate' => true,
            'formatparseandcompilenulldetail' => true,
            'emitparseandcompilenulldiagnostic' => true,
            'recordlastparsefailure' => true,
            'formatphpparsererrorcontext' => true,
            'emitparsecompilefailurestderr' => true,
            'setdebug' => true,
            'setaotdebugsymbols' => true,
        ];
        if (!isset($allowed[$methodLc])) {
            return false;
        }
        $logical = 'PHPCompiler\\Runtime::'.$methodLc;
        $lc = strtolower($logical);
        if (!$this->context->functionIsRegistered($lc)) {
            $this->ensureM3EmitTuRuntimeParseSpineDeps();
        }
        if (!$this->context->functionIsRegistered($lc)) {
            return false;
        }
        $this->context->scope->toCall = $this->context->resolveFunctionProxy($lc);
        $this->context->scope->args = [$dispatchReceiver];

        return true;
    }

    /**
     * @return array<int, JIT\Call> class id => invoke proxy
     */
    private function buildRuntimeInstanceMethodCandidatesByClassId(string $methodLc): array
    {
        $methodLc = strtolower($methodLc);
        $candidates = [];
        foreach ($this->context->type->object->allClassNamesById() as $classId => $className) {
            $classLc = strtolower(ltrim($className, '\\'));
            // Instance dispatch must not invoke static methods that share a short name
            // (HashTable::add vs OutputRewriteVarsJitHelper::add) (#23468).
            if ($this->context->type->object->hasMethod($classId, $methodLc)) {
                $vis = $this->context->type->object->methodVisibility($classId, $methodLc);
                if (0 !== ($vis & \PHPCfg\Func::FLAG_STATIC)) {
                    continue;
                }
            }
            $proxyName = $this->resolveJitInstanceMethodProxyName($classLc, $methodLc);
            if (!$this->context->functionIsRegistered($proxyName)) {
                continue;
            }
            // Static methods are not instance-dispatch targets (zend_execute). Including
            // them mixes e.g. OutputRewriteVarsJitHelper::add into HashTable->$add (#23468).
            if ($this->context->type->object->hasDeclaredClass($classLc)) {
                $vis = $this->context->type->object->methodVisibility(
                    $this->context->type->object->lookup($classLc),
                    $methodLc
                );
                if (0 !== ($vis & \PHPCfg\Func::FLAG_STATIC)) {
                    continue;
                }
            } elseif (
                // Proxy without visibility metadata: still exclude known static rewrite-var helper.
                'phpcompiler\\ext\\standard\\outputrewritevarsjithelper' === $classLc
            ) {
                continue;
            }
            $candidates[$classId] = $this->context->resolveFunctionProxy($proxyName);
        }

        return $candidates;
    }

    /**
     * Subtype-filtered instance dispatch when the declared receiver type has no body
     * (interface / abstract method). Mirrors Zend `zend_std_get_method` (#36382).
     *
     * @return array<int, JIT\Call> class id => invoke proxy
     */
    private function buildRuntimeInstanceMethodCandidatesForDeclaredType(
        string $declaredLc,
        string $methodLc
    ): array {
        $declaredLc = strtolower(ltrim($declaredLc, '\\'));
        if ('' === $declaredLc || 'object' === $declaredLc) {
            return $this->buildRuntimeInstanceMethodCandidatesByClassId($methodLc);
        }
        $allowed = array_flip($this->context->type->object->classIdsInstanceOf($declaredLc));
        if ([] === $allowed) {
            return [];
        }
        $candidates = [];
        foreach ($this->buildRuntimeInstanceMethodCandidatesByClassId($methodLc) as $classId => $call) {
            if (isset($allowed[$classId])) {
                $candidates[$classId] = $call;
            }
        }

        return $candidates;
    }

    /**
     * Safe `__construct` candidates for `new $class` (#27156).
     *
     * Custom Call proxies (LimitIteratorConstruct, …) validate PHP arg counts while
     * emitting every switch arm — skip those. Classes without a constructor get
     * {@see JIT\Call\NoOpConstruct} so stdClass does not abort when Exception is also present.
     *
     * @return array<int, JIT\Call>
     */
    private function buildRuntimeNewConstructCandidatesByClassId(): array
    {
        $object = $this->context->type->object;
        $candidates = [];
        foreach ($object->allClassNamesById() as $classId => $className) {
            if (null !== JIT\InstantiableClassJitGuard::userInstantiationErrorMessage($object, $classId)) {
                continue;
            }
            $classLc = strtolower(ltrim($className, '\\'));
            $proxyName = $this->resolveJitInstanceMethodProxyName($classLc, '__construct');
            if ($this->context->functionIsRegistered($proxyName)) {
                $proxy = $this->context->resolveFunctionProxy($proxyName);
                if ($this->isSafeRuntimeNewConstructProxy($proxy)) {
                    $candidates[$classId] = $proxy;
                    continue;
                }
            }
            $candidates[$classId] = new JIT\Call\NoOpConstruct();
        }

        return $candidates;
    }

    private function isSafeRuntimeNewConstructProxy(JIT\Call $proxy): bool
    {
        return $proxy instanceof JIT\Call\Native
            || $proxy instanceof JIT\Call\ExceptionConstruct
            || $proxy instanceof JIT\Call\SensitiveParameterValueConstruct
            || $proxy instanceof JIT\Call\Vararg
            || $proxy instanceof CoreFunc\Internal;
    }

    /**
     * Resolve lowered instance method proxy, walking extends chain (#101, Zend zend_inheritance).
     */
    private function resolveJitInstanceMethodProxyName(string $classLc, string $methodLc): string
    {
        $methodLc = strtolower($methodLc);
        $visited = [];
        $current = strtolower(ltrim($classLc, '\\'));
        // php-types InternalArgInfo typo: simplexml_load_* → simplemxml_element (#25338, #26911).
        if ('simplemxml_element' === $current) {
            $current = 'simplexmlelement';
            $classLc = 'simplexmlelement';
        }
        while (!isset($visited[$current])) {
            $visited[$current] = true;
            $proxy = $current.'::'.$methodLc;
            if ($this->context->functionIsRegistered($proxy)) {
                return $proxy;
            }
            if ($this->context->type->object->hasDeclaredClass($current)) {
                $classId = $this->context->type->object->lookup($current);
                $traitLc = $this->context->type->object->traitMethodSource($classId, $methodLc);
                if (null !== $traitLc) {
                    $traitProxy = $traitLc.'::'.$methodLc;
                    if ($this->context->functionIsRegistered($traitProxy)) {
                        return $traitProxy;
                    }
                }
            }
            $parent = $this->context->type->object->parentClassLc($current);
            if (null === $parent) {
                break;
            }
            $current = $parent;
        }

        return strtolower(ltrim($classLc, '\\')).'::'.$methodLc;
    }

    /**
     * ext/dom internal classes inherit DOMNode methods without LLVM parentClassLc (#18951).
     */
    private function resolveDomSubclassInstanceMethodProxy(string $classLc, string $methodLc, string $proxyName): string
    {
        if ($this->context->functionIsRegistered($proxyName)) {
            return $proxyName;
        }
        $classLc = strtolower(ltrim($classLc, '\\'));
        if ('dom\\htmldocument' === $classLc || str_ends_with($classLc, '\\htmldocument')) {
            $livingProxy = 'dom\\htmldocument::'.strtolower($methodLc);
            JIT\DomInstanceMethodJit::ensureProxy($this->context, $livingProxy);
            if ($this->context->functionIsRegistered($livingProxy)) {
                return $livingProxy;
            }
        }
        if (!str_starts_with($classLc, 'dom') || 'domnode' === $classLc) {
            return $proxyName;
        }
        JIT\DomInstanceMethodJit::ensureProxy($this->context, 'domnode::'.$methodLc);
        $nodeProxy = 'domnode::'.strtolower($methodLc);
        if ($this->context->functionIsRegistered($nodeProxy)) {
            return $nodeProxy;
        }
        if ('createdocumentfragment' === strtolower($methodLc)) {
            JIT\DomInstanceMethodJit::ensureProxy($this->context, 'domdocument::createdocumentfragment');
            $docProxy = 'domdocument::createdocumentfragment';
            if ($this->context->functionIsRegistered($docProxy)) {
                return $docProxy;
            }
        }
        if ('appendchild' === strtolower($methodLc)) {
            JIT\DomInstanceMethodJit::ensureProxy($this->context, 'domdocumentfragment::appendchild');
            $fragmentProxy = 'domdocumentfragment::appendchild';
            if ($this->context->functionIsRegistered($fragmentProxy)) {
                return $fragmentProxy;
            }
        }

        return $proxyName;
    }

    /**
     * @param array<int, JIT\Variable> $callArgs
     *
     * @return list<JIT\Variable>
     */
    private function densifyInternalCallArgs(CoreFunc\Internal $call, array $callArgs): array
    {
        [$paramNames] = $this->jitCalleeParamMetadata($call);
        if ([] === $paramNames) {
            return $callArgs;
        }

        return JIT\NamedOptionalCallArgs::densifyForSpread($this->context, $callArgs, \count($paramNames));
    }

    /**
     * Save outer FUNCCALL_INIT state before a nested INIT overwrites it (VM #15217; AOT #27242).
     */
    private function saveJitPendingOutboundCall(): void
    {
        if (null === $this->context->scope->toCall) {
            return;
        }
        $this->context->scope->pendingOutboundCallRestore[] = [
            'toCall' => $this->context->scope->toCall,
            'args' => $this->context->scope->args,
            'argOperands' => $this->context->scope->argOperands,
        ];
    }

    private function clearJitOutgoingCallState(): void
    {
        $this->context->scope->toCall = null;
        $this->context->scope->args = [];
        $this->context->scope->argOperands = [];
    }

    private function restoreJitPendingOutboundCall(): void
    {
        if ([] === $this->context->scope->pendingOutboundCallRestore) {
            return;
        }
        $saved = array_pop($this->context->scope->pendingOutboundCallRestore);
        $this->context->scope->toCall = $saved['toCall'];
        $this->context->scope->args = $saved['args'];
        $this->context->scope->argOperands = $saved['argOperands'];
    }

    /**
     * METHODCALL_INIT may bind DateTimeZone::getOffset for any `$x->getOffset()`.
     * DateTime(Immutable)::getOffset() has no datetime arg — rewrite to that proxy (#30761).
     *
     * @param array<int, Variable> $callArgs
     */
    private function rewritePendingDateTimeGetOffsetIfNeeded(array $callArgs): void
    {
        if (empty($this->context->scope->pendingDateTimeZoneGetOffset)) {
            return;
        }
        if (
            $this->context->scope->toCall instanceof JIT\Call\DateTimeZoneGetOffset
            && \count($callArgs) < 2
            && $this->context->functionIsRegistered('datetime::getoffset')
        ) {
            $recv = $callArgs[0] ?? null;
            $hint = is_object($recv) ? strtolower((string) ($recv->classUserType ?? '')) : '';
            $this->context->scope->toCall = $this->context->resolveFunctionProxy(
                'datetimeimmutable' === $hint
                    ? 'datetimeimmutable::getoffset'
                    : 'datetime::getoffset'
            );
        }
        $this->context->scope->pendingDateTimeZoneGetOffset = false;
    }

    /**
     * Dispatch a resolved call, preserving named-arg parameter indices for Native (#23972).
     *
     * @param array<int, Variable> $callArgs
     */
    private function invokeJitCall(JIT\Call $toCall, array $callArgs): \PHPLLVM\Value
    {
        JIT\DeprecatedCallGuard::emitBeforeCall($this->context, $toCall);
        // Leaf-recursive no-throw callees (fibo_r): skip uncaught-trace frames + pending
        // throw checks — they cannot appear on an exception path (#36386).
        $noThrowCallee = JIT\NoThrowCallElision::calleeIsNoThrow($this->context, $toCall, $callArgs);
        $identity = JIT\NoThrowCallElision::tryEmitTrivialIdentity($this->context, $toCall, $callArgs);
        if (null !== $identity) {
            return $identity;
        }
        $trackUncaught = !$noThrowCallee
            && JIT\Builtin\UncaughtThrowPrinter::shouldTrackCall($this->context, $toCall);
        if ($trackUncaught) {
            JIT\Builtin\UncaughtThrowPrinter::emitPushFrame($this->context, $toCall);
        }
        if ($toCall instanceof JIT\Call\Native) {
            $result = $toCall->callWithArgMap($this->context, $callArgs);
        } else {
            // Named optional middle params (DOMDocument::saveXML options:) stay sparse until
            // here; array_values alone would drop the omitted $node slot (#31396 / #32018).
            if (isset($toCall->paramNames) && \is_array($toCall->paramNames) && [] !== $toCall->paramNames) {
                $callArgs = JIT\NamedOptionalCallArgs::densifyForSpread(
                    $this->context,
                    $callArgs,
                    1 + \count($toCall->paramNames)
                );
            }
            $result = $toCall->call($this->context, ...array_values($callArgs));
        }
        if ($trackUncaught) {
            JIT\Builtin\UncaughtThrowPrinter::emitPopFrame($this->context);
        }
        // Enum::from() (and other callees) set throw-pending then return; catch here (#24219).
        if (!$noThrowCallee) {
            JIT\TryCatchHelper::emitCheckPendingThrowAfterCall($this->context);
        }

        return $result;
    }

    /**
     * Flatten ARG_SEND list; unpack entries merge into one packed list (issue #1361).
     *
     * @param list<Variable|array{unpack: Variable}> $argEntries
     *
     * @return list<Variable>
     */
    private function finalizeJitCallArgs(array $argEntries): array
    {
        foreach ($argEntries as $entry) {
            if (\is_array($entry) && isset($entry['unpack'])) {
                return [JIT\HashTableHelper::mergeCallArgEntries($this->context, $argEntries)];
            }
        }

        $out = [];
        foreach ($argEntries as $entry) {
            if (\is_array($entry) && isset($entry['named'])) {
                $out[] = $entry['value'];
                continue;
            }
            $out[] = $entry;
        }

        return $out;
    }

    /**
     * @param list<Variable|array{unpack: Variable}|array{named: string, value: Variable}> $argEntries
     * @param list<Operand|null>                                                          $argOperands
     *
     * @return array{0: list<Variable>, 1: list<Operand|null>, 2: bool}
     */
    private function resolveJitOutgoingCall(JIT\Call $toCall, array $argEntries, array $argOperands): array
    {
        $prefixLen = $this->jitNamedCallArgPrefixLength($toCall, $argEntries);
        $this->context->callSiteOutgoingUserArgCount = max(0, \count($argEntries) - $prefixLen);

        if (null !== $this->context->scope->magicCallMethodName) {
            $methodName = $this->context->scope->magicCallMethodName;
            $this->context->scope->magicCallMethodName = null;
            $rewritten = JIT\MagicMethodDispatch::rewriteOutgoingMagicCallArgs(
                $this->context,
                $methodName,
                $argEntries,
                $argOperands
            );
            // Clear after rewrite — rewrite reads magicCallIsStatic (#27517).
            $this->context->scope->magicCallIsStatic = false;
            if (null !== $rewritten) {
                return [$rewritten[0], $rewritten[1], false];
            }
        }

        if ($this->jitCallArgsHaveUnpack($argEntries)) {
            // Instance methods / constructors prepend $this (NEW result). Named-arg
            // resolution already slices that prefix (#11844); unpack must too or
            // mergeCallArgEntries packs $this into the HT and CallUnpackExpand
            // either mis-indexes or drops user args → ACE "0 passed" (#34468).
            $prefixLen = $this->jitNamedCallArgPrefixLength($toCall, $argEntries);
            $prefix = \array_slice($argEntries, 0, $prefixLen);
            $prefixOperands = \array_slice($argOperands, 0, $prefixLen);
            $userEntries = \array_slice($argEntries, $prefixLen);
            $userOperands = \array_slice($argOperands, $prefixLen);
            $prefixVars = [];
            foreach ($prefix as $entry) {
                if ($entry instanceof Variable) {
                    $prefixVars[] = $entry;
                } elseif (\is_array($entry) && isset($entry['value']) && $entry['value'] instanceof Variable) {
                    $prefixVars[] = $entry['value'];
                }
            }

            [$paramNames, $variadicIndex] = $this->jitCalleeParamMetadata($toCall);
            $functionName = $this->jitInternalBuiltinFunctionName($toCall);
            // Prefer the block being lowered — INIT_ARRAY for ...[1,2] often lives in a
            // successor after a prior ?: / JUMPIF, not the function entry (jitEnclosingBlock).
            // Entry-only lookup drops the unpack → call_user_func forwards 0 args (#35105).
            $unpackBlock = $this->context->jitCurrentBlock ?? $this->context->jitEnclosingBlock;
            $namedUnpack = JIT\CallUnpackHelper::tryResolveCompileTimeNamedUnpack(
                $unpackBlock,
                $userEntries,
                $userOperands,
                $paramNames,
                $variadicIndex,
                $this,
                $functionName
            );
            if (null !== $namedUnpack) {
                if (
                    $toCall instanceof JIT\Call\Native
                    && 1 === \count($namedUnpack[0])
                    && Variable::TYPE_HASHTABLE === $namedUnpack[0][0]->type
                ) {
                    $expanded = JIT\CallUnpackExpand::expandPackedForNative(
                        $this->context,
                        $namedUnpack[0][0],
                        $toCall
                    );
                    if (null !== $expanded) {
                        $full = array_merge($prefixVars, $expanded);

                        return [$full, array_merge($prefixOperands, array_fill(0, \count($expanded), null)), false];
                    }
                }
                $full = array_merge($prefixVars, $namedUnpack[0]);

                return [$full, array_merge($prefixOperands, $namedUnpack[1]), false];
            }

            $callArgs = $this->finalizeJitCallArgs($userEntries);
            if (
                $toCall instanceof JIT\Call\Native
                && 1 === \count($callArgs)
                && Variable::TYPE_HASHTABLE === $callArgs[0]->type
            ) {
                $expanded = JIT\CallUnpackExpand::expandPackedForNative(
                    $this->context,
                    $callArgs[0],
                    $toCall
                );
                if (null !== $expanded) {
                    $full = array_merge($prefixVars, $expanded);

                    return [$full, array_merge($prefixOperands, array_fill(0, \count($expanded), null)), false];
                }
            }
            $full = array_merge($prefixVars, $callArgs);

            return [
                $full,
                array_merge($prefixOperands, $userOperands),
                false,
            ];
        }

        if ($this->jitCallArgsHaveNamed($argEntries)) {
            [$paramNames, $variadicIndex] = $this->jitCalleeParamMetadata($toCall);
            if ([] !== $paramNames) {
                $prefixLen = $this->jitNamedCallArgPrefixLength($toCall, $argEntries);
                $prefix = \array_slice($argEntries, 0, $prefixLen);
                $prefixOperands = \array_slice($argOperands, 0, $prefixLen);
                $userEntries = \array_slice($argEntries, $prefixLen);
                $userOperands = \array_slice($argOperands, $prefixLen);
                $calleeNative = $toCall instanceof JIT\Call\Native ? $toCall : null;
                $builtinName = $this->jitInternalBuiltinFunctionName($toCall);
                $compileTime = JIT\NamedArgs::tryCompileTimeResolveOutgoing(
                    $userEntries,
                    $userOperands,
                    $paramNames,
                    $variadicIndex,
                    $builtinName,
                    $this,
                    $calleeNative,
                    null !== $builtinName
                );
                if (null !== $compileTime) {
                    [$userArgs, $userOps] = $compileTime;
                } else {
                    try {
                        [$userArgs, $userOps] = JIT\NamedArgs::resolveOutgoing(
                            $userEntries,
                            $userOperands,
                            $paramNames,
                            $variadicIndex,
                            $builtinName,
                            $this->context,
                            null !== $builtinName
                        );
                    } catch (\ArgumentCountError $e) {
                        // Defer Zend call-binding errors to runtime so try/catch works (#23449).
                        JIT\ExceptionBridge::emitArgumentCountErrorAndAbort(
                            $this->context,
                            $e->getMessage()
                        );

                        return [[], [], true];
                    } catch (\Error $e) {
                        // Defer unknown named-parameter binding to runtime (#24508, #23490).
                        if (!str_starts_with($e->getMessage(), 'Unknown named parameter $')) {
                            throw $e;
                        }
                        JIT\ExceptionBridge::emitErrorAndAbort($this->context, $e->getMessage());

                        return [[], [], true];
                    }
                }
                $callArgs = $prefix;
                foreach ($userArgs as $idx => $value) {
                    $callArgs[$prefixLen + (int) $idx] = $value;
                }
                $callOperands = $prefixOperands;
                foreach ($userOps as $idx => $operand) {
                    $callOperands[$prefixLen + (int) $idx] = $operand;
                }

                return [$callArgs, $callOperands, false];
            }
        }

        return [
            $this->finalizeJitCallArgs($argEntries),
            $argOperands,
            false,
        ];
    }

    /**
     * @param list<Variable|array{unpack: Variable}|array{named: string, value: Variable}> $argEntries
     */
    private function jitCallArgsHaveNamed(array $argEntries): bool
    {
        foreach ($argEntries as $entry) {
            if (\is_array($entry) && isset($entry['named'])) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param list<Variable|array{unpack: Variable}|array{named: string, value: Variable}> $argEntries
     */
    private function jitCallArgsHaveUnpack(array $argEntries): bool
    {
        foreach ($argEntries as $entry) {
            if (\is_array($entry) && isset($entry['unpack'])) {
                return true;
            }
        }

        return false;
    }

    /** @internal CallUnpackHelper compile-time named unpack (#5031). */
    public function jitVariableFromVmConstantForCallUnpack(VM\Variable $vm): Variable
    {
        return $this->jitVariableFromVmConstant($vm);
    }

    /**
     * @return array{0: list<string>, 1: ?int}
     */
    private function jitCalleeParamMetadata(JIT\Call $toCall): array
    {
        if ($toCall instanceof JIT\Call\Native) {
            if ([] !== $toCall->paramNames) {
                return [$toCall->paramNames, $toCall->namedArgsVariadicIndex];
            }
            $names = BuiltinParamNames::paramNamesForInternalFunction($toCall->name)
                ?? BuiltinParamNames::forClassMethod($toCall->name);

            return [$names ?? [], BuiltinParamNames::variadicParamIndexForFunction($toCall->name)];
        }
        if ($toCall instanceof CoreFunc\Internal) {
            $name = $toCall->getName();
            // VmClassMethod Internals are registered under bare names ('bind'); prefer
            // Closure::… stub tables when the active proxy key is qualified (#24591).
            // Fall through to InternalArgInfo via paramNamesForInternalFunction (#25182).
            $qualified = $this->jitQualifiedProxyNameForCall($toCall);
            if (null !== $qualified) {
                $names = BuiltinParamNames::paramNamesForInternalFunction($qualified);
                if (null !== $names) {
                    return [
                        $names,
                        BuiltinParamNames::variadicParamIndexForFunction($qualified),
                    ];
                }
            }
            $names = BuiltinParamNames::paramNamesForInternalFunction($name)
                ?? BuiltinParamNames::forClassMethod($name);

            return [
                $names ?? [],
                BuiltinParamNames::variadicParamIndexForFunction($name),
            ];
        }
        // Custom Call proxies (Fiber::__construct, WeakReference::create, …) (#24592).
        if (isset($toCall->paramNames) && \is_array($toCall->paramNames) && [] !== $toCall->paramNames) {
            $variadic = $toCall->namedArgsVariadicIndex ?? null;

            return [$toCall->paramNames, \is_int($variadic) ? $variadic : null];
        }
        if ($toCall instanceof JIT\Call\RuntimeIndirectInstanceMethodCall) {
            // Closure::$c->call(newThis: …) / bindTo — candidate set is class-id keyed (#24591).
            $qualified = 'closure::'.$toCall->methodLc;
            $names = BuiltinParamNames::forClassMethod($qualified);
            if (null !== $names) {
                return [
                    $names,
                    BuiltinParamNames::variadicParamIndexForFunction($qualified),
                ];
            }
        }
        $qualified = $this->jitQualifiedProxyNameForCall($toCall);
        if (null !== $qualified) {
            $names = BuiltinParamNames::forClassMethod($qualified)
                ?? BuiltinParamNames::paramNamesForInternalFunction($qualified);
            if (null !== $names && [] !== $names) {
                return [
                    $names,
                    BuiltinParamNames::variadicParamIndexForFunction($qualified),
                ];
            }
        }
        if (isset($toCall->name) && \is_string($toCall->name) && '' !== $toCall->name) {
            $names = BuiltinParamNames::forClassMethod($toCall->name)
                ?? BuiltinParamNames::paramNamesForInternalFunction($toCall->name);

            return [$names ?? [], BuiltinParamNames::variadicParamIndexForFunction($toCall->name)];
        }

        return [[], null];
    }

    /** Reverse-lookup class::method proxy key for a dedicated JIT Call object (#24591). */
    private function jitQualifiedProxyNameForCall(JIT\Call $toCall): ?string
    {
        foreach ($this->context->functionProxies as $proxyName => $proxy) {
            if ($proxy !== $toCall) {
                continue;
            }
            $name = (string) $proxyName;
            if (str_contains($name, '::')) {
                return strtolower($name);
            }
        }

        return null;
    }

    private function jitInternalBuiltinFunctionName(JIT\Call $toCall): ?string
    {
        if ($toCall instanceof JIT\Call\Native) {
            return $toCall->name;
        }
        if ($toCall instanceof CoreFunc\Internal) {
            return $this->jitQualifiedProxyNameForCall($toCall) ?? $toCall->getName();
        }
        $qualified = $this->jitQualifiedProxyNameForCall($toCall);
        if (null !== $qualified) {
            return $qualified;
        }
        if ($toCall instanceof JIT\Call\RuntimeIndirectInstanceMethodCall) {
            $qualified = 'closure::'.$toCall->methodLc;
            if (null !== BuiltinParamNames::forClassMethod($qualified)) {
                return $qualified;
            }
        }
        if (isset($toCall->name) && \is_string($toCall->name) && '' !== $toCall->name) {
            return $toCall->name;
        }

        return null;
    }

    /**
     * Leading $this / NEW result args must not participate in named-arg index resolution (#11844).
     *
     * @param list<Variable|array<string, mixed>> $argEntries
     */
    private function jitNamedCallArgPrefixLength(JIT\Call $toCall, array $argEntries): int
    {
        if ([] === $argEntries || \is_array($argEntries[0])) {
            return 0;
        }
        if (isset($toCall->namedArgsReceiverPrefix) && \is_int($toCall->namedArgsReceiverPrefix)) {
            return max(0, $toCall->namedArgsReceiverPrefix);
        }
        if ($toCall instanceof JIT\Call\Native && [] !== $toCall->argTypes) {
            return '__object__*' === $this->context->getStringFromType($toCall->argTypes[0]) ? 1 : 0;
        }
        // Instance-method proxies prepend $this before user args (Closure::call/bindTo, #24591).
        // DOM JIT Call\Dom* helpers are always instance methods (#25182).
        if ($toCall instanceof JIT\Call\RuntimeIndirectInstanceMethodCall
            || $toCall instanceof JIT\Call\ClosureBindTo
            || str_starts_with($toCall::class, 'PHPCompiler\\JIT\\Call\\Dom')
        ) {
            return 1;
        }
        $qualified = $this->jitQualifiedProxyNameForCall($toCall);
        if (null !== $qualified) {
            if (str_ends_with($qualified, '::call') || str_ends_with($qualified, '::bindto')) {
                return 1;
            }
        }

        return 0;
    }

    /**
     * Static parent::instanceMethod() / self:: / static:: from an instance method passes
     * implicit $this (#1858, #28050).
     */
    /**
     * Static parent::__construct() from an instance method passes only declared params;
     * the callee LLVM signature may still include implicit $this when blockUsesThis().
     *
     * @param array<int, Variable> $args
     *
     * @return array<int, Variable>
     */
    private function prependImplicitThisForStaticInstanceCall(
        Block $block,
        JIT\Call $toCall,
        array $args
    ): array {
        if ($toCall instanceof JIT\Call\RuntimeIndirectStaticMethodCall && $toCall->bindCallerThis) {
            $thisVar = $this->resolveThisVariable($block);
            if (null === $thisVar) {
                return $args;
            }
            array_unshift($args, $thisVar);

            return $args;
        }
        if (!$toCall instanceof JIT\Call\Native) {
            return $args;
        }
        if ([] === $toCall->argTypes) {
            return $args;
        }
        if ('__object__*' !== $this->context->getStringFromType($toCall->argTypes[0])) {
            return $args;
        }
        // Optional trailing params make count($args) < count($argTypes) even when TYPE_NEW
        // already seeded $this — that used to double-prepend and shift user args (#36382).
        if (\count($args) >= $toCall->minimumPositionalArgCountWithReceiver()) {
            return $args;
        }
        if (null === $block->func || null === $block->func->cfg) {
            return $args;
        }
        if (($block->func->flags ?? 0) & \PHPCfg\Func::FLAG_STATIC) {
            return $args;
        }
        $thisVar = $this->resolveThisVariable($block);
        if (null === $thisVar) {
            return $args;
        }

        array_unshift($args, $thisVar);

        return $args;
    }

    /**
     * @param list<Variable|array{unpack: Variable}> $args
     * @param list<Operand|null> $operands
     *
     * @return list<Operand|null>
     */
    private function prependImplicitThisOperandForStaticInstanceCall(
        Block $block,
        JIT\Call\Native $toCall,
        array $operands
    ): array {
        if ([] === $toCall->argTypes) {
            return $operands;
        }
        if ('__object__*' !== $this->context->getStringFromType($toCall->argTypes[0])) {
            return $operands;
        }
        if (\count($operands) >= $toCall->minimumPositionalArgCountWithReceiver()) {
            return $operands;
        }
        if (null === $block->func || null === $block->func->cfg) {
            return $operands;
        }
        if (($block->func->flags ?? 0) & \PHPCfg\Func::FLAG_STATIC) {
            return $operands;
        }
        if (null === $this->resolveThisVariable($block)) {
            return $operands;
        }

        array_unshift($operands, null);

        return $operands;
    }

    private function resolveThisVariable(Block $block): ?Variable
    {
        if (null === $block->func || null === $block->func->cfg) {
            if (null !== $this->context->implicitThisArgument) {
                return $this->context->implicitThisArgument;
            }

            return null;
        }
        foreach ($block->func->cfg->hoistedOperands as $hoisted) {
            if ('this' !== JIT\OperandName::resolve($hoisted)) {
                continue;
            }
            if ($this->context->hasVariableOpInScopes($hoisted)) {
                return $this->context->getVariableFromOpInScopes($hoisted);
            }
            // Hoisted $this not materialized yet — fall through to LLVM param 0.
            break;
        }

        if (null !== $this->context->implicitThisArgument) {
            return $this->context->implicitThisArgument;
        }

        return null;
    }

    /**
     * LLVM argument index => VM type constraint.
     *
     * @return array<int, int>
     */
    private function paramTypeConstraintsForNativeCall(Block $block): array
    {
        $constraints = [];
        $offset = $this->llvmThisParamOffset($block);
        foreach ($block->opCodes as $op) {
            if (OpCode::TYPE_ARG_RECV !== $op->type) {
                continue;
            }
            $slot = (int) $op->arg1;
            $paramIdx = (int) $op->arg2;
            $isVariadic = null !== $block->variadicParamIndex && $paramIdx === $block->variadicParamIndex;
            if ($isVariadic) {
                if (
                    isset($block->paramVariadicElementIntersectionConstraints[$slot])
                    || isset($block->paramVariadicElementDnfConstraints[$slot])
                ) {
                    continue;
                }
                if (!isset($block->paramVariadicElementTypeConstraints[$slot])) {
                    continue;
                }
                $constraints[$paramIdx + $offset] = $block->paramVariadicElementTypeConstraints[$slot];
                continue;
            }
            if (
                isset($block->paramIntersectionConstraints[$slot])
                || isset($block->paramDnfConstraints[$slot])
            ) {
                continue;
            }
            if (!isset($block->paramTypeConstraints[$slot])) {
                continue;
            }
            $constraints[$paramIdx + $offset] = $block->paramTypeConstraints[$slot];
        }

        return $constraints;
    }

    /**
     * @return array<int, true>
     */
    private function paramImplicitNullableForNativeCall(Block $block): array
    {
        $implicit = [];
        $offset = $this->llvmThisParamOffset($block);
        foreach ($block->opCodes as $op) {
            if (OpCode::TYPE_ARG_RECV !== $op->type) {
                continue;
            }
            $slot = (int) $op->arg1;
            if (!isset($block->paramImplicitNullable[$slot])) {
                continue;
            }
            $implicit[(int) $op->arg2 + $offset] = true;
        }

        return $implicit;
    }

    /**
     * @return array<int, list<string>>
     */
    private function paramIntersectionConstraintsForNativeCall(Block $block): array
    {
        $constraints = [];
        $offset = $this->llvmThisParamOffset($block);
        foreach ($block->opCodes as $op) {
            if (OpCode::TYPE_ARG_RECV !== $op->type) {
                continue;
            }
            $slot = (int) $op->arg1;
            $paramIdx = (int) $op->arg2;
            $isVariadic = null !== $block->variadicParamIndex && $paramIdx === $block->variadicParamIndex;
            if ($isVariadic) {
                if (!isset($block->paramVariadicElementIntersectionConstraints[$slot])) {
                    continue;
                }
                $constraints[$paramIdx + $offset] = $block->paramVariadicElementIntersectionConstraints[$slot];
                continue;
            }
            if (!isset($block->paramIntersectionConstraints[$slot])) {
                continue;
            }
            $constraints[$paramIdx + $offset] = $block->paramIntersectionConstraints[$slot];
        }

        return $constraints;
    }

    /**
     * @return array<int, string>
     */
    private function paramClassConstraintsForNativeCall(Block $block): array
    {
        $constraints = [];
        $offset = $this->llvmThisParamOffset($block);
        foreach ($block->opCodes as $op) {
            if (OpCode::TYPE_ARG_RECV !== $op->type) {
                continue;
            }
            $slot = (int) $op->arg1;
            $paramIdx = (int) $op->arg2;
            if (!isset($block->paramClassConstraints[$slot])) {
                continue;
            }
            $constraint = $block->paramClassConstraints[$slot];
            // Trait flatten sets traitComposingClassName — bind `parent` like VM (#31747).
            if ('parent' === strtolower(ltrim($constraint, '\\'))) {
                try {
                    $constraint = $this->resolveJitStaticScopeClass(
                        $block,
                        new Operand\Literal('parent')
                    );
                } catch (\Throwable) {
                    // Keep lexical keyword when composing parent is unavailable.
                }
            }
            $constraints[$paramIdx + $offset] = $constraint;
        }

        return $constraints;
    }

    /**
     * @return array<int, list<array{kind: string, interfaces?: list<string>, display?: string, name?: string}>>
     */
    private function paramDnfConstraintsForNativeCall(Block $block): array
    {
        $constraints = [];
        $offset = $this->llvmThisParamOffset($block);
        foreach ($block->opCodes as $op) {
            if (OpCode::TYPE_ARG_RECV !== $op->type) {
                continue;
            }
            $slot = (int) $op->arg1;
            $paramIdx = (int) $op->arg2;
            $isVariadic = null !== $block->variadicParamIndex && $paramIdx === $block->variadicParamIndex;
            if ($isVariadic) {
                if (!isset($block->paramVariadicElementDnfConstraints[$slot])) {
                    continue;
                }
                $constraints[$paramIdx + $offset] = $block->paramVariadicElementDnfConstraints[$slot];
                continue;
            }
            if (!isset($block->paramDnfConstraints[$slot])) {
                continue;
            }
            $constraints[$paramIdx + $offset] = $block->paramDnfConstraints[$slot];
        }

        return $constraints;
    }

    /**
     * LLVM argument index => by-reference formal (issue #3161, #140).
     *
     * @return array<int, true>
     */
    private function paramByRefForNativeCall(Block $block): array
    {
        $refs = [];
        $offset = $this->llvmThisParamOffset($block);
        foreach ($block->opCodes as $op) {
            if (OpCode::TYPE_ARG_RECV !== $op->type) {
                continue;
            }
            if (!isset($block->paramByRef[(int) $op->arg2])) {
                continue;
            }
            $refs[(int) $op->arg2 + $offset] = true;
        }

        return $refs;
    }

    private function bindJitParamByReference(
        Block $block,
        Operand $paramOperand,
        Variable $callerArg
    ): void {
        if (!$this->context->hasVariableOp($paramOperand)) {
            throw new \LogicException('By-reference parameter requires a bound operand');
        }
        $paramVar = $this->context->getVariableFromOp($paramOperand);
        JIT\ClosureHelper::bindCaptureSlotByReference($this->context, $paramVar, $callerArg);
        $this->context->setVariableOp($paramOperand, $paramVar);
        // php-cfg uses a fresh SSA var for `$x = …` inside the callee; bind the name so
        // assigns/reads share the aliased formal (#24162, Zend ZEND_RECV / SEND_REF).
        $name = JIT\OperandName::resolve($paramOperand);
        if (null !== $name && '' !== $name) {
            $this->context->bindVariableByName($name, $paramVar);
        }
        $this->syncJitParamVariableToSlotOperands($block, $paramOperand, $paramVar);
    }

    /** Rebind every scoped operand sharing a formal slot (#27624, e06_byref `$r = $v`). */
    private function syncJitParamVariableToSlotOperands(
        Block $block,
        Operand $paramOperand,
        Variable $paramVar
    ): void {
        $slot = $block->slotForOperand($paramOperand);
        if (null === $slot) {
            return;
        }
        foreach ($block->scopedOperands() as $scopeOp) {
            if ($block->slotForOperand($scopeOp) === $slot) {
                $this->context->setVariableOp($scopeOp, $paramVar);
            }
        }
    }

    /**
     * {@see TYPE_ASSIGN} into a by-ref formal: write through the LLVM {@see __value__*} edge
     * argument (ZEND_SEND_REF / zend_assign_to_variable), bypassing orphan SSA operands (#e06_byref).
     *
     * @param list<Variable> $args
     */
    private function emitAssignOperandWithByRefFormalFastPath(
        Block $block,
        Operand $destOp,
        Operand $rhsOperand,
        Variable $value,
        array $args,
        int $thisParamOffset,
        bool $force
    ): void {
        if (
            !$this->tryEmitByRefFormalValueBoxAssign(
                $block,
                $destOp,
                $rhsOperand,
                $value,
                $args,
                $thisParamOffset
            )
        ) {
            $this->assignOperand($destOp, $value, $force);
        }
    }

    /**
     * {@see TYPE_ASSIGN} into a by-ref formal: write through the LLVM {@see __value__*} edge
     * argument (ZEND_SEND_REF / zend_assign_to_variable), bypassing orphan SSA operands (#e06_byref).
     *
     * @param list<Variable> $args
     */
    private function tryEmitByRefFormalValueBoxAssign(
        Block $block,
        Operand $destOp,
        Operand $rhsOperand,
        Variable $value,
        array $args,
        int $thisParamOffset
    ): bool {
        if (null === $block->func || [] === $block->paramByRef) {
            return false;
        }
        $refIdx = $this->byRefFormalParamIndexForAssignDest($block, $destOp);
        if (null === $refIdx) {
            return false;
        }
        $formalRhs = $this->tryResolveFormalParamVariableForRhs($block, $rhsOperand);
        if (null !== $formalRhs) {
            $value = $formalRhs;
        } else {
            $rhsSlot = $block->slotForOperand($rhsOperand);
            if (null !== $rhsSlot) {
                foreach ($block->func->params as $param) {
                    if (
                        $block->slotForOperand($param->result) === $rhsSlot
                        && $this->context->hasVariableOp($param->result)
                    ) {
                        $value = $this->context->getVariableFromOp($param->result);
                        break;
                    }
                }
            } elseif ($this->context->hasVariableOp($rhsOperand)) {
                $value = $this->context->getVariableFromOp($rhsOperand);
            }
        }
        $destBinding = $this->resolveByRefFormalAssignDestBinding($block, $destOp, $args, $thisParamOffset, $refIdx);
        if (null === $destBinding) {
            return false;
        }
        [$destPtr, $destVar] = $destBinding;
        if ($this->tryEmitByRefFormalAssignFromCalleeFormal($block, $rhsOperand, $destPtr, $args, $thisParamOffset)) {
            // emitted from ABI formal edge
        } elseif ($this->tryEmitDirectByRefFormalValueBoxCopy($destPtr, $value)) {
            // emitted
        } elseif (
            Variable::TYPE_VALUE === $value->type
            && Variable::KIND_VARIABLE === $value->kind
            && '__value__' === $this->context->getStringFromType($value->value->typeOf())
        ) {
            JIT\JitValueBox::copyIntoPointer(
                $this->context,
                $destPtr,
                JIT\JitValueBox::pointer($this->context, $value->value)
            );
        } else {
            JIT\JitValueBox::assignToPointer($this->context, $destPtr, $value);
        }
        JIT\JitValueBox::publishAfterWrite($this->context, $destPtr);
        $this->context->setVariableOp($destOp, $destVar);
        $destName = JIT\OperandName::resolve($destOp);
        if (null !== $destName && '' !== $destName) {
            $this->context->bindVariableByName(
                $this->context->resolveRefAliasName($destName),
                $destVar
            );
        }
        JIT\UndefinedVariableHelper::markAssigned($this->context, $destOp, $destVar);

        return true;
    }

    /**
     * `$r = $v` when RHS is an untyped formal still on the LLVM {@see __value__} edge (#e06_byref).
     *
     * @param list<Variable> $args
     */
    private function tryEmitByRefFormalAssignFromCalleeFormal(
        Block $block,
        Operand $rhsOperand,
        \PHPLLVM\Value $destPtr,
        array $args,
        int $thisParamOffset
    ): bool {
        $rhsSlot = $block->slotForOperand($rhsOperand);
        if (null === $rhsSlot) {
            return false;
        }
        foreach ($block->func->params as $idx => $param) {
            if ($block->slotForOperand($param->result) !== $rhsSlot) {
                continue;
            }
            $argIdx = $thisParamOffset + (int) $idx;
            if (!isset($args[$argIdx])) {
                return false;
            }
            $formal = $args[$argIdx];
            if (
                Variable::KIND_VALUE !== $formal->kind
                || Variable::TYPE_VALUE !== $formal->type
                || '__value__' !== $this->context->getStringFromType($formal->value->typeOf())
            ) {
                return false;
            }
            if (!JIT\BasicBlockHelper::unsealAndContinue($this->context)) {
                JIT\BasicBlockHelper::ensureOpenInsertBlockReplacingVoidReturn($this->context, 'byref_formal_abi_cont');
            }
            $slot = JIT\JitValueBox::alloc($this->context);
            $this->context->builder->store($formal->value, $slot);
            $srcPtr = JIT\JitValueBox::pointer($this->context, $slot);
            $long = $this->context->builder->call(
                $this->context->lookupFunction('__value__readLong'),
                $srcPtr
            );
            $this->context->builder->call(
                $this->context->lookupFunction('__value__writeLong'),
                $destPtr,
                $long
            );
            JIT\JitValueBox::publishAfterWrite($this->context, $destPtr);

            return true;
        }

        return false;
    }

    /**
     * Direct typed write into a by-ref formal edge — avoids copyBetweenPointers dispatch
     * picking the wrong source box when orphan SSA operands share a scope slot (#e06_byref).
     */
    private function tryEmitDirectByRefFormalValueBoxCopy(\PHPLLVM\Value $destPtr, Variable $value): bool
    {
        if (Variable::TYPE_NATIVE_LONG === $value->type) {
            $this->context->builder->call(
                $this->context->lookupFunction('__value__writeLong'),
                $destPtr,
                $this->context->helper->loadValue($value)
            );

            return true;
        }
        $srcPtr = null;
        if (
            Variable::TYPE_VALUE === $value->type
            && Variable::KIND_VALUE === $value->kind
            && '__value__*' === $this->context->getStringFromType($value->value->typeOf())
        ) {
            $srcPtr = JIT\JitValueBox::normalizeValuePtr($this->context, $value->value);
        } elseif (
            Variable::TYPE_VALUE === $value->type
            && Variable::KIND_VARIABLE === $value->kind
        ) {
            $llvmTy = $this->context->getStringFromType($value->value->typeOf());
            if ('__value__*' === $llvmTy) {
                $srcPtr = JIT\JitValueBox::normalizeValuePtr($this->context, $value->value);
            } elseif ('__value__' === $llvmTy) {
                $srcPtr = JIT\JitValueBox::pointer($this->context, $value->value);
            }
        }
        if (null === $srcPtr) {
            return false;
        }
        if (!JIT\BasicBlockHelper::unsealAndContinue($this->context)) {
            JIT\BasicBlockHelper::ensureOpenInsertBlockReplacingVoidReturn($this->context, 'byref_formal_assign_cont');
        }
        $map = $this->context->structFieldMap['__value__'];
        $typeByte = $this->context->builder->load(
            $this->context->builder->structGep($srcPtr, $map['type'])
        );
        $i8 = $this->context->getTypeFromString('int8');
        $kind = $this->context->builder->and($typeByte, $i8->constInt(0x7f, false));
        $isLong = $this->context->builder->icmp(
            \PHPLLVM\Builder::INT_EQ,
            $kind,
            $i8->constInt(Variable::TYPE_NATIVE_LONG, false)
        );
        $longBlock = JIT\BasicBlockHelper::append($this->context, 'byref_formal_assign_long');
        $slowBlock = JIT\BasicBlockHelper::append($this->context, 'byref_formal_assign_slow');
        $doneBlock = JIT\BasicBlockHelper::append($this->context, 'byref_formal_assign_done');
        $this->context->builder->branchIf($isLong, $longBlock, $slowBlock);
        $this->context->builder->positionAtEnd($longBlock);
        $long = $this->context->builder->call(
            $this->context->lookupFunction('__value__readLong'),
            $srcPtr
        );
        $this->context->builder->call(
            $this->context->lookupFunction('__value__writeLong'),
            $destPtr,
            $long
        );
        $this->context->builder->branch($doneBlock);
        $this->context->builder->positionAtEnd($slowBlock);
        JIT\JitValueBox::assignToPointer($this->context, $destPtr, $value);
        $this->context->builder->branch($doneBlock);
        $this->context->builder->positionAtEnd($doneBlock);

        return true;
    }

    /**
     * @param list<Variable> $args
     *
     * @return array{0: \PHPLLVM\Value, 1: Variable}|null
     */
    private function resolveByRefFormalAssignDestBinding(
        Block $block,
        Operand $destOp,
        array $args,
        int $thisParamOffset,
        int $refIdx
    ): ?array {
        $param = $block->func->params[$refIdx] ?? null;
        if (null !== $param && $this->context->hasVariableOp($param->result)) {
            $paramVar = $this->context->getVariableFromOp($param->result);
            if (null !== $paramVar->valueBoxAliasPtr) {
                return [
                    JIT\JitValueBox::normalizeValuePtr($this->context, $paramVar->valueBoxAliasPtr),
                    $paramVar,
                ];
            }
        }
        $argIdx = $thisParamOffset + $refIdx;
        if (isset($args[$argIdx])) {
            $argVar = $args[$argIdx];
            $destVar = $argVar;
            if (null !== $param && $this->context->hasVariableOp($param->result)) {
                $destVar = $this->context->getVariableFromOp($param->result);
            }

            return [
                JIT\JitValueBox::valuePtrFromVariable($this->context, $argVar),
                $destVar,
            ];
        }
        $destName = JIT\OperandName::resolve($destOp);
        if (null !== $destName && '' !== $destName) {
            $boundName = $this->context->resolveRefAliasName($destName);
            if (isset($this->context->namedVariableBindings[$boundName])) {
                $bound = $this->context->namedVariableBindings[$boundName];
                if (null !== $bound->valueBoxAliasPtr) {
                    return [
                        JIT\JitValueBox::normalizeValuePtr($this->context, $bound->valueBoxAliasPtr),
                        $bound,
                    ];
                }
            }
        }
        $paramName = $block->paramNames[$refIdx] ?? null;
        if (null !== $paramName && '' !== $paramName) {
            $boundName = $this->context->resolveRefAliasName($paramName);
            if (isset($this->context->namedVariableBindings[$boundName])) {
                $bound = $this->context->namedVariableBindings[$boundName];
                if (null !== $bound->valueBoxAliasPtr) {
                    return [
                        JIT\JitValueBox::normalizeValuePtr($this->context, $bound->valueBoxAliasPtr),
                        $bound,
                    ];
                }
            }
        }

        return null;
    }

    private function byRefFormalParamIndexForAssignDest(Block $block, Operand $destOp): ?int
    {
        if (null === $block->func) {
            return null;
        }
        $destSlot = $block->slotForOperand($destOp);
        if (null !== $destSlot) {
            foreach ($block->paramByRef as $paramIdx => $_) {
                $param = $block->func->params[$paramIdx] ?? null;
                if (null === $param) {
                    continue;
                }
                if ($block->slotForOperand($param->result) === $destSlot) {
                    return (int) $paramIdx;
                }
            }
        }
        $destName = JIT\OperandName::resolve($destOp);
        if (null === $destName || '' === $destName) {
            return null;
        }
        foreach ($block->paramByRef as $paramIdx => $_) {
            $paramName = $block->paramNames[$paramIdx] ?? null;
            if (null === $paramName || $paramName !== $destName) {
                continue;
            }

            return (int) $paramIdx;
        }

        return null;
    }

    /**
     * php-cfg may use a distinct SSA operand for `$r = …` vs the param's {@see Param::result};
     * rebind before assign so {@see Variable::$valueBoxAliasPtr} is not lost (#e06_byref).
     */
    private function rebindAssignLvalueFromByRefFormalOrName(Operand $resultOp): bool
    {
        if ($this->context->hasVariableOp($resultOp)) {
            $existing = $this->context->getVariableFromOp($resultOp);
            if (
                null !== $existing->valueBoxAliasPtr
                || $existing->borrowedValueEntry
                || null !== $existing->foreachByRefPackedArm
                || $existing->assignRefLvalueAlias
            ) {
                return false;
            }
        }
        $block = $this->context->jitEnclosingBlock;
        if (null !== $block && null !== $block->func) {
            $slot = $block->slotForOperand($resultOp);
            if (null !== $slot) {
                foreach ($block->func->params as $paramIdx => $param) {
                    if (!isset($block->paramByRef[$paramIdx])) {
                        continue;
                    }
                    if ($block->slotForOperand($param->result) !== $slot) {
                        continue;
                    }
                    if (!$this->context->hasVariableOp($param->result)) {
                        continue;
                    }
                    $paramVar = $this->context->getVariableFromOp($param->result);
                    if (
                        null === $paramVar->valueBoxAliasPtr
                        && !$paramVar->borrowedValueEntry
                    ) {
                        continue;
                    }
                    $this->context->setVariableOp($resultOp, $paramVar);

                    return true;
                }
            }
        }
        $name = JIT\OperandName::resolve($resultOp);
        if (null === $name || '' === $name) {
            return false;
        }
        $boundName = $this->context->resolveRefAliasName($name);
        if (!isset($this->context->namedVariableBindings[$boundName])) {
            return false;
        }
        $bound = $this->context->namedVariableBindings[$boundName];
        if (
            null === $bound->valueBoxAliasPtr
            && !$bound->borrowedValueEntry
            && null === $bound->foreachByRefPackedArm
            && null === $bound->writableHt
            && null === $bound->objectPropertySlot
            && null === $bound->staticPropertyGlobal
            && !$bound->assignRefLvalueAlias
        ) {
            return false;
        }
        $this->context->setVariableOp($resultOp, $bound);

        return true;
    }

    /**
     * Recv a by-value {@see __value__} ABI formal via struct store, not copyBetweenPointers (#e06_byref).
     *
     * Sealed prologue BBs made the dispatch copy unreachable; `$r = $v` then copied null into
     * the caller's by-ref slot.
     */
    private function storeJitCalleeValueStructFormal(Operand $paramOperand, Variable $formalArg): bool
    {
        if (Variable::KIND_VALUE !== $formalArg->kind || Variable::TYPE_VALUE !== $formalArg->type) {
            return false;
        }
        if ('__value__' !== $this->context->getStringFromType($formalArg->value->typeOf())) {
            return false;
        }
        if (!$this->context->hasVariableOp($paramOperand)) {
            return false;
        }
        $dest = $this->context->getVariableFromOp($paramOperand);
        if ('__value__' !== $this->context->getStringFromType($dest->value->typeOf())) {
            return false;
        }
        $this->context->builder->store($formalArg->value, $dest->value);
        $dest->addref();
        JIT\UndefinedVariableHelper::markAssigned($this->context, $paramOperand, $dest);

        return true;
    }

    /**
     * @param list<Variable> $args
     * @param list<Operand> $operands
     *
     * @return list<Variable>
     */
    private function adaptByRefCallArgs(
        JIT\Call\Native $call,
        array $args,
        array $operands,
        Block $block
    ): array {
        if ([] === $call->paramByRefByArg) {
            return $args;
        }
        foreach ($call->paramByRefByArg as $idx => $_) {
            if (null !== $call->variadicArgIndex && $idx === $call->variadicArgIndex) {
                continue;
            }
            if (!isset($args[$idx])) {
                continue;
            }
            $operand = $operands[$idx] ?? null;
            if (null === $operand) {
                continue;
            }
            $args[$idx] = $this->adaptNativeByRefCallArg($call, $block, $idx, $operand, $args[$idx]);
        }
        if (
            null !== $call->variadicArgIndex
            && isset($call->paramByRefByArg[$call->variadicArgIndex])
        ) {
            $start = $call->variadicArgIndex;
            $end = \count($args) - 1;
            if (null !== $call->namedArgsVariadicIndex) {
                $trailing = \count($call->paramNames) - $call->namedArgsVariadicIndex - 1;
                if ($trailing > 0) {
                    $end = \count($args) - $trailing - 1;
                }
            }
            for ($idx = $start; $idx <= $end; ++$idx) {
                if (!isset($args[$idx])) {
                    continue;
                }
                $operand = $operands[$idx] ?? null;
                if (null === $operand) {
                    continue;
                }
                $args[$idx] = $this->adaptNativeByRefCallArg($call, $block, $idx, $operand, $args[$idx]);
            }
        }

        return $args;
    }

    /**
     * User/method by-ref actual: named lvalue → alias; call/new return temp → Notice + temp box;
     * other non-variables → Error (#30027, zend_execute.c ZEND_SEND_VAR_NO_REF).
     */
    private function adaptNativeByRefCallArg(
        JIT\Call\Native $call,
        Block $block,
        int $idx,
        Operand $operand,
        Variable $arg
    ): Variable {
        $namedLocalSlot = $block->slotForOperand($operand);
        if (null !== $namedLocalSlot && $block->isNamedVariableSlot((int) $namedLocalSlot)) {
            return $this->ensureValueBoxLvalueForByRefPass($operand, $arg);
        }
        if (JIT\JitReferencableCheck::isOperandReferenceable($operand, $arg)) {
            return $this->ensureValueBoxLvalueForByRefPass($operand, $arg);
        }
        if (VM\ReferencableCheck::operandIsFuncCallReturn($operand, $block)) {
            JIT\JitReferencableCheck::emitNonVariableByRefNotice($this->context);
            $arg->nonVariableByRefTempAllowed = true;

            return $this->ensureValueBoxLvalueForByRefPass($operand, $arg);
        }
        // Match Call\Native::receiverPrefix — Argument #N skips implicit $this (#30027).
        $receiverPrefix = (
            [] !== $call->paramNames
            && \count($call->argTypes) === \count($call->paramNames) + 1
        ) ? 1 : 0;
        $phpParamIdx = max(0, $idx - $receiverPrefix);
        JIT\JitReferencableCheck::emitByRefError(
            $this->context,
            $call->name,
            $phpParamIdx,
            $call->paramNames
        );

        return $arg;
    }

    /**
     * @param list<JIT\Variable>           $args
     * @param list<Operand|null>           $operands
     *
     * @return list<JIT\Variable>
     */
    private function foldSortFamilyFlagsArg(string $name, array $args, array $operands, Block $block): array
    {
        $lc = strtolower($name);
        if (!\in_array($lc, [
            'sort', 'rsort', 'asort', 'arsort', 'ksort', 'krsort',
            // array_unique flags share SORT_*|SORT_FLAG_CASE folding (#29114).
            'array_unique',
        ], true)) {
            return $args;
        }
        if (2 !== \count($args) || !isset($operands[1])) {
            return $args;
        }
        $resolved = \PHPCompiler\ext\standard\VmInternalCompare::tryResolveJitSortFlags($this->context, $args[1])
            ?? \PHPCompiler\ext\standard\VmInternalCompare::tryResolveJitSortFlagsFromBlock(
                $this->context,
                $block,
                $operands[1]
            );
        if (null !== $resolved) {
            $args[1] = JIT\Variable::fromConstantInt($this->context, $resolved);
        }

        return $args;
    }

    private function ensureValueBoxLvalueForByRefPass(Operand $op, Variable $var): Variable
    {
        // Zend: cannot create references to/from string offsets (#29523 / #21910).
        if (JIT\StringOffsetHelper::isWritableCharOffsetLvalue($var, $this->context)) {
            JIT\StringOffsetHelper::emitRefError($this->context);
            $this->context->builder->call($this->context->lookupFunction('abort'));
            $this->context->builder->clearInsertionPosition();

            return $var;
        }
        // Promote the caller's lvalue in place. Copying into a fresh box left the
        // original native/script-global binding unchanged, so AOT saw the pre-call
        // value (or null on {main}) after return (#24162, Zend ZEND_SEND_REF).
        $promoted = JIT\ClosureHelper::referenceCapture($this->context, $var);
        $this->context->setVariableOp($op, $promoted);
        $name = JIT\OperandName::resolve($op);
        if (null !== $name) {
            $this->context->bindVariableByName($name, $promoted);
        }
        $block = $this->context->jitCurrentBlock;
        if (null !== $block) {
            $slot = $block->slotForOperand($op);
            if (null !== $slot) {
                foreach ($block->scopedOperands() as $scopeOp) {
                    if ($block->slotForOperand($scopeOp) === $slot) {
                        $this->context->setVariableOp($scopeOp, $promoted);
                    }
                }
            }
        }

        return $promoted;
    }

    /**
     * Optional-param defaults as {@see VM\Variable} recipes — not lowered LLVM Values.
     *
     * Call sites rematerialize via {@see JIT\Call\Native::materializeDefaultArg()} so empty
     * array / null / string defaults are not reused across functions (Nyholm Response::__construct
     * `[]`/`null` → parentless `__hashtable__alloc` / dominate-fail under Slim AOT, #36382).
     *
     * @return array<int, VM\Variable>
     */
    private function collectParamDefaults(Block $block): array {
        $defaults = [];
        foreach ($block->opCodes as $op) {
            if ($op->type !== OpCode::TYPE_ARG_RECV) {
                continue;
            }
            if (null === $op->arg3) {
                continue;
            }
            if (null !== $block->variadicParamIndex && $block->variadicParamIndex === (int) $op->arg2) {
                continue;
            }
            if (!isset($block->constants[$op->arg3])) {
                continue;
            }
            $defaultIdx = $op->arg2;
            if ($this->instanceMethodUsesThis($block)) {
                ++$defaultIdx;
            }
            $defaults[$defaultIdx] = $block->constants[$op->arg3];
        }
        return $defaults;
    }

    /**
     * Promoted ctor params with `new` defaults — property initialized at allocate() (#6652).
     *
     * @return array<int, array{prop: string, declClass: string}> LLVM arg index => promoted property meta
     */
    private function collectPromotedRuntimeNewDefaultProps(Block $block): array
    {
        if (!$this->instanceMethodUsesThis($block)) {
            return [];
        }
        $classId = $this->context->scope->classId;
        $declClass = ltrim($this->context->scope->className, '\\');
        $thisParamOffset = $this->llvmThisParamOffset($block);
        $defaults = [];
        foreach ($block->opCodes as $op) {
            if (OpCode::TYPE_ARG_RECV !== $op->type) {
                continue;
            }
            $paramIdx = (int) $op->arg2;
            if (null !== $block->variadicParamIndex && $block->variadicParamIndex === $paramIdx) {
                continue;
            }
            if (!isset($block->paramRuntimeDefaultInitBlocks[$paramIdx])) {
                continue;
            }
            $propName = $block->paramNames[$paramIdx] ?? null;
            if (!is_string($propName) || '' === $propName) {
                continue;
            }
            if ($classId >= 0) {
                $initBlock = $block->paramRuntimeDefaultInitBlocks[$paramIdx];
                $newClass = $this->jitPropertyNewClassNameFromOps($initBlock, $initBlock->opCodes);
                if (null !== $newClass) {
                    $this->context->type->object->definePropertyRuntimeNewDefault(
                        $classId,
                        $propName,
                        $newClass
                    );
                    $this->context->type->object->definePropertyRuntimeNewInitFragment(
                        $classId,
                        $propName,
                        $initBlock,
                        $block->paramRuntimeDefaultResultSlots[$paramIdx]
                            ?? throw new \LogicException('Missing runtime parameter default result slot')
                    );
                }
            }
            $defaults[$paramIdx + $thisParamOffset] = ['prop' => $propName, 'declClass' => $declClass];
        }

        return $defaults;
    }

    /**
     * Lower a property/param `new` init fragment at the current insert point (#3391, #6652).
     */
    public function jitVariableFromRuntimeNewInitFragment(Block $initBlock, int $resultSlot): Variable
    {
        JIT\BasicBlockHelper::ensureOpenInsertBlock($this->context, 'runtime_new_init');
        $func = JIT\BasicBlockHelper::parentFunction($this->context);
        $entry = $func->appendBasicBlock('runtime_new_init_entry');
        $cont = $func->appendBasicBlock('runtime_new_init_cont');
        $this->context->builder->branch($entry);
        $savedToCall = $this->context->scope->toCall;
        $savedArgs = $this->context->scope->args;
        $savedArgOperands = $this->context->scope->argOperands;
        $savedPreserveNew = $this->context->scope->preserveNewResultOnNullCall;
        $saved = $initBlock->syntheticCfgBranch ?? false;
        $initBlock->syntheticCfgBranch = true;
        try {
            $tail = $this->compileSubBlockAtEntry($func, $initBlock, $entry);
        } finally {
            $initBlock->syntheticCfgBranch = $saved;
            $this->context->scope->toCall = $savedToCall;
            $this->context->scope->args = $savedArgs;
            $this->context->scope->argOperands = $savedArgOperands;
            $this->context->scope->preserveNewResultOnNullCall = $savedPreserveNew;
        }
        JIT\BasicBlockHelper::ensureOpenInsertBlock($this->context, 'runtime_new_init_done');
        $this->context->builder->positionAtEnd($tail);
        $var = $this->variableFromBlockSlot($initBlock, $resultSlot);
        $this->context->builder->branch($cont);
        $this->context->builder->positionAtEnd($cont);

        return $var;
    }

    /**
     * Resolve a class constant initializer for JIT defineClassConst (#4900, zend_constants.c).
     */
    private function jitClassConstDefineValue(
        Block $block,
        OpCode $op,
        string $constNameLc,
        int $classId,
        ?string $constDisplayName = null
    ): VM\Variable {
        if (
            !isset($block->constants[$op->arg2])
            || $block->constants[$op->arg2]->is(VM\Variable::TYPE_NULL)
        ) {
            $vm = new VM($this->context->runtime->vmContext);
            $className = $this->context->type->object->classNameForId($classId);
            $rootBlock = $this->context->jitFunctionRootBlock ?? $this->context->jitEnclosingBlock;
            VM\ClassConstMaterializer::seedReferencedClasses($vm, $rootBlock, $block, $op->arg2);
            $value = VM\ClassConstMaterializer::materializeSlot($vm, $block, $op->arg2, $className);
        } else {
            $value = $block->constants[$op->arg2];
        }
        if (null !== $op->arg3 && isset($block->constants[$op->arg3])) {
            $check = new VM\Variable();
            $check->copyFrom($value);
            $className = $this->context->type->object->classNameForId($classId);
            VM\TypeCheck::assertClassConstantTypedValue(
                $check,
                $block->constants[$op->arg3],
                $constDisplayName ?? $constNameLc,
                '' !== $className ? $className : null
            );
            $value = $check;
        }

        return $value;
    }

    /**
     * Compile DECLARE_ENUM ops in $block that have not been registered yet (#31967).
     */
    private function jitCompilePendingEnumsInBlock(Block $block): void
    {
        foreach ($block->opCodes as $op) {
            if (OpCode::TYPE_DECLARE_ENUM !== $op->type) {
                continue;
            }
            $nameOp = $block->getOperand($op->arg1);
            if (!$nameOp instanceof Operand\Literal) {
                continue;
            }
            if ($this->context->type->object->isRegisteredEnumLc(strtolower($nameOp->value))) {
                continue;
            }
            $this->jitCompileDeclareEnum($block, $op);
        }
    }

    private function jitCompileDeclareEnum(Block $block, OpCode $op): void
    {
        $nameOp = $this->jitResolveClassLikeDeclareNameOperand($block, $op);
        if (null === $nameOp) {
            return;
        }
        if ($this->emitDuplicateClassLikeDeclareFatalIfNeeded($op, $block, 'enum', $nameOp->value)) {
            return;
        }
        if ([] !== $op->classImplements) {
            JIT\ImplementsHierarchyJitGuard::emitBeforeDeclare(
                $this->context,
                $nameOp->value,
                $op->classImplements,
                $block->scriptPath(),
                $op->sourceLocation,
                null,
                true
            );
        }
        $this->context->pushScope();
        $this->context->scope->classId = $this->context->type->object->declareEnum($nameOp);
        $this->context->type->object->setClassSourceLocation(
            $this->context->scope->classId,
            $op->sourceLocation
        );
        $this->context->scope->className = strtolower($nameOp->value);
        if (AttributeClassRegistry::isRegisteredAttributeClass($op->attributeEntries)) {
            $this->context->type->object->markAttributeClass($nameOp->value);
        }
        if (null !== $op->arg2 && isset($block->constants[$op->arg2])) {
            $this->context->type->object->setEnumBackedType(
                $this->context->scope->classId,
                $block->constants[$op->arg2]->toString()
            );
        }
        if (null !== $this->context->runtime->vmContext) {
            $this->context->runtime->vmContext->enums[strtolower($nameOp->value)] = true;
        }
        $this->compileClass($op->block1, $this->context->scope->classId);
        if ([] !== $op->classImplements) {
            $this->context->type->object->setClassInterfaces(
                $nameOp->value,
                $op->classImplements
            );
            $this->seedVmClassEntryInterfaces($nameOp->value, $op->classImplements);
        }
        $this->context->type->object->inheritInterfaceConstants(
            $this->context->scope->classId,
            $nameOp->value
        );
        $this->context->type->object->finishEnumClass($this->context->scope->classId);
        $this->seedVmEnumForCompileTimeFolds($nameOp->value, $op, $this->context->scope->classId);
        $this->context->popScope();
    }

    /**
     * Register enum methods/interfaces on vmContext for compile-time json_encode folds (#6880).
     *
     * MODE_AOT skips VM DECLARE_CLASS, so JsonSerializable::jsonSerialize is otherwise
     * unreachable during {@see JitJsonEncode::tryFoldEnumCase}.
     */
    private function seedVmEnumForCompileTimeFolds(string $enumName, OpCode $op, int $classId): void
    {
        $vmContext = $this->context->runtime->vmContext ?? null;
        if (null === $vmContext || null === $op->block1) {
            return;
        }
        $lc = strtolower(ltrim($enumName, '\\'));
        if (!isset($vmContext->classes[$lc])) {
            $vmContext->classes[$lc] = new VM\ClassEntry(ltrim($enumName, '\\'));
        }
        $entry = $vmContext->classes[$lc];
        $entry->isEnum = true;
        $entry->backedType = $this->context->type->object->enumBackedTypeFor($classId);
        if ([] !== $op->classImplements) {
            $entry->interfaces = $op->classImplements;
        }
        VM\EnumSupport::ensureBuiltinEnumInterfaces($entry);
        $bodyBlock = $op->block1;
        $frame = $bodyBlock->getFrame($vmContext);
        foreach ($bodyBlock->opCodes as $methodOp) {
            if (OpCode::TYPE_DECLARE_METHOD !== $methodOp->type || null === $methodOp->block1) {
                continue;
            }
            $methodName = strtolower($frame->scope[$methodOp->arg1]->toString());
            if (isset($entry->methods[$methodName])) {
                continue;
            }
            $method = new Func\PHP($entry->name.'::'.$methodName, $methodOp->block1);
            $entry->methods[$methodName] = $method;
        }
    }

    /**
     * Replace hoisted null placeholders with enum-case singletons once the enum exists (#31967).
     *
     * php-cfg folds `C::K` / `E::X` into Block::$constants (vm type 9) and drops the
     * CLASS_CONST_FETCH opcode, so makeVariableFromOp must rematerialize after DECLARE_ENUM.
     */
    private function rebindEnumCaseConstantSlots(Block $block, OpCode $op): void
    {
        foreach ([$op->arg1, $op->arg2, $op->arg3] as $slot) {
            if (null === $slot || !isset($block->constants[$slot])) {
                continue;
            }
            $vm = $block->constants[$slot];
            if (VM\Variable::TYPE_ENUM_CASE !== $vm->type) {
                continue;
            }
            $operand = $block->getOperand((int) $slot);
            if (null === $operand) {
                continue;
            }
            try {
                $this->context->scope->variables[$operand] = JIT\VmConstantJit::toVariable($this->context, $vm);
            } catch (\LogicException) {
                continue;
            }
        }
    }

    /**
     * Attach implements[] on the VM ClassEntry before compiling class-const expressions (#31967).
     *
     * @param list<string> $implements
     */
    private function seedVmClassEntryInterfaces(string $className, array $implements): void
    {
        $vmContext = $this->context->runtime->vmContext ?? null;
        if (null === $vmContext) {
            return;
        }
        $lc = strtolower(ltrim($className, '\\'));
        if (!isset($vmContext->classes[$lc])) {
            $vmContext->classes[$lc] = new VM\ClassEntry($className);
        }
        $vmContext->classes[$lc]->interfaces = $implements;
    }

    private function jitVariableFromVmConstant(VM\Variable $vm): Variable {
        return JIT\VmConstantJit::toVariable($this->context, $vm);
    }

    private function jitNullVariable(): Variable
    {
        $slot = JIT\JitValueBox::alloc($this->context);
        $this->context->builder->call(
            $this->context->lookupFunction('__value__writeNull'),
            JIT\JitValueBox::pointer($this->context, $slot)
        );

        return new Variable(
            $this->context,
            Variable::TYPE_VALUE,
            Variable::KIND_VARIABLE,
            $slot
        );
    }

    private function jitVariableFromVmArray(VM\Variable $vm): Variable
    {
        return JIT\VmConstantJit::toVariable($this->context, $vm);
    }

    private function ternaryEchoPhiPropertyFetchDest(Block $block, int $fetchIndex): ?Operand
    {
        $fetch = $block->opCodes[$fetchIndex];
        if (OpCode::TYPE_PROPERTY_FETCH !== $fetch->type && OpCode::TYPE_PROPERTY_FETCH_WRITE !== $fetch->type) {
            return null;
        }
        $fetchResultSlot = (int) $fetch->arg1;
        $next = $block->opCodes[$fetchIndex + 1] ?? null;
        if (null === $next || OpCode::TYPE_ASSIGN !== $next->type) {
            return null;
        }
        if ((int) $next->arg2 !== $fetchResultSlot || (int) $next->arg3 !== $fetchResultSlot) {
            return null;
        }
        $dest = $block->getOperand($next->arg1);
        if (!$this->context->coalesceAssignTargets->contains($dest)) {
            return null;
        }

        return $dest;
    }

    /**
     * After ??= arms persist the store, drop fetch-arm property SSA so the merge
     * block (and nested outer ??) load the stack box (#33760 / #32988).
     *
     * ASSIGN_REF aliases (`$r =& $obj->prop; $r ??= …`) already have a dominating
     * GEP from the ref bind — stripping here makes the right-arm store a local-box
     * no-op (leftover of #35898 / #33748).
     */
    private function reseatCoalesceResultAfterPropertyArms(Operand $coalesceResult): void
    {
        $this->ensureCoalesceMergeStackSlot($coalesceResult);
        if (!$this->context->hasVariableOp($coalesceResult)) {
            return;
        }
        $mergeSeat = $this->context->getVariableFromOp($coalesceResult);
        $namedRefAlias = $mergeSeat->assignRefLvalueAlias
            && null !== JIT\OperandName::resolve($coalesceResult)
            && '' !== (string) JIT\OperandName::resolve($coalesceResult);
        if ($namedRefAlias) {
            return;
        }
        $mergeSeat->objectPropertySlot = null;
        $mergeSeat->objectPropertyType = null;
        $mergeSeat->objectPropertyReceiver = null;
        $mergeSeat->objectPropertyName = null;
        $mergeSeat->objectPropertyClassName = null;
        $mergeSeat->objectPropertyDnfArms = null;
        $mergeSeat->staticPropertyGlobal = null;
        $mergeSeat->staticPropertyType = null;
    }

    private function stampPropertyFetchReceiverOp(Variable $fetched, Operand $receiverOp): void
    {
        $fetched->objectPropertyReceiverOp = $receiverOp;
        if (
            'DOMNodeList' !== ($fetched->classUserType ?? '')
            || null !== ($fetched->compileTimeDomNodeListLength ?? null)
            || !$this->context->hasVariableOp($receiverOp)
        ) {
            return;
        }
        $receiverVar = $this->context->getVariableFromOp($receiverOp);
        $local = $receiverVar->compileTimeDomAttrLocalName ?? null;
        if (null === $local) {
            return;
        }
        $valueLit = $this->context->extensionLowering->domCompileTime?->compileTimeAttrValuePublic(
            $receiverVar->compileTimeDomAttrNamespace ?? '',
            $local
        );
        if (null !== $valueLit) {
            $fetched->compileTimeDomNodeListLength = '' !== $valueLit ? 1 : 0;
        }
    }

    private function releaseCoalesceMergeSlotMapping(Block $block, Operand $coalesceResult): void
    {
        $mergeSlot = $block->slotForOperand($coalesceResult);
        if (null !== $mergeSlot) {
            unset($this->context->coalesceMergeSlotOperands[$mergeSlot]);
        }
    }

    /**
     * Copy a native declared property read into a stack slot — loop JUMPIF must not
     * compare through a live objectPropertySlot or boxed __value__ alias (#36018).
     */
    private function snapshotNativeScalarPropertyRead(Variable $fetched, int $propType): Variable
    {
        $loaded = $this->context->helper->loadValue($fetched);
        $tyName = match ($propType) {
            Variable::TYPE_NATIVE_BOOL => 'int1',
            Variable::TYPE_NATIVE_DOUBLE => 'double',
            default => 'int64',
        };
        $ty = $this->context->getTypeFromString($tyName);
        $slot = JIT\BasicBlockHelper::entryAlloca($this->context, $ty);
        $this->context->builder->store($loaded, $slot);
        $snap = new Variable(
            $this->context,
            $propType,
            Variable::KIND_VARIABLE,
            $slot
        );
        $snap->addref();
        $snap->compileTimeLong = null;
        $snap->compileTimeFloat = null;
        if (null !== $fetched->compileTimeDomNodeListLength) {
            $snap->compileTimeDomNodeListLength = $fetched->compileTimeDomNodeListLength;
        }

        return $snap;
    }

    /**
     * `$len = $n->length` in user functions must bind native i64 like `(int)$n->length` (#36018).
     */
    private function coerceNamedLocalNativeLongPropertyAssign(Operand $resultOp, Variable $value): Variable
    {
        $name = JIT\OperandName::resolve($resultOp);
        if (null === $name || '' === $name) {
            return $value;
        }
        $block = $this->context->jitEnclosingBlock;
        if (null === $block || null === $block->func || $block->isMainScript()) {
            return $value;
        }
        if (Variable::TYPE_VALUE !== $value->type || !JIT\JitValueBox::isValueOperand($value)) {
            return $value;
        }
        $isNativeLongProp = Variable::TYPE_NATIVE_LONG === ($value->objectPropertyType ?? null);
        $isDomNodeListLen = null !== ($value->compileTimeDomNodeListLength ?? null);
        if (!$isNativeLongProp && !$isDomNodeListLen) {
            return $value;
        }
        $long = ext\standard\JitZendScalarCast::emitIntCast($this->context, $value);
        $i64 = $this->context->getTypeFromString('int64');
        $slot = JIT\BasicBlockHelper::entryAlloca($this->context, $i64);
        $this->context->builder->store($long, $slot);
        $native = new Variable(
            $this->context,
            Variable::TYPE_NATIVE_LONG,
            Variable::KIND_VARIABLE,
            $slot
        );
        $native->addref();
        $native->compileTimeLong = null;
        if ($isDomNodeListLen) {
            $native->compileTimeDomNodeListLength = $value->compileTimeDomNodeListLength;
        }

        return $native;
    }

    /**
     * Read fetches keep objectPropertySlot on branch-local SSA; ARG_SEND / var_dump load it later
     * from a block where the GEP does not dominate (#33760, peer #32988).
     */
    /**
     * Bind a property fetch result. Write / dim-write keep the live slot (no value-box reseat)
     * so `$r =& $o->p[$k]; $s =& $o->p[$k]` share one HT (#35980).
     *
     * @see php-src Zend/zend_object_handlers.c zend_std_get_property_ptr_ptr
     */
    private function bindPropertyFetchResult(Operand $result, Variable $fetched, bool $forWrite): void
    {
        if ($forWrite) {
            $this->context->scope->variables[$result] = $fetched;
            $this->context->setVariableOp($result, $fetched);

            return;
        }
        $boxed = $this->reseatPropertyFetchReadIntoValueBox($fetched);
        $this->context->scope->variables[$result] = $boxed;
        $this->applyTypedPropertyFetchResultType($result, $boxed);
    }

    private function reseatPropertyFetchReadIntoValueBox(Variable $fetched): Variable
    {
        if (null === $fetched->objectPropertySlot) {
            return $fetched;
        }
        $propType = $fetched->objectPropertyType ?? $fetched->type;
        // Native scalar declared properties (e.g. DOMNodeList::$length) must not stay
        // live-slot aliased or __value__-boxed — loop `$i < $len` needs snapshot i64 (#36018).
        if (\in_array($propType, [
            Variable::TYPE_NATIVE_LONG,
            Variable::TYPE_NATIVE_BOOL,
            Variable::TYPE_NATIVE_DOUBLE,
        ], true)) {
            return $this->snapshotNativeScalarPropertyRead($fetched, $propType);
        }
        $slot = JIT\JitValueBox::alloc($this->context);
        JIT\Builtin\Type\ObjectInstancePropertyLlvm::boxFetchedPropertyIntoValue(
            $this->context->type->object,
            $slot,
            $fetched,
            $propType
        );
        $boxed = new Variable(
            $this->context,
            Variable::TYPE_VALUE,
            Variable::KIND_VARIABLE,
            $slot
        );
        $boxed->compileTimeString = $fetched->compileTimeString;
        // Runtime boxFetchedPropertyIntoValue is authoritative — fetch-temp compileTimeLong
        // can be stale/wrong and made `$obj->n ** 2` / `$t = $obj->n; $t ** 2` fold 1 (#35978).
        $boxed->isNullConstant = $fetched->isNullConstant;
        // Keep typed-prop identity for BP_VAR_R guards (echo/loadValue). Stripping these
        // made unset string props echo garbage instead of Error (#33886 / re-#33007);
        // isset/?? use loadValueQuietForIsset and stay silent (#29688).
        $this->copyObjectPropertyBacking($boxed, $fetched);
        // documentElement/firstChild temps lose loadXML stamps when boxed — C14N fold and
        // appendChild refreshCompileTimeXmlWithRootInner need compileTimeDomLoadXml (#32978).
        $this->syncCompileTimeDomTagName($boxed, $fetched, true);
        if (null !== $fetched->classUserType) {
            $boxed->classUserType = $fetched->classUserType;
        }
        if (null !== $fetched->compileTimeDomNodeListLength) {
            $boxed->compileTimeDomNodeListLength = $fetched->compileTimeDomNodeListLength;
        }
        if (null !== $fetched->compileTimeDomAttrLocalName) {
            $boxed->compileTimeDomAttrLocalName = $fetched->compileTimeDomAttrLocalName;
            $boxed->compileTimeDomAttrNamespace = $fetched->compileTimeDomAttrNamespace ?? '';
        }
        $constraintUserType = $this->typedPropertyClassConstraintUserType($fetched);
        if (null !== $constraintUserType) {
            $boxed->classUserType = $constraintUserType;
        }
        if (null !== $fetched->compileTimeDateTimeTimestamp) {
            $boxed->compileTimeDateTimeTimestamp = $fetched->compileTimeDateTimeTimestamp;
            $boxed->compileTimeDateTimeMicrosecond = $fetched->compileTimeDateTimeMicrosecond;
            $boxed->compileTimeTimezoneName = $fetched->compileTimeTimezoneName;
            $boxed->compileTimeDateTimeClassName = $fetched->compileTimeDateTimeClassName;
        } elseif (null !== $constraintUserType) {
            $dateLc = strtolower(ltrim($constraintUserType, '\\'));
            if (\in_array($dateLc, ['datetime', 'datetimeimmutable'], true)) {
                $boxed->compileTimeDateTimeClassName = 'datetimeimmutable' === $dateLc
                    ? 'DateTimeImmutable'
                    : 'DateTime';
            }
        }

        return $boxed;
    }

    /**
     * Typed property fetch results carry objectPropertyClassConstraint, not CFG userType.
     * Without this, `$sub->dt->format()` in global scope resolves Sub::format (#35752).
     */
    private function typedPropertyClassConstraintUserType(JIT\Variable $var): ?string
    {
        $constraint = $var->objectPropertyClassConstraint ?? null;
        if (!\is_string($constraint) || '' === $constraint) {
            return null;
        }
        $lc = strtolower(ltrim($constraint, '\\'));
        if (\in_array($lc, [
            'int', 'float', 'string', 'bool', 'array', 'object', 'mixed', 'null',
            'callable', 'iterable', 'resource', 'void', 'never', 'false', 'true', 'null',
        ], true)) {
            return null;
        }

        return ltrim($constraint, '\\');
    }

    private function applyTypedPropertyFetchResultType(Operand $result, JIT\Variable $var): void
    {
        $userType = $this->typedPropertyClassConstraintUserType($var);
        if (null === $userType) {
            return;
        }
        $var->classUserType = $userType;
        $result->type = Type::object($userType);
    }

    /**
     * ?? / ??= fetch arms bind objectPropertySlot in branch-only SSA. Later PROPERTY_FETCH or
     * ARG_SEND that reuses the scope Variable loads a GEP that does not dominate — e.g. second
     * `$a->p ??= $b->q ??=` then var_dump($a->p, $b->q) (#33760, peer #32988).
     */
    private function clearCoalesceFetchArmPropertySlotsInScope(): void
    {
        foreach ($this->context->scope->variables as $op) {
            if ($this->context->coalesceAssignTargets->contains($op)) {
                continue;
            }
            $var = $this->context->scope->variables[$op];
            if (null === $var->objectPropertySlot) {
                continue;
            }
            $var->objectPropertySlot = null;
            $var->objectPropertyType = null;
            $var->objectPropertyReceiver = null;
            $var->objectPropertyName = null;
            $var->objectPropertyClassName = null;
            $var->objectPropertyDnfArms = null;
        }
    }

    /**
     * ??= merge temps are stack slots. Class::$prop fetch binds KIND_VALUE plus
     * staticPropertyGlobal; promoting first drops that lvalue so the store never
     * reaches the module global and AOT readback stays NULL (#32035, #20877).
     * Instance `$o->p ??=` is the same shape with objectPropertySlot (#33748 / re-#32880).
     */
    private function persistPropertyBeforeCoalesceMergePromote(Operand $coalesceTarget, Variable $value): void
    {
        if (!$this->context->hasVariableOp($coalesceTarget)) {
            return;
        }
        $dest = $this->context->getVariableFromOp($coalesceTarget);
        if (
            (null === $dest->staticPropertyGlobal || null === $dest->staticPropertyType)
            && (null === $dest->objectPropertySlot || null === $dest->objectPropertyType)
        ) {
            return;
        }
        $this->assignOperand($coalesceTarget, $value, false);
    }

    /**
     * ?: / ?? merge ASSIGN dest — match coalesceAssignTargets or the stack-phi slot map.
     *
     * php-cfg reuses slot numbers across JUMPIF arms but not always the same Operand
     * instance; the else arm alias temp must resolve via coalesceMergeSlotOperands (#34956).
     */
    private function bindCoalesceMergeSlotVariable(Block $block, int $slot, Variable $var): void
    {
        if (isset($this->context->coalesceMergeSlotOperands[$slot])) {
            $this->context->setVariableOp($this->context->coalesceMergeSlotOperands[$slot], $var);
        }
        $aliasOp = $block->getOperand($slot);
        if ($aliasOp instanceof Operand) {
            $this->context->setVariableOp($aliasOp, $var);
        }
        foreach ($this->context->scope->variables as $scopeOp) {
            if (!$scopeOp instanceof Operand) {
                continue;
            }
            if ($block->slotForOperand($scopeOp) !== $slot) {
                continue;
            }
            $this->context->setVariableOp($scopeOp, $var);
        }
    }

    private function resolveCoalesceMergeAssignTarget(
        ?Operand $destOp,
        ?Operand $aliasOp,
        Block $block
    ): ?Operand {
        if (null !== $destOp && $this->context->coalesceAssignTargets->contains($destOp)) {
            return $destOp;
        }
        if (null !== $aliasOp && $this->context->coalesceAssignTargets->contains($aliasOp)) {
            return $aliasOp;
        }
        foreach ([$aliasOp, $destOp] as $op) {
            if (null === $op) {
                continue;
            }
            $slot = $block->slotForOperand($op);
            if (null !== $slot && isset($this->context->coalesceMergeSlotOperands[$slot])) {
                return $this->context->coalesceMergeSlotOperands[$slot];
            }
        }

        return null;
    }

    private function ensureCoalesceMergeStackSlot(Operand $mergeOp): void
    {
        if ($this->context->hasVariableOp($mergeOp)) {
            $var = $this->context->getVariableFromOp($mergeOp);
            if (
                Variable::TYPE_VALUE === $var->type
                && Variable::KIND_VARIABLE === $var->kind
            ) {
                // Property-backed slots carry a fetch-arm-only SSA pointer (objectPropertySlot).
                // Keep the alloca (already written by the fetch/null arm) but drop the backing so
                // nullsafe_merge reads the stack box — otherwise Module verify fails (#32988).
                // `$r =& $obj->prop` GEP dominates both ??= arms; dropping it here leaves
                // `$r ??= n` as a local-box write (#35987 leftover of #35898).
                // Only named CVs: FETCH_OBJ_W temps for `$a->p ??= $b->q ??=` must still
                // drop arm-local GEPs (#33760 / #32988).
                $namedRefAlias = $var->assignRefLvalueAlias
                    && null !== JIT\OperandName::resolve($mergeOp)
                    && '' !== (string) JIT\OperandName::resolve($mergeOp);
                if (
                    !$namedRefAlias
                    && (
                        null !== $var->objectPropertySlot
                        || null !== $var->staticPropertyGlobal
                        || null !== $var->valueBoxAliasPtr
                        || $var->functionStaticGlobal
                    )
                ) {
                    $var->objectPropertySlot = null;
                    $var->objectPropertyType = null;
                    $var->objectPropertyReceiver = null;
                    $var->objectPropertyName = null;
                    $var->objectPropertyClassName = null;
                    $var->objectPropertyDnfArms = null;
                    $var->staticPropertyGlobal = null;
                    $var->staticPropertyType = null;
                    $var->valueBoxAliasPtr = null;
                    $var->functionStaticGlobal = false;
                }

                return;
            }
        }
        $slot = JIT\JitValueBox::alloc($this->context);
        $this->context->setVariableOp(
            $mergeOp,
            new Variable(
                $this->context,
                Variable::TYPE_VALUE,
                Variable::KIND_VARIABLE,
                $slot
            )
        );
    }

    /**
     * Merge-block ECHO may reference the ternary alias temp; redirect to the phi dest (#18052).
     */
    private function recordTernaryEchoPhiByAliasSlot(
        Block $block,
        OpCode $op,
        Operand $destOp,
        ?Operand $aliasOp,
        int $rhsSlot
    ): void {
        if (null === $aliasOp || $op->arg1 === $op->arg2) {
            return;
        }
        $aliasSlot = $block->slotForOperand($aliasOp);
        if (null === $aliasSlot) {
            return;
        }
        $phiOp = $this->context->coalesceAssignTargets->contains($destOp) || $op->arg2 === $rhsSlot
            ? $destOp
            : $block->getOperand($rhsSlot);
        $this->context->ternaryEchoPhiByAliasSlot[$aliasSlot] = $phiOp;
    }

    private function loadPropertyFetchReceiver(Operand $objOp): PHPLLVM\Value
    {
        $name = JIT\OperandName::resolve($objOp);
        if (null !== $name && '' !== $name) {
            $resolved = $this->context->resolveRefAliasName($name);
            if (isset($this->context->namedVariableBindings[$resolved])) {
                $bound = $this->context->namedVariableBindings[$resolved];
                if (Variable::TYPE_OBJECT === $bound->type) {
                    return $this->context->helper->loadValue($bound);
                }
            }
        }
        $var = $this->context->getVariableFromOpInScopes($objOp);
        if (Variable::TYPE_OBJECT === $var->type) {
            return $this->context->helper->loadValue($var);
        }
        if (Variable::TYPE_VALUE === $var->type) {
            return $this->context->builder->call(
                $this->context->lookupFunction('__value__readObject'),
                JIT\JitValueBox::valuePtrFromVariable($this->context, $var)
            );
        }

        throw new \LogicException(
            'Property fetch receiver must be object or object-valued property, got '
            .Variable::getStringType($var->type)
        );
    }

    private static function foreachContainerUserType(
        Operand $arrayOp,
        ?JIT\Variable $arrayVar = null
    ): ?string {
        $userType = $arrayOp->type->userType ?? null;
        if (null !== $userType && '' !== $userType) {
            return $userType;
        }
        if (null !== $arrayOp->type && Variable::TYPE_HASHTABLE === Variable::getTypeFromType($arrayOp->type)) {
            $decl = $arrayOp->type->userType ?? null;
            if (null !== $decl && 0 === strcasecmp($decl, 'SplObjectStorage')) {
                return 'SplObjectStorage';
            }
        }
        // Property fetches (childNodes) tag DOMNodeList on the JIT binding, not CFG userType (#33082).
        $tagged = $arrayVar->classUserType ?? null;
        if (null !== $tagged && '' !== $tagged) {
            return $tagged;
        }

        return null;
    }

    /**
     * Propagate compile-time callable names through TYPE_ASSIGN (first-class callables, #1363).
     */
    private function foldCompileTimeStringFromAssign(
        Block $block,
        int $sourceSlot,
        Variable $dest,
        Variable $source
    ): void {
        if (null !== $source->classUserType) {
            $dest->classUserType = $source->classUserType;
        }
        if (null !== $source->compileTimeDomNodeListLength) {
            $dest->compileTimeDomNodeListLength = $source->compileTimeDomNodeListLength;
        }
        if (null !== $source->serializePayloadClass) {
            $dest->serializePayloadClass = $source->serializePayloadClass;
        }
        if ($source->fromUnserializeObject) {
            $dest->fromUnserializeObject = true;
        }
        if (null !== $source->compileTimeString) {
            $dest->compileTimeString = $source->compileTimeString;

            return;
        }
        if (null !== $dest->compileTimeString) {
            // Catch/branch reassignment from a non-const RHS must drop stale init literals
            // (e.g. $error = '' then catch $error = 'msg' — call args kept strlen=0) (#32570).
            $dest->compileTimeString = null;
        }
        if (null !== $source->compileTimeDomTagName && null === $dest->compileTimeDomTagName) {
            $dest->compileTimeDomTagName = $source->compileTimeDomTagName;
        }
        if (null !== $source->compileTimeDomInnerXml && null === $dest->compileTimeDomInnerXml) {
            $dest->compileTimeDomInnerXml = $source->compileTimeDomInnerXml;
        }
        if (null !== $source->compileTimeDomInnerXmlParent && null === $dest->compileTimeDomInnerXmlParent) {
            $dest->compileTimeDomInnerXmlParent = $source->compileTimeDomInnerXmlParent;
        }
        if (null !== $source->compileTimeDomChildIndex && null === $dest->compileTimeDomChildIndex) {
            $dest->compileTimeDomChildIndex = $source->compileTimeDomChildIndex;
        }
        if (null !== $source->compileTimeDomNodePath && null === $dest->compileTimeDomNodePath) {
            $dest->compileTimeDomNodePath = $source->compileTimeDomNodePath;
        }
        if (null !== $source->compileTimeDomLineNo && null === $dest->compileTimeDomLineNo) {
            $dest->compileTimeDomLineNo = $source->compileTimeDomLineNo;
        }
        if (null !== $source->compileTimeDomTextData && null === $dest->compileTimeDomTextData) {
            $dest->compileTimeDomTextData = $source->compileTimeDomTextData;
        }
        if (null !== $source->compileTimeDomAttributes && null === $dest->compileTimeDomAttributes) {
            $dest->compileTimeDomAttributes = $source->compileTimeDomAttributes;
        }
        if (null !== $source->compileTimeDomGeiHtmlHit && null === $dest->compileTimeDomGeiHtmlHit) {
            $dest->compileTimeDomGeiHtmlHit = $source->compileTimeDomGeiHtmlHit;
        }
        if (null !== $source->compileTimeDomElementId && null === $dest->compileTimeDomElementId) {
            $dest->compileTimeDomElementId = $source->compileTimeDomElementId;
        }
        if (null !== $source->compileTimeDomLoadXml && null === $dest->compileTimeDomLoadXml) {
            $dest->compileTimeDomLoadXml = $source->compileTimeDomLoadXml;
        }
        if ($source->compileTimeDomHtmlLoaded && !$dest->compileTimeDomHtmlLoaded) {
            $dest->compileTimeDomHtmlLoaded = true;
        }
        if (null !== $source->compileTimeDomImportHostSxeToken && null === $dest->compileTimeDomImportHostSxeToken) {
            $dest->compileTimeDomImportHostSxeToken = $source->compileTimeDomImportHostSxeToken;
        }
        $this->foldCompileTimeStringFromSlot($block, $sourceSlot, $dest);
    }

    private function foldCompileTimeStringFromSlot(Block $block, int $slot, Variable $dest): void
    {
        if (null !== $dest->compileTimeString) {
            return;
        }
        $scopeName = JIT\OperandName::resolve($block->operandForScopeSlot($slot) ?? $block->getOperand($slot));
        if (null === $scopeName || '' === $scopeName) {
            foreach ($block->eachNamedScopeSlot() as [$name, $scopeSlot]) {
                if ($scopeSlot === $slot) {
                    $scopeName = $name;
                    break;
                }
            }
        }
        if (null !== $scopeName && '' !== $scopeName) {
            if ($this->jitNamedLocalHasDivergentBranchCompileTimeStrings($block, $scopeName)) {
                return;
            }
            $resolved = $this->jitEffectiveNamedLocalCompileTimeString($block, $scopeName);
        } else {
            $resolved = $this->resolveJitCompileTimeStringSlot($block, $slot);
        }
        if (null !== $resolved) {
            $dest->compileTimeString = $resolved;
        }
    }

    /**
     * Named CVs keep firstChild/lastChild open-tag stamps; ARG_SEND temps often
     * reuse lastFetched* from a later nested walk (#21644 / #34050).
     *
     * @param list<Operand|null> $operands
     * @param list<Variable>     $args
     */
    private function promoteCompileTimeDomOnCallArgs(Block $block, array $operands, array $args): void
    {
        foreach ($args as $i => $arg) {
            if (!$arg instanceof Variable) {
                continue;
            }
            $operand = $operands[$i] ?? null;
            if (!$operand instanceof \PHPCfg\Operand) {
                continue;
            }
            $scopeName = JIT\OperandName::resolve($operand);
            if (null === $scopeName || '' === $scopeName) {
                continue;
            }
            $resolved = $this->context->resolveRefAliasName($scopeName);
            $bound = $this->context->namedVariableBindings[$resolved] ?? null;
            if (!$bound instanceof Variable || $bound === $arg) {
                continue;
            }
            // Promote on ElementId / tagName too — replaceChild clears the attrs bag so
            // later setAttribute does not shadow CreateElementAttrs, but the ARG_SEND temp
            // still needs ElementId or lastId() points at a newer createElement (#35386).
            if (
                (null === $bound->compileTimeDomAttributes || [] === $bound->compileTimeDomAttributes)
                && null === $bound->compileTimeDomElementId
                && (null === $bound->compileTimeDomTagName || '' === $bound->compileTimeDomTagName)
            ) {
                continue;
            }
            $this->syncCompileTimeDomTagName($arg, $bound, true);
            if (null !== $bound->classUserType && '' !== $bound->classUserType) {
                $arg->classUserType = $bound->classUserType;
            }
        }
    }

    /**
     * @param list<Operand|null> $operands
     * @param list<Variable> $args
     */
    /**
     * Echo prefers native {@see __string__*} allocas over empty {main} script-global boxes
     * (#36366). Builtin call args must match or strlen/htmlspecialchars read stale sidecars.
     */
    private function preferNativeStringBindingForCallArg(string $scopeName, Variable $arg): Variable
    {
        $resolved = $this->context->resolveRefAliasName($scopeName);
        $bound = $this->context->namedVariableBindings[$resolved] ?? null;
        if (
            $bound instanceof Variable
            && $bound !== $arg
            && Variable::KIND_VARIABLE === $bound->kind
            && Variable::TYPE_STRING === $bound->type
            && (
                Variable::TYPE_VALUE === $arg->type
                || $arg->functionStaticGlobal
                || Variable::TYPE_STRING !== $arg->type
            )
        ) {
            return $bound;
        }

        return $arg;
    }

    private function promoteCompileTimeStringOnCallArgs(Block $block, array $operands, array $args): void
    {
        foreach ($args as $i => $arg) {
            $operand = $operands[$i] ?? null;
            if (!$operand instanceof \PHPCfg\Operand) {
                continue;
            }
            $slot = $block->slotForOperand($operand);
            if (null === $slot) {
                continue;
            }
            // Named string locals (native or boxed): always re-resolve — php-cfg uses distinct
            // operands per block for one CV and init compileTimeString ('' before try) survives
            // on catch reassignment (#32496, #32570, htmlspecialchars #32636 / ThrowsWeb #2076).
            if (
                (Variable::TYPE_STRING === $arg->type || Variable::TYPE_VALUE === $arg->type)
                && !$operand instanceof Operand\Literal
            ) {
                $scopeName = JIT\OperandName::resolve($operand);
                if (null !== $scopeName && '' !== $scopeName) {
                    $args[$i] = $this->preferNativeStringBindingForCallArg($scopeName, $arg);
                    $arg = $args[$i];
                    if ($this->jitNamedLocalHasDivergentBranchCompileTimeStrings($block, $scopeName)
                        || $this->jitNamedLocalScopeHasConcatMutation($block, $scopeName)) {
                        $arg->compileTimeString = null;
                    } else {
                        $effective = $this->jitEffectiveNamedLocalCompileTimeString(
                            $block,
                            $scopeName
                        );
                        if (null !== $effective) {
                            if (
                                null !== $arg->compileTimeString
                                && $arg->compileTimeString !== $effective
                            ) {
                                // `.=` / loop back-edges stamp a longer runtime string on the
                                // JIT Variable than init-literal back-walk finds (#36244).
                                $arg->compileTimeString = null;
                            } else {
                                $arg->compileTimeString = $effective;
                            }
                        } elseif (null !== $arg->compileTimeString) {
                            // Loop/branch merge could not prove a literal — stale init '' must
                            // not fold strlen/htmlspecialchars (#36406).
                            $arg->compileTimeString = null;
                        }
                    }

                    continue;
                }
            }
            if (null !== $arg->compileTimeString) {
                if ($operand instanceof Operand\Literal) {
                    continue;
                }
                // Catch/branch reassignment: stale try-path '' on boxed locals (#32570).
                // Divergence must be checked before resolve — merge blocks return null from
                // resolveJitCompileTimeStringSlot when try/catch arms disagree, which skipped
                // the old guard and left strlen/htmlspecialchars folding to '' (#32636).
                $divergent = false;
                foreach ($block->eachNamedScopeSlot() as [$scopeName, $scopeSlot]) {
                    if ($scopeSlot === $slot
                        && $this->jitNamedLocalHasDivergentBranchCompileTimeStrings($block, $scopeName)
                    ) {
                        $divergent = true;
                        break;
                    }
                }
                if ($divergent) {
                    $arg->compileTimeString = null;
                    continue;
                }
                $resolved = $this->resolveJitCompileTimeStringSlot($block, $slot);
                // Do not wipe a good stamp with null (misaligned argOperands used to do this) (#35234).
                if (null !== $resolved) {
                    $arg->compileTimeString = $resolved;
                }

                continue;
            }
            $this->foldCompileTimeStringFromSlot($block, $slot, $arg);
        }
    }

    /**
     * @param array<int, true> $visited
     */
    private function resolveJitCompileTimeStringSlot(Block $block, int $slot, array &$visited = []): ?string
    {
        if (isset($visited[$slot])) {
            return null;
        }
        $visited[$slot] = true;
        if (isset($block->constants[$slot])) {
            $const = $block->constants[$slot];
            if (VM\Variable::TYPE_STRING !== $const->type) {
                return null;
            }

            return $const->toString();
        }
        foreach ($block->opCodes as $prior) {
            if (OpCode::TYPE_CLASS_CONST_FETCH === $prior->type && $prior->arg1 === $slot) {
                $classOp = $block->getOperand($prior->arg2);
                $nameOp = $block->getOperand($prior->arg3);
                if (
                    $classOp instanceof Operand\Literal
                    && $nameOp instanceof Operand\Literal
                    && 'class' === strtolower($nameOp->value)
                ) {
                    return $this->resolveClassNameForPseudoConst($block, $classOp);
                }
            }
            if (OpCode::TYPE_CONCAT === $prior->type && $prior->arg1 === $slot) {
                $left = $this->resolveJitCompileTimeStringSlot($block, (int) $prior->arg2, $visited);
                $right = $this->resolveJitCompileTimeStringSlot($block, (int) $prior->arg3, $visited);
                if (null !== $left && null !== $right) {
                    return $left.$right;
                }
            }
            if (OpCode::TYPE_ASSIGN !== $prior->type) {
                continue;
            }
            if (!\in_array($prior->arg2, $this->jitNamedScopeSlotAliases($block, $slot), true)) {
                continue;
            }
            $resolved = $this->resolveJitCompileTimeStringSlot($block, (int) $prior->arg3, $visited);
            if (null !== $resolved) {
                return $resolved;
            }
        }

        if (\count($block->parents) > 1) {
            // Try/catch (and ?: / foreach) merge blocks: all incoming paths must agree.
            // Picking the first parent folded catch assigns to the try-path literal (e.g.
            // $error = "" before try, "msg" in catch → strlen($error) became 0) (#32570).
            $agreed = null;
            foreach ($block->parents as $parent) {
                if (!$parent instanceof Block) {
                    return null;
                }
                // Fresh visited per parent — shared $visited marks the slot seen on parent
                // one and makes parent two return null before walking (#32496 openssl PEM).
                $branchVisited = [];
                $resolved = $this->resolveJitCompileTimeStringSlot($parent, $slot, $branchVisited);
                if (null === $resolved) {
                    return null;
                }
                if (null === $agreed) {
                    $agreed = $resolved;
                } elseif ($agreed !== $resolved) {
                    return null;
                }
            }

            return $agreed;
        }

        foreach ($block->parents as $parent) {
            if (!$parent instanceof Block) {
                continue;
            }
            $resolved = $this->resolveJitCompileTimeStringSlot($parent, $slot, $visited);
            if (null !== $resolved) {
                return $resolved;
            }
        }

        return $this->jitCompileTimeStringFromNamedBindingIfStable($block, $slot);
    }

    /**
     * When {@see Block::$parents} is empty on forward-only CFG edges, slot back-edges
     * cannot reach the ASSIGN — fall back to named bindings unless try/catch (or ?:)
     * branches disagree (#32496 vs #32570).
     */
    private function jitCompileTimeStringFromNamedBindingIfStable(Block $block, int $slot): ?string
    {
        $name = null;
        foreach ($block->eachNamedScopeSlot() as [$scopeName, $scopeSlot]) {
            if ($scopeSlot === $slot) {
                $name = $scopeName;
                break;
            }
        }
        if (null === $name || '' === $name) {
            return null;
        }
        if ($this->jitNamedLocalHasDivergentBranchCompileTimeStrings($block, $name)) {
            return null;
        }
        $bound = $this->context->namedVariableBindings[
            $this->context->resolveRefAliasName($name)
        ] ?? null;
        if (null === $bound || null === $bound->compileTimeString) {
            return null;
        }

        return $bound->compileTimeString;
    }

    /**
     * True when $name was mutated via CONCAT on any block reachable walking parents.
     * Init-literal back-walk cannot soundly describe loop-carried `.=` growth (#36406).
     *
     * @param array<int, true> $visited
     */
    private function jitNamedLocalScopeHasConcatMutation(
        Block $block,
        string $name,
        array &$visited = []
    ): bool {
        $id = spl_object_id($block);
        if (isset($visited[$id])) {
            return false;
        }
        $visited[$id] = true;
        $slot = null;
        foreach ($block->eachNamedScopeSlot() as [$scopeName, $scopeSlot]) {
            if ($scopeName === $name) {
                $slot = $scopeSlot;
                break;
            }
        }
        if (null !== $slot) {
            $aliases = $this->jitNamedScopeSlotAliases($block, $slot);
            foreach ($block->opCodes as $prior) {
                if (
                    OpCode::TYPE_CONCAT === $prior->type
                    && \in_array($prior->arg1, $aliases, true)
                ) {
                    return true;
                }
            }
        }
        foreach ($block->parents as $parent) {
            if (!$parent instanceof Block) {
                continue;
            }
            if ($this->jitNamedLocalScopeHasConcatMutation($parent, $name, $visited)) {
                return true;
            }
        }

        return false;
    }

    /**
     * True when incoming CFG arms assign different compile-time strings to $name
     * (try $error="" vs catch $error="msg" — #32570).
     */
    private function jitNamedLocalHasDivergentBranchCompileTimeStrings(
        Block $block,
        string $name,
        array &$visited = []
    ): bool {
        $id = spl_object_id($block);
        if (isset($visited[$id])) {
            return false;
        }
        $visited[$id] = true;

        if (\count($block->parents) > 1) {
            $agreed = null;
            $sawNull = false;
            foreach ($block->parents as $parent) {
                if (!$parent instanceof Block) {
                    return true;
                }
                $resolved = $this->jitEffectiveNamedLocalCompileTimeString($parent, $name);
                if (null === $resolved) {
                    $sawNull = true;
                    continue;
                }
                if (null === $agreed) {
                    $agreed = $resolved;
                } elseif ($agreed !== $resolved) {
                    return true;
                }
            }
            // Loop header: entry literal vs null back-edge must not fold (#36244).
            if ($sawNull && null !== $agreed) {
                return true;
            }

            return false;
        }

        // If/catch arms are single-parent blocks; divergence lives at an ancestor
        // merge (try $error="" vs catch $error="msg") (#32570, ThrowsWeb #2076).
        foreach ($block->parents as $parent) {
            if (!$parent instanceof Block) {
                continue;
            }
            if ($this->jitNamedLocalHasDivergentBranchCompileTimeStrings($parent, $name, $visited)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Last in-block CONCAT on a named CV wins over earlier init ASSIGN (#36244).
     */
    private function jitNamedLocalCompileTimeStringInBlock(Block $block, int $slot): ?string
    {
        foreach (array_reverse($block->opCodes) as $prior) {
            if (OpCode::TYPE_CONCAT !== $prior->type || $prior->arg1 !== $slot) {
                continue;
            }
            $leftOp = $block->getOperand((int) $prior->arg2);
            $rightOp = $block->getOperand((int) $prior->arg3);
            if (
                !$this->context->hasVariableOp($leftOp)
                || !$this->context->hasVariableOp($rightOp)
            ) {
                return null;
            }
            $left = $this->context->getVariableFromOp($leftOp);
            $right = $this->context->getVariableFromOp($rightOp);
            if (
                null !== ($left->compileTimeString ?? null)
                && null !== ($right->compileTimeString ?? null)
            ) {
                return $left->compileTimeString.$right->compileTimeString;
            }

            return null;
        }

        return null;
    }

    private function jitEffectiveNamedLocalCompileTimeString(
        Block $block,
        string $name,
        array &$visited = []
    ): ?string {
        $id = spl_object_id($block);
        if (isset($visited[$id])) {
            return null;
        }
        $visited[$id] = true;
        $slot = null;
        foreach ($block->eachNamedScopeSlot() as [$scopeName, $scopeSlot]) {
            if ($scopeName === $name) {
                $slot = $scopeSlot;
                break;
            }
        }
        if (null !== $slot) {
            $inBlock = $this->jitNamedLocalCompileTimeStringInBlock($block, $slot);
            if (null !== $inBlock) {
                return $inBlock;
            }
            foreach ($block->opCodes as $prior) {
                if (OpCode::TYPE_ASSIGN !== $prior->type) {
                    continue;
                }
                if (!\in_array($prior->arg2, $this->jitNamedScopeSlotAliases($block, $slot), true)) {
                    continue;
                }
                $branchVisited = [];
                $rhs = $this->resolveJitCompileTimeStringSlot($block, (int) $prior->arg3, $branchVisited);
                if (null !== $rhs) {
                    return $rhs;
                }
            }
        } else {
            // php-cfg catch/try arms may omit the CV from eachNamedScopeSlot (#32570).
            foreach ($block->opCodes as $prior) {
                if (OpCode::TYPE_ASSIGN !== $prior->type) {
                    continue;
                }
                $destOp = $block->getOperand($prior->arg2);
                if (null === $destOp || JIT\OperandName::resolve($destOp) !== $name) {
                    continue;
                }
                $branchVisited = [];
                $rhs = $this->resolveJitCompileTimeStringSlot($block, (int) $prior->arg3, $branchVisited);
                if (null !== $rhs) {
                    return $rhs;
                }
            }
        }
        if (\count($block->parents) > 1) {
            // Loop headers and branch merges: all incoming paths must agree (#36244, #36406).
            // Returning the first parent alone folded strlen($s) to init '' after a `.=` loop.
            $agreed = null;
            foreach ($block->parents as $parent) {
                if (!$parent instanceof Block) {
                    return null;
                }
                // Copy-on-write branch visited — fresh [] re-enters loop headers forever (#36406).
                $branchVisited = $visited;
                $resolved = $this->jitEffectiveNamedLocalCompileTimeString($parent, $name, $branchVisited);
                if (null === $resolved) {
                    return null;
                }
                if (null === $agreed) {
                    $agreed = $resolved;
                } elseif ($agreed !== $resolved) {
                    return null;
                }
            }

            return $agreed;
        }

        foreach ($block->parents as $parent) {
            if (!$parent instanceof Block) {
                continue;
            }
            $resolved = $this->jitEffectiveNamedLocalCompileTimeString($parent, $name, $visited);
            if (null !== $resolved) {
                return $resolved;
            }
        }
        $bound = $this->context->namedVariableBindings[
            $this->context->resolveRefAliasName($name)
        ] ?? null;
        // Stale init '' on script-global boxes survives `.=` — never treat as proof (#36406).
        if (null !== $bound && null !== $bound->compileTimeString && '' !== $bound->compileTimeString) {
            return $bound->compileTimeString;
        }

        return null;
    }

    /**
     * php-cfg may bind distinct {@see Operand} objects to different scope slots for one CV
     * name (#72). Call sites can reference a different slot than the TYPE_ASSIGN dest
     * (openssl_x509_parse($pem) after `$pem = <<<'PEM'…` — #32496).
     *
     * @return list<int>
     */
    private function jitNamedScopeSlotAliases(Block $block, int $slot): array
    {
        $name = null;
        foreach ($block->eachNamedScopeSlot() as [$scopeName, $scopeSlot]) {
            if ($scopeSlot === $slot) {
                $name = $scopeName;
                break;
            }
        }
        if (null === $name || '' === $name) {
            return [$slot];
        }
        $aliases = [];
        foreach ($block->eachNamedScopeSlot() as [$scopeName, $scopeSlot]) {
            if ($scopeName === $name) {
                $aliases[] = $scopeSlot;
            }
        }

        return [] !== $aliases ? $aliases : [$slot];
    }

    /** Release boxed locals before user function return (Zend end of scope; #4096). */
    private function releaseJitFunctionLocalsAtReturn(Block $block): void
    {
        if (null === $block->func) {
            return;
        }
        $fnName = $block->func->name;
        if ('{main}' === $fnName || str_ends_with($fnName, '::__destruct')) {
            return;
        }
        $byRefParamNames = [];
        foreach ($block->paramByRef as $paramIdx => $_) {
            if (isset($block->paramNames[$paramIdx]) && '' !== $block->paramNames[$paramIdx]) {
                $byRefParamNames[$block->paramNames[$paramIdx]] = true;
            }
        }
        /** @var array<string, true> $released */
        $released = [];
        /** @var array<string, true> $localNames */
        $localNames = [];
        foreach ($this->jitFunctionNamedScopeSlots($block) as [$name, ,]) {
            if ('this' !== $name && '' !== $name) {
                $localNames[$name] = true;
            }
        }
        foreach ($this->jitFunctionAssignTargets($block) as $destOp) {
            $name = JIT\OperandName::resolve($destOp);
            if (null !== $name && '' !== $name) {
                $localNames[$name] = true;
            }
        }
        foreach ($block->orig->deadOperands ?? [] as $deadOp) {
            $name = JIT\OperandName::resolve($deadOp);
            if (null !== $name && '' !== $name) {
                $localNames[$name] = true;
            }
        }
        foreach (array_keys($localNames) as $name) {
            if (isset($released[$name])) {
                continue;
            }
            $resolved = $this->context->resolveRefAliasName($name);
            $var = $this->context->namedVariableBindings[$resolved] ?? null;
            if (null === $var) {
                continue;
            }
            $this->releaseJitCanonicalNamedLocalAtReturn(
                $name,
                $var,
                $byRefParamNames,
                $released
            );
        }
    }

    /**
     * @param array<string, true> $byRefParamNames
     * @param array<string, true> $released
     */
    private function releaseJitCanonicalNamedLocalAtReturn(
        string $name,
        Variable $var,
        array $byRefParamNames,
        array &$released
    ): void {
        if ('this' === $name || isset($released[$name])) {
            return;
        }
        if (isset($byRefParamNames[$name])) {
            return;
        }
        if (Variable::KIND_VARIABLE !== $var->kind) {
            return;
        }
        if ($var->borrowedValueEntry || null !== $var->valueBoxAliasPtr) {
            return;
        }
        if (Variable::TYPE_VALUE === $var->type) {
            $this->jitWriteNullForUnset(JIT\JitValueBox::valuePtrFromVariable($this->context, $var));
            $released[$name] = true;

            return;
        }
        // Native packed arrays (e.g. `string[1]`) still have IS_REFCOUNTED on the
        // element type. loadValue+delref would bitcast the array aggregate
        // (`[1 x %__string__*]`) to `__ref__virtual*` / i8* and fail module verify
        // (#36382 Slim/nyholm; php-src zend_array_destroy walks buckets).
        if (0 !== ($var->type & Variable::IS_NATIVE_ARRAY)) {
            $var->free();
            $released[$name] = true;

            return;
        }
        if ($var->type & Variable::IS_REFCOUNTED) {
            if (null !== $var->objectPropertySlot) {
                return;
            }
            $ptr = Variable::KIND_VALUE === $var->kind
                ? $var->value
                : $this->context->helper->loadValue($var);
            if ($this->context->type->object->hasUserDestructors()) {
                \PHPCompiler\JIT\Builtin\GcCollectCyclesRuntime::ensureLinked($this->context);
                $this->context->builder->call(
                    $this->context->lookupFunction('phpc_destruct_try_invoke'),
                    $this->context->builder->pointerCast(
                        $ptr,
                        $this->context->getTypeFromString('int8*')
                    )
                );
            }
            JIT\Builtin\WeakRefRuntime::ensureLinked($this->context);
            $this->context->builder->call(
                $this->context->lookupFunction('phpc_weakref_clear_object'),
                $this->context->builder->pointerCast(
                    $ptr,
                    $this->context->getTypeFromString('int8*')
                )
            );
            $this->context->refcount->delref($ptr);
            if (Variable::KIND_VARIABLE === $var->kind && null !== $var->value) {
                $slotTy = $var->value->typeOf();
                if (\PHPLLVM\Type::KIND_POINTER === $slotTy->getKind()) {
                    $this->context->builder->store(
                        $slotTy->getElementType()->constNull(),
                        $var->value
                    );
                }
            }
            $released[$name] = true;
        }
    }

    /**
     * @param array<string, true> $byRefParamNames
     * @param array<string, true> $released
     */
    private function releaseJitNamedLocalAtReturn(
        Block $returnBlock,
        string $name,
        int $slotIdx,
        Block $scopeBlock,
        array $byRefParamNames,
        array &$released
    ): void {
        if ('this' === $name || isset($released[$name])) {
            return;
        }
        if (isset($byRefParamNames[$name])) {
            return;
        }
        $resolved = $this->context->resolveRefAliasName($name);
        $var = $this->context->namedVariableBindings[$resolved] ?? null;
        if (null !== $var) {
            $this->releaseJitCanonicalNamedLocalAtReturn($name, $var, $byRefParamNames, $released);

            return;
        }
        if ($slotIdx < 0) {
            return;
        }
        $scopedOp = $scopeBlock->operandForScopeSlot($slotIdx);
        if (null === $scopedOp) {
            return;
        }
        try {
            $var = $this->context->getVariableFromOp($scopedOp);
        } catch (\LogicException) {
            return;
        }
        $this->releaseJitCanonicalNamedLocalAtReturn($name, $var, $byRefParamNames, $released);
    }

    /**
     * @return list<\PHPCfg\Operand>
     */
    private function jitFunctionAssignTargets(Block $returnBlock): array
    {
        /** @var list<\PHPCfg\Operand> $targets */
        $targets = [];
        $seen = new \SplObjectStorage();
        foreach ($this->jitFunctionNamedScopeSlots($returnBlock) as [, , $scopeBlock]) {
            foreach ($this->listUnpackAssignTargetsInBlock($scopeBlock) as $dest) {
                if ($seen->contains($dest)) {
                    continue;
                }
                $seen[$dest] = true;
                $targets[] = $dest;
            }
        }

        return $targets;
    }

    /**
     * All named CV slots in the returning function — return-block scope alone omits
     * live-at-return locals php-cfg already marked dead (#36245 make_pair).
     *
     * @return \Generator<int, array{0: string, 1: int, 2: Block}, mixed, void>
     */
    private function jitFunctionNamedScopeSlots(Block $returnBlock): \Generator
    {
        $root = $this->context->jitFunctionRootBlock ?? $returnBlock;
        /** @var array<int, true> $seenBlocks */
        $seenBlocks = [];
        /** @var list<Block> $queue */
        $queue = [$root];
        while ([] !== $queue) {
            $scan = array_shift($queue);
            $blockId = spl_object_id($scan);
            if (isset($seenBlocks[$blockId])) {
                continue;
            }
            $seenBlocks[$blockId] = true;
            foreach ($scan->eachNamedScopeSlot() as [$name, $slotIdx]) {
                yield [$name, $slotIdx, $scan];
            }
            foreach ($scan->opCodes as $op) {
                foreach ([$op->block1 ?? null, $op->block2 ?? null, $op->block3 ?? null] as $target) {
                    if ($target instanceof Block && !isset($seenBlocks[spl_object_id($target)])) {
                        $queue[] = $target;
                    }
                }
            }
        }
    }

    /**
     * unset($var) on boxed locals: run __destruct before nulling when {main} defers delref destroy (#4096).
     * Also clear WeakMap/WeakReference immediately — {main} may defer __ref__delref free (#27621 / #26795).
     */
    private function jitWriteNullForUnset(\PHPLLVM\Value $valueBoxPtr): void
    {
        $map = $this->context->structFieldMap['__value__'];
        $i8 = $this->context->getTypeFromString('int8');
        $i8p = $this->context->getTypeFromString('int8*');
        $typeByte = $this->context->builder->load(
            $this->context->builder->structGep($valueBoxPtr, $map['type'])
        );
        $isObject = $this->context->builder->icmp(
            \PHPLLVM\Builder::INT_EQ,
            $typeByte,
            $i8->constInt(Variable::TYPE_OBJECT, false)
        );
        $objBlock = JIT\BasicBlockHelper::append($this->context, 'unset_object_side');
        $doneBlock = JIT\BasicBlockHelper::append($this->context, 'unset_object_done');
        $this->context->builder->branchIf($isObject, $objBlock, $doneBlock);
        $this->context->builder->positionAtEnd($objBlock);
        $obj = $this->context->builder->call(
            $this->context->lookupFunction('__value__readObject'),
            $valueBoxPtr
        );
        $objI8 = $this->context->builder->pointerCast($obj, $i8p);
        if ($this->context->type->object->hasUserDestructors()) {
            \PHPCompiler\JIT\Builtin\GcCollectCyclesRuntime::ensureLinked($this->context);
            $this->context->builder->call(
                $this->context->lookupFunction('phpc_destruct_try_invoke'),
                $objI8
            );
        }
        // WeakMap keys must drop before count() even when delref destroy is deferred (#27621).
        // Save insert point — WeakRefRuntime::ensureLinked clears the builder (#27621).
        $insertBefore = $this->context->builder->getInsertBlock();
        JIT\Builtin\WeakRefRuntime::ensureLinked($this->context);
        if (null !== $insertBefore) {
            $this->context->builder->positionAtEnd($insertBefore);
        }
        $this->context->builder->call(
            $this->context->lookupFunction('phpc_weakref_clear_object'),
            $objI8
        );
        // Zend decrements refcount on unset — valueDelref alone leaves extra GC roots (#36245).
        $this->context->refcount->delref($obj);
        $this->context->builder->branch($doneBlock);
        $this->context->builder->positionAtEnd($doneBlock);
        $this->jitNoteMemoryReleaseForUnset($valueBoxPtr);
        $this->context->builder->call(
            $this->context->lookupFunction('__value__writeNull'),
            $valueBoxPtr
        );
    }

    /**
     * Named-storage `$a = new T` keeps the object in the NEW/ASSIGN result
     * {@see __object__**} alloca as well as the CV value-box. Unset (and null
     * assign) must null those mirrors without delref — otherwise the next
     * loop-body NEW freeObjectMirrorUnlessNull double-delrefs the orphan and
     * GC sees roots=0 (#36245 loop_unset). Distinct Operand instances share a
     * CFG slot, so clear via getOperand (assign's operand) not only
     * operandForScopeSlot (prologue).
     */
    private function jitClearAssignResultObjectMirrorForNamedUnset(Block $block, ?int $unsetArgSlot): void
    {
        if (null === $unsetArgSlot) {
            return;
        }
        $targetSlot = (int) $unsetArgSlot;
        $seen = new \SplObjectStorage();
        foreach ($block->opCodes as $assignOp) {
            if (OpCode::TYPE_ASSIGN !== $assignOp->type || null === $assignOp->arg2) {
                continue;
            }
            if ((int) $assignOp->arg2 !== $targetSlot) {
                continue;
            }
            // Property/dim assigns use arg1 === arg2; still clear RHS object mirrors.
            $slots = [];
            if (null !== $assignOp->arg1 && $assignOp->arg1 !== $assignOp->arg2) {
                $slots[] = (int) $assignOp->arg1;
            }
            try {
                $rhs = $this->assignRhsSlot($assignOp);
                if ($rhs !== $targetSlot) {
                    $slots[] = $rhs;
                }
            } catch (\LogicException $e) {
                // Missing RHS slot — named unset still clears assign-result mirrors.
            }
            foreach ($slots as $slot) {
                $this->jitNullObjectMirrorForScopeSlot($block, $slot, $seen);
            }
        }
    }

    /**
     * Null every {@see __object__**} alloca bound to $slot (map + all Operand aliases).
     *
     * @param \SplObjectStorage<\PHPLLVM\Value, mixed> $seen
     */
    private function jitNullObjectMirrorForScopeSlot(Block $block, int $slot, \SplObjectStorage $seen): void
    {
        $nullObj = $this->context->getTypeFromString('__object__*')->constNull();
        if (isset($this->context->scopeSlotObjectMirrorLlvmBySlot[$slot])) {
            $llvmMirror = $this->context->scopeSlotObjectMirrorLlvmBySlot[$slot];
            if (!$seen->contains($llvmMirror)) {
                $seen[$llvmMirror] = true;
                $this->context->builder->store($nullObj, $llvmMirror);
            }
        }
        $operands = [];
        $scoped = $block->operandForScopeSlot($slot);
        if (null !== $scoped) {
            $operands[] = $scoped;
        }
        // Prefer the exact Operand getOperand returns — assign/NEW lower against it (#36245).
        $fromOpcode = $block->getOperand($slot);
        if (null !== $fromOpcode) {
            $operands[] = $fromOpcode;
        }
        foreach ($block->scopedOperands() as $scopedOp) {
            if ($block->slotForOperand($scopedOp) === $slot) {
                $operands[] = $scopedOp;
            }
        }
        foreach ($operands as $op) {
            if (!$this->context->hasVariableOp($op) && !$this->context->scope->variables->contains($op)) {
                continue;
            }
            $mirror = $this->context->hasVariableOp($op)
                ? $this->context->getVariableFromOp($op)
                : $this->context->scope->variables[$op];
            if (
                Variable::TYPE_OBJECT !== $mirror->type
                || Variable::KIND_VARIABLE !== $mirror->kind
                || null !== $mirror->objectPropertySlot
                || $mirror->functionStaticGlobal
            ) {
                continue;
            }
            if (!str_contains($this->context->getStringFromType($mirror->value->typeOf()), '__object__')) {
                continue;
            }
            if ($seen->contains($mirror->value)) {
                continue;
            }
            $seen[$mirror->value] = true;
            $this->context->builder->store($nullObj, $mirror->value);
            $this->context->scopeSlotObjectMirrorLlvmBySlot[$slot] = $mirror->value;
        }
    }

    /** Delref an {@see __object__**} mirror only when it still holds a non-null pointer (#36245). */
    private function freeObjectMirrorUnlessNull(Variable $mirror): void
    {
        $nullObj = $this->context->getTypeFromString('__object__*')->constNull();
        $loaded = $this->context->builder->load($mirror->value);
        $hasObj = $this->context->builder->icmp(
            \PHPLLVM\Builder::INT_NE,
            $loaded,
            $nullObj
        );
        $delrefBlock = JIT\BasicBlockHelper::append($this->context, 'obj_mirror_delref');
        $skipBlock = JIT\BasicBlockHelper::append($this->context, 'obj_mirror_skip');
        $this->context->builder->branchIf($hasObj, $delrefBlock, $skipBlock);
        $this->context->builder->positionAtEnd($delrefBlock);
        $this->context->refcount->delref($loaded);
        $this->context->builder->branch($skipBlock);
        $this->context->builder->positionAtEnd($skipBlock);
        // Always clear — unset may have nulled without delref; next NEW must not
        // load a stale pointer (#36245 / Variable::free peer).
        $this->context->builder->store($nullObj, $mirror->value);
    }

    /**
     * After === / !==, drop anonymous Temporary value boxes (call results). Named
     * locals stay; freeDeadVariables at block edges is too late for unset (#27118).
     */
    private function jitReleaseTempValueBoxAfterCompare(Block $block, Operand $op): void
    {
        $this->context->aliasVariableOpFromSlot($block, $op);
        if (!$this->context->hasVariableOp($op) && !$this->context->scope->variables->contains($op)) {
            $slot = $block->slotForOperand($op);
            if (null === $slot) {
                return;
            }
            $scoped = $block->operandForScopeSlot($slot);
            if (null === $scoped) {
                return;
            }
            $op = $scoped;
        }
        if (!$this->context->hasVariableOp($op) && !$this->context->scope->variables->contains($op)) {
            return;
        }
        $var = $this->context->hasVariableOp($op)
            ? $this->context->getVariableFromOp($op)
            : $this->context->scope->variables[$op];
        $name = JIT\OperandName::resolve($op);
        if (null !== $name && '' !== $name) {
            // Named locals/params must survive identical/not-identical (#31101 MiniWebApp
            // $route after !== "api/status"). Only anonymous temps are statement-end released
            // for WeakReference::get (#27118).
            return;
        }
        if (
            Variable::TYPE_VALUE !== $var->type
            || $var->functionStaticGlobal
            || $var->borrowedValueEntry
            || null !== $var->superglobalName
            || null !== $var->valueBoxAliasPtr
        ) {
            return;
        }
        if (
            Variable::KIND_VARIABLE !== $var->kind
            && Variable::KIND_VALUE !== $var->kind
        ) {
            return;
        }
        $this->jitWriteNullForUnset(
            JIT\JitValueBox::valuePtrFromVariable($this->context, $var)
        );
        if ($this->context->scope->variables->contains($op)) {
            $this->context->scope->variables->detach($op);
        }
    }

    private function jitReleasePendingWeakReferenceGetResult(): void
    {
        $op = $this->context->pendingWeakReferenceGetResult;
        $this->context->pendingWeakReferenceGetResult = null;
        if (null === $op) {
            return;
        }
        if (!$this->context->hasVariableOp($op) && !$this->context->scope->variables->contains($op)) {
            return;
        }
        $var = $this->context->hasVariableOp($op)
            ? $this->context->getVariableFromOp($op)
            : $this->context->scope->variables[$op];
        if (
            Variable::TYPE_VALUE !== $var->type
            || $var->functionStaticGlobal
            || $var->borrowedValueEntry
        ) {
            return;
        }
        $this->jitWriteNullForUnset(
            JIT\JitValueBox::valuePtrFromVariable($this->context, $var)
        );
        if ($this->context->scope->variables->contains($op)) {
            $this->context->scope->variables->detach($op);
        }
    }

    /**
     * VM releaseVmJumpIfCondTemps (#14103) for JIT: drop anonymous TYPE_VALUE boxes
     * that die in this block before branching so WeakReference::get() results do not
     * keep referents across unset in a ternary-echo merge block (#27118).
     *
     * Only considers {@see Block::$orig} deadOperands for this block — after successor
     * arms are compiled, scope also holds merge-block bindings that must not be freed here.
     */
    private function jitReleaseJumpIfAnonValueBoxes(Block $block, OpCode $jumpIf): void
    {
        $keepOps = new \SplObjectStorage();
        if (null !== $jumpIf->arg1) {
            $condOp = $this->operandAt($block, $jumpIf->arg1, 'branch condition');
            $keepOps[$condOp] = true;
        }
        foreach ($this->context->coalesceAssignTargets as $mergeOp) {
            $keepOps[$mergeOp] = true;
        }
        $toFree = [];
        $seen = new \SplObjectStorage();
        foreach ($block->orig->deadOperands as $deadOp) {
            $candidates = [$deadOp];
            $slot = $block->slotForOperand($deadOp);
            if (null !== $slot) {
                $scoped = $block->operandForScopeSlot($slot);
                if (null !== $scoped) {
                    $candidates[] = $scoped;
                }
            }
            foreach ($candidates as $op) {
                if ($seen->contains($op)) {
                    continue;
                }
                $seen[$op] = true;
                if ($keepOps->contains($op)) {
                    continue;
                }
                $name = JIT\OperandName::resolve($op);
                // Named CVs (params / locals) are never "anon" temps — nulling them at a
                // JUMPIF edge clears live values still read in ternary/if arms (#27624:
                // DNF `__value__*` param `$x` + `is_array($x) ? count($x) : …`).
                if (null !== $name && '' !== $name) {
                    continue;
                }
                if (!$this->context->scope->variables->contains($op) && !$this->context->hasVariableOp($op)) {
                    continue;
                }
                $var = $this->context->hasVariableOp($op)
                    ? $this->context->getVariableFromOp($op)
                    : $this->context->scope->variables[$op];
                // Only owned KIND_VARIABLE allocas. KIND_VALUE often aliases a live CV /
                // caller `__value__*` (DNF/mixed params); writeNull would clear storage
                // still read in ternary arms (#27624).
                if (
                    Variable::TYPE_VALUE !== $var->type
                    || Variable::KIND_VARIABLE !== $var->kind
                    || $var->functionStaticGlobal
                    || $var->borrowedValueEntry
                    || null !== $var->superglobalName
                    || null !== $var->valueBoxAliasPtr
                ) {
                    continue;
                }
                $toFree[] = $op;
            }
        }
        foreach ($toFree as $op) {
            $var = $this->context->hasVariableOp($op)
                ? $this->context->getVariableFromOp($op)
                : $this->context->scope->variables[$op];
            $this->jitWriteNullForUnset(
                JIT\JitValueBox::valuePtrFromVariable($this->context, $var)
            );
            if ($this->context->scope->variables->contains($op)) {
                $this->context->scope->variables->detach($op);
            }
        }
    }

    /** Zend emalloc parity: drop tracked bytes when unset frees a string (#7310). */
    private function jitNoteMemoryReleaseForUnset(\PHPLLVM\Value $valueBoxPtr): void
    {
        JIT\Builtin\MemoryRuntime::ensureLinked($this->context);
        $map = $this->context->structFieldMap['__value__'];
        $stringMap = $this->context->structFieldMap['__string__'];
        $i8 = $this->context->getTypeFromString('int8');
        $i64 = $this->context->getTypeFromString('int64');
        $zero = $i64->constInt(0, false);
        $typeByte = $this->context->builder->load(
            $this->context->builder->structGep($valueBoxPtr, $map['type'])
        );
        $isString = $this->context->builder->icmp(
            \PHPLLVM\Builder::INT_EQ,
            $typeByte,
            $i8->constInt(Variable::TYPE_STRING, false)
        );
        $doneBlock = JIT\BasicBlockHelper::append($this->context, 'unset_mem_done');
        $stringBlock = JIT\BasicBlockHelper::append($this->context, 'unset_mem_string');
        $this->context->builder->branchIf($isString, $stringBlock, $doneBlock);
        $this->context->builder->positionAtEnd($stringBlock);
        $strPtr = $this->context->builder->call(
            $this->context->lookupFunction('__value__readString'),
            $valueBoxPtr
        );
        $len = $this->context->builder->load(
            $this->context->builder->structGep($strPtr, $stringMap['length'])
        );
        $negLen = $this->context->builder->sub($zero, $len);
        JIT\Builtin\MemoryRuntime::noteAlloc($this->context, $negLen);
        $this->context->builder->branch($doneBlock);
        $this->context->builder->positionAtEnd($doneBlock);
    }

    /** Drop assign RHS / result temps so block-end dead-operand free cannot re-delref (#4096). */
    private function jitClearAssignTempOperand(Operand $op): void
    {
        $this->jitWriteNullOperand($op);
        if ($this->context->scope->variables->contains($op)) {
            $this->context->scope->variables->detach($op);
        }
    }

    /** Mirror VM assign: clear dead assign-result / RHS temps (#4096). */
    private function jitWriteNullOperand(Operand $op): void
    {
        if (!$this->context->hasVariableOp($op)) {
            return;
        }
        $var = $this->context->getVariableFromOp($op);
        if (Variable::KIND_VARIABLE === $var->kind && Variable::TYPE_VALUE === $var->type) {
            $this->context->builder->call(
                $this->context->lookupFunction('__value__writeNull'),
                $var->value
            );

            return;
        }
        if (
            Variable::TYPE_OBJECT === $var->type
            && Variable::KIND_VARIABLE === $var->kind
            && null !== $var->value
            && \in_array($var->value, $this->context->scopeSlotObjectMirrorLlvmBySlot, true)
        ) {
            $isCanonicalCv = false;
            foreach ($this->context->namedVariableBindings as $bound) {
                if ($bound === $var) {
                    $isCanonicalCv = true;
                    break;
                }
            }
            if (!$isCanonicalCv) {
                $slotTy = $var->value->typeOf();
                if (\PHPLLVM\Type::KIND_POINTER === $slotTy->getKind()) {
                    $this->context->builder->store(
                        $slotTy->getElementType()->constNull(),
                        $var->value
                    );
                }

                return;
            }
        }
        $var->free();
    }

    /**
     * php-cfg ASSIGN(resultTemp, namedAlias, rhs) — mark the named CV, not only the dead temp (#36405).
     */
    private function propagateAssignAliasBinding(
        ?Operand $destOp,
        Operand $aliasOp,
        bool $namedAliasReceivedAssign
    ): void {
        $aliasName = JIT\OperandName::resolve($aliasOp);
        if (null === $aliasName || '' === $aliasName) {
            return;
        }
        if ($namedAliasReceivedAssign) {
            if ($this->context->hasVariableOp($aliasOp)) {
                JIT\UndefinedVariableHelper::markAssigned(
                    $this->context,
                    $aliasOp,
                    $this->context->getVariableFromOp($aliasOp)
                );
            }

            return;
        }
        if (null !== $destOp && $this->context->hasVariableOp($destOp)) {
            $destVar = $this->context->getVariableFromOp($destOp);
            $this->context->setVariableOp($aliasOp, $destVar);
            $this->context->bindVariableByName(
                $this->context->resolveRefAliasName($aliasName),
                $destVar
            );
            JIT\UndefinedVariableHelper::markAssigned($this->context, $aliasOp, $destVar);
        }
    }

    private function maybeBindNamedVariable(Operand $op): void
    {
        if (!$this->context->hasVariableOp($op)) {
            return;
        }
        $name = JIT\OperandName::resolve($op);
        if (null === $name || '' === $name) {
            return;
        }
        $var = $this->context->getVariableFromOp($op);
        $this->context->bindVariableByName($name, $var);
        // TYPE_ASSIGN dest is a defined CV for later ZEND_CHECK_UNDEFINED_VAR (#32041).
        JIT\UndefinedVariableHelper::markAssigned($this->context, $op, $var);
    }

    /**
     * After KIND_VALUE→alloca CONCAT promotion, rebind every Operand for the dest
     * scope slot (named local + unnamed Temporary) so in-place `$out .=` loads and
     * stores the same alloca across loop iterations (#22845).
     */
    private function bindPromotedStringConcatDest(Block $block, Operand $destOp, Variable $promoted): void
    {
        $names = [];
        $destName = JIT\OperandName::resolve($destOp);
        if (null !== $destName && '' !== $destName) {
            $names[$destName] = true;
        }
        $slot = $block->slotForOperand($destOp);
        if (null !== $slot) {
            foreach ($block->scopedOperands() as $scopeOp) {
                if ($block->slotForOperand($scopeOp) !== $slot) {
                    continue;
                }
                $this->context->setVariableOp($scopeOp, $promoted);
                $scopeName = JIT\OperandName::resolve($scopeOp);
                if (null !== $scopeName && '' !== $scopeName) {
                    $names[$scopeName] = true;
                }
            }
        }
        foreach ($names as $name => $_) {
            $this->context->bindVariableByName((string) $name, $promoted);
        }
        $this->markScopeVariableAssignedIfTracked($destOp, $promoted);
        if (null !== $slot) {
            foreach ($block->scopedOperands() as $scopeOp) {
                if ($block->slotForOperand($scopeOp) !== $slot) {
                    continue;
                }
                $this->markScopeVariableAssignedIfTracked($scopeOp, $promoted);
            }
        }
    }

    /**
     * When php-cfg assigns through a named temporary with no downstream usages, the name slot
     * may still be skipped by assignOperand; fold from the matching TYPE_ASSIGN constant (#1226).
     */
    private function foldVarFetchNameFromAssign(Block $block, int $nameSlot, Variable $nameVar): void
    {
        if (null !== $nameVar->compileTimeString) {
            return;
        }
        if (isset($block->constants[$nameSlot])) {
            $nameVar->compileTimeString = $block->constants[$nameSlot]->toString();

            return;
        }
        foreach ($block->opCodes as $prior) {
            if (OpCode::TYPE_ASSIGN !== $prior->type) {
                continue;
            }
            if (!\in_array($prior->arg2, $this->jitNamedScopeSlotAliases($block, $nameSlot), true)) {
                continue;
            }
            if (!isset($block->constants[$prior->arg3])) {
                continue;
            }
            $nameVar->compileTimeString = $block->constants[$prior->arg3]->toString();

            return;
        }
    }

    private function varFetchDestUsedAsAssignLvalue(Block $block, int $opIndex, int $destSlot): bool
    {
        // Immediate next only — later ASSIGN is often dead-temp reuse, not a write (#23986).
        $next = $block->opCodes[$opIndex + 1] ?? null;
        if (null === $next) {
            return false;
        }
        if (!OpCode::destSlotUsedAsAssignLvalue($next, $destSlot)) {
            return false;
        }
        // php-cfg folds `($o->prop . '=')` into in-place CONCAT on the ?: echo phi slot.
        // That CONCAT writes the stack phi, not the property — a write-mode fetch empties
        // virtual DOM props (nodeName) and AOT prints "=" then after= is blank (#33849).
        if (
            OpCode::TYPE_CONCAT === $next->type
            && isset($this->context->coalesceMergeSlotOperands[$destSlot])
        ) {
            return false;
        }

        return true;
    }

    /**
     * True when fetch dest is the operand of an immediate TYPE_RETURN in a by-ref function
     * (`function &f(){ return C::$x; }` → ZEND_FETCH_STATIC_PROP_W, #34727).
     */
    private function varFetchDestUsedAsByRefReturn(Block $block, int $opIndex, int $destSlot): bool
    {
        if (!$this->cfgFunctionReturnsByRef($block->func)) {
            return false;
        }
        $next = $block->opCodes[$opIndex + 1] ?? null;
        if (null === $next || OpCode::TYPE_RETURN !== $next->type) {
            return false;
        }

        return (int) $next->arg1 === $destSlot;
    }

    /**
     * True when the fetch dest is the LHS of the immediately following TYPE_ASSIGN
     * (`$this->x = $rhs`). Skip the VALUE-slot load for those writes (#32349).
     */
    private function varFetchDestUsedAsPlainAssignStore(Block $block, int $opIndex, int $destSlot): bool
    {
        $next = $block->opCodes[$opIndex + 1] ?? null;
        if (null === $next || OpCode::TYPE_ASSIGN !== $next->type) {
            return false;
        }

        return OpCode::destSlotUsedAsAssignLvalue($next, $destSlot);
    }

    /** True when fetch dest is lhs of a following compound assign ($a[$k] += …, #31991). */
    private function varFetchDestUsedAsCompoundAssign(Block $block, int $opIndex, int $destSlot): bool
    {
        $next = $block->opCodes[$opIndex + 1] ?? null;
        if (null === $next) {
            return false;
        }

        return OpCode::destSlotUsedAsCompoundAssignRead($next, $destSlot)
            || OpCode::destSlotUsedAsInPlaceCompoundAssign($next, $destSlot);
    }

    /** True when fetch dest is lhs of a following compound read ($prop += …, #30077). */
    private function varFetchDestUsedAsCompoundAssignRead(Block $block, int $opIndex, int $destSlot): bool
    {
        $next = $block->opCodes[$opIndex + 1] ?? null;
        if (null === $next) {
            return false;
        }

        return OpCode::destSlotUsedAsCompoundAssignRead($next, $destSlot);
    }

    /**
     * True when the next meaningful use of the fetch dest is TYPE_ISSET (?? / isset).
     * Those are BP_VAR_IS and must not raise typed-uninit (#29688 / #33886).
     */
    private function propertyFetchResultUsedOnlyAsIsset(Block $block, int $opIndex, int $destSlot): bool
    {
        $ops = $block->opCodes;
        $n = \count($ops);
        for ($i = $opIndex + 1; $i < $n; ++$i) {
            $next = $ops[$i];
            if (OpCode::TYPE_ISSET === $next->type) {
                return (int) $next->arg2 === $destSlot || (int) $next->arg1 === $destSlot;
            }
            // Any other consumer of this slot (echo, assign, call, …) is BP_VAR_R.
            if (
                (int) $next->arg1 === $destSlot
                || (int) ($next->arg2 ?? -1) === $destSlot
                || (int) ($next->arg3 ?? -1) === $destSlot
            ) {
                return false;
            }
        }

        return false;
    }

    /**
     * True when fetch dest is the container for `$prop[]=` / `$prop[$k]=` / unset dim (#29748).
     */
    private function varFetchDestUsedAsDimWriteContainer(Block $block, int $opIndex, int $destSlot): bool
    {
        $ops = $block->opCodes;
        $n = \count($ops);
        for ($i = $opIndex + 1; $i < $n; ++$i) {
            $next = $ops[$i];
            if (OpCode::destSlotUsedAsDimWriteContainer($next, $destSlot)) {
                return true;
            }
            if (
                OpCode::TYPE_PROPERTY_FETCH === $next->type
                || OpCode::TYPE_PROPERTY_FETCH_WRITE === $next->type
            ) {
                if ((int) $next->arg1 === $destSlot) {
                    return false;
                }
                continue;
            }
            if (
                OpCode::TYPE_ARRAY_DIM_FETCH === $next->type
                || OpCode::TYPE_ARRAY_DIM_FETCH_WRITE === $next->type
            ) {
                continue;
            }
            if (OpCode::TYPE_UNSET === $next->type) {
                continue;
            }

            return false;
        }

        return false;
    }

    /**
     * True when fetch dest is the container of a later FETCH_DIM_W (`$a[i][j]` / #34745).
     *
     * @see php-src Zend/zend_execute.c ZEND_FETCH_DIM_W (nested dimension address)
     */
    private function varFetchDestUsedAsNestedDimWriteContainer(Block $block, int $opIndex, int $destSlot): bool
    {
        $ops = $block->opCodes;
        $n = \count($ops);
        for ($i = $opIndex + 1; $i < $n; ++$i) {
            $next = $ops[$i];
            if (
                OpCode::TYPE_ARRAY_DIM_FETCH_WRITE === $next->type
                && (int) $next->arg2 === $destSlot
            ) {
                return true;
            }
        }

        return false;
    }

    /**
     * Expected type for dimFetch: force TYPE_ARRAY on nested FETCH_DIM_W intermediates (#24011 / #34745)
     * and on FETCH_DIM_W prefixes that feed unset($a[i][k]) (#36380).
     *
     * CFG often leaves `$a[0]` as mixed when `$a` is a by-ref formal; without TYPE_ARRAY the outer
     * write returns a prepareIndexWrite orphan and the inner write/unset mutates a detached HT.
     *
     * php-src: Zend/zend_execute.c ZEND_FETCH_DIM_W (nested dimension address) + ZEND_UNSET_DIM.
     */
    private function dimFetchExpectedType(
        Block $block,
        int $opIndex,
        int $destSlot,
        ?\PHPTypes\Type $resultType,
        bool $forWrite
    ): ?\PHPTypes\Type {
        if (
            $forWrite
            && (
                $this->varFetchDestUsedAsNestedDimWriteContainer($block, $opIndex, $destSlot)
                || $this->varFetchDestUsedAsDimWriteContainer($block, $opIndex, $destSlot)
            )
        ) {
            return \PHPTypes\Type::fromDecl('array');
        }

        return $resultType;
    }

    /**
     * True when property fetch feeds dim RW (++/--/+=) — Zend BP_VAR_RW (#31784).
     */
    private function varFetchDestUsedAsDimRwContainer(Block $block, int $opIndex, int $destSlot): bool
    {
        $ops = $block->opCodes;
        $n = \count($ops);
        for ($i = $opIndex + 1; $i < $n; ++$i) {
            $next = $ops[$i];
            if (
                OpCode::TYPE_ARRAY_DIM_FETCH_WRITE === $next->type
                && (int) $next->arg2 === $destSlot
            ) {
                $dimSlot = (int) $next->arg1;
                for ($j = $i + 1; $j < $n; ++$j) {
                    $consumer = $ops[$j];
                    if (OpCode::dimSlotUsedAsRwOp($consumer, $dimSlot)) {
                        return true;
                    }
                    if (
                        OpCode::TYPE_ASSIGN === $consumer->type
                        && (int) $consumer->arg2 === $dimSlot
                        && (int) $consumer->arg3 !== $dimSlot
                    ) {
                        return false;
                    }
                    if ((int) $consumer->arg1 === $dimSlot) {
                        if (
                            OpCode::TYPE_PROPERTY_FETCH === $consumer->type
                            || OpCode::TYPE_PROPERTY_FETCH_WRITE === $consumer->type
                            || OpCode::TYPE_ARRAY_DIM_FETCH === $consumer->type
                            || OpCode::TYPE_ARRAY_DIM_FETCH_WRITE === $consumer->type
                        ) {
                            return false;
                        }
                    }
                }

                return false;
            }
            if (
                OpCode::TYPE_PROPERTY_FETCH === $next->type
                || OpCode::TYPE_PROPERTY_FETCH_WRITE === $next->type
            ) {
                if ((int) $next->arg1 === $destSlot) {
                    return false;
                }
                continue;
            }
            if (
                OpCode::TYPE_ARRAY_DIM_FETCH === $next->type
                || OpCode::TYPE_ARRAY_DIM_FETCH_WRITE === $next->type
            ) {
                continue;
            }
            if (OpCode::TYPE_UNSET === $next->type) {
                continue;
            }

            return false;
        }

        return false;
    }

    private function varFetchDestUsedAsIncDec(Block $block, int $opIndex, int $destSlot): bool
    {
        $next = $block->opCodes[$opIndex + 1] ?? null;
        if (null === $next) {
            return false;
        }

        return \in_array($next->type, [
            OpCode::TYPE_PRE_INC,
            OpCode::TYPE_POST_INC,
            OpCode::TYPE_PRE_DEC,
            OpCode::TYPE_POST_DEC,
        ], true) && $next->arg3 === $destSlot;
    }

    /**
     * Resolve the JIT variable for a scope slot (issue #1226).
     *
     * TYPE_VAR_FETCH arg2 is the slot holding the runtime name string, which may map to
     * multiple CFG operands; prefer a bound operand with compile-time string metadata.
     */
    private function variableFromBlockSlot(Block $block, int $slot): Variable
    {
        $operands = [];
        foreach ($block->scopedOperands() as $op) {
            if ($block->slotForOperand($op) === $slot) {
                $operands[] = $op;
            }
        }
        if ([] === $operands) {
            throw new \LogicException('No operand mapped to slot '.$slot);
        }
        usort($operands, [self::class, 'compareOperandsForSlotResolution']);
        $bound = null;
        foreach ($operands as $op) {
            if (!$this->context->hasVariableOp($op)) {
                continue;
            }
            $candidate = $this->context->getVariableFromOp($op);
            if (null !== $candidate->compileTimeString) {
                return $candidate;
            }
            if (null === $bound) {
                $bound = $candidate;
            }
        }
        if (null !== $bound) {
            return $bound;
        }

        throw new \LogicException('No JIT variable for slot '.$slot);
    }

    /**
     * Collect `global $name` imports before lowering any block — try bodies may compile first (#16828).
     */
    private function prescanFunctionImportedGlobals(\PHPCfg\Func $func): void
    {
        if (null === $func->cfg) {
            return;
        }
        $seen = [];
        $queue = [$func->cfg];
        while ([] !== $queue) {
            $scan = array_shift($queue);
            $id = spl_object_id($scan);
            if (isset($seen[$id])) {
                continue;
            }
            $seen[$id] = true;
            foreach ($scan->children as $op) {
                if ($op instanceof \PHPCfg\Op\Terminal\GlobalVar) {
                    $name = JIT\OperandName::resolve($op->var);
                    if (null !== $name && '' !== $name) {
                        $this->context->jitImportedGlobalNames[$name] = true;
                    }
                }
                Cfg\OpSubBlockAccess::enqueueSubBlocks($op, $queue);
            }
        }
    }

    private function ensureJitGlobal(string $name): Variable
    {
        return $this->context->ensureScriptGlobal($name);
    }

    /**
     * Resolve `public const X = SomeEnum::Case` when VM materialization lacks the enum (#4445).
     *
     * @return array{0: int, 1: string}|null enum class id + case key (lowercase)
     */
    private function tryResolveEnumCaseClassConstInit(Block $block, int $valueSlot): ?array
    {
        $fetchOp = null;
        foreach ($block->opCodes as $initOp) {
            if (OpCode::TYPE_DECLARE_CLASS_CONST === $initOp->type && $valueSlot === $initOp->arg2) {
                break;
            }
            if (OpCode::TYPE_CLASS_CONST_FETCH === $initOp->type) {
                $fetchOp = $initOp;
            }
        }
        if (null === $fetchOp) {
            return null;
        }
        $enumClassOp = $block->getOperand($fetchOp->arg2);
        $caseOp = $block->getOperand($fetchOp->arg3);
        $enumName = null;
        $caseName = null;
        if ($enumClassOp instanceof Operand\Literal) {
            $enumName = (string) $enumClassOp->value;
        } elseif (isset($block->constants[$fetchOp->arg2])
            && \PHPCompiler\VM\Variable::TYPE_STRING === $block->constants[$fetchOp->arg2]->type) {
            $enumName = $block->constants[$fetchOp->arg2]->toString();
        }
        if ($caseOp instanceof Operand\Literal) {
            $caseName = (string) $caseOp->value;
        } elseif (isset($block->constants[$fetchOp->arg3])
            && \PHPCompiler\VM\Variable::TYPE_STRING === $block->constants[$fetchOp->arg3]->type) {
            $caseName = $block->constants[$fetchOp->arg3]->toString();
        }
        if (null === $enumName || null === $caseName) {
            return null;
        }
        $enumLc = strtolower(ltrim($enumName, '\\'));
        if (!$this->context->type->object->isRegisteredEnumLc($enumLc)) {
            return null;
        }
        $enumClassId = $this->context->type->object->lookup($enumLc);
        if (!$this->context->type->object->isEnumClassId($enumClassId)) {
            return null;
        }

        return [$enumClassId, \PHPCompiler\ClassConstName::key($caseName)];
    }

    /**
     * Map a folded TYPE_ENUM_CASE VM slot to the JIT enum singleton (#31967).
     *
     * @return array{0: int, 1: string}|null
     */
    private function tryEnumCaseRefFromVmConstant(VM\Variable $vm): ?array
    {
        if (VM\Variable::TYPE_ENUM_CASE !== $vm->type) {
            return null;
        }
        $case = $vm->toEnumCase();
        $enumLc = strtolower(ltrim($case->enumClass->name, '\\'));
        if (!$this->context->type->object->hasDeclaredClass($enumLc)
            || !$this->context->type->object->isRegisteredEnumLc($enumLc)) {
            return null;
        }
        $enumClassId = $this->context->type->object->lookup($enumLc);
        if (!$this->context->type->object->isEnumClassId($enumClassId)) {
            return null;
        }

        return [$enumClassId, \PHPCompiler\ClassConstName::key($case->caseName)];
    }

    private function ensureJitFunctionStatic(string $storageKey): Variable
    {
        if (!isset($this->context->jitFunctionStaticVariables[$storageKey])) {
            $globalName = 'phpc_fn_static_val_'.substr(hash('sha256', $storageKey), 0, 16);
            $ptrTy = $this->context->getTypeFromString('__value__*');
            $global = $this->context->module->addGlobal($ptrTy, $globalName);
            $global->setInitializer($ptrTy->constNull());
            $this->initJitFunctionStaticValueGlobal($global);
            $staticVar = new Variable(
                $this->context,
                Variable::TYPE_VALUE,
                Variable::KIND_VALUE,
                $global
            );
            $staticVar->functionStaticGlobal = true;
            $this->context->jitFunctionStaticVariables[$storageKey] = $staticVar;
        }

        return $this->context->jitFunctionStaticVariables[$storageKey];
    }

    /**
     * Retype a DECLARE_FUNCTION_STATIC operand (and same-slot aliases) so FETCH_DIM_W
     * can distinguish HT vs string-offset paths (#32806 / #32814).
     */
    private function retypeFunctionStaticOperand($block, Operand $destOp, Type $type): void
    {
        $destOp->type = $type;
        $typedSlot = $block->slotForOperand($destOp);
        if (null === $typedSlot) {
            return;
        }
        foreach ($block->scopedOperands() as $scopeOp) {
            if ($block->slotForOperand($scopeOp) === $typedSlot) {
                $scopeOp->type = $type;
            }
        }
    }

    private function initJitFunctionStaticValueGlobal(PHPLLVM\Value $global): void
    {
        $restore = $this->context->builder->getInsertBlock();
        $this->context->builder->positionAtEnd($this->context->initBlock);
        $valueType = $this->context->getTypeFromString('__value__');
        $heapVal = $this->context->memory->malloc($valueType);
        $heapPtr = $this->context->builder->pointerCast(
            $heapVal,
            $this->context->getTypeFromString('__value__*')
        );
        $this->context->builder->call(
            $this->context->lookupFunction('__value__writeNull'),
            $heapPtr
        );
        $this->context->builder->store($heapPtr, $global);
        $this->context->builder->positionAtEnd($restore);
    }

    private static function operandSlotRank(\PHPCfg\Operand $op): int
    {
        $name = JIT\OperandName::resolve($op);
        if ($op instanceof \PHPCfg\Operand\Temporary && null !== $name && '' !== $name) {
            return 3;
        }
        if ($op instanceof \PHPCfg\Operand\Variable) {
            return 2;
        }

        return 1;
    }

    private static function compareOperandsForSlotResolution(\PHPCfg\Operand $a, \PHPCfg\Operand $b): int
    {
        return self::operandSlotRank($b) <=> self::operandSlotRank($a);
    }

}
