<?php

declare(strict_types=1);

namespace PHPCompiler;

require_once __DIR__.'/../BaseTest.php';

/** VM compliance for array_column() (#4220). */
final class ArrayColumnVMTest extends BaseTest
{
    protected static string $DIR = __DIR__;

    public static function providePHPTests(): \Generator
    {
        yield 'array_column_null_index.phpt' => self::parsePHPT(
            __DIR__.'/cases/stdlib/array_column_null_index.phpt',
            'array_column_null_index.phpt'
        );
        yield 'array_column_null_column.phpt' => self::parsePHPT(
            __DIR__.'/cases/stdlib/array_column_null_column.phpt',
            'array_column_null_column.phpt'
        );
        yield 'array_column_inline_null_index.phpt' => self::parsePHPT(
            __DIR__.'/cases/stdlib/array_column_inline_null_index.phpt',
            'array_column_inline_null_index.phpt'
        );
        yield 'array_column_missing_key.phpt' => self::parsePHPT(
            __DIR__.'/cases/stdlib/array_column_missing_key.phpt',
            'array_column_missing_key.phpt'
        );
    }

    public function setUp(): void
    {
        $this->BIN = realpath(__DIR__.'/../../bin/vm.php');
    }
}
