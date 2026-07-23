<?php

declare(strict_types=1);

namespace PHPCompiler\ext\lz4;

use PHPCompiler\ModuleAbstract;

/**
 * LZ4 extension module (kjdev/php-ext-lz4; #22529).
 */
class Module extends ModuleAbstract
{
    public function getFunctions(): array
    {
        return [
            new lz4_compress(),
            new lz4_uncompress(),
        ];
    }
}
