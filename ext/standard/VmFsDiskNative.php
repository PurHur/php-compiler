<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

/**
 * disk_free_space() / disk_total_space() for VM — pure PHP via {@see VmFsDiskPure} (#3758, #8989, #12234).
 *
 * php-src: ext/standard/filestat.c — php_disk_free_space / php_disk_total_space
 * JIT/AOT: {@see StatPathRuntime} / {@see StatFieldsJitHelper} (#9112).
 */
final class VmFsDiskNative
{
    public static function available(): bool
    {
        return VmFsDiskPure::available();
    }

    /**
     * @return float|false
     */
    public static function diskFreeSpace(string $path)
    {
        return VmFsDiskPure::diskFreeSpace($path);
    }

    /**
     * @return float|false
     */
    public static function diskTotalSpace(string $path)
    {
        return VmFsDiskPure::diskTotalSpace($path);
    }
}
