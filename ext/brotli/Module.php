<?php

declare(strict_types=1);

namespace PHPCompiler\ext\brotli;

use PHPCompiler\ModuleAbstract;

/**
 * brotli extension module (kjdev/php-ext-brotli; issue #6814).
 */
class Module extends ModuleAbstract
{
    public function getFunctions(): array
    {
        if (!VmBrotliNative::available()) {
            return [];
        }

        return [
            new brotli_compress(),
            new brotli_uncompress(),
        ];
    }
}
