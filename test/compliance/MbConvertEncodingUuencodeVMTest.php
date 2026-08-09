<?php

declare(strict_types=1);

namespace PHPCompiler;

require_once __DIR__.'/../BaseTest.php';

/** VM compliance for mb_convert_encoding() UUENCODE (#28981). */
final class MbConvertEncodingUuencodeVMTest extends BaseTest
{
    protected static string $DIR = __DIR__;

    public static function providePHPTests(): \Generator
    {
        yield 'mb_convert_encoding_uuencode.phpt' => self::parsePHPT(
            __DIR__.'/cases/mbstring/mb_convert_encoding_uuencode.phpt',
            'mb_convert_encoding_uuencode.phpt'
        );
    }

    public function setUp(): void
    {
        $this->BIN = realpath(__DIR__.'/../../bin/vm.php');
    }
}
