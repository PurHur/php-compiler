<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

/**
 * getcwd without libc FFI (#8955, pairs {@see VmGetcwdNative}).
 *
 * Bootstrap path when FFI is disabled: host getcwd under Zend VM.
 *
 * php-src: ext/standard/dir.c — getcwd
 */
final class VmGetcwdPure
{
    public static function available(): bool
    {
        return \function_exists('getcwd');
    }

    /**
     * @return string|false
     */
    public static function resolve()
    {
        $cwd = @\getcwd();
        if (false === $cwd || '' === $cwd) {
            return false;
        }
        if (str_ends_with($cwd, ' (deleted)')) {
            return false;
        }
        if (!\is_dir($cwd)) {
            return false;
        }

        return $cwd;
    }
}
