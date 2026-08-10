<?php

declare(strict_types=1);

namespace PHPCompiler;

require_once __DIR__.'/../BaseTest.php';

/** JIT: utf8_encode/utf8_decode(null) TypeError under strict_types (#29889, ext/standard/utf8.c). */
final class Utf8EncodeDecodeNullStrictJITTest extends BaseTest
{
    protected static string $DIR = __DIR__;

    public static function providePHPTests(): \Generator
    {
        yield 'utf8_encode_decode_null_strict_typeerror_jit.phpt' => self::parsePHPT(
            __DIR__.'/cases/stdlib/utf8_encode_decode_null_strict_typeerror_jit.phpt',
            'utf8_encode_decode_null_strict_typeerror_jit.phpt'
        );
    }

    public function setUp(): void
    {
        $this->BIN = realpath(__DIR__.'/../../bin/jit.php');
    }
}
