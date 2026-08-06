<?php

declare(strict_types=1);

namespace PHPCompiler\ext\brotli;

use PHPCompiler\ModuleAbstract;
use PHPCompiler\Runtime;

/**
 * brotli extension module (kjdev/php-ext-brotli; issue #6814, #17563, #27856).
 *
 * Register compress/uncompress + streaming init/add and BROTLI_* constants when
 * {@see BrotliExtensionPolicy::advertisesExtension()} — withheld on reference profile.
 */
class Module extends ModuleAbstract
{
    public function init(Runtime $runtime): void
    {
        parent::init($runtime);
        if (!BrotliExtensionPolicy::advertisesExtension()) {
            return;
        }
        VmBrotliContext::registerClasses($runtime->vmContext);
        foreach ([
            'BROTLI_GENERIC' => VmBrotliNative::MODE_GENERIC,
            'BROTLI_TEXT' => VmBrotliNative::MODE_TEXT,
            'BROTLI_FONT' => VmBrotliNative::MODE_FONT,
            'BROTLI_COMPRESS_LEVEL_MIN' => VmBrotliNative::MIN_QUALITY,
            'BROTLI_COMPRESS_LEVEL_MAX' => VmBrotliNative::MAX_QUALITY,
            'BROTLI_COMPRESS_LEVEL_DEFAULT' => VmBrotliNative::DEFAULT_QUALITY,
            'BROTLI_PROCESS' => VmBrotliContext::OP_PROCESS,
            'BROTLI_FLUSH' => VmBrotliContext::OP_FLUSH,
            'BROTLI_FINISH' => VmBrotliContext::OP_FINISH,
        ] as $name => $value) {
            $var = new \PHPCompiler\VM\Variable();
            $var->int($value);
            $runtime->vmContext->defineConstant($name, $var);
        }
    }

    public function getFunctions(): array
    {
        if (!BrotliExtensionPolicy::advertisesExtension()) {
            return [];
        }

        return [
            new brotli_compress(),
            new brotli_uncompress(),
            new brotli_compress_init(),
            new brotli_compress_add(),
            new brotli_uncompress_init(),
            new brotli_uncompress_add(),
        ];
    }
}
