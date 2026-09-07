<?php

declare(strict_types=1);

namespace PHPCompiler\JIT;

use PHPCompiler\Block;

/**
 * Full-module bitcode warm restore + meta/stamp persist for CompileCache (#36387 / #36199).
 *
 * Extracted from {@see CompileCache} so `module.bc` / meta.json / fresh.stamp save+restore
 * stay a separate TU (split-TU / size-budget ratchet) while the hub keeps recording and
 * edit-scaffold session state. Trait composition shares hub private statics with
 * {@see CompileCacheEditScaffold} (same pattern).
 *
 * No new C ABI. php-src analogy: Zend opcache file-cache load of a compiled script image
 * (`Zend/zend_file_cache.c` zend_file_cache_script_load) — replace the live module from
 * durable bytes and rebind exports without re-lowering.
 */
trait CompileCacheBitcodePersist
{
    /**
     * @return bool true when bitcode was loaded and exports restored
     */
    public static function tryRestore(Context $context, Block $block, string $key): bool
    {
        $meta = self::readMeta($key);
        if (null === $meta) {
            return false;
        }
        $bcPath = self::bitcodePath($key);
        if (!is_file($bcPath)) {
            return false;
        }

        try {
            $context->replaceModuleFromBitcodeFile($bcPath);
        } catch (\Throwable $e) {
            return false;
        }

        SuperglobalInit::rebindGlobalsFromModule($context);
        self::restoreExports($context, $block, $meta['exports']);
        $context->rebindFunctionScopeFromModule();
        $context->rebindInitShutdownAfterModuleReplace();
        $context->refreshIntrinsicAfterModuleReplace();
        $context->syncIntrinsicBuilder();
        self::$skipModuleFuncCompile = true;
        self::$editScaffoldActive = false;

        return true;
    }

    /**
     * User-script main LLVM function after {@see tryRestore()} (#36199).
     */
    public static function resolveRestoredMainFunction(Context $context, string $key): ?\PHPLLVM\Value\Function_
    {
        $meta = self::readMeta($key);
        if (null === $meta) {
            return null;
        }
        foreach ($meta['exports'] as $entry) {
            if (($entry['scoped'] ?? '') !== '{main}') {
                continue;
            }
            $llvm = (string) ($entry['llvm'] ?? '');
            if ('' === $llvm) {
                continue;
            }
            $func = $context->module->getNamedFunction($llvm);
            if ($func instanceof \PHPLLVM\Value\Function_) {
                return $func;
            }
        }

        return null;
    }

    public static function save(Context $context, string $key): void
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
            $context->module->writeBitcodeToFile(self::bitcodePath($key));
            $payload = json_encode([
                'version' => CompileCacheKeyLayout::META_VERSION,
                'fingerprint' => CompileCacheKeyLayout::fingerprint(),
                'exports' => self::$recordingExports,
            ], JSON_PRETTY_PRINT);
            if (false !== $payload) {
                file_put_contents(self::metaPath($key), $payload);
            }
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

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

    /**
     * @param list<array{llvm?: string, signature?: string, scoped?: string}> $exports
     */
    private static function restoreExports(Context $context, Block $block, array $exports): void
    {
        $blocksByScoped = self::collectBlocksByScopedName($block);
        foreach ($exports as $entry) {
            $llvm = $entry['llvm'] ?? '';
            $signature = $entry['signature'] ?? '';
            $scoped = $entry['scoped'] ?? '';
            if ('' === $llvm || '' === $signature || '' === $scoped) {
                continue;
            }
            if (!isset($blocksByScoped[$scoped])) {
                continue;
            }
            $context->addExport($llvm, $signature, $blocksByScoped[$scoped]);
        }
    }

    /**
     * @return array<string, Block>
     */
    private static function collectBlocksByScopedName(Block $root): array
    {
        $map = [];
        $queue = [$root];
        while ([] !== $queue) {
            $current = array_shift($queue);
            if (null !== $current->func) {
                $map[$current->func->getScopedName()] = $current;
            } else {
                $map['{main}'] = $current;
            }
            foreach ($current->blocks as $child) {
                $queue[] = $child;
            }
        }

        return $map;
    }
}
