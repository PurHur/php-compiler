<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

/**
 * getcwd for VM — /proc/self/cwd on Linux, else {@see VmGetcwdPure}; no libc FFI (#8955, #12154).
 *
 * php-src: ext/standard/dir.c — getcwd(2) / realpath fallback.
 * JIT/AOT: {@see JitChdir} via realpath(3) on ".".
 */
final class VmGetcwdNative
{
    public static function available(): bool
    {
        return VmGetcwdPure::available() || 'Linux' === \PHP_OS_FAMILY;
    }

    /**
     * @return string|false
     */
    public static function resolve()
    {
        if ('Linux' === \PHP_OS_FAMILY) {
            $viaProc = self::resolveLinuxProcCwd();
            if (false !== $viaProc) {
                return self::validateCwd($viaProc);
            }
        }

        return VmGetcwdPure::resolve();
    }

    /**
     * php-src getcwd(2) failure / Linux proc "(deleted)" cwd must be false (#10451).
     *
     * @return string|false
     */
    private static function validateCwd(string $path)
    {
        if ('' === $path || str_ends_with($path, ' (deleted)')) {
            return false;
        }
        if (!is_dir($path)) {
            return false;
        }

        return $path;
    }

    /**
     * Linux bootstrap without host getcwd: /proc/self/cwd symlink (issue #7287 pattern).
     *
     * @return string|false
     */
    private static function resolveLinuxProcCwd()
    {
        if (!\is_link('/proc/self/cwd') && !\is_readable('/proc/self/cwd')) {
            return false;
        }

        $target = @\readlink('/proc/self/cwd');
        if (false === $target || '' === $target) {
            return false;
        }

        return $target;
    }
}
