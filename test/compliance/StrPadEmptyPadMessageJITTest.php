<?php

declare(strict_types=1);

namespace PHPCompiler;

require_once __DIR__.'/../BaseTest.php';

/** JIT: str_pad(..., '') ValueError "must not be empty" (#29292, php-src string.c). */
final class StrPadEmptyPadMessageJITTest extends BaseTest
{
    protected static string $DIR = __DIR__;

    public static function providePHPTests(): \Generator
    {
        yield 'str_pad_empty_pad_must_not_be_empty_jit.phpt' => self::parsePHPT(
            __DIR__.'/cases/stdlib/str_pad_empty_pad_must_not_be_empty_jit.phpt',
            'str_pad_empty_pad_must_not_be_empty_jit.phpt'
        );
    }

    public function setUp(): void
    {
        $this->BIN = realpath(__DIR__.'/../../bin/jit.php');
    }
}
