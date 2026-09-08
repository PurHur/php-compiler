<?php

declare(strict_types=1);

namespace PHPCompiler\JIT;

use PHPCompiler\Config;
use PHPLLVM;

/**
 * MCJIT compile-in-place, module verify, and opt passes for {@see Context} (#36387).
 *
 * Extracted from {@see Context} so compileInPlace / compileCommon /
 * verifyModuleOrThrow / runModuleOptimizationPasses stay a separate TU from
 * the Context construction / type hub (split-TU / size-budget ratchet toward
 * Context ≤ 4k lines, #36199 / #36403).
 *
 * Used via {@code use ContextModuleCompileAndOptimize;} on {@see Context}.
 *
 * No new C ABI. php-src analogy: zend_compile + opcache optimize pass pipeline
 * and executor hand-off live beside the compiler front-end
 * (Zend/zend_compile.c, Zend/Optimizer/, Zend/zend_execute.c).
 */
trait ContextModuleCompileAndOptimize
{
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
}
