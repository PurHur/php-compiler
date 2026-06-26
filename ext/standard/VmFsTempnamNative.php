<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

/**
 * tempnam() mkstemp for VM — pure PHP via {@see VmFsTempnamPure} (#4401, #8999, #12145).
 *
 * mkstempOpen() is unavailable without libc FFI; {@see VmTmpfilePure} falls back to php://temp.
 */
final class VmFsTempnamNative
{
    public static function mkstemp(string $dir, string $prefix): string|false
    {
        return VmFsTempnamPure::mkstemp($dir, $prefix);
    }

    /**
     * @return array{0: int, 1: string}|false
     */
    public static function mkstempOpen(string $dir, string $prefix = 'php'): array|false
    {
        return false;
    }

    public static function unlinkPath(string $path): bool
    {
        return VmFsUnlink::unlink($path);
    }
}
