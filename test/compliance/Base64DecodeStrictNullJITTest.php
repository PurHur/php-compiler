<?php

declare(strict_types=1);

namespace PHPCompiler;

require_once __DIR__.'/../BaseTest.php';

/**
 * @group llvm
 */
/** JIT: base64_decode(null $strict) under strict_types TypeError (#29867). */
final class Base64DecodeStrictNullJITTest extends BaseTest
{
    protected static string $DIR = __DIR__;

    public static function providePHPTests(): \Generator
    {
        yield 'base64_decode_strict_null_jit.phpt' => self::parsePHPT(
            __DIR__.'/cases/stdlib/base64_decode_strict_null_jit.phpt',
            'base64_decode_strict_null_jit.phpt'
        );
    }

    public function setUp(): void
    {
        $this->BIN = realpath(__DIR__.'/../../bin/jit.php');
    }
}
