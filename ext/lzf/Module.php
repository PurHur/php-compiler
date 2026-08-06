<?php

declare(strict_types=1);

namespace PHPCompiler\ext\lzf;

use PHPCompiler\ModuleAbstract;

/**
 * LZF extension module (PECL lzf / php-src ext/lzf/lzf.c; issues #6384, #25287, #28063).
 *
 * Advertise lzf_* / extension_loaded('lzf') only when
 * {@see LzfExtensionPolicy::advertisesExtension()}.
 */
class Module extends ModuleAbstract
{
    public function getFunctions(): array
    {
        if (!LzfExtensionPolicy::advertisesBuiltins()) {
            return [];
        }

        return [
            new lzf_compress(),
            new lzf_decompress(),
            new lzf_optimized_for(),
        ];
    }
}
