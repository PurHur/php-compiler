<?php

declare(strict_types=1);

namespace PHPCompiler\AOT;

use PHPCompiler\JIT\Context;

/**
 * Split-compilation cache for php-in-PHP JIT helpers (#15889).
 *
 * `phpc build` re-lowers the same *JitHelper PHP sources into every user
 * module (~half of a hello-world build). script/emit-helper-runtime-object.php
 * compiles each helper unit once, in isolation, into its own translation unit:
 *
 *   build/helper-runtime-cache/<fingerprint>/
 *     <unit>.bc      — bitcode; per-script builds read exact function types
 *     <unit>.o       — object the Linker merges at the end
 *     manifest.json  — logical callee → {symbol, unit}
 *
 * Per-script builds synthesize extern declarations (types shared via the
 * LLVMContext) instead of re-lowering helper bodies; the Linker appends the
 * used unit objects with -z muldefs — the script object is listed first, so
 * its ABI definitions win and runtime state stays single-copy.
 *
 * Opt-in: PHP_COMPILER_HELPER_RUNTIME_O=1.
 */
final class HelperRuntimeCache
{
    private const ENV_FLAG = 'PHP_COMPILER_HELPER_RUNTIME_O';

    private const ENV_DIR = 'PHP_COMPILER_HELPER_RUNTIME_CACHE_DIR';

    /** Guard so the emitter itself never consumes the cache. */
    private const ENV_EMITTING = 'PHP_COMPILER_HELPER_RUNTIME_EMITTING';

    /** @var array<string, array{symbol: string, unit: string}>|null */
    private static ?array $manifestHelpers = null;

    private static ?string $manifestFingerprint = null;

    /** @var array<string, object> unit slug → parsed bitcode module (kept alive: types are shared) */
    private static array $parsedUnits = [];

    /** @var array<string, true> unit slug → merged at link time */
    private static array $usedUnits = [];

    public static function enabled(): bool
    {
        if ('1' === getenv(self::ENV_EMITTING)) {
            return false;
        }
        $flag = getenv(self::ENV_FLAG);

        return '1' === $flag || 'true' === strtolower((string) $flag);
    }

    public static function cacheDir(): string
    {
        $dir = getenv(self::ENV_DIR);
        if (is_string($dir) && '' !== $dir) {
            return rtrim($dir, '/');
        }

        return \dirname(__DIR__, 2).'/build/helper-runtime-cache';
    }

    public static function entryDir(): string
    {
        return self::cacheDir().'/'.self::fingerprint();
    }

    /**
     * Fingerprint of everything that shapes helper lowering: LLVM toolchain,
     * dependency lock, the JIT core, and the helper source trees
     * (path+mtime+size — content hashing ~1k files per build would defeat the
     * point).
     */
    public static function fingerprint(): string
    {
        static $fingerprint = null;
        if (null !== $fingerprint) {
            return $fingerprint;
        }
        $root = \dirname(__DIR__, 2);
        $parts = [
            (string) getenv('PHP_COMPILER_LLVM_PATH'),
            @filemtime($root.'/composer.lock').':'.@filesize($root.'/composer.lock'),
        ];
        foreach ([$root.'/lib/JIT.php', $root.'/lib/JIT/Context.php', $root.'/lib/Runtime.php'] as $file) {
            $parts[] = $file.':'.@filemtime($file).':'.@filesize($file);
        }
        foreach ([$root.'/lib/VM', $root.'/lib/JIT', $root.'/ext'] as $dir) {
            $parts[] = self::treeStamp($dir);
        }

        return $fingerprint = substr(hash('sha256', implode("\n", $parts)), 0, 24);
    }

    private static function treeStamp(string $dir): string
    {
        if (!is_dir($dir)) {
            return $dir.':absent';
        }
        $stamp = hash_init('sha256');
        $it = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS)
        );
        foreach ($it as $file) {
            /** @var \SplFileInfo $file */
            if ('php' !== $file->getExtension()) {
                continue;
            }
            hash_update($stamp, $file->getPathname().':'.$file->getMTime().':'.$file->getSize()."\n");
        }

        return hash_final($stamp);
    }

    public static function isFresh(): bool
    {
        return null !== self::loadManifest() && self::$manifestFingerprint === self::fingerprint();
    }

    /** @return array<string, array{symbol: string, unit: string}>|null */
    private static function loadManifest(): ?array
    {
        if (null !== self::$manifestHelpers) {
            return self::$manifestHelpers;
        }
        $path = self::entryDir().'/manifest.json';
        if (!is_readable($path)) {
            return null;
        }
        $decoded = json_decode((string) file_get_contents($path), true);
        if (!\is_array($decoded) || !isset($decoded['fingerprint'], $decoded['helpers']) || !\is_array($decoded['helpers'])) {
            return null;
        }
        self::$manifestFingerprint = (string) $decoded['fingerprint'];

        return self::$manifestHelpers = $decoded['helpers'];
    }

    /**
     * Bind every cached helper among $logicalNames into $context->functions as
     * an extern declaration with the exact type from the unit's bitcode.
     * Returns true when at least one binding happened; callers re-check for
     * missing names and fall back to nested lowering.
     *
     * @param list<string> $logicalNames
     */
    public static function tryProvide(Context $context, array $logicalNames): bool
    {
        if (!self::enabled() || !self::isFresh()) {
            return false;
        }
        $manifest = (array) self::loadManifest();
        $lib = $context->llvm->lib;
        $bound = 0;
        foreach ($logicalNames as $logical) {
            $lc = strtolower($logical);
            if (isset($context->functions[$lc]) || !isset($manifest[$lc])) {
                continue;
            }
            $symbol = $manifest[$lc]['symbol'];
            $slug = $manifest[$lc]['unit'];

            $existing = $context->module->getNamedFunction($symbol);
            if (null !== $existing) {
                $context->functions[$lc] = $existing;
                self::$usedUnits[$slug] = true;
                ++$bound;

                continue;
            }

            $parsed = self::parsedUnit($context, $slug);
            if (null === $parsed) {
                continue;
            }
            $source = $parsed->getNamedFunction($symbol);
            if (null === $source) {
                continue;
            }
            $fnType = $lib->LLVMGetElementType($lib->LLVMTypeOf($source->value));
            if (null === $fnType) {
                continue;
            }
            $type = $context->llvm->factory->type($context->context, $fnType);
            $context->functions[$lc] = $context->module->addFunction($symbol, $type);
            self::$usedUnits[$slug] = true;
            ++$bound;
        }

        return $bound > 0;
    }

    private static function parsedUnit(Context $context, string $slug): ?object
    {
        if (isset(self::$parsedUnits[$slug])) {
            return self::$parsedUnits[$slug];
        }
        $path = self::entryDir().'/'.$slug.'.bc';
        if (!is_file($path) || !is_file(self::entryDir().'/'.$slug.'.o')) {
            return null;
        }
        $data = (string) file_get_contents($path);
        if ('' === $data) {
            return null;
        }
        // createMemoryBufferWithString instead of ...WithFile: the vendored
        // ...WithFile references an unimported FFI class (latent php-llvm bug).
        $buffer = $context->llvm->createMemoryBufferWithString($data, $slug.'.bc');

        try {
            // Kept referenced for the process lifetime — declaration types
            // point into the shared LLVMContext.
            return self::$parsedUnits[$slug] = $buffer->parseBitcode($context->context);
        } catch (\Throwable $e) {
            // Unreadable unit — nested lowering fallback for its helpers.
            return null;
        }
    }

    /**
     * Linker hook: unit objects whose helpers were bound in this build.
     *
     * @return list<string>
     */
    public static function linkObjects(): array
    {
        if (!self::enabled() || [] === self::$usedUnits) {
            return [];
        }
        $objects = [];
        foreach (array_keys(self::$usedUnits) as $slug) {
            $object = self::entryDir().'/'.$slug.'.o';
            if (is_file($object)) {
                $objects[] = $object;
            }
        }

        return $objects;
    }

    public static function markEmitting(): void
    {
        putenv(self::ENV_EMITTING.'=1');
    }
}
