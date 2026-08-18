<?php

declare(strict_types=1);

namespace PHPCompiler;

require_once __DIR__.'/../BaseTest.php';

/**
 * VM: native long ⊙ numeric-string bitwise (#32407).
 */
final class LongStringBitwise32407VMTest extends BaseTest
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
        $this->BIN = realpath(__DIR__.'/../../bin/vm.php');
    }
}
