<?php

declare(strict_types=1);

namespace PHPCompiler\JIT;

/**
 * AOT stamp + optional module.bc persist for CompileCache (#36387 / #36199).
 *
 * Extracted from {@see CompileCacheBitcodePersist} so {@see saveAotStamp()} stays a
 * separate TU from MCJIT {@see CompileCacheBitcodePersist::save()} (split-TU /
 * size-budget ratchet). Warm tryRestore remains in {@see CompileCacheBitcodeRestore}.
 *
 * No new C ABI. php-src analogy: Zend opcache writing a compiled script image
 * for later restore (Zend/zend_file_cache.c) — persist meta + aot stamp (+ bitcode)
 * so the next AOT process can skip LLVM IR lowering when inputs are unchanged.
 */
trait CompileCacheBitcodeAotStamp
{
    /**
     * AOT cache entry: meta + {@see stampPath()} + optional round-trippable module.bc (#36387).
     *
     * void* previously made LLVMParseBitcode fail with Invalid type; opaque pointers now
     * lower as i8* so bitcode is durable. Warm rebuilds still prefer aot.bin / aot.o.
     */
    public static function saveAotStamp(string $key, ?Context $context = null): void
    {
        if (null === self::$recordingExports) {
            return;
        }
        $dir = self::entryDir($key);
        if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
            return;
        }

        $lockPath = $dir.'/.lock';
        $lock = @fopen($lockPath, 'c+');
        if (false === $lock) {
            return;
        }
        if (!flock($lock, LOCK_EX)) {
            fclose($lock);

            return;
        }

        try {
            // Capture user main wrapper if present (added in compileToFile before this runs).
            if (null !== $context) {
                $main = $context->module->getNamedFunction('main');
                if ($main instanceof \PHPLLVM\Value\Function_) {
                    self::recordUserLlvmSymbol('main');
                }
            }
            $userSymbols = array_values(array_unique(self::$recordingUserSymbols ?? []));
            $helperSymbols = self::$recordingHelperSymbols ?? [];
            $functionLlvmSymbols = [];
            if (null !== $context && is_array($context->functionLlvmSymbols)) {
                foreach ($context->functionLlvmSymbols as $logical => $llvm) {
                    if (is_string($logical) && is_string($llvm) && '' !== $logical && '' !== $llvm) {
                        $functionLlvmSymbols[strtolower($logical)] = $llvm;
                    }
                }
            }
            $payload = json_encode([
                'version' => CompileCacheKeyLayout::META_VERSION,
                'fingerprint' => CompileCacheKeyLayout::fingerprint(),
                'exports' => self::$recordingExports,
                'user_symbols' => $userSymbols,
                'user_symbols_by_member' => self::$recordingUserSymbolsByMember ?? [],
                'user_symbols_by_function' => self::$recordingUserSymbolsByFunction ?? [],
                'helper_symbols' => $helperSymbols,
                // Full builtin/user logical→LLVM map so edit-scaffold can rebuild
                // Context::$functions without re-implement() (#36387).
                'function_llvm_symbols' => $functionLlvmSymbols,
                'aot_stamp' => true,
            ], JSON_PRETTY_PRINT);
            if (false !== $payload) {
                file_put_contents(self::metaPath($key), $payload);
            }
            file_put_contents(self::stampPath($key), "aot\n");
            if (null !== $context) {
                $context->module->writeBitcodeToFile(self::bitcodePath($key));
            }
            $members = self::projectMembers();
            if ([] !== $members) {
                self::rememberProject(
                    self::projectId($members),
                    $key,
                    self::memberHashes($members)
                );
            }
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }
}
