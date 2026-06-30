<?php

declare(strict_types=1);

namespace PHPCompiler;

require_once __DIR__.'/../BaseTest.php';

/**
 * @group llvm
 */
/** JIT compliance for array_pad(). */
final class ArrayPadJITTest extends BaseTest
{
    protected static string $DIR = __DIR__;

    public static function providePHPTests(): \Generator
    {
        yield 'array_pad_jit.phpt' => self::parsePHPT(
            __DIR__.'/cases/stdlib/array_pad_jit.phpt',
            'array_pad_jit.phpt'
        );
        yield 'array_pad_chunk_enum_jit.phpt' => self::parsePHPT(
            __DIR__.'/cases/stdlib/array_pad_chunk_enum_jit.phpt',
            'array_pad_chunk_enum_jit.phpt'
        );
        yield 'array_pad_float_length_strict_jit.phpt' => self::parsePHPT(
            __DIR__.'/cases/stdlib/array_pad_float_length_strict_jit.phpt',
            'array_pad_float_length_strict_jit.phpt'
        );
    }

    public function setUp(): void
    {
        $this->BIN = realpath(__DIR__.'/../../bin/jit.php');
    }
}
