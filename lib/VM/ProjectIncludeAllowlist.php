<?php

declare(strict_types=1);

namespace PHPCompiler\VM;

/**
 * Project file-map gate for include/require (#36382).
 *
 * php-src: Zend/zend_execute.c — ZEND_INCLUDE_OR_EVAL resolves the path then opens the file;
 * an AOT project build additionally refuses paths outside the compile-unit map (never silent).
 */
final class ProjectIncludeAllowlist
{
    public const DENY_PREFIX = 'include/require path outside project file map: ';

    public const DENY_SUFFIX = ' (issue #36382)';

    /**
     * @param array<string, true>|null $allow
     */
    public static function isAllowed(string $path, ?array $allow): bool
    {
        if (null === $allow || [] === $allow) {
            return true;
        }

        if (isset($allow[$path])) {
            return true;
        }

        $resolved = realpath($path);
        if (false !== $resolved && isset($allow[$resolved])) {
            return true;
        }

        return false;
    }

    public static function denyMessage(string $path): string
    {
        return self::DENY_PREFIX.$path.self::DENY_SUFFIX;
    }

    /**
     * Unique path keys for LLVM strcmp emit (stable order).
     *
     * Keeps every allowlist entry (realpath and original) so runtime paths that match
     * either form succeed without an LLVM realpath call.
     *
     * @param array<string, true> $allow
     *
     * @return list<string>
     */
    public static function emitKeys(array $allow): array
    {
        $keys = [];
        $seen = [];
        foreach (array_keys($allow) as $path) {
            $path = (string) $path;
            if ('' === $path || isset($seen[$path])) {
                continue;
            }
            $seen[$path] = true;
            $keys[] = $path;
        }
        sort($keys);

        return $keys;
    }
}
