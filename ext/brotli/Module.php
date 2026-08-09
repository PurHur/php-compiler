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
        // PECL brotli.c — php_register_url_stream_wrapper("compress.brotli", …) (#28115).
        \PHPCompiler\ext\standard\VmStreamWrapperRegistry::registerExtensionBuiltin(VmBrotliStream::PROTOCOL);
        VmBrotliContext::registerClasses($runtime->vmContext);
        foreach (BrotliConstants::registeredConstants() as $name => $value) {
            $var = new \PHPCompiler\VM\Variable();
            if (\is_bool($value)) {
                $var->bool($value);
            } elseif (\is_string($value)) {
                $var->string($value);
            } else {
                $var->int($value);
            }
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
            new ns_compress(),
            new ns_uncompress(),
            new ns_compress_init(),
            new ns_compress_add(),
            new ns_uncompress_init(),
            new ns_uncompress_add(),
        ];
    }
}
