<?php

declare(strict_types=1);

namespace PHPCompiler;

require_once __DIR__.'/../BaseTest.php';

/** VM compliance for str_getcsv(). */
final class StrGetcsvVMTest extends BaseTest
{
    protected static string $DIR = __DIR__;

    public static function providePHPTests(): \Generator
    {
        yield 'str_getcsv.phpt' => self::parsePHPT(
            __DIR__.'/cases/stdlib/str_getcsv.phpt',
            'str_getcsv.phpt'
        );
        yield 'str_getcsv_enum_type_error.phpt' => self::parsePHPT(
            __DIR__.'/cases/stdlib/str_getcsv_enum_type_error.phpt',
            'str_getcsv_enum_type_error.phpt'
        );
        yield 'str_getcsv_newline_only.phpt' => self::parsePHPT(
            __DIR__.'/cases/stdlib/str_getcsv_newline_only.phpt',
            'str_getcsv_newline_only.phpt'
        );
        yield 'str_getcsv_null_separator.phpt' => self::parsePHPT(
            __DIR__.'/cases/stdlib/str_getcsv_null_separator.phpt',
            'str_getcsv_null_separator.phpt'
        );
        yield 'str_getcsv_lone_quote.phpt' => self::parsePHPT(
            __DIR__.'/cases/stdlib/str_getcsv_lone_quote.phpt',
            'str_getcsv_lone_quote.phpt'
        );
    }

    public function setUp(): void
    {
        $this->BIN = realpath(__DIR__.'/../../bin/vm.php');
    }
}
