<?php

declare(strict_types=1);

namespace PHPCompiler;

require_once __DIR__.'/../BaseTest.php';

/** VM: mb_convert_encoding(null) TypeError under strict_types (#29777, php-src mbstring.c). */
final class MbConvertEncodingNullStrictVMTest extends BaseTest
{
    protected static string $DIR = __DIR__;

    public static function providePHPTests(): \Generator
    {
        yield 'mb_convert_encoding_null_strict.phpt' => self::parsePHPT(
            __DIR__.'/cases/mbstring/mb_convert_encoding_null_strict.phpt',
            'mb_convert_encoding_null_strict.phpt'
        );
    }

    public function setUp(): void
    {
        $this->BIN = realpath(__DIR__.'/../../bin/vm.php');
    }
}
