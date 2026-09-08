<?php

declare(strict_types=1);

namespace PHPCompiler\JIT;

/**
 * Linked-binary mid-tier warm restore for AOT CompileCache (#36387 / #36199).
 *
 * Extracted from {@see CompileCache} so artifact (`aot.bin`) persistence stays a
 * separate TU (split-TU / compile-cache iterability) while the hub keeps thin
 * public delegates used by bin/compile.php and unit tests. User-object + helper
 * link-manifest restore lives in {@see CompileCacheObjectLinkPersist}.
 *
 * Warm tiers (fastest first):
 * 1. {@see tryRestoreArtifact()} / {@see tryRestoreArtifactByKey()} — copy cached `aot.bin`
 * 2. {@see CompileCacheObjectLinkPersist::tryRestoreObjectAndLink()} — link cached `aot.o`
 * 3. Full re-lower when neither tier is fingerprint-fresh
 *
 * No new C ABI. php-src analogy: Zend opcache file-cache restore of a compiled script
 * image without re-parsing (Zend/zend_file_cache.c shape) — attach a prebuilt artifact
 * and skip the emit pipeline.
 */
final class CompileCacheArtifactPersist
{
    /**
     * True when a linked binary was saved for this key and inputs are still fresh (#36387).
     */
    public static function hasFreshArtifact(string $key, string $sourcePath, string $sourceCode): bool
    {
        if (!CompileCache::isFresh($key, $sourcePath, $sourceCode)) {
            return false;
        }
        $path = CompileCache::artifactPath($key);

        return is_file($path) && filesize($path) > 0;
    }

    /**
     * Copy a cached linked binary to {@see $outfile}. Returns true on success (#36387).
     */
    public static function tryRestoreArtifact(
        string $key,
        string $outfile,
        string $sourcePath,
        string $sourceCode
    ): bool {
        if (!self::hasFreshArtifact($key, $sourcePath, $sourceCode)) {
            return false;
        }

        return self::copyArtifactTo($key, $outfile);
    }

    /**
     * Warm restore by known cache key (project index hit — skip SourceBundler) (#36387).
     */
    public static function tryRestoreArtifactByKey(string $key, string $outfile): bool
    {
        if (!CompileCache::isEnabled() || '' === $key) {
            return false;
        }
        if (null === CompileCache::readMeta($key)) {
            return false;
        }
        $path = CompileCache::artifactPath($key);
        if (!is_file($path) || filesize($path) < 1) {
            return false;
        }

        return self::copyArtifactTo($key, $outfile);
    }

    private static function copyArtifactTo(string $key, string $outfile): bool
    {
        $src = CompileCache::artifactPath($key);
        $outDir = dirname($outfile);
        if ('' !== $outDir && '.' !== $outDir && !is_dir($outDir)) {
            if (!@mkdir($outDir, 0775, true) && !is_dir($outDir)) {
                return false;
            }
        }
        $tmp = $outfile.'.tmp.'.getmypid();
        if (!@copy($src, $tmp)) {
            @unlink($tmp);

            return false;
        }
        @chmod($tmp, 0755);
        if (!@rename($tmp, $outfile)) {
            if (!@copy($tmp, $outfile)) {
                @unlink($tmp);

                return false;
            }
            @unlink($tmp);
            @chmod($outfile, 0755);
        }

        return is_file($outfile) && filesize($outfile) > 0;
    }

    /**
     * Persist the linked executable beside bitcode/meta for the next warm build (#36387).
     */
    public static function saveArtifact(string $key, string $outfile): void
    {
        if ('' === $key || !is_file($outfile) || filesize($outfile) < 1) {
            return;
        }
        $dir = CompileCache::entryDir($key);
        if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
            return;
        }
        if (!CompileCache::hasDurableMarker($key) || null === CompileCache::readMeta($key)) {
            return;
        }
        $dest = CompileCache::artifactPath($key);
        $tmp = $dest.'.tmp.'.getmypid();
        if (!@copy($outfile, $tmp)) {
            @unlink($tmp);

            return;
        }
        @chmod($tmp, 0755);
        if (!@rename($tmp, $dest)) {
            if (!@copy($tmp, $dest)) {
                @unlink($tmp);

                return;
            }
            @unlink($tmp);
            @chmod($dest, 0755);
        }
    }
}
