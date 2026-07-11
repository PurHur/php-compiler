<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\VM\ScriptStack;

/**
 * stream_resolve_include_path lookup for VM + compiled JIT/AOT (#9245, php-in-PHP).
 *
 * VM path: {@see VmFs::resolveIncludePath()} delegates to {@see IncludePathJitHelper}.
     * JIT lowering compiles {@see resolveJit()} only (builtin file_exists/realpath).
 * php-src: ext/standard/streams.c — php_stream_resolve_include_path
 */
final class IncludePathResolveJitHelper
{
    /**
     * @return string|null absolute path when found; null when not (JIT ABI uses null __string__*)
     */
    public static function resolveJit(string $filename): ?string
    {
        $resolved = self::resolveJitZend($filename, IncludePathJitHelper::get());

        return false === $resolved ? null : $resolved;
    }

    /**
     * Zend parity via ScriptStack + VmStatPath (VM interpreter + reference).
     *
     * @return string|false absolute path when found
     */
    public static function resolve(string $filename, string $includePath): string|false
    {
        if ('' === $filename || '.' === $filename) {
            return self::resolveCwdFilename();
        }
        if (\str_contains($filename, "\0")) {
            return false;
        }
        if ($filename[0] === '/' || (\strlen($filename) > 1 && $filename[1] === ':')) {
            $normalized = ScriptStack::normalize($filename);

            return '' !== $normalized && VmStatPath::exists($normalized) ? $normalized : false;
        }
        if ('' === $includePath) {
            return false;
        }
        foreach (\explode(\PATH_SEPARATOR, $includePath) as $dir) {
            if ('' === $dir) {
                continue;
            }
            $candidate = ScriptStack::normalize(\rtrim($dir, '/\\').'/'.$filename);
            if ('' !== $candidate && VmStatPath::exists($candidate)) {
                return $candidate;
            }
        }

        return false;
    }

    /**
     * JIT-compilable resolve using file_exists/realpath (php-src streams.c semantics).
     *
     * @return string|false absolute path when found
     */
    public static function resolveJitZend(string $filename, string $includePath): string|false
    {
        if ('' === $filename || '.' === $filename) {
            return self::resolveCwdFilename();
        }
        if (\str_contains($filename, "\0")) {
            return false;
        }
        if ($filename[0] === '/' || (\strlen($filename) > 1 && $filename[1] === ':')) {
            if (!\file_exists($filename)) {
                return false;
            }
            $resolved = \realpath($filename);

            return false !== $resolved ? $resolved : $filename;
        }
        if ('' === $includePath) {
            return false;
        }
        foreach (\explode(\PATH_SEPARATOR, $includePath) as $dir) {
            if ('' === $dir) {
                continue;
            }
            $candidate = \rtrim($dir, '/\\').'/'.$filename;
            if (\file_exists($candidate)) {
                $resolved = \realpath($candidate);

                return false !== $resolved ? $resolved : $candidate;
            }
        }

        return false;
    }

    /**
     * php-src ext/standard/streams.c — stream_resolve_include_path(".") / ("") → getcwd().
     *
     * @return string|false
     */
    private static function resolveCwdFilename(): string|false
    {
        $cwd = VmFs::getcwd();

        return false !== $cwd ? $cwd : false;
    }
}
