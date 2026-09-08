<?php

declare(strict_types=1);

namespace PHPCompiler\JIT;

use PHPCompiler\Config;
use PHPLLVM;

/**
 * MCJIT compile-in-place and module verify for {@see Context} (#36387).
 *
 * Extracted from {@see Context} so compileInPlace / compileCommon /
 * verifyModuleOrThrow stay a separate TU from IR opt passes
 * ({@see ContextModuleOptimizationPasses}) and the Context construction hub
 * (split-TU / size-budget ratchet toward Context ≤ 4k lines, #36199 / #36403).
 *
 * Used via {@code use ContextModuleCompileAndOptimize;} on {@see Context}.
 * Opt pipeline: {@see ContextModuleOptimizationPasses}.
 *
 * No new C ABI. php-src analogy: zend_compile + executor hand-off live beside
 * the compiler front-end (Zend/zend_compile.c, Zend/zend_execute.c); Optimizer
 * passes live in {@see ContextModuleOptimizationPasses}.
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
