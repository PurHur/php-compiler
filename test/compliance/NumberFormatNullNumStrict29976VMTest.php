<?php

declare(strict_types=1);

namespace PHPCompiler;

require_once __DIR__.'/../BaseTest.php';

/** VM: number_format(null) TypeError cites int|float under strict_types (#29976). */
final class NumberFormatNullNumStrict29976VMTest extends BaseTest
{
    protected static string $DIR = __DIR__;

    public static function providePHPTests(): \Generator
    {
        yield 'number_format_null_num_strict.phpt' => self::parsePHPT(
            __DIR__.'/cases/stdlib/number_format_null_num_strict.phpt',
            'number_format_null_num_strict.phpt'
        );
        yield 'number_format_type_error.phpt' => self::parsePHPT(
            __DIR__.'/cases/stdlib/number_format_type_error.phpt',
            'number_format_type_error.phpt'
        );
        yield 'number_format_enum_num_typeerror.phpt' => self::parsePHPT(
            __DIR__.'/cases/stdlib/number_format_enum_num_typeerror.phpt',
            'number_format_enum_num_typeerror.phpt'
        );
    }

    public function setUp(): void
    {
        $this->BIN = realpath(__DIR__.'/../../bin/vm.php');
    }
}
