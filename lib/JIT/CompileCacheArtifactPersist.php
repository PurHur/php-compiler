<?php

declare(strict_types=1);

namespace PHPCompiler\JIT;

use PHPCompiler\AOT\HelperRuntimeCache;

/**
 * Linked-binary / user-object mid-tier warm restore for AOT CompileCache (#36387 / #36199).
 *
 * Extracted from {@see CompileCache} so artifact + `aot.o` + helper-slug link-manifest
 * persistence stay a separate TU (split-TU / compile-cache iterability) while the hub
 * keeps thin public delegates used by bin/compile.php and unit tests.
 *
 * Warm tiers (fastest first):
 * 1. {@see tryRestoreArtifact()} / {@see tryRestoreArtifactByKey()} — copy cached `aot.bin`
 * 2. {@see tryRestoreObjectAndLink()} — link cached `aot.o` with recorded helper units
 * 3. Full re-lower when neither tier is fingerprint-fresh
 *
 * No new C ABI. php-src analogy: Zend opcache file-cache restore of a compiled script
 * image without re-parsing (Zend/zend_file_cache.c shape) — attach a prebuilt artifact
 * or object and skip the emit pipeline.
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

    /**
     * True when a cached user `.o` + link manifest are fresh for this key (#36387).
     */
    public static function hasFreshObject(string $key, string $sourcePath, string $sourceCode): bool
    {
        if (!CompileCache::isFresh($key, $sourcePath, $sourceCode)) {
            return false;
        }
        $object = CompileCache::objectPath($key);
        if (!is_file($object) || filesize($object) < 1) {
            return false;
        }
        $link = self::readLinkManifest($key);

        return null !== $link;
    }

    /**
     * @return array{version: int, helper_slugs: list<string>}|null
     */
    public static function readLinkManifest(string $key): ?array
    {
        $path = CompileCache::linkManifestPath($key);
        if (!is_file($path)) {
            return null;
        }
        $raw = file_get_contents($path);
        if (false === $raw) {
            return null;
        }
        $decoded = json_decode($raw, true);
        if (!is_array($decoded) || (int) ($decoded['version'] ?? 0) !== 1) {
            return null;
        }
        if (!isset($decoded['helper_slugs']) || !is_array($decoded['helper_slugs'])) {
            return null;
        }
        $slugs = [];
        foreach ($decoded['helper_slugs'] as $slug) {
            if (is_string($slug) && '' !== $slug) {
                $slugs[] = $slug;
            }
        }

        return [
            'version' => 1,
            'helper_slugs' => $slugs,
        ];
    }

    /**
     * Persist the emitted user object + helper unit slugs for mid-tier link restore (#36387).
     *
     * @param list<string> $helperSlugs basenames under helper-runtime units/
     */
    public static function saveObject(string $key, string $objectFile, array $helperSlugs): void
    {
        if ('' === $key || !is_file($objectFile) || filesize($objectFile) < 1) {
            return;
        }
        $dir = CompileCache::entryDir($key);
        if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
            return;
        }
        if (!CompileCache::hasDurableMarker($key) || null === CompileCache::readMeta($key)) {
            return;
        }
        $dest = CompileCache::objectPath($key);
        $tmp = $dest.'.tmp.'.getmypid();
        if (!@copy($objectFile, $tmp)) {
            @unlink($tmp);

            return;
        }
        if (!@rename($tmp, $dest)) {
            if (!@copy($tmp, $dest)) {
                @unlink($tmp);

                return;
            }
            @unlink($tmp);
        }
        $clean = [];
        foreach ($helperSlugs as $slug) {
            if (is_string($slug) && '' !== $slug) {
                $clean[] = $slug;
            }
        }
        $payload = json_encode([
            'version' => 1,
            'helper_slugs' => array_values(array_unique($clean)),
        ], JSON_PRETTY_PRINT);
        if (false !== $payload) {
            file_put_contents(CompileCache::linkManifestPath($key), $payload."\n");
        }
    }

    /**
     * Mid-tier warm path: link a cached user `.o` with recorded helper units (#36387).
     *
     * Skips loadJitContext + LLVM emitToFile. Used when `aot.bin` was removed but the
     * object + bitcode/meta are still fingerprint-fresh.
     */
    public static function tryRestoreObjectAndLink(
        string $key,
        string $outfile,
        string $sourcePath,
        string $sourceCode
    ): bool {
        if (!self::hasFreshObject($key, $sourcePath, $sourceCode)) {
            return false;
        }
        $link = self::readLinkManifest($key);
        if (null === $link) {
            return false;
        }
        $object = CompileCache::objectPath($key);
        $work = $outfile.'.o.restore.'.getmypid();
        if (!@copy($object, $work)) {
            @unlink($work);

            return false;
        }
        HelperRuntimeCache::adoptUnitSlugsForLink($link['helper_slugs']);
        try {
            \PHPCompiler\AOT\Linker::link($work, $outfile);
            \PHPCompiler\AOT\Linker::assertNonEmptyRequestedOutput($outfile);
        } catch (\Throwable $e) {
            @unlink($work);
            @unlink($outfile);

            return false;
        }
        @unlink($work);
        self::saveArtifact($key, $outfile);

        return is_file($outfile) && filesize($outfile) > 0;
    }
}
