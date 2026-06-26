<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

/**
 * php://stdin / php://stdout / php://stderr — {@see VmFsStdioPure} SSOT (#4648, #12252).
 *
 * JIT/AOT: {@see \PHPCompiler\JIT\Builtin\StreamStdioJit} / __compiler_fopen stdio branch
 */
final class VmFsStdioNative
{
    public static function available(): bool
    {
        return VmFsStdioPure::available();
    }

    /**
     * @return int|false VM fd stream handle
     */
    public static function openDupFd(int $fd, string $mode)
    {
        return VmFsStdioPure::openDupFd($fd, $mode);
    }
}
