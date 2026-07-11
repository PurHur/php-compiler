<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

/**
 * tempnam() for compiled JIT/AOT modules (#15685, php-in-PHP).
 *
 * SSOT: {@see VmFsTempnam}, {@see FsDirJitHelper}
 * php-src: ext/standard/file.c — php_tempnam
 */
final class TempnamJitHelper
{
    /** @return string|null null when tempnam() fails */
    public static function resolveArgv(string $directory, string $prefix): ?string
    {
        return FsDirJitHelper::tempnam($directory, $prefix);
    }

    public static function consumeNotice(): bool
    {
        return FsDirJitHelper::consumeTempnamNotice();
    }
}
