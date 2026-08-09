<?php

declare(strict_types=1);

namespace PHPCompiler;

require_once __DIR__.'/../BaseTest.php';

/** JIT: wordwrap(..., '') ValueError "must not be empty" (#29291, php-src string.c). */
final class WordwrapEmptyBreakMessageJITTest extends BaseTest
{
    protected static string $DIR = __DIR__;

    public static function providePHPTests(): \Generator
    {
        yield 'wordwrap_empty_break_must_not_be_empty_jit.phpt' => self::parsePHPT(
            __DIR__.'/cases/stdlib/wordwrap_empty_break_must_not_be_empty_jit.phpt',
            'wordwrap_empty_break_must_not_be_empty_jit.phpt'
        );
    }

    public function setUp(): void
    {
        $this->BIN = realpath(__DIR__.'/../../bin/jit.php');
    }
}
