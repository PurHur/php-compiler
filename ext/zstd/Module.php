<?php

declare(strict_types=1);

namespace PHPCompiler\ext\zstd;

use PHPCompiler\ModuleAbstract;

/**
 * zstd extension module (PECL php-ext-zstd; issues #6382, #6387, #25287).
 *
 * Advertise zstd_* / extension_loaded('zstd') only when
 * {@see ZstdExtensionPolicy::advertisesExtension()}.
 */
class Module extends ModuleAbstract
{
    public function getFunctions(): array
    {
        if (!ZstdExtensionPolicy::advertisesBuiltins()) {
            return [];
        }

        return [
            new zstd_compress(),
            new zstd_decompress(),
            new zstd_uncompress(),
        ];
    }
}
