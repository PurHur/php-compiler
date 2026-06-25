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
require_once __DIR__.'/JIT/RuntimeInitVmContext.php';
require_once __DIR__.'/JIT/RuntimeInitCompiler.php';
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

    private static int $functionNumber = 0;
    private static int $blockNumber = 0;
    /** Nested php-in-PHP helper compiles during an outer JIT::compile() (#10528). */
    private static int $compileDepth = 0;

    public int $optimizationLevel = 3;


    private array $stringConstant = [];
    private array $intConstant = [];
    private array $builtIns = [];

    private array $queue = [];

    /** @var \SplObjectStorage<OpCode, true> DECLARE_GLOBAL_CONST opcodes that registered (#4941). */
    private \SplObjectStorage $registeredGlobalConstDeclareOpcodes;

    private ?Block $m3EmitTuMainBlock = null;
    private ?Block $m3CompileDriverMainBlock = null;
    private bool $m3EmitTuRuntimeSpineLowered = false;
    private bool $m3CompileDriverRuntimeSpineLowered = false;
    private ?Block $m3EmitTuTrivialEchoBlock = null;
    private ?string $m3EmitTuTrivialEchoSource = null;
    private bool $m3EmitTuSidecarsCached = false;

    public Context $context;

    public function __construct(Context $context) {
        $this->context = $context;
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
        }
        $emitHelperStubBlock = $this->m3CompileDriverMainBlock ?? $this->m3EmitTuMainBlock;
        if (null !== $emitHelperStubBlock && $this->shouldStubInventoryEmitHelperBundledBodies()) {
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
            if (!isset($this->context->functions[$parseLc])) {
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
        if (!$this->shouldUseM4InventoryArgvNativeEmitRebuild($block)) {
            $this->runQueue();
        }
        JIT\Progress::noteFunction('jit_compile_run_queue_done');
        JIT\Progress::noteFunction('jit_compile_finalize_m3_emit_tu_spine_begin');
        $this->finalizeM3EmitTuRuntimeSpineAfterQueue();
        JIT\Progress::noteFunction('jit_compile_finalize_m3_emit_tu_spine_done');

        JIT\Progress::noteFunction('jit_compile_done');
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
            $this->context->coalesceAssignTargets = new \SplObjectStorage();
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
            $llvmFunc = $run[0];
            $cfgBlock = $run[1] ?? null;
            if ($cfgBlock instanceof Block && null !== $cfgBlock->func) {
                $this->context->activeFunction = strtolower($cfgBlock->func->getScopedName());
            } else {
                foreach ($this->context->functions as $name => $candidate) {
                    if ($candidate === $llvmFunc) {
                        $this->context->activeFunction = $name;
                        break;
                    }
                }
            }
            $this->compileBlockInternal($llvmFunc, $cfgBlock, null, null, 0, false, ...$run[2]);
        }
    }

    /**
     * php-cfg dead operands before branchIf run before any successor; skip inside inlined
     * includes so template locals survive layout title-branch partial includes (#784, #764).
     */
    private function shouldFreeDeadVariablesBeforeBranch(): bool
    {
        return 0 === $this->context->inlineIncludeDepth;
    }

    /** List-unpack merge that inlines an include still needs assign-block locals (#846). */
    private function mergeBlockInheritsCallerLocals(?Block $mergeBlock): bool
    {
        if (null === $mergeBlock) {
            return false;
        }
        foreach ($mergeBlock->opCodes as $op) {
            if (OpCode::TYPE_INCLUDE === $op->type) {
                return true;
            }
        }

        return false;
    }

    private function branchJumpMergeBlock(?Block $branch): ?Block
    {
        if (null === $branch) {
            return null;
        }
        foreach ($branch->opCodes as $branchOp) {
            if (OpCode::TYPE_JUMP === $branchOp->type) {
                return $branchOp->block1;
            }
        }

        return null;
    }

    /** Both ?: arms jump to a merge block ending in RETURN (#4280, #8555). */
    private function jumpIfTargetsReturnMerge(?Block $ifBlock, ?Block $elseBlock): bool
    {
        $ifMerge = $this->branchJumpMergeBlock($ifBlock);
        $elseMerge = $this->branchJumpMergeBlock($elseBlock);
        if (null === $ifMerge || $ifMerge !== $elseMerge) {
            return false;
        }
        $phi = $this->ternaryReturnPhiOperand($ifMerge);
        if (null === $phi) {
            return false;
        }
        if (!$this->branchIsTernaryReturnMergeArm($ifBlock) && !$this->branchIsTernaryReturnMergeArm($elseBlock)) {
            return false;
        }
        // Switch-as-JUMPIF chains share a post-switch merge RETURN but do not assign into
        // the ?: phi before breaking; require an arm assign (#878).
        return null !== $this->ternaryPhiAssignSourceOperand($ifBlock, $ifMerge)
            || null !== $this->ternaryPhiAssignSourceOperand($elseBlock, $ifMerge);
    }

    /**
     * False when a JUMPIF arm has switch/call/echo side effects before its merge JUMP (#878).
     */
    private function branchIsTernaryReturnMergeArm(?Block $branch): bool
    {
        if (null === $branch) {
            return false;
        }
        foreach ($branch->opCodes as $branchOp) {
            if (OpCode::TYPE_JUMP === $branchOp->type) {
                break;
            }
            if (
                OpCode::TYPE_FUNCCALL_EXEC_NORETURN === $branchOp->type
                || OpCode::TYPE_FUNCCALL_EXEC_RETURN === $branchOp->type
                || OpCode::TYPE_METHODCALL_INIT === $branchOp->type
                || OpCode::TYPE_STATICCALL_INIT === $branchOp->type
                || OpCode::TYPE_ECHO === $branchOp->type
            ) {
                return false;
            }
        }

        return true;
    }

    private function ternaryReturnPhiOperand(Block $mergeBlock): ?Operand
    {
        foreach ($mergeBlock->opCodes as $mergeOp) {
            if (OpCode::TYPE_RETURN === $mergeOp->type && null !== $mergeOp->arg1) {
                return $mergeBlock->getOperand($mergeOp->arg1);
            }
        }

        return null;
    }

    /** True when the branch assigns a string into the shared ?: phi (#8555). */
    private function branchAssignsStringToTernaryPhi(Block $branch, Block $mergeBlock): bool
    {
        $source = $this->ternaryPhiAssignSourceOperand($branch, $mergeBlock);
        if (null === $source) {
            return false;
        }

        return Variable::TYPE_STRING === Variable::getTypeFromType($source->type)
            || $this->operandTypeIncludesString($source);
    }

    /**
     * True when the branch assigns only null into the shared ?: phi (#8555).
     */
    private function branchAssignsOnlyNullToTernaryPhi(Block $branch, Block $mergeBlock): bool
    {
        $source = $this->ternaryPhiAssignSourceOperand($branch, $mergeBlock);
        if (null === $source) {
            return false;
        }

        return Variable::TYPE_NULL === Variable::getTypeFromType($source->type);
    }

    private function ternaryPhiAssignSourceOperand(Block $branch, Block $mergeBlock): ?Operand
    {
        $phi = $this->ternaryReturnPhiOperand($mergeBlock);
        if (null === $phi) {
            return null;
        }
        $phiSlot = $mergeBlock->slotForOperand($phi);
        if (null === $phiSlot) {
            return null;
        }
        foreach ($branch->opCodes as $branchOp) {
            if (OpCode::TYPE_ASSIGN !== $branchOp->type) {
                continue;
            }
            $destSlot = $branch->slotForOperand($branch->getOperand($branchOp->arg1));
            $aliasSlot = $branch->slotForOperand($branch->getOperand($branchOp->arg2));
            if ($destSlot !== $phiSlot && $aliasSlot !== $phiSlot) {
                continue;
            }

            return $branch->getOperand($branchOp->arg3);
        }

        return null;
    }

    private function operandTypeIncludesString(Operand $op): bool
    {
        $type = $op->type;
        if (null === $type) {
            return false;
        }
        if (\PHPTypes\Type::TYPE_STRING === $type->type) {
            return true;
        }
        foreach ($type->subTypes ?? [] as $sub) {
            if (\PHPTypes\Type::TYPE_STRING === ($sub->type ?? null)) {
                return true;
            }
        }

        return false;
    }

    /** True when the branch only assigns null into the shared ?: phi (#8555). */
    private function branchAssignsNullToTernaryPhi(Block $branch, Block $mergeBlock): bool
    {
        $phi = $this->ternaryReturnPhiOperand($mergeBlock);
        if (null === $phi) {
            return false;
        }
        $phiSlot = $mergeBlock->slotForOperand($phi);
        if (null === $phiSlot) {
            return false;
        }
        if ($this->branchAssignsStringToTernaryPhi($branch, $mergeBlock)) {
            return false;
        }
        foreach ($branch->opCodes as $branchOp) {
            if (OpCode::TYPE_ASSIGN !== $branchOp->type) {
                continue;
            }
            $destSlot = $branch->slotForOperand($branch->getOperand($branchOp->arg1));
            $aliasSlot = $branch->slotForOperand($branch->getOperand($branchOp->arg2));
            if ($destSlot !== $phiSlot && $aliasSlot !== $phiSlot) {
                continue;
            }

            return true;
        }

        return false;
    }

    /**
     * @return array{0: Block, 1: Block} compile order (non-string arm first)
     */
    private function ternaryReturnMergeCompileOrder(Block $ifBlock, Block $elseBlock, Block $mergeBlock): array
    {
        $ifString = $this->branchAssignsStringToTernaryPhi($ifBlock, $mergeBlock);
        $elseString = $this->branchAssignsStringToTernaryPhi($elseBlock, $mergeBlock);
        if ($ifString && !$elseString) {
            return [$elseBlock, $ifBlock];
        }
        if ($elseString && !$ifString) {
            return [$ifBlock, $elseBlock];
        }

        return [$ifBlock, $elseBlock];
    }

    private function ternaryArmAssignSourceVariable(Block $armBlock, Block $mergeBlock): ?Variable
    {
        $source = $this->ternaryPhiAssignSourceOperand($armBlock, $mergeBlock);
        if (null === $source) {
            return null;
        }
        if (
            Variable::TYPE_NULL === Variable::getTypeFromType($source->type)
            && !$this->operandTypeIncludesString($source)
        ) {
            return null;
        }

        return $this->context->getVariableFromOp($source);
    }

    /**
     * Lower CFG RETURN for a shared ?: phi at an arm tail (issue #8555).
     */
    private function emitCfgReturnOperand(
        PHPLLVM\Value\Function_ $func,
        Block $cfgBlock,
        Operand $returnOperand,
        PHPLLVM\BasicBlock $tailBlock,
        ?Variable $returnValue = null
    ): void {
        if (null !== $tailBlock->getTerminator()) {
            return;
        }
        if (null !== $returnValue) {
            $return = $returnValue;
        } else {
            $bound = $this->context->functionScopeBindingVariable($returnOperand, $cfgBlock);
            if (null !== $bound) {
                $return = $bound;
            } else {
                $return = $this->context->getVariableFromOp($returnOperand);
            }
        }
        $builder = $this->context->builder;
        $builder->positionAtEnd($tailBlock);
        $this->markJitThisConstructedIfLeavingConstruct($cfgBlock);
        if (
            0 === $this->context->inlineIncludeDepth
            && JIT\TryCatchHelper::deferReturnIfNeeded($this, $this->context, $func, $cfgBlock, false, $return)
        ) {
            return;
        }
        if ($cfgBlock->returnTypeVoid) {
            JIT\Builtin\TypeErrorRaise::registerDeclarations($this->context);
            JIT\Builtin\TypeErrorRaise::ensureLinked($this->context);
            JIT\Builtin\TypeErrorRaise::emitRaise(
                $this->context,
                'A void function must not return a value'
            );

            return;
        }
        $return->addref();
        if (null !== $cfgBlock->returnDnfConstraints) {
            JIT\DnfParamCheck::enforce(
                $this->context,
                $return,
                $cfgBlock->returnDnfConstraints,
                'Return value'
            );
        }
        $retval = $this->context->helper->loadValue($return);
        $expected = $this->cfgFunctionReturnCallbackType($cfgBlock->func);
        if (null === $expected && null !== $this->context->activeFunction) {
            $expected = $this->context->functionReturnType[strtolower($this->context->activeFunction)] ?? null;
        }
        $retval = $this->coerceReturnValue($return, $retval, $expected);
        $retval = $this->alignRetvalToLlvmFnReturn($retval, $func);
        // Arm-tail ?: returns must not use merge-block dead operands — they free branch
        // locals (e.g. string params) before coerceReturnValue finishes (#8555).
        if ($this->isVoidLlvmFunction($func)) {
            $builder->returnVoid();
        } elseif ($this->cfgFunctionReturnsByRef($cfgBlock->func)) {
            $builder->returnValue(
                JIT\JitValueBox::valuePtrFromVariable($this->context, $return)
            );
        } else {
            $builder->returnValue($retval);
        }
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
        if ($this->context->inlineIncludeDepth > 0) {
            JIT\IncludeHelper::refreshInlineIncludeBindings($this->context);
        }
    }

    /** Branch targets must use CFG entry BBs, not include-updated resume slots (#866, #878). */
    private function jitBranchEntryBlock(Block $branch): PHPLLVM\BasicBlock
    {
        if ($this->context->scope->blockEntryStorage->contains($branch)) {
            return $this->context->scope->blockEntryStorage[$branch];
        }

        return $this->context->scope->blockStorage[$branch];
    }

    /** Self-host AOT sets PHP_COMPILER_SELFHOST_AOT=1 (#816, #557). */
    private function shouldUseSelfHostJitStubs(): bool
    {
        $flag = getenv('PHP_COMPILER_SELFHOST_AOT');

        return '1' === $flag || 'true' === strtolower((string) $flag);
    }

    /** User script AOT via bin/compile.php: real closure lowering (#3725). */
    private function shouldStubClosureLowering(): bool
    {
        $userScript = getenv('PHP_COMPILER_AOT_USER_SCRIPT');
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
        $flag = getenv('PHP_COMPILER_EMIT_HELPER_LINK');

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
        $flag = getenv('PHP_COMPILER_VENDOR_PRELINK');

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
        if ('1' === (string) getenv('PHP_COMPILER_M3_EMIT_SIDECAR_RECURSION_GUARD')) {
            return false;
        }
        if ('1' === (string) getenv('PHP_COMPILER_M3_EMIT_TU')) {
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
        $keep = getenv('PHP_COMPILER_KEEP_OBJECT_FILE');

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
        $selfhost = getenv('PHP_COMPILER_SELFHOST_AOT');

        return '1' === $selfhost || 'true' === strtolower((string) $selfhost);
    }

    /** Gen-0 argv driver + self-host link: real-lower standalone when not vendor-prelink (#3053). */
    private function shouldUseSelfHostExecutableEmit(): bool
    {
        if ($this->shouldUseVendorPrelinkJitStubs()) {
            return false;
        }
        $selfhost = getenv('PHP_COMPILER_SELFHOST_AOT');

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
        $flag = getenv('PHP_COMPILER_M3_COMPILE_DRIVER_MAIN');

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
        return $this->shouldUseM3InventoryEmitDriver() && $this->shouldUseM3CompileDriverRealLowering();
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
        $flag = getenv('PHP_COMPILER_M5_DRIVER_HOST');

        return '1' === $flag || 'true' === strtolower((string) $flag);
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
        $flag = getenv('PHP_COMPILER_M4_BIN_COMPILE_DRIVER');

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
        if (!$this->shouldUseSelfHostJitStubs()) {
            return false;
        }
        $flag = getenv('PHP_COMPILER_M3_COMPILE_DRIVER');

        return '1' === $flag || 'true' === strtolower((string) $flag);
    }

    /** Emit native entry TU only — not compile_driver bundles that include compile_smoke_m3_emit (#1937). */
    private function shouldUseM3EmitTuNativeBridge(): bool
    {
        // Inventory emit links compile_driver.php {main} via the same bridge as helloworld_m3_emit (#2843).
        if ($this->shouldUseM3InventoryEmitDriver() && $this->shouldUseEmitHelperLinkStubs()) {
            return true;
        }
        $flag = getenv('PHP_COMPILER_M3_EMIT_TU');

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
            || str_ends_with($lower, '\\runtime::rewritesourcebeforeparser')
            || str_ends_with($lower, '\\runtime::parse')) {
            return !$this->shouldStubInventoryEmitParseCompileSpine();
        }
        if (str_ends_with($lower, '\\runtime::compileemitsmoke')) {
            return true;
        }
        if (str_ends_with($lower, '\\runtime::parseandcompileemitsmoke')) {
            return true;
        }
        if (str_ends_with($lower, '\\runtime::compile')) {
            return true;
        }
        if (str_ends_with($lower, '\\runtime::loadjit')) {
            return true;
        }
        if (str_ends_with($lower, '\\runtime::initvmcontext')) {
            return true;
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
     * LLVM 9 crashes lowering these during M3 compile-driver link; keep stubbed until fixed (#1402).
     *
     * @return list<string> lowercase name fragments
     */
    private function m3CompileDriverSpineDenyNames(): array
    {
        return [
            // Full emit FUNCDEF LLVM 9 link crash (#1514); inline emit in compile_driver compile mode (#1983).
            '\\bootstrapaot\\helloworld_compile_smoke',
        ];
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
                if ($this->shouldUseM3EmitTuRuntimeMethodStub('parse')) {
                    return $this->emitM3EmitTuRuntimeParseStubNative($internalName, $logicalName, $block);
                }

                return $this->compileRuntimeSpinePhpLowering($internalName, $block, $logicalName);
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
        $args = [];
        $rawTypes = [];
        $argVars = [];
        $returnsByRef = false;
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
            $returnType = $this->context->getTypeFromString($callbackType);
            $this->context->functionReturnType[strtolower($logicalName ?? $internalName)] = $callbackType;

            if ($this->instanceMethodUsesThis($block)) {
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

        $func = $this->context->module->addFunction(
            $internalName,
            $this->context->context->functionType(
                $returnType,
                $isVarArgs,
                ...$args
            )
        );

        $cfgParamCount = null !== $block->func ? count($block->func->params) : 0;
        foreach ($args as $idx => $arg) {
            $varType = Variable::getTypeFromType($rawTypes[$idx]);
            if ($idx < $cfgParamCount && $block->func->params[$idx]->variadic) {
                $varType = Variable::TYPE_HASHTABLE;
            }
            $argVars[] = new Variable($this->context, $varType, Variable::KIND_VALUE, $func->getParam($idx));
        }

        $lcname = strtolower($logicalName ?? $internalName);
        $this->context->functions[$lcname] = $func;
        $this->context->activeFunction = $lcname;
        if (!is_null($funcName)) {
            $lcname = strtolower($funcName);
            $this->context->activeFunction = $lcname;
            $this->context->functions[$lcname] = $func;
            if ($isVarArgs) {
                $this->context->functionProxies[$lcname] = new JIT\Call\Vararg($func, $funcName, count($args));
            } else {
                $defaultArgs = $this->collectParamDefaults($block);
                $variadicArgIndex = null;
                if (null !== $block->variadicParamIndex) {
                    $variadicArgIndex = $block->variadicParamIndex;
                    if ($this->instanceMethodUsesThis($block)) {
                        ++$variadicArgIndex;
                    }
                }
                $this->context->functionProxies[$lcname] = new JIT\Call\Native(
                    $func,
                    $funcName,
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
                    $this->paramImplicitNullableForNativeCall($block)
                );
                JIT\NoDiscardCallGuard::registerCallee($this->context, $funcName, $block);
            }
            if ($returnsByRef) {
                $this->markFunctionReturnsByRef($lcname, $funcName ?? '');
            }
        }

        $this->queue[] = [$func, $block, $argVars];
        if ($callbackType === 'void(*)()' && !Block::containsNonLiteralEvalOpcodes($block)) {
            $this->context->addExport($internalName, $callbackType, $block);
        }
        return $func;
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
        $flag = getenv('PHP_COMPILER_M3_EMIT_HELPER_SPINE');

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
        if ($this->shouldUseM3EmitTuRuntimeMethodStub('initparsepipeline')) {
            return $this->emitM3EmitTuRuntimeInitVoidStub($internalName, $logicalName, $block);
        }
        $lcname = strtolower($logicalName);
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
        if ($param->byRef) {
            return $this->context->getTypeFromString('__value__*');
        }
        if ($param->variadic) {
            return $this->context->getTypeFromString('__hashtable__*');
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
        if (null !== $declared && $this->isCfgObjectIdentityParamType($declared)) {
            return $this->context->getTypeFromString('__object__*');
        }
        $rawType = $this->rawTypeFromCfgParam($param);
        if ($this->isCfgObjectIdentityParamType($rawType)) {
            return $this->context->getTypeFromString('__object__*');
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
        $logPrefix = getenv('PHP_COMPILER_M3_EMIT_LOG_PREFIX');
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
        $logPrefix = getenv('PHP_COMPILER_M3_EMIT_LOG_PREFIX');
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
        $logPrefix = getenv('PHP_COMPILER_M3_EMIT_LOG_PREFIX');
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
        ) {
            return;
        }
        $this->ensureM3EmitTuEmitBridgeSpineSymbols();
        $savedClassId = $this->context->scope->classId;
        $savedClassName = $this->context->scope->className;
        $this->context->scope->classId = $this->context->type->object->lookup('PHPCompiler\\Runtime');
        $this->context->scope->className = 'phpcompiler\\runtime';
        $forceRealParseSpine = $this->shouldRealLowerInventoryArgvParseSpine();
        if ($forceRealParseSpine) {
            foreach (['preprocesssourceforparse', 'rewritesourcebeforeparser', 'preparesourceforparser', 'parse', 'compileemitsmoke'] as $spineLc) {
                $spineLcKey = strtolower('PHPCompiler\\Runtime::'.$spineLc);
                unset(
                    $this->context->functions[$spineLcKey],
                    $this->context->functionReturnType[$spineLcKey],
                    $this->context->functionProxies[$spineLcKey]
                );
            }
        }
        if (!$this->shouldUseM4InventoryArgvNativeEmitRebuild()) {
            foreach ([
                'preprocesssourceforparse',
                'rewritesourcebeforeparser',
                'preparesourceforparser',
                'parse',
                'compileemitsmoke',
            ] as $spineLc) {
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
        if (!$this->shouldUseM4InventoryArgvNativeEmitRebuild()) {
            $this->runQueue();
        }
        $stubBlock = $this->m3EmitTuMainBlock ?? $this->m3CompileDriverMainBlock;
        foreach (['parse', 'compileemitsmoke'] as $spineLc) {
            $spineLogical = 'PHPCompiler\\Runtime::'.$spineLc;
            $spineLcKey = strtolower($spineLogical);
            if (isset($this->context->functions[$spineLcKey]) || null === $stubBlock) {
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
        $this->context->scope->classId = $savedClassId;
        $this->context->scope->className = $savedClassName;
    }

    /**
     * Pre-lower emit spine before native emit bridge (#2550, #2559).
     *
     * Compile-driver path: host-lowers Runtime::__construct/parse/compileEmitSmoke from modules.
     * Emit-helper path: link-time trivial-echo AOT sidecar for parseAndCompile* / standalone.
     */
    private function compileM3EmitTuRuntimeSpineMethodsForRealLowering(): void
    {
        $sidecar = $this->isM3EmitTuTrivialEchoSidecarActive();
        if (!$this->shouldUseM3CompileDriverRealLowering()) {
            return;
        }
        $this->ensureM3EmitTuCompilerRuntimeCompileDeps();
        $this->ensureM3EmitTuRuntimeParseSpineDeps();
        $emitHelperStubMethods = $this->shouldStubInventoryEmitParseCompileSpine()
            ? ['parse', 'preparesourceforparser', 'preprocesssourceforparse', 'rewritesourcebeforeparser', 'compileemitsmoke']
            : [];
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
        if ($sidecar && null !== $this->m3EmitTuMainBlock) {
            $this->emitM3EmitTuRuntimeStandaloneStubNative(
                $this->llvmInternalName('PHPCompiler\\Runtime::standalone'),
                'PHPCompiler\\Runtime::standalone',
                $this->m3EmitTuMainBlock
            );
        }
        if (!$this->shouldUseM4InventoryArgvNativeEmitRebuild()) {
            $this->runQueue();
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

    /** Private Runtime helpers required before lowering parse() on inventory argv links (#2967). */
    private function ensureM3EmitTuRuntimeParseSpineDeps(): void
    {
        if (!$this->shouldRealLowerInventoryArgvParseSpine()) {
            return;
        }
        foreach (['detectfilestricttypes', 'resetparsernameresolverstate'] as $methodLc) {
            $logical = 'PHPCompiler\\Runtime::'.$methodLc;
            $lc = strtolower($logical);
            if (isset($this->context->functions[$lc])) {
                continue;
            }
            $this->compileM3EmitTuRuntimeMethodFromRuntimePhpFile($methodLc, $logical, $lc);
            if (!isset($this->context->functions[$lc])) {
                $this->compileM3EmitTuRuntimeMethodFromDeclareClassBlocks([$methodLc]);
            }
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
     * Live repo root for M3 sidecar registration (#3046, #3012).
     *
     * Self-host AOT may bake compile-time {@see __DIR__} as /compiler/lib from Docker/prelinked gen-0;
     * walk env/cwd/markers so gen-2→gen-3 links register host-relative sidecar paths.
     */
    private function m3EmitTuRuntimeRepoRoot(): string
    {
        static $resolved = null;
        if (is_string($resolved) && '' !== $resolved) {
            return $resolved;
        }
        $fromEnv = getenv('PHP_COMPILER_REPO_ROOT');
        if (is_string($fromEnv) && '' !== $fromEnv) {
            $real = realpath($fromEnv);
            if (false !== $real && is_readable($real.'/bin/compile.php') && is_readable($real.'/lib/JIT.php')) {
                return $resolved = str_replace('\\', '/', $real);
            }
        }
        /** @var list<string> $candidates */
        $candidates = [];
        $cwd = getcwd();
        if (is_string($cwd) && '' !== $cwd) {
            $candidates[] = $cwd;
        }
        if (is_string($this->context->m3EmitTuTrivialEchoPath ?? null) && '' !== $this->context->m3EmitTuTrivialEchoPath) {
            $candidates[] = dirname($this->context->m3EmitTuTrivialEchoPath);
        }
        $candidates[] = dirname(__DIR__);
        $seen = [];
        foreach ($candidates as $start) {
            if (!is_string($start) || '' === $start || isset($seen[$start])) {
                continue;
            }
            $seen[$start] = true;
            $dir = str_replace('\\', '/', $start);
            for ($depth = 0; $depth < 16; ++$depth) {
                if (is_readable($dir.'/bin/compile.php') && is_readable($dir.'/lib/JIT.php')) {
                    $real = realpath($dir);

                    return $resolved = false !== $real ? str_replace('\\', '/', $real) : $dir;
                }
                $parent = dirname($dir);
                if ($parent === $dir) {
                    break;
                }
                $dir = $parent;
            }
        }
        $fallback = str_replace('\\', '/', dirname(__DIR__));
        $real = realpath($fallback);

        return $resolved = false !== $real ? str_replace('\\', '/', $real) : $fallback;
    }

    private function m3EmitTuRepoPath(string $relativePath): string
    {
        return $this->m3EmitTuRuntimeRepoRoot().'/'.ltrim(str_replace('\\', '/', $relativePath), '/');
    }

    /** Host-compile emit-helper probe source and cache linked AOT bytes at link time (#2559, #2567, #2618). */
    /** Default-on fast inventory link: argv driver only needs compiler_minimal-scale sidecars (#1492). */
    private function shouldUseM3InventoryMinimalSidecars(): bool
    {
        foreach (['BOOTSTRAP_INVENTORY_DRIVER_FULL', 'PHP_COMPILER_M3_INVENTORY_FULL_SIDECARS'] as $fullKey) {
            $full = getenv($fullKey);
            if ('1' === $full || 'true' === strtolower((string) $full)) {
                return false;
            }
        }
        foreach (['PHP_COMPILER_M3_INVENTORY_MINIMAL_SIDECARS', 'BOOTSTRAP_INVENTORY_MINIMAL_SIDECARS'] as $envKey) {
            $v = getenv($envKey);
            if ('0' === $v || 'false' === strtolower((string) $v)) {
                return false;
            }
        }

        return true;
    }

    private function m3EmitTuForceSidecarHostCompile(): bool
    {
        foreach (['BOOTSTRAP_FORCE_COMPILER_LIB_SIDECAR_REGEN', 'PHP_COMPILER_M3_FORCE_SIDECAR_HOST_COMPILE'] as $envKey) {
            $v = getenv($envKey);
            if ('1' === $v || 'true' === strtolower((string) $v)) {
                return true;
            }
        }

        return false;
    }

    /** Reuse committed compiler_lib sidecar without honest stamp match (skip multi-hour host-compile — #8703). */
    private function m3EmitTuReuseStaleCompilerLibSidecar(): bool
    {
        if ($this->m3EmitTuForceSidecarHostCompile()) {
            return false;
        }
        if ($this->shouldUseM3InventoryMinimalSidecars()) {
            return true;
        }
        foreach (['PHP_COMPILER_M3_REUSE_STALE_COMPILER_LIB_SIDECAR', 'BOOTSTRAP_ALLOW_STALE_SIDECAR'] as $envKey) {
            $v = getenv($envKey);
            if ('1' === $v || 'true' === strtolower((string) $v)) {
                return true;
            }
        }

        return true;
    }

    /**
     * @return list<string> repo-relative sidecar paths to try for stale compiler_lib reuse
     */
    private function m3EmitTuCompilerLibSidecarFallbackPaths(string $repoRoot): array
    {
        return [
            $repoRoot.'/build/.m3_compiler_lib_aot_blob',
            $repoRoot.'/prelinked/bootstrap-gen0/compiler_lib_aot_blob',
        ];
    }

    private function m3EmitTuTryRegisterStaleCompilerLibSidecar(
        string $path,
        string $sidecarRel,
        string $sentinelLogical,
        string $code,
        string $repoRoot
    ): bool {
        if (\PHPCompiler\JIT\M3EmitTuTrivialEchoAot::COMPILER_LIB_SIDECAR_REL !== $sidecarRel
            || !$this->m3EmitTuReuseStaleCompilerLibSidecar()) {
            return false;
        }
        foreach ($this->m3EmitTuCompilerLibSidecarFallbackPaths($repoRoot) as $blobPath) {
            if (!is_readable($blobPath)) {
                continue;
            }
            $aotBytes = file_get_contents($blobPath);
            if (!is_string($aotBytes) || '' === $aotBytes) {
                continue;
            }
            \PHPCompiler\JIT\M3EmitTuTrivialEchoAot::registerLinktime(
                $this->context,
                $repoRoot,
                $code,
                $aotBytes,
                $sidecarRel,
                $sentinelLogical,
                true,
                $this->m3EmitTuSidecarSourcePathNorm($path)
            );

            return true;
        }

        return false;
    }

    /**
     * @return list<string> candidate blob paths for an existing sidecar (build/ then prelinked gen-0)
     */
    private function m3EmitTuExistingSidecarBlobPaths(string $repoRoot, string $sidecarRel): array
    {
        $paths = [$repoRoot.'/'.ltrim($sidecarRel, '/')];
        $base = basename($sidecarRel);
        $prelinkedDir = $repoRoot.'/prelinked/bootstrap-gen0';
        if (\PHPCompiler\JIT\M3EmitTuTrivialEchoAot::BIN_COMPILE_SIDECAR_REL === $sidecarRel) {
            $paths[] = $prelinkedDir.'/bin-compile-aot';
            $paths[] = $prelinkedDir.'/.m3_bin_compile_aot_blob';
        } elseif (\PHPCompiler\JIT\M3EmitTuTrivialEchoAot::COMPILER_MINIMAL_SIDECAR_REL === $sidecarRel) {
            $paths[] = $prelinkedDir.'/compiler_minimal_aot_blob';
        } elseif (\PHPCompiler\JIT\M3EmitTuTrivialEchoAot::COMPILER_LIB_SIDECAR_REL === $sidecarRel) {
            $paths = array_merge($paths, $this->m3EmitTuCompilerLibSidecarFallbackPaths($repoRoot));
        } elseif (is_dir($prelinkedDir)) {
            $paths[] = $prelinkedDir.'/'.$base;
            if (str_starts_with($base, '.m3_')) {
                $paths[] = $prelinkedDir.'/'.substr($base, 4);
            }
        }

        return array_values(array_unique($paths));
    }

    private function m3EmitTuTryRegisterExistingSidecarBlob(
        string $path,
        string $sidecarRel,
        string $sentinelLogical,
        string $code,
        string $repoRoot
    ): bool {
        if ($this->m3EmitTuForceSidecarHostCompile()) {
            return false;
        }
        if (\PHPCompiler\JIT\M3EmitTuTrivialEchoAot::COMPILE_DRIVER_SIDECAR_REL === $sidecarRel
            && $this->isM3HelloworldInventoryCompileDriverTarget($this->m3CompileDriverMainBlock)) {
            return false;
        }
        if (\PHPCompiler\JIT\M3EmitTuTrivialEchoAot::COMPILER_LIB_SIDECAR_REL === $sidecarRel) {
            return $this->m3EmitTuTryRegisterStaleCompilerLibSidecar($path, $sidecarRel, $sentinelLogical, $code, $repoRoot);
        }
        foreach ($this->m3EmitTuExistingSidecarBlobPaths($repoRoot, $sidecarRel) as $blobPath) {
            if (!is_readable($blobPath)) {
                continue;
            }
            $aotBytes = file_get_contents($blobPath);
            if (!is_string($aotBytes) || '' === $aotBytes) {
                continue;
            }
            if ($this->m3EmitTuPrelinkedSidecarLooksStale($aotBytes)
                && \PHPCompiler\JIT\M3EmitTuTrivialEchoAot::BIN_COMPILE_SIDECAR_REL !== $sidecarRel) {
                continue;
            }
            \PHPCompiler\JIT\M3EmitTuTrivialEchoAot::registerLinktime(
                $this->context,
                $repoRoot,
                $code,
                $aotBytes,
                $sidecarRel,
                $sentinelLogical,
                true,
                $this->m3EmitTuSidecarSourcePathNorm($path)
            );

            return true;
        }

        return false;
    }

    private function cacheM3EmitTuTrivialEchoAtLinkTime(): void
    {
        if ($this->m3EmitTuSidecarsCached) {
            return;
        }
        $this->m3EmitTuSidecarsCached = true;
        $repoRoot = $this->m3EmitTuRuntimeRepoRoot();
        $logPrefix = getenv('PHP_COMPILER_M3_EMIT_LOG_PREFIX');
        if ('helloworld_compile_smoke' === $logPrefix) {
            $minimalSidecars = $this->shouldUseM3InventoryMinimalSidecars();
            $this->registerM3EmitTuSidecarFromPath(
                $repoRoot.'/examples/000-HelloWorld/example.php',
                \PHPCompiler\JIT\M3EmitTuTrivialEchoAot::HELLOWORLD_SIDECAR_REL,
                'PHPCompiler\\JIT\\M3EmitTuTrivialEchoAot::helloworldSentinelBlock'
            );
            $this->registerM3EmitTuSidecarFromPath(
                $repoRoot.'/test/bootstrap-aot/compiler_smoke_standalone.php',
                \PHPCompiler\JIT\M3EmitTuTrivialEchoAot::COMPILE_SMOKE_SIDECAR_REL,
                'PHPCompiler\\JIT\\M3EmitTuTrivialEchoAot::compileSmokeSentinelBlock'
            );
            if (!$minimalSidecars) {
                // Gen-3 argv driver (full revision) must be able to emit non-smoke fixtures (eg compiler unit probe)
                // without falling back to compile_smoke_m3_emit helpers (#2900, #2925).
                $this->registerM3EmitTuSidecarFromPath(
                    $repoRoot.'/test/selfhost/compiler_unit_probe/compiler_unit_probe_compile.php',
                    \PHPCompiler\JIT\M3EmitTuTrivialEchoAot::COMPILER_UNIT_PROBE_SIDECAR_REL,
                    'PHPCompiler\\JIT\\M3EmitTuTrivialEchoAot::compilerUnitProbeSentinelBlock'
                );
                $this->registerM3EmitTuSidecarFromPath(
                    $repoRoot.'/test/selfhost/compiler_helloworld_smoke/compile_driver.php',
                    \PHPCompiler\JIT\M3EmitTuTrivialEchoAot::COMPILE_DRIVER_SIDECAR_REL,
                    'PHPCompiler\\JIT\\M3EmitTuTrivialEchoAot::compileDriverSentinelBlock'
                );
                $this->registerM3EmitTuSidecarFromPath(
                    $repoRoot.'/test/selfhost/compiler_helloworld_smoke/main.php',
                    \PHPCompiler\JIT\M3EmitTuTrivialEchoAot::HELLOWORLD_SMOKE_MAIN_SIDECAR_REL,
                    'PHPCompiler\\JIT\\M3EmitTuTrivialEchoAot::helloworldSmokeMainSentinelBlock'
                );
                $this->registerM3EmitTuSidecarFromPath(
                    $repoRoot.'/test/selfhost/bootstrap_loop_smoke/main.php',
                    \PHPCompiler\JIT\M3EmitTuTrivialEchoAot::BOOTSTRAP_LOOP_SMOKE_MAIN_SIDECAR_REL,
                    'PHPCompiler\\JIT\\M3EmitTuTrivialEchoAot::bootstrapLoopSmokeMainSentinelBlock'
                );
            }
            $this->registerM3EmitTuSidecarFromPath(
                $repoRoot.'/test/selfhost/compiler_minimal/main.php',
                \PHPCompiler\JIT\M3EmitTuTrivialEchoAot::COMPILER_MINIMAL_SIDECAR_REL,
                'PHPCompiler\\JIT\\M3EmitTuTrivialEchoAot::compilerMinimalSentinelBlock'
            );
            // Minimal inventory argv links still compile spine smoke — reuse committed/stale sidecar (#3012, #2967).
            $this->registerM3EmitTuSidecarFromPath(
                $repoRoot.'/test/selfhost/compiler_lib_spine_smoke/main.php',
                \PHPCompiler\JIT\M3EmitTuTrivialEchoAot::COMPILER_LIB_SIDECAR_REL,
                'PHPCompiler\\JIT\\M3EmitTuTrivialEchoAot::compilerLibSentinelBlock',
                true
            );
            if (!$minimalSidecars) {
                $this->registerM3EmitTuSidecarFromPath(
                    $repoRoot.'/lib/Compiler.php',
                    \PHPCompiler\JIT\M3EmitTuTrivialEchoAot::COMPILER_PHP_SIDECAR_REL,
                    'PHPCompiler\\JIT\\M3EmitTuTrivialEchoAot::compilerPhpSentinelBlock'
                );
            }
            if (!$this->shouldUseM4InventoryArgvNativeEmitRebuild()) {
                $this->registerM3EmitTuSidecarFromPath(
                    $repoRoot.'/bin/compile.php',
                    \PHPCompiler\JIT\M3EmitTuTrivialEchoAot::BIN_COMPILE_SIDECAR_REL,
                    'PHPCompiler\\JIT\\M3EmitTuTrivialEchoAot::binCompileSentinelBlock'
                );
            }
            if (!$minimalSidecars) {
                $this->registerM3EmitTuSidecarFromPath(
                    $repoRoot.'/bin/vm.php',
                    \PHPCompiler\JIT\M3EmitTuTrivialEchoAot::BIN_VM_SIDECAR_REL,
                    'PHPCompiler\\JIT\\M3EmitTuTrivialEchoAot::binVmSentinelBlock',
                    true
                );
                $this->registerM3EmitTuSidecarFromPath(
                    $repoRoot.'/src/cli_driver.php',
                    \PHPCompiler\JIT\M3EmitTuTrivialEchoAot::CLI_DRIVER_SIDECAR_REL,
                    'PHPCompiler\\JIT\\M3EmitTuTrivialEchoAot::cliDriverSentinelBlock',
                    true
                );
            }
            // M5 vendor prelink bundles: Zend host-compile at emit-helper link (#3028, #3030, #3031).
            $this->registerM3EmitTuSidecarFromPath(
                $repoRoot.'/test/bootstrap-vendor-prelink/generated/ircmaxell-php-cfg_bundle.php',
                \PHPCompiler\JIT\M3EmitTuTrivialEchoAot::VENDOR_PHP_CFG_SIDECAR_REL,
                'PHPCompiler\\JIT\\M3EmitTuTrivialEchoAot::vendorPhpCfgSentinelBlock'
            );
            $this->registerM3EmitTuSidecarFromPath(
                $repoRoot.'/test/bootstrap-vendor-prelink/generated/ircmaxell-php-types_bundle.php',
                \PHPCompiler\JIT\M3EmitTuTrivialEchoAot::VENDOR_PHP_TYPES_SIDECAR_REL,
                'PHPCompiler\\JIT\\M3EmitTuTrivialEchoAot::vendorPhpTypesSentinelBlock'
            );
            $this->registerM3EmitTuSidecarFromPath(
                $repoRoot.'/test/bootstrap-vendor-prelink/generated/ircmaxell-php-llvm_bundle.php',
                \PHPCompiler\JIT\M3EmitTuTrivialEchoAot::VENDOR_PHP_LLVM_SIDECAR_REL,
                'PHPCompiler\\JIT\\M3EmitTuTrivialEchoAot::vendorPhpLlvmSentinelBlock'
            );
        } elseif ('compile_smoke_m3_emit' === $logPrefix || $this->shouldUseM3InventoryEmitDriver()) {
            $minimalSidecars = $this->shouldUseM3InventoryMinimalSidecars();
            $this->registerM3EmitTuSidecarFromPath(
                $repoRoot.'/examples/000-HelloWorld/example.php',
                \PHPCompiler\JIT\M3EmitTuTrivialEchoAot::HELLOWORLD_SIDECAR_REL,
                'PHPCompiler\\JIT\\M3EmitTuTrivialEchoAot::helloworldSentinelBlock'
            );
            $this->registerM3EmitTuSidecarFromPath(
                $repoRoot.'/test/bootstrap-aot/compiler_smoke_standalone.php',
                \PHPCompiler\JIT\M3EmitTuTrivialEchoAot::COMPILE_SMOKE_SIDECAR_REL,
                'PHPCompiler\\JIT\\M3EmitTuTrivialEchoAot::compileSmokeSentinelBlock'
            );
            $this->registerM3EmitTuSidecarFromPath(
                $repoRoot.'/test/selfhost/compiler_minimal/main.php',
                \PHPCompiler\JIT\M3EmitTuTrivialEchoAot::COMPILER_MINIMAL_SIDECAR_REL,
                'PHPCompiler\\JIT\\M3EmitTuTrivialEchoAot::compilerMinimalSentinelBlock'
            );
            $this->registerM3EmitTuSidecarFromPath(
                $repoRoot.'/test/selfhost/compiler_lib_spine_smoke/main.php',
                \PHPCompiler\JIT\M3EmitTuTrivialEchoAot::COMPILER_LIB_SIDECAR_REL,
                'PHPCompiler\\JIT\\M3EmitTuTrivialEchoAot::compilerLibSentinelBlock',
                true
            );
            if (!$minimalSidecars) {
                $this->registerM3EmitTuSidecarFromPath(
                    $repoRoot.'/test/selfhost/compiler_unit_probe/compiler_unit_probe_compile.php',
                    \PHPCompiler\JIT\M3EmitTuTrivialEchoAot::COMPILER_UNIT_PROBE_SIDECAR_REL,
                    'PHPCompiler\\JIT\\M3EmitTuTrivialEchoAot::compilerUnitProbeSentinelBlock'
                );
                $this->registerM3EmitTuSidecarFromPath(
                    $repoRoot.'/test/selfhost/jit_unit_probe/jit_unit_probe_compile.php',
                    \PHPCompiler\JIT\M3EmitTuTrivialEchoAot::JIT_UNIT_PROBE_SIDECAR_REL,
                    'PHPCompiler\\JIT\\M3EmitTuTrivialEchoAot::jitUnitProbeSentinelBlock'
                );
                $this->registerM3EmitTuSidecarFromPath(
                    $repoRoot.'/test/selfhost/jit_unit_probe/compile_driver.php',
                    \PHPCompiler\JIT\M3EmitTuTrivialEchoAot::JIT_UNIT_PROBE_COMPILE_DRIVER_SIDECAR_REL,
                    'PHPCompiler\\JIT\\M3EmitTuTrivialEchoAot::jitUnitProbeCompileDriverSentinelBlock'
                );
                $this->registerM3EmitTuSidecarFromPath(
                    $repoRoot.'/test/selfhost/compiler_helloworld_smoke/compile_driver.php',
                    \PHPCompiler\JIT\M3EmitTuTrivialEchoAot::COMPILE_DRIVER_SIDECAR_REL,
                    'PHPCompiler\\JIT\\M3EmitTuTrivialEchoAot::compileDriverSentinelBlock'
                );
                $this->registerM3EmitTuSidecarFromPath(
                    $repoRoot.'/test/selfhost/compiler_helloworld_smoke/main.php',
                    \PHPCompiler\JIT\M3EmitTuTrivialEchoAot::HELLOWORLD_SMOKE_MAIN_SIDECAR_REL,
                    'PHPCompiler\\JIT\\M3EmitTuTrivialEchoAot::helloworldSmokeMainSentinelBlock'
                );
                $this->registerM3EmitTuSidecarFromPath(
                    $repoRoot.'/test/selfhost/bootstrap_loop_smoke/main.php',
                    \PHPCompiler\JIT\M3EmitTuTrivialEchoAot::BOOTSTRAP_LOOP_SMOKE_MAIN_SIDECAR_REL,
                    'PHPCompiler\\JIT\\M3EmitTuTrivialEchoAot::bootstrapLoopSmokeMainSentinelBlock'
                );
                // M5 inventory emit via selfhost-helloworld-emit (#2666, #2681): mirror helloworld_compile_smoke branch.
                $this->registerM3EmitTuSidecarFromPath(
                    $repoRoot.'/lib/Compiler.php',
                    \PHPCompiler\JIT\M3EmitTuTrivialEchoAot::COMPILER_PHP_SIDECAR_REL,
                    'PHPCompiler\\JIT\\M3EmitTuTrivialEchoAot::compilerPhpSentinelBlock'
                );
            }
            if (!$this->shouldUseM4InventoryArgvNativeEmitRebuild()) {
                $this->registerM3EmitTuSidecarFromPath(
                    $repoRoot.'/bin/compile.php',
                    \PHPCompiler\JIT\M3EmitTuTrivialEchoAot::BIN_COMPILE_SIDECAR_REL,
                    'PHPCompiler\\JIT\\M3EmitTuTrivialEchoAot::binCompileSentinelBlock'
                );
            }
            if (!$minimalSidecars) {
                $this->registerM3EmitTuSidecarFromPath(
                    $repoRoot.'/bin/vm.php',
                    \PHPCompiler\JIT\M3EmitTuTrivialEchoAot::BIN_VM_SIDECAR_REL,
                    'PHPCompiler\\JIT\\M3EmitTuTrivialEchoAot::binVmSentinelBlock',
                    true
                );
                $this->registerM3EmitTuSidecarFromPath(
                    $repoRoot.'/src/cli_driver.php',
                    \PHPCompiler\JIT\M3EmitTuTrivialEchoAot::CLI_DRIVER_SIDECAR_REL,
                    'PHPCompiler\\JIT\\M3EmitTuTrivialEchoAot::cliDriverSentinelBlock',
                    true
                );
            }
            $this->registerM3EmitTuSidecarFromPath(
                $repoRoot.'/test/bootstrap-aot/runtime_trivial_echo.php',
                \PHPCompiler\JIT\M3EmitTuTrivialEchoAot::TRIVIAL_ECHO_SIDECAR_REL,
                'PHPCompiler\\JIT\\M3EmitTuTrivialEchoAot::sentinelBlock'
            );
            $this->registerM3EmitTuSidecarFromPath(
                $repoRoot.'/test/bootstrap-vendor-prelink/generated/ircmaxell-php-cfg_bundle.php',
                \PHPCompiler\JIT\M3EmitTuTrivialEchoAot::VENDOR_PHP_CFG_SIDECAR_REL,
                'PHPCompiler\\JIT\\M3EmitTuTrivialEchoAot::vendorPhpCfgSentinelBlock'
            );
            $this->registerM3EmitTuSidecarFromPath(
                $repoRoot.'/test/bootstrap-vendor-prelink/generated/ircmaxell-php-types_bundle.php',
                \PHPCompiler\JIT\M3EmitTuTrivialEchoAot::VENDOR_PHP_TYPES_SIDECAR_REL,
                'PHPCompiler\\JIT\\M3EmitTuTrivialEchoAot::vendorPhpTypesSentinelBlock'
            );
            $this->registerM3EmitTuSidecarFromPath(
                $repoRoot.'/test/bootstrap-vendor-prelink/generated/ircmaxell-php-llvm_bundle.php',
                \PHPCompiler\JIT\M3EmitTuTrivialEchoAot::VENDOR_PHP_LLVM_SIDECAR_REL,
                'PHPCompiler\\JIT\\M3EmitTuTrivialEchoAot::vendorPhpLlvmSentinelBlock'
            );
        } else {
            $this->registerM3EmitTuSidecarFromPath(
                $repoRoot.'/test/bootstrap-aot/runtime_trivial_echo.php',
                \PHPCompiler\JIT\M3EmitTuTrivialEchoAot::TRIVIAL_ECHO_SIDECAR_REL,
                'PHPCompiler\\JIT\\M3EmitTuTrivialEchoAot::sentinelBlock'
            );
        }
    }

    /**
     * Environment for nested bin/compile.php sidecar host-compiles (#2930).
     *
     * PHP CLI often leaves $_ENV empty while getenv() still sees exports from bootstrap scripts
     * (e.g. PHP_COMPILER_MEMORY_LIMIT=4096M). Without that, nested compiles default to 2G and OOM
     * during inventory argv link, which surfaces as exit 139.
     *
     * @param array<string, string> $overrides
     *
     * @return array<string, string>
     */
    private function m3EmitSidecarHostCompileEnv(array $overrides = []): array
    {
        $base = getenv();
        if (!is_array($base)) {
            $base = is_array($_ENV) ? $_ENV : [];
        }
        $memLimit = getenv('PHP_COMPILER_MEMORY_LIMIT');
        if (is_string($memLimit) && '' !== $memLimit && '-1' !== $memLimit) {
            $base['PHP_COMPILER_MEMORY_LIMIT'] = $memLimit;
        }

        return array_merge($base, $overrides);
    }

    /** Host-compile one probe source and register link-time AOT sidecar bytes (#2559, #2618). */
    private function registerM3EmitTuSidecarFromPath(
        string $path,
        string $sidecarRel,
        string $sentinelLogical,
        bool $sidecarHostStubNonLiteralIncludes = false
    ): void {
        $maxDepthRaw = getenv('PHP_COMPILER_M3_EMIT_SIDECAR_MAX_DEPTH');
        $maxDepth = is_string($maxDepthRaw) && '' !== $maxDepthRaw ? (int) $maxDepthRaw : 4;
        $depthRaw = getenv('PHP_COMPILER_M3_EMIT_SIDECAR_DEPTH');
        $depth = is_string($depthRaw) && '' !== $depthRaw ? (int) $depthRaw : 0;
        if ($depth >= $maxDepth) {
            throw new \LogicException(
                "m3-emit-tu sidecar host-compile exceeded max depth: depth={$depth} max={$maxDepth} sidecar={$sidecarRel} source={$path}"
            );
        }
        // Prevent unbounded sidecar recursion: a sidecar host-compile runs bin/compile.php, which would
        // otherwise register/host-compile additional sidecars again (hang in bootstrap-selfhost-helloworld).
        $guard = getenv('PHP_COMPILER_M3_EMIT_SIDECAR_RECURSION_GUARD');
        if ('1' === $guard || 'true' === strtolower((string) $guard)) {
            return;
        }
        if ($this->shouldSkipM3InventoryEmitDriverSelfSidecar($path)) {
            return;
        }
        if (\PHPCompiler\JIT\M3EmitTuTrivialEchoAot::BIN_COMPILE_SIDECAR_REL === $sidecarRel
            && $this->shouldUseM4InventoryArgvNativeEmitRebuild()) {
            return;
        }
        if (!is_readable($path)) {
            return;
        }
        $code = file_get_contents($path);
        if (!is_string($code) || '' === $code) {
            return;
        }
        if (null === $this->m3EmitTuTrivialEchoSource) {
            $this->m3EmitTuTrivialEchoSource = $code;
            $this->context->m3EmitTuTrivialEchoSource = $code;
            $this->context->m3EmitTuTrivialEchoPath = $path;
        }
        $repoRoot = $this->m3EmitTuRuntimeRepoRoot();
        $pathNorm = str_replace('\\', '/', $path);
        // M5 vendor bundles: Zend host-compile hits non-literal includes in php-cfg; reuse committed
        // prelinked .o at emit-helper link so native argv driver can sidecar-copy at runtime (#3028).
        if (str_contains($pathNorm, 'bootstrap-vendor-prelink/generated/')
            && preg_match('#/([^/]+)_bundle\\.php$#', $pathNorm, $vendorBundleMatch)
        ) {
            $vendorSlug = $vendorBundleMatch[1];
            $prelinkedObject = $repoRoot.'/prelinked/bootstrap-vendor/'.$vendorSlug.'.o';
            if (is_readable($prelinkedObject)) {
                $aotBytes = file_get_contents($prelinkedObject);
                if (is_string($aotBytes) && '' !== $aotBytes) {
                    \PHPCompiler\JIT\M3EmitTuTrivialEchoAot::registerLinktime(
                        $this->context,
                        $repoRoot,
                        $code,
                        $aotBytes,
                        $sidecarRel,
                        $sentinelLogical,
                        true
                    );

                    return;
                }
            }
        }
        $repoSidecar = $repoRoot.'/'.ltrim($sidecarRel, '/');
        if (is_readable($repoSidecar)) {
            $compilerLibSidecar = \PHPCompiler\JIT\M3EmitTuTrivialEchoAot::COMPILER_LIB_SIDECAR_REL === $sidecarRel;
            $compilerLibStampOk = !$compilerLibSidecar
                || $this->m3EmitTuCompilerLibSidecarStampUsable(
                    $repoRoot,
                    $sidecarRel,
                    $repoRoot.'/build/.m3_compiler_lib_sidecar.sha'
                );
            if ($compilerLibStampOk || ($compilerLibSidecar && $this->m3EmitTuReuseStaleCompilerLibSidecar())) {
                $aotBytes = file_get_contents($repoSidecar);
                if (is_string($aotBytes) && '' !== $aotBytes) {
                    \PHPCompiler\JIT\M3EmitTuTrivialEchoAot::registerLinktime(
                        $this->context,
                        $repoRoot,
                        $code,
                        $aotBytes,
                        $sidecarRel,
                        $sentinelLogical,
                        false,
                        $this->m3EmitTuSidecarSourcePathNorm($path)
                    );

                    return;
                }
            }
        }
        if (\PHPCompiler\JIT\M3EmitTuTrivialEchoAot::BIN_VM_SIDECAR_REL === $sidecarRel
            && $this->registerM3BinVmSidecarStubFallback($path, $sidecarRel, $sentinelLogical, $code, $repoRoot)) {
            return;
        }
        // Zend host-compile of bin/compile.php inventory argv driver SIGSEGVs — reuse committed gen-0 (#2930).
        if (\PHPCompiler\JIT\M3EmitTuTrivialEchoAot::BIN_COMPILE_SIDECAR_REL === $sidecarRel) {
            foreach (
                [
                    $repoRoot.'/build/.m3_bin_compile_aot_blob',
                    $repoRoot.'/prelinked/bootstrap-gen0/.m3_bin_compile_aot_blob',
                    $repoRoot.'/prelinked/bootstrap-gen0/bin-compile-aot',
                ] as $prelinkedBinCompile
            ) {
                if (!is_readable($prelinkedBinCompile)) {
                    continue;
                }
                $aotBytes = file_get_contents($prelinkedBinCompile);
                if (!is_string($aotBytes) || '' === $aotBytes) {
                    continue;
                }
                if ($this->m3EmitTuPrelinkedSidecarLooksStale($aotBytes)) {
                    continue;
                }
                \PHPCompiler\JIT\M3EmitTuTrivialEchoAot::registerLinktime(
                    $this->context,
                    $repoRoot,
                    $code,
                    $aotBytes,
                    $sidecarRel,
                    $sentinelLogical,
                    true,
                    $this->m3EmitTuSidecarSourcePathNorm($path)
                );

                return;
            }
        }
        if (\PHPCompiler\JIT\M3EmitTuTrivialEchoAot::COMPILER_MINIMAL_SIDECAR_REL === $sidecarRel) {
            $prelinkedMinimal = $repoRoot.'/prelinked/bootstrap-gen0/compiler_minimal_aot_blob';
            if (is_readable($prelinkedMinimal)) {
                $aotBytes = file_get_contents($prelinkedMinimal);
                if (is_string($aotBytes) && '' !== $aotBytes && !$this->m3EmitTuPrelinkedSidecarLooksStale($aotBytes)) {
                    \PHPCompiler\JIT\M3EmitTuTrivialEchoAot::registerLinktime(
                        $this->context,
                        $repoRoot,
                        $code,
                        $aotBytes,
                        $sidecarRel,
                        $sentinelLogical,
                        true
                    );

                    return;
                }
            }
        }
        if (\PHPCompiler\JIT\M3EmitTuTrivialEchoAot::COMPILER_LIB_SIDECAR_REL === $sidecarRel) {
            $prelinkedLib = $repoRoot.'/prelinked/bootstrap-gen0/compiler_lib_aot_blob';
            if (is_readable($prelinkedLib)) {
                $aotBytes = file_get_contents($prelinkedLib);
                if (is_string($aotBytes) && '' !== $aotBytes && !$this->m3EmitTuPrelinkedSidecarLooksStale($aotBytes)
                    && $this->m3EmitTuCompilerLibSidecarStampUsable(
                        $repoRoot,
                        $sidecarRel,
                        $repoRoot.'/prelinked/bootstrap-gen0/.m3_compiler_lib_sidecar.sha'
                    )) {
                    \PHPCompiler\JIT\M3EmitTuTrivialEchoAot::registerLinktime(
                        $this->context,
                        $repoRoot,
                        $code,
                        $aotBytes,
                        $sidecarRel,
                        $sentinelLogical,
                        true,
                        $this->m3EmitTuSidecarSourcePathNorm($path)
                    );

                    return;
                }
            }
            if ($this->m3EmitTuTryRegisterStaleCompilerLibSidecar($path, $sidecarRel, $sentinelLogical, $code, $repoRoot)) {
                return;
            }
        }
        if ($this->m3EmitTuTryRegisterExistingSidecarBlob($path, $sidecarRel, $sentinelLogical, $code, $repoRoot)) {
            return;
        }
        if (!$this->m3EmitTuForceSidecarHostCompile()
            && \PHPCompiler\JIT\M3EmitTuTrivialEchoAot::COMPILER_LIB_SIDECAR_REL === $sidecarRel) {
            return;
        }
        $hostCompilePath = $path;
        if (str_ends_with($pathNorm, '/bin/compile.php')) {
            // For gen-3 (argv) and other bootstrap products, compiling bin/compile.php must default to the
            // inventory emit driver (compile_driver.php) instead of the compile_smoke_m3_emit helper (#2925, #2900).
            // The helper TU is a narrow smoke path and has been observed to LLVM-segfault when used to emit
            // inventory fixtures like compiler_unit_probe_compile.php.
            $hostCompilePath = $path;
        }
        // Sidecar-only: avoid host compileEmitSmoke in emit TU LLVM module (#2540).
        // Memoize per-entrypoint+source to prevent runaway sidecar chains (#2908).
        $hostCode = @file_get_contents($hostCompilePath);
        $hostCodeHash = is_string($hostCode) && '' !== $hostCode ? substr(sha1($hostCode), 0, 16) : 'missing';
        $cacheKey = substr(sha1($hostCompilePath."\n".$sidecarRel."\n".$hostCodeHash), 0, 24);
        $cacheOut = sys_get_temp_dir().'/m3_emit_sidecar_cache_'.$cacheKey;
        $tmpOut = sys_get_temp_dir().'/m3_emit_sidecar_aot_'.getmypid().'_'.substr(md5($sidecarRel), 0, 8);
        @unlink($tmpOut);
        $compileCmd = 'php';
        $memLimit = getenv('PHP_COMPILER_MEMORY_LIMIT');
        // ci_apply_llvm_memory_env pins 4096M; full-spine sidecar host-compile OOMs below 8GB (#8559).
        if (\PHPCompiler\JIT\M3EmitTuTrivialEchoAot::COMPILER_LIB_SIDECAR_REL === $sidecarRel) {
            $memLimit = '8192M';
        }
        if (is_string($memLimit) && '' !== $memLimit && '-1' !== $memLimit) {
            $compileCmd .= ' -d memory_limit='.escapeshellarg($memLimit);
        }
        $compileCmd .= ' '.escapeshellarg($repoRoot.'/bin/compile.php')
            .' -o '.escapeshellarg($tmpOut)
            .' '.escapeshellarg($hostCompilePath);
        $compileEnv = $this->m3EmitSidecarHostCompileEnv();
        if (\PHPCompiler\JIT\M3EmitTuTrivialEchoAot::COMPILER_LIB_SIDECAR_REL === $sidecarRel) {
            $compileEnv['PHP_COMPILER_MEMORY_LIMIT'] = '8192M';
        }
        // Self-host skips cli/vendor includes during link; M3 compile-driver Runtime ctor native (#2600, #2633).
        $compileEnv['PHP_COMPILER_SELFHOST_AOT'] = '1';
        $compileEnv['PHP_COMPILER_M3_COMPILE_DRIVER'] = '1';
        // Recursion guard: nested bin/compile.php invocations should not spawn further sidecar host-compiles.
        $compileEnv['PHP_COMPILER_M3_EMIT_SIDECAR_RECURSION_GUARD'] = '1';
        $compileEnv['PHP_COMPILER_M3_EMIT_SIDECAR_DEPTH'] = (string) ($depth + 1);
        $compileEnv['PHP_COMPILER_M3_EMIT_SIDECAR_MAX_DEPTH'] = (string) $maxDepth;
        if (str_ends_with($pathNorm, '/bin/compile.php')) {
            // Treat host-compiling bin/compile.php as an argv-driver build: enable the inventory emit driver
            // regardless of outer env defaults so gen-3 products don't depend on compile_smoke_m3_emit (#2925).
            $compileEnv['PHP_COMPILER_M3_COMPILE_DRIVER_MAIN'] = '1';
            $compileEnv['PHP_COMPILER_M4_BIN_COMPILE_DRIVER'] = '1';
            $compileEnv['PHP_COMPILER_M3_INVENTORY_EMIT_DRIVER'] = '1';
            $compileEnv['BOOTSTRAP_M3_USE_INVENTORY_EMIT_DRIVER'] = '1';
            $compileEnv['PHP_COMPILER_EMIT_HELPER_LINK'] = '1';
            $compileEnv['PHP_COMPILER_M3_EMIT_LOG_PREFIX'] = 'helloworld_compile_smoke';
            unset($compileEnv['PHP_COMPILER_M3_EMIT_TU']);
        }
        if ($sidecarHostStubNonLiteralIncludes) {
            $compileEnv['PHP_COMPILER_M3_SIDECAR_HOST'] = '1';
        }
        $vendorObjectSidecar = str_contains($pathNorm, 'bootstrap-vendor-prelink/generated/');
        if ($vendorObjectSidecar) {
            $compileEnv['PHP_COMPILER_VENDOR_PRELINK'] = '1';
            $compileEnv['PHP_COMPILER_SELFHOST_AOT'] = '0';
            $compileEnv['PHP_COMPILER_KEEP_OBJECT_FILE'] = '1';
        }
        if (!str_ends_with($pathNorm, '/bin/compile.php')) {
            unset($compileEnv['PHP_COMPILER_EMIT_HELPER_LINK'], $compileEnv['PHP_COMPILER_M3_EMIT_TU']);
        }
        if (is_readable($cacheOut)
            && $this->m3EmitTuCompilerLibSidecarStampUsable(
                $repoRoot,
                $sidecarRel,
                $repoRoot.'/build/.m3_compiler_lib_sidecar.sha'
            )) {
            $aotBytes = file_get_contents($cacheOut);
            if (is_string($aotBytes) && '' !== $aotBytes) {
                \PHPCompiler\JIT\M3EmitTuTrivialEchoAot::registerLinktime(
                    $this->context,
                    $repoRoot,
                    $code,
                    $aotBytes,
                    $sidecarRel,
                    $sentinelLogical
                );

                return;
            }
        }
        $descriptor = [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
        $proc = proc_open($compileCmd, $descriptor, $pipes, $repoRoot, $compileEnv);
        if (!is_resource($proc)) {
            return;
        }
        fclose($pipes[0]);
        fclose($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[2]);
        $exit = proc_close($proc);
        $artifactPath = $tmpOut;
        if ($vendorObjectSidecar && is_readable($tmpOut.'.o')) {
            $artifactPath = $tmpOut.'.o';
        }
        if (0 !== $exit || !is_readable($artifactPath)) {
            if (is_string($stderr) && '' !== $stderr) {
                $tail = strlen($stderr) > 8000 ? substr($stderr, -8000) : $stderr;
                fwrite(
                    STDERR,
                    "m3-emit-tu sidecar host-compile failed: exit={$exit} source={$path} sidecar={$sidecarRel}\n".$tail."\n"
                );
            } else {
                fwrite(
                    STDERR,
                    "m3-emit-tu sidecar host-compile failed: exit={$exit} source={$path} sidecar={$sidecarRel}\n"
                );
            }
            @unlink($tmpOut);
            @unlink($tmpOut.'.o');
            if ($this->m3EmitTuTryRegisterStaleCompilerLibSidecar($path, $sidecarRel, $sentinelLogical, $code, $repoRoot)) {
                return;
            }
            // Gen-2 native argv driver cannot always spawn Zend during link; reuse blobs from an
            // earlier Zend host-compile in the same workspace (#3004).
            $repoSidecar = $repoRoot.'/'.ltrim($sidecarRel, '/');
            if (is_readable($repoSidecar)
                && $this->m3EmitTuCompilerLibSidecarStampUsable(
                    $repoRoot,
                    $sidecarRel,
                    $repoRoot.'/build/.m3_compiler_lib_sidecar.sha'
                )) {
                $aotBytes = file_get_contents($repoSidecar);
                if (is_string($aotBytes) && '' !== $aotBytes) {
                    \PHPCompiler\JIT\M3EmitTuTrivialEchoAot::registerLinktime(
                        $this->context,
                        $repoRoot,
                        $code,
                        $aotBytes,
                        $sidecarRel,
                        $sentinelLogical,
                        false,
                        $this->m3EmitTuSidecarSourcePathNorm($path)
                    );

                    return;
                }
            }
            if ($this->registerM3BinVmSidecarStubFallback($path, $sidecarRel, $sentinelLogical, $code, $repoRoot)) {
                return;
            }

            return;
        }
        $aotBytes = file_get_contents($artifactPath);
        @unlink($tmpOut);
        @unlink($tmpOut.'.o');
        if (!is_string($aotBytes) || '' === $aotBytes) {
            return;
        }
        // Persist a stable copy for memoization across multiple sidecar registrations in the same link.
        // If a concurrent writer races, the content should be identical for this cacheKey.
        @file_put_contents($cacheOut, $aotBytes);
        \PHPCompiler\JIT\M3EmitTuTrivialEchoAot::registerLinktime(
            $this->context,
            $repoRoot,
            $code,
            $aotBytes,
            $sidecarRel,
            $sentinelLogical,
            $vendorObjectSidecar,
            $this->m3EmitTuSidecarSourcePathNorm($path)
        );
    }

    /**
     * Prelinked gen-0 sidecars baked in Docker embed /compiler/build/.m3_* paths; skip on host (#3046).
     */
    private function m3EmitTuPrelinkedSidecarLooksStale(string $aotBytes): bool
    {
        return str_contains($aotBytes, '/compiler/build/.m3_')
            || str_contains($aotBytes, '/compiler/bin/compile.php');
    }

    private function m3EmitTuCompilerLibSidecarStampUsable(string $repoRoot, string $sidecarRel, string $stampPath): bool
    {
        if (\PHPCompiler\JIT\M3EmitTuTrivialEchoAot::COMPILER_LIB_SIDECAR_REL !== $sidecarRel) {
            return true;
        }

        return \PHPCompiler\JIT\M3EmitTuTrivialEchoAot::compilerLibSidecarStampMatches($repoRoot, $stampPath);
    }

    private function m3EmitTuSidecarSourcePathNorm(string $path): ?string
    {
        return \PHPCompiler\JIT\M3EmitTuTrivialEchoAot::normalizeSidecarSourcePath($path);
    }

    /**
     * bin/vm.php honest AOT still LLVM-segfaults; register path-keyed stub sidecar (#2699, #1492).
     */
    private function registerM3BinVmSidecarStubFallback(
        string $path,
        string $sidecarRel,
        string $sentinelLogical,
        string $code,
        string $repoRoot
    ): bool {
        if (\PHPCompiler\JIT\M3EmitTuTrivialEchoAot::BIN_VM_SIDECAR_REL !== $sidecarRel) {
            return false;
        }
        $sourcePathNorm = $this->m3EmitTuSidecarSourcePathNorm($path);
        if (null === $sourcePathNorm) {
            return false;
        }
        foreach (
            [
                $repoRoot.'/prelinked/bootstrap-gen0/.m3_bin_vm_aot_blob',
                $repoRoot.'/'.ltrim($sidecarRel, '/'),
            ] as $prelinked
        ) {
            if (!is_readable($prelinked)) {
                continue;
            }
            $aotBytes = file_get_contents($prelinked);
            if (!is_string($aotBytes) || '' === $aotBytes) {
                continue;
            }
            if ($this->m3EmitTuPrelinkedSidecarLooksStale($aotBytes)) {
                continue;
            }
            \PHPCompiler\JIT\M3EmitTuTrivialEchoAot::registerLinktime(
                $this->context,
                $repoRoot,
                $code,
                $aotBytes,
                $sidecarRel,
                $sentinelLogical,
                true,
                $sourcePathNorm
            );

            return true;
        }
        $stub = $repoRoot.'/test/bootstrap-aot/bin_vm_sidecar_stub.php';
        if (!is_readable($stub)) {
            return false;
        }
        $tmpOut = sys_get_temp_dir().'/m3_bin_vm_sidecar_stub_'.getmypid();
        @unlink($tmpOut);
        $compileCmd = 'php';
        $memLimit = getenv('PHP_COMPILER_MEMORY_LIMIT');
        if (is_string($memLimit) && '' !== $memLimit && '-1' !== $memLimit) {
            $compileCmd .= ' -d memory_limit='.escapeshellarg($memLimit);
        }
        $compileCmd .= ' '.escapeshellarg($repoRoot.'/bin/compile.php')
            .' -o '.escapeshellarg($tmpOut)
            .' '.escapeshellarg($stub);
        $compileEnv = $this->m3EmitSidecarHostCompileEnv();
        $compileEnv['PHP_COMPILER_SELFHOST_AOT'] = '1';
        $compileEnv['PHP_COMPILER_M3_SIDECAR_HOST'] = '1';
        $compileEnv['PHP_COMPILER_M3_EMIT_SIDECAR_RECURSION_GUARD'] = '1';
        unset($compileEnv['PHP_COMPILER_EMIT_HELPER_LINK'], $compileEnv['PHP_COMPILER_M3_EMIT_TU']);
        $descriptor = [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
        $proc = proc_open($compileCmd, $descriptor, $pipes, $repoRoot, $compileEnv);
        if (!is_resource($proc)) {
            return false;
        }
        fclose($pipes[0]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exit = proc_close($proc);
        if (0 !== $exit || !is_readable($tmpOut)) {
            @unlink($tmpOut);

            return false;
        }
        $aotBytes = file_get_contents($tmpOut);
        @unlink($tmpOut);
        if (!is_string($aotBytes) || '' === $aotBytes) {
            return false;
        }
        $repoSidecar = $repoRoot.'/'.ltrim($sidecarRel, '/');
        @file_put_contents($repoSidecar, $aotBytes);
        @chmod($repoSidecar, 0755);
        \PHPCompiler\JIT\M3EmitTuTrivialEchoAot::registerLinktime(
            $this->context,
            $repoRoot,
            $code,
            $aotBytes,
            $sidecarRel,
            $sentinelLogical,
            true,
            $sourcePathNorm
        );

        return true;
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
        try {
            $script = $this->context->runtime->parse((string) file_get_contents($compilerPath), $compilerPath);
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
        if (isset($this->context->functions[$lc])) {
            return;
        }
        if ($this->shouldUseM3InventoryEmitDriver()) {
            // Never scan O(modules×funcs) on inventory argv links (#2967). parse/compileEmitSmoke from
            // Runtime.php; ctor/init* use native M3 via compileBlock / ensureM3EmitTuRuntimeInitSpineSymbols.
            if (in_array($methodLc, ['parse', 'preparesourceforparser', 'compileemitsmoke', 'peeklastparsefailure', 'noteparsecompilenullforscript'], true)) {
                if ($this->shouldRealLowerInventoryArgvParseSpine()) {
                    unset(
                        $this->context->functions[$lc],
                        $this->context->functionReturnType[$lc],
                        $this->context->functionProxies[$lc]
                    );
                }
                $this->compileM3EmitTuRuntimeMethodFromRuntimePhpFile($methodLc, $logical, $lc);
                if (!isset($this->context->functions[$lc])) {
                    $this->compileM3EmitTuRuntimeMethodFromDeclareClassBlocks([$methodLc]);
                }
            }

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
                $this->compileBlock($compiled->block, $logical);
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
                    $this->compileBlock($compiled->block, $logical);
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
        $this->context->builder = $this->context->context->builderCreate();
        $this->context->builder->positionAtEnd($bb);
        $this->emitSelfHostStubReturn($callbackType, $func);
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
        if (null !== $logicalName) {
            JIT\NoDiscardCallGuard::registerCallee($this->context, $logicalName, $block);
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
     * Inline an included compilation unit at a dedicated entry block (issue #568 / MiniWebApp templates).
     */
    public function compileIncludedAtEntry(
        PHPLLVM\Value $func,
        Block $block,
        PHPLLVM\BasicBlock $entryBlock
    ): PHPLLVM\BasicBlock {
        $limit = $this->includedAtEntryOpcodeLimit($block);

        $this->context->inlineIncludeExitBlock = null;
        $exit = $this->compileBlockInternal($func, $block, $limit, $entryBlock);
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
        $exit = $this->compileBlockInternal($func, $block, $limit, $entryBlock, 0, false, ...$args);
        if (null !== $this->context->inlineIncludeExitBlock) {
            $exit = $this->context->inlineIncludeExitBlock;
        }

        return $exit;
    }

    /**
     * Lower a finally CFG arm at entry (#4246).
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
        $exit = $this->compileBlockInternal($func, $block, $limit, $entryBlock, 0, false, ...$args);
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
            $retval = $this->loadPendingReturnValue($valuePtr, $expected);
            $retval = $this->alignRetvalToLlvmFnReturn($retval, $func);
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

    private function compileBlockInternal(
        PHPLLVM\Value $func,
        Block $block,
        ?int $limit = null,
        ?PHPLLVM\BasicBlock $entryBlock = null,
        int $startIndex = 0,
        bool $allowRecompile = false,
        Variable ...$args
    ): PHPLLVM\BasicBlock {
        if (
            null !== $block->func
            && '{main}' === $block->func->name
            && $this->isM4BinCompileScriptMain($block)
            && (
                $this->shouldUseM4BinCompileArgvMainNative()
                || $this->shouldUseHelloworldBinCompileInventoryArgvLink()
            )
            && $this->shouldUseM4InventoryArgvNativeEmitRebuild($block)
        ) {
            if (null !== $entryBlock) {
                return $entryBlock;
            }
            $existing = $func->getBasicBlocks();
            if ([] !== $existing) {
                return $existing[0];
            }

            return $func->appendBasicBlock('entry');
        }
        if (!$allowRecompile && $this->context->scope->blockStorage->contains($block)) {
            $cached = $this->context->scope->blockStorage[$block];
            if (null === $entryBlock || $cached === $entryBlock) {
                return $cached;
            }
        }
        if (null !== $block->func) {
            JIT\Progress::noteFunction($block->func->getScopedName());
        }
        if (null !== $entryBlock) {
            $origBasicBlock = $basicBlock = $entryBlock;
        } else {
            self::$blockNumber++;
            $origBasicBlock = $basicBlock = $func->appendBasicBlock('block_' . self::$blockNumber);
        }
        if (!$this->context->scope->blockStorage->contains($block) || null === $entryBlock) {
            $this->context->scope->blockStorage[$block] = $basicBlock;
        }
        if (!$this->context->scope->blockEntryStorage->contains($block) || null === $entryBlock) {
            $this->context->scope->blockEntryStorage[$block] = $basicBlock;
        }
        $builder = $this->context->builder;
        $builder->positionAtEnd($basicBlock);
        $this->context->jitCurrentBlock = $block;
        if (null === $this->context->listUnpackAssignRootBlock) {
            foreach ($block->opCodes as $scanOp) {
                if (OpCode::TYPE_LIST_UNPACK_CHECK === $scanOp->type && null !== $scanOp->block1) {
                    $this->context->listUnpackAssignRootBlock = $block;
                    break;
                }
            }
        }
        if (null !== $block->func && $block->orig === $block->func->cfg) {
            $this->context->jitFunctionRootBlock = $block;
            $this->emitJitDestructAllowDelref($block);
        }
        if ([] !== $args) {
            $this->context->implicitThisArgument = null;
        }
        // Handle hoisted variables
        foreach ($block->orig->hoistedOperands as $operand) {
            if ($this->context->coalesceAssignTargets->contains($operand)) {
                continue;
            }
            $this->context->makeVariableFromOp($func, $basicBlock, $block, $operand);
        }
        $blockKey = spl_object_id($block);
        if (isset($this->context->listUnpackMergeNullInitTargets[$blockKey])) {
            foreach ($this->context->listUnpackMergeNullInitTargets[$blockKey] as $destOp) {
                if (!$this->context->hasVariableOpInScopes($destOp)) {
                    $this->context->makeVariableFromOp($func, $basicBlock, $block, $destOp);
                }
                $dest = $this->context->getVariableFromOpInScopes($destOp);
                if (JIT\Variable::KIND_VARIABLE !== $dest->kind) {
                    continue;
                }
                $this->context->builder->call(
                    $this->context->lookupFunction('__value__writeNull'),
                    JIT\JitValueBox::pointer($this->context, $dest->value)
                );
                $dest->isNullConstant = true;
            }
            unset($this->context->listUnpackMergeNullInitTargets[$blockKey]);
        }
        $thisParamOffset = 0;
        if (null !== $block->func && $block->orig === $block->func->cfg) {
            $this->context->jitEnclosingBlock = $block;
            $methodLc = strtolower($block->func->name);
            if (str_contains($methodLc, '::')) {
                $methodLc = substr($methodLc, strrpos($methodLc, '::') + 2);
            }
            $this->context->jitPropertyHookRawProperty = SourcePreprocessor\PropertyHooks::propertyNameFromSetHookMethod($methodLc)
                ?? SourcePreprocessor\PropertyHooks::propertyNameFromGetHookMethod($methodLc);
        }
        if ([] !== $args) {
            if ($this->instanceMethodUsesThis($block)) {
                $thisParamOffset = 1;
            }
            foreach ($block->orig->hoistedOperands as $hoisted) {
                if ('this' === JIT\OperandName::resolve($hoisted)) {
                    if (!$this->context->hasVariableOp($hoisted)) {
                        $this->context->makeVariableFromOp($func, $basicBlock, $block, $hoisted);
                    }
                    $this->assignOperand($hoisted, $args[0], true);
                    $thisParamOffset = 1;
                    break;
                }
            }
            if (1 === $thisParamOffset) {
                $this->context->implicitThisArgument = $args[0];
            } else {
                $this->context->implicitThisArgument = null;
            }
            // Only the CFG entry block receives LLVM arguments; branch blocks share the same func (#210).
            if (null !== $block->func && $block->orig === $block->func->cfg) {
                foreach ($block->func->params as $idx => $param) {
                    $argIdx = $thisParamOffset + $idx;
                    if ($param->variadic) {
                        $remaining = array_slice($args, $argIdx);
                        $packed = [] === $remaining
                            ? JIT\HashTableHelper::emptyVariable($this->context)
                            : JIT\HashTableHelper::packVariables($this->context, $remaining);
                        if (!$this->context->hasVariableOp($param->result)) {
                            $this->context->makeVariableFromOp($func, $basicBlock, $block, $param->result);
                        }
                        $this->assignOperand($param->result, $packed, true);
                        break;
                    }
                    if ($argIdx >= count($args)) {
                        break;
                    }
                    if (!$this->context->hasVariableOp($param->result)) {
                        $this->context->makeVariableFromOp($func, $basicBlock, $block, $param->result);
                    }
                    if (isset($block->paramByRef[$idx])) {
                        $this->bindJitParamByReference($block, $param->result, $args[$argIdx]);
                    } else {
                        $this->assignOperand($param->result, $args[$argIdx], true);
                    }
                }
                $captureSlots = JIT\ClosureHelper::orderedCaptureSlots($block);
                if ([] !== $captureSlots) {
                    $captureBase = count($args) - count($captureSlots);
                    foreach ($captureSlots as $captureIdx => $captureSlot) {
                        $captureOperand = JIT\ClosureHelper::operandForCaptureSlot($block, $captureSlot);
                        if (null === $captureOperand) {
                            continue;
                        }
                        if (!$this->context->hasVariableOp($captureOperand)) {
                            $this->context->makeVariableFromOp($func, $basicBlock, $block, $captureOperand);
                        }
                        $captureArg = $args[$captureBase + $captureIdx];
                        $captureVar = $this->context->getVariableFromOp($captureOperand);
                        if (isset($block->closureCaptureByRef[$captureSlot])) {
                            JIT\ClosureHelper::bindCaptureSlotByReference(
                                $this->context,
                                $captureVar,
                                $captureArg
                            );
                        } else {
                            $this->assignOperand($captureOperand, $captureArg, true);
                        }
                    }
                }
            }
        }

        for ($i = $startIndex, $length = null !== $limit ? $limit : count($block->opCodes); $i < $length; ++$i) {
            $op = $block->opCodes[$i];
            if (
                null !== $block->func
                && '{main}' === $block->func->name
            ) {
                JIT\Progress::noteFunction('{main}:op='.$i.':type='.$op->type);
            }
            switch ($op->type) {
                case OpCode::TYPE_ARG_RECV:
                    $recvSlot = $op->arg2 + $thisParamOffset;
                    $isVariadicSlot = null !== $block->variadicParamIndex
                        && $block->variadicParamIndex === (int) $op->arg2;
                    if ($isVariadicSlot) {
                        $packed = isset($args[$recvSlot])
                            ? $args[$recvSlot]
                            : JIT\HashTableHelper::emptyVariable($this->context);
                        $this->assignOperand($block->getOperand($op->arg1), $packed, true);
                        break;
                    }
                    if (!isset($args[$recvSlot])) {
                        throw new \LogicException('Missing required argument ' . $op->arg2);
                    }
                    if (isset($block->paramByRef[(int) $op->arg2])) {
                        $this->bindJitParamByReference(
                            $block,
                            $block->getOperand($op->arg1),
                            $args[$recvSlot]
                        );
                    } else {
                        $this->assignOperand($block->getOperand($op->arg1), $args[$recvSlot]);
                    }
                    break;
                case OpCode::TYPE_ASSIGN:
                    $value = $this->context->getVariableFromOp($block->getOperand($op->arg3));
                    $destOp = $block->getOperand($op->arg1);
                    $aliasOp = $block->getOperand($op->arg2);
                    if (null !== $this->context->ternarySharedReturnSlot && $this->isTernaryBranchMergeAssign($block, $op)) {
                        $this->emitJitReturnFromValue($func, $block, $value);
                        break;
                    }
                    $coalesceTarget = null;
                    if ($this->context->coalesceAssignTargets->contains($destOp)) {
                        $coalesceTarget = $destOp;
                    } elseif ($this->context->coalesceAssignTargets->contains($aliasOp)) {
                        $coalesceTarget = $aliasOp;
                    }
                    $forceCoalesce = null !== $coalesceTarget;
                    $srcOp = $block->getOperand($op->arg3);
                    $isNullSource = $value->isNullConstant
                        || Variable::TYPE_NULL === $value->type
                        || ($srcOp instanceof Operand\Literal && null === $srcOp->value);
                    if ($forceCoalesce && $isNullSource) {
                        if (!$this->context->hasVariableOp($coalesceTarget)) {
                            $this->context->makeVariableFromOp($func, $basicBlock, $block, $coalesceTarget);
                        }
                        $mergeDest = $this->context->getVariableFromOp($coalesceTarget);
                        if (Variable::KIND_VALUE === $mergeDest->kind) {
                            $slot = JIT\JitValueBox::alloc($this->context);
                            $this->context->setVariableOp(
                                $coalesceTarget,
                                new Variable(
                                    $this->context,
                                    Variable::TYPE_VALUE,
                                    Variable::KIND_VARIABLE,
                                    $slot
                                )
                            );
                            $mergeDest = $this->context->getVariableFromOp($coalesceTarget);
                        }
                        if (
                            Variable::TYPE_VALUE === $mergeDest->type
                            && Variable::KIND_VARIABLE === $mergeDest->kind
                        ) {
                            $this->context->builder->call(
                                $this->context->lookupFunction('__value__writeNull'),
                                JIT\JitValueBox::pointer($this->context, $mergeDest->value)
                            );
                            $mergeDest->isNullConstant = true;
                            break;
                        }
                    }
                    if ($forceCoalesce && !$isNullSource) {
                        if (!$this->context->hasVariableOp($coalesceTarget)) {
                            $this->context->makeVariableFromOp($func, $basicBlock, $block, $coalesceTarget);
                        }
                        $mergeDest = $this->context->getVariableFromOp($coalesceTarget);
                        if (
                            Variable::TYPE_VALUE !== $mergeDest->type
                            || Variable::KIND_VARIABLE !== $mergeDest->kind
                        ) {
                            $slot = JIT\JitValueBox::alloc($this->context);
                            $this->context->setVariableOp(
                                $coalesceTarget,
                                new Variable(
                                    $this->context,
                                    Variable::TYPE_VALUE,
                                    Variable::KIND_VARIABLE,
                                    $slot
                                )
                            );
                        }
                    }
                    $forceAssign = $forceCoalesce
                        || $this->assignOperandsUsedByLiteralInclude($block, $op);
                    $aliasName = JIT\OperandName::resolve($aliasOp);
                    $needsNamedStorageAssign = $op->arg1 !== $op->arg2
                        && null !== $aliasName
                        && '' !== $aliasName
                        && null === JIT\OperandName::resolve($destOp);
                    if ($needsNamedStorageAssign && !$this->context->hasVariableOp($destOp)) {
                        $this->context->makeVariableFromOp($func, $basicBlock, $block, $destOp);
                    }
                    if (
                        $this->context->hasVariableOp($aliasOp)
                        && $this->context->hasVariableOp($block->getOperand($op->arg3))
                    ) {
                        $aliasVar = $this->context->getVariableFromOp($aliasOp);
                        $srcVar = $this->context->getVariableFromOp($block->getOperand($op->arg3));
                        if ($aliasVar === $srcVar) {
                            if ([] !== $destOp->usages || $forceAssign) {
                                $this->assignOperand($destOp, $value, $forceAssign);
                            }
                            break;
                        }
                    }
                    if ($needsNamedStorageAssign) {
                        if (!$this->context->hasVariableOp($aliasOp)) {
                            $this->context->makeVariableFromOp($func, $basicBlock, $block, $aliasOp);
                        }
                        $this->assignOperand($aliasOp, $value, true);
                        $this->recordListUnpackAssignSlot($aliasOp, $this->context->getVariableFromOp($aliasOp));
                    } else {
                        $this->assignOperand($aliasOp, $value, $forceAssign);
                        $destUsed = [] !== $destOp->usages;
                        if ($destUsed || $forceAssign) {
                            $this->assignOperand($destOp, $value, $destUsed || $forceAssign);
                        }
                    }
                    $srcOp = $block->getOperand($op->arg3);
                    if ($op->arg2 !== $op->arg3 && $block->assignTempSlotIsDead((int) $op->arg3)) {
                        $this->jitClearAssignTempOperand($srcOp);
                    }
                    if (
                        !$needsNamedStorageAssign
                        && $op->arg1 !== $op->arg2
                        && $op->arg1 !== $op->arg3
                        && $block->assignTempSlotIsDead((int) $op->arg1)
                    ) {
                        $this->jitClearAssignTempOperand($destOp);
                    }
                    $this->maybeBindNamedVariable($aliasOp);
                    if ($op->arg1 === $op->arg2) {
                        $this->maybeBindNamedVariable($destOp);
                    }
                    foreach ([$block->getOperand($op->arg2), $destOp] as $destOperand) {
                        if (!$this->context->hasVariableOp($destOperand)) {
                            continue;
                        }
                        $destVar = $this->context->getVariableFromOp($destOperand);
                        $this->foldCompileTimeStringFromAssign(
                            $block,
                            (int) $op->arg3,
                            $destVar,
                            $value
                        );
                    }
                    break;  
                case OpCode::TYPE_ASSIGN_REF:
                    if (null !== $op->arg3 && 1 === (int) $op->arg3) {
                        throw new \LogicException('Cannot assign reference to non referenceable value');
                    }
                    if (
                        null !== $op->arg3
                        && OpCode::ASSIGN_REF_FOREACH_PROPERTY_HOOK === (int) $op->arg3
                    ) {
                        break;
                    }
                    $destOp = $block->getOperand($op->arg1);
                    $srcOp = $block->getOperand($op->arg2);
                    $destName = JIT\OperandName::resolve($destOp);
                    $srcName = JIT\OperandName::resolve($srcOp);
                    if (null === $destName) {
                        throw new \LogicException('Reference assignment requires named destination variable');
                    }
                    if (null === $srcName) {
                        $this->context->foreachByRefLocalNames[$this->context->resolveRefAliasName($destName)] = true;
                    }
                    if (null !== $srcName) {
                        if ($this->context->hasVariableOp($srcOp)) {
                            $srcVar = $this->context->getVariableFromOp($srcOp);
                            if (
                                Variable::TYPE_VALUE === $srcVar->type
                                && null === $srcVar->valueBoxAliasPtr
                                && !$srcVar->borrowedValueEntry
                            ) {
                                $srcVar->valueBoxAliasPtr = JIT\JitValueBox::valuePtrFromVariable(
                                    $this->context,
                                    $srcVar
                                );
                            }
                            $this->context->bindVariableByName($destName, $srcVar);
                            $this->context->setVariableOp($destOp, $srcVar);
                            break;
                        }
                        $this->context->refAliasNames[$destName] = $this->context->resolveRefAliasName($srcName);
                        break;
                    }
                    if (!$this->context->hasVariableOp($srcOp)) {
                        throw new \LogicException('Reference assignment requires a bound source variable');
                    }
                    $srcVar = $this->context->getVariableFromOp($srcOp);
                    if (
                        Variable::TYPE_VALUE === $srcVar->type
                        && null === $srcVar->valueBoxAliasPtr
                        && !$srcVar->borrowedValueEntry
                    ) {
                        $srcVar->valueBoxAliasPtr = JIT\JitValueBox::valuePtrFromVariable(
                            $this->context,
                            $srcVar
                        );
                    }
                    $this->context->bindVariableByName($destName, $srcVar);
                    $this->context->setVariableOp($destOp, $srcVar);
                    break;
                case OpCode::TYPE_DECLARE_GLOBAL:
                    if (!isset($block->constants[$op->arg2])) {
                        throw new \LogicException('Global name must be a compile-time constant');
                    }
                    $globalName = $block->constants[$op->arg2]->toString();
                    $globalVar = $this->ensureJitGlobal($globalName);
                    $this->context->bindVariableByName($globalName, $globalVar);
                    $this->context->setVariableOp(
                        $block->getOperand($op->arg1),
                        $globalVar
                    );
                    break;
                case OpCode::TYPE_DECLARE_FUNCTION_STATIC:
                    if (!isset($block->constants[$op->arg2])) {
                        throw new \LogicException('Function static key must be a compile-time constant');
                    }
                    $storageKey = $block->constants[$op->arg2]->toString();
                    $destOp = $block->getOperand($op->arg1);
                    if (!$this->context->hasVariableOp($destOp)) {
                        $this->context->makeVariableFromOp($func, $basicBlock, $block, $destOp);
                    }
                    $staticVar = $this->ensureJitFunctionStatic($storageKey);
                    if (null !== $op->arg3 && isset($block->constants[$op->arg3])) {
                        JIT\FunctionStaticHelper::emitLazyInit(
                            $this->context,
                            $storageKey,
                            $staticVar,
                            $this->jitVariableFromVmConstant($block->constants[$op->arg3])
                        );
                    }
                    $this->context->setVariableOp($destOp, $staticVar);
                    $staticName = JIT\OperandName::resolve($destOp);
                    if (null !== $staticName && '' !== $staticName) {
                        $this->context->bindVariableByName($staticName, $staticVar);
                    }
                    $staticSlot = $block->slotForOperand($destOp);
                    if (null !== $staticSlot) {
                        foreach ($block->scopedOperands() as $scopeOp) {
                            if ($block->slotForOperand($scopeOp) === $staticSlot) {
                                $this->context->setVariableOp($scopeOp, $staticVar);
                            }
                        }
                    }
                    break;
                case OpCode::TYPE_JUMPIF_FUNCTION_STATIC_INITIALIZED:
                    if (!isset($block->constants[$op->arg2])) {
                        throw new \LogicException('Function static key must be a compile-time constant');
                    }
                    $branchBlock = $builder->getInsertBlock();
                    $builder->positionAtEnd($branchBlock);
                    $jumpKey = $block->constants[$op->arg2]->toString();
                    $this->compileBlockInternal($func, $op->block1, null, null, 0, false, ...$args);
                    $skipEntry = $this->jitBranchEntryBlock($op->block1);
                    $initPathBb = JIT\BasicBlockHelper::append($this->context, 'fn_static_init_path');
                    $builder->positionAtEnd($branchBlock);
                    $builder->branchIf(
                        JIT\FunctionStaticHelper::isInitializedCondition($this->context, $jumpKey),
                        $skipEntry,
                        $initPathBb
                    );
                    $builder->positionAtEnd($initPathBb);
                    break;
                case OpCode::TYPE_FUNCTION_STATIC_INIT_STORE:
                    if (!isset($block->constants[$op->arg2])) {
                        throw new \LogicException('Function static key must be a compile-time constant');
                    }
                    if (null === $op->arg3) {
                        throw new \LogicException('Function static init store requires a value slot');
                    }
                    $storeKey = $block->constants[$op->arg2]->toString();
                    $storeVar = $this->ensureJitFunctionStatic($storeKey);
                    $initValue = $this->variableFromBlockSlot($block, (int) $op->arg3);
                    JIT\FunctionStaticHelper::emitRuntimeInitStore(
                        $this->context,
                        $storeKey,
                        $storeVar,
                        $initValue
                    );
                    break;
                case OpCode::TYPE_VAR_FETCH:
                    $destOp = $block->getOperand($op->arg1);
                    if (!$this->context->hasVariableOp($destOp)) {
                        $this->context->makeVariableFromOp($func, $basicBlock, $block, $destOp);
                    }
                    $nameSlot = (int) $op->arg2;
                    foreach ($block->scopedOperands() as $slotOp) {
                        if ($block->slotForOperand($slotOp) === $nameSlot && !$this->context->hasVariableOp($slotOp)) {
                            $this->context->makeVariableFromOp($func, $basicBlock, $block, $slotOp);
                        }
                    }
                    $nameVar = $this->variableFromBlockSlot($block, $nameSlot);
                    $this->foldVarFetchNameFromAssign($block, $nameSlot, $nameVar);
                    $forWrite = $this->varFetchDestUsedAsAssignLvalue($block, $i, (int) $op->arg1);
                    $target = JIT\VarFetchHelper::resolveTarget($this->context, $block, $nameVar, $forWrite);
                    if ($forWrite) {
                        $this->context->setVariableOp($destOp, $target);
                    } else {
                        $this->assignOperand($destOp, $target, true);
                    }
                    break;
                case OpCode::TYPE_ARRAY_DIM_FETCH:
                case OpCode::TYPE_ARRAY_DIM_FETCH_WRITE:
                    $forWrite = OpCode::TYPE_ARRAY_DIM_FETCH_WRITE === $op->type;
                    $value = $this->context->getVariableFromOp($block->getOperand($op->arg2));
                    if (
                        $forWrite
                        && null !== $value->magicGetOverloadedClass
                        && null !== $value->magicGetOverloadedName
                    ) {
                        JIT\MagicMethodDispatch::emitMagicGetIndirectModifyError(
                            $this->context,
                            $value->magicGetOverloadedClass,
                            $value->magicGetOverloadedName
                        );
                        $this->context->builder->call($this->context->lookupFunction('abort'));
                        $this->context->builder->clearInsertionPosition();
                        break;
                    }
                    $resultOp = $block->getOperand($op->arg1);
                    $forceBranchMerge = $this->context->coalesceAssignTargets->contains($resultOp);
                    if (null === $op->arg3) {
                        $bracketLabel = Variable::cannotUseBracketLabel($value->type);
                        if (null !== $bracketLabel) {
                            JIT\Builtin\ErrorRaise::registerDeclarations($this->context);
                            JIT\Builtin\ErrorRaise::ensureLinked($this->context);
                            JIT\Builtin\ErrorRaise::emitRaise(
                                $this->context,
                                \PHPCompiler\VM\TypeCheck::SCALAR_USED_AS_ARRAY_MESSAGE
                            );
                            break;
                        }
                        if (Variable::TYPE_STRING === $value->type) {
                            throw new \LogicException('[] is only supported for arrays');
                        }
                        $this->context->setVariableOp(
                            $resultOp,
                            JIT\HashTableHelper::reserveAppendSlot($this->context, $value)
                        );
                        break;
                    }
                    $dimOp = $block->getOperand($op->arg3);
                    $dim = $this->context->getVariableFromOp($dimOp);
                    $containerOp = $block->getOperand($op->arg2);
                    $containerUserType = $containerOp->type->userType ?? '';
                    if (
                        $value->type === Variable::TYPE_OBJECT
                        && 'splobjectstorage' === strtolower($containerUserType)
                        && Variable::TYPE_OBJECT === $dim->type
                    ) {
                        $ht = $this->context->type->object->splBackingHashtable($value);
                        $htVal = $this->context->helper->loadValue($ht);
                        $keyObj = $this->context->helper->loadValue($dim);
                        if ($forWrite) {
                            $fetched = JIT\HashTableHelper::writableObjectKeyValueBox(
                                $this->context,
                                $htVal,
                                $keyObj
                            );
                            $this->context->setVariableOp($resultOp, $fetched);
                        } else {
                            $fetched = JIT\HashTableHelper::readObjectKeyToValueBox(
                                $this->context,
                                $htVal,
                                $keyObj
                            );
                            $this->assignOperand($resultOp, $fetched);
                        }
                        break;
                    }
                    if (
                        $value->type === Variable::TYPE_STRING
                        && !$this->context->listUnpackSkipAssignPath
                    ) {
                        $charPtr = JIT\StringOffsetHelper::dimFetch(
                            $this->context,
                            $value->value,
                            $dim
                        );
                        if ($forWrite) {
                            $this->context->makeVariableFromValueOp($charPtr, $resultOp);
                        } else {
                            $str = JIT\StringOffsetHelper::readAsString($this->context, $charPtr);
                            $this->context->makeVariableFromValueOp($str, $resultOp);
                        }
                        break;
                    }
                    if ($value->type === Variable::TYPE_HASHTABLE) {
                        $fetched = $value->dimFetch($dim, $resultOp->type, $forWrite);
                        if ($forWrite) {
                            $this->context->setVariableOp($resultOp, $fetched);
                        } elseif ($forceBranchMerge) {
                            $this->assignOperand($resultOp, $fetched, true);
                        } else {
                            $this->assignOperand($resultOp, $fetched);
                        }
                        break;
                    }
                    if (Variable::TYPE_VALUE === $value->type) {
                        $fetched = $value->dimFetch($dim, $resultOp->type, $forWrite);
                        if ($forWrite) {
                            $this->context->setVariableOp($resultOp, $fetched);
                        } elseif ($forceBranchMerge) {
                            $this->assignOperand($resultOp, $fetched, true);
                        } else {
                            $this->assignOperand($resultOp, $fetched);
                        }
                        break;
                    }
                    $bracketLabel = Variable::cannotUseBracketLabel($value->type);
                    if (null !== $bracketLabel && !$this->context->listUnpackSkipAssignPath) {
                        if (!$forWrite) {
                            JIT\ScalarDimFetchHelper::lowerScalarDimRead(
                                $this->context,
                                $resultOp,
                                $value->type
                            );
                            break;
                        }
                        JIT\Builtin\ErrorRaise::registerDeclarations($this->context);
                        JIT\Builtin\ErrorRaise::ensureLinked($this->context);
                        JIT\Builtin\ErrorRaise::emitRaise(
                            $this->context,
                            \PHPCompiler\VM\TypeCheck::SCALAR_USED_AS_ARRAY_MESSAGE
                        );
                        break;
                    }
                    if (
                        $this->context->listUnpackSkipAssignPath
                        && (
                            Variable::TYPE_NULL === $value->type
                            || Variable::TYPE_NATIVE_BOOL === $value->type
                            || Variable::TYPE_NATIVE_LONG === $value->type
                            || Variable::TYPE_NATIVE_DOUBLE === $value->type
                            || Variable::TYPE_STRING === $value->type
                        )
                    ) {
                        // Guarded list destruct compiles dim fetches on non-array RHS (#4325, #4308); unreachable at run time.
                        $boxed = new Variable(
                            $this->context,
                            Variable::TYPE_VALUE,
                            Variable::KIND_VALUE,
                            JIT\JitValueBox::alloc($this->context)
                        );
                        $fetched = $boxed->dimFetch($dim, $resultOp->type, $forWrite);
                        if ($forWrite) {
                            $this->context->setVariableOp($resultOp, $fetched);
                        } elseif ($forceBranchMerge) {
                            $this->assignOperand($resultOp, $fetched, true);
                        } else {
                            $this->assignOperand($resultOp, $fetched);
                        }
                        break;
                    }
                    if (Variable::TYPE_OBJECT === $value->type && null !== $op->arg3) {
                        $arrayAccess = JIT\ArrayAccessHelper::tryCompileDimFetch(
                            $this->context,
                            $value,
                            $dim,
                            $containerOp,
                            $forWrite
                        );
                        if (null !== $arrayAccess) {
                            if ($forWrite) {
                                $this->context->setVariableOp($resultOp, $arrayAccess);
                            } elseif ($forceBranchMerge) {
                                $this->assignOperand($resultOp, $arrayAccess, true);
                            } else {
                                $this->assignOperand($resultOp, $arrayAccess);
                            }
                            break;
                        }
                        if (JIT\ArrayAccessHelper::isKnownNonArrayAccessObject(
                            $this->context,
                            $value,
                            $containerOp
                        )) {
                            JIT\ArrayAccessHelper::emitIllegalOffset($this->context);
                            break;
                        }
                    }
                    if ($value->type & Variable::IS_NATIVE_ARRAY && $this->context->analyzer->needsBoundsCheck($value, $dimOp)) {
                        $this->context->builder->call(
                            $this->context->lookupFunction('__nativearray__boundscheck'),
                            $dim->value,
                            $this->context->constantFromInteger($value->nextFreeElement)
                        );
                    }
                    $fetched = $value->dimFetch($dim, $resultOp->type, $forWrite);
                    if ($forceBranchMerge && !$forWrite) {
                        $this->assignOperand($resultOp, $fetched, true);
                    } else {
                        $this->assignOperand($resultOp, $fetched);
                    }
                    break;
                case OpCode::TYPE_INIT_ARRAY:
                    $result = $this->context->getVariableFromOp($block->getOperand($op->arg1));
                    JIT\HashTableHelper::initArray($this->context, $result);
                    if (null !== $op->arg2) {
                        $element = $this->context->getVariableFromOp($block->getOperand($op->arg2));
                        $key = $this->jitArrayElementKeyVariable($block, $op->arg3);
                        JIT\HashTableHelper::addElement($this->context, $result, $element, $key);
                        $this->bumpNativeArrayNextFreeForExplicitIntKey($result, $op->arg3, $block);
                    }
                    break;
                case OpCode::TYPE_ADD_ARRAY_ELEMENT:
                    $result = $this->context->getVariableFromOp($block->getOperand($op->arg1));
                    $element = $this->context->getVariableFromOp($block->getOperand($op->arg2));
                    $key = $this->jitArrayElementKeyVariable($block, $op->arg3);
                    JIT\HashTableHelper::addElement($this->context, $result, $element, $key);
                    $this->bumpNativeArrayNextFreeForExplicitIntKey($result, $op->arg3, $block);
                    break;
                case OpCode::TYPE_ARRAY_SPREAD:
                    JIT\HashTableHelper::spreadInto(
                        $this->context,
                        $this->context->getVariableFromOp($block->getOperand($op->arg1)),
                        $this->context->getVariableFromOp($block->getOperand($op->arg2))
                    );
                    break;
                case OpCode::TYPE_LIST_UNPACK_CHECK:
                    if (null !== $op->block1) {
                        $branchBlock = $builder->getInsertBlock();
                        $builder->positionAtEnd($branchBlock);
                        $array = $this->context->getVariableFromOp($block->getOperand($op->arg2));
                        if (!$this->context->listUnpackMergeLlvmBlocks->contains($op->block1)) {
                            ++self::$blockNumber;
                            $mergeBody = $func->appendBasicBlock('block_'.self::$blockNumber);
                            $this->context->listUnpackMergeLlvmBlocks[$op->block1] = $mergeBody;
                        } else {
                            $mergeBody = $this->context->listUnpackMergeLlvmBlocks[$op->block1];
                        }
                        $this->context->listUnpackAssignRootBlock = $block;
                        $this->context->listUnpackSkipAssignPath = JIT\ListUnpackHelper::emitGuardedListUnpackCheck(
                            $this->context,
                            $array,
                            $branchBlock,
                            $mergeBody,
                            $block->getOperand($op->arg2)
                        );
                        break;
                    }
                    JIT\ListUnpackHelper::emitCheck(
                        $this->context,
                        $this->context->getVariableFromOp($block->getOperand($op->arg2))
                    );
                    break;
                case OpCode::TYPE_LIST_SPREAD_ASSIGN:
                    if ($this->context->listUnpackSkipAssignPath) {
                        break;
                    }
                    if (!isset($block->constants[$op->arg3])) {
                        throw new \LogicException('list spread assign requires compile-time offset');
                    }
                    $spreadDestOp = $block->getOperand($op->arg1);
                    $spreadSrc = $this->context->getVariableFromOp($block->getOperand($op->arg2));
                    $spreadI64 = $this->context->getTypeFromString('int64');
                    $spreadI1 = $this->context->getTypeFromString('int1');
                    $spreadOffset = $spreadI64->constInt($block->constants[$op->arg3]->toInt(), false);
                    if ([] !== $op->listSpreadExcludedKeys) {
                        $spreadTailHt = JIT\ArrayBuiltinHelper::buildCopyListSpreadTail(
                            $this->context,
                            $spreadSrc,
                            $spreadOffset,
                            $op->listSpreadExcludedKeys
                        );
                    } else {
                        if (!JIT\ListUnpackHelper::isDefinitelyNonArrayAtCompileTime($this->context, $spreadSrc)) {
                            JIT\ListUnpackHelper::emitIsListBranchOrFail($this->context, $spreadSrc);
                        }
                        $spreadTailHt = JIT\ArrayBuiltinHelper::buildSliceArray(
                            $this->context,
                            $spreadSrc,
                            $spreadOffset,
                            $spreadI1->constInt(0, false),
                            $spreadI64->constInt(0, false)
                        );
                    }
                    $spreadDestVar = $this->context->getVariableFromOp($spreadDestOp);
                    if (0 !== ($spreadDestVar->type & Variable::IS_NATIVE_ARRAY)) {
                        $spreadBox = JIT\JitValueBox::alloc($this->context);
                        $this->context->setVariableOp(
                            $spreadDestOp,
                            new Variable(
                                $this->context,
                                Variable::TYPE_VALUE,
                                Variable::KIND_VARIABLE,
                                $spreadBox
                            )
                        );
                    }
                    $spreadTailVar = new Variable(
                        $this->context,
                        Variable::TYPE_HASHTABLE,
                        Variable::KIND_VALUE,
                        $spreadTailHt
                    );
                    $this->assignOperand($spreadDestOp, $spreadTailVar);
                    break;
                case OpCode::TYPE_TYPE_ASSERT:
                    $this->assignOperand(
                        $block->getOperand($op->arg1),
                        $this->context->getVariableFromOp($block->getOperand($op->arg2))
                    );
                    break;
                case OpCode::TYPE_EMPTY:
                    $from = $this->context->getVariableFromOp($block->getOperand($op->arg2));
                    $emptyResult = JIT\EmptyObjectPropertyHelper::compileEmptyFromValue(
                        $this->context,
                        $from
                    );
                    $this->assignOperandValue(
                        $block->getOperand($op->arg1),
                        $emptyResult
                    );
                    break;
                case OpCode::TYPE_EMPTY_OBJECT_PROPERTY:
                    $containerOp = $block->getOperand($op->arg2);
                    $dimOp = $block->getOperand($op->arg3);
                    $container = $this->context->getVariableFromOp($containerOp);
                    $dim = $this->context->getVariableFromOp($dimOp);
                    $emptyResult = JIT\EmptyObjectPropertyHelper::compile(
                        $this->context,
                        $container,
                        $dim,
                        $dimOp,
                        $containerOp
                    );
                    $this->assignOperandValue($block->getOperand($op->arg1), $emptyResult);
                    break;
                case OpCode::TYPE_EVAL:
                    JIT\EvalHelper::compile($this, $func, $block, $op);
                    break;
                case OpCode::TYPE_ISSET:
                    $containerOp = $block->getOperand($op->arg2);
                    $dimOp = null !== $op->arg3 ? $block->getOperand($op->arg3) : null;
                    $container = $this->context->getVariableFromOp($containerOp);
                    $dim = null !== $dimOp ? $this->context->getVariableFromOp($dimOp) : null;
                    $issetResult = IssetHelper::compile(
                        $this->context,
                        $container,
                        $dim,
                        $dimOp,
                        $containerOp,
                        $op->issetOnProperty
                    );
                    $this->assignOperandValue($block->getOperand($op->arg1), $issetResult);
                    break;
                case OpCode::TYPE_ITER_RESET:
                    $arrayOp = $block->getOperand($op->arg1);
                    $array = $this->context->getVariableFromOp($arrayOp);
                    if (JIT\GeneratorHelper::isGeneratorVariable($array)) {
                        JIT\GeneratorHelper::compileIterReset($this->context, $array);
                        break;
                    }
                    JIT\IteratorHelper::compileReset(
                        $this->context,
                        $array,
                        self::foreachContainerUserType($arrayOp)
                    );
                    break;
                case OpCode::TYPE_ITER_VALID:
                    $arrayOp = $block->getOperand($op->arg2);
                    $array = $this->context->getVariableFromOp($arrayOp);
                    if (JIT\GeneratorHelper::isGeneratorVariable($array)) {
                        $valid = JIT\GeneratorHelper::compileIterValid($this->context, $array);
                        $this->assignOperandValue($block->getOperand($op->arg1), $valid);
                        break;
                    }
                    $valid = JIT\IteratorHelper::compileValid(
                        $this->context,
                        $array,
                        self::foreachContainerUserType($arrayOp)
                    );
                    $this->assignOperandValue($block->getOperand($op->arg1), $valid);
                    break;
                case OpCode::TYPE_ITER_KEY:
                    $arrayOp = $block->getOperand($op->arg2);
                    $array = $this->context->getVariableFromOp($arrayOp);
                    if (JIT\GeneratorHelper::isGeneratorVariable($array)) {
                        $key = JIT\GeneratorHelper::compileIterKey($this->context, $array);
                        $this->assignOperand($block->getOperand($op->arg1), $key);
                        break;
                    }
                    $key = JIT\IteratorHelper::compileKey(
                        $this->context,
                        $array,
                        self::foreachContainerUserType($arrayOp)
                    );
                    $this->assignOperand($block->getOperand($op->arg1), $key);
                    break;
                case OpCode::TYPE_ITER_VALUE:
                    $arrayOp = $block->getOperand($op->arg2);
                    $array = $this->context->getVariableFromOp($arrayOp);
                    if (JIT\GeneratorHelper::isGeneratorVariable($array)) {
                        if ($op->arg3) {
                            $value = JIT\GeneratorHelper::compileIterValueByRef($this->context, $array, $this);
                            $this->context->setVariableOp($block->getOperand($op->arg1), $value);
                            break;
                        }
                        $value = JIT\GeneratorHelper::compileIterValue($this->context, $array);
                        $this->assignOperand($block->getOperand($op->arg1), $value);
                        break;
                    }
                    if ($op->arg3) {
                        $destOp = $block->getOperand($op->arg1);
                        $destName = JIT\OperandName::resolve($destOp);
                        if (null !== $destName) {
                            $this->context->foreachByRefLocalNames[
                                $this->context->resolveRefAliasName($destName)
                            ] = true;
                        }
                        $value = JIT\IteratorHelper::compileValueByRef(
                            $this->context,
                            $array,
                            self::foreachContainerUserType($arrayOp),
                            $this
                        );
                        $this->context->setVariableOp($destOp, $value);
                        if (null !== $destName) {
                            $this->context->bindVariableByName($destName, $value);
                        }
                        break;
                    }
                    $value = JIT\IteratorHelper::compileValue(
                        $this->context,
                        $array,
                        self::foreachContainerUserType($arrayOp)
                    );
                    $this->assignOperand($block->getOperand($op->arg1), $value);
                    break;
                case OpCode::TYPE_SCRIPT_MAGIC:
                    if (OpCode::SCRIPT_MAGIC_HALT_OFFSET === (int) $op->arg3) {
                        $offset = $block->haltCompilerOffset;
                        if (null === $offset) {
                            throw new \LogicException('Undefined constant "__COMPILER_HALT_OFFSET__"');
                        }
                        $this->assignOperand(
                            $block->getOperand($op->arg1),
                            JIT\Variable::fromConstantInt($this->context, $offset)
                        );
                    } elseif (OpCode::SCRIPT_MAGIC_LINE === (int) $op->arg3) {
                        $line = null !== $op->arg2 ? (int) $op->arg2 : 1;
                        if ($line < 1) {
                            $line = 1;
                        }
                        $this->assignOperand(
                            $block->getOperand($op->arg1),
                            JIT\Variable::fromConstantInt($this->context, $line)
                        );
                    } else {
                        $magicStr = JIT\ScriptMagic::stringForBlock($block, (int) $op->arg3);
                        $lit = new Operand\Literal($magicStr);
                        $lit->type = \PHPTypes\Type::string();
                        $this->assignOperand(
                            $block->getOperand($op->arg1),
                            JIT\Variable::fromLiteral($this->context, $lit)
                        );
                    }
                    break;
                case OpCode::TYPE_INCLUDE:
                    if ($this->context->inlineIncludeDepth > 0) {
                        JIT\IncludeHelper::refreshInlineIncludeBindings($this->context);
                    }
                    JIT\IncludeHelper::compileLiteral(
                        $this,
                        $func,
                        $block,
                        $op,
                        null !== $op->arg2 ? $block->getOperand($op->arg2) : null
                    );
                    break;
                case OpCode::TYPE_CLONE:
                    $srcVar = $this->context->getVariableFromOp($block->getOperand($op->arg2));
                    if (Variable::TYPE_OBJECT === $srcVar->type) {
                        $srcObj = $this->context->helper->loadValue($srcVar);
                    } elseif (Variable::TYPE_VALUE === $srcVar->type) {
                        $valuePtr = JIT\JitValueBox::valuePtrFromVariable($this->context, $srcVar);
                        $srcObj = $this->context->builder->call(
                            $this->context->lookupFunction('__value__readObject'),
                            $valuePtr
                        );
                    } else {
                        throw new \LogicException('clone requires an object');
                    }
                    $cloned = $this->context->type->object->cloneObject($srcObj);
                    $this->context->type->object->invokeCloneMagicIfPresent($block, $cloned);
                    $objVar = new JIT\Variable(
                        $this->context,
                        Variable::TYPE_OBJECT,
                        Variable::KIND_VALUE,
                        $cloned
                    );
                    $this->assignOperand($block->getOperand($op->arg1), $objVar);
                    break;
                case OpCode::TYPE_BOOLEAN_NOT:
                    $from = $this->context->getVariableFromOp($block->getOperand($op->arg2));
                    if ($from->type === Variable::TYPE_NATIVE_BOOL) {
                        $value = $this->context->helper->loadValue($from);
                    } else {
                        $value = $this->context->castToBool($this->context->helper->loadValue($from));
                    }
                    $__right = $value->typeOf()->constInt(1, false);
                            
                        

                        

                        

                        $result = $this->context->builder->bitwiseXor($value, $__right);
    

                    $this->assignOperandValue($block->getOperand($op->arg1), $result);
                    break;
                case OpCode::TYPE_CONCAT:
                    if (null === $op->arg2 || null === $op->arg3) {
                        break;
                    }
                    if (!$this->context->hasVariableOp($block->getOperand($op->arg1))) {
                        // don't bother with constant operations
                        break;
                    }
                    $result = $this->context->getVariableFromOp($block->getOperand($op->arg1));
                    $left = $this->context->getVariableFromOp($block->getOperand($op->arg2));
                    $right = $this->context->getVariableFromOp($block->getOperand($op->arg3));
                    if (null !== $result->objectPropertySlot) {
                        $this->compileObjectPropertyConcatOp($result, $left, $right);
                    } elseif (Variable::TYPE_VALUE === $result->type || JIT\JitValueBox::isValueOperand($result)) {
                        $newVal = $this->compileConcatIntoNewString($left, $right);
                        JIT\JitValueBox::assignToPointer(
                            $this->context,
                            $this->valueBoxPointer($result),
                            $newVal
                        );
                        JIT\JitValueBox::publishAfterWrite(
                            $this->context,
                            $this->valueBoxPointer($result)
                        );
                        if (null !== ($newVal->compileTimeString ?? null)) {
                            $result->compileTimeString = $newVal->compileTimeString;
                        }
                    } else {
                        $this->context->type->string->concat($result, $left, $right);
                    }
                    if (
                        null !== ($left->compileTimeString ?? null)
                        && null !== ($right->compileTimeString ?? null)
                    ) {
                        $result->compileTimeString = $left->compileTimeString.$right->compileTimeString;
                    } else {
                        $leftResolved = $left->compileTimeString
                            ?? $this->resolveJitCompileTimeStringSlot($block, (int) $op->arg2);
                        $rightResolved = $right->compileTimeString
                            ?? $this->resolveJitCompileTimeStringSlot($block, (int) $op->arg3);
                        if (null !== $leftResolved && null !== $rightResolved) {
                            $result->compileTimeString = $leftResolved.$rightResolved;
                        }
                    }
                    $this->maybeRefreshIncludeBindingsBeforeUse();
                    break;
                case OpCode::TYPE_CONST_FETCH:
                    $value = null;
                    if (!is_null($op->arg3)) {
                        // try NS constant fetch
                        $value = $this->context->constantFetch($block->getOperand($op->arg3));
                    }
                    if (is_null($value)) {
                        $value = $this->context->constantFetch($block->getOperand($op->arg2));
                    }
                    if (is_null($value)) {
                        $name = $block->getOperand($op->arg2);
                        $label = $name instanceof Operand\Literal ? (string) $name->value : get_class($name);
                        if (null !== $op->arg3) {
                            $ns = $block->getOperand($op->arg3);
                            if ($ns instanceof Operand\Literal) {
                                $label = (string) $ns->value.'\\'.$label;
                            }
                        }
                        $bundleConst = $this->jitFoldPhpCompilerBundleConstant($label);
                        if (null !== $bundleConst) {
                            $this->assignOperand($block->getOperand($op->arg1), $bundleConst);
                            break;
                        }
                        throw new \RuntimeException('Undefined constant "'.$label.'"');
                    }
                    $this->assignOperand($block->getOperand($op->arg1), $value);
                    break;
                case OpCode::TYPE_CLASS_CONST_FETCH:
                    $classOp = $block->getOperand($op->arg2);
                    $nameOp = $block->getOperand($op->arg3);
                    if ($nameOp instanceof Operand\Literal && 'class' === strtolower($nameOp->value)) {
                        if ($classOp instanceof Operand\Literal) {
                            $className = $this->resolveClassNameForPseudoConst($block, $classOp);
                            $lit = new Operand\Literal($className);
                            $lit->type = Type::string();
                            $this->assignOperand(
                                $block->getOperand($op->arg1),
                                JIT\Variable::fromLiteral($this->context, $lit)
                            );
                            break;
                        }
                        $classVar = $this->context->getVariableFromOp($classOp);
                        if ($op->classConstFetchOnObject) {
                            $classNameVal = JIT\ClassConstFetchHelper::emitExprClassPseudoConst(
                                $this->context->type->object,
                                $classVar
                            );
                        } elseif (JIT\Variable::TYPE_OBJECT === $classVar->type) {
                            $classNameVal = JIT\ReflectionBuiltinHelper::getClassName($this->context, $classVar);
                        } else {
                            $classNameVal = JIT\ClassConstFetchHelper::emitClassPseudoConstStringValue(
                                $this->context->type->object,
                                $block,
                                $classVar
                            );
                        }
                        $this->assignOperandValue($block->getOperand($op->arg1), $classNameVal);
                        break;
                    }
                    if ($classOp instanceof Operand\Literal) {
                        $classId = $this->context->type->object->resolveClassId($classOp);
                        if ($nameOp instanceof Operand\Literal) {
                            if ('native_type_map' === strtolower($nameOp->value) || 'type_map' === strtolower($nameOp->value)) {
                                $classLabel = strtolower($classOp->value);
                                if (str_contains($classLabel, 'variable')) {
                                    $mapVar = $this->jitVariableArrayClassConstant($nameOp->value);
                                    if (null !== $mapVar) {
                                        $this->assignOperand($block->getOperand($op->arg1), $mapVar);
                                        break;
                                    }
                                }
                            }
                            $opcodeConst = $this->jitFoldOpCodeClassConstant($classOp, $nameOp->value);
                            if (null !== $opcodeConst) {
                                $this->assignOperand($block->getOperand($op->arg1), $opcodeConst);
                                break;
                            }
                            JIT\ClassConstVisibilityJitGuard::emitBeforeFetch(
                                $this->context->type->object,
                                $this,
                                $block,
                                $classId,
                                $nameOp->value
                            );
                            if ($this->context->type->object->isEnumClassId($classId)) {
                                JIT\BackedEnumDuplicateJitGuard::emitBeforeEnumCaseFetch(
                                    $this->context->type->object,
                                    $this,
                                    $block,
                                    $classId
                                );
                            }
                            $value = $this->context->type->object->classConstFetch($classId, $nameOp->value, $block);
                            $resultOp = $block->getOperand($op->arg1);
                            if ($this->context->type->object->isEnumClassId($classId)
                                && $classOp instanceof Operand\Literal) {
                                $resultOp->type = Type::object($classOp->value);
                            }
                            $this->assignOperand($resultOp, $value);
                            break;
                        }
                        $nameVar = $this->context->getVariableFromOp($nameOp);
                        $value = $this->context->type->object->classConstFetchDynamic(
                            $classId,
                            $nameVar,
                            $classOp,
                            $block,
                            $this
                        );
                        $this->assignOperand($block->getOperand($op->arg1), $value);
                        break;
                    }
                    $classVar = $this->context->getVariableFromOp($classOp);
                    if ($nameOp instanceof Operand\Literal) {
                        if ('native_type_map' === strtolower($nameOp->value) || 'type_map' === strtolower($nameOp->value)) {
                            break;
                        }
                        $opcodeConst = $this->jitFoldOpCodeClassConstant($classOp, $nameOp->value);
                        if (null !== $opcodeConst) {
                            $this->assignOperand($block->getOperand($op->arg1), $opcodeConst);
                            break;
                        }
                        $value = JIT\ClassConstFetchHelper::fetchLiteralConstWithRuntimeClass(
                            $this->context->type->object,
                            $block,
                            $classVar,
                            $classOp,
                            $nameOp->value,
                            $this
                        );
                        $this->assignOperand($block->getOperand($op->arg1), $value);
                        break;
                    }
                    $nameVar = $this->context->getVariableFromOp($nameOp);
                    $value = JIT\ClassConstFetchHelper::fetchDynamicWithRuntimeClass(
                        $this->context->type->object,
                        $block,
                        $classVar,
                        $nameVar,
                        $classOp
                    );
                    $this->assignOperand($block->getOperand($op->arg1), $value);
                    break;
                case OpCode::TYPE_INSTANCEOF:
                    $expr = $this->context->getVariableFromOp($block->getOperand($op->arg2));
                    $unionEncoded = $op->instanceofUnionTypes;
                    if (null !== $unionEncoded && '' !== $unionEncoded) {
                        $types = array_values(array_filter(explode('|', $unionEncoded), static fn (string $t): bool => '' !== $t));
                        $result = $this->context->type->object->emitInstanceOfUnion($expr, $types);
                        $this->assignOperand($block->getOperand($op->arg1), $result);
                        break;
                    }
                    $result = JIT\InstanceOfHelper::emit(
                        $this->context,
                        $expr,
                        $block->getOperand($op->arg3)
                    );
                    $this->assignOperand($block->getOperand($op->arg1), $result);
                    break;
                case OpCode::TYPE_IN:
                    $needle = $this->context->getVariableFromOp($block->getOperand($op->arg2));
                    $haystack = $this->context->getVariableFromOp($block->getOperand($op->arg3));
                    $found = JIT\InOperatorHelper::emitContains($this->context, $needle, $haystack);
                    $this->assignOperand($block->getOperand($op->arg1), $found);
                    break;
                case OpCode::TYPE_STATIC_PROPERTY_FETCH:
                    $classOp = $block->getOperand($op->arg2);
                    $nameOp = $block->getOperand($op->arg3);
                    $classId = $this->context->type->object->resolveClassId($classOp);
                    $className = $this->context->type->object->classNameForId($classId);
                    if ($nameOp instanceof Operand\Literal) {
                        $forWrite = $this->varFetchDestUsedAsAssignLvalue($block, $i, (int) $op->arg1);
                        if (!$forWrite) {
                            $hookFetched = JIT\PropertyHookDispatch::tryEmitStaticPropertyGet(
                                $this->context,
                                $className,
                                $nameOp->value,
                                $block
                            );
                            if (null !== $hookFetched) {
                                $this->assignOperandValue($block->getOperand($op->arg1), $hookFetched);
                                break;
                            }
                        }
                        JIT\StaticPropertyVisibilityJitGuard::emitBeforeFetch(
                            $this->context->type->object,
                            $this,
                            $block,
                            $classId,
                            $nameOp->value
                        );
                        $fetched = $this->context->type->object->staticPropertyFetch($classId, $nameOp->value);
                        if ($forWrite) {
                            $fetched->staticPropertyHookClassLc = strtolower(ltrim($className, '\\'));
                            $fetched->objectPropertyName = $nameOp->value;
                        }
                    } else {
                        $nameVar = $this->context->getVariableFromOp($nameOp);
                        $fetched = $this->context->type->object->staticPropertyFetchDynamic($classId, $nameVar);
                    }
                    $this->context->setVariableOp($block->getOperand($op->arg1), $fetched);
                    break;
                case OpCode::TYPE_STATIC_PROPERTY_UNSET:
                    $classOp = $block->getOperand($op->arg2);
                    $nameOp = $block->getOperand($op->arg3);
                    $classId = $this->context->type->object->resolveClassId($classOp);
                    if ($nameOp instanceof Operand\Literal) {
                        $this->context->type->object->staticPropertyUnset($classId, $nameOp->value);
                    } else {
                        $nameVar = $this->context->getVariableFromOp($nameOp);
                        $this->context->type->object->staticPropertyUnsetDynamic($classId, $nameVar);
                    }
                    break;
                case OpCode::TYPE_UNSET:
                    if (null === $op->arg3) {
                        $targetOp = null !== $op->arg2
                            ? ($block->operandForScopeSlot($op->arg2) ?? $block->getOperand($op->arg2))
                            : null;
                        if (null === $targetOp) {
                            break;
                        }
                        $this->context->aliasVariableOpFromSlot($block, $targetOp);
                        $unsetName = JIT\OperandName::resolve($targetOp);
                        if (null !== $unsetName && '' !== $unsetName) {
                            $resolvedUnset = $this->context->resolveRefAliasName($unsetName);
                            if (isset($this->context->namedVariableBindings[$resolvedUnset])) {
                                $bound = $this->context->namedVariableBindings[$resolvedUnset];
                                if (
                                    Variable::KIND_VARIABLE === $bound->kind
                                    && Variable::TYPE_VALUE === $bound->type
                                ) {
                                    $this->jitWriteNullForUnset($bound->value);
                                    break;
                                }
                            }
                        }
                        if (
                            !$this->context->hasVariableOp($targetOp)
                            && null === JIT\OperandName::resolve($targetOp)
                        ) {
                            break;
                        }
                        if ($this->context->hasVariableOp($targetOp)) {
                            $target = $this->context->getVariableFromOp($targetOp);
                            if (
                                null !== $target->writableHt
                                && null !== $target->writableStringKey
                                && JIT\Builtin::LOAD_TYPE_STANDALONE === $this->context->loadType
                            ) {
                                JIT\HashTableHelper::unsetStringKey(
                                    $this->context,
                                    $target->writableHt,
                                    $target->writableStringKey
                                );
                                break;
                            }
                            if (
                                Variable::KIND_VARIABLE === $target->kind
                                && Variable::TYPE_VALUE === $target->type
                            ) {
                                $this->jitWriteNullForUnset($target->value);
                                break;
                            }
                            $target->free();
                            $this->context->setVariableOp($targetOp, $this->jitNullVariable());
                        }
                    } else {
                        JIT\UnsetHelper::compileOffset($this->context, $block, $op, $this);
                    }
                    break;
                case OpCode::TYPE_CAST_BOOL:
                    $value = $this->context->getVariableFromOp($block->getOperand($op->arg2));
                    $this->assignOperand($block->getOperand($op->arg1), $value->castTo(Variable::TYPE_NATIVE_BOOL));
                    break;
                case OpCode::TYPE_CAST_INT:
                    $value = $this->context->getVariableFromOp($block->getOperand($op->arg2));
                    $long = ext\standard\JitZendScalarCast::emitIntCast($this->context, $value);
                    $this->assignOperandValue($block->getOperand($op->arg1), $long);
                    break;
                case OpCode::TYPE_CAST_FLOAT:
                    $value = $this->context->getVariableFromOp($block->getOperand($op->arg2));
                    $double = ext\standard\JitZendScalarCast::emitFloatCast($this->context, $value);
                    $this->assignOperandValue($block->getOperand($op->arg1), $double);
                    break;
                case OpCode::TYPE_CAST_STRING:
                    $value = $this->context->getVariableFromOp($block->getOperand($op->arg2));
                    $this->assignOperand(
                        $block->getOperand($op->arg1),
                        JIT\JitNativeString::coerce($this->context, $value)
                    );
                    break;
                case OpCode::TYPE_CAST_VOID:
                    $this->assignOperand($block->getOperand($op->arg1), $this->jitNullVariable());
                    break;
                case OpCode::TYPE_CAST_ARRAY:
                    $value = $this->context->getVariableFromOp($block->getOperand($op->arg2));
                    $this->assignOperand(
                        $block->getOperand($op->arg1),
                        JIT\CastHelper::emitArrayCast($this->context, $value)
                    );
                    break;
                case OpCode::TYPE_CAST_OBJECT:
                    $value = $this->context->getVariableFromOp($block->getOperand($op->arg2));
                    $this->assignOperand(
                        $block->getOperand($op->arg1),
                        JIT\CastHelper::emitObjectCast($this->context, $value, $block, $op)
                    );
                    break;
                case OpCode::TYPE_CAST_UNSET:
                    $value = $this->context->getVariableFromOp($block->getOperand($op->arg2));
                    $this->assignOperand(
                        $block->getOperand($op->arg1),
                        JIT\CastHelper::emitUnsetCast($this->context, $value)
                    );
                    break;
                case OpCode::TYPE_ECHO:
                case OpCode::TYPE_PRINT:
                    if ($this->context->inlineIncludeDepth > 0) {
                        JIT\IncludeHelper::refreshInlineIncludeBindings($this->context);
                    }
                    JIT\Builtin\PendingHeaders::emitFlushForStandalone($this->context);
                    $argOffset = $op->type === OpCode::TYPE_ECHO ? $op->arg1 : $op->arg2;
                    $arg = $this->context->getVariableFromOp($block->getOperand($argOffset));
                    if (Variable::KIND_VARIABLE === $arg->kind) {
                        $slotType = $this->context->getStringFromType($arg->value->typeOf());
                        if ('__value__' === $slotType) {
                            JIT\TypedPropertyUninitGuard::emitBeforeRead($this->context, $arg);
                            JIT\ValueEchoHelper::echo(
                                $this->context,
                                JIT\JitValueBox::pointer($this->context, $arg->value)
                            );
                            break;
                        }
                        if ('__string__' === $slotType && Variable::TYPE_STRING !== $arg->type) {
                            $arg = new Variable(
                                $this->context,
                                Variable::TYPE_STRING,
                                Variable::KIND_VARIABLE,
                                $arg->value
                            );
                        }
                    }
                    switch ($arg->type) {
                        case Variable::TYPE_VALUE:
                            $echoSlot = JIT\JitValueBox::alloc($this->context);
                            JIT\JitValueBox::copyFromPointer(
                                $this->context,
                                $echoSlot,
                                JIT\JitValueBox::valuePtrFromVariable($this->context, $arg)
                            );
                            JIT\ValueEchoHelper::echo(
                                $this->context,
                                JIT\JitValueBox::pointer($this->context, $echoSlot)
                            );
                            break;
                        case Variable::TYPE_STRING:
                            if ($arg->kind === Variable::KIND_VALUE
                                && 'i8*' === $this->context->getStringFromType($arg->value->typeOf())
                            ) {
                                $byte = $this->context->builder->load($arg->value);
                                $this->context->builder->call(
                                    $this->context->lookupFunction('__phpc_ob_echo_char'),
                                    $byte
                                );
                                break;
                            }
                            $argValue = $this->context->helper->loadValue($arg);
                            $offset = $this->context->structFieldIndex($argValue, 'length');
                            $__str__length = $this->context->builder->load(
                                $this->context->builder->structGep($argValue, $offset)
                            );
                            $offset = $this->context->structFieldIndex($argValue, 'value');
                            $__str__value = $this->context->builder->structGep($argValue, $offset);
                            $sizeT = $this->context->getTypeFromString('size_t');
                            $this->context->builder->call(
                                $this->context->lookupFunction('__phpc_ob_echo_substr'),
                                $__str__value,
                                $this->context->builder->zExt($__str__length, $sizeT)
                            );
                            break;
                        case Variable::TYPE_NATIVE_LONG:
                            JIT\ValueEchoHelper::echoNativeLong(
                                $this->context,
                                $this->context->helper->loadValue($arg)
                            );
                            break;
                        case Variable::TYPE_NATIVE_DOUBLE:
                            $argValue = $this->context->helper->loadValue($arg);
                            $this->context->builder->call(
                                $this->context->lookupFunction('__phpc_ob_echo_double'),
                                $argValue
                            );
                            break;
                        case Variable::TYPE_NATIVE_BOOL:
                            $boolVal = $this->context->helper->loadValue($arg);
                            $charPtr = $this->context->getTypeFromString('char*');
                            $trueBlock = JIT\BasicBlockHelper::append($this->context, 'echo_bool_true');
                            $doneBlock = JIT\BasicBlockHelper::append($this->context, 'echo_bool_done');
                            $this->context->builder->branchIf($boolVal, $trueBlock, $doneBlock);
                            $this->context->builder->positionAtEnd($trueBlock);
                            $this->context->builder->call(
                                $this->context->lookupFunction('__phpc_ob_echo_cstr'),
                                $this->context->builder->pointerCast(
                                    $this->context->constantFromString('1'),
                                    $charPtr
                                )
                            );
                            $this->context->builder->branch($doneBlock);
                            $this->context->builder->positionAtEnd($doneBlock);
                            break;

                        case Variable::TYPE_HASHTABLE:
                            JIT\ValueEchoHelper::echoLiteral($this->context, 'Array');
                            break;
                        case Variable::TYPE_OBJECT:
                            $classHint = $block->getOperand($argOffset)->type?->userType ?? null;
                            JIT\ValueEchoHelper::echoObjectVariable(
                                $this->context,
                                $arg,
                                $classHint
                            );
                            break;

                        default:
                            if (0 !== ($arg->type & Variable::IS_NATIVE_ARRAY)) {
                                JIT\ValueEchoHelper::echoLiteral($this->context, 'Array');
                                break;
                            }
                            if (Variable::KIND_VARIABLE === $arg->kind
                                && '__value__' === $this->context->getStringFromType($arg->value->typeOf())
                            ) {
                                JIT\ValueEchoHelper::echo(
                                    $this->context,
                                    JIT\JitValueBox::pointer($this->context, $arg->value)
                                );
                                break;
                            }
                            if (Variable::KIND_VALUE === $arg->kind
                                && '__value__*' === $this->context->getStringFromType($arg->value->typeOf())
                            ) {
                                JIT\ValueEchoHelper::echo($this->context, $arg->value);
                                break;
                            }
                            throw new \LogicException("Echo for type $arg->type not implemented");
                    }
                    if ($op->type === OpCode::TYPE_PRINT) {
                        $this->assignOperand(
                            $block->getOperand($op->arg1),
                            new Variable($this->context, Variable::TYPE_NATIVE_LONG, Variable::KIND_VALUE, $this->context->constantFromInteger(1))
                        );
                    }
                    break;
                case OpCode::TYPE_EXIT:
                    if (null === $op->arg2) {
                        if (JIT\Builtin::LOAD_TYPE_STANDALONE === $this->context->loadType) {
                            JIT\Builtin\PendingHeaders::emitFlushForStandalone($this->context);
                        }
                        $i32 = $this->context->getTypeFromString('int32');
                        $this->context->builder->call(
                            $this->context->lookupFunction('exit'),
                            $i32->constInt(0, false)
                        );
                        break;
                    }
                    $exitArg = $this->context->getVariableFromOp($block->getOperand($op->arg2));
                    if (null !== $op->exitMessageSlot) {
                        $messageArg = $this->context->getVariableFromOp($block->getOperand($op->exitMessageSlot));
                        JIT\Builtin\ScriptExit::emitWithMessage($this->context, $exitArg, $messageArg);
                        break;
                    }
                    JIT\Builtin\ScriptExit::emit($this->context, $exitArg);
                    break;
                case OpCode::TYPE_POW:
                    $pow = new \PHPCompiler\ext\standard\pow();
                    $this->context->powReturnValueBox = true;
                    $powResult = $pow->call(
                        $this->context,
                        $this->context->getVariableFromOp($block->getOperand($op->arg2)),
                        $this->context->getVariableFromOp($block->getOperand($op->arg3))
                    );
                    $this->context->powReturnValueBox = false;
                    $this->assignOperandValue($block->getOperand($op->arg1), $powResult);
                    break;
                case OpCode::TYPE_POST_INC:
                    $this->compileIncDecOp($block, $op, true, false);
                    break;
                case OpCode::TYPE_PRE_INC:
                    $this->compileIncDecOp($block, $op, true, true);
                    break;
                case OpCode::TYPE_POST_DEC:
                    $this->compileIncDecOp($block, $op, false, false);
                    break;
                case OpCode::TYPE_PRE_DEC:
                    $this->compileIncDecOp($block, $op, false, true);
                    break;
                case OpCode::TYPE_MUL:
                case OpCode::TYPE_PLUS:
                case OpCode::TYPE_MINUS:
                    if ($op->isIncDec && (OpCode::TYPE_PLUS === $op->type || OpCode::TYPE_MINUS === $op->type)) {
                        $this->maybeRefreshIncludeBindingsBeforeUse();
                        $left = $this->context->getVariableFromOp($this->operandAt($block, $op->arg2, 'inc/dec left'));
                        $right = $this->context->getVariableFromOp($this->operandAt($block, $op->arg3, 'inc/dec right'));
                        $resultOp = $this->operandAt($block, $op->arg1, 'inc/dec result');
                        $literal = JIT\JitStringArg::compileTimeLiteral($left) ?? JIT\JitStringArg::compileTimeLiteral($right);
                        if (null !== $literal) {
                            $vm = new VM\Variable();
                            $vm->string($literal);
                            if (OpCode::TYPE_PLUS === $op->type) {
                                $vm->applyIncrement();
                            } else {
                                $vm->applyDecrement();
                            }
                            $this->assignOperand($resultOp, $this->jitVariableFromVmConstant($vm), true);
                            break;
                        }
                    }
                    // fall through
                case OpCode::TYPE_DIV:
                case OpCode::TYPE_MODULO:
                case OpCode::TYPE_BITWISE_AND:
                case OpCode::TYPE_BITWISE_OR:
                case OpCode::TYPE_BITWISE_XOR:
                case OpCode::TYPE_SHIFT_LEFT:
                case OpCode::TYPE_SHIFT_RIGHT:
                case OpCode::TYPE_GREATER_OR_EQUAL:
                case OpCode::TYPE_SMALLER_OR_EQUAL:
                case OpCode::TYPE_GREATER:
                case OpCode::TYPE_SMALLER:
                case OpCode::TYPE_IDENTICAL:
                case OpCode::TYPE_NOT_IDENTICAL:
                    $this->maybeRefreshIncludeBindingsBeforeUse();
                    $this->assignOperand(
                        $this->operandAt($block, $op->arg1, opcode_type_name($op->type).' result'),
                        $this->compileBinaryOp(
                            $op,
                            $this->context->getVariableFromOp($this->operandAt($block, $op->arg2, opcode_type_name($op->type).' left')),
                            $this->context->getVariableFromOp($this->operandAt($block, $op->arg3, opcode_type_name($op->type).' right'))
                        )
                    );
                    break;
                case OpCode::TYPE_EQUAL:
                case OpCode::TYPE_NOT_EQUAL:
                case OpCode::TYPE_LOGICAL_XOR:
                case OpCode::TYPE_SPACESHIP:
                    $this->maybeRefreshIncludeBindingsBeforeUse();
                    $this->assignOperand(
                        $this->operandAt($block, $op->arg1, opcode_type_name($op->type).' result'),
                        $this->compileBinaryOp(
                            $op,
                            $this->context->getVariableFromOp($this->operandAt($block, $op->arg2, opcode_type_name($op->type).' left')),
                            $this->context->getVariableFromOp($this->operandAt($block, $op->arg3, opcode_type_name($op->type).' right'))
                        )
                    );
                    break;
                case OpCode::TYPE_UNARY_MINUS:
                case OpCode::TYPE_BITWISE_NOT:
                case OpCode::TYPE_UNARY_PLUS:
                    $this->assignOperand(
                        $block->getOperand($op->arg1),
                        OpCode::TYPE_UNARY_PLUS === $op->type
                            ? JIT\JitUnaryPlus::lower(
                                $this->context,
                                $op,
                                $this->context->getVariableFromOp($block->getOperand($op->arg2)),
                            )
                            : (OpCode::TYPE_UNARY_MINUS === $op->type
                                ? JIT\JitUnaryMinus::lower(
                                    $this->context,
                                    $op,
                                    $this->context->getVariableFromOp($block->getOperand($op->arg2)),
                                )
                                : $this->context->helper->unaryOp(
                                    $op,
                                    $this->context->getVariableFromOp($block->getOperand($op->arg2)),
                                ))
                    );
                    break;
                case OpCode::TYPE_CASE:
                    $branchBlock = $builder->getInsertBlock();
                    $builder->positionAtEnd($branchBlock);
                    $this->maybeRefreshIncludeBindingsBeforeUse();
                    $switchVar = $this->context->getVariableFromOp($this->operandAt($block, $op->arg1, 'switch value'));
                    $caseVar = $this->context->getVariableFromOp($this->operandAt($block, $op->arg2, 'switch case'));
                    $equalOp = new OpCode(OpCode::TYPE_EQUAL);
                    $matchVar = $this->context->helper->binaryOp($equalOp, $switchVar, $caseVar);
                    $match = $this->context->castToBool(
                        $this->context->helper->loadValue($matchVar)
                    );
                    $this->compileBlockInternal($func, $op->block1, null, null, 0, false, ...$args);
                    $caseEntry = $this->jitBranchEntryBlock($op->block1);
                    $nextBb = JIT\BasicBlockHelper::append($this->context, 'switch_next_case');
                    $builder->positionAtEnd($branchBlock);
                    if ($this->shouldFreeDeadVariablesBeforeBranch()) {
                        $this->context->freeDeadVariables($func, $branchBlock, $block);
                    }
                    $builder->branchIf($match, $caseEntry, $nextBb);
                    $builder->positionAtEnd($nextBb);
                    break;
                case OpCode::TYPE_JUMP:
                    JIT\BasicBlockHelper::ensureOpenInsertBlock($this->context, 'jump_cont');
                    $branchBlock = $builder->getInsertBlock();
                    $builder->positionAtEnd($branchBlock);
                    $skippedListUnpackAssign = $this->context->listUnpackSkipAssignPath;
                    $this->context->listUnpackSkipAssignPath = false;
                    $mergeLlvm = null;
                    $allowRecompile = false;
                    if ($this->context->listUnpackMergeLlvmBlocks->contains($op->block1)) {
                        $mergeLlvm = $this->context->listUnpackMergeLlvmBlocks[$op->block1];
                        $allowRecompile = true;
                        $this->context->listUnpackMergeLlvmBlocks->detach($op->block1);
                        if ($skippedListUnpackAssign) {
                            $mergeKey = spl_object_id($op->block1);
                            $this->context->listUnpackMergeNullInitTargets[$mergeKey]
                                = $this->listUnpackAssignTargetsInBlock($block);
                        }
                    }
                    $this->context->listUnpackAssignCallerBlock = $block;
                    $this->compileBlockInternal($func, $op->block1, null, $mergeLlvm, 0, $allowRecompile, ...$args);
                    $this->context->listUnpackAssignCallerBlock = null;
                    $targetEntry = $this->jitBranchEntryBlock($op->block1);
                    if ($this->context->inlineIncludeDepth > 0) {
                        // Use the merge block itself (not getInsertBlock — callee may be cached) (#846, #784).
                        $this->context->inlineIncludeExitBlock = $targetEntry;
                    }
                    $builder->positionAtEnd($branchBlock);
                    if (
                        $this->shouldFreeDeadVariablesBeforeBranch()
                        && !$this->mergeBlockInheritsCallerLocals($op->block1)
                    ) {
                        $this->context->freeDeadVariables($func, $branchBlock, $block);
                    }
                    $builder->branch($targetEntry);
                    return $origBasicBlock;
                case OpCode::TYPE_COALESCE:
                    $branchBlock = $builder->getInsertBlock();
                    $builder->positionAtEnd($branchBlock);
                    $coalesceResult = $block->getOperand($op->arg1);
                    $this->context->coalesceAssignTargets[$coalesceResult] = true;
                    $condition = JIT\CoalesceHelper::isTakeLeftBranch(
                        $this,
                        $this->context->getVariableFromOp($block->getOperand($op->arg2))
                    );
                    // Branch from the block that defined $condition (e.g. sg_sk_done after $_SERVER['key']).
                    // Repositioning to $branchBlock caused invalid LLVM when ?? left uses multi-block reads (#866).
                    $coalesceTestBlock = $builder->getInsertBlock();
                    $leftTail = JIT\CoalesceHelper::compileBranch($this, $func, $op->block1);
                    $rightTail = JIT\CoalesceHelper::compileBranch($this, $func, $op->block2);
                    // Both branches compile; right-side literal metadata must not fold builtins (#764).
                    if ($this->context->hasVariableOp($coalesceResult)) {
                        $coalesceVar = $this->context->getVariableFromOp($coalesceResult);
                        $coalesceVar->compileTimeString = null;
                        $coalesceVar->compileTimeConstantName = null;
                        $coalesceVar->compileTimeEnumCase = null;
                    }
                    $leftEntry = $this->context->scope->blockStorage[$op->block1];
                    $rightEntry = $this->context->scope->blockStorage[$op->block2];
                    $builder->positionAtEnd($coalesceTestBlock);
                    // Do not free php-cfg "dead" operands here; ?? temps are used on branch/merge blocks (#99).
                    $builder->branchIf($condition, $leftEntry, $rightEntry);
                    if (null !== $op->block3) {
                        $mergeBb = JIT\BasicBlockHelper::append($this->context, 'coalesce_merge');
                        $builder->positionAtEnd($leftTail);
                        if (null === $leftTail->getTerminator()) {
                            $builder->branch($mergeBb);
                        }
                        $builder->positionAtEnd($rightTail);
                        if (null === $rightTail->getTerminator()) {
                            $builder->branch($mergeBb);
                        }
                        $builder->positionAtEnd($mergeBb);
                        if ($this->context->inlineIncludeDepth > 0) {
                            JIT\IncludeHelper::refreshInlineIncludeBindings($this->context);
                        }
                        $mergeLimit = JIT\CoalesceHelper::mergeBlockOpcodeLimit($op->block3);
                        $merged = $this->compileBlockInternal($func, $op->block3, $mergeLimit, $mergeBb, 0, false, ...$args);
                        unset($this->context->coalesceAssignTargets[$coalesceResult]);
                        if ($this->context->inlineIncludeDepth > 0) {
                            // Do not set inlineIncludeExitBlock to the ?? merge block (#866, #784).
                            break;
                        }

                        return $merged;
                    }
                    unset($this->context->coalesceAssignTargets[$coalesceResult]);
                    if ($this->context->inlineIncludeDepth > 0) {
                        // Two-branch ?? without merge: continue in the including TU (#866).
                        break;
                    }

                    return $origBasicBlock;
                case OpCode::TYPE_NULLSAFE:
                    $branchBlock = $builder->getInsertBlock();
                    $builder->positionAtEnd($branchBlock);
                    $nullsafeResult = $block->getOperand($op->arg1);
                    $this->context->coalesceAssignTargets[$nullsafeResult] = true;
                    $receiver = $this->context->getVariableFromOp($block->getOperand($op->arg2));
                    $isNull = JIT\NullsafeHelper::isReceiverNull($this, $receiver);
                    // Mirror ?? lowering: branchIf targets entry blocks; merge from branch tails (#3219).
                    $nullTail = JIT\NullsafeHelper::compileBranch($this, $func, $op->block1);
                    $fetchTail = JIT\NullsafeHelper::compileBranch($this, $func, $op->block2);
                    if ($this->context->hasVariableOp($nullsafeResult)) {
                        $nullsafeVar = $this->context->getVariableFromOp($nullsafeResult);
                        $nullsafeVar->compileTimeString = null;
                        $nullsafeVar->compileTimeConstantName = null;
                        $nullsafeVar->compileTimeEnumCase = null;
                    }
                    $nullEntry = $this->context->scope->blockStorage[$op->block1];
                    $fetchEntry = $this->context->scope->blockStorage[$op->block2];
                    $builder->positionAtEnd($branchBlock);
                    // Do not free php-cfg "dead" operands here; ?-> temps are used on branch/merge blocks (#3219).
                    $builder->branchIf($isNull, $nullEntry, $fetchEntry);
                    if (null !== $op->block3) {
                        $mergeBb = JIT\BasicBlockHelper::append($this->context, 'nullsafe_merge');
                        $builder->positionAtEnd($nullTail);
                        if (null === $nullTail->getTerminator()) {
                            $builder->branch($mergeBb);
                        }
                        $builder->positionAtEnd($fetchTail);
                        if (null === $fetchTail->getTerminator()) {
                            $builder->branch($mergeBb);
                        }
                        $builder->positionAtEnd($mergeBb);
                        $mergeLimit = JIT\CoalesceHelper::mergeBlockOpcodeLimit($op->block3);
                        $merged = $this->compileBlockInternal($func, $op->block3, $mergeLimit, $mergeBb, 0, false, ...$args);
                        unset($this->context->coalesceAssignTargets[$nullsafeResult]);

                        return $merged;
                    }
                    unset($this->context->coalesceAssignTargets[$nullsafeResult]);

                    return $origBasicBlock;
                case OpCode::TYPE_JUMPIF:
                    $branchBlock = $builder->getInsertBlock();
                    $builder->positionAtEnd($branchBlock);
                    $this->maybeRefreshIncludeBindingsBeforeUse();
                    $ternaryMergeReturn = null;
                    $savedTernarySharedReturn = $this->context->ternarySharedReturnOperand;
                    $savedTernarySharedReturnSlot = $this->context->ternarySharedReturnSlot;
                    $isTernaryReturnMerge = 0 === $this->context->inlineIncludeDepth
                        && $this->jumpIfTargetsReturnMerge($op->block1, $op->block2);
                    if ($isTernaryReturnMerge) {
                        $mergeBlock = $this->branchJumpMergeBlock($op->block1);
                        assert(null !== $mergeBlock);
                        $ternaryMergeReturn = $this->ternaryReturnPhiOperand($mergeBlock);
                        if (null !== $ternaryMergeReturn) {
                            $this->context->coalesceAssignTargets[$ternaryMergeReturn] = true;
                            $this->context->ternarySharedReturnOperand = $ternaryMergeReturn;
                            foreach ($mergeBlock->opCodes as $mergeOp) {
                                if (OpCode::TYPE_RETURN === $mergeOp->type && null !== $mergeOp->arg1) {
                                    $this->context->ternarySharedReturnSlot = (int) $mergeOp->arg1;
                                    break;
                                }
                            }
                        }
                    }
                    $condition = $this->context->castToBool(
                        $this->context->helper->loadValue(
                            $this->context->getVariableFromOp($this->operandAt($block, $op->arg1, 'branch condition'))
                        )
                    );
                    // If-branch JUMP may compile a shared merge RETURN_VOID before the else/elseif arm
                    // runs; do not let inlineIncludeExitBlock leak across arms (#784, #846, #764).
                    $savedIncludeExit = null;
                    $exitAfterIfBranch = null;
                    if ($this->context->inlineIncludeDepth > 0) {
                        $savedIncludeExit = $this->context->inlineIncludeExitBlock;
                        $this->context->inlineIncludeExitBlock = null;
                    }
                    if ($isTernaryReturnMerge) {
                        $mergeBlock = $this->branchJumpMergeBlock($op->block1);
                        assert(null !== $mergeBlock);
                        $ifString = $this->branchAssignsStringToTernaryPhi($op->block1, $mergeBlock)
                            || (
                                !$this->branchAssignsOnlyNullToTernaryPhi($op->block1, $mergeBlock)
                                && $this->branchAssignsOnlyNullToTernaryPhi($op->block2, $mergeBlock)
                            );
                        $elseString = $this->branchAssignsStringToTernaryPhi($op->block2, $mergeBlock);
                        if ($ifString && !$elseString) {
                            $returnOp = $this->ternaryReturnPhiOperand($mergeBlock);
                            assert(null !== $returnOp);
                            [$firstArm, $secondArm] = $this->ternaryReturnMergeCompileOrder(
                                $op->block1,
                                $op->block2,
                                $mergeBlock
                            );
                            $ifSource = $this->ternaryPhiAssignSourceOperand($op->block1, $mergeBlock);
                            $ifDirectString = null !== $ifSource
                                && Variable::TYPE_STRING === Variable::getTypeFromType($ifSource->type);
                            if ($ifDirectString) {
                                $stringArm = $op->block1;
                                $firstTail = $this->compileSubBlock($func, $firstArm, ...$args);
                                if ($firstArm === $stringArm) {
                                    $this->emitCfgReturnOperand(
                                        $func,
                                        $firstArm,
                                        $returnOp,
                                        $firstTail,
                                        $this->ternaryArmAssignSourceVariable($firstArm, $mergeBlock)
                                    );
                                } else {
                                    $this->emitCfgReturnOperand($func, $firstArm, $returnOp, $firstTail);
                                }
                                $secondTail = $this->compileSubBlock($func, $secondArm, ...$args);
                                if ($secondArm === $stringArm) {
                                    $this->emitCfgReturnOperand(
                                        $func,
                                        $secondArm,
                                        $returnOp,
                                        $secondTail,
                                        $this->ternaryArmAssignSourceVariable($secondArm, $mergeBlock)
                                    );
                                } else {
                                    $this->emitCfgReturnOperand($func, $secondArm, $returnOp, $secondTail);
                                }
                            } else {
                                $firstTail = $this->compileSubBlock($func, $op->block1, ...$args);
                                $this->emitCfgReturnOperand($func, $op->block1, $returnOp, $firstTail);
                                $secondTail = $this->compileSubBlock($func, $op->block2, ...$args);
                                $this->emitCfgReturnOperand($func, $op->block2, $returnOp, $secondTail);
                            }
                        } else {
                            [$firstArm, $secondArm] = $this->ternaryReturnMergeCompileOrder(
                                $op->block1,
                                $op->block2,
                                $mergeBlock
                            );
                            $this->compileBlockInternal($func, $firstArm, null, null, 0, false, ...$args);
                            $this->compileBlockInternal($func, $secondArm, null, null, 0, false, ...$args);
                        }
                        $ifEntry = $this->jitBranchEntryBlock($op->block1);
                        $elseEntry = $this->jitBranchEntryBlock($op->block2);
                        $builder->positionAtEnd($branchBlock);
                        if ($this->shouldFreeDeadVariablesBeforeBranch()) {
                            $this->context->freeDeadVariables($func, $branchBlock, $block);
                        }
                        $builder->branchIf($condition, $ifEntry, $elseEntry);

                        return $origBasicBlock;
                    }
                    $this->compileBlockInternal($func, $op->block1, null, null, 0, false, ...$args);
                    if ($this->context->inlineIncludeDepth > 0) {
                        $exitAfterIfBranch = $this->context->inlineIncludeExitBlock;
                        $this->context->inlineIncludeExitBlock = null;
                    }
                    $this->compileBlockInternal($func, $op->block2, null, null, 0, false, ...$args);
                    if ($this->context->inlineIncludeDepth > 0) {
                        $this->context->inlineIncludeExitBlock = $exitAfterIfBranch
                            ?? $this->context->inlineIncludeExitBlock
                            ?? $savedIncludeExit;
                    }
                    $ifEntry = $this->jitBranchEntryBlock($op->block1);
                    $elseEntry = $this->jitBranchEntryBlock($op->block2);
                    $builder->positionAtEnd($branchBlock);
                    if ($this->shouldFreeDeadVariablesBeforeBranch()) {
                        $this->context->freeDeadVariables($func, $branchBlock, $block);
                    }
                    $builder->branchIf($condition, $ifEntry, $elseEntry);
                    if (null !== $ternaryMergeReturn) {
                        unset($this->context->coalesceAssignTargets[$ternaryMergeReturn]);
                    }
                    $this->context->ternarySharedReturnOperand = $savedTernarySharedReturn;
                    $this->context->ternarySharedReturnSlot = $savedTernarySharedReturnSlot;

                    return $origBasicBlock;
                case OpCode::TYPE_TRY:
                    JIT\TryCatchHelper::beginTry($this, $func, $this->context, $block, $op, $i, $args);

                    return $origBasicBlock;
                case OpCode::TYPE_CATCH:
                    if ([] !== $this->context->tryCatch->handlerStack) {
                        JIT\TryCatchHelper::finishPostTryOpcode($this->context);
                        break;
                    }
                    if (null !== $op->block1) {
                        $this->compileBlockInternal($func, $op->block1, null, null, 0, false, ...$args);
                    }

                    return $origBasicBlock;
                case OpCode::TYPE_FINALLY:
                    if ([] !== $this->context->tryCatch->handlerStack) {
                        JIT\TryCatchHelper::finishPostTryOpcode($this->context);
                        break;
                    }
                    if (null !== $op->block1) {
                        $this->compileBlockInternal($func, $op->block1, null, null, 0, false, ...$args);
                    }

                    return $origBasicBlock;
                case OpCode::TYPE_THROW:
                    JIT\TryCatchHelper::emitThrow($this, $this->context, $func, $block, $op);

                    return $origBasicBlock;
                case OpCode::TYPE_RETHROW:
                    JIT\TryCatchHelper::emitRethrow($this, $this->context, $func, $block);

                    return $origBasicBlock;
                case OpCode::TYPE_RETURN_VOID:
                    $returnBlock = $builder->getInsertBlock();
                    $builder->positionAtEnd($returnBlock);
                    $this->markJitThisConstructedIfLeavingConstruct($block);
                    $this->releaseJitFunctionLocalsAtReturn($block);
                    if ($this->shouldFreeDeadVariablesBeforeBranch()) {
                        $this->context->freeDeadVariables($func, $returnBlock, $block);
                    }
                    if (
                        0 === $this->context->inlineIncludeDepth
                        && JIT\TryCatchHelper::deferReturnIfNeeded($this, $this->context, $func, $block, true, null)
                    ) {
                        return $origBasicBlock;
                    }
                    if (0 === $this->context->inlineIncludeDepth) {
                        if ($block->returnTypeNever) {
                            $neverFunc = null !== $block->func ? $block->func->name : null;
                            JIT\Builtin\TypeErrorRaise::emitRaise(
                                $this->context,
                                null !== $neverFunc && '' !== $neverFunc
                                    ? "{$neverFunc}(): never-returning function must not implicitly return"
                                    : 'A never-returning function must not return'
                            );
                        }
                        if ($this->isVoidLlvmFunction($func)) {
                            $this->context->builder->returnVoid();
                        } else {
                            $expectedReturn = null !== $block->func
                                ? $this->cfgFunctionReturnCallbackType($block->func)
                                : null;
                            $this->context->builder->returnValue(
                                null !== $expectedReturn
                                    ? $this->defaultLlvmReturnValueForCallbackType($expectedReturn, $func)
                                    : $this->defaultLlvmReturnValue($func)
                            );
                        }
                    } else {
                        $this->context->inlineIncludeExitBlock = $returnBlock;
                    }

                    return $this->context->inlineIncludeDepth > 0
                        ? $returnBlock
                        : $origBasicBlock;
                case OpCode::TYPE_RETURN:
                    $return = $this->context->getVariableFromOp($block->getOperand($op->arg1));
                    $returnBlock = $builder->getInsertBlock();
                    $builder->positionAtEnd($returnBlock);
                    $this->markJitThisConstructedIfLeavingConstruct($block);
                    if (
                        0 === $this->context->inlineIncludeDepth
                        && JIT\TryCatchHelper::deferReturnIfNeeded($this, $this->context, $func, $block, false, $return)
                    ) {
                        return $origBasicBlock;
                    }
                    if ($this->context->inlineIncludeDepth > 0) {
                        if ([] !== $this->context->inlineIncludeReturnOperands) {
                            $holderOp = $this->context->inlineIncludeReturnOperands[
                                array_key_last($this->context->inlineIncludeReturnOperands)
                            ];
                            $return->addref();
                            $this->assignOperand($holderOp, $return, true);
                        }
                        $this->context->inlineIncludeExitBlock = $returnBlock;

                        return $returnBlock;
                    }
                    if ($block->returnTypeNever) {
                        $neverFunc = null !== $block->func ? $block->func->name : null;
                        JIT\Builtin\TypeErrorRaise::emitRaise(
                            $this->context,
                            null !== $neverFunc && '' !== $neverFunc
                                ? "{$neverFunc}(): never-returning function must not implicitly return"
                                : 'A never-returning function must not return'
                        );
                    }
                    if ($block->returnTypeVoid) {
                        JIT\Builtin\TypeErrorRaise::registerDeclarations($this->context);
                        JIT\Builtin\TypeErrorRaise::ensureLinked($this->context);
                        JIT\Builtin\TypeErrorRaise::emitRaise(
                            $this->context,
                            'A void function must not return a value'
                        );

                        return $origBasicBlock;
                    }
                    $returnOperand = $block->getOperand($op->arg1);
                    if ($this->shouldFreeDeadVariablesBeforeBranch()) {
                        // php-cfg may mark inline `new class` temps dead before return (#3098).
                        $this->context->freeDeadVariables($func, $returnBlock, $block, $returnOperand);
                    }
                    if ($this->isVoidLlvmFunction($func)) {
                        $this->context->builder->returnVoid();
                    } elseif ($this->cfgFunctionReturnsByRef($block->func)) {
                        $return->addref();
                        $this->context->builder->returnValue(
                            JIT\JitValueBox::valuePtrFromVariable($this->context, $return)
                        );
                    } else {
                        $return->addref();
                        if (null !== $block->returnDnfConstraints) {
                            JIT\DnfParamCheck::enforce(
                                $this->context,
                                $return,
                                $block->returnDnfConstraints,
                                'Return value'
                            );
                        }
                        $retval = $this->context->helper->loadValue($return);
                        $expected = $this->cfgFunctionReturnCallbackType($block->func);
                        if (null === $expected && null !== $this->context->activeFunction) {
                            $expected = $this->context->functionReturnType[strtolower($this->context->activeFunction)] ?? null;
                        }
                        $retval = $this->coerceReturnValue($return, $retval, $expected);
                        $retval = $this->alignRetvalToLlvmFnReturn($retval, $func);
                        $this->context->builder->returnValue($retval);
                    }
    
                    return $origBasicBlock;
                case OpCode::TYPE_FUNCDEF:
                    $nameOp = $block->getOperand($op->arg1);
                    assert($nameOp instanceof Operand\Literal);
                    $this->compileBlock($op->block1, $nameOp->value);
                    break;
                case OpCode::TYPE_CLOSURE:
                    if ($this->shouldStubClosureLowering() || null === $op->block1) {
                        // Bootstrap / vendor prelink: closures are not executable yet; represent as null.
                        $nullVar = new Variable(
                            $this->context,
                            Variable::TYPE_NULL,
                            Variable::KIND_VALUE,
                            $this->context->getTypeFromString('__value__*')->constNull()
                        );
                        $nullVar->isNullConstant = true;
                        $this->assignOperandValue($block->getOperand($op->arg1), $nullVar->value);
                        break;
                    }
                    if (JIT\FiberHelper::blockContainsFiberSuspend($op->block1)) {
                        $internalName = JIT\ClosureHelper::nextInternalName();
                        $resumeName = strtolower($internalName.'__fiber_resume');
                        JIT\FiberHelper::compileResumeFunction(
                            $this,
                            $resumeName,
                            $op->block1,
                            $internalName
                        );
                        $this->context->scriptFiberResumeName = $resumeName;
                        $closureObj = JIT\FiberHelper::allocateFiberCallbackObject(
                            $this->context,
                            $resumeName
                        );
                        $this->assignOperand($block->getOperand($op->arg1), $closureObj, true);
                        break;
                    }
                    $internalName = JIT\ClosureHelper::nextInternalName();
                    $this->compileBlock($op->block1, $internalName);
                    $lcname = strtolower($internalName);
                    if (!isset($this->context->functionProxies[$lcname])) {
                        throw new \LogicException("Closure body failed to register JIT proxy: {$internalName}");
                    }
                    $callProxy = $this->context->functionProxies[$lcname];
                    if ([] !== $op->closureCaptures) {
                        $captures = JIT\ClosureHelper::snapshotCapturesForClosure(
                            $this->context,
                            $op->block1,
                            $op->closureCaptures
                        );
                        $callProxy = JIT\ClosureHelper::wrapCallWithCaptures($callProxy, $captures);
                    }
                    $closureObj = JIT\ClosureHelper::allocateClosureObject(
                        $this->context,
                        $callProxy,
                        $internalName
                    );
                    $isStaticClosure = null !== $op->block1->func
                        && (($op->block1->func->flags ?? 0) & \PHPCfg\Func::FLAG_STATIC) !== 0;
                    if ($isStaticClosure) {
                        $closureObj->closureIsStatic = true;
                        JIT\ClosureBindHelper::storeStaticClosureFlag(
                            $this->context,
                            $this->context->helper->loadValue($closureObj)
                        );
                    }
                    if (null !== $block->func && null !== $block->func->class) {
                        JIT\ClosureBindHelper::ensureClosureBindingProperties($this->context);

                        $scopeName = (string) $block->func->class->value;
                        $scopeConst = $this->context->context->constString($scopeName, true);
                        $boundScope = new Variable(
                            $this->context,
                            Variable::TYPE_STRING,
                            Variable::KIND_VALUE,
                            $scopeConst
                        );
                        $boundScope->compileTimeString = $scopeName;

                        $boundThis = JIT\ClosureHelper::nullCapture($this->context);
                        if (!$isStaticClosure) {
                            $thisVar = $this->context->variableForScopedName('this');
                            if (null !== $thisVar) {
                                $boundThis = JIT\ClosureHelper::snapshotCapture($this->context, $thisVar);
                            }
                        }

                        $obj = $this->context->helper->loadValue($closureObj);
                        $this->context->type->object->storeInstanceProperty(
                            $obj,
                            'Closure',
                            JIT\ClosureBindHelper::BOUND_THIS_PROPERTY,
                            $boundThis
                        );
                        $this->context->type->object->storeInstanceProperty(
                            $obj,
                            'Closure',
                            JIT\ClosureBindHelper::BOUND_SCOPE_PROPERTY,
                            $boundScope
                        );
                        $closureObj->closureCall = new JIT\Call\ClosureWithBinding($callProxy, $boundThis, $boundScope);
                    }
                    $this->assignOperand($block->getOperand($op->arg1), $closureObj, true);
                    break;
                case OpCode::TYPE_YIELD:
                case OpCode::TYPE_YIELD_FROM:
                    if ($this->context->compilingGeneratorResume) {
                        throw new \LogicException('yield should be lowered via GeneratorHelper resume switch (issue #3074)');
                    }
                    throw new \LogicException('Generators (yield) are VM-only (issue #167)');
                case OpCode::TYPE_FUNCCALL_INIT:
                    $nameOp = $block->getOperand($op->arg1);
                    if ($nameOp instanceof Operand\Literal) {
                        $lcname = strtolower($nameOp->value);
                        $this->context->scope->generatorResumeCallee = JIT\GeneratorHelper::creatorResumeName(
                            $this->context,
                            $lcname
                        );
                        $this->context->scope->toCall = $this->context->resolveFunctionProxy($lcname);
                    } else {
                        $nameVar = $this->context->getVariableFromOp($nameOp);
                        $closureCall = JIT\ClosureHelper::resolveCall($this->context, $nameVar);
                        if (null !== $closureCall) {
                            $this->context->scope->toCall = $closureCall;
                            $this->context->scope->args = [];
                            $this->context->scope->argOperands = [];
                            break;
                        }
                        if (null !== $nameOp->type && Type::TYPE_OBJECT === $nameOp->type->type) {
                            $this->initJitMethodCall($block, $nameOp, '__invoke');
                            break;
                        }
                        $nameSlot = $block->slotForOperand($nameOp);
                        if (
                            JIT\BoundMethodCallableHelper::isBoundMethodArrayCallee($nameOp, $nameVar)
                            && $this->tryInitBoundMethodFccDirect($block, $nameSlot)
                        ) {
                            $this->context->scope->argOperands = [];
                            break;
                        }
                        if (null !== $nameSlot) {
                            $this->foldCompileTimeStringFromSlot($block, $nameSlot, $nameVar);
                        }
                        if (null === $nameVar->compileTimeString) {
                            if ($this->shouldUseSelfHostJitStubs()) {
                                $this->context->scope->toCall = null;
                                $this->context->scope->args = [];
                                $this->context->scope->argOperands = [];
                                break;
                            }
                            $hints = array_values(array_unique(array_merge(
                                JIT\VariableFunctionCallHelper::hintedCalleeNames($block, $nameSlot),
                                JIT\VariableFunctionCallHelper::coalesceBranchLiteralHints($block),
                                JIT\VariableFunctionCallHelper::funDefNamesInCompilationUnit($block)
                            )));
                            $this->context->scope->toCall = new JIT\Call\RuntimeVariableFunction($nameVar, $hints);
                        } else {
                            $lcname = strtolower($nameVar->compileTimeString);
                            if (!$this->context->functionIsRegistered($lcname)) {
                                if (str_contains($nameVar->compileTimeString, '::')) {
                                    [$staticClass, $staticMethod] = explode('::', $nameVar->compileTimeString, 2);
                                    if ($this->tryResolveSelfHostSuperglobalsStaticCall($staticClass, $staticMethod)) {
                                        break;
                                    }
                                    if ($this->tryResolveProgressStaticCall($staticClass, $staticMethod)) {
                                        break;
                                    }
                                    throw new \LogicException("Call to undefined static method {$nameVar->compileTimeString}()");
                                }
                                throw new \LogicException("Call to undefined function {$lcname}()");
                            }
                            $this->context->scope->toCall = $this->context->resolveFunctionProxy($lcname);
                        }
                    }
                    $this->context->scope->args = [];
                    $this->context->scope->argOperands = [];
                    break;
                case OpCode::TYPE_STATICCALL_INIT:
                    $this->initJitStaticCall($block, $op->arg1, $op->arg2, $op->staticCallParentScope);
                    break;
                case OpCode::TYPE_ARG_SEND:
                    if ($this->context->inlineIncludeDepth > 0) {
                        JIT\IncludeHelper::refreshInlineIncludeBindings($this->context);
                    }
                    $sendValue = $this->context->getVariableFromOp($block->getOperand($op->arg1));
                    if (null !== $op->arg3) {
                        $this->context->scope->args[] = ['unpack' => $sendValue];
                        $this->context->scope->argOperands[] = $block->getOperand($op->arg1);
                    } elseif (null !== $op->arg2 && isset($block->constants[$op->arg2])) {
                        $this->context->scope->args[] = [
                            'named' => $block->constants[$op->arg2]->toString(),
                            'value' => $sendValue,
                        ];
                        $this->context->scope->argOperands[] = $block->getOperand($op->arg1);
                    } else {
                        $this->context->scope->args[] = $sendValue;
                        $this->context->scope->argOperands[] = $block->getOperand($op->arg1);
                    }
                    break;
                case OpCode::TYPE_FUNCCALL_EXEC_NORETURN:
                    if (is_null($this->context->scope->toCall)) {
                        // short circuit
                        break;
                    }
                    $this->context->callSiteLine = (int) ($op->arg1 ?? 0);
                    [$callArgs, $callOperands] = $this->resolveJitOutgoingCall(
                        $this->context->scope->toCall,
                        $this->context->scope->args,
                        $this->context->scope->argOperands
                    );
                    $callArgs = $this->prependImplicitThisForStaticInstanceCall(
                        $block,
                        $this->context->scope->toCall,
                        $callArgs
                    );
                    if ($this->context->scope->toCall instanceof JIT\Call\Native) {
                        $nativeCall = $this->context->scope->toCall;
                        $callOperands = $this->prependImplicitThisOperandForStaticInstanceCall(
                            $block,
                            $nativeCall,
                            $callOperands
                        );
                        $callArgs = $this->adaptByRefCallArgs($nativeCall, $callArgs, $callOperands);
                    }
                    if ($this->context->scope->toCall instanceof CoreFunc\Internal) {
                        $callArgs = $this->adaptByRefCallArgsForInternal(
                            $this->context->scope->toCall->getName(),
                            $callArgs,
                            $callOperands
                        );
                        $callArgs = $this->foldSortFamilyFlagsArg(
                            $this->context->scope->toCall->getName(),
                            $callArgs,
                            $callOperands,
                            $block
                        );
                    }
                    if (null !== $block->func && '{main}' === $block->func->name) {
                        $toCall = $this->context->scope->toCall;
                        $label = get_class($toCall);
                        if ($toCall instanceof CoreFunc\Internal) {
                            $label .= ':'.$toCall->getName();
                        } elseif ($toCall instanceof JIT\Call\Native) {
                            $label .= ':'.$toCall->name;
                        }
                        JIT\Progress::noteFunction('{main}:call='.$label);
                    }
                    $prevStrict = $this->context->callerStrictTypes;
                    $this->context->callerStrictTypes = $block->strictTypes;
                    $this->emitJitLateStaticCallSiteBinding($callArgs);
                    if ($this->context->scope->toCall instanceof CoreFunc\Internal) {
                        $callArgs = $this->densifyInternalCallArgs($this->context->scope->toCall, $callArgs);
                    }
                    $this->context->scope->toCall->call($this->context, ...$callArgs);
                    JIT\NoDiscardCallGuard::emitAfterDiscardedReturn($this->context, $this->context->scope->toCall);
                    $this->markNewObjectConstructedAfterCall($this->context->scope->toCall, $callArgs);
                    $this->context->callerStrictTypes = $prevStrict;
                    break;
                case OpCode::TYPE_FUNCCALL_EXEC_RETURN:
                    if (is_null($this->context->scope->toCall)) {
                        // Self-host stub/short-circuit (eg runtime variable function): represent as null.
                        $this->context->callSiteLine = (int) ($op->arg2 ?? 0);
                        if ($this->context->scope->preserveNewResultOnNullCall) {
                            $this->context->scope->preserveNewResultOnNullCall = false;
                            break;
                        }
                        $nullVar = new Variable(
                            $this->context,
                            Variable::TYPE_NULL,
                            Variable::KIND_VALUE,
                            $this->context->getTypeFromString('__value__*')->constNull()
                        );
                        $nullVar->isNullConstant = true;
                        $this->assignOperandValue($block->getOperand($op->arg1), $nullVar->value);
                        break;
                    }
                    $this->context->callSiteLine = (int) ($op->arg2 ?? 0);
                    [$callArgs, $callOperands] = $this->resolveJitOutgoingCall(
                        $this->context->scope->toCall,
                        $this->context->scope->args,
                        $this->context->scope->argOperands
                    );
                    $callArgs = $this->prependImplicitThisForStaticInstanceCall(
                        $block,
                        $this->context->scope->toCall,
                        $callArgs
                    );
                    if ($this->context->scope->toCall instanceof JIT\Call\Native) {
                        $nativeCall = $this->context->scope->toCall;
                        $callOperands = $this->prependImplicitThisOperandForStaticInstanceCall(
                            $block,
                            $nativeCall,
                            $callOperands
                        );
                        $callArgs = $this->adaptByRefCallArgs($nativeCall, $callArgs, $callOperands);
                    }
                    if ($this->context->scope->toCall instanceof CoreFunc\Internal) {
                        $callArgs = $this->adaptByRefCallArgsForInternal(
                            $this->context->scope->toCall->getName(),
                            $callArgs,
                            $callOperands
                        );
                        $callArgs = $this->foldSortFamilyFlagsArg(
                            $this->context->scope->toCall->getName(),
                            $callArgs,
                            $callOperands,
                            $block
                        );
                    }
                    if (
                        $this->context->scope->toCall instanceof CoreFunc\Internal
                        && 'parse_url' === strtolower($this->context->scope->toCall->getName())
                        && 2 === count($callArgs)
                        && isset($callOperands[1])
                    ) {
                        $component = \PHPCompiler\ext\standard\JitParseUrl::tryResolveComponent(
                            $this->context,
                            $callArgs[1],
                            $this->context->jitEnclosingBlock,
                            $callOperands[1]
                        );
                        if (null !== $component) {
                            $prevStrict = $this->context->callerStrictTypes;
                            $this->context->callerStrictTypes = $block->strictTypes;
                            $result = \PHPCompiler\ext\standard\JitParseUrl::parseUrl(
                                $this->context,
                                $callArgs[0],
                                Variable::fromConstantInt($this->context, $component)
                            );
                            $this->context->callerStrictTypes = $prevStrict;
                            $this->assignCallResultOperand(
                                $block->getOperand($op->arg1),
                                $result,
                                $this->calleeReturnsByRef($this->context->scope->toCall)
                            );
                            break;
                        }
                    }
                    if (
                        $this->context->scope->toCall instanceof CoreFunc\Internal
                        && 'round' === strtolower($this->context->scope->toCall->getName())
                        && 3 === count($callArgs)
                        && isset($callOperands[2])
                    ) {
                        $mode = \PHPCompiler\ext\standard\JitRoundModeResolve::tryResolveMode(
                            $this->context,
                            $callArgs[2],
                            $block,
                            $callOperands[2]
                        );
                        if (null !== $mode) {
                            $prevStrict = $this->context->callerStrictTypes;
                            $this->context->callerStrictTypes = $block->strictTypes;
                            $result = \PHPCompiler\ext\standard\JitRound::roundWithModeInt(
                                $this->context,
                                $callArgs[0],
                                $callArgs[1],
                                $mode
                            );
                            $this->context->callerStrictTypes = $prevStrict;
                            $this->assignCallResultOperand(
                                $block->getOperand($op->arg1),
                                $result,
                                $this->calleeReturnsByRef($this->context->scope->toCall)
                            );
                            break;
                        }
                    }
                    if (
                        $this->context->scope->toCall instanceof CoreFunc\Internal
                        && 'pathinfo' === strtolower($this->context->scope->toCall->getName())
                        && 2 === count($callArgs)
                        && isset($callOperands[1])
                    ) {
                        $mask = \PHPCompiler\ext\standard\JitPathinfo::tryResolveFlags($this->context, $callArgs[1])
                            ?? \PHPCompiler\ext\standard\JitPathinfo::tryResolveFlagsFromBlock(
                                $this->context,
                                $block,
                                $callOperands[1]
                            );
                        if (null !== $mask) {
                            $prevStrict = $this->context->callerStrictTypes;
                            $this->context->callerStrictTypes = $block->strictTypes;
                            $result = \PHPCompiler\ext\standard\JitPathinfo::invoke(
                                $this->context,
                                $callArgs[0],
                                Variable::fromConstantInt($this->context, $mask)
                            );
                            $this->context->callerStrictTypes = $prevStrict;
                            $this->assignCallResultOperand(
                                $block->getOperand($op->arg1),
                                $result,
                                $this->calleeReturnsByRef($this->context->scope->toCall)
                            );
                            break;
                        }
                    }
                    $resumeName = $this->context->scope->generatorResumeCallee;
                    $this->context->scope->generatorResumeCallee = null;
                    if (null !== $resumeName) {
                        $genVar = JIT\GeneratorHelper::emitCreateFromCall(
                            $this,
                            $resumeName
                        );
                        $this->assignOperandForced($block->getOperand($op->arg1), $genVar);
                        break;
                    }
                    $prevStrict = $this->context->callerStrictTypes;
                    $this->context->callerStrictTypes = $block->strictTypes;
                    $this->emitJitLateStaticCallSiteBinding($callArgs);
                    $savedUnserializeOptionsOperand = $this->context->jitUnserializeOptionsOperand;
                    if (
                        $this->context->scope->toCall instanceof CoreFunc\Internal
                        && 'unserialize' === strtolower($this->context->scope->toCall->getName())
                        && isset($callOperands[1])
                    ) {
                        $this->context->jitUnserializeOptionsOperand = $callOperands[1];
                    }
                    $savedCallUserFuncArrayOperand = $this->context->jitCallUserFuncArrayParamsOperand;
                    if (
                        $this->context->scope->toCall instanceof CoreFunc\Internal
                        && 'call_user_func_array' === strtolower($this->context->scope->toCall->getName())
                        && isset($callOperands[1])
                    ) {
                        $this->context->jitCallUserFuncArrayParamsOperand = $callOperands[1];
                    }
                    $this->promoteCompileTimeStringOnCallArgs($block, $callOperands, $callArgs);
                    if ($this->context->scope->toCall instanceof CoreFunc\Internal) {
                        $callArgs = $this->densifyInternalCallArgs($this->context->scope->toCall, $callArgs);
                    }
                    $result = $this->context->scope->toCall->call($this->context, ...$callArgs);
                    $this->context->jitUnserializeOptionsOperand = $savedUnserializeOptionsOperand;
                    $this->context->jitCallUserFuncArrayParamsOperand = $savedCallUserFuncArrayOperand;
                    $this->markNewObjectConstructedAfterCall($this->context->scope->toCall, $callArgs);
                    $this->context->callerStrictTypes = $prevStrict;
                    $this->assignCallResultOperand(
                        $block->getOperand($op->arg1),
                        $result,
                        $this->calleeReturnsByRef($this->context->scope->toCall)
                    );
                    break;
                case OpCode::TYPE_DECLARE_GLOBAL_CONST:
                    $nameOp = $block->getOperand($op->arg1);
                    assert($nameOp instanceof Operand\Literal);
                    if (isset($block->constants[$op->arg2])) {
                        $constValue = new VM\Variable();
                        $constValue->copyFrom($block->constants[$op->arg2]);
                    } else {
                        if ($this->shouldUseSelfHostJitStubs()) {
                            break;
                        }
                        $vm = new VM($this->context->runtime->vmContext);
                        $frame = $block->getFrame($vm->context);
                        foreach ($block->opCodes as $initOp) {
                            if (OpCode::TYPE_DECLARE_GLOBAL_CONST === $initOp->type && $op->arg2 === $initOp->arg2) {
                                break;
                            }
                            if ($vm->isClassBodyConstInitOpcode($initOp->type)) {
                                $vm->executeClassBodyConstInitOpcode($frame, $initOp);
                            }
                        }
                        if (!isset($frame->scope[$op->arg2])) {
                            throw new \LogicException('Global constant value must be a compile-time constant');
                        }
                        $constValue = new VM\Variable();
                        $constValue->copyFrom($frame->scope[$op->arg2]);
                    }
                    $constValue = VM\EnumCaseSupport::materializeConstantValue(
                        $this->context->runtime->vmContext,
                        $constValue
                    );
                    if ($this->context->runtime->vmContext->defineConstant(
                        $nameOp->value,
                        $constValue
                    )) {
                        $this->registeredGlobalConstDeclareOpcodes->attach($op);
                        if (VM\Variable::TYPE_ARRAY === $constValue->type) {
                            $this->context->constantArrayFromVmHashTable(
                                $nameOp->value,
                                $constValue->toArray()
                            );
                        }
                        break;
                    }
                    // Spine may require bin/vm.php after tokenizer-compat shims (#2134).
                    if ($this->shouldUseSelfHostJitStubs()) {
                        break;
                    }
                    // Re-compile passes (jitCompileBlock + runQueue) may revisit DECLARE_GLOBAL_CONST (#4941).
                    if ($this->registeredGlobalConstDeclareOpcodes->contains($op)) {
                        break;
                    }
                    $scriptPath = $block->scriptPath();
                    $line = (int) ($op->globalConstStartLine ?? 0);
                    $this->context->runtime->vmContext->errors->triggerError(
                        "Constant {$nameOp->value} already defined",
                        VM\ErrorReporter::E_WARNING,
                        null !== $scriptPath && '' !== $scriptPath ? $scriptPath : null,
                        $this->context->runtime->vmContext,
                        null,
                        $line > 0 ? $line : 0
                    );
                    break;
                case OpCode::TYPE_DECLARE_INTERFACE:
                    $nameOp = $block->getOperand($op->arg1);
                    assert($nameOp instanceof Operand\Literal);
                    $this->context->pushScope();
                    $this->context->scope->classId = $this->context->type->object->declareClass($nameOp);
                    $this->context->scope->className = strtolower($nameOp->value);
                    $this->context->type->object->markInterfaceClass($nameOp->value);
                    if (AttributeClassRegistry::isRegisteredAttributeClass($op->attributeEntries)) {
                        $this->context->type->object->markAttributeClass($nameOp->value);
                    }
                    if ([] !== $op->classImplements) {
                        $this->context->type->object->setInterfaceExtends(
                            $nameOp->value,
                            $op->classImplements
                        );
                    }
                    if (null !== $op->block1) {
                        $this->compileClass($op->block1, $this->context->scope->classId);
                    }
                    $this->context->type->object->inheritInterfaceConstants(
                        $this->context->scope->classId,
                        $nameOp->value
                    );
                    $this->context->type->object->inheritInterfacePropertySetVisibility(
                        $this->context->scope->classId,
                        $nameOp->value
                    );
                    $this->context->type->object->propagateInterfaceConstantsToImplementors($nameOp->value);
                    $this->context->popScope();
                    break;
                case OpCode::TYPE_DECLARE_TRAIT:
                    $nameOp = $block->getOperand($op->arg1);
                    assert($nameOp instanceof Operand\Literal);
                    $this->context->pushScope();
                    $this->context->scope->classId = $this->context->type->object->declareClass($nameOp);
                    $this->context->scope->className = strtolower($nameOp->value);
                    $this->context->type->object->markTraitClass($this->context->scope->className);
                    if (AttributeClassRegistry::isRegisteredAttributeClass($op->attributeEntries)) {
                        $this->context->type->object->markAttributeClass($nameOp->value);
                    }
                    if (null !== $this->context->runtime->vmContext) {
                        $lcname = strtolower($nameOp->value);
                        if (!isset($this->context->runtime->vmContext->classes[$lcname])) {
                            $traitEntry = new \PHPCompiler\VM\ClassEntry($nameOp->value);
                            $traitEntry->isTrait = true;
                            $this->context->runtime->vmContext->classes[$lcname] = $traitEntry;
                        }
                    }
                    $this->compileClass($op->block1, $this->context->scope->classId);
                    $this->context->popScope();
                    break;
                case OpCode::TYPE_DECLARE_ENUM:
                    $nameOp = $block->getOperand($op->arg1);
                    assert($nameOp instanceof Operand\Literal);
                    $this->context->pushScope();
                    $this->context->scope->classId = $this->context->type->object->declareEnum($nameOp);
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
                    }
                    $this->context->type->object->inheritInterfaceConstants(
                        $this->context->scope->classId,
                        $nameOp->value
                    );
                    $this->context->type->object->finishEnumClass($this->context->scope->classId);
                    $this->context->popScope();
                    break;
                case OpCode::TYPE_DECLARE_CLASS:
                    $nameOp = $block->getOperand($op->arg1);
                    assert($nameOp instanceof Operand\Literal);
                    $this->context->pushScope();
                    $this->context->scope->classId = $this->context->type->object->declareClass($nameOp);
                    $this->context->scope->className = strtolower($nameOp->value);
                    if (null !== $op->arg3 && isset($block->constants[$op->arg3])) {
                        $this->context->scope->classIsReadonly = VM\ClassFlags::isReadonly(
                            $block->constants[$op->arg3]->toInt()
                        );
                        $this->context->type->object->setClassReadonly(
                            $this->context->scope->classId,
                            $this->context->scope->classIsReadonly
                        );
                    } else {
                        $this->context->scope->classIsReadonly = false;
                    }
                    $parentOp = null;
                    if (null !== $op->arg2) {
                        $parentOp = $block->getOperand($op->arg2);
                        assert($parentOp instanceof Operand\Literal);
                        $this->context->type->object->setClassParentName($nameOp->value, $parentOp->value);
                    }
                    if ([] !== $op->attributeNames || [] !== $op->attributeEntries) {
                        $attrNames = [];
                        foreach ($op->attributeNames as $n) {
                            $attrNames[] = ltrim($n, '\\');
                        }
                        if (AttributeNames::hasAllowDynamicProperties($attrNames)) {
                            $this->context->type->object->setClassAllowsDynamicProperties(
                                $this->context->scope->classId,
                                true
                            );
                        }
                        AttributeRegistry::emitRegisterClass(
                            $this->context,
                            strtolower(ltrim($nameOp->value, '\\')),
                            [] !== $op->attributeEntries ? $op->attributeEntries : $attrNames
                        );
                    }
                    if (AttributeClassRegistry::isRegisteredAttributeClass($op->attributeEntries)) {
                        $this->context->type->object->markAttributeClass($nameOp->value);
                    }
                    $this->compileClass($op->block1, $this->context->scope->classId);
                    if ($parentOp instanceof Operand\Literal) {
                        $this->context->type->object->inheritReadonlyFromParent(
                            $this->context->scope->classId,
                            $parentOp->value
                        );
                        $this->context->type->object->inheritMethodVisibilityFromParent(
                            $this->context->scope->classId,
                            $this->context->scope->className
                        );
                        $this->context->type->object->inheritParentStaticProperties(
                            $this->context->scope->classId,
                            strtolower(ltrim($parentOp->value, '\\'))
                        );
                    }
                    if ([] !== $op->classImplements) {
                        $this->context->type->object->setClassInterfaces(
                            $nameOp->value,
                            $op->classImplements
                        );
                    }
                    $this->context->type->object->inheritInterfaceConstants(
                        $this->context->scope->classId,
                        $nameOp->value
                    );
                    $this->context->type->object->inheritInterfacePropertySetVisibility(
                        $this->context->scope->classId,
                        $nameOp->value
                    );
                    $this->context->popScope();
                    break;
                case OpCode::TYPE_NEW:
                    $classOp = $block->getOperand($op->arg2);
                    if ($classOp instanceof Operand\Literal && 0 === strcasecmp($classOp->value, 'SplObjectStorage')) {
                        $classId = $this->context->type->object->lookup('SplObjectStorage');
                        $obj = new Variable(
                            $this->context,
                            Variable::TYPE_OBJECT,
                            Variable::KIND_VALUE,
                            $this->context->type->object->allocate($classId)
                        );
                        $resultOp = $block->getOperand($op->arg1);
                        $resultOp->type = new Type(Type::TYPE_OBJECT, [], 'SplObjectStorage');
                        $this->assignOperand($resultOp, $obj, true);
                        $this->context->type->object->markObjectConstructed(
                            $this->context->helper->loadValue($obj)
                        );
                        $this->context->scope->preserveNewResultOnNullCall = true;
                        $this->context->scope->toCall = null;
                        $this->context->scope->args = [];
                    } else {
                        if (JIT\LateStaticBindingHelper::operandNeedsRuntimeClassResolution(
                            $classOp,
                            $this->context
                        )) {
                            $classVar = $this->context->getVariableFromOp($classOp);
                            $classIdVal = JIT\ClassConstFetchHelper::emitResolveClassId(
                                $this->context->type->object,
                                $block,
                                $classVar,
                                $classOp
                            );
                            $objVal = $this->context->type->object->allocateForRuntimeClassId($classIdVal);
                            $obj = new Variable(
                                $this->context,
                                Variable::TYPE_OBJECT,
                                Variable::KIND_VALUE,
                                $objVal
                            );
                            $resultOp = $block->getOperand($op->arg1);
                            $this->assignOperand($resultOp, $obj, true);
                            $resultOp->type = new Type(Type::TYPE_OBJECT);
                            $this->context->type->object->markObjectConstructed(
                                $this->context->helper->loadValue($obj)
                            );
                            $this->context->scope->preserveNewResultOnNullCall = true;
                            $this->context->scope->toCall = null;
                            $this->context->scope->args = [];
                        } else {
                            $classId = $this->context->type->object->resolveClassId($classOp);
                            $resolvedName = $this->context->type->object->classNameForId($classId);
                            if (!$this->context->type->object->hasUserDeclaredClass($resolvedName)) {
                                \PHPCompiler\ext\standard\JitSplAutoload::dispatchLiteral(
                                    $this->context,
                                    $resolvedName
                                );
                            }
                            $obj = new Variable(
                                $this->context,
                                Variable::TYPE_OBJECT,
                                Variable::KIND_VALUE,
                                $this->context->type->object->allocate($classId)
                            );
                            $resultOp = $block->getOperand($op->arg1);
                            $this->assignOperand($resultOp, $obj, true);
                            $resultOp->type = new Type(Type::TYPE_OBJECT, [], $resolvedName);
                            if ($classOp instanceof Operand\Literal
                                && 0 === strcasecmp(ltrim($classOp->value, '\\'), 'ReflectionClass')
                            ) {
                                $this->context->scope->toCall = $this->context->resolveFunctionProxy('reflectionclass::__construct');
                                $this->context->scope->args = [$this->context->getVariableFromOp($resultOp)];
                            } elseif ($this->context->type->object->hasConstructor($classId)) {
                                $proxyName = strtolower($resolvedName).'::'.'__construct';
                                $this->context->scope->toCall = $this->context->resolveFunctionProxy($proxyName);
                                $this->context->scope->args = [$this->context->getVariableFromOp($resultOp)];
                            } else {
                                $this->context->scope->preserveNewResultOnNullCall = true;
                                $this->context->type->object->markObjectConstructed(
                                    $this->context->helper->loadValue($obj)
                                );
                                $this->context->scope->toCall = null;
                                $this->context->scope->args = [];
                            }
                        }
                    }
                    break;
                case OpCode::TYPE_METHODCALL_INIT:
                    $receiverOp = $block->getOperand($op->arg1);
                    $nameOp = $block->getOperand($op->arg2);
                    assert($nameOp instanceof Operand\Literal);
                    $this->initJitMethodCall($block, $receiverOp, $nameOp->value);
                    break;
                case OpCode::TYPE_PROPERTY_FETCH:
                    $result = $block->getOperand($op->arg1);
                    $obj = $block->getOperand($op->arg2);
                    $name = $block->getOperand($op->arg3);
                    $propName = $name instanceof Operand\Literal ? $name->value : null;
                    $nonObjectLabel = Variable::propertyFetchNonObjectTypeLabel(
                        Variable::getTypeFromType($obj->type)
                    );
                    if (null !== $nonObjectLabel && null !== $propName) {
                        $forWrite = $this->varFetchDestUsedAsAssignLvalue($block, $i, (int) $op->arg1);
                        if ($forWrite) {
                            if (
                                'null' === $nonObjectLabel
                                && $this->varFetchDestUsedAsIncDec($block, $i, (int) $op->arg1)
                            ) {
                                $message = sprintf(
                                    'Attempt to increment/decrement property "%s" on null',
                                    $propName
                                );
                            } else {
                                $message = sprintf(
                                    'Attempt to assign property "%s" on %s',
                                    $propName,
                                    $nonObjectLabel
                                );
                            }
                            if ([] !== $this->context->tryCatch->handlerStack) {
                                JIT\NonObjectPropertyFetchHelper::lowerNullPropertyDest($this->context, $result);
                                JIT\TryCatchHelper::emitCatchableErrorMessage($this->context, $this, $message);
                            } else {
                                JIT\Builtin\ErrorRaise::emitRaise($this->context, $message);
                                $this->context->builder->call($this->context->lookupFunction('abort'));
                                $this->context->builder->clearInsertionPosition();
                            }
                            break;
                        }
                        if ('null' === $nonObjectLabel) {
                            $message = sprintf('Attempt to read property "%s" on null', $propName);
                            if ([] !== $this->context->tryCatch->handlerStack) {
                                JIT\NonObjectPropertyFetchHelper::lowerNullPropertyDest($this->context, $result);
                                JIT\TryCatchHelper::emitCatchableErrorMessage($this->context, $this, $message);
                            } else {
                                JIT\Builtin\ErrorRaise::emitRaise($this->context, $message);
                                $this->context->builder->call($this->context->lookupFunction('abort'));
                                $this->context->builder->clearInsertionPosition();
                            }
                            break;
                        }
                        JIT\NonObjectPropertyFetchHelper::lowerNonObjectPropertyRead(
                            $this->context,
                            $result,
                            $propName,
                            $nonObjectLabel
                        );
                        break;
                    }
                    assert($obj->type->type === Type::TYPE_OBJECT);
                    $declaringClass = $this->resolvePropertyDeclaringClass($obj, $block, $propName);
                    $receiver = $this->loadPropertyFetchReceiver($obj);
                    $forceBranchMerge = $this->context->coalesceAssignTargets->contains($result);
                    if ($forceBranchMerge) {
                        if (!$this->context->hasVariableOp($result)) {
                            $this->context->makeVariableFromOp($func, $basicBlock, $block, $result);
                        }
                        $mergeVar = $this->context->getVariableFromOp($result);
                        if (Variable::KIND_VALUE === $mergeVar->kind) {
                            $slot = JIT\JitValueBox::alloc($this->context);
                            $this->context->setVariableOp(
                                $result,
                                new Variable(
                                    $this->context,
                                    Variable::TYPE_VALUE,
                                    Variable::KIND_VARIABLE,
                                    $slot
                                )
                            );
                        }
                    }
                    $forWrite = $this->varFetchDestUsedAsAssignLvalue($block, $i, (int) $op->arg1);
                    if ($name instanceof Operand\Literal) {
                        $classId = $this->context->type->object->lookup($declaringClass);
                        if (
                            $forWrite
                            && !$this->context->type->object->hasProperty($classId, $name->value)
                            && JIT\MagicMethodDispatch::hasInstanceMethod(
                                $this->context->type->object,
                                $classId,
                                '__set'
                            )
                        ) {
                            $lvalue = new Variable(
                                $this->context,
                                Variable::TYPE_NULL,
                                Variable::KIND_VALUE,
                                $this->context->getTypeFromString('__value__*')->constNull()
                            );
                            $lvalue->magicSetReceiver = $receiver;
                            $lvalue->magicSetName = $name->value;
                            if ($forceBranchMerge) {
                                $this->assignOperand($result, $lvalue, true);
                            } else {
                                $this->context->scope->variables[$result] = $lvalue;
                            }
                            break;
                        }
                        if (
                            $forWrite
                            && !$this->context->type->object->hasProperty($classId, $name->value)
                            && $this->context->type->object->isReadonlyClass($classId)
                            && !JIT\MagicMethodDispatch::hasInstanceMethod(
                                $this->context->type->object,
                                $classId,
                                '__set'
                            )
                        ) {
                            \PHPCompiler\JIT\Builtin\ErrorRaise::emitRaise(
                                $this->context,
                                sprintf(
                                    'Cannot create dynamic property %s::$%s',
                                    $declaringClass,
                                    $name->value
                                )
                            );
                            $this->context->builder->call($this->context->lookupFunction('abort'));
                            $this->context->builder->clearInsertionPosition();
                            break;
                        }
                        if (
                            $forWrite
                            && !$this->context->type->object->hasProperty($classId, $name->value)
                        ) {
                            JIT\DynamicPropertyDeprecationGuard::emitBeforeUndeclaredWrite(
                                $this->context,
                                $this->context->type->object,
                                $classId,
                                $declaringClass,
                                $name->value
                            );
                        }
                        if (!$forWrite) {
                            $magicFetched = JIT\MagicMethodDispatch::tryEmitMagicGet(
                                $this->context,
                                $receiver,
                                $declaringClass,
                                $name->value,
                                $block
                            );
                            if (null !== $magicFetched) {
                                $this->assignOperandValue($result, $magicFetched);
                                $magicVar = $this->context->getVariableFromOp($result);
                                $magicVar->magicGetOverloadedClass = $declaringClass;
                                $magicVar->magicGetOverloadedName = $name->value;
                                break;
                            }
                        }
                        $hookFetched = JIT\PropertyHookDispatch::tryEmitPropertyGet(
                            $this->context,
                            $receiver,
                            $declaringClass,
                            $name->value,
                            $block
                        );
                        if (null !== $hookFetched) {
                            $this->assignOperandValue($result, $hookFetched);
                            break;
                        }
                        if (JIT\PropertyHookDispatch::emitWriteOnlyVirtualReadGuard(
                            $this->context,
                            $this,
                            $declaringClass,
                            $name->value
                        )) {
                            break;
                        }
                        if (!$forWrite) {
                            JIT\InstancePropertyVisibilityJitGuard::emitBeforeFetch(
                                $this->context->type->object,
                                $this,
                                $block,
                                $classId,
                                $name->value,
                                $declaringClass
                            );
                        }
                        JIT\LazyObjectHelper::emitEnsureInitialized(
                            $this->context,
                            $this->loadPropertyFetchReceiver($obj)
                        );
                        $fetched = $this->context->type->object->propertyFetch(
                            $receiver,
                            $declaringClass,
                            $name->value
                        );
                        if ($forceBranchMerge) {
                            $this->assignOperand($result, $fetched, true);
                        } else {
                            $this->context->scope->variables[$result] = $fetched;
                        }
                        $this->applyExternalPropertyResultType($result, $declaringClass, $name->value);
                    } else {
                        $nameVar = $this->context->getVariableFromOp($name);
                        $fetched = $this->context->type->object->propertyFetchDynamic(
                            $receiver,
                            $declaringClass,
                            $nameVar
                        );
                        if ($forceBranchMerge) {
                            $this->assignOperand($result, $fetched, true);
                        } else {
                            $this->context->scope->variables[$result] = $fetched;
                        }
                    }
                    break;
                case OpCode::TYPE_FROM_CALLABLE:
                    $closureVar = JIT\FromCallableHelper::createClosureVariable($this->context, $block, $op);
                    $this->assignOperand($block->getOperand($op->arg1), $closureVar, true);
                    break;
                case OpCode::TYPE_BEGIN_SILENCE:
                    JIT\ErrorSilenceHelper::beginSilence($this->context);
                    break;
                case OpCode::TYPE_END_SILENCE:
                    JIT\ErrorSilenceHelper::endSilence($this->context);
                    break;
                default:
                    throw new \LogicException("Unknown JIT opcode: ". opcode_type_name($op->type));
            }
        }

        $tail = $builder->getInsertBlock();
        if (
            0 === $this->context->inlineIncludeDepth
            && $this->isVoidLlvmFunction($func)
            && !$block->syntheticCfgBranch
            && null !== $block->func
            && null !== $tail
            && null === $tail->getTerminator()
        ) {
            $builder->positionAtEnd($tail);
            $this->context->freeDeadVariables($func, $tail, $block);
            $this->context->builder->returnVoid();
        }

        return $builder->getInsertBlock();
    }

    /** `return $c ? $a : $b` nullable arm — direct return avoids AOT merge-slot segfault (#8555). */
    private function emitJitReturnFromValue(PHPLLVM\Value $func, Block $block, Variable $value): void
    {
        $builder = $this->context->builder;
        $returnBlock = $builder->getInsertBlock();
        $builder->positionAtEnd($returnBlock);
        $this->markJitThisConstructedIfLeavingConstruct($block);
        if (
            0 === $this->context->inlineIncludeDepth
            && JIT\TryCatchHelper::deferReturnIfNeeded($this, $this->context, $func, $block, false, $value)
        ) {
            return;
        }
        if ($this->context->inlineIncludeDepth > 0) {
            if ([] !== $this->context->inlineIncludeReturnOperands) {
                $holderOp = $this->context->inlineIncludeReturnOperands[
                    array_key_last($this->context->inlineIncludeReturnOperands)
                ];
                $value->addref();
                $this->assignOperand($holderOp, $value, true);
            }
            $this->context->inlineIncludeExitBlock = $returnBlock;

            return;
        }
        if ($block->returnTypeVoid) {
            JIT\Builtin\TypeErrorRaise::registerDeclarations($this->context);
            JIT\Builtin\TypeErrorRaise::ensureLinked($this->context);
            JIT\Builtin\TypeErrorRaise::emitRaise(
                $this->context,
                'A void function must not return a value'
            );

            return;
        }
        if ($this->shouldFreeDeadVariablesBeforeBranch()) {
            $this->context->freeDeadVariables($func, $returnBlock, $block);
        }
        if ($this->isVoidLlvmFunction($func)) {
            $builder->returnVoid();

            return;
        }
        if ($this->cfgFunctionReturnsByRef($block->func)) {
            $value->addref();
            $builder->returnValue(
                JIT\JitValueBox::valuePtrFromVariable($this->context, $value)
            );

            return;
        }
        $value->addref();
        if (null !== $block->returnDnfConstraints) {
            JIT\DnfParamCheck::enforce(
                $this->context,
                $value,
                $block->returnDnfConstraints,
                'Return value'
            );
        }
        $expected = $this->cfgFunctionReturnCallbackType($block->func);
        if (null === $expected && null !== $this->context->activeFunction) {
            $expected = $this->context->functionReturnType[strtolower($this->context->activeFunction)] ?? null;
        }
        $retval = $this->coerceReturnValue($value, $this->context->helper->loadValue($value), $expected);
        $retval = $this->alignRetvalToLlvmFnReturn($retval, $func);
        $builder->returnValue($retval);
    }

    private function coerceReturnValue(Variable $return, PHPLLVM\Value $retval, ?string $expected): PHPLLVM\Value
    {
        if ('__object__*' === $expected && Variable::TYPE_OBJECT === $return->type) {
            return $retval;
        }
        if ('__value__*' === $expected) {
            if (Variable::TYPE_VALUE === $return->type) {
                // Nullable returns use __value__*; copy merge/ternary slots into a fresh
                // return slot instead of returning an interior pointer (#8555).
                $slot = JIT\JitValueBox::alloc($this->context);
                JIT\JitValueBox::copyFromPointer(
                    $this->context,
                    $slot,
                    JIT\JitValueBox::valuePtrFromVariable($this->context, $return)
                );

                return JIT\JitValueBox::pointer($this->context, $slot);
            }
            if (Variable::TYPE_NULL === $return->type) {
                return $this->context->getTypeFromString('__value__*')->constNull();
            }
            if (Variable::TYPE_OBJECT === $return->type) {
                $slot = JIT\JitValueBox::alloc($this->context);
                $this->context->builder->call(
                    $this->context->lookupFunction('__value__writeObject'),
                    JIT\JitValueBox::pointer($this->context, $slot),
                    $retval
                );

                return JIT\JitValueBox::pointer($this->context, $slot);
            }
            if (Variable::TYPE_STRING === $return->type) {
                $slot = JIT\JitValueBox::alloc($this->context);
                $owned = $this->context->builder->call(
                    $this->context->lookupFunction('__string__separate'),
                    $retval
                );
                $this->context->builder->call(
                    $this->context->lookupFunction('__value__writeString'),
                    JIT\JitValueBox::pointer($this->context, $slot),
                    $owned
                );

                return JIT\JitValueBox::pointer($this->context, $slot);
            }

            return $this->context->getTypeFromString('__value__*')->constNull();
        }
        if ('__value__' === $expected) {
            if (Variable::TYPE_VALUE === $return->type) {
                if (Variable::KIND_VARIABLE === $return->kind) {
                    return $this->context->builder->load($return->value);
                }
                if ('__value__*' === $this->context->getStringFromType($retval->typeOf())) {
                    return $this->context->builder->load($retval);
                }

                return $retval;
            }
            if (Variable::TYPE_NULL === $return->type) {
                return $this->loadNullValueStruct();
            }
            if (Variable::TYPE_STRING === $return->type) {
                $slot = JIT\JitValueBox::alloc($this->context);
                $owned = $this->context->builder->call(
                    $this->context->lookupFunction('__string__separate'),
                    $retval
                );
                $this->context->builder->call(
                    $this->context->lookupFunction('__value__writeString'),
                    JIT\JitValueBox::pointer($this->context, $slot),
                    $owned
                );

                return $this->context->builder->load($slot);
            }
            if (Variable::TYPE_OBJECT === $return->type) {
                $slot = JIT\JitValueBox::alloc($this->context);
                $this->context->builder->call(
                    $this->context->lookupFunction('__value__writeObject'),
                    JIT\JitValueBox::pointer($this->context, $slot),
                    $retval
                );

                return $this->context->builder->load($slot);
            }
            if (Variable::TYPE_HASHTABLE === $return->type) {
                $slot = JIT\JitValueBox::alloc($this->context);
                $this->context->builder->call(
                    $this->context->lookupFunction('__value__writeHashtable'),
                    JIT\JitValueBox::pointer($this->context, $slot),
                    $retval
                );

                return $this->context->builder->load($slot);
            }
            if (Variable::TYPE_NATIVE_BOOL === $return->type) {
                $slot = JIT\JitValueBox::alloc($this->context);
                JIT\JitValueBox::writeBool(
                    $this->context,
                    $slot,
                    $this->context->builder->truncOrBitCast(
                        $retval,
                        $this->context->getTypeFromString('int1')
                    )
                );

                return $this->context->builder->load($slot);
            }
            if (Variable::TYPE_NATIVE_LONG === $return->type) {
                $slot = JIT\JitValueBox::alloc($this->context);
                $this->context->builder->call(
                    $this->context->lookupFunction('__value__writeLong'),
                    JIT\JitValueBox::pointer($this->context, $slot),
                    $retval
                );

                return $this->context->builder->load($slot);
            }
            if (Variable::TYPE_NATIVE_DOUBLE === $return->type) {
                $slot = JIT\JitValueBox::alloc($this->context);
                $this->context->builder->call(
                    $this->context->lookupFunction('__value__writeDouble'),
                    JIT\JitValueBox::pointer($this->context, $slot),
                    $retval
                );

                return $this->context->builder->load($slot);
            }

            return $this->loadNullValueStruct();
        }
        if (null === $expected || Variable::TYPE_VALUE !== $return->type) {
            if ('bool' === $expected && Variable::TYPE_NATIVE_BOOL === $return->type) {
                return $this->context->builder->truncOrBitCast(
                    $retval,
                    $this->context->getTypeFromString('int1')
                );
            }
            if (
                ('int64' === $expected || 'long long' === $expected)
                && Variable::TYPE_NATIVE_LONG === $return->type
            ) {
                $i64 = $this->context->getTypeFromString('int64');
                if ($retval->typeOf() !== $i64) {
                    return $this->context->builder->zext($retval, $i64);
                }

                return $retval;
            }
            if ('int32' === $expected && Variable::TYPE_NATIVE_LONG === $return->type) {
                return $this->context->builder->trunc(
                    $retval,
                    $this->context->getTypeFromString('int32')
                );
            }
            if ('__value__' === $expected && Variable::TYPE_STRING === $return->type) {
                $slot = JIT\JitValueBox::alloc($this->context);
                $owned = $this->context->builder->call(
                    $this->context->lookupFunction('__string__separate'),
                    $retval
                );
                $this->context->builder->call(
                    $this->context->lookupFunction('__value__writeString'),
                    JIT\JitValueBox::pointer($this->context, $slot),
                    $owned
                );

                return $this->context->builder->load($slot);
            }
            if ('__string__*' === $expected && Variable::TYPE_NULL === $return->type) {
                return $this->context->getTypeFromString('__string__*')->constNull();
            }
            if ('__hashtable__*' === $expected && Variable::TYPE_NULL === $return->type) {
                return $this->context->getTypeFromString('__hashtable__*')->constNull();
            }
            if ('__hashtable__*' === $expected && Variable::TYPE_HASHTABLE === $return->type) {
                $htPtr = $this->context->getTypeFromString('__hashtable__*');
                if ($retval->typeOf() !== $htPtr) {
                    return $this->context->builder->bitcast($retval, $htPtr);
                }

                return $retval;
            }
            if ('__hashtable__*' === $expected && 0 !== ($return->type & Variable::IS_NATIVE_ARRAY)) {
                return JIT\HashTableHelper::materializeNativeArrayForCall($this->context, $return);
            }
            if ('__string__*' === $expected && Variable::TYPE_VALUE === $return->type) {
                return JIT\JitValueBox::readStringOrNull($this->context, $return);
            }

            return $retval;
        }
        if ('__string__*' === $expected && Variable::TYPE_VALUE === $return->type) {
            return JIT\JitValueBox::readStringOrNull($this->context, $return);
        }
        if ($return->functionStaticGlobal) {
            $valuePtr = JIT\JitValueBox::valuePtrFromVariable($this->context, $return);
        } elseif (Variable::KIND_VARIABLE === $return->kind) {
            $valuePtr = JIT\JitValueBox::pointer($this->context, $return->value);
        } else {
            $valuePtr = JIT\BasicBlockHelper::entryAlloca(
                $this->context,
                $this->context->getTypeFromString('__value__')
            );
            if (Variable::KIND_VALUE === $return->kind) {
                $this->context->builder->store($retval, $valuePtr);
            }
        }
        if ('long long' === $expected || 'int64' === $expected) {
            return $this->context->builder->call(
                $this->context->lookupFunction('__value__readLong'),
                $valuePtr
            );
        }
        if ('double' === $expected) {
            return $this->context->builder->call(
                $this->context->lookupFunction('__value__readDouble'),
                $valuePtr
            );
        }
        if ('bool' === $expected) {
            return $this->context->builder->truncOrBitCast(
                $this->context->builder->call(
                    $this->context->lookupFunction('__value__readLong'),
                    $valuePtr
                ),
                $this->context->getTypeFromString('int1')
            );
        }
        if ('__object__*' === $expected) {
            return $this->context->builder->call(
                $this->context->lookupFunction('__value__readObject'),
                $valuePtr
            );
        }
        if ('__hashtable__*' === $expected) {
            return $this->context->builder->call(
                $this->context->lookupFunction('__value__readHashtable'),
                $valuePtr
            );
        }

        return $retval;
    }

    private function alignRetvalToLlvmFnReturn(PHPLLVM\Value $retval, PHPLLVM\Value $func): PHPLLVM\Value
    {
        $want = null;
        $sig = JIT\BasicBlockHelper::llvmFunctionSignatureType($func);
        if (null !== $sig) {
            $want = $sig->getReturnType();
        }
        if (null === $want && null !== $this->context->activeFunction) {
            $expected = $this->context->functionReturnType[$this->context->activeFunction] ?? null;
            if (null !== $expected && 'void' !== $expected) {
                $want = $this->context->getTypeFromString($expected);
            }
        }
        if (null === $want) {
            return $retval;
        }
        $have = $retval->typeOf();
        if ($want === $have) {
            return $retval;
        }
        $wantStr = $this->context->getStringFromType($want);
        $haveStr = $this->context->getStringFromType($have);
        if (('int1' === $wantStr || 'bool' === $wantStr) && ('int64' === $haveStr || 'long long' === $haveStr || 'int32' === $haveStr)) {
            return $this->context->builder->truncOrBitCast($retval, $want);
        }
        if ('int8' === $haveStr && ('int32' === $wantStr || 'int64' === $wantStr || 'long long' === $wantStr)) {
            return $this->context->builder->zext($retval, $want);
        }
        if ('int32' === $wantStr && ('int64' === $haveStr || 'long long' === $haveStr)) {
            return $this->context->builder->trunc($retval, $want);
        }
        if (('int64' === $wantStr || 'long long' === $wantStr) && ('int32' === $haveStr || 'int1' === $haveStr)) {
            return $this->context->builder->zext($retval, $want);
        }
        if ('__hashtable__*' === $wantStr && '__object__*' === $haveStr) {
            return $this->context->builder->bitcast($retval, $want);
        }
        if ('__object__*' === $wantStr && '__hashtable__*' === $haveStr) {
            return $this->context->builder->bitcast($retval, $want);
        }
        if ('__value__' === $wantStr && '__value__*' === $haveStr) {
            return $this->context->builder->load($retval);
        }
        if ('__value__' === $wantStr && ('int64' === $haveStr || 'long long' === $haveStr)) {
            $slot = JIT\JitValueBox::alloc($this->context);
            $this->context->builder->call(
                $this->context->lookupFunction('__value__writeLong'),
                JIT\JitValueBox::pointer($this->context, $slot),
                $retval
            );

            return $this->context->builder->load($slot);
        }
        if (\PHPLLVM\Type::KIND_INTEGER === $want->getKind() && \PHPLLVM\Type::KIND_INTEGER === $have->getKind()) {
            return $this->context->builder->truncOrBitCast($retval, $want);
        }
        if (\PHPLLVM\Type::KIND_POINTER === $want->getKind() && \PHPLLVM\Type::KIND_POINTER === $have->getKind()) {
            return $this->context->builder->bitcast($retval, $want);
        }

        return $retval;
    }

    private function operandAt(Block $block, ?int $slot, string $context): Operand
    {
        if (null === $slot) {
            throw new \LogicException('Missing operand slot for '.$context);
        }

        return $block->getOperand($slot);
    }

    private function isVoidCfgFunction(Block $block): bool
    {
        return 'void' === $this->cfgFunctionReturnCallbackType($block->func);
    }

    private function isVoidLlvmFunction(PHPLLVM\Value $func): bool
    {
        return JIT\BasicBlockHelper::isVoidLlvmFunctionValue($func);
    }

    private function defaultLlvmReturnValue(PHPLLVM\Value $func): PHPLLVM\Value
    {
        if (null !== $this->context->activeFunction) {
            $expected = $this->context->functionReturnType[$this->context->activeFunction] ?? null;
            if (null !== $expected) {
                return $this->defaultLlvmReturnValueForCallbackType($expected, $func);
            }
        }
        $fnType = JIT\BasicBlockHelper::llvmFunctionSignatureType($func);
        if (null === $fnType) {
            return $this->context->constantFromInteger(0);
        }
        $llvmReturn = $this->context->getStringFromType($fnType->getReturnType());
        if ('unknown' === $llvmReturn && \PHPLLVM\Type::KIND_STRUCT === $fnType->getReturnType()->getKind()) {
            $llvmReturn = '__value__';
        }

        return $this->defaultLlvmReturnValueForCallbackType($llvmReturn, $func);
    }

    private function emitSelfHostStubReturn(string $callbackType, PHPLLVM\Value $func, ?int $longReturn = null): void
    {
        if ('void' === $callbackType) {
            $this->context->builder->returnVoid();
            return;
        }
        $this->context->builder->returnValue(
            $this->defaultLlvmReturnValueForCallbackType($callbackType, $func, $longReturn)
        );
    }

    private function defaultLlvmReturnValueForCallbackType(
        string $callbackType,
        PHPLLVM\Value $func,
        ?int $longReturn = null
    ): PHPLLVM\Value {
        switch ($callbackType) {
            case 'long long':
            case 'int64':
                return $this->context->getTypeFromString('int64')->constInt($longReturn ?? 0, false);
            case 'double':
                return $this->context->getTypeFromString('double')->constReal(0.0);
            case 'bool':
            case 'int1':
                return $this->context->getTypeFromString('bool')->constInt(0, false);
            case '__string__*':
                return $this->context->getTypeFromString('__string__*')->constNull();
            case '__object__*':
                return $this->context->getTypeFromString('__object__*')->constNull();
            case '__hashtable__*':
                return $this->context->getTypeFromString('__hashtable__*')->constNull();
            case '__value__*':
                return $this->context->getTypeFromString('__value__*')->constNull();
            case '__value__':
                $slot = JIT\JitValueBox::alloc($this->context);
                $this->context->builder->call(
                    $this->context->lookupFunction('__value__writeNull'),
                    JIT\JitValueBox::pointer($this->context, $slot)
                );
                return $this->context->builder->load($slot);
            default:
                $fnType = $func->typeOf();
                if ($fnType instanceof \PHPLLVM\Type\Function_) {
                    $returnType = $fnType->getReturnType();
                    if ($this->isValueStructLlvmType($returnType)) {
                        return $this->loadNullValueStruct();
                    }
                    if (\PHPLLVM\Type::KIND_POINTER === $returnType->getKind()) {
                        return $returnType->constNull();
                    }
                    if (\PHPLLVM\Type::KIND_INTEGER === $returnType->getKind()) {
                        return $returnType->constInt(0, false);
                    }
                }
                return $this->context->constantFromInteger(0);
        }
    }

    private function loadNullValueStruct(): PHPLLVM\Value
    {
        $slot = JIT\JitValueBox::alloc($this->context);
        $this->context->builder->call(
            $this->context->lookupFunction('__value__writeNull'),
            JIT\JitValueBox::pointer($this->context, $slot)
        );

        return $this->context->builder->load($slot);
    }

    private function isValueStructLlvmType(PHPLLVM\Type $type): bool
    {
        return $type->toString() === $this->context->getTypeFromString('__value__')->toString();
    }

    private function assignOperandsUsedByLiteralInclude(Block $block, OpCode $op): bool
    {
        if ([] === $block->literalIncludePaths) {
            return false;
        }
        foreach ($block->literalIncludePaths as $path) {
            if (!is_file($path)) {
                continue;
            }
            $code = file_get_contents($path);
            if (false === $code || '' === $code) {
                continue;
            }
            foreach ([$op->arg1, $op->arg2] as $slotIdx) {
                $name = JIT\OperandName::resolve($block->getOperand($slotIdx));
                if (null === $name || '' === $name) {
                    continue;
                }
                if (preg_match('/\\$'.preg_quote($name, '/').'\\b/', $code)) {
                    return true;
                }
            }
        }

        return false;
    }

    private function resolvePropertyDeclaringClass(Operand $obj, Block $block, ?string $propName): string
    {
        $declaringClass = $obj->type->userType ?? null;
        if (null === $declaringClass || '' === $declaringClass) {
            $operandName = strtolower(JIT\OperandName::resolve($obj) ?? '');
            if ('script' === $operandName) {
                $declaringClass = 'PHPCfg\\Script';
            } elseif (in_array($operandName, ['main', 'func'], true)) {
                $declaringClass = 'PHPCfg\\Func';
            } elseif (in_array($operandName, ['cfg', 'block'], true)) {
                $declaringClass = 'PHPCfg\\Block';
            }
        }
        if ((null === $declaringClass || '' === $declaringClass) && null !== $propName) {
            $declaringClass = $this->externalPropertyDeclaringClassFallback(
                $this->context->scope->className,
                $propName
            );
        }
        if (null === $declaringClass && null !== $block->func && null !== $block->func->class) {
            $declaringClass = $block->func->class->value;
        }
        if (null !== $declaringClass && '' !== $declaringClass && '' !== $this->context->scope->className) {
            $funcClassLc = strtolower(ltrim($declaringClass, '\\'));
            $scopeClassLc = strtolower(ltrim($this->context->scope->className, '\\'));
            if (
                $this->context->type->object->isTraitClass($funcClassLc)
                && !$this->context->type->object->isTraitClass($scopeClassLc)
            ) {
                $declaringClass = $this->context->scope->className;
            }
        }
        if (null === $declaringClass || '' === $declaringClass) {
            $declaringClass = $this->context->scope->className !== ''
                ? $this->context->scope->className
                : 'object';
        }

        return $declaringClass;
    }

    private function externalPropertyDeclaringClassFallback(string $scopeClass, string $propName): ?string
    {
        if (!str_starts_with(strtolower($scopeClass), 'phpcompiler\\')) {
            return null;
        }
        $lcProp = strtolower($propName);
        if ('main' === $lcProp) {
            return 'PHPCfg\\Script';
        }
        if ('cfg' === $lcProp) {
            return 'PHPCfg\\Func';
        }

        return null;
    }

    private function applyExternalPropertyResultType(Operand $result, string $declaringClass, string $propName): void
    {
        $userType = $this->externalPropertyResultUserType($declaringClass, $propName);
        if (null === $userType) {
            return;
        }
        $result->type = Type::object($userType);
    }

    private function externalPropertyResultUserType(string $class, string $name): ?string
    {
        $lcClass = strtolower(str_replace('/', '\\', ltrim($class, '\\')));
        $lcName = strtolower($name);
        if (str_starts_with($lcClass, 'phpcfg\\script') && 'main' === $lcName) {
            return 'PHPCfg\\Func';
        }
        if (str_starts_with($lcClass, 'phpcfg\\func') && 'cfg' === $lcName) {
            return 'PHPCfg\\Block';
        }

        return null;
    }

    private function rawTypeFromCfgParam(\PHPCfg\Op\Expr\Param $param): Type
    {
        $declared = $this->declaredTypeFromCfgParam($param);
        if ($param->declaredType instanceof Op\Type\Literal
            && 'mixed' === strtolower($param->declaredType->name)
        ) {
            return Type::mixed();
        }
        if (null !== $declared && Type::TYPE_UNION === $declared->type) {
            return $declared;
        }
        if (null !== $param->result->type && Type::TYPE_NULL !== $param->result->type->type) {
            return $param->result->type;
        }
        if (null !== $declared) {
            return $declared;
        }
        if (null !== $param->result->type) {
            return $param->result->type;
        }

        return Type::mixed();
    }

    private function rawTypeFromCfgReturn(?\PHPCfg\Op\Type $returnType): ?Type
    {
        if (null === $returnType) {
            return null;
        }
        if ($returnType instanceof Op\Type\Literal) {
            return Type::fromDecl($returnType->name);
        }
        if ($returnType instanceof Op\Type\Reference && null !== $returnType->declaration) {
            $inner = $returnType->declaration;
            if ($inner instanceof \PHPCfg\Operand\Literal) {
                return Type::fromDecl($inner->value);
            }
            if ($inner instanceof Op\Type\Literal) {
                return Type::fromDecl($inner->name);
            }
            try {
                return Type::fromTypeDecl($inner);
            } catch (\LogicException) {
                return null;
            }
        }
        try {
            return Type::fromTypeDecl($returnType);
        } catch (\LogicException) {
            return null;
        }
    }

    private function typeIncludesNull(Type $type): bool
    {
        if (Type::TYPE_NULL === $type->type) {
            return true;
        }
        if (Type::TYPE_UNION === $type->type) {
            foreach ($type->subTypes ?? [] as $sub) {
                if ($this->typeIncludesNull($sub)) {
                    return true;
                }
            }
        }

        return false;
    }

    private function cfgParamDeclaredTypeUsesDnfShape(\PHPCfg\Op\Expr\Param $param): bool
    {
        $declared = $param->declaredType;
        if (!$declared instanceof Op\Type) {
            return false;
        }
        if ($declared instanceof Op\Type\Union_ || $declared instanceof Op\Type\Intersection) {
            return true;
        }

        return $declared instanceof Op\Type\Nullable;
    }

    private function cfgParamIsImplicitNullable(Block $block, int $paramIdx): bool
    {
        foreach ($block->opCodes as $op) {
            if (OpCode::TYPE_ARG_RECV !== $op->type || (int) $op->arg2 !== $paramIdx) {
                continue;
            }

            return isset($block->paramImplicitNullable[(int) $op->arg1]);
        }

        return false;
    }

    private function callbackTypeFromPhptype(Type $type): ?string
    {
        $allowsNull = $this->typeIncludesNull($type);
        $type = $this->context->unwrapNullableUnionType($type);
        switch ($type->type) {
            case Type::TYPE_LONG:
                $callback = 'int64';
                break;
            case Type::TYPE_DOUBLE:
                $callback = 'double';
                break;
            case Type::TYPE_BOOLEAN:
                $callback = 'bool';
                break;
            case Type::TYPE_STRING:
                $callback = '__string__*';
                break;
            case Type::TYPE_OBJECT:
                $callback = '__object__*';
                break;
            case Type::TYPE_ARRAY:
                $callback = '__hashtable__*';
                break;
            case Type::TYPE_NULL:
                $callback = '__value__';
                break;
            default:
                $callback = null;
                break;
        }
        if ($allowsNull && null !== $callback && '__value__' !== $callback && '__object__*' !== $callback) {
            return '__value__*';
        }

        return $callback;
    }

    private function cfgFunctionReturnsByRef(?\PHPCfg\Func $cfgFunc): bool
    {
        return null !== $cfgFunc
            && (($cfgFunc->flags ?? 0) & \PHPCfg\Func::FLAG_RETURNS_REF) !== 0;
    }

    /** @param string ...$names logical / proxy function names */
    private function markFunctionReturnsByRef(string ...$names): void
    {
        foreach ($names as $name) {
            $lc = strtolower($name);
            if ('' !== $lc) {
                $this->context->functionReturnsRef[$lc] = true;
            }
        }
    }

    private function calleeReturnsByRef(?JIT\Call $toCall): bool
    {
        if (null === $toCall) {
            return false;
        }
        if ($toCall instanceof JIT\Call\Native || $toCall instanceof JIT\Call\Vararg) {
            return isset($this->context->functionReturnsRef[strtolower($toCall->name)]);
        }
        if ($toCall instanceof JIT\Call\RuntimeIndirectInstanceMethodCall) {
            foreach ($toCall->candidatesByClassId as $candidate) {
                if (
                    $candidate instanceof JIT\Call\Native
                    && isset($this->context->functionReturnsRef[strtolower($candidate->name)])
                ) {
                    return true;
                }
            }
        }

        return false;
    }

    private function assignCallResultOperand(Operand $result, PHPLLVM\Value $llvmResult, bool $returnsByRef): void
    {
        if ('void' === $this->context->getStringFromType($llvmResult->typeOf())) {
            return;
        }
        if (!$returnsByRef) {
            // FUNCCALL_EXEC_RETURN must materialize even when php-cfg dropped result usages
            // (nested f(g()) arg temps — strlen(trim($s)), #8561).
            $this->assignOperandValue($result, $llvmResult, true);

            return;
        }
        if (empty($result->usages) && !$this->context->scope->variables->contains($result)) {
            return;
        }
        $refVar = new Variable(
            $this->context,
            Variable::TYPE_VALUE,
            Variable::KIND_VALUE,
            $llvmResult
        );
        if ('__value__*' === $this->context->getStringFromType($llvmResult->typeOf())) {
            $refVar->valueBoxAliasPtr = JIT\JitValueBox::normalizeValuePtr($this->context, $llvmResult);
        }
        $refVar->addref();
        if (!$this->context->hasVariableOp($result)) {
            $this->context->setVariableOp($result, $refVar);

            return;
        }
        $this->context->setVariableOp($result, $refVar);
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
            switch ($cfgFunc->returnType->name) {
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

    private function compileClass(?Block $block, int $classId) {
        if ($block === null) {
            return;
        }
        $ownMethods = [];
        $traitMethodSources = [];
        /** @var list<string> */
        $pendingTraitNames = [];
        /** @var list<OpCode> */
        $pendingPropertyNewDefaultOps = [];
        $pendingPropertyNewClassName = null;
        foreach ($block->opCodes as $op) {
            if ([] !== $pendingPropertyNewDefaultOps) {
                if (OpCode::TYPE_DECLARE_PROPERTY === $op->type) {
                    $pendingPropertyNewClassName = $this->jitPropertyNewClassNameFromOps($block, $pendingPropertyNewDefaultOps);
                    $pendingPropertyNewDefaultOps = [];
                } elseif (OpCode::TYPE_DECLARE_STATIC_PROPERTY === $op->type
                    || OpCode::TYPE_DECLARE_CLASS_CONST === $op->type) {
                    $pendingPropertyNewDefaultOps = [];
                    $pendingPropertyNewClassName = null;
                } else {
                    $pendingPropertyNewDefaultOps[] = $op;

                    continue;
                }
            } elseif (OpCode::TYPE_NEW === $op->type) {
                $pendingPropertyNewDefaultOps[] = $op;

                continue;
            }
            if (OpCode::TYPE_TRAIT_USE_ADAPTATION === $op->type) {
                if ($this->shouldSkipExternalClassBodyLowering($classId)) {
                    $pendingTraitNames = [];

                    continue;
                }
                $this->applyJitTraitUsesWithAdaptations(
                    $block,
                    $pendingTraitNames,
                    $op->traitAdaptations,
                    $classId,
                    $ownMethods,
                    $traitMethodSources
                );
                $pendingTraitNames = [];

                continue;
            }
            if (OpCode::TYPE_USE_TRAIT !== $op->type) {
                $this->flushPendingJitTraitUses(
                    $block,
                    $pendingTraitNames,
                    $classId,
                    $ownMethods,
                    $traitMethodSources
                );
            }
            switch ($op->type) {
                case OpCode::TYPE_DECLARE_STATIC_PROPERTY:
                    $name = $block->getOperand($op->arg1);
                    assert($name instanceof Operand\Literal);
                    $className = $this->context->scope->className ?? '';
                    $declaredJitType = Variable::getTypeFromType($block->getOperand($op->arg3)->type);
                    if (
                        Variable::TYPE_NATIVE_LONG !== $declaredJitType
                        && Variable::TYPE_STRING !== $declaredJitType
                        && Variable::TYPE_NATIVE_BOOL !== $declaredJitType
                        && Variable::TYPE_NATIVE_DOUBLE !== $declaredJitType
                        && Variable::TYPE_HASHTABLE !== $declaredJitType
                    ) {
                        $declaredJitType = $this->context->type->object->externalPropertyJitType(
                            $className,
                            $name->value
                        );
                    }
                    $default = (null !== $op->arg2 && isset($block->constants[$op->arg2]))
                        ? $block->constants[$op->arg2]
                        : null;
                    $prototype = (null !== $op->arg3 && isset($block->constants[$op->arg3]))
                        ? $block->constants[$op->arg3]
                        : null;
                    $this->context->type->object->defineStaticProperty(
                        $classId,
                        $name->value,
                        $declaredJitType,
                        $default,
                        $prototype,
                        false,
                        \PHPCompiler\MethodVisibility::mask($op->propertyVisibility)
                    );
                    if (null !== $prototype && null !== $prototype->dnfArms) {
                        $this->context->type->object->defineStaticPropertyDnfArms(
                            $classId,
                            $name->value,
                            $prototype->dnfArms
                        );
                    }
                    $this->context->type->object->defineStaticPropertySetVisibility(
                        $classId,
                        $name->value,
                        (int) ($op->propertySetVisibility ?? 0)
                    );
                    $this->context->type->object->defineStaticPropertyGetVisibility(
                        $classId,
                        $name->value,
                        (int) ($op->propertyGetVisibility ?? 0)
                    );
                    break;
                case OpCode::TYPE_DECLARE_PROPERTY:
                    $name = $block->getOperand($op->arg1);
                    assert($name instanceof Operand\Literal);
                    $className = $this->context->scope->className ?? '';
                    $declaredJitType = Variable::getTypeFromType($block->getOperand($op->arg3)->type);
                    if (Variable::TYPE_HASHTABLE === $declaredJitType || Variable::TYPE_STRING === $declaredJitType) {
                        $jitType = $declaredJitType;
                        $lcClass = strtolower(str_replace('/', '\\', ltrim($className, '\\')));
                        if (
                            !str_starts_with($lcClass, 'phpcfg\\')
                            && !str_starts_with($lcClass, 'phpcompiler\\')
                        ) {
                            if (Variable::TYPE_HASHTABLE === $declaredJitType) {
                                $jitType = Variable::TYPE_VALUE;
                            }
                            // User string properties: boxed __value__ slots (fetch/store parity, #4598).
                            if (Variable::TYPE_STRING === $declaredJitType) {
                                $jitType = Variable::TYPE_VALUE;
                            }
                        }
                    } else {
                        $lcClass = strtolower(str_replace('/', '\\', ltrim($className, '\\')));
                        if (
                            !str_starts_with($lcClass, 'phpcfg\\')
                            && !str_starts_with($lcClass, 'phpcompiler\\')
                        ) {
                            // User classes: native slots for declared scalars (VALUE-box fetch segfaults MCJIT, #5111).
                            $jitType = $declaredJitType;
                            $propType = $block->getOperand($op->arg3)->type;
                            $userType = is_object($propType) ? ($propType->userType ?? null) : null;
                            if (is_string($userType) && 0 === strcasecmp($userType, 'SplObjectStorage')) {
                                // Boxed object slots: native TYPE_OBJECT property fetch breaks method calls (#8422).
                                $jitType = Variable::TYPE_VALUE;
                            }
                        } else {
                            $jitType = $this->context->type->object->externalPropertyJitType(
                                $className,
                                $name->value
                            );
                        }
                    }
                    $this->context->type->object->defineProperty($classId, $name->value, $jitType);
                    if (null !== $op->arg3 && isset($block->constants[$op->arg3])) {
                        $proto = $block->constants[$op->arg3];
                        if (null !== $proto->dnfArms) {
                            $this->context->type->object->definePropertyDnfArms(
                                $classId,
                                $name->value,
                                $proto->dnfArms
                            );
                        }
                        if (\PHPCompiler\VM\TypedPropertyCheck::propertyAllowsNull($proto)) {
                            $this->context->type->object->markPropertyAllowsNull($classId, $name->value);
                        }
                    }
                    $this->context->type->object->definePropertyVisibility(
                        $classId,
                        $name->value,
                        \PHPCompiler\MethodVisibility::mask($op->propertyVisibility)
                    );
                    $this->context->type->object->definePropertySetVisibility(
                        $classId,
                        $name->value,
                        (int) ($op->propertySetVisibility ?? 0)
                    );
                    $this->context->type->object->definePropertyGetVisibility(
                        $classId,
                        $name->value,
                        (int) ($op->propertyGetVisibility ?? 0)
                    );
                    if ($op->propertyReadonly || $this->context->scope->classIsReadonly) {
                        $this->context->type->object->markPropertyReadonly($classId, $name->value);
                    }
                    if (null !== $op->arg2 && isset($block->constants[$op->arg2])) {
                        $this->context->type->object->definePropertyDefault(
                            $classId,
                            $name->value,
                            $block->constants[$op->arg2]
                        );
                    }
                    if (null !== $pendingPropertyNewClassName) {
                        $this->context->type->object->definePropertyRuntimeNewDefault(
                            $classId,
                            $name->value,
                            $pendingPropertyNewClassName
                        );
                        $pendingPropertyNewClassName = null;
                    }
                    if ([] !== $op->attributeNames) {
                        $classLc = '' !== $this->context->scope->className
                            ? strtolower(ltrim($this->context->scope->className, '\\'))
                            : strtolower(ltrim($this->context->type->object->classNameForId($this->context->scope->classId), '\\'));
                        if ('' !== $classLc) {
                            $attrNames = [];
                            foreach ($op->attributeNames as $n) {
                                $attrNames[] = ltrim($n, '\\');
                            }
                            AttributeRegistry::emitRegisterMethod(
                                $this->context,
                                $classLc,
                                strtolower($name->value),
                                $attrNames
                            );
                        }
                    }
                    break;
                case OpCode::TYPE_CONST_FETCH:
                case OpCode::TYPE_CLASS_CONST_FETCH:
                case OpCode::TYPE_INIT_ARRAY:
                case OpCode::TYPE_ARG_SEND:
                case OpCode::TYPE_FUNCCALL_EXEC_NORETURN:
                case OpCode::TYPE_FUNCCALL_EXEC_RETURN:
                    // Default property values are initialized in __object__ allocation.
                    // Object class constants are materialized at TYPE_DECLARE_CLASS_CONST (#3196).
                    break;
                case OpCode::TYPE_PLUS:
                case OpCode::TYPE_MINUS:
                case OpCode::TYPE_MUL:
                case OpCode::TYPE_DIV:
                case OpCode::TYPE_MODULO:
                case OpCode::TYPE_POW:
                case OpCode::TYPE_BITWISE_AND:
                case OpCode::TYPE_BITWISE_OR:
                case OpCode::TYPE_BITWISE_XOR:
                case OpCode::TYPE_SHIFT_LEFT:
                case OpCode::TYPE_SHIFT_RIGHT:
                case OpCode::TYPE_UNARY_MINUS:
                case OpCode::TYPE_UNARY_PLUS:
                case OpCode::TYPE_BITWISE_NOT:
                case OpCode::TYPE_BOOLEAN_NOT:
                case OpCode::TYPE_CONCAT:
                case OpCode::TYPE_ARRAY_DIM_FETCH:
                case OpCode::TYPE_PROPERTY_FETCH:
                    // Scalar class const expressions — evaluated in jitClassConstDefineValue (#5394).
                    break;
                case OpCode::TYPE_DECLARE_METHOD:
                    $name = $block->getOperand($op->arg1);
                    assert($name instanceof Operand\Literal);
                    $methodLc = strtolower($name->value);
                    $ownMethods[$methodLc] = true;
                    if ([] !== $op->attributeNames) {
                        $classLc = '' !== $this->context->scope->className
                            ? strtolower(ltrim($this->context->scope->className, '\\'))
                            : strtolower(ltrim($this->context->type->object->classNameForId($this->context->scope->classId), '\\'));
                        if ('' !== $classLc) {
                            $attrNames = [];
                            foreach ($op->attributeNames as $n) {
                                $attrNames[] = ltrim($n, '\\');
                            }
                            AttributeRegistry::emitRegisterMethod(
                                $this->context,
                                $classLc,
                                $methodLc,
                                $attrNames
                            );
                        }
                    }
                    $visFlags = \PHPCfg\Func::FLAG_PUBLIC;
                    if (null !== $op->arg3 && isset($block->constants[$op->arg3])) {
                        $storedFlags = $block->constants[$op->arg3]->toInt();
                        $visFlags = MethodVisibility::mask($storedFlags);
                        if (($storedFlags & \PHPCfg\Func::FLAG_STATIC) !== 0) {
                            $visFlags |= \PHPCfg\Func::FLAG_STATIC;
                        }
                        if (($storedFlags & \PHPCfg\Func::FLAG_FINAL) !== 0) {
                            $visFlags |= \PHPCfg\Func::FLAG_FINAL;
                        }
                    }
                    $methodBlock = $op->block1;
                    if (null !== $methodBlock && null !== $methodBlock->func
                        && (($methodBlock->func->flags ?? 0) & \PHPCfg\Func::FLAG_STATIC) !== 0) {
                        $visFlags |= \PHPCfg\Func::FLAG_STATIC;
                    }
                    $this->context->type->object->defineMethodVisibility(
                        $classId,
                        $methodLc,
                        $visFlags,
                        $name->value
                    );
                    if (($this->isBundledSuperglobalsClass($classId) || $this->shouldSkipExternalClassBodyLowering($classId))
                        && 'issuperglobalname' !== $methodLc
                    ) {
                        break;
                    }
                    $methodBlock = $op->block1;
                    $className = null !== $methodBlock && null !== $methodBlock->func && null !== $methodBlock->func->class
                        ? strtolower($methodBlock->func->class->value)
                        : $this->context->scope->className;
                    $funcName = $className.'::'.$methodLc;
                    if (null !== $methodBlock) {
                        if ('__construct' === $methodLc) {
                            $this->context->type->object->markHasConstructor($this->context->scope->classId);
                        }
                        if ('__destruct' === $methodLc) {
                            $this->context->type->object->recordDestructorBlock(
                                $this->context->scope->classId,
                                $methodBlock
                            );
                        }
                        if ($this->context->type->object->isTraitClass($this->context->scope->className ?? '')) {
                            $this->context->type->object->recordTraitMethodBlock(
                                $this->context->scope->classId,
                                $methodLc,
                                $methodBlock
                            );
                            break;
                        }
                        $this->compileBlock($methodBlock, $funcName);
                    }
                    break;
                case OpCode::TYPE_DECLARE_CLASS_CONST:
                    $name = $block->getOperand($op->arg1);
                    assert($name instanceof Operand\Literal);
                    $constNameLc = strtolower($name->value);
                    $constValue = $this->jitClassConstDefineValue($block, $op, $constNameLc, $classId);
                    if (!isset($block->constants[$op->arg2])) {
                        if ($this->shouldSkipExternalClassBodyLowering($classId)) {
                            break;
                        }
                        if ($this->context->type->object->isEnumClassId($classId) && $op->isEnumCaseDeclare) {
                            $this->context->type->object->defineEnumCaseConst($classId, $name->value, $constValue);
                            break;
                        }
                        $enumCaseRef = $this->tryResolveEnumCaseClassConstInit($block, $op->arg2);
                        if (null !== $enumCaseRef) {
                            $this->context->type->object->defineClassConstEnumCaseRef(
                                $classId,
                                $name->value,
                                $enumCaseRef[0],
                                $enumCaseRef[1]
                            );
                            break;
                        }
                    $this->context->type->object->defineClassConst(
                        $classId,
                        $name->value,
                        $constValue
                    );
                    $this->context->type->object->defineClassConstVisibility(
                        $classId,
                        $name->value,
                        $op->classConstVisibilityFlags
                    );
                    break;
                }
                if ($this->context->type->object->isEnumClassId($classId) && $op->isEnumCaseDeclare) {
                        $this->context->type->object->defineEnumCaseConst(
                            $classId,
                            $name->value,
                            $constValue
                        );
                        break;
                    }
                $this->context->type->object->defineClassConst(
                    $classId,
                    $name->value,
                    $constValue
                );
                $this->context->type->object->defineClassConstVisibility(
                    $classId,
                    $name->value,
                    $op->classConstVisibilityFlags
                );
                if ([] !== $op->attributeNames) {
                        $classLc = '' !== $this->context->scope->className
                            ? strtolower(ltrim($this->context->scope->className, '\\'))
                            : strtolower(ltrim($this->context->type->object->classNameForId($this->context->scope->classId), '\\'));
                        if ('' !== $classLc) {
                            $attrNames = [];
                            foreach ($op->attributeNames as $n) {
                                $attrNames[] = ltrim($n, '\\');
                            }
                            AttributeRegistry::emitRegisterMethod(
                                $this->context,
                                $classLc,
                                strtolower($name->value),
                                $attrNames
                            );
                        }
                    }
                    break;
                case OpCode::TYPE_USE_TRAIT:
                    if ($this->shouldSkipExternalClassBodyLowering($classId)) {
                        break;
                    }
                    $traitOp = $block->getOperand($op->arg1);
                    assert($traitOp instanceof Operand\Literal);
                    $pendingTraitNames[] = (string) $traitOp->value;
                    break;
                default:
                    if ($this->shouldSkipExternalClassBodyLowering($classId)) {
                        break;
                    }
                    throw new \LogicException('Other class body types are not jittable for now');
            }
        }
        $this->flushPendingJitTraitUses(
            $block,
            $pendingTraitNames,
            $classId,
            $ownMethods,
            $traitMethodSources
        );
        $this->context->type->object->definePendingUndeclaredInstanceProperties(
            $classId,
            $this->context->scope->className ?? ''
        );
    }

    /**
     * @param list<OpCode> $pendingOps
     */
    private function jitPropertyNewClassNameFromOps(Block $block, array $pendingOps): ?string
    {
        foreach (array_reverse($pendingOps) as $newOp) {
            if (OpCode::TYPE_NEW !== $newOp->type) {
                continue;
            }
            $classOp = $block->getOperand($newOp->arg2);
            if (!$classOp instanceof Operand\Literal) {
                return null;
            }

            return $classOp->value;
        }

        return null;
    }

    /**
     * @param list<string> $pendingTraitNames
     * @param array<string, true> $ownMethods
     * @param array<string, string> $traitMethodSources
     */
    private function flushPendingJitTraitUses(
        Block $block,
        array &$pendingTraitNames,
        int $classId,
        array $ownMethods,
        array &$traitMethodSources
    ): void {
        if ([] === $pendingTraitNames || $this->shouldSkipExternalClassBodyLowering($classId)) {
            $pendingTraitNames = [];

            return;
        }
        $this->applyJitTraitUsesWithAdaptations(
            $block,
            $pendingTraitNames,
            [],
            $classId,
            $ownMethods,
            $traitMethodSources
        );
        $pendingTraitNames = [];
    }

    /**
     * Merge trait methods/constants onto a using class (Zend zend_compile_traits; #3238).
     *
     * @param list<string> $traitNames
     * @param list<array<string, mixed>> $adaptations
     * @param array<string, true> $ownMethods
     * @param array<string, string> $traitMethodSources method lc => trait FQCN
     */
    private function applyJitTraitUsesWithAdaptations(
        Block $block,
        array $traitNames,
        array $adaptations,
        int $classId,
        array $ownMethods,
        array &$traitMethodSources
    ): void {
        if ([] === $traitNames) {
            return;
        }
        $dedupedTraitNames = [];
        $seenTraitLc = [];
        foreach ($traitNames as $traitName) {
            $traitLc = strtolower(ltrim($traitName, '\\'));
            if (isset($seenTraitLc[$traitLc])) {
                continue;
            }
            $seenTraitLc[$traitLc] = true;
            $dedupedTraitNames[] = $traitName;
        }
        $traitNames = $dedupedTraitNames;
        if ([] === $traitNames) {
            return;
        }
        $classLc = '' !== ($this->context->scope->className ?? '')
            ? strtolower(ltrim($this->context->scope->className, '\\'))
            : strtolower(ltrim($this->context->type->object->classNameForId($classId), '\\'));
        $className = $this->context->type->object->classNameForId($classId);
        $object = $this->context->type->object;
        $excluded = $ownMethods;
        $visited = [];
        $current = $object->parentClassLc($classLc);
        while (null !== $current && !isset($visited[$current])) {
            $visited[$current] = true;
            if (!$object->hasDeclaredClass($current)) {
                break;
            }
            $parentId = $object->lookup($current);
            foreach ($object->declaredMethodNames($parentId) as $methodLc) {
                $excluded[$methodLc] = true;
            }
            $current = $object->parentClassLc($current);
        }

        /** @var array<string, array<string, array{traitId: int, traitName: string, traitLc: string, methodLc: string}>> */
        $perTraitMethods = [];
        /** @var array<string, string> */
        $usedTraitNameByLc = [];
        foreach ($traitNames as $traitName) {
            $traitLc = strtolower(ltrim($traitName, '\\'));
            if (!$object->hasDeclaredClass($traitName)) {
                throw new \LogicException("Could not find trait {$traitName}");
            }
            if (!$object->isTraitClass($traitLc)) {
                throw new \LogicException("{$traitName} is not a trait");
            }
            $traitId = $object->lookup($traitName);
            if (VM\LazyGhostTraitSupport::isLazyGhostTrait($traitLc)) {
                $object->markLazyGhostTraitClass($classId);
            }
            $object->inheritTraitConstants($classId, $traitId, $traitName);
            $object->inheritTraitStaticProperties($classId, $traitId, $traitName);
            $object->inheritTraitInstanceProperties($classId, $traitId, $traitName);
            if (!isset($perTraitMethods[$traitLc])) {
                $perTraitMethods[$traitLc] = [];
            }
            $usedTraitNameByLc[$traitLc] = $traitName;
            $object->recordClassUsedTrait($classLc, $traitName);
            foreach ($object->declaredMethodNames($traitId) as $methodLc) {
                $perTraitMethods[$traitLc][$methodLc] = [
                    'traitId' => $traitId,
                    'traitName' => $traitName,
                    'traitLc' => $traitLc,
                    'methodLc' => $methodLc,
                    'sourceMethodLc' => $methodLc,
                ];
            }
        }

        /** @var array<string, true> */
        $excludedByPrecedence = [];
        foreach ($adaptations as $adaptation) {
            if ('precedence' !== ($adaptation['kind'] ?? '')) {
                continue;
            }
            $winnerTraitLc = strtolower(ltrim((string) ($adaptation['trait'] ?? ''), '\\'));
            if ('' === $winnerTraitLc) {
                throw new \LogicException('Trait precedence adaptation must specify a trait');
            }
            if (!isset($usedTraitNameByLc[$winnerTraitLc])) {
                throw new \LogicException('Could not find trait ' . (string) ($adaptation['trait'] ?? ''));
            }
            $methodLc = strtolower((string) $adaptation['method']);
            if (!isset($perTraitMethods[$winnerTraitLc][$methodLc])) {
                throw new \LogicException(
                    'A precedence rule was defined for '
                    . $usedTraitNameByLc[$winnerTraitLc]
                    . '::' . (string) ($adaptation['method'] ?? '')
                    . ' but this method does not exist'
                );
            }
            foreach ($adaptation['insteadof'] as $loserTrait) {
                $loserLc = strtolower(ltrim((string) $loserTrait, '\\'));
                if (!isset($usedTraitNameByLc[$loserLc])) {
                    throw new \LogicException('Could not find trait ' . (string) $loserTrait);
                }
                if (!isset($perTraitMethods[$loserLc][$methodLc])) {
                    throw new \LogicException(
                        'A precedence rule was defined for '
                        . $usedTraitNameByLc[$winnerTraitLc]
                        . '::' . (string) ($adaptation['method'] ?? '')
                        . ' but this method does not exist in '
                        . $usedTraitNameByLc[$loserLc]
                    );
                }
                $excludedByPrecedence["{$loserLc}\0{$methodLc}"] = true;
            }
        }

        /** @var array<string, array{traitId: int, traitName: string, traitLc: string, methodLc: string}> */
        $merged = [];
        foreach ($perTraitMethods as $traitLc => $methods) {
            foreach ($methods as $methodLc => $data) {
                if (isset($excludedByPrecedence["{$traitLc}\0{$methodLc}"])) {
                    continue;
                }
                if (isset($excluded[$methodLc])) {
                    continue;
                }
                if (isset($merged[$methodLc])) {
                    if ($merged[$methodLc]['traitLc'] === $traitLc) {
                        continue;
                    }
                    $prev = $merged[$methodLc]['traitName'];
                    throw new \CompileError(
                        "Trait method {$data['traitName']}::{$methodLc} has not been applied as {$className}::{$methodLc}, "
                        ."because of collision with {$prev}::{$methodLc}"
                    );
                }
                $merged[$methodLc] = $data;
            }
        }

        foreach ($adaptations as $adaptation) {
            if ('alias' !== ($adaptation['kind'] ?? '')) {
                continue;
            }
            $methodLc = strtolower((string) $adaptation['method']);
            $traitLcFilter = null !== ($adaptation['trait'] ?? null)
                ? strtolower(ltrim((string) $adaptation['trait'], '\\'))
                : null;
            $newName = $adaptation['newName'] ?? null;
            $newModifier = $adaptation['newModifier'] ?? null;
            if (null === $newName && null === $newModifier) {
                continue;
            }

            $traitPrefix = null !== ($adaptation['trait'] ?? null)
                ? (string) $adaptation['trait'] . '::'
                : '';

            if (null === $newName) {
                if (!isset($merged[$methodLc])) {
                    throw new \LogicException(
                        'An alias was defined for ' . $traitPrefix . (string) ($adaptation['method'] ?? '')
                        . ' but this method does not exist'
                    );
                }
                if (null !== $traitLcFilter && $merged[$methodLc]['traitLc'] !== $traitLcFilter) {
                    throw new \LogicException(
                        'An alias was defined for ' . (string) ($adaptation['trait'] ?? '') . '::' . (string) ($adaptation['method'] ?? '')
                        . ' but this method does not exist'
                    );
                }
                if (null !== $newModifier) {
                    $merged[$methodLc]['vis'] = (int) $newModifier;
                }

                continue;
            }

            $newNameLc = strtolower((string) $newName);
            if (isset($merged[$newNameLc])) {
                throw new \LogicException('Cannot redefine method ' . $newName);
            }

            if (null !== $traitLcFilter) {
                if (!isset($usedTraitNameByLc[$traitLcFilter]) || !isset($perTraitMethods[$traitLcFilter][$methodLc])) {
                    throw new \LogicException(
                        'An alias was defined for ' . (string) ($adaptation['trait'] ?? '') . '::' . (string) ($adaptation['method'] ?? '')
                        . ' but this method does not exist'
                    );
                }
                $data = $perTraitMethods[$traitLcFilter][$methodLc];
            } else {
                if (isset($merged[$methodLc])) {
                    $data = $merged[$methodLc];
                } else {
                    $source = null;
                    foreach ($perTraitMethods as $methods) {
                        if (isset($methods[$methodLc])) {
                            $source = $methods[$methodLc];
                            break;
                        }
                    }
                    if (null === $source) {
                        throw new \LogicException(
                            'An alias was defined for ' . $traitPrefix . (string) ($adaptation['method'] ?? '')
                            . ' but this method does not exist'
                        );
                    }
                    $data = $source;
                }
            }

            if (null !== $newModifier) {
                $data['vis'] = (int) $newModifier;
            }
            $data['sourceMethodLc'] = $methodLc;
            $data['methodLc'] = $newNameLc;
            if (null === $traitLcFilter) {
                unset($merged[$methodLc]);
            }
            $merged[$newNameLc] = $data;
        }

        foreach ($merged as $methodLc => $data) {
            if (isset($excluded[$methodLc])) {
                continue;
            }
            if (isset($ownMethods[$methodLc]) && !isset($traitMethodSources[$methodLc])) {
                continue;
            }
            if (isset($traitMethodSources[$methodLc])) {
                $prevTrait = $traitMethodSources[$methodLc];
                if ($prevTrait === $data['traitName']) {
                    continue;
                }
                throw new \CompileError(
                    "Trait method {$data['traitName']}::{$methodLc} has not been applied as {$className}::{$methodLc}, "
                    ."because of collision with {$prevTrait}::{$methodLc}"
                );
            }
            $traitMethodSources[$methodLc] = $data['traitName'];
            $traitId = $data['traitId'];
            $vis = $data['vis'] ?? $object->methodVisibility($traitId, $methodLc);
            $object->defineMethodVisibility(
                $classId,
                $methodLc,
                $vis
            );
            if ('__construct' === $methodLc) {
                $object->markHasConstructor($classId);
            }
            $object->recordTraitMethodSource($classId, $methodLc, $data['traitLc']);
            $sourceMethodLc = $data['sourceMethodLc'] ?? $data['methodLc'];
            $methodBlock = $object->traitMethodBlock($traitId, $sourceMethodLc);
            if (null !== $methodBlock) {
                $methodBlock = TraitMethodFunctionStatic::bindBlock(
                    $methodBlock,
                    $className,
                    $data['traitName']
                );
                if ($this->context->scope->blockStorage->contains($methodBlock)) {
                    $this->context->scope->blockStorage->detach($methodBlock);
                }
                $this->compileBlock($methodBlock, $classLc.'::'.$methodLc);
            }
        }
    }

    public function assignIncludeResult(Operand $result): void
    {
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

    /**
     * First assignment to a {main} script global must populate the heap box (#1492 bootstrap-aot).
     *
     * Without this, makeVariableFromValueOp keeps an SSA rvalue while a later VAR_FETCH rebinds
     * the name to an empty script-global wrapper — SplObjectStorage::contains() then reads null.
     */
    private function tryAssignScriptGlobalFirstBinding(Operand $resultOp, JIT\Variable $value): bool
    {
        $block = $this->context->jitEnclosingBlock;
        if (null === $block || !$block->isMainScript()) {
            return false;
        }
        $name = JIT\OperandName::resolve($resultOp);
        if (null === $name || '' === $name || \PHPCompiler\Web\Superglobals::isSuperglobalName($name)) {
            return false;
        }
        if ($this->context->isForeachByRefLocalName($name, $block)) {
            return false;
        }
        $globalVar = $this->context->ensureScriptGlobal($name);
        $this->context->setVariableOp($resultOp, $globalVar);
        JIT\JitValueBox::assignToPointer(
            $this->context,
            JIT\JitValueBox::valuePtrFromVariable($this->context, $globalVar),
            $value
        );
        $this->context->bindVariableByName($this->context->resolveRefAliasName($name), $globalVar);

        return true;
    }

    /**
     * Prefer active foreach by-ref lvalues over {main} script-global slots (#4364).
     */
    private function resolveAssignLvalue(Operand $resultOp): JIT\Variable
    {
        $result = $this->context->getVariableFromOp($resultOp);
        if (null !== $result->foreachByRefPackedArm || $result->borrowedValueEntry) {
            return $result;
        }
        $name = JIT\OperandName::resolve($resultOp);
        if (null === $name || '' === $name || !$result->functionStaticGlobal) {
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
        $mergeReturns = false;
        foreach ($merge->opCodes as $mergeOp) {
            if (OpCode::TYPE_RETURN === $mergeOp->type) {
                $mergeReturns = true;
                break;
            }
        }
        if (!$mergeReturns) {
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

    private function assignOperand(Operand $resultOp, Variable $value, bool $force = false): void {
        $branchMergeTarget = $force && $this->context->coalesceAssignTargets->contains($resultOp);
        $resolvedName = JIT\OperandName::resolve($resultOp);
        if (!$this->context->hasVariableOp($resultOp)) {
            if (
                null !== $this->context->jitCurrentBlock
                && $this->context->aliasVariableOpFromSlot($this->context->jitCurrentBlock, $resultOp)
            ) {
                // fall through to normal assign on the aliased lvalue
            } elseif ($this->tryAssignScriptGlobalFirstBinding($resultOp, $value)) {
                return;
            } elseif (
                Variable::TYPE_VALUE === $value->type
                && Variable::KIND_VALUE === $value->kind
                && '__value__*' === $this->context->getStringFromType($this->context->helper->loadValue($value)->typeOf())
            ) {
                // getenv() and similar return __value__* rvalues — copy into a stack slot (#8555).
                $slot = JIT\JitValueBox::alloc($this->context);
                JIT\JitValueBox::copyFromPointer(
                    $this->context,
                    $slot,
                    JIT\JitValueBox::normalizeValuePtr(
                        $this->context,
                        $this->context->helper->loadValue($value)
                    )
                );
                $var = new Variable(
                    $this->context,
                    Variable::TYPE_VALUE,
                    Variable::KIND_VARIABLE,
                    $slot
                );
                $var->addref();
                $this->context->setVariableOp($resultOp, $var);
                $resolved = JIT\OperandName::resolve($resultOp);
                if (null !== $resolved && '' !== $resolved) {
                    $this->context->bindVariableByName($resolved, $var);
                }

                return;
            } else {
                // it's a kind!
                $var = $this->context->makeVariableFromValueOp($this->context->helper->loadValue($value), $resultOp);
                $var->compileTimeConstantName = $value->compileTimeConstantName;
                $var->compileTimeEnumCase = $value->compileTimeEnumCase;
                $var->compileTimeFloat = $value->compileTimeFloat;

                return;
            }
        }
        $result = $this->resolveAssignLvalue($resultOp);
        if ($result === $value) {
            return;
        }
        // Foreach by-ref must use hashtable index writes, not valueBoxAliasPtr (#4364, AOT {main}).
        if (null !== $result->foreachByRefPackedArm) {
            JIT\HashTableHelper::assignForeachByRefWritable($this->context, $result, $value);

            return;
        }
        if (
            $result->borrowedValueEntry
            && null !== $result->writableHt
            && null !== $result->writableIndex
        ) {
            JIT\HashTableHelper::setAtIndex(
                $this->context,
                $result->writableHt,
                $result->writableIndex,
                $value
            );

            return;
        }
        // Reference aliases to object properties keep objectPropertySlot; guard before
        // valueBoxAliasPtr writes so readonly checks are not skipped (#4273, #3149).
        if (null !== $result->valueBoxAliasPtr && null === $result->objectPropertySlot) {
            JIT\JitValueBox::assignToPointer(
                $this->context,
                $result->valueBoxAliasPtr,
                $value
            );
            $this->recordListUnpackAssignSlot($resultOp, $result);

            return;
        }
        if ($value->isJitGenerator) {
            $this->context->setVariableOp($resultOp, $value);
            $resolved = JIT\OperandName::resolve($resultOp);
            if (null !== $resolved && '' !== $resolved) {
                $this->context->bindVariableByName($resolved, $value);
            }

            return;
        }
        if (
            $force
            && Variable::KIND_VALUE === $result->kind
            && Variable::TYPE_STRING !== $result->type
            && !$value->isJitGenerator
            && null === $result->objectPropertySlot
            && !$result->functionStaticGlobal
        ) {
            // ?? left branch fetch binds a superglobal lvalue; force-assign needs a stack slot (#866).
            // Property lvalues keep objectPropertySlot so ReadonlyClassGuard runs on inc/dec (#3149).
            $slot = JIT\JitValueBox::alloc($this->context);
            $this->context->setVariableOp(
                $resultOp,
                new Variable(
                    $this->context,
                    Variable::TYPE_VALUE,
                    Variable::KIND_VARIABLE,
                    $slot
                )
            );
            $result = $this->context->getVariableFromOp($resultOp);
        }
        if (
            !$force
            && $resultOp instanceof \PHPCfg\Operand\Temporary
            && Variable::KIND_VALUE === $result->kind
            && Variable::TYPE_STRING !== $result->type
        ) {
            // Temporaries can start life as rvalues; promote to a boxed stack slot on first assignment.
            $slot = JIT\JitValueBox::alloc($this->context);
            $this->context->setVariableOp(
                $resultOp,
                new Variable(
                    $this->context,
                    Variable::TYPE_VALUE,
                    Variable::KIND_VARIABLE,
                    $slot
                )
            );
            $result = $this->context->getVariableFromOp($resultOp);
        }
        if (null !== $result->magicSetReceiver && null !== $result->magicSetName) {
            $receiverVar = new Variable(
                $this->context,
                Variable::TYPE_OBJECT,
                Variable::KIND_VALUE,
                $result->magicSetReceiver
            );
            if (JIT\MagicMethodDispatch::tryEmitMagicSet(
                $this->context,
                $receiverVar,
                $result->magicSetName,
                $value,
                $this->context->jitEnclosingBlock
            )) {
                return;
            }
        }
        if (null !== $result->objectPropertySlot) {
            if (null === $result->objectPropertyType) {
                throw new \LogicException('objectPropertySlot requires objectPropertyType');
            }
            JIT\DynamicObjectReadonlyGuard::emitBeforePropertyStore(
                $this->context,
                $result,
                $this->context->jitEnclosingBlock
            );
            JIT\ReadonlyClassGuard::emitBeforePropertyStore(
                $this->context,
                $result,
                $this->context->jitEnclosingBlock
            );
            if (JIT\AsymmetricVisibilityGuard::emitBeforePropertyStore(
                $this->context,
                $this,
                $result,
                $this->context->jitEnclosingBlock
            )) {
                return;
            }
            if (JIT\PropertyHookDispatch::emitSetHookIfNeeded(
                $this->context,
                $result,
                $value,
                $this->context->jitEnclosingBlock,
                $this
            )) {
                return;
            }
            if (null !== $result->objectPropertyDnfArms) {
                JIT\DnfParamCheck::enforcePropertyWrite(
                    $this->context,
                    $value,
                    $result->objectPropertyDnfArms
                );
            }
            JIT\ReadonlyClassGuard::emitStoreUnlessPending(
                $this->context,
                function () use ($result, $value): void {
                    $this->context->type->object->propertyStore(
                        $result->objectPropertySlot,
                        $value,
                        $result->objectPropertyType
                    );
                }
            );

            return;
        }
        if (null !== $result->staticPropertyGlobal) {
            if (null === $result->staticPropertyType) {
                throw new \LogicException('staticPropertyGlobal requires staticPropertyType');
            }
            if (JIT\AsymmetricVisibilityGuard::emitBeforeStaticPropertyStore(
                $this->context,
                $this,
                $result,
                $this->context->jitEnclosingBlock
            )) {
                return;
            }
            if (JIT\PropertyHookDispatch::emitStaticSetHookIfNeeded(
                $this->context,
                $result,
                $value,
                $this->context->jitEnclosingBlock,
                $this
            )) {
                return;
            }
            if (null !== $result->staticPropertyDnfArms) {
                JIT\DnfParamCheck::enforcePropertyWrite(
                    $this->context,
                    $value,
                    $result->staticPropertyDnfArms
                );
            }
            $this->context->type->object->staticPropertyStore(
                $result->staticPropertyGlobal,
                $value,
                $result->staticPropertyType,
                $result->staticPropertyInitGlobal
            );

            return;
        }
        if ($result->functionStaticGlobal) {
            JIT\JitValueBox::assignToPointer(
                $this->context,
                JIT\JitValueBox::valuePtrFromVariable($this->context, $result),
                $value
            );
            $resolved = JIT\OperandName::resolve($resultOp);
            if (null !== $resolved && '' !== $resolved) {
                $this->context->bindVariableByName(
                    $this->context->resolveRefAliasName($resolved),
                    $result
                );
            }

            return;
        }
        if (null !== $result->writableHt && null !== $result->writableValueBoxKey) {
            JIT\HashTableHelper::setValueBoxKey(
                $this->context,
                $result->writableHt,
                $result->writableValueBoxKey,
                $value
            );

            return;
        }
        if ($result->isArrayAccessWritableOffset) {
            JIT\ArrayAccessHelper::assignWritableOffset($this->context, $result, $value);

            return;
        }
        if (
            $result->kind === Variable::KIND_VALUE
            && $result->type === Variable::TYPE_STRING
            && JIT\StringOffsetHelper::isWritableCharOffsetLvalue($result, $this->context)
        ) {
            JIT\StringOffsetHelper::dimAssign($this->context, $result->value, $value);

            return;
        }
        if (
            Variable::TYPE_NATIVE_BOOL === $value->type
            && Variable::TYPE_STRING === $result->type
            && (Variable::KIND_VARIABLE === $result->kind || Variable::KIND_VALUE === $result->kind)
        ) {
            // && short-circuit false branch can target a phi slot still typed from a string dim fetch (#1492).
            $this->context->setVariableOp(
                $resultOp,
                new Variable(
                    $this->context,
                    Variable::TYPE_NATIVE_BOOL,
                    Variable::KIND_VALUE,
                    $this->context->helper->loadValue($value)
                )
            );

            return;
        }
        if (
            Variable::TYPE_NATIVE_BOOL === $value->type
            && Variable::TYPE_NATIVE_BOOL === $result->type
            && Variable::KIND_VALUE === $result->kind
        ) {
            // defined() && CONST phi merge in bin/vm.php spine guard (#1492).
            $this->context->setVariableOp(
                $resultOp,
                new Variable(
                    $this->context,
                    Variable::TYPE_NATIVE_BOOL,
                    Variable::KIND_VALUE,
                    Variable::KIND_VALUE === $value->kind
                        ? $value->value
                        : $this->context->helper->loadValue($value)
                )
            );

            return;
        }
        if (null !== $result->valueBoxAliasPtr) {
            JIT\JitValueBox::assignToPointer(
                $this->context,
                $result->valueBoxAliasPtr,
                $value
            );

            return;
        }
        if ($result->kind !== Variable::KIND_VARIABLE) {
            throw new \LogicException('Cannot assign to a value');
        }
        if (
            $branchMergeTarget
            && null === $result->objectPropertySlot
            && !$result->functionStaticGlobal
        ) {
            if (Variable::TYPE_VALUE !== $result->type || Variable::KIND_VARIABLE !== $result->kind) {
                $slot = JIT\JitValueBox::alloc($this->context);
                $this->context->setVariableOp(
                    $resultOp,
                    new Variable(
                        $this->context,
                        Variable::TYPE_VALUE,
                        Variable::KIND_VARIABLE,
                        $slot
                    )
                );
                $result = $this->context->getVariableFromOp($resultOp);
            }
            JIT\JitValueBox::assignToPointer(
                $this->context,
                JIT\JitValueBox::pointer($this->context, $result->value),
                $value
            );
            $resolved = JIT\OperandName::resolve($resultOp);
            if (null !== $resolved && '' !== $resolved) {
                $this->context->bindVariableByName($resolved, $result);
            }

            return;
        }
        if (
            $value->type === $result->type
            && !($branchMergeTarget && Variable::TYPE_VALUE === $result->type)
        ) {
            if (!$result->includeBinding) {
                $result->free();
            }
            if ($value->type & Variable::IS_NATIVE_ARRAY || Variable::TYPE_HASHTABLE === $value->type) {
                $result->nextFreeElement = $value->nextFreeElement;
            }
            if (Variable::TYPE_VALUE === $value->type) {
                $destLlvm = $result->value->typeOf();
                $destTy = $this->context->getStringFromType($destLlvm);
                if ('__value__' === $destTy || '__value__*' === $destTy) {
                    $destPointsAtStruct = '__value__' === $destTy;
                    if (
                        '__value__*' === $destTy
                        && \PHPLLVM\Type::KIND_POINTER === $destLlvm->getKind()
                        && '__value__' === $this->context->getStringFromType($destLlvm->getElementType())
                    ) {
                        $destPointsAtStruct = true;
                    }
                    if ($destPointsAtStruct) {
                        JIT\JitValueBox::copyFromPointer(
                            $this->context,
                            $result->value,
                            $this->valueBoxPointer($value)
                        );
                    } else {
                        $this->context->builder->store(
                            $this->valueBoxPointer($value),
                            $result->value
                        );
                    }
                    $this->maybeCopyObjectPropertyBacking($result, $value, $force);
                    if (null === $result->objectPropertySlot) {
                        $result->addref();
                    }
                    $this->copyValueBoxJitFlags($result, $value, $force);
                    $result->compileTimeConstantName = $value->compileTimeConstantName;
                    $result->compileTimeEnumCase = $value->compileTimeEnumCase;
                    $this->syncCompileTimeString($result, $value, $force);
                    $this->syncCompileTimeFloat($result, $value, $force);

                    return;
                }
            }
            $toStore = $this->context->helper->loadValue($value);
            $this->context->builder->store(
                $toStore,
                $result->value
            );
            $this->maybeCopyObjectPropertyBacking($result, $value, $force);
            if (null === $result->objectPropertySlot) {
                $result->addref();
            }
            $this->copyValueBoxJitFlags($result, $value, $force);
            $result->compileTimeConstantName = $value->compileTimeConstantName;
            $result->compileTimeEnumCase = $value->compileTimeEnumCase;
            $this->syncCompileTimeString($result, $value, $force);
            $this->syncCompileTimeFloat($result, $value, $force);
            if ($value->isJitGenerator) {
                $resolved = JIT\OperandName::resolve($resultOp);
                if (null !== $resolved && '' !== $resolved) {
                    $this->context->bindVariableByName($resolved, $result);
                }
            }

            return;
        } elseif ($result->type === Variable::TYPE_VALUE) {
            // wrap
            $valueRef = $result->value;
            $valueFrom = $value->value;
            if ($value->type & Variable::IS_NATIVE_ARRAY) {
                $ht = JIT\HashTableHelper::materializeNativeArrayForCall($this->context, $value);
                $this->context->builder->call(
                    $this->context->lookupFunction('__value__writeHashtable'),
                    $valueRef,
                    $ht
                );
                $this->context->refcount->addref($ht);
                $result->valueBoxHashtable = true;

                return;
            }
            switch ($value->type) {
                case Variable::TYPE_NULL:
                    $this->context->builder->call(
                    $this->context->lookupFunction('__value__writeNull') , 
                    $valueRef
                    
                );
                    $result->isNullConstant = $value->isNullConstant;
    
                    return;
                case Variable::TYPE_NATIVE_LONG:
                    if (null !== $result->writableHt && null !== $result->writableObjectKey) {
                        $this->context->builder->call(
                            $this->context->lookupFunction('__hashtable__setObjectKeyLong'),
                            $result->writableHt,
                            $result->writableObjectKey,
                            $this->context->helper->loadValue($value)
                        );

                        return;
                    }
                    if (null !== $result->writableHt && null !== $result->writableStringKey) {
                        $this->context->builder->call(
                            $this->context->lookupFunction('__hashtable__setStringKeyLong'),
                            $result->writableHt,
                            $result->writableStringKey,
                            $this->context->helper->loadValue($value)
                        );

                        return;
                    }
                    if (null !== $result->writableHt && null !== $result->writableIndex) {
                        JIT\HashTableHelper::setAtIndex(
                            $this->context,
                            $result->writableHt,
                            $result->writableIndex,
                            $value
                        );

                        return;
                    }
                    $this->context->builder->call(
                    $this->context->lookupFunction('__value__writeLong') , 
                    $valueRef
                    , $this->context->helper->loadValue($value)
                    
                );
                    $result->compileTimeConstantName = $value->compileTimeConstantName;
                    $result->compileTimeEnumCase = $value->compileTimeEnumCase;
    
                    return;
                case Variable::TYPE_NATIVE_DOUBLE:
                    $this->context->builder->call(
                    $this->context->lookupFunction('__value__writeDouble') , 
                    $valueRef
                    , $this->context->helper->loadValue($value)
                    
                );
                    $this->syncCompileTimeFloat($result, $value, $force);
    
                    return;
                case Variable::TYPE_NATIVE_BOOL:
                    if (null !== $result->writableHt && null !== $result->writableStringKey) {
                        $this->context->builder->call(
                            $this->context->lookupFunction('__hashtable__setStringKeyBool'),
                            $result->writableHt,
                            $result->writableStringKey,
                            $this->context->builder->truncOrBitCast(
                                $this->context->helper->loadValue($value),
                                $this->context->getTypeFromString('int1')
                            )
                        );

                        return;
                    }
                    JIT\JitValueBox::writeBool(
                        $this->context,
                        $valueRef,
                        $this->context->helper->loadValue($value)
                    );

                    return;
                case Variable::TYPE_STRING:
                    $str = JIT\JitStringArg::lowerDominating($this->context, $value, 'value box write');
                    $owned = $this->context->builder->call(
                        $this->context->lookupFunction('__string__separate'),
                        $str
                    );
                    if (null !== $result->writableHt && null !== $result->writableIndex) {
                        JIT\HashTableHelper::setAtIndex(
                            $this->context,
                            $result->writableHt,
                            $result->writableIndex,
                            $value
                        );

                        return;
                    }
                    if (null !== $result->writableHt && null !== $result->writableStringKey) {
                        $this->context->builder->call(
                            $this->context->lookupFunction('__hashtable__setStringKeyString'),
                            $result->writableHt,
                            $result->writableStringKey,
                            $owned
                        );

                        return;
                    }
                    $this->context->builder->call(
                        $this->context->lookupFunction('__value__writeString'),
                        $valueRef,
                        $owned
                    );
                    $this->syncCompileTimeString($result, $value, $force);

                    return;
                case Variable::TYPE_HASHTABLE:
                    $this->context->builder->call(
                        $this->context->lookupFunction('__value__writeHashtable'),
                        $valueRef,
                        $this->context->helper->loadValue($value)
                    );
                    $result->valueBoxHashtable = true;

                    return;
                case Variable::TYPE_OBJECT:
                    $objVal = $this->context->helper->loadValue($value);
                    if (null !== $result->writableHt && null !== $result->writableObjectKey) {
                        $this->context->builder->call(
                            $this->context->lookupFunction('__hashtable__setObjectKeyObject'),
                            $result->writableHt,
                            $result->writableObjectKey,
                            $objVal
                        );

                        return;
                    }
                    $this->context->builder->call(
                        $this->context->lookupFunction('__value__writeObject'),
                        $valueRef,
                        $objVal
                    );
                    $result->closureCall = $value->closureCall;
                    $result->compileTimeConstantName = $value->compileTimeConstantName;
                    $result->compileTimeEnumCase = $value->compileTimeEnumCase;

                    return;
                case Variable::TYPE_VALUE:
                    JIT\JitValueBox::copyFromPointer(
                        $this->context,
                        $valueRef,
                        $this->valueBoxPointer($value)
                    );
                    $this->copyValueBoxJitFlags($result, $value, $force);
                    $this->recordListUnpackAssignSlot($resultOp, $result);

                    return;
                default:
                    if ($value->type & Variable::IS_NATIVE_ARRAY) {
                        $ht = JIT\HashTableHelper::materializeNativeArrayForCall($this->context, $value);
                        $this->context->builder->call(
                            $this->context->lookupFunction('__value__writeHashtable'),
                            $valueRef,
                            $ht
                        );
                        $result->valueBoxHashtable = true;

                        return;
                    }
                    throw new \LogicException("Source type: {$value->type}");
            }
        } elseif ($result->type === Variable::TYPE_NATIVE_LONG && Variable::TYPE_VALUE === $value->type) {
            $fp = $this->unboxValueToNativeDouble($value);
            $longVal = $this->context->builder->fpToSi(
                $fp,
                $this->context->getTypeFromString('int64')
            );
            $result->free();
            $this->context->builder->store($longVal, $result->value);
            $result->addref();

            return;
        } elseif ($result->type === Variable::TYPE_NATIVE_LONG && Variable::TYPE_NATIVE_DOUBLE === $value->type) {
            $result->free();
            $fp = $this->context->helper->loadValue($value);
            $long = $this->context->builder->fpToSi($fp, $this->context->getTypeFromString('int64'));
            $this->context->builder->store($long, $result->value);
            $result->addref();

            return;
        } elseif ($result->type === Variable::TYPE_NATIVE_LONG && Variable::TYPE_NATIVE_BOOL === $value->type) {
            $result->free();
            $boolVal = $this->context->helper->loadValue($value);
            $long = $this->context->builder->zExt($boolVal, $this->context->getTypeFromString('int64'));
            $this->context->builder->store($long, $result->value);
            $result->addref();

            return;
        } elseif ($result->type === Variable::TYPE_NATIVE_LONG && Variable::TYPE_STRING === $value->type) {
            $result->free();
            $long = JIT\JitLongArg::lowerStringValue($this->context, $this->context->helper->loadValue($value));
            $this->context->builder->store($long, $result->value);
            $result->addref();

            return;
        } elseif ($result->type === Variable::TYPE_NATIVE_DOUBLE && Variable::TYPE_NATIVE_LONG === $value->type) {
            $result->free();
            $long = $this->context->helper->loadValue($value);
            $fp = $this->context->builder->siToFp($long, $this->context->getTypeFromString('double'));
            $this->context->builder->store($fp, $result->value);
            $result->addref();

            return;
        } elseif ($result->type === Variable::TYPE_NATIVE_DOUBLE && Variable::TYPE_VALUE === $value->type) {
            $fp = $this->unboxValueToNativeDouble($value);
            $result->free();
            $this->context->builder->store($fp, $result->value);
            $result->addref();

            return;
        } elseif (Variable::TYPE_VALUE === $result->type && Variable::TYPE_VALUE === $value->type) {
            JIT\JitValueBox::copyFromPointer(
                $this->context,
                $result->value,
                $this->valueBoxPointer($value)
            );
            $this->copyValueBoxJitFlags($result, $value, $force);
            $result->compileTimeConstantName = $value->compileTimeConstantName;
            $result->compileTimeEnumCase = $value->compileTimeEnumCase;
            $this->syncCompileTimeString($result, $value, $force);
            $this->recordListUnpackAssignSlot($resultOp, $result);

            return;
        } elseif (Variable::TYPE_HASHTABLE === $result->type && Variable::TYPE_VALUE === $value->type) {
            // ini_get_all() and similar builtins return array|false as __value__; keep the box
            // so strict comparisons against false use JitValueCompare (issue #3205, #848).
            $slot = JIT\JitValueBox::alloc($this->context);
            JIT\JitValueBox::copyFromPointer(
                $this->context,
                $slot,
                $this->valueBoxPointer($value)
            );
            $result->free();
            $result->type = Variable::TYPE_VALUE;
            $result->value = $slot;
            $this->copyValueBoxJitFlags($result, $value, $force);
            $result->compileTimeConstantName = $value->compileTimeConstantName;
            $result->compileTimeEnumCase = $value->compileTimeEnumCase;
            $this->syncCompileTimeString($result, $value, $force);
            $result->addref();

            return;
        } elseif (Variable::TYPE_VALUE === $result->type && Variable::TYPE_HASHTABLE === $value->type) {
            $this->context->builder->call(
                $this->context->lookupFunction('__value__writeHashtable'),
                $this->valueBoxPointer($result),
                $this->context->helper->loadValue($value)
            );
            $result->valueBoxHashtable = true;

            return;
        } elseif (Variable::TYPE_STRING === $result->type && Variable::TYPE_VALUE === $value->type) {
            // getenv() and similar builtins return string|false as __value__; keep the box
            // so strict comparisons against false use JitValueCompare (issue #848).
            $slot = JIT\JitValueBox::alloc($this->context);
            JIT\JitValueBox::copyFromPointer(
                $this->context,
                $slot,
                $this->valueBoxPointer($value)
            );
            $result->free();
            $result->type = Variable::TYPE_VALUE;
            $result->value = $slot;
            $this->syncCompileTimeString($result, $value, $force);
            $result->addref();

            return;
        } elseif (Variable::TYPE_NATIVE_BOOL === $result->type && Variable::TYPE_VALUE === $value->type) {
            $boolVal = $this->context->castToBool($this->context->helper->loadValue($value));
            $result->free();
            $this->context->builder->store($boolVal, $result->value);
            $result->addref();

            return;
        } elseif (Variable::TYPE_OBJECT === $result->type && Variable::TYPE_VALUE === $value->type) {
            $valuePtr = $this->valueBoxPointer($value);
            $map = $this->context->structFieldMap['__value__'];
            $typeByte = $this->context->builder->load(
                $this->context->builder->structGep($valuePtr, $map['type'])
            );
            $i8 = $this->context->getTypeFromString('int8');
            $isLong = $this->context->builder->icmp(
                PHPLLVM\Builder::INT_EQ,
                $typeByte,
                $i8->constInt(Variable::TYPE_NATIVE_LONG, false)
            );
            $isBool = $this->context->builder->icmp(
                PHPLLVM\Builder::INT_EQ,
                $typeByte,
                $i8->constInt(Variable::TYPE_NATIVE_BOOL, false)
            );
            $isStreamHandle = $this->context->builder->bitwiseOr($isLong, $isBool);
            $objectBlock = JIT\BasicBlockHelper::append($this->context, 'assign_object_from_value');
            $handleBlock = JIT\BasicBlockHelper::append($this->context, 'assign_stream_handle_from_value');
            $doneBlock = JIT\BasicBlockHelper::append($this->context, 'assign_object_from_value_done');
            $this->context->builder->branchIf($isStreamHandle, $handleBlock, $objectBlock);
            $this->context->builder->positionAtEnd($objectBlock);
            $obj = $this->context->builder->call(
                $this->context->lookupFunction('__value__readObject'),
                $valuePtr
            );
            $result->free();
            $this->context->builder->store($obj, $result->value);
            $result->addref();
            $this->context->builder->branch($doneBlock);
            $this->context->builder->positionAtEnd($handleBlock);
            $result->free();
            $slot = JIT\JitValueBox::alloc($this->context);
            $destPtr = JIT\JitValueBox::pointer($this->context, $slot);
            $longBlock = JIT\BasicBlockHelper::append($this->context, 'assign_stream_handle_long');
            $boolBlock = JIT\BasicBlockHelper::append($this->context, 'assign_stream_handle_bool');
            $this->context->builder->branchIf($isLong, $longBlock, $boolBlock);
            $this->context->builder->positionAtEnd($longBlock);
            $this->context->builder->call(
                $this->context->lookupFunction('__value__writeLong'),
                $destPtr,
                $this->context->builder->call(
                    $this->context->lookupFunction('__value__readLong'),
                    $valuePtr
                )
            );
            $this->context->builder->branch($doneBlock);
            $this->context->builder->positionAtEnd($boolBlock);
            JIT\JitValueBox::writeBool(
                $this->context,
                $slot,
                $this->context->builder->truncOrBitCast(
                    $this->context->builder->call(
                        $this->context->lookupFunction('__value__readLong'),
                        $valuePtr
                    ),
                    $this->context->getTypeFromString('int1')
                )
            );
            $this->context->builder->branch($doneBlock);
            $result->type = Variable::TYPE_VALUE;
            $result->value = $slot;
            $result->addref();
            $this->context->builder->positionAtEnd($doneBlock);

            return;
        } elseif (Variable::TYPE_OBJECT === $result->type && Variable::TYPE_HASHTABLE === $value->type) {
            $ht = $this->context->helper->loadValue($value);
            $result->free();
            $this->context->builder->store(
                $this->context->builder->pointerCast(
                    $ht,
                    $this->context->getTypeFromString('__object__*')
                ),
                $result->value
            );
            $result->addref();

            return;
        } elseif (Variable::TYPE_HASHTABLE === $result->type && Variable::TYPE_OBJECT === $value->type) {
            if (null !== $result->writableHt && null !== $result->writableIndex) {
                JIT\HashTableHelper::setAtIndex(
                    $this->context,
                    $result->writableHt,
                    $result->writableIndex,
                    $value
                );

                return;
            }
            $obj = $this->context->helper->loadValue($value);
            $result->free();
            $this->context->builder->store(
                $this->context->builder->pointerCast(
                    $obj,
                    $this->context->getTypeFromString('__hashtable__*')
                ),
                $result->value
            );
            $result->addref();

            return;
        } elseif (Variable::TYPE_NATIVE_BOOL === $result->type && Variable::TYPE_NATIVE_LONG === $value->type) {
            // Bool ++/-- promotes to int in a value box (#4727).
            $slot = JIT\JitValueBox::alloc($this->context);
            JIT\JitValueBox::writeLong(
                $this->context,
                $slot,
                $this->context->helper->loadValue($value)
            );
            $result->free();
            $result->type = Variable::TYPE_VALUE;
            $result->value = $slot;
            $result->addref();
            $this->context->setVariableOp($resultOp, $result);
            $resolved = JIT\OperandName::resolve($resultOp);
            if (null !== $resolved && '' !== $resolved) {
                $this->context->bindVariableByName($resolved, $result);
            }

            return;
        } elseif (Variable::TYPE_STRING === $result->type && Variable::TYPE_NATIVE_BOOL === $value->type) {
            // JumpIf `&&` chains may reuse a string dim-fetch operand for a bool compare (#816, ns_func).
            $slot = JIT\JitValueBox::alloc($this->context);
            JIT\JitValueBox::writeBool(
                $this->context,
                $slot,
                $this->context->helper->loadValue($value)
            );
            $result->free();
            $result->type = Variable::TYPE_VALUE;
            $result->value = $slot;
            $result->addref();
            $this->context->setVariableOp($resultOp, $result);
            $resolved = JIT\OperandName::resolve($resultOp);
            if (null !== $resolved && '' !== $resolved) {
                $this->context->bindVariableByName($resolved, $result);
            }

            return;
        } elseif (Variable::TYPE_OBJECT === $result->type && Variable::TYPE_STRING === $value->type) {
            $slot = JIT\JitValueBox::alloc($this->context);
            $str = JIT\JitStringArg::stringPtrFromVariable($this->context, $value);
            $owned = $this->context->builder->call(
                $this->context->lookupFunction('__string__separate'),
                $str
            );
            $this->context->builder->call(
                $this->context->lookupFunction('__value__writeString'),
                JIT\JitValueBox::pointer($this->context, $slot),
                $owned
            );
            $result->free();
            $result->type = Variable::TYPE_VALUE;
            $result->value = $slot;
            $this->syncCompileTimeString($result, $value, $force);
            $result->addref();

            return;
        } elseif (Variable::TYPE_STRING === $result->type && Variable::TYPE_OBJECT === $value->type) {
            $slot = JIT\JitValueBox::alloc($this->context);
            $this->context->builder->call(
                $this->context->lookupFunction('__value__writeObject'),
                JIT\JitValueBox::pointer($this->context, $slot),
                $this->context->helper->loadValue($value)
            );
            $result->free();
            $result->type = Variable::TYPE_VALUE;
            $result->value = $slot;
            $result->compileTimeEnumCase = $value->compileTimeEnumCase;
            $result->addref();

            return;
        } elseif (Variable::TYPE_OBJECT === $result->type && Variable::TYPE_NATIVE_BOOL === $value->type) {
            // Self-host inventory spine: bool assigned into object-typed operand (#2967, #8708).
            $slot = JIT\JitValueBox::alloc($this->context);
            JIT\JitValueBox::writeBool(
                $this->context,
                $slot,
                $this->context->helper->loadValue($value)
            );
            $result->free();
            $result->type = Variable::TYPE_VALUE;
            $result->value = $slot;
            $result->addref();

            return;
        }
        throw new \LogicException("Cannot assign operands of different types (yet): {$value->type}, {$result->type}");
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
            throw new \LogicException('Cannot assign to a value');
        }
        $valueTy = $this->context->getStringFromType($value->typeOf());
        $destTy = $this->context->getStringFromType($dest->value->typeOf());
        if (Variable::TYPE_NATIVE_BOOL === $dest->type) {
            if ('__value__' === $valueTy || '__value__*' === $valueTy) {
                $source = new Variable(
                    $this->context,
                    Variable::TYPE_VALUE,
                    Variable::KIND_VALUE,
                    $value
                );
                $this->assignOperand($result, $source);

                return;
            }
            if ('int1' === $valueTy || 'bool' === $valueTy) {
                $dest->free();
                $this->context->builder->store($value, $dest->value);
                $dest->addref();

                return;
            }
        }
        if (Variable::TYPE_NATIVE_LONG === $dest->type || Variable::TYPE_NATIVE_DOUBLE === $dest->type) {
            if ('__value__' === $valueTy || '__value__*' === $valueTy) {
                $source = new Variable(
                    $this->context,
                    Variable::TYPE_VALUE,
                    Variable::KIND_VALUE,
                    $value
                );
                $this->assignOperand($result, $source);

                return;
            }
        }
        if ('__string__*' === $valueTy && Variable::TYPE_VALUE === $dest->type) {
            $dest->free();
            $isNullPtr = $this->context->builder->icmp(
                PHPLLVM\Builder::INT_EQ,
                $value,
                $value->typeOf()->constNull()
            );
            $nullBlock = JIT\BasicBlockHelper::append($this->context, 'assign_string_null_ptr');
            $copyBlock = JIT\BasicBlockHelper::append($this->context, 'assign_string_copy_ptr');
            $doneBlock = JIT\BasicBlockHelper::append($this->context, 'assign_string_ptr_done');
            $this->context->builder->branchIf($isNullPtr, $nullBlock, $copyBlock);
            $this->context->builder->positionAtEnd($nullBlock);
            $this->context->builder->call(
                $this->context->lookupFunction('__value__writeNull'),
                JIT\JitValueBox::pointer($this->context, $dest->value)
            );
            $dest->isNullConstant = true;
            $this->context->builder->branch($doneBlock);
            $this->context->builder->positionAtEnd($copyBlock);
            $owned = $this->context->builder->call(
                $this->context->lookupFunction('__string__separate'),
                $value
            );
            $this->context->builder->call(
                $this->context->lookupFunction('__value__writeString'),
                JIT\JitValueBox::pointer($this->context, $dest->value),
                $owned
            );
            $this->context->builder->branch($doneBlock);
            $this->context->builder->positionAtEnd($doneBlock);
            $dest->addref();

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
    }

    private function syncCompileTimeFloat(Variable $dest, Variable $src, bool $force): void
    {
        if ($force || null !== $src->compileTimeFloat) {
            $dest->compileTimeFloat = $src->compileTimeFloat;
        }
    }

    private function copyValueBoxJitFlags(Variable $dest, Variable $src, bool $force = false): void
    {
        if (Variable::TYPE_VALUE !== $dest->type || Variable::TYPE_VALUE !== $src->type) {
            return;
        }
        $dest->valueBoxHashtable = $src->valueBoxHashtable;
        $dest->isNullConstant = $src->isNullConstant;
        $dest->compileTimeConstantName = $src->compileTimeConstantName;
        $dest->compileTimeEnumCase = $src->compileTimeEnumCase;
        $this->syncCompileTimeString($dest, $src, $force);
        $this->syncCompileTimeFloat($dest, $src, $force);
    }

    /** Keep borrowed object-property hashtable metadata on locals ($cfg = $this->config, #848). */
    private function maybeCopyObjectPropertyBacking(Variable $dest, Variable $src, bool $force): void
    {
        // Branch-merge assigns (?-> / ??) must read the unified __value__ slot at the merge block (#3219).
        if ($force) {
            $dest->objectPropertySlot = null;
            $dest->objectPropertyType = null;
            $dest->objectPropertyReceiver = null;
            $dest->objectPropertyName = null;
            $dest->objectPropertyClassName = null;
            $dest->objectPropertyDnfArms = null;
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
        $dest->objectPropertyName = $src->objectPropertyName;
        $dest->objectPropertyClassName = $src->objectPropertyClassName;
        $dest->objectPropertyDnfArms = $src->objectPropertyDnfArms;
        $dest->closureCall = $src->closureCall;
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
            $htPtr = JIT\HashTableHelper::materializeNativeArrayForCall($this->context, $src);
            $this->context->builder->call(
                $this->context->lookupFunction('__value__writeHashtable'),
                $destPtr,
                $htPtr
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

    /**
     * @param list<JIT\Variable|array{unpack: JIT\Variable}> $callArgs
     */
    private function markNewObjectConstructedAfterCall(?JIT\Call $toCall, array $callArgs): void
    {
        if (null === $toCall) {
            return;
        }
        if ($toCall instanceof JIT\Call\Native) {
            $name = strtolower($toCall->name);
        } elseif ($toCall instanceof JIT\Call\ExternalMethod) {
            $name = strtolower($toCall->proxyName);
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

    private function compileIncDecOp(Block $block, OpCode $op, bool $increment, bool $prefix): void
    {
        $this->maybeRefreshIncludeBindingsBeforeUse();
        $readOp = $this->operandAt($block, $op->arg2, 'inc/dec read');
        $writeOp = $this->operandAt($block, $op->arg3, 'inc/dec write');
        $resultOp = $this->operandAt($block, $op->arg1, 'inc/dec result');
        $read = $this->context->getVariableFromOpInScopes($readOp);
        $write = $this->context->getVariableFromOpInScopes($writeOp);
        if (
            JIT\StringOffsetHelper::isWritableCharOffsetLvalue($write, $this->context)
            || JIT\StringOffsetHelper::isWritableCharOffsetLvalue($read, $this->context)
        ) {
            JIT\StringOffsetHelper::emitIncDecError($this->context);

            return;
        }
        $literal = JIT\JitStringArg::compileTimeLiteral($read);
        if (null !== $literal) {
            $vm = new VM\Variable();
            $vm->string($literal);
            if ($increment) {
                $vm->applyIncrement();
            } else {
                $vm->applyDecrement();
            }
            $newVar = $this->jitVariableFromVmConstant($vm);
            if (!$prefix) {
                $this->assignOperand($resultOp, $read, true);
            }
            $this->assignOperand($writeOp, $newVar, true);
            if ($prefix) {
                $this->assignOperand($resultOp, $newVar, true);
            }

            return;
        }
        if (null !== $read->staticPropertyGlobal) {
            $write = $this->context->getVariableFromOpInScopes($writeOp);
            $this->compileStaticPropertyIncDecOp($read, $write, $resultOp, $increment, $prefix);

            return;
        }

        if (null !== $read->objectPropertySlot) {
            $this->compileObjectPropertyIncDecOp($read, $resultOp, $increment, $prefix);

            return;
        }

        if (Variable::TYPE_NULL === $read->type || ($read->isNullConstant ?? false)) {
            if ($increment) {
                $newLong = $this->context->constantFromInteger(1);
                $newVar = new Variable(
                    $this->context,
                    Variable::TYPE_NATIVE_LONG,
                    Variable::KIND_VALUE,
                    $newLong
                );
                if (!$prefix) {
                    $this->assignOperand($resultOp, $read, true);
                }
                $this->assignOperand($writeOp, $newVar, true);
                if ($prefix) {
                    $this->assignOperand($resultOp, $newVar, true);
                }
            } else {
                if (!$prefix) {
                    $this->assignOperand($resultOp, $read, true);
                }
                $this->assignOperand($writeOp, $read, true);
                if ($prefix) {
                    $this->assignOperand($resultOp, $read, true);
                }
            }

            return;
        }

        if (Variable::TYPE_NATIVE_BOOL === $read->type) {
            // PHP 8.2+ zend_operators.c: bool inc/dec is a no-op (issue #7058, re-#4727).
            if (!$prefix) {
                $this->assignOperand($resultOp, $read, true);
            }
            $this->assignOperand($writeOp, $read, true);
            if ($prefix) {
                $this->assignOperand($resultOp, $read, true);
            }

            return;
        }

        if (
            Variable::TYPE_VALUE === $read->type
            && (Variable::KIND_VARIABLE === $read->kind || $read->functionStaticGlobal)
        ) {
            $this->guardIncDecResourceOperand($read, $increment);
            $readPtr = JIT\JitValueBox::valuePtrFromVariable($this->context, $read);
            $cur = $this->readIncDecValueBoxLong($read, $readPtr, $increment);
            $one = $cur->typeOf()->constInt(1, false);
            $newLong = $increment
                ? $this->context->builder->add($cur, $one)
                : $this->context->builder->sub($cur, $one);
            if (!$prefix) {
                $oldVar = new Variable(
                    $this->context,
                    Variable::TYPE_NATIVE_LONG,
                    Variable::KIND_VALUE,
                    $cur
                );
                $this->assignOperand($resultOp, $oldVar, true);
            }
            $write = $this->context->getVariableFromOpInScopes($writeOp);
            $writePtr = JIT\JitValueBox::valuePtrFromVariable($this->context, $write);
            $this->context->builder->call(
                $this->context->lookupFunction('__value__writeLong'),
                $writePtr,
                $newLong
            );
            if ($prefix) {
                $newVar = new Variable(
                    $this->context,
                    Variable::TYPE_NATIVE_LONG,
                    Variable::KIND_VALUE,
                    $newLong
                );
                $this->assignOperand($resultOp, $newVar, true);
            }

            return;
        }

        if (Variable::TYPE_NATIVE_LONG === $read->type && Variable::KIND_VARIABLE === $read->kind) {
            $this->guardIncDecResourceOperand($read, $increment);
            $cur = $this->context->helper->loadValue($read);
            $one = $cur->typeOf()->constInt(1, false);
            $newLong = $increment
                ? $this->context->builder->add($cur, $one)
                : $this->context->builder->sub($cur, $one);
            $newVar = new Variable(
                $this->context,
                Variable::TYPE_NATIVE_LONG,
                Variable::KIND_VALUE,
                $newLong
            );
            if (!$prefix) {
                $oldVar = new Variable(
                    $this->context,
                    Variable::TYPE_NATIVE_LONG,
                    Variable::KIND_VALUE,
                    $cur
                );
                $this->assignOperand($resultOp, $oldVar, true);
            }
            $this->assignOperand($writeOp, $newVar, true);
            if ($prefix) {
                $this->assignOperand($resultOp, $newVar, true);
            }

            return;
        }

        if (!$prefix) {
            $this->assignOperand($resultOp, $read, true);
        }

        $arithOp = new OpCode($increment ? OpCode::TYPE_PLUS : OpCode::TYPE_MINUS);
        $oneVar = new Variable(
            $this->context,
            Variable::TYPE_NATIVE_LONG,
            Variable::KIND_VALUE,
            $this->context->constantFromInteger(1)
        );
        $newVal = $this->context->helper->binaryOp($arithOp, $read, $oneVar);
        $this->assignOperand($writeOp, $newVal, true);
        if ($prefix) {
            $this->assignOperand($resultOp, $newVal, true);
        }
    }

    /** Coerce null value-box operands to 0 before ++; decrement uses raw readLong (#7435). */
    private function readIncDecValueBoxLong(
        JIT\Variable $read,
        PHPLLVM\Value $readPtr,
        bool $increment
    ): PHPLLVM\Value {
        if (!$increment) {
            return $this->context->builder->call(
                $this->context->lookupFunction('__value__readLong'),
                $readPtr
            );
        }
        if (JIT\Variable::TYPE_NULL === $read->type || ($read->isNullConstant ?? false)) {
            return $readPtr->typeOf()->constInt(0, false);
        }
        if (!JIT\JitValueBox::isValueOperand($read)) {
            return $this->context->builder->call(
                $this->context->lookupFunction('__value__readLong'),
                $readPtr
            );
        }
        $isNull = JIT\JitValueCompare::valueBoxIsNull($this->context, $read);
        $zero = $readPtr->typeOf()->constInt(0, false);
        $readLong = $this->context->builder->call(
            $this->context->lookupFunction('__value__readLong'),
            $readPtr
        );
        $okBlock = JIT\BasicBlockHelper::append($this->context, 'incdec_null_coerce_ok');
        $nullBlock = JIT\BasicBlockHelper::append($this->context, 'incdec_null_coerce_null');
        $mergeBlock = JIT\BasicBlockHelper::append($this->context, 'incdec_null_coerce_merge');
        $this->context->builder->branchIf($isNull, $nullBlock, $okBlock);
        $this->context->builder->positionAtEnd($nullBlock);
        $this->context->builder->branch($mergeBlock);
        $this->context->builder->positionAtEnd($okBlock);
        $this->context->builder->branch($mergeBlock);
        $this->context->builder->positionAtEnd($mergeBlock);
        $phi = $this->context->builder->phi($readLong->typeOf(), 'incdec_null_coerced');
        $phi->addIncoming($zero, $nullBlock);
        $phi->addIncoming($readLong, $okBlock);

        return $phi;
    }

    /** Reject ++/-- on stream/dir handles (issue #6396, zend_operators.c). */
    private function guardIncDecResourceOperand(JIT\Variable $read, bool $increment): void
    {
        if (JIT\NestedJitCompileScope::isActive()) {
            return;
        }
        $longVal = null;
        if (JIT\Variable::TYPE_NATIVE_LONG === $read->type) {
            $longVal = $this->context->helper->loadValue($read);
        } elseif (
            JIT\Variable::TYPE_VALUE === $read->type
            && (JIT\Variable::KIND_VARIABLE === $read->kind || $read->functionStaticGlobal)
        ) {
            $readPtr = JIT\JitValueBox::valuePtrFromVariable($this->context, $read);
            $longVal = $this->context->builder->call(
                $this->context->lookupFunction('__value__readLong'),
                $readPtr
            );
        }
        if (null === $longVal) {
            return;
        }
        JIT\Builtin\StringDir::ensureLinked($this->context);
        $isRes = JIT\JitValueCompare::nativeLongIsResource($this->context, $longVal);
        ++self::$blockNumber;
        $suffix = (string) self::$blockNumber;
        $okBlock = JIT\BasicBlockHelper::append($this->context, 'incdec_res_ok_'.$suffix);
        $errBlock = JIT\BasicBlockHelper::append($this->context, 'incdec_res_err_'.$suffix);
        $this->context->builder->branchIf($isRes, $errBlock, $okBlock);
        $this->context->builder->positionAtEnd($errBlock);
        JIT\Builtin\TypeErrorRaise::registerDeclarations($this->context);
        JIT\Builtin\TypeErrorRaise::ensureLinked($this->context);
        JIT\Builtin\TypeErrorRaise::emitRaise(
            $this->context,
            $increment ? 'Cannot increment resource' : 'Cannot decrement resource'
        );
        $this->context->builder->call($this->context->lookupFunction('abort'));
        $this->context->builder->positionAtEnd($okBlock);
    }

    /** .= on object properties: concat into new string, guard readonly, store via slot (#3149). */
    private function compileObjectPropertyConcatOp(Variable $dest, Variable $left, Variable $right): void
    {
        if (null === $dest->objectPropertySlot || null === $dest->objectPropertyType) {
            throw new \LogicException('objectPropertySlot requires objectPropertyType');
        }
        $newVal = $this->compileConcatIntoNewString($left, $right);
        JIT\DynamicObjectReadonlyGuard::emitBeforePropertyStore(
            $this->context,
            $dest,
            $this->context->jitEnclosingBlock
        );
        JIT\ReadonlyClassGuard::emitBeforePropertyStore(
            $this->context,
            $dest,
            $this->context->jitEnclosingBlock
        );
        if (JIT\AsymmetricVisibilityGuard::emitBeforePropertyStore(
            $this->context,
            $this,
            $dest,
            $this->context->jitEnclosingBlock
        )) {
            return;
        }
        if (null !== $dest->objectPropertyDnfArms) {
            JIT\DnfParamCheck::enforcePropertyWrite(
                $this->context,
                $newVal,
                $dest->objectPropertyDnfArms
            );
        }
        JIT\ReadonlyClassGuard::emitStoreUnlessPending(
            $this->context,
            function () use ($dest, $newVal): void {
                $this->context->type->object->propertyStore(
                    $dest->objectPropertySlot,
                    $newVal,
                    $dest->objectPropertyType
                );
            }
        );
    }

    /** Allocate a fresh native string holding left . right (php-src string concat semantics). */
    private function compileConcatIntoNewString(Variable $left, Variable $right): Variable
    {
        $this->context->intrinsic->builder = $this->context->builder;
        $left = JIT\JitNativeString::coerce($this->context, $left);
        $right = JIT\JitNativeString::coerce($this->context, $right);
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

    /** ++/-- on static hooked properties: get hook read, set hook write (#6319). */
    private function compileStaticPropertyIncDecOp(
        Variable $read,
        Variable $write,
        \PHPCfg\Operand $resultOp,
        bool $increment,
        bool $prefix
    ): void {
        if (null === $read->staticPropertyType) {
            throw new \LogicException('staticPropertyGlobal requires staticPropertyType');
        }
        $className = $read->staticPropertyHookClassLc ?? '';
        $propName = $read->objectPropertyName ?? '';
        $current = null;
        if ('' !== $className && '' !== $propName) {
            $hookVal = JIT\PropertyHookDispatch::tryEmitStaticPropertyGet(
                $this->context,
                $className,
                $propName,
                $this->context->jitEnclosingBlock
            );
            if (null !== $hookVal) {
                $current = new Variable(
                    $this->context,
                    Variable::TYPE_VALUE,
                    Variable::KIND_VALUE,
                    $hookVal
                );
            }
        }
        if (null === $current) {
            $current = $read;
        }
        if (!$prefix) {
            $this->assignOperand($resultOp, $current, true);
        }
        $arithOp = new OpCode($increment ? OpCode::TYPE_PLUS : OpCode::TYPE_MINUS);
        $oneVar = new Variable(
            $this->context,
            Variable::TYPE_NATIVE_LONG,
            Variable::KIND_VALUE,
            $this->context->constantFromInteger(1)
        );
        $newVal = $this->context->helper->binaryOp($arithOp, $current, $oneVar);
        if (
            !JIT\AsymmetricVisibilityGuard::emitBeforeStaticPropertyStore(
                $this->context,
                $this,
                $read,
                $this->context->jitEnclosingBlock
            )
            && !JIT\PropertyHookDispatch::emitStaticSetHookIfNeeded(
                $this->context,
                $write,
                $newVal,
                $this->context->jitEnclosingBlock,
                $this
            )
        ) {
            $this->context->type->object->staticPropertyStore(
                $read->staticPropertyGlobal,
                $newVal,
                $read->staticPropertyType,
                $read->staticPropertyInitGlobal
            );
        }
        if ($prefix) {
            $this->assignOperand($resultOp, $newVal, true);
        }
    }

    /** ++/-- on object properties: get/set hook dispatch or guard readonly (#6309, #3149). */
    private function compileObjectPropertyIncDecOp(
        Variable $read,
        \PHPCfg\Operand $resultOp,
        bool $increment,
        bool $prefix
    ): void {
        if (null === $read->objectPropertySlot || null === $read->objectPropertyType) {
            throw new \LogicException('objectPropertySlot requires objectPropertyType');
        }
        $current = null;
        if (
            null !== $read->objectPropertyReceiver
            && null !== $read->objectPropertyClassName
            && null !== $read->objectPropertyName
            && '' !== $read->objectPropertyName
        ) {
            $hookVal = JIT\PropertyHookDispatch::tryEmitPropertyGet(
                $this->context,
                $read->objectPropertyReceiver,
                $read->objectPropertyClassName,
                $read->objectPropertyName,
                $this->context->jitEnclosingBlock
            );
            if (null !== $hookVal) {
                $current = new Variable(
                    $this->context,
                    Variable::TYPE_VALUE,
                    Variable::KIND_VALUE,
                    $hookVal
                );
            } elseif (JIT\PropertyHookDispatch::emitWriteOnlyVirtualReadGuard(
                $this->context,
                $this,
                $read->objectPropertyClassName,
                $read->objectPropertyName
            )) {
                return;
            }
        }
        if (null === $current) {
            $current = $read;
        }
        if (!$prefix) {
            $this->assignOperand($resultOp, $current, true);
        }
        $arithOp = new OpCode($increment ? OpCode::TYPE_PLUS : OpCode::TYPE_MINUS);
        $oneVar = new Variable(
            $this->context,
            Variable::TYPE_NATIVE_LONG,
            Variable::KIND_VALUE,
            $this->context->constantFromInteger(1)
        );
        $newVal = $this->context->helper->binaryOp($arithOp, $current, $oneVar);
        if (JIT\PropertyHookDispatch::emitSetHookIfNeeded(
            $this->context,
            $read,
            $newVal,
            $this->context->jitEnclosingBlock,
            $this
        )) {
            if ($prefix) {
                $this->assignOperand($resultOp, $newVal, true);
            }

            return;
        }
        JIT\DynamicObjectReadonlyGuard::emitBeforePropertyStore(
            $this->context,
            $read,
            $this->context->jitEnclosingBlock
        );
        JIT\ReadonlyClassGuard::emitBeforePropertyStore(
            $this->context,
            $read,
            $this->context->jitEnclosingBlock
        );
        if (JIT\AsymmetricVisibilityGuard::emitBeforePropertyStore(
            $this->context,
            $this,
            $read,
            $this->context->jitEnclosingBlock
        )) {
            return;
        }
        JIT\ReadonlyClassGuard::emitStoreUnlessPending(
            $this->context,
            function () use ($read, $newVal): void {
                $this->context->type->object->propertyStore(
                    $read->objectPropertySlot,
                    $newVal,
                    $read->objectPropertyType
                );
            }
        );
        if ($prefix) {
            $this->assignOperand($resultOp, $newVal, true);
        }
    }

    private function compileBinaryOp(OpCode $op, Variable $left, Variable $right): Variable
    {
        if (Variable::TYPE_VALUE === $left->type && Variable::TYPE_VALUE === $right->type) {
            switch ($op->type) {
                case OpCode::TYPE_BITWISE_AND:
                case OpCode::TYPE_BITWISE_OR:
                case OpCode::TYPE_BITWISE_XOR:
                    return $this->compileValueBoxedBitwiseOp($op->type, $left, $right);
            }
        }

        return $this->context->helper->binaryOp($op, $left, $right);
    }

    private function compileValueBoxedBitwiseOp(int $opcodeType, Variable $left, Variable $right): Variable
    {
        $folded = $this->context->helper->tryFoldCoreIntBitwise($opcodeType, $left, $right);
        if (null !== $folded) {
            return Variable::fromConstantInt($this->context, $folded);
        }

        $leftPtr = Variable::KIND_VARIABLE === $left->kind
            ? $left->value
            : $this->context->helper->loadValue($left);
        $rightPtr = Variable::KIND_VARIABLE === $right->kind
            ? $right->value
            : $this->context->helper->loadValue($right);
        $readLong = $this->context->lookupFunction('__value__readLong');
        $leftLong = $this->context->builder->call($readLong, $leftPtr);
        $rightLong = $this->context->builder->call($readLong, $rightPtr);
        switch ($opcodeType) {
            case OpCode::TYPE_BITWISE_AND:
                $result = $this->context->builder->bitwiseAnd($leftLong, $rightLong);
                break;
            case OpCode::TYPE_BITWISE_OR:
                $result = $this->context->builder->bitwiseOr($leftLong, $rightLong);
                break;
            case OpCode::TYPE_BITWISE_XOR:
                $result = $this->context->builder->bitwiseXor($leftLong, $rightLong);
                break;
            default:
                throw new \LogicException('Unsupported boxed bitwise opcode: '.opcode_type_name($opcodeType));
        }

        return new Variable($this->context, Variable::TYPE_NATIVE_LONG, Variable::KIND_VALUE, $result);
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

    private function resolveJitStaticScopeClass(Block $block, Operand\Literal $classOp): string
    {
        $lc = strtolower($classOp->value);
        if ('self' === $lc) {
            if (null === $block->func || null === $block->func->class) {
                PseudoClassScope::fatalInGlobalScope('self');
            }

            return $block->func->class->value;
        }
        if ('static' === $lc) {
            if ($this->context->scope->calledClassName !== '') {
                return $this->context->scope->calledClassName;
            }
            if (null !== $block->func && null !== $block->func->class) {
                return $block->func->class->value;
            }
            PseudoClassScope::fatalInGlobalScope('static');
        }
        if ('parent' === $lc) {
            if (null === $block->func || null === $block->func->class) {
                PseudoClassScope::fatalInGlobalScope('parent');
            }
            $parentLc = $this->context->type->object->parentClassLc($block->func->class->value);
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

    private function instanceMethodUsesThis(Block $block): bool
    {
        if (null === $block->func || null === $block->func->class) {
            return false;
        }
        if (($block->func->flags ?? 0) & \PHPCfg\Func::FLAG_STATIC) {
            return false;
        }

        return true;
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

    private function initJitMethodCall(Block $block, Operand $receiverOp, string $methodName): void
    {
        if ('__invoke' === strtolower($methodName)) {
            $receiver = $this->context->getVariableFromOp($receiverOp);
            $closureCall = JIT\ClosureHelper::resolveCall($this->context, $receiver);
            if (null !== $closureCall) {
                $this->context->scope->toCall = $closureCall;
                $this->context->scope->args = [];

                return;
            }
        }
        if ('propertyisinitialized' === strtolower($methodName)) {
            $receiverVar = $this->context->getVariableFromOp($receiverOp);
            if (Type::TYPE_OBJECT === $receiverOp->type?->type) {
                JIT\LazyObjectHelper::emitEnsureInitialized(
                    $this->context,
                    $this->context->helper->loadValue($receiverVar)
                );
            }
            $this->context->scope->toCall = new VM\PropertyIsInitializedHandler();
            $this->context->scope->args = [$receiverVar];

            return;
        }
        $receiverVar = $this->context->getVariableFromOp($receiverOp);
        if (JIT\GeneratorHelper::isGeneratorVariable($receiverVar)) {
            $methodLc = strtolower($methodName);
            $proxyName = 'generator::'.$methodLc;
            if ($this->context->functionIsRegistered($proxyName)) {
                $this->context->scope->toCall = $this->context->resolveFunctionProxy($proxyName);
                $this->context->scope->args = [$receiverVar];

                return;
            }
        }
        if (null === $receiverOp->type) {
            // Bootstrap/self-host can hit methodcall init before operand typing stabilizes.
            // Prefer a safe short-circuit for stubbed self-host JIT paths over hard-crashing.
            if ($this->shouldUseSelfHostJitStubs()) {
                $this->context->scope->toCall = null;
                $this->context->scope->args = [];

                return;
            }
        } elseif (Type::TYPE_OBJECT !== $receiverOp->type->type) {
            // Some bootstrap paths produce a receiver operand whose inferred PHPCfg type
            // is not yet marked as object (but is still an object at runtime).
            if ($this->shouldUseSelfHostJitStubs()) {
                $this->context->scope->toCall = null;
                $this->context->scope->args = [];

                return;
            }
            // ?-> fetch blocks compile against a null-typed receiver slot; at runtime the
            // branch is only taken when the receiver is a real object (zend_compile.c).
            $methodLcEarly = strtolower($methodName);
            $runtimeCandidates = $this->buildRuntimeInstanceMethodCandidatesByClassId($methodLcEarly);
            if ([] !== $runtimeCandidates) {
                $receiverVar = $this->context->getVariableFromOp($receiverOp);
                $this->context->scope->toCall = new JIT\Call\RuntimeIndirectInstanceMethodCall(
                    $receiverVar,
                    $methodLcEarly,
                    $runtimeCandidates
                );
                $this->context->scope->args = [$receiverVar];

                return;
            }
        }

        $userType = $receiverOp->type?->userType;
        $className = (is_string($userType) && '' !== ltrim($userType, '\\'))
            ? $userType
            : ($this->context->scope->className !== '' ? $this->context->scope->className : 'object');
        $declaringClassLc = strtolower(ltrim($className, '\\'));
        $methodLc = strtolower($methodName);

        if ('object' === $declaringClassLc) {
            if ('getname' === $methodLc && $this->context->functionIsRegistered('reflectionattribute::getname')) {
                $className = 'ReflectionAttribute';
                $declaringClassLc = 'reflectionattribute';
            } elseif ('newinstance' === $methodLc && $this->context->functionIsRegistered('reflectionattribute::newinstance')) {
                $className = 'ReflectionAttribute';
                $declaringClassLc = 'reflectionattribute';
            } elseif ('getattributes' === $methodLc && $this->context->functionIsRegistered('reflectionmethod::getattributes')) {
                $className = 'ReflectionMethod';
                $declaringClassLc = 'reflectionmethod';
            }
        }

        $proxyName = $this->resolveJitInstanceMethodProxyName($declaringClassLc, $methodLc);
        $receiverVar = $this->context->getVariableFromOp($receiverOp);
        $dispatchReceiver = $this->jitInstanceMethodReceiverVariable($receiverVar);
        $splObjectStorageMethod = str_starts_with(strtolower($proxyName), 'splobjectstorage::');
        if (Type::TYPE_OBJECT === $receiverOp->type?->type && !$splObjectStorageMethod) {
            JIT\LazyObjectHelper::emitEnsureInitialized(
                $this->context,
                $this->context->helper->loadValue($dispatchReceiver)
            );
        }
        if (!$this->context->functionIsRegistered($proxyName)) {
            if ('getmessage' === $methodLc && $this->context->functionIsRegistered('exception::getmessage')) {
                $this->context->scope->toCall = $this->context->resolveFunctionProxy('exception::getmessage');
                $this->context->scope->args = [$receiverVar];

                return;
            }
            if ('object' === $declaringClassLc || '' === $declaringClassLc) {
                $runtimeCandidates = $this->buildRuntimeInstanceMethodCandidatesByClassId($methodLc);
                if ([] !== $runtimeCandidates) {
                    $this->context->scope->toCall = new JIT\Call\RuntimeIndirectInstanceMethodCall(
                        $receiverVar,
                        $methodLc,
                        $runtimeCandidates
                    );
                    $this->context->scope->args = [$receiverVar];

                    return;
                }
            }
            if (JIT\MagicMethodDispatch::tryInitMagicCall(
                $this->context,
                $declaringClassLc,
                $methodName,
                $receiverVar
            )) {
                return;
            }
            if ($this->isBundledJitExternalClassPrefix($declaringClassLc)) {
                $this->context->scope->toCall = $this->context->resolveFunctionProxy($proxyName);
                $this->context->scope->args = [$receiverVar];

                return;
            }
            throw new \LogicException("Call to undefined method {$className}::{$methodLc}()");
        }
        $receiverUserType = $receiverOp->type?->userType;
        $normalizedReceiverUserType = is_string($receiverUserType) ? ltrim($receiverUserType, '\\') : null;
        $staticProxy = $this->context->resolveFunctionProxy($proxyName);
        // :object receivers use RuntimeIndirectInstanceMethodCall; MCJIT segfaults on
        // ReflectionAttribute::newInstance() through that path (#4598).
        if ('reflectionattribute::newinstance' === strtolower($proxyName)) {
            $this->context->scope->toCall = $staticProxy;
            $this->context->scope->args = [$receiverVar];

            return;
        }
        $needsRuntimeDispatch = null === $normalizedReceiverUserType
            || '' === $normalizedReceiverUserType
            || 'object' === strtolower($normalizedReceiverUserType)
            || $staticProxy instanceof JIT\Call\ExternalMethod;
        if ($needsRuntimeDispatch) {
            $runtimeCandidates = $this->buildRuntimeInstanceMethodCandidatesByClassId($methodLc);
            if ([] !== $runtimeCandidates) {
                $this->context->scope->toCall = new JIT\Call\RuntimeIndirectInstanceMethodCall(
                    $receiverVar,
                    $methodLc,
                    $runtimeCandidates
                );
                $this->context->scope->args = [$receiverVar];

                return;
            }
        }
        $resolvedClassLc = strstr($proxyName, '::', true) ?: $declaringClassLc;
        $declaringClassId = $this->context->type->object->lookup($resolvedClassLc);
        $visFlags = $this->context->type->object->methodVisibility($declaringClassId, $methodLc);
        $callerClassLc = null;
        if (null !== $block->func && null !== $block->func->class) {
            $callerClassLc = strtolower($block->func->class->value);
        } elseif ($this->context->scope->className !== '') {
            $callerClassLc = $this->context->scope->className;
        }
        MethodVisibility::assertCallable(
            $visFlags,
            $callerClassLc,
            $resolvedClassLc,
            $className,
            $methodName
        );
        if (
            null !== $receiverUserType
            && 'object' !== strtolower(ltrim((string) $receiverUserType, '\\'))
        ) {
            $this->context->scope->lateStaticCallClassId = $this->context->type->object->lookup($receiverUserType);
        }
        $this->context->scope->toCall = $staticProxy;
        $this->context->scope->args = [$splObjectStorageMethod ? $receiverVar : $dispatchReceiver];
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
            $proxyName = $this->resolveJitInstanceMethodProxyName($classLc, $methodLc);
            if (!$this->context->functionIsRegistered($proxyName)) {
                continue;
            }
            $candidates[$classId] = $this->context->resolveFunctionProxy($proxyName);
        }

        return $candidates;
    }

    /**
     * Resolve lowered instance method proxy, walking extends chain (#101, Zend zend_inheritance).
     */
    private function resolveJitInstanceMethodProxyName(string $classLc, string $methodLc): string
    {
        $methodLc = strtolower($methodLc);
        $visited = [];
        $current = strtolower(ltrim($classLc, '\\'));
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

    private function isSelfHostSuperglobalsClassLc(string $classLc): bool
    {
        $classLc = strtolower(ltrim($classLc, '\\'));

        return 'superglobals' === $classLc || str_ends_with($classLc, '\\superglobals');
    }

    private function tryResolveSelfHostSuperglobalsStaticCall(string $className, string $methodName): bool
    {
        if (!$this->shouldUseSelfHostJitStubs()) {
            return false;
        }
        $declaringClassLc = strtolower(ltrim($className, '\\'));
        if (!$this->isSelfHostSuperglobalsClassLc($declaringClassLc)) {
            return false;
        }
        $methodLc = strtolower($methodName);
        $fullLower = ('superglobals' === $declaringClassLc ? 'phpcompiler\\web\\superglobals' : $declaringClassLc)
            .'::'.$methodLc;
        if ('populatefromenvironment' === $methodLc) {
            JIT\Builtin\SuperglobalRefreshRuntime::ensureLinked($this->context);
            if (!$this->context->functionIsRegistered('__superglobals__refresh')) {
                JIT\SuperglobalInit::declareRefresh($this->context);
            }
            $this->context->scope->toCall = $this->context->resolveFunctionProxy('__superglobals__refresh');

            return true;
        }
        if (!$this->isSuperglobalsRealLoweringMethod($fullLower)
            && !str_ends_with($fullLower, '::issuperglobalname')) {
            $this->context->scope->toCall = $this->context->resolveFunctionProxy($fullLower);

            return true;
        }

        return false;
    }

    /**
     * Lower Progress::{noteFunction,notePhase,noteEntry} to __phpc_progress_note when the PHP
     * method is not yet queued (self-host spine compile order — #8560, #6748).
     */
    private function tryResolveProgressStaticCall(string $className, string $methodName): bool
    {
        $declaringClassLc = strtolower(ltrim($className, '\\'));
        if ('phpcompiler\\jit\\progress' !== $declaringClassLc && 'jit\\progress' !== $declaringClassLc) {
            return false;
        }
        $methodLc = strtolower($methodName);
        if (!in_array($methodLc, ['notefunction', 'notephase', 'noteentry'], true)) {
            return false;
        }
        JIT\Builtin\ProgressNoteRuntime::ensureLinked($this->context);
        $proxy = 'phpcompiler\\jit\\progress::'.strtolower($methodName);
        if (!$this->context->functionIsRegistered($proxy)) {
            return false;
        }
        $this->context->scope->toCall = $this->context->resolveFunctionProxy($proxy);

        return true;
    }

    private function resolveJitStaticMethodProxyName(string $classLc, string $methodLc): string
    {
        $methodLc = strtolower($methodLc);
        $visited = [];
        $current = strtolower(ltrim($classLc, '\\'));
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
            $parentLc = $this->context->type->object->parentClassLc($current);
            if (null === $parentLc) {
                break;
            }
            $current = $parentLc;
        }

        return strtolower(ltrim($classLc, '\\')).'::'.$methodLc;
    }

    /**
     * Zend zend_std_get_static_method: reject instance methods on Class::name() (#5339).
     */
    private function assertJitStaticMethodCallable(
        string $calledClassLc,
        string $methodLc,
        string $calledClassName,
        string $methodDisplay
    ): void {
        if ($this->context->type->object->isEnumClassLc(strtolower(ltrim($calledClassLc, '\\')))
            && 'cases' === $methodLc) {
            return;
        }
        $visited = [];
        $current = strtolower(ltrim($calledClassLc, '\\'));
        while (!isset($visited[$current])) {
            $visited[$current] = true;
            if ($this->context->type->object->hasDeclaredClass($current)) {
                $classId = $this->context->type->object->lookup($current);
                if ($this->context->type->object->hasMethod($classId, $methodLc)) {
                    $vis = $this->context->type->object->methodVisibility($classId, $methodLc);
                    if (0 === ($vis & \PHPCfg\Func::FLAG_STATIC)) {
                        $declaringName = $this->context->type->object->classNameForId($classId);
                        throw new \LogicException(
                            'Non-static method '.$declaringName.'::'.$methodDisplay.'() cannot be called statically'
                        );
                    }

                    return;
                }
            }
            $parent = $this->context->type->object->parentClassLc($current);
            if (null === $parent) {
                break;
            }
            $current = $parent;
        }
    }

    private function initJitStaticCall(Block $block, int $classOpIdx, int $nameOpIdx, bool $parentScope = false): void
    {
        $classOp = $block->getOperand($classOpIdx);
        $nameOp = $block->getOperand($nameOpIdx);
        assert($nameOp instanceof Operand\Literal);
        if (!$classOp instanceof Operand\Literal) {
            throw new \LogicException('Static call class must be a literal');
        }
        $className = $this->resolveJitStaticScopeClass($block, $classOp);
        $declaringClassLc = strtolower($className);
        $methodLc = strtolower($nameOp->value);
        if ($this->context->compilingFiberResume && 'fiber' === $declaringClassLc && 'suspend' === $methodLc) {
            $this->context->scope->toCall = null;
            $this->context->scope->args = [];

            return;
        }
        $declaringClassId = $this->context->type->object->lookup($className);
        $callerClassLc = null;
        if (null !== $block->func && null !== $block->func->class) {
            $callerClassLc = strtolower($block->func->class->value);
        } elseif ($this->context->scope->className !== '') {
            $callerClassLc = $this->context->scope->className;
        }
        $callerInstanceMethod = $this->instanceMethodUsesThis($block);
        $directParentLc = null !== $callerClassLc
            ? $this->context->type->object->parentClassLc($callerClassLc)
            : null;
        // Compiler staticCallParentScope + php-cfg lowered parent class (#1858, #6735).
        $parentScopeInstanceCall = ($parentScope && $callerInstanceMethod)
            || ($callerInstanceMethod
                && null !== $directParentLc
                && $directParentLc === $declaringClassLc);
        if (!$parentScopeInstanceCall) {
            $this->assertJitStaticMethodCallable($declaringClassLc, $methodLc, $className, $nameOp->value);
        }
        $visFlags = $this->context->type->object->methodVisibility($declaringClassId, $methodLc);
        $parentScopeAllows = false;
        if (null !== $callerClassLc) {
            if (null !== $directParentLc && $directParentLc === $declaringClassLc) {
                $parentScopeAllows = MethodVisibility::parentScopeAllows(
                    $visFlags,
                    $callerClassLc,
                    $declaringClassLc,
                    $declaringClassLc,
                    fn (string $classLc, string $ancestorLc): bool => $this->jitIsClassSameOrSubclassOf($classLc, $ancestorLc)
                );
            }
        }
        MethodVisibility::assertCallable(
            $visFlags,
            $callerClassLc,
            $declaringClassLc,
            $className,
            $nameOp->value,
            $parentScopeAllows
        );
        $proxyName = $this->resolveJitStaticMethodProxyName($declaringClassLc, $methodLc);
        if (!$this->context->functionIsRegistered($proxyName)) {
            if ($this->context->type->object->isEnumClassLc($declaringClassLc)
                && \in_array($methodLc, ['cases', 'from', 'tryfrom'], true)) {
                $this->context->type->object->finishEnumClass($declaringClassId);
            }
        }
        if (!$this->context->functionIsRegistered($proxyName)) {
            if ($this->context->type->object->isExternalOnlyClass($declaringClassId)) {
                $this->context->scope->toCall = $this->context->resolveFunctionProxy($proxyName);
                $this->context->scope->args = [];

                return;
            }
            // Zend FFI is not lowered in self-host AOT bundles (#2633, StringPasswordCrypto::preloadLibcrypt).
            if ($this->shouldUseSelfHostJitStubs() && 'ffi' === $declaringClassLc) {
                $this->context->scope->toCall = $this->context->resolveFunctionProxy(
                    $className.'::'.$nameOp->value
                );
                $this->context->scope->args = [];

                return;
            }
            // bin/compile.php process capture: lower LinkerProcessPolyfill::run() to the AOT builtin
            // phpc_run_command() (proc_open is not lowered; #2779). This applies to any native AOT/JIT
            // compilation path (not only self-host stubs).
            if ('phpcompiler\\aot\\linkerprocesspolyfill' === $declaringClassLc && 'run' === $methodLc) {
                if (!$this->context->functionIsRegistered('phpc_run_command')) {
                    throw new \LogicException(
                        'phpc_run_command internal missing for LinkerProcessPolyfill::run lowering (#2779)'
                    );
                }
                $this->context->scope->toCall = $this->context->resolveFunctionProxy('phpc_run_command');
                $this->context->scope->args = [];

                return;
            }
            if ($this->tryResolveSelfHostSuperglobalsStaticCall($className, $nameOp->value)) {
                $this->context->scope->args = [];

                return;
            }
            if ($this->tryResolveProgressStaticCall($className, $nameOp->value)) {
                $this->context->scope->args = [];

                return;
            }
            throw new \LogicException("Call to undefined static method {$className}::{$nameOp->value}()");
        }
        $this->context->scope->lateStaticCallClassId = $declaringClassId;
        $this->context->scope->toCall = $this->context->resolveFunctionProxy($proxyName);
        $this->context->scope->args = [];
    }

    /**
     * @param list<Variable> $callArgs
     */
    private function emitJitLateStaticCallSiteBinding(array $callArgs): void
    {
        if (!JIT\LateStaticBindingHelper::useRuntimeLateStatic($this->context)) {
            return;
        }
        // Store call-site class before Internal early-out — get_called_class() reads phpc_late_static_class_id (#4255).
        if (null !== $this->context->scope->lateStaticCallClassId) {
            JIT\LateStaticBindingHelper::emitStoreClassId(
                $this->context,
                $this->context->constantFromInteger($this->context->scope->lateStaticCallClassId, 'int64')
            );
            $this->context->scope->lateStaticCallClassId = null;
        }
        $toCall = $this->context->scope->toCall;
        if (
            $toCall instanceof CoreFunc\Internal
            || $toCall instanceof JIT\Call\Native
            || $toCall instanceof JIT\Call\ExternalMethod
            || $toCall instanceof JIT\Call\RuntimeIndirectInstanceMethodCall
        ) {
            return;
        }
        if ([] === $callArgs) {
            return;
        }
        $receiver = $callArgs[0];
        if (Variable::TYPE_OBJECT !== $receiver->type) {
            return;
        }
        $objMap = $this->context->structFieldMap['__object__'];
        $classId = $this->context->builder->load(
            $this->context->builder->structGep($receiver->value, $objMap['class_id'])
        );
        JIT\LateStaticBindingHelper::emitStoreClassId($this->context, $classId);
    }

    /**
     * Static parent::__construct() from an instance method passes only declared params;
     * the callee LLVM signature may still include implicit $this when blockUsesThis().
     *
     * @param array<int, Variable> $args
     *
     * @return array<int, Variable>
     */
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
     * @return array{0: list<Variable>, 1: list<Operand|null>}
     */
    private function resolveJitOutgoingCall(JIT\Call $toCall, array $argEntries, array $argOperands): array
    {
        if (null !== $this->context->scope->magicCallMethodName) {
            $methodName = $this->context->scope->magicCallMethodName;
            $this->context->scope->magicCallMethodName = null;
            $rewritten = JIT\MagicMethodDispatch::rewriteOutgoingMagicCallArgs(
                $this->context,
                $methodName,
                $argEntries,
                $argOperands
            );
            if (null !== $rewritten) {
                return $rewritten;
            }
        }

        if ($this->jitCallArgsHaveUnpack($argEntries)) {
            [$paramNames, $variadicIndex] = $this->jitCalleeParamMetadata($toCall);
            $functionName = $this->jitInternalBuiltinFunctionName($toCall);
            $namedUnpack = JIT\CallUnpackHelper::tryResolveCompileTimeNamedUnpack(
                $this->context->jitEnclosingBlock,
                $argEntries,
                $argOperands,
                $paramNames,
                $variadicIndex,
                $this,
                $functionName
            );
            if (null !== $namedUnpack) {
                return $namedUnpack;
            }

            return [
                $this->finalizeJitCallArgs($argEntries),
                $argOperands,
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
                [$userArgs, $userOps] = JIT\NamedArgs::resolveOutgoing(
                    $userEntries,
                    $userOperands,
                    $paramNames,
                    $variadicIndex,
                    $this->jitInternalBuiltinFunctionName($toCall)
                );
                $callArgs = $prefix;
                foreach ($userArgs as $idx => $value) {
                    $callArgs[$prefixLen + (int) $idx] = $value;
                }
                $callOperands = $prefixOperands;
                foreach ($userOps as $idx => $operand) {
                    $callOperands[$prefixLen + (int) $idx] = $operand;
                }

                return [$callArgs, $callOperands];
            }
        }

        return [
            $this->finalizeJitCallArgs($argEntries),
            $argOperands,
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
            $names = BuiltinParamNames::forFunction($toCall->name);

            return [$names ?? [], null];
        }
        if ($toCall instanceof CoreFunc\Internal) {
            $name = $toCall->getName();

            return [
                BuiltinParamNames::forFunction($name) ?? [],
                BuiltinParamNames::variadicParamIndexForFunction($name),
            ];
        }

        return [[], null];
    }

    private function jitInternalBuiltinFunctionName(JIT\Call $toCall): ?string
    {
        if ($toCall instanceof JIT\Call\Native) {
            return $toCall->name;
        }
        if ($toCall instanceof CoreFunc\Internal) {
            return $toCall->getName();
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
        if (!$toCall instanceof JIT\Call\Native || [] === $toCall->argTypes) {
            return 0;
        }

        return '__object__*' === $this->context->getStringFromType($toCall->argTypes[0]) ? 1 : 0;
    }

    /**
     * Static parent::instanceMethod() from an instance method passes implicit $this (#1858).
     */
    private function prependImplicitThisForStaticInstanceCall(
        Block $block,
        JIT\Call $toCall,
        array $args
    ): array {
        if (!$toCall instanceof JIT\Call\Native) {
            return $args;
        }
        if ([] === $toCall->argTypes) {
            return $args;
        }
        if ('__object__*' !== $this->context->getStringFromType($toCall->argTypes[0])) {
            return $args;
        }
        if (count($args) >= count($toCall->argTypes)) {
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
        if (\count($operands) >= \count($toCall->argTypes)) {
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
            return null;
        }
        foreach ($block->func->cfg->hoistedOperands as $hoisted) {
            if ('this' !== JIT\OperandName::resolve($hoisted)) {
                continue;
            }
            if (!$this->context->hasVariableOpInScopes($hoisted)) {
                return null;
            }

            return $this->context->getVariableFromOpInScopes($hoisted);
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
        $offset = $this->instanceMethodUsesThis($block) ? 1 : 0;
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
        $offset = $this->instanceMethodUsesThis($block) ? 1 : 0;
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
        $offset = $this->instanceMethodUsesThis($block) ? 1 : 0;
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
        $offset = $this->instanceMethodUsesThis($block) ? 1 : 0;
        foreach ($block->opCodes as $op) {
            if (OpCode::TYPE_ARG_RECV !== $op->type) {
                continue;
            }
            $slot = (int) $op->arg1;
            $paramIdx = (int) $op->arg2;
            if (!isset($block->paramClassConstraints[$slot])) {
                continue;
            }
            $constraints[$paramIdx + $offset] = $block->paramClassConstraints[$slot];
        }

        return $constraints;
    }

    /**
     * @return array<int, list<array{kind: string, interfaces?: list<string>, display?: string, name?: string}>>
     */
    private function paramDnfConstraintsForNativeCall(Block $block): array
    {
        $constraints = [];
        $offset = $this->instanceMethodUsesThis($block) ? 1 : 0;
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
        $offset = $this->instanceMethodUsesThis($block) ? 1 : 0;
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
    }

    /**
     * @param list<Variable> $args
     * @param list<Operand> $operands
     *
     * @return list<Variable>
     */
    private function adaptByRefCallArgs(JIT\Call\Native $call, array $args, array $operands): array
    {
        if ([] === $call->paramByRefByArg) {
            return $args;
        }
        foreach ($call->paramByRefByArg as $idx => $_) {
            if (!isset($args[$idx])) {
                continue;
            }
            $operand = $operands[$idx] ?? null;
            if (null === $operand) {
                continue;
            }
            $args[$idx] = $this->ensureValueBoxLvalueForByRefPass($operand, $args[$idx]);
        }

        return $args;
    }

    private function adaptByRefCallArgsForInternal(string $name, array $args, array $operands): array
    {
        $byRef = BuiltinByRefParams::forFunction($name);
        foreach ($byRef as $idx) {
            if (!isset($args[$idx])) {
                continue;
            }
            $operand = $operands[$idx] ?? null;
            if (null === $operand) {
                continue;
            }
            if (!JIT\JitReferencableCheck::isOperandReferenceable($operand, $args[$idx])) {
                if (
                    0 === $idx
                    && VM\ReferencableCheck::allowsEphemeralArrayLiteralByRef($name)
                    && JIT\JitReferencableCheck::isEphemeralArrayArg($args[$idx])
                ) {
                    continue;
                }
                JIT\JitReferencableCheck::emitByRefError($this->context, $name, $idx);

                continue;
            }
            $args[$idx] = $this->ensureValueBoxLvalueForByRefPass($operand, $args[$idx]);
        }
        $variadicFrom = BuiltinByRefParams::variadicByRefFromIndex($name);
        if (null !== $variadicFrom) {
            $n = \count($args);
            for ($idx = $variadicFrom; $idx < $n; ++$idx) {
                if (!isset($args[$idx])) {
                    continue;
                }
                $operand = $operands[$idx] ?? null;
                if (null === $operand) {
                    continue;
                }
                if (
                    'array_multisort' === strtolower($name)
                    && !self::jitArgLooksLikeArray($args[$idx])
                ) {
                    continue;
                }
                if (!JIT\JitReferencableCheck::isOperandReferenceable($operand, $args[$idx])) {
                    JIT\JitReferencableCheck::emitByRefError($this->context, $name, $idx);

                    continue;
                }
                $args[$idx] = $this->ensureValueBoxLvalueForByRefPass($operand, $args[$idx]);
            }
        }

        return $args;
    }

    private static function jitArgLooksLikeArray(JIT\Variable $arg): bool
    {
        if (JIT\Variable::TYPE_HASHTABLE === ($arg->type & ~JIT\Variable::IS_NATIVE_ARRAY)
            || JIT\ArrayBuiltinHelper::isNativeArray($arg->type)) {
            return true;
        }

        return JIT\Variable::TYPE_VALUE === $arg->type;
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
        if (!\in_array($lc, ['sort', 'rsort', 'asort', 'arsort', 'ksort', 'krsort'], true)) {
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
        if (Variable::TYPE_VALUE === $var->type || null !== $var->valueBoxAliasPtr) {
            return JIT\ClosureHelper::referenceCapture($this->context, $var);
        }
        $slot = JIT\JitValueBox::alloc($this->context);
        $native = $this->context->helper->loadValue($var);
        switch ($var->type) {
            case Variable::TYPE_NATIVE_LONG:
                JIT\JitValueBox::writeLong($this->context, $slot, $native);
                break;
            case Variable::TYPE_NATIVE_BOOL:
                JIT\JitValueBox::writeBool($this->context, $slot, $native);
                break;
            case Variable::TYPE_NATIVE_DOUBLE:
                $this->context->builder->call(
                    $this->context->lookupFunction('__value__writeDouble'),
                    JIT\JitValueBox::pointer($this->context, $slot),
                    $native
                );
                break;
            case Variable::TYPE_STRING:
                $owned = $this->context->builder->call(
                    $this->context->lookupFunction('__string__separate'),
                    $native
                );
                $this->context->builder->call(
                    $this->context->lookupFunction('__value__writeString'),
                    JIT\JitValueBox::pointer($this->context, $slot),
                    $owned
                );
                break;
            default:
                throw new \LogicException(
                    'By-reference call argument requires a boxed lvalue, got '
                    . Variable::getStringType($var->type)
                );
        }
        $boxed = new Variable($this->context, Variable::TYPE_VALUE, Variable::KIND_VARIABLE, $slot);
        $this->context->setVariableOp($op, $boxed);
        $name = JIT\OperandName::resolve($op);
        if (null !== $name) {
            $this->context->bindVariableByName($name, $boxed);
        }

        return JIT\ClosureHelper::referenceCapture($this->context, $boxed);
    }

    private function collectParamDefaults(Block $block): array {
        $defaults = [];
        foreach ($block->opCodes as $op) {
            if ($op->type !== OpCode::TYPE_ARG_RECV || null === $op->arg3) {
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
            $defaults[$defaultIdx] = $this->jitVariableFromVmConstant($block->constants[$op->arg3]);
        }
        return $defaults;
    }

    /**
     * Resolve a class constant initializer for JIT defineClassConst (#4900, zend_constants.c).
     */
    private function jitClassConstDefineValue(
        Block $block,
        OpCode $op,
        string $constNameLc,
        int $classId
    ): VM\Variable {
        if (
            !isset($block->constants[$op->arg2])
            || $block->constants[$op->arg2]->is(VM\Variable::TYPE_NULL)
        ) {
            $vm = new VM($this->context->runtime->vmContext);
            $className = $this->context->type->object->classNameForId($classId);
            $value = VM\ClassConstMaterializer::materializeSlot($vm, $block, $op->arg2, $className);
        } else {
            $value = $block->constants[$op->arg2];
        }
        if (null !== $op->arg3 && isset($block->constants[$op->arg3])) {
            $check = new VM\Variable();
            $check->copyFrom($value);
            VM\TypeCheck::assertClassConstantTypedValue($check, $block->constants[$op->arg3], $constNameLc);
            $value = $check;
        }

        return $value;
    }

    private function jitVariableFromVmConstant(VM\Variable $vm): Variable {
        switch ($vm->type) {
            case VM\Variable::TYPE_INTEGER:
                return Variable::fromConstantInt($this->context, $vm->toInt());
            case VM\Variable::TYPE_STRING:
                $lit = new Operand\Literal($vm->toString());
                $lit->type = Type::string();
                return Variable::fromLiteral($this->context, $lit);
            case VM\Variable::TYPE_FLOAT:
                $lit = new Operand\Literal($vm->toFloat());
                $lit->type = Type::float();
                return Variable::fromLiteral($this->context, $lit);
            case VM\Variable::TYPE_BOOLEAN:
                $lit = new Operand\Literal($vm->toBool());
                $lit->type = Type::bool();
                return Variable::fromLiteral($this->context, $lit);
            case VM\Variable::TYPE_NULL:
                $nullVar = new Variable(
                    $this->context,
                    Variable::TYPE_NULL,
                    Variable::KIND_VALUE,
                    $this->context->getTypeFromString('__value__*')->constNull()
                );
                $nullVar->isNullConstant = true;

                return $nullVar;
            case VM\Variable::TYPE_ARRAY:
                return $this->jitVariableFromVmArray($vm);
            default:
                throw new \LogicException('Unsupported default parameter type for JIT (vm type ' . $vm->type . ')');
        }
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
        $ht = $vm->toArray();
        $jitHt = JIT\HashTableHelper::alloc($this->context);
        $var = new Variable(
            $this->context,
            Variable::TYPE_HASHTABLE,
            Variable::KIND_VALUE,
            $jitHt
        );
        if (0 === $ht->getNumElements()) {
            return $var;
        }
        foreach ($ht->iterateKeyed(true) as [$key, $value]) {
            JIT\HashTableHelper::addElement(
                $this->context,
                $var,
                $this->jitVariableFromVmConstant($value),
                $this->jitVariableFromVmConstant($key)
            );
        }

        return $var;
    }

    private function loadPropertyFetchReceiver(Operand $objOp): PHPLLVM\Value
    {
        $var = $this->context->getVariableFromOp($objOp);
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

    private static function foreachContainerUserType(Operand $arrayOp): ?string
    {
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
        if (null !== $dest->compileTimeString) {
            return;
        }
        if (null !== $source->compileTimeString) {
            $dest->compileTimeString = $source->compileTimeString;

            return;
        }
        $this->foldCompileTimeStringFromSlot($block, $sourceSlot, $dest);
    }

    private function foldCompileTimeStringFromSlot(Block $block, int $slot, Variable $dest): void
    {
        if (null !== $dest->compileTimeString) {
            return;
        }
        $resolved = $this->resolveJitCompileTimeStringSlot($block, $slot);
        if (null !== $resolved) {
            $dest->compileTimeString = $resolved;
        }
    }

    /**
     * @param list<Operand|null> $operands
     * @param list<Variable> $args
     */
    private function promoteCompileTimeStringOnCallArgs(Block $block, array $operands, array $args): void
    {
        foreach ($args as $i => $arg) {
            if (null !== $arg->compileTimeString) {
                continue;
            }
            $operand = $operands[$i] ?? null;
            if (!$operand instanceof \PHPCfg\Operand) {
                continue;
            }
            $slot = $block->slotForOperand($operand);
            if (null === $slot) {
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
            if (OpCode::TYPE_CONCAT === $prior->type && $prior->arg1 === $slot) {
                $left = $this->resolveJitCompileTimeStringSlot($block, (int) $prior->arg2, $visited);
                $right = $this->resolveJitCompileTimeStringSlot($block, (int) $prior->arg3, $visited);
                if (null !== $left && null !== $right) {
                    return $left.$right;
                }
            }
            if (OpCode::TYPE_ASSIGN !== $prior->type || $prior->arg2 !== $slot) {
                continue;
            }
            $resolved = $this->resolveJitCompileTimeStringSlot($block, (int) $prior->arg3, $visited);
            if (null !== $resolved) {
                return $resolved;
            }
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

        return null;
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
        $writeNull = $this->context->lookupFunction('__value__writeNull');
        foreach ($block->eachNamedScopeSlot() as [$name, $slotIdx]) {
            if ('this' === $name) {
                continue;
            }
            $scopedOp = $block->operandForScopeSlot($slotIdx);
            if (null === $scopedOp || !$this->context->hasVariableOp($scopedOp)) {
                continue;
            }
            $var = $this->context->getVariableFromOp($scopedOp);
            if (Variable::KIND_VARIABLE !== $var->kind || Variable::TYPE_VALUE !== $var->type) {
                continue;
            }
            $this->context->builder->call($writeNull, $var->value);
        }
    }

    /**
     * unset($var) on boxed locals: run __destruct before nulling when {main} defers delref destroy (#4096).
     */
    private function jitWriteNullForUnset(\PHPLLVM\Value $valueBoxPtr): void
    {
        if ($this->context->type->object->hasUserDestructors()) {
            \PHPCompiler\JIT\Builtin\GcCollectCyclesRuntime::ensureLinked($this->context);
            $map = $this->context->structFieldMap['__value__'];
            $i8 = $this->context->getTypeFromString('int8');
            $typeByte = $this->context->builder->load(
                $this->context->builder->structGep($valueBoxPtr, $map['type'])
            );
            $isObject = $this->context->builder->icmp(
                \PHPLLVM\Builder::INT_EQ,
                $typeByte,
                $i8->constInt(Variable::TYPE_OBJECT, false)
            );
            $invokeBlock = JIT\BasicBlockHelper::append($this->context, 'unset_destruct_invoke');
            $doneBlock = JIT\BasicBlockHelper::append($this->context, 'unset_destruct_done');
            $this->context->builder->branchIf($isObject, $invokeBlock, $doneBlock);
            $this->context->builder->positionAtEnd($invokeBlock);
            $obj = $this->context->builder->call(
                $this->context->lookupFunction('__value__readObject'),
                $valueBoxPtr
            );
            $this->context->builder->call(
                $this->context->lookupFunction('phpc_destruct_try_invoke'),
                $this->context->builder->pointerCast($obj, $this->context->getTypeFromString('int8*'))
            );
            $this->context->builder->branch($doneBlock);
            $this->context->builder->positionAtEnd($doneBlock);
        }
        $this->jitNoteMemoryReleaseForUnset($valueBoxPtr);
        $this->context->builder->call(
            $this->context->lookupFunction('__value__writeNull'),
            $valueBoxPtr
        );
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
        $var->free();
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
        $this->context->bindVariableByName($name, $this->context->getVariableFromOp($op));
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
            if (
                OpCode::TYPE_ASSIGN !== $prior->type
                || $prior->arg2 !== $nameSlot
                || !isset($block->constants[$prior->arg3])
            ) {
                continue;
            }
            $nameVar->compileTimeString = $block->constants[$prior->arg3]->toString();

            return;
        }
    }

    private function varFetchDestUsedAsAssignLvalue(Block $block, int $opIndex, int $destSlot): bool
    {
        for ($j = $opIndex + 1, $n = count($block->opCodes); $j < $n; $j++) {
            if (OpCode::destSlotUsedAsAssignLvalue($block->opCodes[$j], $destSlot)) {
                return true;
            }
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
        if (!$enumClassOp instanceof Operand\Literal || !$caseOp instanceof Operand\Literal) {
            return null;
        }
        $enumClassId = $this->context->type->object->lookup(strtolower($enumClassOp->value));
        if (!$this->context->type->object->isEnumClassId($enumClassId)) {
            return null;
        }

        return [$enumClassId, strtolower($caseOp->value)];
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
