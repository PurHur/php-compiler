<?php

declare(strict_types=1);

namespace PHPCompiler\ext\lz4;

use PHPCompiler\ModuleAbstract;
use PHPCompiler\Runtime;

/**
 * LZ4 extension module (kjdev/php-ext-lz4; #22529, #25087, #27883).
 *
 * Advertise lz4_* / extension_loaded('lz4') only when
 * {@see Lz4ExtensionPolicy::advertisesExtension()}.
 */
class Module extends ModuleAbstract
{
    public function init(Runtime $runtime): void
    {
        parent::init($runtime);
        if (!Lz4ExtensionPolicy::advertisesBuiltins()) {
            return;
        }
        foreach ([
            'LZ4_CLEVEL_MIN' => VmLz4Native::CLEVEL_MIN,
            'LZ4_CLEVEL_MAX' => VmLz4Native::CLEVEL_MAX,
            'LZ4_VERSION_NUMBER' => VmLz4Native::versionNumber(),
            'LZ4_CHECKSUM_FRAME' => VmLz4Native::CHECKSUM_FRAME,
            'LZ4_CHECKSUM_BLOCK' => VmLz4Native::CHECKSUM_BLOCK,
            'LZ4_BLOCK_SIZE_64KB' => VmLz4Native::BLOCK_SIZE_64KB,
            'LZ4_BLOCK_SIZE_256KB' => VmLz4Native::BLOCK_SIZE_256KB,
            'LZ4_BLOCK_SIZE_1MB' => VmLz4Native::BLOCK_SIZE_1MB,
            'LZ4_BLOCK_SIZE_4MB' => VmLz4Native::BLOCK_SIZE_4MB,
        ] as $name => $value) {
            $var = new \PHPCompiler\VM\Variable();
            $var->int($value);
            $runtime->vmContext->defineConstant($name, $var);
        }
        $text = new \PHPCompiler\VM\Variable();
        $text->string(VmLz4Native::versionText());
        $runtime->vmContext->defineConstant('LZ4_VERSION_TEXT', $text);
    }

    public function getFunctions(): array
    {
        if (!Lz4ExtensionPolicy::advertisesBuiltins()) {
            return [];
        }

        return [
            new lz4_compress(),
            new lz4_uncompress(),
            new lz4_compress_frame(),
            new lz4_uncompress_frame(),
        ];
    }
}
