<?php

declare(strict_types=1);

namespace PHPCompiler\Web;

/**
 * Minimal phpc.json reader (issue #106 subset for serve --aot and phpc build --project).
 */
final class ProjectManifest
{
    /**
     * Directory containing phpc.json (walks up from $startDir).
     */
    public static function resolveProjectDir(string $startDir): ?string
    {
        $dir = realpath($startDir);
        if (false === $dir) {
            return null;
        }

        for ($i = 0; $i < 8; ++$i) {
            if (is_file($dir.'/phpc.json')) {
                return $dir;
            }
            $parent = dirname($dir);
            if ($parent === $dir) {
                break;
            }
            $dir = $parent;
        }

        return null;
    }

    /**
     * @return array<string, mixed>|null decoded phpc.json from project dir
     */
    public static function loadManifest(string $startDir): ?array
    {
        $projectDir = self::resolveProjectDir($startDir);
        if (null === $projectDir) {
            return null;
        }

        $raw = file_get_contents($projectDir.'/phpc.json');
        if (false === $raw) {
            return null;
        }

        $data = json_decode($raw, true);

        return is_array($data) ? $data : null;
    }

    /**
     * Entry script path for phpc build --project (issue #106).
     */
    public static function resolveEntryPath(string $startDir, ?array $manifest = null): ?string
    {
        $projectDir = self::resolveProjectDir($startDir);
        if (null === $projectDir) {
            return null;
        }

        $manifest ??= self::loadManifest($projectDir);
        if (null === $manifest || !isset($manifest['entry']) || !is_string($manifest['entry']) || '' === $manifest['entry']) {
            return null;
        }

        $path = self::resolveRelativePath($projectDir, $manifest['entry']);

        return is_file($path) ? $path : null;
    }

    /**
     * Output path for AOT binary from manifest "binary" key (file may not exist yet).
     */
    public static function resolveBinaryOutputPath(string $startDir, ?array $manifest = null): ?string
    {
        $projectDir = self::resolveProjectDir($startDir);
        if (null === $projectDir) {
            return null;
        }

        $manifest ??= self::loadManifest($projectDir);
        if (null === $manifest || !isset($manifest['binary']) || !is_string($manifest['binary']) || '' === $manifest['binary']) {
            return null;
        }

        return self::resolveRelativePath($projectDir, $manifest['binary']);
    }

    /**
     * Resolve AOT binary path from phpc.json, explicit flag, or default layout.
     */
    public static function resolveBinaryPath(string $startDir, ?string $explicit = null): ?string
    {
        if (null !== $explicit && '' !== $explicit) {
            $path = self::resolveRelativePath($startDir, $explicit);

            return is_file($path) ? $path : null;
        }

        $dir = realpath($startDir);
        if (false === $dir) {
            return null;
        }

        for ($i = 0; $i < 8; ++$i) {
            $manifest = $dir.'/phpc.json';
            if (is_file($manifest)) {
                $data = json_decode((string) file_get_contents($manifest), true);
                if (is_array($data) && isset($data['binary']) && is_string($data['binary'])) {
                    $candidate = self::resolveRelativePath($dir, $data['binary']);
                    if (is_file($candidate)) {
                        return $candidate;
                    }
                }
            }
            $parent = dirname($dir);
            if ($parent === $dir) {
                break;
            }
            $dir = $parent;
        }

        foreach (['.phpc/bin/app', 'bin/app', 'app'] as $relative) {
            $candidate = self::resolveRelativePath($startDir, $relative);
            if (is_file($candidate) && is_executable($candidate)) {
                return $candidate;
            }
        }

        return null;
    }

    /**
     * HTTP document root for phpc serve when phpc.json is present (issue #443).
     *
     * Uses manifest "public" when set; otherwise keeps the resolved start directory
     * (e.g. examples/001-SimpleWeb without a public/ subtree).
     */
    public static function resolvePublicDir(string $startDir): string
    {
        $dir = realpath($startDir);
        if (false === $dir) {
            return $startDir;
        }

        $projectDir = self::resolveProjectDir($dir);
        if (null === $projectDir) {
            return $dir;
        }

        $manifest = self::loadManifest($projectDir);
        if (null === $manifest) {
            return $dir;
        }

        if (!isset($manifest['public']) || !is_string($manifest['public']) || '' === $manifest['public']) {
            return $dir;
        }

        $publicDir = self::resolveRelativePath($projectDir, $manifest['public']);
        $publicReal = realpath($publicDir);
        if (false !== $publicReal && is_dir($publicReal)) {
            return $publicReal;
        }

        return $publicDir;
    }

    public static function resolveRelativePath(string $baseDir, string $path): string
    {
        if ('/' === $path[0]) {
            return $path;
        }

        $base = realpath($baseDir);
        if (false === $base) {
            $base = $baseDir;
        }

        return $base.'/'.$path;
    }
}
