<?php

declare(strict_types=1);

namespace PHPCompiler\ext\zstd;

use PHPCompiler\ModuleAbstract;

/**
 * zstd extension module (php-src ext/zstd/zstd.c; issues #6382, #6387).
 */
class Module extends ModuleAbstract
{
    public function getFunctions(): array
    {
        return [
            new zstd_compress(),
            new zstd_decompress(),
            new zstd_uncompress(),
        ];
    }
}
