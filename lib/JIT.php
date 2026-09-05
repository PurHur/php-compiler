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
require_once __DIR__.'/JIT/Concern/SkippedVmEmitHelperAndCompileDriverNames.php';
require_once __DIR__.'/JIT/Concern/M3EmitTuSpineNativeTryAndCfgParamTypes.php';
require_once __DIR__.'/JIT/Concern/M3EmitTuCompilerAndRuntimeVoidStubs.php';
require_once __DIR__.'/JIT/Concern/M3EmitTuAndCompileDriverMainNative.php';
require_once __DIR__.'/JIT/Concern/M3EmitTuRuntimeSpineDeclsAndCompileDeps.php';
require_once __DIR__.'/JIT/Concern/M3EmitTuRuntimeParseAndInitSpine.php';
require_once __DIR__.'/JIT/Concern/VmSmokeAndRuntimeM3NativeStubs.php';
require_once __DIR__.'/JIT/Concern/M3EmitTuCompilerRuntimeMethodCompile.php';
require_once __DIR__.'/JIT/Concern/M3EmitTuRuntimeSpineStubNative.php';
require_once __DIR__.'/JIT/Concern/ValueBoxCoalesceAndConcatHelpers.php';
require_once __DIR__.'/JIT/Concern/SelfHostEmitHelperAndVendorPrelinkPolicy.php';
require_once __DIR__.'/JIT/Concern/CompileSkippedOpcodeVmAndCfgBranchStubs.php';
require_once __DIR__.'/JIT/Concern/CompileBlockPhpLoweringAndClosurePrep.php';
require_once __DIR__.'/JIT/Concern/M3M4M5CompileDriverEmitPolicy.php';
require_once __DIR__.'/JIT/Concern/CompileBlockDispatchAndReflectionMeta.php';
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
    use SkippedVmEmitHelperAndCompileDriverNames;
    use M3EmitTuSpineNativeTryAndCfgParamTypes;
    use M3EmitTuCompilerAndRuntimeVoidStubs;
    use M3EmitTuAndCompileDriverMainNative;
    use M3EmitTuRuntimeSpineDeclsAndCompileDeps;
    use M3EmitTuRuntimeParseAndInitSpine;
    use VmSmokeAndRuntimeM3NativeStubs;
    use M3EmitTuCompilerRuntimeMethodCompile;
    use M3EmitTuRuntimeSpineStubNative;
    use ValueBoxCoalesceAndConcatHelpers;
    use SelfHostEmitHelperAndVendorPrelinkPolicy;
    use CompileSkippedOpcodeVmAndCfgBranchStubs;
    use CompileBlockPhpLoweringAndClosurePrep;
    use M3M4M5CompileDriverEmitPolicy;
    use CompileBlockDispatchAndReflectionMeta;
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

}
