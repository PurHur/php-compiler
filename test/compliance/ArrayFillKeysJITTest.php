<?php

declare(strict_types=1);

namespace PHPCompiler;

require_once __DIR__.'/../BaseTest.php';

/**
 * @group llvm
 */
/** JIT compliance for array_fill_keys(). */
final class ArrayFillKeysJITTest extends BaseTest
{
    protected static string $DIR = __DIR__;

    public static function providePHPTests(): \Generator
    {
        yield 'array_fill_keys_jit.phpt' => self::parsePHPT(
            __DIR__.'/cases/stdlib/array_fill_keys_jit.phpt',
            'array_fill_keys_jit.phpt'
        );
        yield 'array_fill_keys_float_key_jit.phpt' => self::parsePHPT(
            __DIR__.'/cases/stdlib/array_fill_keys_float_key_jit.phpt',
            'array_fill_keys_float_key_jit.phpt'
        );
        yield 'array_fill_keys_null_key_jit.phpt' => self::parsePHPT(
            __DIR__.'/cases/stdlib/array_fill_keys_null_key_jit.phpt',
            'array_fill_keys_null_key_jit.phpt'
        );
    }

    public function setUp(): void
    {
        $this->BIN = realpath(__DIR__.'/../../bin/jit.php');
    }
}
