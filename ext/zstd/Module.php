<?php

declare(strict_types=1);

namespace PHPCompiler\ext\zstd;

use PHPCompiler\ModuleAbstract;
use PHPCompiler\Runtime;

/**
 * zstd extension module (PECL php-ext-zstd; issues #6382, #6387, #25287, #27882).
 *
 * Advertise zstd_* / extension_loaded('zstd') only when
 * {@see ZstdExtensionPolicy::advertisesExtension()}.
 */
class Module extends ModuleAbstract
{
    public function init(Runtime $runtime): void
    {
        parent::init($runtime);
        if (!ZstdExtensionPolicy::advertisesBuiltins()) {
            return;
        }
        VmZstdContext::registerClasses($runtime->vmContext);
        foreach ([
            'ZSTD_COMPRESS_LEVEL_MIN' => VmZstdContext::LEVEL_MIN,
            'ZSTD_COMPRESS_LEVEL_MAX' => VmZstdContext::LEVEL_MAX,
            'ZSTD_COMPRESS_LEVEL_DEFAULT' => VmZstdContext::LEVEL_DEFAULT,
        ] as $name => $value) {
            $var = new \PHPCompiler\VM\Variable();
            $var->int($value);
            $runtime->vmContext->defineConstant($name, $var);
        }
    }

    public function getFunctions(): array
    {
        if (!ZstdExtensionPolicy::advertisesBuiltins()) {
            return [];
        }

        return [
            new zstd_compress(),
            new zstd_decompress(),
            new zstd_uncompress(),
            new zstd_compress_dict(),
            new zstd_uncompress_dict(),
            new zstd_compress_init(),
            new zstd_compress_add(),
            new zstd_uncompress_init(),
            new zstd_uncompress_add(),
        ];
    }
}
