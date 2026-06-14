<?php

declare(strict_types=1);

namespace PHPCompiler\ext\lzf;

use PHPCompiler\ModuleAbstract;

/**
 * LZF extension module (php-src ext/lzf/lzf.c; issue #6384).
 */
class Module extends ModuleAbstract
{
    public function getFunctions(): array
    {
        return [
            new lzf_compress(),
            new lzf_decompress(),
        ];
    }
}
