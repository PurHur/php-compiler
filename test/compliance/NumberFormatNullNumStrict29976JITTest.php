<?php

declare(strict_types=1);

namespace PHPCompiler;

require_once __DIR__.'/../BaseTest.php';

/** JIT: number_format(null) TypeError cites int|float under strict_types (#29976). */
final class NumberFormatNullNumStrict29976JITTest extends BaseTest
{
    protected static string $DIR = __DIR__;

    public static function providePHPTests(): \Generator
    {
        yield 'number_format_null_num_strict_jit.phpt' => self::parsePHPT(
            __DIR__.'/cases/stdlib/number_format_null_num_strict_jit.phpt',
            'number_format_null_num_strict_jit.phpt'
        );
        yield 'number_format_type_error_jit.phpt' => self::parsePHPT(
            __DIR__.'/cases/stdlib/number_format_type_error_jit.phpt',
            'number_format_type_error_jit.phpt'
        );
        yield 'number_format_enum_num_typeerror_jit.phpt' => self::parsePHPT(
            __DIR__.'/cases/stdlib/number_format_enum_num_typeerror_jit.phpt',
            'number_format_enum_num_typeerror_jit.phpt'
        );
    }

    public function setUp(): void
    {
        $this->BIN = realpath(__DIR__.'/../../bin/jit.php');
    }
}
