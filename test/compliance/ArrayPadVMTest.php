<?php

declare(strict_types=1);

namespace PHPCompiler;

require_once __DIR__.'/../BaseTest.php';

/** VM compliance for array_pad(). */
final class ArrayPadVMTest extends BaseTest
{
    protected static string $DIR = __DIR__;

    public static function providePHPTests(): \Generator
    {
        yield 'array_pad.phpt' => self::parsePHPT(
            __DIR__.'/cases/stdlib/array_pad.phpt',
            'array_pad.phpt'
        );
        yield 'array_pad_chunk_enum.phpt' => self::parsePHPT(
            __DIR__.'/cases/stdlib/array_pad_chunk_enum.phpt',
            'array_pad_chunk_enum.phpt'
        );
        yield 'array_pad_negative_length.phpt' => self::parsePHPT(
            __DIR__.'/cases/stdlib/array_pad_negative_length.phpt',
            'array_pad_negative_length.phpt'
        );
        yield 'array_pad_negative_after_udf.phpt' => self::parsePHPT(
            __DIR__.'/cases/stdlib/array_pad_negative_after_udf.phpt',
            'array_pad_negative_after_udf.phpt'
        );
        yield 'array_pad_float_length_strict.phpt' => self::parsePHPT(
            __DIR__.'/cases/stdlib/array_pad_float_length_strict.phpt',
            'array_pad_float_length_strict.phpt'
        );
        yield 'array_pad_float_length_coerce.phpt' => self::parsePHPT(
            __DIR__.'/cases/stdlib/array_pad_float_length_coerce.phpt',
            'array_pad_float_length_coerce.phpt'
        );
        yield 'array_pad_pad_type.phpt' => self::parsePHPT(
            __DIR__.'/cases/stdlib/array_pad_pad_type.phpt',
            'array_pad_pad_type.phpt'
        );
        yield 'array_pad_pad_type_profile82.phpt' => self::parsePHPT(
            __DIR__.'/cases/stdlib/array_pad_pad_type_profile82.phpt',
            'array_pad_pad_type_profile82.phpt'
        );
        yield 'array_pad_type_enum.phpt' => self::parsePHPT(
            __DIR__.'/cases/stdlib/array_pad_type_enum.phpt',
            'array_pad_type_enum.phpt'
        );
        yield 'array_pad_neg_after_udf_array.phpt' => self::parsePHPT(
            __DIR__.'/cases/stdlib/array_pad_neg_after_udf_array.phpt',
            'array_pad_neg_after_udf_array.phpt'
        );
        yield 'array_pad_enum_length_typeerror.phpt' => self::parsePHPT(
            __DIR__.'/cases/stdlib/array_pad_enum_length_typeerror.phpt',
            'array_pad_enum_length_typeerror.phpt'
        );
        yield 'array_pad_length_limit.phpt' => self::parsePHPT(
            __DIR__.'/cases/stdlib/array_pad_length_limit.phpt',
            'array_pad_length_limit.phpt'
        );
    }

    public function setUp(): void
    {
        $this->BIN = realpath(__DIR__.'/../../bin/vm.php');
    }
}
