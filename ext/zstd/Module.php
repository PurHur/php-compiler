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
            'ZSTD_VERSION_NUMBER' => VmZstdNative::versionNumber(),
        ] as $name => $value) {
            $var = new \PHPCompiler\VM\Variable();
            $var->int($value);
            $runtime->vmContext->defineConstant($name, $var);
        }
        $versionText = new \PHPCompiler\VM\Variable();
        $versionText->string(VmZstdNative::versionText());
        $runtime->vmContext->defineConstant('ZSTD_VERSION_TEXT', $versionText);
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
            new ns_compress(),
            new ns_uncompress(),
            new ns_compress_dict(),
            new ns_uncompress_dict(),
            new ns_compress_init(),
            new ns_compress_add(),
            new ns_uncompress_init(),
            new ns_uncompress_add(),
        ];
    }
}
