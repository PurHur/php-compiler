<?php

declare(strict_types=1);

namespace PHPCompiler\JIT;

use PHPCompiler\Config;
use PHPLLVM;

/**
 * LLVM IR optimization pipeline for {@see Context} (#36387).
 *
 * Extracted from {@see ContextModuleCompileAndOptimize} so light/heavy
 * PassManager runs stay a separate TU from compileInPlace / {@see ContextModuleVerify}
 * (split-TU / size-budget ratchet, #36199 / #36403). Invoked from
 * compileInPlace and {@see ContextCompileToFile} via
 * {@see runModuleOptimizationPasses()}.
 *
 * Used via {@code use ContextModuleOptimizationPasses;} on {@see Context}.
 *
 * No new C ABI. php-src analogy: Zend/Optimizer pass pipeline
 * (Zend/Optimizer/zend_optimizer.c, opcache optimize) runs beside compile
 * rather than inside zend_compile itself.
 */
trait ContextModuleOptimizationPasses
{
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
}
