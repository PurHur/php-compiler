<?php

declare(strict_types=1);

namespace PHPCompiler;

require_once __DIR__.'/../BaseTest.php';

/**
 * VM: iconv_get/set_encoding(null) soft DEP (#31311).
 *
 * Dedicated provider — path-slash data-set names break --filter on full VMTest.
 */
final class IconvEncodingNullType31311VMTest extends BaseTest
{
    protected static string $DIR = __DIR__;

    public static function providePHPTests(): \Generator
    {
        yield 'iconv_encoding_null_type_31311.phpt' => self::parsePHPT(
            __DIR__.'/cases/iconv/iconv_encoding_null_type_31311.phpt',
            'iconv_encoding_null_type_31311.phpt'
        );
        yield 'iconv_encoding_null_type_strict_31311.phpt' => self::parsePHPT(
            __DIR__.'/cases/iconv/iconv_encoding_null_type_strict_31311.phpt',
            'iconv_encoding_null_type_strict_31311.phpt'
        );
    }

    public function setUp(): void
    {
        $this->BIN = realpath(__DIR__.'/../../bin/vm.php');
    }
}
