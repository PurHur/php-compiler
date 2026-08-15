<?php

declare(strict_types=1);

namespace PHPCompiler;

require_once __DIR__.'/../BaseTest.php';

/**
 * JIT: iconv_mime_encode(null) $options → TypeError (#31310).
 */
final class IconvMimeEncodeNullOptions31310JITTest extends BaseTest
{
    protected static string $DIR = __DIR__;

    public static function providePHPTests(): \Generator
    {
        yield 'iconv_mime_encode_null_options_31310_jit.phpt' => self::parsePHPT(
            __DIR__.'/cases/iconv/iconv_mime_encode_null_options_31310_jit.phpt',
            'iconv_mime_encode_null_options_31310_jit.phpt'
        );
    }

    public function setUp(): void
    {
        $this->BIN = realpath(__DIR__.'/../../bin/jit.php');
    }
}
