<?php

declare(strict_types=1);

namespace PHPCompiler\ext\brotli;

/**
 * PECL brotli MINIT constants (brotli.c; #27856, #29485).
 *
 * Kept as a named map so get_defined_constants(true) can put BROTLI_* in the
 * brotli bucket instead of leaking into user.
 */
final class BrotliConstants
{
    /** @return array<string, int|string|bool> */
    public static function registeredConstants(): array
    {
        return [
            'BROTLI_GENERIC' => VmBrotliNative::MODE_GENERIC,
            'BROTLI_TEXT' => VmBrotliNative::MODE_TEXT,
            'BROTLI_FONT' => VmBrotliNative::MODE_FONT,
            'BROTLI_COMPRESS_LEVEL_MIN' => VmBrotliNative::MIN_QUALITY,
            'BROTLI_COMPRESS_LEVEL_MAX' => VmBrotliNative::MAX_QUALITY,
            'BROTLI_COMPRESS_LEVEL_DEFAULT' => VmBrotliNative::DEFAULT_QUALITY,
            'BROTLI_PROCESS' => VmBrotliContext::OP_PROCESS,
            'BROTLI_FLUSH' => VmBrotliContext::OP_FLUSH,
            'BROTLI_FINISH' => VmBrotliContext::OP_FINISH,
            'BROTLI_VERSION_NUMBER' => VmBrotliNative::versionNumber(),
            'BROTLI_VERSION_TEXT' => VmBrotliNative::versionText(),
            'BROTLI_DICTIONARY_SUPPORT' => VmBrotliNative::dictionarySupport(),
        ];
    }
}
