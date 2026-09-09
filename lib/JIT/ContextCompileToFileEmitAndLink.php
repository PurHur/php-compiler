<?php

declare(strict_types=1);

namespace PHPCompiler\JIT;

use PHPCompiler\AOT\Linker;
use PHPCompiler\AOT\CompileTarget;
use PHPCompiler\Config;
use PHPLLVM;

/**
 * AOT object emit + link + TargetMachine for {@see Context::compileToFile} (#36387).
 *
 * Extracted from {@see ContextCompileToFile} so compileCommon / IR opt / emitToFile /
 * ld link / CompileCache object persist stay a separate TU from standalone `main`
 * scaffolding (split-TU / size-budget / one-file-edit gate path, #36199).
 *
 * Used via {@code use ContextCompileToFileEmitAndLink;} on {@see Context}.
 * Standalone main emission: {@see ContextCompileToFile}.
 *
 * No new C ABI. php-src analogy: Zend opcache emits a script image then hands off
 * to the linker / executor (Zend/zend_compile.c, Zend/zend_file_cache.c);
 * TargetMachine selection mirrors cross-compile target triples (#36391).
 */
trait ContextCompileToFileEmitAndLink
{
    /**
     * After standalone `main` is wired: compileCommon, opt, object emit, link (#36387).
     */
    private function emitAndLinkCompiledModule(string $file): void
    {
        Progress::noteFunction('jit_context_compile_common_begin');
        \PHPCompiler\AOT\BuildTiming::mark('compile_common');
        $this->compileCommon();
        \PHPCompiler\AOT\BuildTiming::end('compile_common');
        Progress::noteFunction('jit_context_compile_common_done');

        \PHPCompiler\AOT\BuildTiming::mark('ir_opt');
        $this->runModuleOptimizationPasses();
        \PHPCompiler\AOT\BuildTiming::end('ir_opt');

        // AOT CompileCache: stamp + meta + round-trippable module.bc (void*→i8*, #36387).
        // Warm paths still prefer aot.bin / aot.o; bitcode enables tryRestore fallback.
        // Persist FULL module before partial demote so the next edit can thin-boot (#36387).
        if (null !== $this->aotCompileCacheKey && '' !== $this->aotCompileCacheKey) {
            CompileCache::saveAotStamp($this->aotCompileCacheKey, $this);
        }

        // Partial keep: demote bodies already in prior aot.o → tiny delta emit (#36387).
        if (CompileCache::isEditScaffoldPartial() && null !== CompileCache::peekPartialEmitBaseObject()) {
            \PHPCompiler\AOT\BuildTiming::mark('partial_demote');
            CompileCache::demoteBodiesForPartialObjectEmit($this);
            \PHPCompiler\AOT\BuildTiming::end('partial_demote');
        }

        $bitcodePath = Config::getenv('PHP_COMPILER_EMIT_BITCODE');
        if (is_string($bitcodePath) && '' !== $bitcodePath) {
            $bcDir = dirname($bitcodePath);
            if (!is_dir($bcDir) && !mkdir($bcDir, 0775, true) && !is_dir($bcDir)) {
                throw new \RuntimeException('cannot create directory for chunk bitcode: '.$bcDir);
            }
            $this->module->writeBitcodeToFile($bitcodePath);
        }
        $this->exportChunkMethodManifestIfRequested();

        Progress::noteFunction('jit_context_create_target_machine');
        \PHPCompiler\AOT\BuildTiming::mark('target_machine');
        // Prefer a standalone TargetMachine over createExecutionEngine(): EE takes module
        // ownership and pays MCJIT setup we do not need for AOT object emit (#36387).
        // Default OptLevel None: cold MiniWebApp emitToFile drops ~10s → ~1.7s; IR shape is
        // already unoptimised so Default codegen buys wall time without observable wins.
        $machine = $this->createAotTargetMachine();
        \PHPCompiler\AOT\BuildTiming::end('target_machine');
        if (!is_null($this->debugFile)) {
            $machine->emitToFile($this->module, $this->debugFile . '.s', $machine::CODEGEN_FILE_TYPE_ASM);
        }
        $keepObject = Config::getenv('PHP_COMPILER_KEEP_OBJECT_FILE');
        $vendorPrelink = Config::getenv('PHP_COMPILER_VENDOR_PRELINK');
        $selfhostAot = Config::getenv('PHP_COMPILER_SELFHOST_AOT');
        $vendorObjectOnly = ('1' === $vendorPrelink || 'true' === strtolower((string) $vendorPrelink))
            && ('0' === $selfhostAot || 'false' === strtolower((string) $selfhostAot));
        $keepingObjectOnly = ('1' === $keepObject || 'true' === strtolower((string) $keepObject))
            || $vendorObjectOnly;
        // M5 vendor argv uses -o path ending in .o; do not append a second .o (#3054).
        $objectFile = $keepingObjectOnly && str_ends_with($file, '.o') ? $file : $file.'.o';
        \PHPCompiler\AOT\BuildTiming::mark('gc_sections');
        AotGcSections::applyFunctionSections($this->llvm, $this->module);
        \PHPCompiler\AOT\BuildTiming::end('gc_sections');
        Progress::noteFunction('jit_context_emit_object_begin');
        \PHPCompiler\AOT\BuildTiming::mark('emit_object');
        $machine->emitToFile($this->module, $objectFile, $machine::CODEGEN_FILE_TYPE_OBJECT);
        \PHPCompiler\AOT\BuildTiming::end('emit_object');
        Progress::noteFunction('jit_context_emit_object_done');
        if ($keepingObjectOnly) {
            Linker::assertNonEmptyOutputFile($objectFile);
            // Object-only / split-TU chunk emit: persist helper unit slugs so a later
            // combine+link can adoptUnitSlugsForLink without re-lowering (#36387).
            $slugsExport = Config::getenv('PHP_COMPILER_HELPER_SLUGS_EXPORT');
            if (is_string($slugsExport) && '' !== $slugsExport) {
                $slugs = \PHPCompiler\AOT\HelperRuntimeCache::usedUnitSlugs();
                // Stable timestamp when SOURCE_DATE_EPOCH / REPRODUCIBLE is set (#36399).
                $epoch = CompileTarget::sourceDateEpoch();
                $generatedAt = null !== $epoch
                    ? gmdate('c', (int) $epoch)
                    : gmdate('c');
                $payload = json_encode(
                    [
                        'version' => 1,
                        'helper_slugs' => array_values($slugs),
                        'object' => $objectFile,
                        'generated_at' => $generatedAt,
                    ],
                    JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES
                );
                if (false !== $payload) {
                    $dir = dirname($slugsExport);
                    if ('.' !== $dir && '' !== $dir && !is_dir($dir)) {
                        @mkdir($dir, 0755, true);
                    }
                    file_put_contents($slugsExport, $payload."\n");
                }
            }

            return;
        }
        $partialBase = CompileCache::peekPartialEmitBaseObject();
        Progress::noteFunction('jit_context_link_begin');
        \PHPCompiler\AOT\BuildTiming::mark('ld_link');
        Linker::link($objectFile, $file);
        \PHPCompiler\AOT\BuildTiming::end('ld_link');
        Progress::noteFunction('jit_context_link_done');
        Linker::assertNonEmptyOutputFile($file);
        if (null !== $this->aotCompileCacheKey && '' !== $this->aotCompileCacheKey) {
            $objectToCache = $objectFile;
            $combinedTmp = null;
            // Materialize a full aot.o for the new key (delta + prior base) so mid-tier
            // restore stays valid after a partial edit (#36387).
            if (is_string($partialBase) && is_file($partialBase) && filesize($partialBase) > 0) {
                $combinedTmp = $objectFile.'.full.'.getmypid();
                \PHPCompiler\AOT\BuildTiming::mark('object_combine');
                $combined = Linker::combineRelocatableObjects([$objectFile, $partialBase], $combinedTmp);
                \PHPCompiler\AOT\BuildTiming::end('object_combine');
                if ($combined) {
                    $objectToCache = $combinedTmp;
                    \PHPCompiler\AOT\BuildTiming::note('edit_scaffold_object_combine', 1.0);
                }
            }
            CompileCache::saveObject(
                $this->aotCompileCacheKey,
                $objectToCache,
                \PHPCompiler\AOT\HelperRuntimeCache::usedUnitSlugs()
            );
            if (null !== $combinedTmp) {
                @unlink($combinedTmp);
            }
        }
        unlink($objectFile);
    }

    /**
     * TargetMachine for AOT object emit without creating an MCJIT ExecutionEngine (#36387 / #36391).
     *
     * Uses {@see CompileTarget} triple/CPU/reloc. Host MCJIT EE fallback is native-only —
     * a missing aarch64 backend must not silently emit x86_64 objects.
     */
    private function createAotTargetMachine(): PHPLLVM\TargetMachine
    {
        $target = CompileTarget::current();
        $target->initializeLlvm($this->llvm);
        $target->applyToModule($this->module);
        try {
            $llvmTarget = $this->llvm->getTargetFromName($target->llvmTargetName());
            // Explicit AOT_CODEGEN_OPT wins; else OPT_LEVEL 0–3; else OptNone (#36399 / #36387).
            $optLevel = CompileTarget::targetMachineOptLevel();

            return $llvmTarget->createTargetMachine(
                $target->llvmTriple(),
                $target->cpu(),
                '',
                $optLevel,
                $target->llvmRelocModeConst(),
                PHPLLVM\Target::CODE_MODEL_DEFAULT
            );
        } catch (\Throwable $e) {
            if (!$target->isHostNative()) {
                throw new \RuntimeException(
                    'AOT TargetMachine for '.$target->id().' failed (refusing host MCJIT fallback) (#36391): '
                    .$e->getMessage(),
                    0,
                    $e
                );
            }
            $engine = $this->module->createExecutionEngine();

            return $engine->getTargetMachine();
        }
    }

}
