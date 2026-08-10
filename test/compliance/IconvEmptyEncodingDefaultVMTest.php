<?php

declare(strict_types=1);

namespace PHPCompiler;

require_once __DIR__.'/../BaseTest.php';

/** VM: iconv_* empty encoding uses default/internal charset (#29497, php-src iconv.c). */
final class IconvEmptyEncodingDefaultVMTest extends BaseTest
{
    protected static string $DIR = __DIR__;

    public static function providePHPTests(): \Generator
    {
        yield 'iconv_empty_encoding_default.phpt' => self::parsePHPT(
            __DIR__.'/cases/iconv/iconv_empty_encoding_default.phpt',
            'iconv_empty_encoding_default.phpt'
        );
    }

    public function setUp(): void
    {
        $this->BIN = realpath(__DIR__.'/../../bin/vm.php');
    }
}
