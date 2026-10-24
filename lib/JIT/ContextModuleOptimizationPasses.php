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
    /** True once {@see internalizeForStandaloneLink()} stripped the module for a standalone link. */
    private bool $aotInternalized = false;

    /**
     * Give internal linkage to every function the final link cannot need from outside this
     * module, then drop the unreachable ones.
     *
     * The script module carries the whole runtime library (hashtable, string, object, error
     * helpers …) with external linkage, so neither the optimizer nor the code generator can
     * discard any of it — roughly 80% of the IR of a small script is unreachable from `main`,
     * and every pipeline stage paid for it. Peer objects (helper-runtime units, prelinked
     * vendor objects) define their own copies of the runtime ABI (`-z muldefs`); the only
     * script functions they need are their undefined symbols, which stay external, as do all
     * globals (single-copy runtime state).
     *
     * Only for a plain standalone link: chunked/partial/object-only emits link this module
     * against other script objects that may reference anything in it.
     */
    private function internalizeForStandaloneLink(): void
    {
        $flag = Config::getenv('PHP_COMPILER_AOT_INTERNALIZE');
        if ('0' === $flag || 'false' === strtolower((string) $flag)) {
            return;
        }
        foreach (['PHP_COMPILER_KEEP_OBJECT_FILE', 'PHP_COMPILER_VENDOR_PRELINK', 'PHP_COMPILER_SELFHOST_AOT'] as $objectOnlyEnv) {
            $v = Config::getenv($objectOnlyEnv);
            if ('1' === $v || 'true' === strtolower((string) $v)) {
                return;
            }
        }
        if (null !== $this->debugFile) {
            return;
        }
        $bitcodePath = Config::getenv('PHP_COMPILER_EMIT_BITCODE');
        if (is_string($bitcodePath) && '' !== $bitcodePath) {
            return;
        }
        if (CompileCache::isEditScaffoldPartial() || [] !== \PHPCompiler\AOT\Linker::resolveExtraScriptObjects()) {
            return;
        }
        $peerUndefined = \PHPCompiler\AOT\Linker::undefinedSymbolsOfPeerObjects();
        if (null === $peerUndefined) {
            return;
        }
        $keep = array_fill_keys($peerUndefined, true);
        $keep['main'] = true;

        $lib = $this->llvm->lib;
        $internalized = 0;
        $function = $this->module->getFirstFunction();
        while (null !== $function) {
            if ($function instanceof PHPLLVM\Value\Function_ && $function->countBasicBlocks() > 0) {
                $raw = $lib->LLVMGetValueName($function->value);
                $name = null === $raw ? '' : $raw->toString();
                if ('' !== $name && !isset($keep[$name]) && !str_starts_with($name, 'llvm.')) {
                    $lib->LLVMSetLinkage($function->value, \llvm\llvm::LLVMInternalLinkage);
                    ++$internalized;
                }
            }
            $function = $function->getNext();
        }
        $this->aotInternalized = true;
        if (0 === $internalized) {
            return;
        }
        $pm = $this->llvm->createPassManager();
        $lib->LLVMAddGlobalDCEPass($pm->passManager);
        $pm->run($this->module);
        $pm->dispose();
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
                // Stripped standalone module: the full O2 pipeline is cheap enough to be the
                // default (#36386) — it is what turns the boxed-value IR into machine
                // arithmetic. Unstripped modules keep the old skip (compile-time budget).
                if ($this->aotInternalized) {
                    $this->runHeavyModuleOptimizationPasses(2);
                }

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
        $this->outlineDelrefSlowPath();
        $this->prepareInliningForHeavyPipeline();
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

    /**
     * Inliner hygiene before the PassManagerBuilder pipeline.
     *
     * Runtime helpers are stamped `alwaysinline` wholesale — harmless while no inliner ran,
     * but under O2 it force-expands multi-hundred-instruction hashtable/string helpers into
     * every caller: `__init__` grew from ~1.2k to ~280k IR lines on nbody and compile time
     * followed. Keep the attribute only on small accessors (where folding the tag switch is
     * the point) and let the cost model decide the rest. One-shot setup functions get
     * `minsize` so nothing is inlined into them for speed; huge functions get `optsize`.
     */
    private function prepareInliningForHeavyPipeline(): void
    {
        $lib = $this->llvm->lib;
        $maxAlwaysInline = 80;
        $hugeFunction = 4000;
        $alwaysInline = $lib->LLVMGetEnumAttributeKindForName('alwaysinline', 12);
        $minSize = $lib->LLVMGetEnumAttributeKindForName('minsize', 7);
        $optSize = $lib->LLVMGetEnumAttributeKindForName('optsize', 7);
        $fnIndex = PHPLLVM\Attribute::INDEX_FUNCTION;
        $llvmCtx = $this->context->context;
        $function = $lib->LLVMGetFirstFunction($this->module->module);
        while (null !== $function) {
            if ($lib->LLVMCountBasicBlocks($function) > 0) {
                $raw = $lib->LLVMGetValueName($function);
                $name = null === $raw ? '' : $raw->toString();
                if ('__init__' === $name || str_starts_with($name, '__init__') || str_starts_with($name, '__shutdown__')
                    || str_starts_with($name, '__superglobals__')) {
                    $lib->LLVMRemoveEnumAttributeAtIndex($function, $fnIndex, $alwaysInline);
                    $lib->LLVMAddAttributeAtIndex($function, $fnIndex, $lib->LLVMCreateEnumAttribute($llvmCtx, $minSize, 0));
                    $lib->LLVMAddAttributeAtIndex($function, $fnIndex, $lib->LLVMCreateEnumAttribute($llvmCtx, $optSize, 0));
                } else {
                    $count = 0;
                    $bb = $lib->LLVMGetFirstBasicBlock($function);
                    while (null !== $bb && $count <= $hugeFunction) {
                        $inst = $lib->LLVMGetFirstInstruction($bb);
                        while (null !== $inst && $count <= $hugeFunction) {
                            ++$count;
                            $inst = $lib->LLVMGetNextInstruction($inst);
                        }
                        $bb = $lib->LLVMGetNextBasicBlock($bb);
                    }
                    if ($count > $maxAlwaysInline
                        && null !== $lib->LLVMGetEnumAttributeAtIndex($function, $fnIndex, $alwaysInline)) {
                        $lib->LLVMRemoveEnumAttributeAtIndex($function, $fnIndex, $alwaysInline);
                    }
                    // Lowering emits whole coercion/error ladders inline, so some functions are
                    // tens of thousands of instructions of mostly cold code; inlining and
                    // unrolling into those grew them ~5x and dominated compile time.
                    if ($count > $hugeFunction) {
                        $lib->LLVMAddAttributeAtIndex($function, $fnIndex, $lib->LLVMCreateEnumAttribute($llvmCtx, $optSize, 0));
                    }
                }
            }
            $function = $lib->LLVMGetNextFunction($function);
        }
    }

    /**
     * Split `__ref__delref` into an inlinable fast path and an out-of-line slow path.
     *
     * The generated delref carries the whole destroy sequence (destructor, weakref clear, GC
     * unregister, hashtable/object dtor, free) and the GC possible-root buffering. Inlined at
     * every decrement under O2 that was ~100 instructions per site. Like Zend's
     * zval_ptr_dtor (GC_DELREF + rc==0 check inline, rc_dtor_func out of line), the fast
     * path only handles "still referenced and not a GC root candidate"; everything else
     * calls the original body, which re-reads the refcount and does the full work.
     */
    private function outlineDelrefSlowPath(): void
    {
        $assert = Config::getenv('PHP_COMPILER_RUNTIME_ASSERT') ?: Config::getenv('PHPC_RUNTIME_ASSERT');
        if ('1' === $assert || 'true' === strtolower((string) $assert)) {
            return;
        }
        $slow = $this->module->getNamedFunction('__ref__delref');
        if (!$slow instanceof PHPLLVM\Value\Function_ || 0 === $slow->countBasicBlocks()) {
            return;
        }
        $lib = $this->llvm->lib;
        $lib->LLVMSetValueName2($slow->value, '__ref__delref_slow', \strlen('__ref__delref_slow'));
        $fnIndex = PHPLLVM\Attribute::INDEX_FUNCTION;
        $llvmCtx = $this->context->context;
        $lib->LLVMRemoveEnumAttributeAtIndex(
            $slow->value,
            $fnIndex,
            $lib->LLVMGetEnumAttributeKindForName('alwaysinline', 12)
        );
        foreach (['noinline' => 8, 'cold' => 4] as $attr => $len) {
            $lib->LLVMAddAttributeAtIndex($slow->value, $fnIndex, $lib->LLVMCreateEnumAttribute(
                $llvmCtx,
                $lib->LLVMGetEnumAttributeKindForName($attr, $len),
                0
            ));
        }
        $refPtrTy = $this->getTypeFromString('__ref__virtual*');
        $fast = $this->module->addFunction(
            '__ref__delref',
            $this->context->functionType($this->getTypeFromString('void'), false, $refPtrTy)
        );
        $lib->LLVMSetLinkage($fast->value, $lib->LLVMGetLinkage($slow->value));
        $lib->LLVMReplaceAllUsesWith($slow->value, $fast->value);

        // Runs after BasicBlockHelper::sealFunction: do not restore the prior insert block
        // (restoreInsertBlock appends a continuation block nothing would terminate).
        $b = $this->builder;
        $i32 = $this->getTypeFromString('int32');
        $entry = $fast->appendBasicBlock('entry');
        $check = $fast->appendBasicBlock('counted_check');
        $dec = $fast->appendBasicBlock('dec');
        $store = $fast->appendBasicBlock('fast');
        $callSlow = $fast->appendBasicBlock('slow');
        $done = $fast->appendBasicBlock('done');
        $b->positionAtEnd($entry);
        $p = $fast->getParam(0);
        $b->branchIf($b->icmp(PHPLLVM\Builder::INT_EQ, $p, $refPtrTy->constNull()), $done, $check);
        $b->positionAtEnd($check);
        $ref = $b->structGep($p, 0);
        $rcPtr = $b->structGep($ref, 0);
        $rc = $b->load($rcPtr);
        $typeinfo = $b->load($b->structGep($ref, 1));
        $counted = $b->icmp(
            PHPLLVM\Builder::INT_NE,
            $b->bitwiseAnd($typeinfo, $i32->constInt(Builtin\Refcount::TYPE_INFO_REFCOUNTED, false)),
            $i32->constInt(0, false)
        );
        $b->branchIf($counted, $dec, $done);
        $b->positionAtEnd($dec);
        $newRc = $b->sub($rc, $i32->constInt(1, false));
        $gcContainer = $b->icmp(
            PHPLLVM\Builder::INT_NE,
            $b->bitwiseAnd($typeinfo, $i32->constInt(Builtin\Refcount::TYPE_INFO_TYPE_OBJECT, false)),
            $i32->constInt(0, false)
        );
        $needsSlow = $b->bitwiseOr(
            $b->icmp(PHPLLVM\Builder::INT_SLE, $newRc, $i32->constInt(0, false)),
            $b->bitwiseAnd($gcContainer, $b->icmp(PHPLLVM\Builder::INT_EQ, $newRc, $i32->constInt(1, false)))
        );
        $b->branchIf($needsSlow, $callSlow, $store);
        $b->positionAtEnd($store);
        $b->store($newRc, $rcPtr);
        $b->branch($done);
        $b->positionAtEnd($callSlow);
        $b->call($slow, $p);
        $b->branch($done);
        $b->positionAtEnd($done);
        $b->returnVoid();
        $b->clearInsertionPosition();
        $lib->LLVMAddAttributeAtIndex($fast->value, $fnIndex, $lib->LLVMCreateEnumAttribute(
            $llvmCtx,
            $lib->LLVMGetEnumAttributeKindForName('alwaysinline', 12),
            0
        ));
    }
}
