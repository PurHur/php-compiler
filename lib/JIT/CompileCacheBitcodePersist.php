<?php

declare(strict_types=1);

namespace PHPCompiler\JIT;

/**
 * MCJIT bitcode meta/stamp persist for CompileCache (#36387 / #36199).
 *
 * Extracted from {@see CompileCache} so {@see save()} stays a separate TU
 * (split-TU / size-budget ratchet) while AOT stamp persist lives in
 * {@see CompileCacheBitcodeAotStamp} and warm tryRestore lives in
 * {@see CompileCacheBitcodeRestore}. Distinct from
 * {@see CompileCacheArtifactPersist} (aot.bin / aot.o mid-tier) and from the
 * recording symbol-map cluster (beginRecording / record*).
 *
 * No new C ABI. php-src analogy: Zend opcache writing a compiled script image
 * for later restore (Zend/zend_file_cache.c) — persist bitcode + export meta
 * so the next process can skip LLVM IR lowering.
 */
trait CompileCacheBitcodePersist
{
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
}
