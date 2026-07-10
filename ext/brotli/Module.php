<?php

declare(strict_types=1);

namespace PHPCompiler\ext\brotli;

use PHPCompiler\ModuleAbstract;

/**
 * brotli extension module (kjdev/php-ext-brotli; issue #6814, #17563).
 *
 * Register brotli_compress()/brotli_uncompress() when
 * {@see BrotliExtensionPolicy::advertisesExtension()} — withheld on reference profile.
 */
class Module extends ModuleAbstract
{
    public function getFunctions(): array
    {
        if (!BrotliExtensionPolicy::advertisesExtension()) {
            return [];
        }

        return [
            new brotli_compress(),
            new brotli_uncompress(),
        ];
    }
}
