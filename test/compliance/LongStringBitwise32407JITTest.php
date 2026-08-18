<?php

declare(strict_types=1);

namespace PHPCompiler;

require_once __DIR__.'/../BaseTest.php';

/**
 * JIT: native long ⊙ numeric-string bitwise (#32407).
 *
 * @group llvm
 */
final class LongStringBitwise32407JITTest extends BaseTest
{
    protected static string $DIR = __DIR__;

    public static function providePHPTests(): \Generator
    {
        yield 'long_string_bitwise.phpt' => self::parsePHPT(
            __DIR__.'/cases/language/long_string_bitwise.phpt',
            'long_string_bitwise.phpt'
        );
    }

    public function setUp(): void
    {
        $this->BIN = realpath(__DIR__.'/../../bin/jit.php');
    }
}
