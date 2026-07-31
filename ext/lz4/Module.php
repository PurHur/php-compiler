<?php

declare(strict_types=1);

namespace PHPCompiler\ext\lz4;

use PHPCompiler\ModuleAbstract;

/**
 * LZ4 extension module (kjdev/php-ext-lz4; #22529, #25087).
 *
 * Advertise lz4_* / extension_loaded('lz4') only when
 * {@see Lz4ExtensionPolicy::advertisesExtension()}.
 */
class Module extends ModuleAbstract
{
    public function getFunctions(): array
    {
        if (!Lz4ExtensionPolicy::advertisesBuiltins()) {
            return [];
        }

        return [
            new lz4_compress(),
            new lz4_uncompress(),
        ];
    }
}
