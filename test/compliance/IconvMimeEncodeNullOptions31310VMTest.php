<?php

declare(strict_types=1);

namespace PHPCompiler;

require_once __DIR__.'/../BaseTest.php';

/**
 * VM: iconv_mime_encode(null) $options → TypeError (#31310).
 *
 * Dedicated provider — path-slash data-set names break --filter on full VMTest.
 */
final class IconvMimeEncodeNullOptions31310VMTest extends BaseTest
{
    protected static string $DIR = __DIR__;

    public static function providePHPTests(): \Generator
    {
        yield 'iconv_mime_encode_null_options_31310.phpt' => self::parsePHPT(
            __DIR__.'/cases/iconv/iconv_mime_encode_null_options_31310.phpt',
            'iconv_mime_encode_null_options_31310.phpt'
        );
    }

    public function setUp(): void
    {
        $this->BIN = realpath(__DIR__.'/../../bin/vm.php');
    }
}
