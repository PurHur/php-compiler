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
    }

    public function setUp(): void
    {
        $this->BIN = realpath(__DIR__.'/../../bin/vm.php');
    }
}
