<?php

declare(strict_types=1);

namespace PHPCompiler\JIT;

/**
 * AOT {@see Context::compileToFile} orchestration (#36387 / #36199).
 *
 * Standalone {@code main} IR: {@see ContextCompileToFileStandaloneMain}.
 * Object emit / link / TargetMachine: {@see ContextCompileToFileEmitAndLink}.
 * Extracted so path checks + ensureStandaloneBodies stay a thin hub between those TUs
 * (split-TU / one-file-edit / compile-time gate path).
 *
 * Used via {@code use ContextCompileToFile;} on {@see Context}.
 *
 * No new C ABI. php-src analogy: Zend opcache + zend_compile emit a script image
 * then hand off to the executor / linker (Zend/zend_compile.c, Zend/zend_file_cache.c);
 * TargetMachine selection mirrors cross-compile target triples.
 */
trait ContextCompileToFile
{
    public function compileToFile(string $file) {
        Progress::noteFunction('jit_context_compile_to_file_begin');
        // `-o` is a file path, not a directory. When a directory slips through, LLVM/ld
        // errors are confusing and (in some environments) can be misinterpreted as success.
        if (is_dir($file)) {
            throw new \InvalidArgumentException(sprintf(
                'Output path is a directory: %s (expected file path)',
                $file
            ));
        }
        $outDir = dirname($file);
        if ('' !== $outDir && '.' !== $outDir && !is_dir($outDir)) {
            throw new \InvalidArgumentException(sprintf(
                'Output directory does not exist: %s (from -o %s)',
                $outDir,
                $file
            ));
        }
        if ('' !== $outDir && '.' !== $outDir && !is_writable($outDir)) {
            throw new \InvalidArgumentException(sprintf(
                'Output directory is not writable: %s (from -o %s)',
                $outDir,
                $file
            ));
        }

        // Silent-null method lowerings are invisible without this (#579); opt-in via env.
        $this->reportExternalMethodStubs();

        if (Builtin::LOAD_TYPE_STANDALONE === $this->loadType) {
            // Every standalone main emits __phpc_cli_store_argv — link before that call.
            // Was ensureFull-only for non-thin (#35133); thin already linked here (#34822).
            Builtin\CliArgvRuntime::ensureStandaloneBodies($this);
            // Every standalone main calls __superglobals__refresh — link before that call.
            // Was ensureFull-only for non-thin (#35137); thin already emitRefresh here.
            if ($this->isThinStandaloneAotMain()) {
                // IniRuntime always-on removed (#34848): JitIni / IniGet / IniSet / ErrorReporting /
                // ZendDoubleStringRuntime / ExceptionThrowToStringSeed already ensureLinked before
                // lookup (peer #34578 / #34822). Thin hello-world must not NestedJIT ini ABI during
                // compileToFile pre-main — leftover Context NestedJIT vs Runtime ABI drift mints
                // ini_get.1 / phpc_ini_*.1 (#31894 / #32122).
                Builtin\SuperglobalRefreshRuntime::ensureUserScriptRefreshEmit($this);
            } else {
                Builtin\SuperglobalRefreshRuntime::ensureStandaloneBodies($this);
            }
        }

        $this->emitStandaloneMainFunction();
        $this->emitAndLinkCompiledModule($file);
    }

}
