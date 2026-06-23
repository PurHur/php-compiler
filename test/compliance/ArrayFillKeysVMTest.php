<?php

declare(strict_types=1);

namespace PHPCompiler;

require_once __DIR__.'/../BaseTest.php';

/** VM compliance for array_fill_keys(). */
final class ArrayFillKeysVMTest extends BaseTest
{
    protected static string $DIR = __DIR__;

    public static function providePHPTests(): \Generator
    {
        yield 'array_fill_keys.phpt' => self::parsePHPT(
            __DIR__.'/cases/stdlib/array_fill_keys.phpt',
            'array_fill_keys.phpt'
        );
        yield 'array_fill_keys_enum.phpt' => self::parsePHPT(
            __DIR__.'/cases/stdlib/array_fill_keys_enum.phpt',
            'array_fill_keys_enum.phpt'
        );
        yield 'array_fill_keys_float_key.phpt' => self::parsePHPT(
            __DIR__.'/cases/stdlib/array_fill_keys_float_key.phpt',
            'array_fill_keys_float_key.phpt'
        );
        yield 'array_fill_keys_null_key.phpt' => self::parsePHPT(
            __DIR__.'/cases/stdlib/array_fill_keys_null_key.phpt',
            'array_fill_keys_null_key.phpt'
        );
    }

    public function setUp(): void
    {
        $this->BIN = realpath(__DIR__.'/../../bin/vm.php');
    }
}
