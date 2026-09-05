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
require_once __DIR__.'/JIT/Concern/PropertyFetchCoalesceAndCompileTimeString.php';
require_once __DIR__.'/JIT/Concern/CallResultOperandAssign.php';
require_once __DIR__.'/JIT/Concern/ByRefFormalAssignAndCallArgAdapt.php';
require_once __DIR__.'/JIT/Concern/ResolveJitOutgoingCall.php';
require_once __DIR__.'/JIT/Concern/LocalReleaseUnsetAndVarFetchDest.php';
require_once __DIR__.'/JIT/Concern/ScriptGlobalAssignAndLvalueResolve.php';
require_once __DIR__.'/JIT/Concern/AssignRefSharedBoxAndClosureInvoke.php';
require_once __DIR__.'/JIT/Concern/ClassConstEnumAndFunctionStatic.php';
require_once __DIR__.'/JIT/Concern/AssignOperandValueMetaAndGeneratorField.php';
require_once __DIR__.'/JIT/Concern/JitConstructAssignedAndNativeLongLocal.php';
require_once __DIR__.'/JIT/Concern/NestedVmHelperAndThisResolve.php';
require_once __DIR__.'/JIT/Concern/BoundMethodInstanceCallResolve.php';
require_once __DIR__.'/JIT/Concern/ClosureThisAndStaticScopeResolve.php';
require_once __DIR__.'/JIT/Concern/ParamConstraintsAndRuntimeNewInit.php';
require_once __DIR__.'/JIT/Concern/BinaryOpConcatAndTypeMapConstants.php';
require_once __DIR__.'/JIT/Concern/SubBlockCatchFinallyAndGeneratorResume.php';
require_once __DIR__.'/JIT/Concern/ListUnpackClassDeclareAndIncludeAssign.php';
require_once __DIR__.'/JIT/Concern/SkippedSplitCfgAndTernaryMergeHelpers.php';
require_once __DIR__.'/JIT/Concern/SkippedHotPathAndRealLoweringNames.php';
require_once __DIR__.'/JIT/Concern/M3EmitTuCompilerAndRuntimeVoidStubs.php';
require_once __DIR__.'/JIT/Concern/M3EmitTuAndCompileDriverMainNative.php';
require_once __DIR__.'/JIT/Concern/M3EmitTuRuntimeSpineDeclsAndCompileDeps.php';
require_once __DIR__.'/JIT/Concern/M3EmitTuRuntimeParseAndInitSpine.php';
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
    use PropertyFetchCoalesceAndCompileTimeString;
    use CallResultOperandAssign;
    use ByRefFormalAssignAndCallArgAdapt;
    use ResolveJitOutgoingCall;
    use LocalReleaseUnsetAndVarFetchDest;
    use ScriptGlobalAssignAndLvalueResolve;
    use AssignRefSharedBoxAndClosureInvoke;
    use ClassConstEnumAndFunctionStatic;
    use AssignOperandValueMetaAndGeneratorField;
    use JitConstructAssignedAndNativeLongLocal;
    use NestedVmHelperAndThisResolve;
    use BoundMethodInstanceCallResolve;
    use ClosureThisAndStaticScopeResolve;
    use ParamConstraintsAndRuntimeNewInit;
    use BinaryOpConcatAndTypeMapConstants;
    use SubBlockCatchFinallyAndGeneratorResume;
    use ListUnpackClassDeclareAndIncludeAssign;
    use SkippedSplitCfgAndTernaryMergeHelpers;
    use SkippedHotPathAndRealLoweringNames;
    use M3EmitTuCompilerAndRuntimeVoidStubs;
    use M3EmitTuAndCompileDriverMainNative;
    use M3EmitTuRuntimeSpineDeclsAndCompileDeps;
    use M3EmitTuRuntimeParseAndInitSpine;
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

}
