<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

/**
 * stat(2)/lstat(2)/fstat/realpath for VM — pure PHP via {@see VmStatPure} (#7844, #8903, #12265).
 *
 * php-src: Zend/zend_stat.c — php_stat / php_lstat array layout
 * JIT/AOT: JitStatArray LLVM lowering (unchanged).
 */
final class VmStatNative
{
    public static function available(): bool
    {
        return VmStatPure::available();
    }

    /**
     * @return array<int|string, int>|false
     */
    public static function stat(string $path)
    {
        return VmStatPure::stat($path);
    }

    /**
     * @return array<int|string, int>|false
     */
    public static function lstat(string $path)
    {
        return VmStatPure::lstat($path);
    }

    /**
     * fstat(2) on an open fd — php_stream_stat for VmPhpFdStream (#10460).
     *
     * @return array<int|string, int>|false
     */
    public static function fstatFd(int $fd)
    {
        return VmStatPure::fstatFd($fd);
    }

    public static function realpath(string $path): string|false
    {
        return VmStatPure::realpath($path);
    }
}
