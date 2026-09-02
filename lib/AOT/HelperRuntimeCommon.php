<?php

declare(strict_types=1);

namespace PHPCompiler\AOT;

/**
 * Shared runtime prologue object for split-compilation helper units (#36198 part B / #36246).
 *
 * Helper TUs duplicate hundreds of runtime symbols (__value__*, __string__*, …). Emit
 * them once as prelinked/helper-runtime/<arch>/common.o and list it before unit objects
 * at link time; with -z muldefs the first definition wins and --gc-sections drops
 * duplicate per-function sections once units are re-emitted with AotGcSections.
 */
final class HelperRuntimeCommon
{
    /** Opt-in until units are re-emitted with AotGcSections: PHP_COMPILER_HELPER_RUNTIME_COMMON=1 */
    public const ENV = 'PHP_COMPILER_HELPER_RUNTIME_COMMON';

    /**
     * True when $name is a shared runtime symbol (not a PHPCompiler helper export).
     *
     * Used by tooling that compares unit.o vs common.o overlap (#36246).
     */
    public static function isSharedRuntimeSymbol(string $name): bool
    {
        if ('' === $name || str_starts_with($name, 'PHPCompiler_')) {
            return false;
        }

        return str_starts_with($name, '__value__')
            || str_starts_with($name, '__string__')
            || str_starts_with($name, '__hashtable__')
            || str_starts_with($name, '__ref__')
            || str_starts_with($name, '__object__')
            || str_starts_with($name, 'phpc_')
            || str_starts_with($name, '__compiler_')
            || str_starts_with($name, '__phpc_')
            || str_starts_with($name, '__superglobals__')
            || in_array($name, ['malloc', 'realloc', 'free', 'calloc', 'exit', 'abort'], true);
    }

    public static function prelinkedArchDir(): string
    {
        return \dirname(HelperRuntimeCache::prelinkedUnitsDir());
    }

    public static function commonObjectPath(): string
    {
        return self::prelinkedArchDir().'/common.o';
    }

    public static function commonBitcodePath(): string
    {
        return self::prelinkedArchDir().'/common.bc';
    }

    public static function archManifestPath(): string
    {
        return self::prelinkedArchDir().'/manifest.json';
    }

    public static function isLinkEnabled(): bool
    {
        $env = getenv(self::ENV);
        if ('1' !== $env && 'true' !== strtolower((string) $env)) {
            return false;
        }

        return self::commonObjectIsLinkable();
    }

    /**
     * @return array<string, mixed>|null
     */
    public static function readArchManifest(): ?array
    {
        $path = self::archManifestPath();
        if (!is_file($path)) {
            return null;
        }
        $decoded = json_decode((string) file_get_contents($path), true);

        return \is_array($decoded) ? $decoded : null;
    }

    public static function commonObjectIsLinkable(): bool
    {
        $path = self::commonObjectPath();
        if (!is_file($path) || filesize($path) <= 0) {
            return false;
        }
        $manifest = self::readArchManifest();
        if (null === $manifest) {
            return false;
        }
        $committedCore = (string) ($manifest['core_fingerprint'] ?? '');
        if ('' !== $committedCore && !HelperRuntimeCache::coreFingerprintMatches($committedCore)) {
            return false;
        }
        $expectedSha = (string) ($manifest['common_object_sha256'] ?? '');
        if ('' === $expectedSha) {
            return false;
        }
        $liveSha = hash_file('sha256', $path);

        return hash_equals($expectedSha, $liveSha ?: '');
    }

    /**
     * Linker hook: shared runtime object listed before helper unit objects.
     */
    public static function linkObject(): ?string
    {
        if (!self::isLinkEnabled()) {
            return null;
        }

        return self::commonObjectPath();
    }

    /**
     * Record common.o metadata into the per-arch manifest after emit.
     */
    public static function publishManifestMetadata(string $objectPath, string $bitcodePath): void
    {
        $manifest = self::readArchManifest() ?? [
            'version' => 1,
            'arch' => HelperRuntimeCache::archKey(),
            'role' => 'committed per-arch split-compilation helper units (#15889)',
        ];
        // Preserve arch-level fields written by --prelink (unit_count, total_bytes, …).
        // Only refresh common.o metadata; do not rewrite core_fingerprint here (#36246).
        $manifest['common_object_sha256'] = hash_file('sha256', $objectPath) ?: '';
        $manifest['common_object_bytes'] = is_file($objectPath) ? filesize($objectPath) : 0;
        $manifest['common_bitcode_bytes'] = is_file($bitcodePath) ? filesize($bitcodePath) : 0;
        $manifest['common_emitted_at'] = gmdate('c');
        $dir = self::prelinkedArchDir();
        if (!is_dir($dir) && !mkdir($dir, 0755, true) && !is_dir($dir)) {
            throw new \RuntimeException('cannot create helper-runtime arch dir: '.$dir);
        }
        file_put_contents(
            self::archManifestPath(),
            json_encode($manifest, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT)."\n"
        );
    }
}
