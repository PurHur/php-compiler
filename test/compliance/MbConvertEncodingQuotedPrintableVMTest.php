<?php

declare(strict_types=1);

namespace PHPCompiler;

require_once __DIR__.'/../BaseTest.php';

/** VM compliance for mb_convert_encoding() Quoted-Printable (#28982). */
final class MbConvertEncodingQuotedPrintableVMTest extends BaseTest
{
    protected static string $DIR = __DIR__;

    public static function providePHPTests(): \Generator
    {
        yield 'mb_convert_encoding_quoted_printable.phpt' => self::parsePHPT(
            __DIR__.'/cases/mbstring/mb_convert_encoding_quoted_printable.phpt',
            'mb_convert_encoding_quoted_printable.phpt'
        );
    }

    public function setUp(): void
    {
        $this->BIN = realpath(__DIR__.'/../../bin/vm.php');
    }
}
