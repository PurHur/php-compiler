<?php

declare(strict_types=1);

namespace PHPCompiler\Web;

/**
 * Minimal phpc.json reader (issue #106 subset for serve --aot).
 */
final class ProjectManifest
{
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
