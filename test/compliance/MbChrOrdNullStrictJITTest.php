<?php

declare(strict_types=1);

namespace PHPCompiler;

require_once __DIR__.'/../BaseTest.php';

/** JIT: mb_chr/mb_ord(null) TypeError under strict_types (#29778, php-src mbstring.c). */
final class MbChrOrdNullStrictJITTest extends BaseTest
{
    protected static string $DIR = __DIR__;

    public static function providePHPTests(): \Generator
    {
        yield 'mb_chr_ord_null_strict_jit.phpt' => self::parsePHPT(
            __DIR__.'/cases/mbstring/mb_chr_ord_null_strict_jit.phpt',
            'mb_chr_ord_null_strict_jit.phpt'
        );
    }

    public function setUp(): void
    {
        $this->BIN = realpath(__DIR__.'/../../bin/jit.php');
    }
}
