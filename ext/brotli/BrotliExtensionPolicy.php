<?php

declare(strict_types=1);

namespace PHPCompiler\ext\brotli;

use PHPCompiler\CompilerVersion;

/**
 * ext/brotli surface advertisement — php-src ext/brotli/brotli.c module registration (#17563).
 *
 * Pure PHP {@see VmBrotliNative} stays compiled in-tree but is withheld from extension_loaded()
 * and function_exists() on the reference profile until {@see CompilerVersion::supportsBrotli()}.
 */
final class BrotliExtensionPolicy
{
    public static function advertisesExtension(): bool
    {
        return CompilerVersion::supportsBrotli() && VmBrotliNative::available();
    }
}
